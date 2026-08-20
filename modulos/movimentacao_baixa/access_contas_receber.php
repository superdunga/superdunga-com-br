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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'amarrar_access') {
    try {
        acAmarrarAccess($pdo, $empresaId, $usuarioId, (int)$_POST['access_id'], (string)$_POST['destino_tipo'], (int)$_POST['destino_id']);
        $query = trim((string)($_POST['redirect_query'] ?? ''));
        header('Location: access_contas_receber.php' . ($query ? '?' . $query . '&ok=vinculado' : '?ok=vinculado'));
        exit;
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}
if (($_GET['ok'] ?? '') === 'vinculado') {
    $mensagem = 'Recebimento amarrado ao Access.';
}

$dataIni = trim((string)($_GET['data_ini'] ?? date('Y-m-01')));
$dataFim = trim((string)($_GET['data_fim'] ?? date('Y-m-t')));
$dataSuper = trim((string)($_GET['data_super'] ?? 'DTVENC'));
$dataAccess = trim((string)($_GET['data_access'] ?? 'bom_para'));
$status = trim((string)($_GET['status'] ?? ''));
$busca = trim((string)($_GET['busca'] ?? ''));
$valorMin = trim((string)($_GET['valor_min'] ?? ''));
$valorMax = trim((string)($_GET['valor_max'] ?? ''));
$candidato = trim((string)($_GET['candidato'] ?? 'todos'));
$buscarCandidatos = (($_GET['buscar_candidatos'] ?? '') === '1') || $candidato !== 'todos';
$dataVazia = (($_GET['data_vazia'] ?? '') === '1');

$camposData = ['DTVENC', 'DTPAGTO', 'DTEMISSAO', 'DTVENDA'];
if (!in_array($dataSuper, $camposData, true)) {
    $dataSuper = 'DTVENC';
}

$where = ["c.EMPRESA = ?", "COALESCE(c.excluido_firebird, 'N') <> 'S'"];
$params = [$empresaId];
if ($dataSuper === 'DTPAGTO' && $dataVazia) {
    $where[] = "c.{$dataSuper} IS NULL";
} elseif ($dataIni !== '') {
    $where[] = "DATE(c.{$dataSuper}) >= ?";
    $params[] = $dataIni;
}
if (!($dataSuper === 'DTPAGTO' && $dataVazia) && $dataFim !== '') {
    $where[] = "DATE(c.{$dataSuper}) <= ?";
    $params[] = $dataFim;
}
if ($status !== '') {
    $where[] = 'c.STATUS = ?';
    $params[] = $status;
}
if ($busca !== '') {
    $where[] = '(c.TITULO LIKE ? OR c.OBSERVACAO LIKE ? OR c.NUMDOCORIGEM LIKE ? OR c.CRCONTADOR = ?)';
    $params[] = '%' . $busca . '%';
    $params[] = '%' . $busca . '%';
    $params[] = '%' . $busca . '%';
    $params[] = (int)$busca;
}
$min = acFloat($valorMin);
if ($min !== null) {
    $where[] = 'c.VLRPARCELA >= ?';
    $params[] = $min;
}
$max = acFloat($valorMax);
if ($max !== null) {
    $where[] = 'c.VLRPARCELA <= ?';
    $params[] = $max;
}
$whereSql = implode(' AND ', array_map(static function ($w) {
    return '(' . $w . ')';
}, $where));
$whereVinculo = $where;
$whereVinculo[] = "EXISTS (
    SELECT 1
    FROM mov_baixa_lancamentos_access va
    WHERE va.empresa_id = c.EMPRESA
      AND va.tabela_destino = 'armazem_cr001'
      AND va.id_destino = c.CRCONTADOR
      AND COALESCE(va.enviado_superdunga, 'N') = 'S'
)";
$whereVinculoSql = implode(' AND ', array_map(static function ($w) {
    return '(' . $w . ')';
}, $whereVinculo));

