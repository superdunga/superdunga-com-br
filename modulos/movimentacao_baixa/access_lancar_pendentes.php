<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require __DIR__ . '/_empresa2_guard.php';
require __DIR__ . '/_access_comparacao_lib.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
acGarantir($pdo);

$mensagem = '';
$erro = '';

function alpSelecionarAccess(PDO $pdo, int $empresaId, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT *
        FROM mov_baixa_lancamentos_access
        WHERE empresa_id = ?
          AND id IN ({$placeholders})
          AND COALESCE(enviado_superdunga, 'N') <> 'S'
          AND tabela_destino IS NULL
          AND id_destino IS NULL
        ORDER BY linha_origem
    ");
    $stmt->execute(array_merge([$empresaId], $ids));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function alpTipoSelecionado(PDO $pdo, int $empresaId, int $tipoes): array
{
    $stmt = $pdo->prepare("
        SELECT ESCONTADOR, DESCES, TIPOMOV
        FROM armazem_bnc005
        WHERE EMPRESA = ?
          AND ESCONTADOR = ?
          AND COALESCE(REGDISAB, 'N') <> 'S'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $tipoes]);
    $tipo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tipo) {
        throw new RuntimeException('Tipo ES nao encontrado ou inativo.');
    }
    return $tipo;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'lancar_selecionados') {
    try {
        $ids = $_POST['access_ids'] ?? [];
        $destino = (string)($_POST['destino'] ?? '');
        $tipoes = (int)($_POST['tipoes'] ?? 0);
        $historico = trim((string)($_POST['historico'] ?? ''));
        $dataOrigem = (string)($_POST['data_origem'] ?? 'pagamento');
        $dataFixa = trim((string)($_POST['data_fixa'] ?? ''));

        if (!in_array($destino, ['bnc', 'cr', 'cp'], true)) {
            throw new RuntimeException('Informe o destino do lancamento.');
        }
        if ($tipoes <= 0) {
            throw new RuntimeException('Informe o tipo ES.');
        }
        if ($historico === '') {
            throw new RuntimeException('Informe o historico padrao.');
        }
        if ($dataOrigem === 'sem_data' && $dataFixa === '') {
            throw new RuntimeException('Com data de origem "sem data", informe uma data fixa.');
        }

        $linhasSelecionadas = alpSelecionarAccess($pdo, $empresaId, is_array($ids) ? $ids : []);
        if (!$linhasSelecionadas) {
            throw new RuntimeException('Nenhum lancamento Access pendente foi selecionado.');
        }

        $tipoSelecionado = alpTipoSelecionado($pdo, $empresaId, $tipoes);
        $dados = [
            'tipoes' => $tipoes,
            'tipomov' => (string)($tipoSelecionado['TIPOMOV'] ?? ''),
            'historico' => $historico,
            'data_origem' => $dataOrigem,
            'data_fixa' => $dataFixa,
            'cbcontador' => (int)($_POST['cbcontador'] ?? 0),
            'clicontador' => (int)($_POST['clicontador'] ?? 0),
            'fcontador' => (int)($_POST['fcontador'] ?? 0),
        ];

        if ($destino === 'bnc' && $dados['cbcontador'] <= 0) {
            throw new RuntimeException('Informe a conta para lancar em Caixa/Banco.');
        }
        if ($destino === 'bnc' && !in_array($dados['tipomov'], ['D', 'C'], true)) {
            throw new RuntimeException('O tipo ES selecionado nao possui D/C definido.');
        }
        if ($destino === 'cr' && $dados['clicontador'] <= 0) {
            throw new RuntimeException('Informe o cliente para lancar em Contas a Receber.');
        }
        if ($destino === 'cp' && $dados['fcontador'] <= 0) {
            throw new RuntimeException('Informe o fornecedor para lancar em Contas a Pagar.');
        }

        $pdo->beginTransaction();
        $criados = [];
        foreach ($linhasSelecionadas as $linha) {
            if ((float)$linha['valor_origem'] <= 0) {
                throw new RuntimeException('Linha Access ' . (int)$linha['linha_origem'] . ' sem valor valido.');
            }
            if ($destino === 'bnc') {
                $criados[] = 'MOV ' . acCriarBncDeAccess($pdo, $empresaId, $usuarioId, $linha, $dados);
            } elseif ($destino === 'cr') {
                $criados[] = 'CR ' . acCriarCrDeAccess($pdo, $empresaId, $usuarioId, $linha, $dados);
            } else {
                $criados[] = 'CP ' . acCriarCpDeAccess($pdo, $empresaId, $usuarioId, $linha, $dados);
            }
        }
        $pdo->commit();
        $mensagem = count($criados) . ' lancamento(s) criado(s): ' . implode(', ', $criados) . '.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $erro = $e->getMessage();
    }
}

