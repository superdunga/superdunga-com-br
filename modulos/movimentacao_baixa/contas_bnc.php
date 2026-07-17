<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require __DIR__ . '/_empresa2_guard.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$perfil = strtoupper((string)($_SESSION['nivel'] ?? ''));
$permitido = moduloPermitido($pdo, $empresaId, 'movimentacao_baixa_contas_bnc', $perfil);

function mbc2H($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function mbc2Colunas(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'armazem_bnc002'
    ");
    $stmt->execute();
    $cache = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    return $cache;
}

function mbc2TemColuna(PDO $pdo, string $coluna): bool
{
    $colunas = mbc2Colunas($pdo);
    return isset($colunas[$coluna]);
}

function mbc2ProximaConta(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COALESCE(MAX(CBCONTADOR), 0) + 1 FROM armazem_bnc002 WHERE EMPRESA = 2");
    return (int)$stmt->fetchColumn();
}

function mbc2Conta(PDO $pdo, int $cbcontador): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_bnc002
        WHERE EMPRESA = 2
          AND CBCONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$cbcontador]);
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);
    return $conta ?: null;
}

function mbc2NomeConta(array $conta): string
{
    $partes = [];
    foreach (['TITULAR', 'DESCABREV', 'NUMERO'] as $campo) {
        $valor = trim((string)($conta[$campo] ?? ''));
        if ($valor !== '') {
            $partes[] = $valor;
        }
    }
    return $partes ? implode(' | ', array_unique($partes)) : ('Conta ' . (int)($conta['CBCONTADOR'] ?? 0));
}

function mbc2ClassificacaoNome($classificacao): string
{
    $classificacao = (string)$classificacao;
    $nomes = [
        '1' => 'Caixa operacional',
        '2' => 'Banco / conta corrente',
        '3' => 'Investimento / custodia',
        '4' => 'Controle interno',
    ];
    return $nomes[$classificacao] ?? ($classificacao !== '' ? 'Classificacao ' . $classificacao : 'Nao informada');
}

function mbc2ClassificacaoAjuda($classificacao): string
{
    $classificacao = (string)$classificacao;
    $ajudas = [
        '1' => 'Usada para caixa fisico e movimentacoes de dinheiro.',
        '2' => 'Usada para contas bancarias, extratos e conciliacao bancaria.',
        '3' => 'Usada para carteira, corretora, previdencia ou conta de investimento.',
        '4' => 'Usada para controle interno e contrapartidas que nao devem receber extrato.',
    ];
    return $ajudas[$classificacao] ?? 'Defina o tipo para orientar onde esta conta deve aparecer no sistema.';
}

