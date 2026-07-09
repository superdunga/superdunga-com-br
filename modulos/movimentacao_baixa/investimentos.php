<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require __DIR__ . '/_empresa2_guard.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$perfil = strtoupper((string)($_SESSION['nivel'] ?? ''));
$permitido = moduloPermitido($pdo, $empresaId, 'movimentacao_baixa_investimentos', $perfil);

function mbiH($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function mbiDecimal($valor): float
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return 0.0;
    }
    $valor = str_replace(['R$', ' '], '', $valor);
    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }
    return (float)$valor;
}

function mbiMoeda($valor, string $moeda = 'BRL'): string
{
    $prefixo = $moeda === 'USD' ? 'US$ ' : ($moeda === 'BRL' ? 'R$ ' : $moeda . ' ');
    return $prefixo . number_format((float)$valor, 2, ',', '.');
}

function mbiNumero($valor, int $casas = 8): string
{
    $texto = number_format((float)$valor, $casas, ',', '.');
    return rtrim(rtrim($texto, '0'), ',');
}

function mbiGarantirTabelas(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS investimentos_ativos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            conta_id INT NOT NULL,
            codigo VARCHAR(30) NOT NULL,
            nome VARCHAR(180) NOT NULL,
            tipo VARCHAR(30) NOT NULL,
            moeda VARCHAR(10) NOT NULL DEFAULT 'BRL',
            codigo_cotacao VARCHAR(60) NULL,
            quantidade DECIMAL(22,8) NOT NULL DEFAULT 0,
            preco_medio DECIMAL(22,8) NOT NULL DEFAULT 0,
            data_posicao DATE NULL,
            cotacao_atual DECIMAL(22,8) NULL,
            cotacao_em DATETIME NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            observacao VARCHAR(255) NULL,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_por INT NULL,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_investimentos_empresa (empresa_id, ativo),
            INDEX idx_investimentos_codigo (empresa_id, codigo),
            INDEX idx_investimentos_conta (empresa_id, conta_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function mbiContasInvestimento(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT CBCONTADOR, TITULAR, DESCABREV, NUMERO, CLASSIFICACAO
        FROM armazem_bnc002
        WHERE EMPRESA = 2
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
        ORDER BY CASE WHEN CLASSIFICACAO = 3 THEN 0 ELSE 1 END, TITULAR, DESCABREV, CBCONTADOR
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function mbiNomeConta(?array $conta): string
{
    if (!$conta) {
        return '';
    }
    $partes = [];
    foreach (['TITULAR', 'DESCABREV', 'NUMERO'] as $campo) {
        $valor = trim((string)($conta[$campo] ?? ''));
        if ($valor !== '') {
            $partes[] = $valor;
        }
    }
    $nome = $partes ? implode(' | ', array_unique($partes)) : 'Conta ' . (int)$conta['CBCONTADOR'];
    if ((string)($conta['CLASSIFICACAO'] ?? '') === '3') {
        $nome .= ' - Investimento';
    }
    return $nome;
}

function mbiAtivo(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM investimentos_ativos WHERE empresa_id = 2 AND id = ? LIMIT 1");
    $stmt->execute([$id]);
    $ativo = $stmt->fetch(PDO::FETCH_ASSOC);
    return $ativo ?: null;
}

function mbiBuscarCotacaoYahoo(string $codigo): ?float
{
    $codigo = trim($codigo);
    if ($codigo === '') {
        return null;
    }

    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($codigo) . '?range=1d&interval=1d';
    $json = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'SuperDunga/1.0',
        ]);
        $json = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => ['timeout' => 8, 'header' => "User-Agent: SuperDunga/1.0\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $json = @file_get_contents($url, false, $ctx);
    }

    if (!$json) {
        return null;
    }
    $dados = json_decode($json, true);
    $preco = $dados['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
    return is_numeric($preco) ? (float)$preco : null;
}

function mbiSugestaoCotacao(string $codigo, string $tipo, string $moeda): string
{
    $codigo = strtoupper(trim($codigo));
    $tipo = strtoupper(trim($tipo));
    $moeda = strtoupper(trim($moeda));
    if ($codigo === '') {
        return '';
    }
    if (in_array($tipo, ['ACAO_BR', 'BDR', 'FII', 'ETF_BR'], true) && substr($codigo, -3) !== '.SA') {
        return $codigo . '.SA';
    }
    if ($tipo === 'CRIPTO' && strpos($codigo, '-') === false) {
        return $codigo . '-BRL';
    }
    if ($moeda === 'USD' && strpos($codigo, '.') === false && strpos($codigo, '-') === false) {
        return $codigo;
    }
    return $codigo;
}

function mbiCotacaoDolar(PDO $pdo): ?float
{
    static $cotacao = null;
    static $tentou = false;
    if ($tentou) {
        return $cotacao;
    }
    $tentou = true;
    $cotacao = mbiBuscarCotacaoYahoo('USDBRL=X');
    return $cotacao;
}

mbiGarantirTabelas($pdo);

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $permitido && $empresaId === 2) {
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'salvar') {
            $id = (int)($_POST['id'] ?? 0);
            $contaId = (int)($_POST['conta_id'] ?? 0);
            $codigo = strtoupper(trim((string)($_POST['codigo'] ?? '')));
            $nome = trim((string)($_POST['nome'] ?? ''));
            $tipo = trim((string)($_POST['tipo'] ?? ''));
            $moeda = strtoupper(trim((string)($_POST['moeda'] ?? 'BRL')));
            $codigoCotacao = strtoupper(trim((string)($_POST['codigo_cotacao'] ?? '')));
            $quantidade = mbiDecimal($_POST['quantidade'] ?? '0');
            $precoMedio = mbiDecimal($_POST['preco_medio'] ?? '0');
            $dataPosicao = trim((string)($_POST['data_posicao'] ?? ''));
            $ativo = ($_POST['ativo'] ?? 'S') === 'S' ? 'S' : 'N';
            $observacao = trim((string)($_POST['observacao'] ?? ''));

            if ($contaId <= 0) {
                throw new RuntimeException('Informe a conta/custodia do ativo.');
            }
            if ($codigo === '') {
                throw new RuntimeException('Informe o codigo do ativo.');
            }
            if ($nome === '') {
                $nome = $codigo;
            }
            if ($tipo === '') {
                throw new RuntimeException('Informe o tipo do ativo.');
            }
            if (!in_array($moeda, ['BRL', 'USD'], true)) {
                $moeda = 'BRL';
            }
            if ($codigoCotacao === '') {
                $codigoCotacao = mbiSugestaoCotacao($codigo, $tipo, $moeda);
            }

            $params = [
                $contaId,
                $codigo,
                $nome,
                $tipo,
                $moeda,
                $codigoCotacao,
                $quantidade,
                $precoMedio,
                $dataPosicao !== '' ? $dataPosicao : null,
                $ativo,
                $observacao !== '' ? $observacao : null,
                $usuarioId ?: null,
            ];

            if ($id > 0 && mbiAtivo($pdo, $id)) {
                $params[] = $id;
                $stmt = $pdo->prepare("
                    UPDATE investimentos_ativos
                    SET conta_id = ?, codigo = ?, nome = ?, tipo = ?, moeda = ?, codigo_cotacao = ?,
                        quantidade = ?, preco_medio = ?, data_posicao = ?, ativo = ?, observacao = ?,
                        atualizado_por = ?
                    WHERE empresa_id = 2 AND id = ?
                ");
                $stmt->execute($params);
                $mensagem = 'Ativo atualizado com sucesso.';
            } else {
                array_unshift($params, 2);
                $params[] = $usuarioId ?: null;
                $stmt = $pdo->prepare("
                    INSERT INTO investimentos_ativos
                        (empresa_id, conta_id, codigo, nome, tipo, moeda, codigo_cotacao, quantidade, preco_medio,
                         data_posicao, ativo, observacao, atualizado_por, criado_por)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute($params);
                $mensagem = 'Ativo cadastrado com sucesso.';
            }
        } elseif ($acao === 'atualizar_cotacao') {
            $id = (int)($_POST['id'] ?? 0);
            $ativo = mbiAtivo($pdo, $id);
            if (!$ativo) {
                throw new RuntimeException('Ativo nao encontrado.');
            }
            $codigoCotacao = trim((string)($ativo['codigo_cotacao'] ?? ''));
            if ($codigoCotacao === '') {
                throw new RuntimeException('Informe o codigo de cotacao do ativo.');
            }
            $cotacao = mbiBuscarCotacaoYahoo($codigoCotacao);
            if ($cotacao === null) {
                throw new RuntimeException('Nao foi possivel buscar a cotacao de ' . $codigoCotacao . '.');
            }
            $stmt = $pdo->prepare("
                UPDATE investimentos_ativos
                SET cotacao_atual = ?, cotacao_em = NOW(), atualizado_por = ?
                WHERE empresa_id = 2 AND id = ?
            ");
            $stmt->execute([$cotacao, $usuarioId ?: null, $id]);
            $mensagem = 'Cotacao atualizada para ' . $codigoCotacao . '.';
        } elseif ($acao === 'atualizar_todas') {
            $stmt = $pdo->query("
                SELECT id, codigo_cotacao
                FROM investimentos_ativos
                WHERE empresa_id = 2
                  AND ativo = 'S'
                  AND codigo_cotacao IS NOT NULL
                  AND codigo_cotacao <> ''
            ");
            $ok = 0;
            $falhas = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
                $cotacao = mbiBuscarCotacaoYahoo((string)$linha['codigo_cotacao']);
                if ($cotacao === null) {
                    $falhas++;
                    continue;
                }
                $upd = $pdo->prepare("
                    UPDATE investimentos_ativos
                    SET cotacao_atual = ?, cotacao_em = NOW(), atualizado_por = ?
                    WHERE empresa_id = 2 AND id = ?
                ");
                $upd->execute([$cotacao, $usuarioId ?: null, (int)$linha['id']]);
                $ok++;
            }
            $mensagem = "Cotacoes atualizadas: {$ok}. Falhas: {$falhas}.";
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$editarId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$editar = ($permitido && $empresaId === 2 && $editarId > 0) ? mbiAtivo($pdo, $editarId) : null;

$contas = mbiContasInvestimento($pdo);
$mapaContas = [];
foreach ($contas as $conta) {
    $mapaContas[(int)$conta['CBCONTADOR']] = $conta;
}

$fConta = (int)($_GET['conta_id'] ?? 0);
$fTipo = trim((string)($_GET['tipo'] ?? ''));
$fMoeda = trim((string)($_GET['moeda'] ?? ''));
$fBusca = trim((string)($_GET['busca'] ?? ''));
$fSituacao = $_GET['situacao'] ?? 'ativos';

$where = ["ia.empresa_id = 2"];
$params = [];
if ($fConta > 0) {
    $where[] = 'ia.conta_id = ?';
    $params[] = $fConta;
}
if ($fTipo !== '') {
    $where[] = 'ia.tipo = ?';
    $params[] = $fTipo;
}
if ($fMoeda !== '') {
    $where[] = 'ia.moeda = ?';
    $params[] = $fMoeda;
}
if ($fBusca !== '') {
    $where[] = '(ia.codigo LIKE ? OR ia.nome LIKE ? OR ia.codigo_cotacao LIKE ?)';
    $like = '%' . $fBusca . '%';
    array_push($params, $like, $like, $like);
}
if ($fSituacao === 'ativos') {
    $where[] = "ia.ativo = 'S'";
} elseif ($fSituacao === 'inativos') {
    $where[] = "ia.ativo = 'N'";
}

$sql = "
    SELECT ia.*, b.TITULAR, b.DESCABREV, b.NUMERO, b.CLASSIFICACAO
    FROM investimentos_ativos ia
    LEFT JOIN armazem_bnc002 b
           ON b.EMPRESA = ia.empresa_id
          AND b.CBCONTADOR = ia.conta_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.TITULAR, ia.tipo, ia.codigo
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ativos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dolar = null;
$totais = [
    'custo_brl' => 0.0,
    'atual_brl' => 0.0,
    'por_tipo' => [],
    'por_conta' => [],
];
foreach ($ativos as $ativo) {
    $quantidade = (float)$ativo['quantidade'];
    $custo = $quantidade * (float)$ativo['preco_medio'];
    $atual = $quantidade * (float)($ativo['cotacao_atual'] ?? 0);
    $moeda = (string)$ativo['moeda'];
    $fator = 1.0;
    if ($moeda === 'USD') {
        $dolar = $dolar ?? mbiCotacaoDolar($pdo);
        $fator = $dolar ?: 1.0;
    }
    $custoBrl = $custo * $fator;
    $atualBrl = $atual * $fator;
    $totais['custo_brl'] += $custoBrl;
    $totais['atual_brl'] += $atualBrl;

    $tipo = (string)$ativo['tipo'];
    if (!isset($totais['por_tipo'][$tipo])) {
        $totais['por_tipo'][$tipo] = ['custo' => 0.0, 'atual' => 0.0];
    }
    $totais['por_tipo'][$tipo]['custo'] += $custoBrl;
    $totais['por_tipo'][$tipo]['atual'] += $atualBrl;

    $contaNome = mbiNomeConta($ativo);
    if (!isset($totais['por_conta'][$contaNome])) {
        $totais['por_conta'][$contaNome] = ['custo' => 0.0, 'atual' => 0.0];
    }
    $totais['por_conta'][$contaNome]['custo'] += $custoBrl;
    $totais['por_conta'][$contaNome]['atual'] += $atualBrl;
}

$tipos = [
    'ACAO_BR' => 'Acao Brasil',
    'BDR' => 'BDR',
    'FII' => 'FII',
    'ETF_BR' => 'ETF Brasil',
    'ACAO_US' => 'Acao EUA',
    'CRIPTO' => 'Cripto',
    'RENDA_FIXA' => 'Renda Fixa',
    'PREVIDENCIA' => 'Previdencia',
    'OUTRO' => 'Outro',
];

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
        <div>
            <span class="badge text-bg-success mb-2">Empresa 2</span>
            <h1 class="h3 fw-bold mb-1">Investimentos</h1>
            <p class="text-muted mb-0">Carteira inicial, custo medio e valor atual por conta/custodia.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="menu_movimentacao_baixa.php" class="btn btn-outline-secondary">Voltar</a>
            <?php if ($permitido && $empresaId === 2): ?>
                <form method="post" class="d-inline">
                    <input type="hidden" name="acao" value="atualizar_todas">
                    <button class="btn btn-success" type="submit">Atualizar cotacoes</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (!$permitido): ?>
    <div class="alert alert-warning">Voce nao tem permissao para acessar este modulo.</div>
<?php elseif ($empresaId !== 2): ?>
    <div class="alert alert-info">Controle de investimentos disponivel somente para a empresa 2.</div>
<?php else: ?>
    <?php if ($mensagem): ?><div class="alert alert-success"><?= mbiH($mensagem) ?></div><?php endif; ?>
    <?php if ($erro): ?><div class="alert alert-danger"><?= mbiH($erro) ?></div><?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Custo total</div>
                    <div class="h4 fw-bold mb-0"><?= mbiMoeda($totais['custo_brl']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Valor atual</div>
                    <div class="h4 fw-bold mb-0"><?= mbiMoeda($totais['atual_brl']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <?php
            $resultado = $totais['atual_brl'] - $totais['custo_brl'];
            $perc = $totais['custo_brl'] > 0 ? ($resultado / $totais['custo_brl']) * 100 : 0;
            ?>
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Resultado</div>
                    <div class="h4 fw-bold mb-0 <?= $resultado >= 0 ? 'text-success' : 'text-danger' ?>">
                        <?= mbiMoeda($resultado) ?> <span class="fs-6">(<?= number_format($perc, 2, ',', '.') ?>%)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold"><?= $editar ? 'Editar ativo' : 'Cadastrar ativo / posicao inicial' ?></div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" value="<?= (int)($editar['id'] ?? 0) ?>">

                <div class="col-md-4">
                    <label class="form-label">Conta / custodia</label>
                    <select name="conta_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($contas as $conta): ?>
                            <?php $sel = (int)($editar['conta_id'] ?? 0) === (int)$conta['CBCONTADOR']; ?>
                            <option value="<?= (int)$conta['CBCONTADOR'] ?>" <?= $sel ? 'selected' : '' ?>>
                                <?= (int)$conta['CBCONTADOR'] ?> - <?= mbiH(mbiNomeConta($conta)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Use preferencialmente contas BNC002 classificadas como Investimento.</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Codigo</label>
                    <input name="codigo" class="form-control text-uppercase" value="<?= mbiH($editar['codigo'] ?? '') ?>" placeholder="PETR4" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Descricao</label>
                    <input name="nome" class="form-control" value="<?= mbiH($editar['nome'] ?? '') ?>" placeholder="Petrobras PN">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Situacao</label>
                    <select name="ativo" class="form-select">
                        <option value="S" <?= ($editar['ativo'] ?? 'S') === 'S' ? 'selected' : '' ?>>Ativo</option>
                        <option value="N" <?= ($editar['ativo'] ?? 'S') === 'N' ? 'selected' : '' ?>>Inativo</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($tipos as $codigo => $nome): ?>
                            <option value="<?= mbiH($codigo) ?>" <?= ($editar['tipo'] ?? '') === $codigo ? 'selected' : '' ?>><?= mbiH($nome) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Moeda</label>
                    <select name="moeda" class="form-select">
                        <option value="BRL" <?= ($editar['moeda'] ?? 'BRL') === 'BRL' ? 'selected' : '' ?>>BRL</option>
                        <option value="USD" <?= ($editar['moeda'] ?? 'BRL') === 'USD' ? 'selected' : '' ?>>USD</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Codigo cotacao</label>
                    <input name="codigo_cotacao" class="form-control text-uppercase" value="<?= mbiH($editar['codigo_cotacao'] ?? '') ?>" placeholder="PETR4.SA / BTC-BRL / AAPL">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Quantidade</label>
                    <input name="quantidade" inputmode="decimal" class="form-control" value="<?= isset($editar['quantidade']) ? mbiNumero($editar['quantidade']) : '' ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Preco medio</label>
                    <input name="preco_medio" inputmode="decimal" class="form-control" value="<?= isset($editar['preco_medio']) ? mbiNumero($editar['preco_medio']) : '' ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data posicao</label>
                    <input type="date" name="data_posicao" class="form-control" value="<?= mbiH($editar['data_posicao'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-10">
                    <label class="form-label">Observacao</label>
                    <input name="observacao" class="form-control" value="<?= mbiH($editar['observacao'] ?? '') ?>">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Salvar ativo</button>
                    <?php if ($editar): ?><a href="investimentos.php" class="btn btn-outline-secondary">Novo ativo</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Filtros</div>
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Conta</label>
                    <select name="conta_id" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($contas as $conta): ?>
                            <option value="<?= (int)$conta['CBCONTADOR'] ?>" <?= $fConta === (int)$conta['CBCONTADOR'] ? 'selected' : '' ?>>
                                <?= (int)$conta['CBCONTADOR'] ?> - <?= mbiH(mbiNomeConta($conta)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($tipos as $codigo => $nome): ?>
                            <option value="<?= mbiH($codigo) ?>" <?= $fTipo === $codigo ? 'selected' : '' ?>><?= mbiH($nome) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Moeda</label>
                    <select name="moeda" class="form-select">
                        <option value="">Todas</option>
                        <option value="BRL" <?= $fMoeda === 'BRL' ? 'selected' : '' ?>>BRL</option>
                        <option value="USD" <?= $fMoeda === 'USD' ? 'selected' : '' ?>>USD</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Situacao</label>
                    <select name="situacao" class="form-select">
                        <option value="ativos" <?= $fSituacao === 'ativos' ? 'selected' : '' ?>>Ativos</option>
                        <option value="inativos" <?= $fSituacao === 'inativos' ? 'selected' : '' ?>>Inativos</option>
                        <option value="todos" <?= $fSituacao === 'todos' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Busca</label>
                    <input name="busca" class="form-control" value="<?= mbiH($fBusca) ?>" placeholder="Codigo, descricao ou cotacao">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Filtrar</button>
                    <a href="investimentos.php" class="btn btn-outline-secondary">Limpar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Ativos da carteira</span>
            <span class="badge text-bg-light"><?= count($ativos) ?> ativo(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Ativo</th>
                        <th>Conta</th>
                        <th>Tipo</th>
                        <th class="text-end">Qtd.</th>
                        <th class="text-end">Preco medio</th>
                        <th class="text-end">Custo</th>
                        <th class="text-end">Cotacao</th>
                        <th class="text-end">Valor atual</th>
                        <th class="text-end">Resultado</th>
                        <th>Cotacao em</th>
                        <th class="text-end">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ativos as $ativo): ?>
                        <?php
                        $quantidade = (float)$ativo['quantidade'];
                        $precoMedio = (float)$ativo['preco_medio'];
                        $cotacao = (float)($ativo['cotacao_atual'] ?? 0);
                        $custo = $quantidade * $precoMedio;
                        $valorAtual = $quantidade * $cotacao;
                        $resultadoAtivo = $valorAtual - $custo;
                        $moeda = (string)$ativo['moeda'];
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= mbiH($ativo['codigo']) ?></div>
                                <div class="text-muted small"><?= mbiH($ativo['nome']) ?></div>
                                <?php if (($ativo['ativo'] ?? 'S') !== 'S'): ?><span class="badge text-bg-secondary">Inativo</span><?php endif; ?>
                            </td>
                            <td class="small"><?= mbiH(mbiNomeConta($ativo)) ?></td>
                            <td><?= mbiH($tipos[$ativo['tipo']] ?? $ativo['tipo']) ?></td>
                            <td class="text-end"><?= mbiNumero($quantidade) ?></td>
                            <td class="text-end"><?= mbiMoeda($precoMedio, $moeda) ?></td>
                            <td class="text-end fw-semibold"><?= mbiMoeda($custo, $moeda) ?></td>
                            <td class="text-end">
                                <?= $cotacao > 0 ? mbiMoeda($cotacao, $moeda) : '-' ?>
                                <div class="text-muted small"><?= mbiH($ativo['codigo_cotacao'] ?? '') ?></div>
                            </td>
                            <td class="text-end fw-semibold"><?= $cotacao > 0 ? mbiMoeda($valorAtual, $moeda) : '-' ?></td>
                            <td class="text-end <?= $resultadoAtivo >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $cotacao > 0 ? mbiMoeda($resultadoAtivo, $moeda) : '-' ?>
                            </td>
                            <td class="small">
                                <?= !empty($ativo['cotacao_em']) ? date('d/m/Y H:i', strtotime($ativo['cotacao_em'])) : '-' ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a class="btn btn-outline-primary" href="investimentos.php?editar=<?= (int)$ativo['id'] ?>">Editar</a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="acao" value="atualizar_cotacao">
                                        <input type="hidden" name="id" value="<?= (int)$ativo['id'] ?>">
                                        <button class="btn btn-outline-success" type="submit">Cotacao</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$ativos): ?>
                        <tr><td colspan="11" class="text-center text-muted py-4">Nenhum ativo encontrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Resumo por tipo</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Tipo</th><th class="text-end">Custo</th><th class="text-end">Atual</th><th class="text-end">Resultado</th></tr></thead>
                        <tbody>
                            <?php foreach ($totais['por_tipo'] as $tipo => $linha): ?>
                                <tr>
                                    <td><?= mbiH($tipos[$tipo] ?? $tipo) ?></td>
                                    <td class="text-end"><?= mbiMoeda($linha['custo']) ?></td>
                                    <td class="text-end"><?= mbiMoeda($linha['atual']) ?></td>
                                    <td class="text-end <?= ($linha['atual'] - $linha['custo']) >= 0 ? 'text-success' : 'text-danger' ?>"><?= mbiMoeda($linha['atual'] - $linha['custo']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$totais['por_tipo']): ?><tr><td colspan="4" class="text-muted text-center">Sem dados.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Resumo por custodia</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Conta</th><th class="text-end">Custo</th><th class="text-end">Atual</th><th class="text-end">Resultado</th></tr></thead>
                        <tbody>
                            <?php foreach ($totais['por_conta'] as $conta => $linha): ?>
                                <tr>
                                    <td><?= mbiH($conta) ?></td>
                                    <td class="text-end"><?= mbiMoeda($linha['custo']) ?></td>
                                    <td class="text-end"><?= mbiMoeda($linha['atual']) ?></td>
                                    <td class="text-end <?= ($linha['atual'] - $linha['custo']) >= 0 ? 'text-success' : 'text-danger' ?>"><?= mbiMoeda($linha['atual'] - $linha['custo']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$totais['por_conta']): ?><tr><td colspan="4" class="text-muted text-center">Sem dados.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require '../../layout/footer.php'; ?>