$dataAccess = trim((string)($_GET['data_access'] ?? 'pagamento'));
$dataIni = trim((string)($_GET['data_ini'] ?? date('Y-m-01')));
$dataFim = trim((string)($_GET['data_fim'] ?? date('Y-m-t')));
$tipo = trim((string)($_GET['tipo'] ?? ''));
$contaOrigem = trim((string)($_GET['conta_origem'] ?? ''));
$busca = trim((string)($_GET['busca'] ?? ''));
$valorMin = trim((string)($_GET['valor_min'] ?? ''));
$valorMax = trim((string)($_GET['valor_max'] ?? ''));

$dataAccessSql = acCampoDataAccess($dataAccess);
$where = [
    "l.empresa_id = ?",
    "COALESCE(l.enviado_superdunga, 'N') <> 'S'",
    "l.tabela_destino IS NULL",
    "l.id_destino IS NULL",
];
$params = [$empresaId];
if ($dataAccessSql !== '') {
    if ($dataIni !== '') {
        $where[] = "DATE({$dataAccessSql}) >= ?";
        $params[] = $dataIni;
    }
    if ($dataFim !== '') {
        $where[] = "DATE({$dataAccessSql}) <= ?";
        $params[] = $dataFim;
    }
}
if ($tipo === 'D') {
    $where[] = 'l.debito_origem > 0 AND l.credito_origem <= 0';
} elseif ($tipo === 'C') {
    $where[] = 'l.credito_origem > 0 AND l.debito_origem <= 0';
}
if ($contaOrigem !== '') {
    $where[] = 'l.cod_conta_origem = ?';
    $params[] = $contaOrigem;
}
if ($busca !== '') {
    $where[] = '(l.observacao_origem LIKE ? OR l.documento_origem LIKE ? OR l.cheque_n_origem LIKE ? OR l.codigo_origem LIKE ?)';
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like);
}
$min = acFloat($valorMin);
if ($min !== null) {
    $where[] = 'l.valor_origem >= ?';
    $params[] = $min;
}
$max = acFloat($valorMax);
if ($max !== null) {
    $where[] = 'l.valor_origem <= ?';
    $params[] = $max;
}
$whereSql = implode(' AND ', array_map(static function ($w) {
    return '(' . $w . ')';
}, $where));