function mbc2UsoContas(PDO $pdo, array $contasIds): array
{
    $contasIds = array_values(array_unique(array_filter(array_map('intval', $contasIds))));
    if (!$contasIds) {
        return [];
    }

    $usos = [];
    foreach ($contasIds as $id) {
        $usos[$id] = ['bnc' => 0, 'tipoes' => 0, 'saldo' => 0.0];
    }

    $ph = implode(',', array_fill(0, count($contasIds), '?'));

    $stmt = $pdo->prepare("
        SELECT CBCONTADOR, COUNT(*) AS qtd,
               SUM(CASE WHEN TIPOMOV = 'C' THEN VALORMOV ELSE -VALORMOV END) AS saldo
        FROM armazem_bnc001
        WHERE EMPRESA = 2
          AND CBCONTADOR IN ($ph)
          AND COALESCE(deletado, 'N') <> 'S'
        GROUP BY CBCONTADOR
    ");
    $stmt->execute($contasIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $id = (int)$linha['CBCONTADOR'];
        if (isset($usos[$id])) {
            $usos[$id]['bnc'] = (int)$linha['qtd'];
            $usos[$id]['saldo'] = (float)$linha['saldo'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT CONTRAP_CBCONTADOR AS cbcontador, COUNT(*) AS qtd
        FROM armazem_bnc005
        WHERE EMPRESA = 2
          AND CONTRAP_CBCONTADOR IN ($ph)
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        GROUP BY CONTRAP_CBCONTADOR
    ");
    $stmt->execute($contasIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $id = (int)$linha['cbcontador'];
        if (isset($usos[$id])) {
            $usos[$id]['tipoes'] = (int)$linha['qtd'];
        }
    }

    return $usos;
}

function mbc2Moeda($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function mbc2SalvarConta(PDO $pdo, int $usuarioId, array $dados): int
{
    $cbcontador = (int)($dados['cbcontador'] ?? 0);
    $titular = trim((string)($dados['titular'] ?? ''));
    $descabrev = trim((string)($dados['descabrev'] ?? ''));
    $numero = trim((string)($dados['numero'] ?? ''));
    $classificacao = trim((string)($dados['classificacao'] ?? ''));
    $bloqueada = ($_POST['contabloqueada'] ?? 'N') === 'S' ? 'S' : 'N';

    if ($titular === '' && $descabrev === '' && $numero === '') {
        throw new RuntimeException('Informe ao menos titular, descricao ou numero da conta.');
    }

    $colunas = mbc2Colunas($pdo);
    $campos = [
        'TITULAR' => $titular,
        'DESCABREV' => $descabrev,
        'NUMERO' => $numero,
        'CLASSIFICACAO' => $classificacao !== '' ? $classificacao : null,
        'CONTABLOQUEADA' => $bloqueada,
        'REGDISAB' => $bloqueada,
        'REGIMPORT' => 'N',
        'USERALT' => $usuarioId ?: null,
        'DTALT' => date('Y-m-d H:i:s'),
        'REGSTAMP' => date('Y-m-d H:i:s'),
    ];

    $campos = array_filter($campos, static function ($valor, $campo) use ($colunas) {
        return isset($colunas[$campo]);
    }, ARRAY_FILTER_USE_BOTH);

    if ($cbcontador > 0 && mbc2Conta($pdo, $cbcontador)) {
        $sets = [];
        $params = [];
        foreach ($campos as $campo => $valor) {
            $sets[] = "$campo = ?";
            $params[] = $valor;
        }
        $params[] = $cbcontador;
        $sql = "UPDATE armazem_bnc002 SET " . implode(', ', $sets) . " WHERE EMPRESA = 2 AND CBCONTADOR = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $cbcontador;
    }

    $cbcontador = $cbcontador > 0 ? $cbcontador : mbc2ProximaConta($pdo);
    if (mbc2Conta($pdo, $cbcontador)) {
        throw new RuntimeException('Ja existe uma conta com este codigo.');
    }

    $camposInsert = [
        'EMPRESA' => 2,
        'CBCONTADOR' => $cbcontador,
        'USERLANC' => $usuarioId ?: null,
        'DTLANC' => date('Y-m-d H:i:s'),
        'excluido_firebird' => 'N',
    ];
    foreach ($campos as $campo => $valor) {
        $camposInsert[$campo] = $valor;
    }
    $camposInsert = array_filter($camposInsert, static function ($valor, $campo) use ($colunas) {
        return isset($colunas[$campo]);
    }, ARRAY_FILTER_USE_BOTH);
    $camposInsert['EMPRESA'] = 2;
    $camposInsert['CBCONTADOR'] = $cbcontador;

    $nomes = array_keys($camposInsert);
    $placeholders = implode(', ', array_fill(0, count($nomes), '?'));
    $stmt = $pdo->prepare("INSERT INTO armazem_bnc002 (" . implode(', ', $nomes) . ") VALUES ($placeholders)");
    $stmt->execute(array_values($camposInsert));

    return $cbcontador;
}

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $permitido && $empresaId === 2) {
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'salvar') {
            $cb = mbc2SalvarConta($pdo, $usuarioId, $_POST);
            $mensagem = 'Conta #' . $cb . ' salva com sucesso.';
        } elseif ($acao === 'bloquear') {
            $cbcontador = (int)($_POST['cbcontador'] ?? 0);
            $conta = mbc2Conta($pdo, $cbcontador);
            if (!$conta) {
                throw new RuntimeException('Conta nao encontrada.');
            }
            $nova = (($conta['CONTABLOQUEADA'] ?? 'N') === 'S' || ($conta['REGDISAB'] ?? 'N') === 'S') ? 'N' : 'S';
            $sets = [];
            $params = [];
            foreach (['CONTABLOQUEADA', 'REGDISAB'] as $campo) {
                if (mbc2TemColuna($pdo, $campo)) {
                    $sets[] = "$campo = ?";
                    $params[] = $nova;
                }
            }
            foreach (['USERALT' => $usuarioId ?: null, 'DTALT' => date('Y-m-d H:i:s'), 'REGSTAMP' => date('Y-m-d H:i:s'), 'REGIMPORT' => 'N'] as $campo => $valor) {
                if (mbc2TemColuna($pdo, $campo)) {
                    $sets[] = "$campo = ?";
                    $params[] = $valor;
                }
            }
            $params[] = $cbcontador;
            $pdo->prepare("UPDATE armazem_bnc002 SET " . implode(', ', $sets) . " WHERE EMPRESA = 2 AND CBCONTADOR = ?")->execute($params);
            $mensagem = 'Situacao da conta alterada.';
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$editarId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$editar = ($permitido && $empresaId === 2 && $editarId > 0) ? mbc2Conta($pdo, $editarId) : null;

$fCodigo = trim((string)($_GET['codigo'] ?? ''));
$fBusca = trim((string)($_GET['busca'] ?? ''));
$fClassificacao = trim((string)($_GET['classificacao'] ?? ''));
$fSituacao = $_GET['situacao'] ?? 'ativas';

$contas = [];
$usos = [];
$totais = ['qtd' => 0, 'ativas' => 0, 'bloqueadas' => 0, 'investimento' => 0];

if ($permitido && $empresaId === 2) {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS qtd,
            SUM(CASE WHEN COALESCE(CONTABLOQUEADA, 'N') = 'S' THEN 0 ELSE 1 END) AS ativas,
            SUM(CASE WHEN COALESCE(CONTABLOQUEADA, 'N') = 'S' THEN 1 ELSE 0 END) AS bloqueadas,
            SUM(CASE WHEN CLASSIFICACAO = 3 THEN 1 ELSE 0 END) AS investimento
        FROM armazem_bnc002
        WHERE EMPRESA = 2
          AND COALESCE(excluido_firebird, 'N') <> 'S'
    ");
    $totais = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: $totais);

    $where = ["EMPRESA = 2", "COALESCE(excluido_firebird, 'N') <> 'S'"];
    $params = [];
    if ($fCodigo !== '' && ctype_digit($fCodigo)) {
        $where[] = "CBCONTADOR = ?";
        $params[] = (int)$fCodigo;
    }
    if ($fBusca !== '') {
        $where[] = "(TITULAR LIKE ? OR DESCABREV LIKE ? OR NUMERO LIKE ?)";
        $like = '%' . $fBusca . '%';
        array_push($params, $like, $like, $like);
    }
    if ($fClassificacao !== '' && ctype_digit($fClassificacao)) {
        $where[] = "CLASSIFICACAO = ?";
        $params[] = (int)$fClassificacao;
    }
    if ($fSituacao === 'ativas') {
        $where[] = "COALESCE(CONTABLOQUEADA, 'N') <> 'S'";
    } elseif ($fSituacao === 'bloqueadas') {
        $where[] = "COALESCE(CONTABLOQUEADA, 'N') = 'S'";
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_bnc002
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            COALESCE(CLASSIFICACAO, 999999),
            CASE WHEN TRIM(COALESCE(TITULAR, '')) = '' THEN 1 ELSE 0 END,
            TITULAR,
            DESCABREV,
            CBCONTADOR
        LIMIT 500
    ");
    $stmt->execute($params);
    $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $usos = mbc2UsoContas($pdo, array_column($contas, 'CBCONTADOR'));
}

$form = [
    'cbcontador' => $editar['CBCONTADOR'] ?? '',
    'titular' => $editar['TITULAR'] ?? '',
    'descabrev' => $editar['DESCABREV'] ?? '',
    'numero' => $editar['NUMERO'] ?? '',
    'classificacao' => $editar['CLASSIFICACAO'] ?? '',
    'contabloqueada' => (($editar['CONTABLOQUEADA'] ?? 'N') === 'S') ? 'S' : 'N',
];

require '../../layout/header.php';
?>

<style>
.mbc2-wrap { max-width: 1280px; margin: 0 auto; }
.mbc2-hero { background:#123c69; color:#fff; border-radius:8px; padding:24px; display:flex; justify-content:space-between; gap:16px; align-items:center; }
.mbc2-card { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:18px; margin-top:16px; }
.mbc2-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
.mbc2-field label { display:block; font-size:12px; font-weight:700; color:#495057; margin-bottom:4px; }
.mbc2-field input, .mbc2-field select { width:100%; border:1px solid #ced4da; border-radius:6px; padding:9px 10px; background:#fff; }
.mbc2-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.mbc2-metrics { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-top:16px; }
.mbc2-metric { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:14px; }
.mbc2-metric small { display:block; color:#64748b; font-weight:700; }
.mbc2-metric strong { font-size:22px; color:#0f172a; }
.mbc2-table { width:100%; border-collapse:collapse; font-size:13px; }
.mbc2-table th, .mbc2-table td { border-bottom:1px solid #e9ecef; padding:9px; vertical-align:top; }
.mbc2-table th { background:#f1f5f9; font-size:12px; text-transform:uppercase; color:#334155; white-space:nowrap; }
.mbc2-pill { display:inline-flex; border-radius:999px; padding:3px 8px; font-size:12px; font-weight:700; background:#f1f5f9; color:#334155; }
.mbc2-pill.ok { background:#dcfce7; color:#166534; }
.mbc2-pill.off { background:#fee2e2; color:#991b1b; }
.mbc2-pill.inv { background:#e0f2fe; color:#075985; }
.mbc2-help { border-left:4px solid #0d6efd; background:#f8fbff; padding:12px 14px; border-radius:6px; color:#334155; }
@media (max-width: 900px) {
    .mbc2-hero { display:block; }
    .mbc2-grid, .mbc2-metrics { grid-template-columns:1fr; }
    .mbc2-scroll { overflow-x:auto; }
    .mbc2-table { min-width:980px; }
}
</style>

<div class="mbc2-wrap">
    <section class="mbc2-hero">
        <div>
            <span class="badge text-bg-light mb-2">Mov/Baixa</span>
            <h1 class="h4 fw-bold mb-1">Cadastro de Contas BNC002</h1>
            <p class="mb-0 opacity-75">Contas caixa, banco e investimento da empresa 2.</p>
        </div>
        <a href="menu_movimentacao_baixa.php" class="btn btn-outline-light">Voltar</a>
    </section>

    <?php if (!$permitido): ?>
        <div class="alert alert-warning mt-3">Voce nao tem permissao para acessar este cadastro.</div>
    <?php elseif ($empresaId !== 2): ?>
        <div class="alert alert-info mt-3">Cadastro disponivel somente para a empresa 2, pois esta empresa usa as tabelas espelhadas sem Firebird proprio.</div>
    <?php else: ?>
        <?php if ($mensagem): ?><div class="alert alert-success mt-3"><?= mbc2H($mensagem) ?></div><?php endif; ?>
        <?php if ($erro): ?><div class="alert alert-danger mt-3"><?= mbc2H($erro) ?></div><?php endif; ?>

        <section class="mbc2-metrics">
            <div class="mbc2-metric"><small>Total</small><strong><?= (int)$totais['qtd'] ?></strong></div>
            <div class="mbc2-metric"><small>Ativas</small><strong><?= (int)$totais['ativas'] ?></strong></div>
            <div class="mbc2-metric"><small>Bloqueadas</small><strong><?= (int)$totais['bloqueadas'] ?></strong></div>
            <div class="mbc2-metric"><small>Investimento/custodia</small><strong><?= (int)$totais['investimento'] ?></strong></div>
        </section>

        <section class="mbc2-card">
            <h2 class="h6 fw-bold mb-3"><?= $editar ? 'Editar conta #' . (int)$editar['CBCONTADOR'] : 'Nova conta' ?></h2>
            <div class="mbc2-help mb-3">
                O tipo da conta define onde ela aparece no sistema: caixa e banco entram nas movimentacoes e conciliacoes, investimento/custodia fica para carteira e aplicacoes, e controle interno serve para contrapartidas que nao devem receber extrato.
            </div>
            <form method="post">
                <input type="hidden" name="acao" value="salvar">
                <div class="mbc2-grid">
                    <div class="mbc2-field">
                        <label>Codigo</label>
                        <input type="number" name="cbcontador" value="<?= mbc2H($form['cbcontador']) ?>" placeholder="Automatico se vazio">
                    </div>
                    <div class="mbc2-field">
                        <label>Tipo da conta no sistema</label>
                        <select name="classificacao">
                            <option value="">Nao informada</option>
                            <option value="1" <?= (string)$form['classificacao'] === '1' ? 'selected' : '' ?>>1 - Caixa operacional</option>
                            <option value="2" <?= (string)$form['classificacao'] === '2' ? 'selected' : '' ?>>2 - Banco / conta corrente</option>
                            <option value="3" <?= (string)$form['classificacao'] === '3' ? 'selected' : '' ?>>3 - Investimento / custodia</option>
                            <option value="4" <?= (string)$form['classificacao'] === '4' ? 'selected' : '' ?>>4 - Controle interno</option>
                        </select>
                    </div>
                    <div class="mbc2-field" style="grid-column:span 2;">
                        <label>Titular/Nome da conta</label>
                        <input type="text" name="titular" value="<?= mbc2H($form['titular']) ?>">
                    </div>
                    <div class="mbc2-field" style="grid-column:span 2;">
                        <label>Descricao abreviada</label>
                        <input type="text" name="descabrev" value="<?= mbc2H($form['descabrev']) ?>">
                    </div>
                    <div class="mbc2-field">
                        <label>Numero</label>
                        <input type="text" name="numero" value="<?= mbc2H($form['numero']) ?>">
                    </div>
                    <div class="mbc2-field">
                        <label>Situacao</label>
                        <select name="contabloqueada">
                            <option value="N" <?= $form['contabloqueada'] !== 'S' ? 'selected' : '' ?>>Ativa</option>
                            <option value="S" <?= $form['contabloqueada'] === 'S' ? 'selected' : '' ?>>Bloqueada</option>
                        </select>
                    </div>
                </div>
                <div class="mbc2-help mt-3">
                    <strong>Resumo dos tipos:</strong>
                    1 = caixa fisico; 2 = banco/conta corrente; 3 = investimento, corretora, cripto ou previdencia; 4 = controle interno/contrapartida.
                </div>
                <div class="mbc2-actions mt-3">
                    <button class="btn btn-primary">Salvar conta</button>
                    <a href="contas_bnc.php" class="btn btn-outline-secondary">Nova/Limpar</a>
                </div>
            </form>
        </section>

        <section class="mbc2-card">
            <h2 class="h6 fw-bold mb-3">Filtros</h2>
            <form method="get" class="mbc2-grid">
                <div class="mbc2-field">
                    <label>Codigo</label>
                    <input type="number" name="codigo" value="<?= mbc2H($fCodigo) ?>">
                </div>
                <div class="mbc2-field" style="grid-column:span 2;">
                    <label>Busca</label>
                    <input type="text" name="busca" value="<?= mbc2H($fBusca) ?>" placeholder="Titular, descricao ou numero">
                </div>
                <div class="mbc2-field">
                    <label>Tipo da conta</label>
                    <select name="classificacao">
                        <option value="">Todas</option>
                        <option value="1" <?= $fClassificacao === '1' ? 'selected' : '' ?>>Caixa operacional</option>
                        <option value="2" <?= $fClassificacao === '2' ? 'selected' : '' ?>>Banco / conta corrente</option>
                        <option value="3" <?= $fClassificacao === '3' ? 'selected' : '' ?>>Investimento / custodia</option>
                        <option value="4" <?= $fClassificacao === '4' ? 'selected' : '' ?>>Controle interno</option>
                    </select>
                </div>
                <div class="mbc2-field">
                    <label>Situacao</label>
                    <select name="situacao">
                        <option value="ativas" <?= $fSituacao === 'ativas' ? 'selected' : '' ?>>Ativas</option>
                        <option value="bloqueadas" <?= $fSituacao === 'bloqueadas' ? 'selected' : '' ?>>Bloqueadas</option>
                        <option value="todas" <?= $fSituacao === 'todas' ? 'selected' : '' ?>>Todas</option>
                    </select>
                </div>
                <div class="mbc2-actions" style="align-self:end;">
                    <button class="btn btn-outline-primary">Filtrar</button>
                    <a href="contas_bnc.php" class="btn btn-outline-secondary">Limpar</a>
                </div>
            </form>
        </section>

        <section class="mbc2-card">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                <h2 class="h6 fw-bold mb-0">Contas cadastradas</h2>
                <span class="text-muted small"><?= count($contas) ?> registro(s) filtrado(s)</span>
            </div>
            <div class="mbc2-scroll">
                <table class="mbc2-table">
                    <thead>
                        <tr>
                            <th>Codigo</th>
                            <th>Conta</th>
                            <th>Tipo da conta</th>
                            <th>Uso</th>
                            <th>Saldo sistema</th>
                            <th>Situacao</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contas as $conta): ?>
                            <?php
                                $idConta = (int)$conta['CBCONTADOR'];
                                $uso = $usos[$idConta] ?? ['bnc' => 0, 'tipoes' => 0, 'saldo' => 0.0];
                                $bloqueada = (($conta['CONTABLOQUEADA'] ?? 'N') === 'S');
                            ?>
                            <tr>
                                <td><strong><?= $idConta ?></strong></td>
                                <td>
                                    <div class="fw-semibold"><?= mbc2H(mbc2NomeConta($conta)) ?></div>
                                    <div class="text-muted small">
                                        Titular: <?= mbc2H($conta['TITULAR'] ?? '-') ?> |
                                        Desc.: <?= mbc2H($conta['DESCABREV'] ?? '-') ?> |
                                        Num.: <?= mbc2H($conta['NUMERO'] ?? '-') ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="mbc2-pill <?= (string)($conta['CLASSIFICACAO'] ?? '') === '3' ? 'inv' : '' ?>">
                                        <?= mbc2H(mbc2ClassificacaoNome($conta['CLASSIFICACAO'] ?? '')) ?>
                                    </span>
                                    <div class="text-muted small mt-1"><?= mbc2H(mbc2ClassificacaoAjuda($conta['CLASSIFICACAO'] ?? '')) ?></div>
                                </td>
                                <td class="small">
                                    BNC001: <?= (int)$uso['bnc'] ?><br>
                                    TipoES contrapartida: <?= (int)$uso['tipoes'] ?>
                                </td>
                                <td class="<?= (float)$uso['saldo'] < 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= mbc2H(mbc2Moeda($uso['saldo'])) ?>
                                </td>
                                <td>
                                    <?php if ($bloqueada): ?>
                                        <span class="mbc2-pill off">Bloqueada</span>
                                    <?php else: ?>
                                        <span class="mbc2-pill ok">Ativa</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="mbc2-actions">
                                        <a href="contas_bnc.php?editar=<?= $idConta ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                        <form method="post" onsubmit="return confirm('Alterar a situacao desta conta?');">
                                            <input type="hidden" name="acao" value="bloquear">
                                            <input type="hidden" name="cbcontador" value="<?= $idConta ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $bloqueada ? 'Ativar' : 'Bloquear' ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$contas): ?>
                            <tr><td colspan="7" class="text-muted">Nenhuma conta encontrada.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_select_busca.php'; ?>
<?php require '../../layout/footer.php'; ?>