$stmtResumo = $pdo->prepare("SELECT COUNT(*) AS qtd, COALESCE(SUM(c.VLRPARCELA), 0) AS total FROM armazem_cr001 c WHERE {$whereSql}");
$stmtResumo->execute($params);
$resumo = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: [];

$stmtVinculados = $pdo->prepare("SELECT COUNT(*) FROM armazem_cr001 c WHERE {$whereVinculoSql}");
$stmtVinculados->execute($params);
$qtdVinculados = (int)$stmtVinculados->fetchColumn();

$limit = 100;
$stmt = $pdo->prepare("
    SELECT c.CRCONTADOR, c.DTVENC, c.DTPAGTO, c.DTEMISSAO, c.DTVENDA, c.VLRPARCELA, c.STATUS, c.TITULO, c.NUMDOCORIGEM,
           EXISTS (
                SELECT 1
                FROM mov_baixa_lancamentos_access va
                WHERE va.empresa_id = c.EMPRESA
                  AND va.tabela_destino = 'armazem_cr001'
                  AND va.id_destino = c.CRCONTADOR
                  AND COALESCE(va.enviado_superdunga, 'N') = 'S'
                LIMIT 1
           ) AS vinculado_access
    FROM armazem_cr001 c
    WHERE {$whereSql}
    ORDER BY c.{$dataSuper} DESC, c.CRCONTADOR DESC
    LIMIT {$limit}
");
$stmt->execute($params);
$linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($buscarCandidatos) {
    $mapaCandidatos = acMapaCandidatosTitulo($pdo, $empresaId, $linhas, $dataAccess, $dataSuper, 'VLRPARCELA', 'CRCONTADOR');
    foreach ($linhas as $idx => $linha) {
        $linhas[$idx]['candidatos'] = ((int)($linha['vinculado_access'] ?? 0) === 1) ? [] : ($mapaCandidatos[(int)$linha['CRCONTADOR']] ?? []);
    }
} else {
    foreach ($linhas as $idx => $linha) {
        $linhas[$idx]['candidatos'] = null;
    }
}
if ($buscarCandidatos && $candidato !== 'todos') {
    $linhas = array_values(array_filter($linhas, static function ($linha) use ($candidato) {
        $tem = count($linha['candidatos']) > 0;
        return $candidato === 'com' ? $tem : !$tem;
    }));
}

require '../../layout/header.php';
echo acCss();
?>

<section class="mb-4">
    <div class="p-4 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-2">Analise Access</span>
                <h1 class="h4 fw-bold mb-1">Contas a Receber x Access</h1>
                <p class="text-muted mb-0">Procura CR001 no Access considerando valor igual e data aproximada.</p>
            </div>
            <div class="col-lg-4 text-lg-end"><a href="access_legado.php" class="btn btn-outline-secondary">Voltar</a></div>
        </div>
    </div>
</section>
<?php if ($mensagem): ?><div class="alert alert-success"><?= acH($mensagem) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger"><?= acH($erro) ?></div><?php endif; ?>

<section class="mb-3"><div class="row g-3">
    <div class="col-md-4"><div class="ac-kpi"><small>Registros filtrados</small><strong><?= number_format((int)($resumo['qtd'] ?? 0), 0, ',', '.') ?></strong></div></div>
    <div class="col-md-4"><div class="ac-kpi"><small>Total filtrado</small><strong><?= acH(acMoeda($resumo['total'] ?? 0)) ?></strong></div></div>
    <div class="col-md-4"><div class="ac-kpi"><small>Vinculados Access</small><strong><?= number_format($qtdVinculados, 0, ',', '.') ?></strong></div></div>
</div></section>

<section class="card ac-card mb-3">
    <div class="card-header">Filtros</div>
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-2"><label class="form-label">Data SuperDunga</label><select name="data_super" class="form-select"><?php foreach ($camposData as $campo): ?><option value="<?= acH($campo) ?>" <?= $dataSuper === $campo ? 'selected' : '' ?>><?= acH($campo) ?></option><?php endforeach; ?></select></div>
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
            <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">Todos</option><option value="AB" <?= $status === 'AB' ? 'selected' : '' ?>>AB</option><option value="QT" <?= $status === 'QT' ? 'selected' : '' ?>>QT</option></select></div>
            <div class="col-md-2"><label class="form-label">Candidato</label><select name="candidato" class="form-select"><option value="todos" <?= $candidato === 'todos' ? 'selected' : '' ?>>Todos</option><option value="com" <?= $candidato === 'com' ? 'selected' : '' ?>>Com candidato</option><option value="sem" <?= $candidato === 'sem' ? 'selected' : '' ?>>Sem candidato</option></select></div>
            <div class="col-md-2">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="data_vazia" value="1" id="data_vazia_cr" <?= $dataVazia ? 'checked' : '' ?>>
                    <label class="form-check-label" for="data_vazia_cr">DataPagamento vazia</label>
                </div>
            </div>
            <div class="col-md-3"><label class="form-label">Busca</label><input type="text" name="busca" class="form-control" value="<?= acH($busca) ?>"></div>
            <div class="col-md-2"><label class="form-label">Valor inicial</label><input type="text" name="valor_min" class="form-control" value="<?= acH($valorMin) ?>" inputmode="decimal"></div>
            <div class="col-md-2"><label class="form-label">Valor final</label><input type="text" name="valor_max" class="form-control" value="<?= acH($valorMax) ?>" inputmode="decimal"></div>
            <div class="col-md-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Filtrar</button>
                <button class="btn btn-outline-primary" type="submit" name="buscar_candidatos" value="1">Buscar candidatos</button>
                <a href="access_contas_receber.php" class="btn btn-outline-secondary">Limpar</a>
            </div>
        </form>
    </div>
</section>

<section class="card ac-card">
    <div class="card-header">Titulos a receber</div>
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover ac-table mb-0">
            <thead><tr><th>CR</th><th>Data</th><th>Status</th><th>Valor</th><th>Titulo</th><th>Origem</th><th>Candidatos</th></tr></thead>
            <tbody>
            <?php foreach ($linhas as $linha): ?>
                <tr>
                    <td class="ac-code"><?= (int)$linha['CRCONTADOR'] ?></td>
                    <td><?= acH(acData($linha[$dataSuper])) ?></td>
                    <td><?= acH($linha['STATUS']) ?></td>
                    <td><?= acH(acMoeda($linha['VLRPARCELA'])) ?></td>
                    <td class="ac-truncate" title="<?= acH($linha['TITULO']) ?>"><?= acH($linha['TITULO']) ?></td>
                    <td><?= acH($linha['NUMDOCORIGEM']) ?></td>
                    <td>
                        <?php if ((int)($linha['vinculado_access'] ?? 0) === 1): ?>
                            <span class="badge text-bg-success">Vinculado</span>
                        <?php elseif ($linha['candidatos'] === null): ?>
                            <span class="badge text-bg-light">Clique em Buscar candidatos</span>
                        <?php else: ?>
                            <?php $qtdCandidatos = count($linha['candidatos']); ?>
                            <span class="badge <?= $qtdCandidatos ? 'text-bg-primary' : 'text-bg-light' ?>"><?= $qtdCandidatos ?> candidato<?= $qtdCandidatos === 1 ? '' : 's' ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (is_array($linha['candidatos']) && count($linha['candidatos']) > 0): ?>
                    <tr class="ac-candidatos-row">
                        <td colspan="7">
                            <details>
                                <summary>Ver candidatos Access para o CR <?= (int)$linha['CRCONTADOR'] ?></summary>
                                <div class="mt-2">
                                    <?= acRenderCandidatos($linha['candidatos'], 'cr', (int)$linha['CRCONTADOR']) ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$linhas): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div>
</section>

<?php require '../../layout/footer.php'; ?>