$stmtResumo = $pdo->prepare("
    SELECT COUNT(*) AS qtd,
           COALESCE(SUM(l.debito_origem), 0) AS debito,
           COALESCE(SUM(l.credito_origem), 0) AS credito
    FROM mov_baixa_lancamentos_access l
    WHERE {$whereSql}
");
$stmtResumo->execute($params);
$resumo = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: [];

$limit = 100;
$stmt = $pdo->prepare("
    SELECT l.*, ca.descricao_conta, ca.banco_bnc002
    FROM mov_baixa_lancamentos_access l
    LEFT JOIN mov_baixa_contas_access ca
      ON ca.empresa_id = l.empresa_id
     AND ca.cod_conta_origem = l.cod_conta_origem
    WHERE {$whereSql}
    ORDER BY l.data_pagamento_origem DESC, l.data_emissao_origem DESC, l.data_bom_para_origem DESC, l.linha_origem DESC
    LIMIT {$limit}
");
$stmt->execute($params);
$linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmtContasOrigem = $pdo->prepare("
    SELECT DISTINCT l.cod_conta_origem, ca.descricao_conta
    FROM mov_baixa_lancamentos_access l
    LEFT JOIN mov_baixa_contas_access ca
      ON ca.empresa_id = l.empresa_id
     AND ca.cod_conta_origem = l.cod_conta_origem
    WHERE l.empresa_id = ?
      AND COALESCE(l.enviado_superdunga, 'N') <> 'S'
      AND l.tabela_destino IS NULL
      AND l.id_destino IS NULL
      AND l.cod_conta_origem IS NOT NULL
    ORDER BY l.cod_conta_origem
");
$stmtContasOrigem->execute([$empresaId]);
$contasOrigem = $stmtContasOrigem->fetchAll(PDO::FETCH_ASSOC);

$stmtContas = $pdo->prepare("
    SELECT CBCONTADOR, NUMERO, DESCABREV, TITULAR
    FROM armazem_bnc002
    WHERE EMPRESA = ?
      AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY COALESCE(NULLIF(TITULAR, ''), DESCABREV), CBCONTADOR
");
$stmtContas->execute([$empresaId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

$stmtTipoes = $pdo->prepare("
    SELECT ESCONTADOR, DESCES, TIPOMOV
    FROM armazem_bnc005
    WHERE EMPRESA = ?
      AND COALESCE(REGDISAB, 'N') <> 'S'
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY DESCES, ESCONTADOR
");
$stmtTipoes->execute([$empresaId]);
$tipos = $stmtTipoes->fetchAll(PDO::FETCH_ASSOC);

$stmtClientes = $pdo->prepare("
    SELECT CLICONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Cliente ', CLICONTADOR)) AS nome
    FROM armazem_cr002
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY nome, CLICONTADOR
");
$stmtClientes->execute([$empresaId]);
$clientes = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);

$stmtFornecedores = $pdo->prepare("
    SELECT FCONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Fornecedor ', FCONTADOR)) AS nome
    FROM armazem_cp003
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
      AND COALESCE(INATIVO, 'N') <> 'S'
    ORDER BY nome, FCONTADOR
");
$stmtFornecedores->execute([$empresaId]);
$fornecedores = $stmtFornecedores->fetchAll(PDO::FETCH_ASSOC);

require '../../layout/header.php';
echo acCss();
?>

<section class="mb-4">
    <div class="p-4 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-2">Analise Access</span>
                <h1 class="h4 fw-bold mb-1">Lancamentos Access pendentes</h1>
                <p class="text-muted mb-0">Seleciona registros ainda nao vinculados e cria os lancamentos correspondentes no SuperDunga.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="access_legado.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagem): ?><div class="alert alert-success"><?= acH($mensagem) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger"><?= acH($erro) ?></div><?php endif; ?>

<section class="mb-3">
    <div class="row g-3">
        <div class="col-md-4"><div class="ac-kpi"><small>Pendentes filtrados</small><strong><?= number_format((int)($resumo['qtd'] ?? 0), 0, ',', '.') ?></strong></div></div>
        <div class="col-md-4"><div class="ac-kpi"><small>Debito Access</small><strong><?= acH(acMoeda($resumo['debito'] ?? 0)) ?></strong></div></div>
        <div class="col-md-4"><div class="ac-kpi"><small>Credito Access</small><strong><?= acH(acMoeda($resumo['credito'] ?? 0)) ?></strong></div></div>
    </div>
</section>

<section class="card ac-card mb-3">
    <div class="card-header">Filtros</div>
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Data Access</label>
                <select name="data_access" class="form-select">
                    <option value="pagamento" <?= $dataAccess === 'pagamento' ? 'selected' : '' ?>>DataPagamento</option>
                    <option value="bom_para" <?= $dataAccess === 'bom_para' ? 'selected' : '' ?>>DataBomPara</option>
                    <option value="emissao" <?= $dataAccess === 'emissao' ? 'selected' : '' ?>>DataEmissao</option>
                    <option value="sem_data" <?= $dataAccess === 'sem_data' ? 'selected' : '' ?>>Sem data</option>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label">Data inicial</label><input type="date" name="data_ini" class="form-control" value="<?= acH($dataIni) ?>"></div>
            <div class="col-md-2"><label class="form-label">Data final</label><input type="date" name="data_fim" class="form-control" value="<?= acH($dataFim) ?>"></div>
            <div class="col-md-1"><label class="form-label">Tipo</label><select name="tipo" class="form-select"><option value="">Todos</option><option value="D" <?= $tipo === 'D' ? 'selected' : '' ?>>D</option><option value="C" <?= $tipo === 'C' ? 'selected' : '' ?>>C</option></select></div>
            <div class="col-md-3">
                <label class="form-label">Conta Access</label>
                <select name="conta_origem" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach ($contasOrigem as $conta): ?>
                        <option value="<?= acH($conta['cod_conta_origem']) ?>" <?= $contaOrigem === (string)$conta['cod_conta_origem'] ? 'selected' : '' ?>><?= acH($conta['cod_conta_origem']) ?><?= $conta['descricao_conta'] ? ' - ' . acH($conta['descricao_conta']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label">Busca</label><input type="text" name="busca" class="form-control" value="<?= acH($busca) ?>"></div>
            <div class="col-md-2"><label class="form-label">Valor inicial</label><input type="text" name="valor_min" class="form-control" value="<?= acH($valorMin) ?>" inputmode="decimal"></div>
            <div class="col-md-2"><label class="form-label">Valor final</label><input type="text" name="valor_max" class="form-control" value="<?= acH($valorMax) ?>" inputmode="decimal"></div>
            <div class="col-md-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Filtrar</button>
                <a href="access_lancar_pendentes.php" class="btn btn-outline-secondary">Limpar</a>
            </div>
        </form>
    </div>
</section>

<form method="post" onsubmit="return confirm('Criar lancamentos no SuperDunga para os registros selecionados?');">
    <input type="hidden" name="acao" value="lancar_selecionados">

    <section class="card ac-card mb-3">
        <div class="card-header">Lancamentos pendentes do Access</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover ac-table mb-0">
                    <thead>
                        <tr>
                            <th><input type="checkbox" onclick="document.querySelectorAll('.access-check').forEach(c => c.checked = this.checked);"></th>
                            <th>Linha</th>
                            <th>Codigo</th>
                            <th>Tipo</th>
                            <th>Datas</th>
                            <th>Conta Access</th>
                            <th>Documento</th>
                            <th>Valor</th>
                            <th>Observacao</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($linhas as $linha): ?>
                        <tr>
                            <td><input type="checkbox" class="access-check" name="access_ids[]" value="<?= (int)$linha['id'] ?>"></td>
                            <td class="ac-code"><?= (int)$linha['linha_origem'] ?></td>
                            <td class="ac-code"><?= acH($linha['codigo_origem']) ?></td>
                            <td><?= acH(acTipoAccess($linha) ?: '-') ?></td>
                            <td><?= acH(acData($linha['data_pagamento_origem'])) ?> / <?= acH(acData($linha['data_bom_para_origem'])) ?> / <?= acH(acData($linha['data_emissao_origem'])) ?></td>
                            <td><?= acH($linha['cod_conta_origem']) ?><?= $linha['descricao_conta'] ? ' - ' . acH($linha['descricao_conta']) : '' ?></td>
                            <td><?= acH(acDocumentoAccess($linha) ?: '-') ?></td>
                            <td><?= acH(acMoeda($linha['valor_origem'])) ?></td>
                            <td class="ac-truncate" title="<?= acH($linha['observacao_origem']) ?>"><?= acH($linha['observacao_origem']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$linhas): ?><tr><td colspan="9" class="text-center text-muted py-4">Nenhum pendente encontrado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card ac-card mb-3">
        <div class="card-header">Lancar selecionados</div>
        <div class="card-body">
            <div class="alert alert-info py-2">Todos os selecionados usam os dados abaixo. Somente valor, data e documento de origem variam por linha.</div>
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">Destino</label>
                    <select name="destino" class="form-select" required>
                        <option value="">Selecione</option>
                        <option value="bnc">Caixa/Banco</option>
                        <option value="cr">Contas a Receber</option>
                        <option value="cp">Contas a Pagar</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Conta Caixa/Banco</label>
                    <select name="cbcontador" class="form-select">
                        <option value="">Selecione para Caixa/Banco</option>
                        <?php foreach ($contas as $conta): ?>
                            <option value="<?= (int)$conta['CBCONTADOR'] ?>"><?= (int)$conta['CBCONTADOR'] ?> - <?= acH($conta['TITULAR'] ?: $conta['DESCABREV']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo ES</label>
                    <select name="tipoes" class="form-select" required>
                        <option value="">Selecione</option>
                        <?php foreach ($tipos as $tipoLinha): ?>
                            <option value="<?= (int)$tipoLinha['ESCONTADOR'] ?>"><?= (int)$tipoLinha['ESCONTADOR'] ?> - <?= acH($tipoLinha['DESCES']) ?><?= $tipoLinha['TIPOMOV'] ? ' (' . acH($tipoLinha['TIPOMOV']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cliente</label>
                    <select name="clicontador" class="form-select">
                        <option value="">Para CR</option>
                        <?php foreach ($clientes as $cliente): ?>
                            <option value="<?= (int)$cliente['CLICONTADOR'] ?>"><?= (int)$cliente['CLICONTADOR'] ?> - <?= acH($cliente['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Fornecedor</label>
                    <select name="fcontador" class="form-select">
                        <option value="">Para CP</option>
                        <?php foreach ($fornecedores as $fornecedor): ?>
                            <option value="<?= (int)$fornecedor['FCONTADOR'] ?>"><?= (int)$fornecedor['FCONTADOR'] ?> - <?= acH($fornecedor['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data origem</label>
                    <select name="data_origem" class="form-select">
                        <option value="pagamento">DataPagamento</option>
                        <option value="bom_para">DataBomPara</option>
                        <option value="emissao">DataEmissao</option>
                        <option value="sem_data">Usar data fixa</option>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label">Data fixa</label><input type="date" name="data_fixa" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">Historico padrao</label><input type="text" name="historico" class="form-control" maxlength="180" required></div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success w-100">Lancar selecionados</button>
                </div>
            </div>
        </div>
    </section>
</form>

<?php require '../../layout/footer.php'; ?>
