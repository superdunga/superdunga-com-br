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
        header('Location: access_caixa_banco.php' . ($query ? '?' . $query . '&ok=vinculado' : '?ok=vinculado'));
        exit;
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}
if (($_GET['ok'] ?? '') === 'vinculado') {
    $mensagem = 'Lancamento amarrado ao Access.';
}

$dataIni = trim((string)($_GET['data_ini'] ?? date('Y-m-01')));
$dataFim = trim((string)($_GET['data_fim'] ?? date('Y-m-t')));
$cbcontador = trim((string)($_GET['cbcontador'] ?? ''));
$tipo = trim((string)($_GET['tipo'] ?? ''));
$historico = trim((string)($_GET['historico'] ?? ''));
$valorMin = trim((string)($_GET['valor_min'] ?? ''));
$valorMax = trim((string)($_GET['valor_max'] ?? ''));
$dataAccess = trim((string)($_GET['data_access'] ?? 'pagamento'));
$candidato = trim((string)($_GET['candidato'] ?? 'todos'));
$buscarCandidatos = (($_GET['buscar_candidatos'] ?? '') === '1') || $candidato !== 'todos';

$where = ["b.EMPRESA = ?", "COALESCE(b.deletado, 'N') <> 'S'"];
$params = [$empresaId];
if ($dataIni !== '') {
    $where[] = 'DATE(b.DTMOV) >= ?';
    $params[] = $dataIni;
}
if ($dataFim !== '') {
    $where[] = 'DATE(b.DTMOV) <= ?';
    $params[] = $dataFim;
}
if ($cbcontador !== '') {
    $where[] = 'b.CBCONTADOR = ?';
    $params[] = (int)$cbcontador;
}
if ($tipo === 'D' || $tipo === 'C') {
    $where[] = 'b.TIPOMOV = ?';
    $params[] = $tipo;
}
if ($historico !== '') {
    $where[] = 'b.HISTMOV LIKE ?';
    $params[] = '%' . $historico . '%';
}
$min = acFloat($valorMin);
if ($min !== null) {
    $where[] = 'b.VALORMOV >= ?';
    $params[] = $min;
}
$max = acFloat($valorMax);
if ($max !== null) {
    $where[] = 'b.VALORMOV <= ?';
    $params[] = $max;
}
$whereSql = implode(' AND ', array_map(static fn($w) => '(' . $w . ')', $where));
$whereVinculo = $where;
$whereVinculo[] = "EXISTS (
    SELECT 1
    FROM mov_baixa_lancamentos_access va
    WHERE va.empresa_id = b.EMPRESA
      AND va.tabela_destino = 'armazem_bnc001'
      AND va.id_destino = b.MOVCONTADOR
      AND COALESCE(va.enviado_superdunga, 'N') = 'S'
)";
$whereVinculoSql = implode(' AND ', array_map(static fn($w) => '(' . $w . ')', $whereVinculo));

$stmtResumo = $pdo->prepare("
    SELECT COUNT(*) AS qtd, COALESCE(SUM(CASE WHEN b.TIPOMOV = 'D' THEN b.VALORMOV ELSE 0 END), 0) AS debito,
           COALESCE(SUM(CASE WHEN b.TIPOMOV = 'C' THEN b.VALORMOV ELSE 0 END), 0) AS credito
    FROM armazem_bnc001 b
    WHERE {$whereSql}
");
$stmtResumo->execute($params);
$resumo = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: [];

$stmtVinculados = $pdo->prepare("
    SELECT COUNT(*) AS qtd
    FROM armazem_bnc001 b
    WHERE {$whereVinculoSql}
");
$stmtVinculados->execute($params);
$qtdVinculados = (int)$stmtVinculados->fetchColumn();

$limit = 100;
$stmt = $pdo->prepare("
    SELECT b.MOVCONTADOR, b.DTMOV, b.CBCONTADOR, b.TIPOMOV, b.VALORMOV, b.HISTMOV, b.NUMDOC,
           COALESCE(NULLIF(c.TITULAR, ''), NULLIF(c.DESCABREV, ''), CONCAT('Conta ', b.CBCONTADOR)) AS nome_conta
           , EXISTS (
                SELECT 1
                FROM mov_baixa_lancamentos_access va
                WHERE va.empresa_id = b.EMPRESA
                  AND va.tabela_destino = 'armazem_bnc001'
                  AND va.id_destino = b.MOVCONTADOR
                  AND COALESCE(va.enviado_superdunga, 'N') = 'S'
                LIMIT 1
           ) AS vinculado_access
    FROM armazem_bnc001 b
    LEFT JOIN armazem_bnc002 c
           ON c.EMPRESA = b.EMPRESA
          AND c.CBCONTADOR = b.CBCONTADOR
    WHERE {$whereSql}
    ORDER BY b.DTMOV DESC, b.MOVCONTADOR DESC
    LIMIT {$limit}
");
$stmt->execute($params);
$linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($buscarCandidatos) {
    $mapaCandidatos = acMapaCandidatosCaixa($pdo, $empresaId, $linhas, $dataAccess);
    foreach ($linhas as $idx => $linha) {
        $linhas[$idx]['candidatos'] = ((int)($linha['vinculado_access'] ?? 0) === 1) ? [] : ($mapaCandidatos[(int)$linha['MOVCONTADOR']] ?? []);
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

$stmtContas = $pdo->prepare("SELECT CBCONTADOR, TITULAR, DESCABREV FROM armazem_bnc002 WHERE EMPRESA = ? AND COALESCE(excluido_firebird, 'N') <> 'S' ORDER BY TITULAR, CBCONTADOR");
$stmtContas->execute([$empresaId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

require '../../layout/header.php';
echo acCss();
?>

<section class="mb-4">
    <div class="p-4 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-2">Analise Access</span>
                <h1 class="h4 fw-bold mb-1">Caixa/Banco x Access</h1>
                <p class="text-muted mb-0">Procura lancamentos BNC001 no Access considerando valor igual e data aproximada.</p>
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
        <div class="col-md-3"><div class="ac-kpi"><small>Registros filtrados</small><strong><?= number_format((int)($resumo['qtd'] ?? 0), 0, ',', '.') ?></strong></div></div>
        <div class="col-md-3"><div class="ac-kpi"><small>Debito</small><strong><?= acH(acMoeda($resumo['debito'] ?? 0)) ?></strong></div></div>
        <div class="col-md-3"><div class="ac-kpi"><small>Credito</small><strong><?= acH(acMoeda($resumo['credito'] ?? 0)) ?></strong></div></div>
        <div class="col-md-3"><div class="ac-kpi"><small>Vinculados Access</small><strong><?= number_format($qtdVinculados, 0, ',', '.') ?></strong></div></div>
    </div>
</section>

<section class="card ac-card mb-3">
    <div class="card-header">Filtros</div>
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-2"><label class="form-label">Data inicial</label><input type="date" name="data_ini" class="form-control" value="<?= acH($dataIni) ?>"></div>
            <div class="col-md-2"><label class="form-label">Data final</label><input type="date" name="data_fim" class="form-control" value="<?= acH($dataFim) ?>"></div>
            <div class="col-md-2">
                <label class="form-label">Conta</label>
                <select name="cbcontador" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach ($contas as $conta): ?>
                        <option value="<?= (int)$conta['CBCONTADOR'] ?>" <?= (string)$cbcontador === (string)$conta['CBCONTADOR'] ? 'selected' : '' ?>><?= (int)$conta['CBCONTADOR'] ?> - <?= acH($conta['TITULAR'] ?: $conta['DESCABREV']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1"><label class="form-label">Tipo</label><select name="tipo" class="form-select"><option value="">Todos</option><option value="D" <?= $tipo === 'D' ? 'selected' : '' ?>>D</option><option value="C" <?= $tipo === 'C' ? 'selected' : '' ?>>C</option></select></div>
            <div class="col-md-2">
                <label class="form-label">Data Access</label>
                <select name="data_access" class="form-select">
                    <option value="pagamento" <?= $dataAccess === 'pagamento' ? 'selected' : '' ?>>DataPagamento</option>
                    <option value="bom_para" <?= $dataAccess === 'bom_para' ? 'selected' : '' ?>>DataBomPara</option>
                    <option value="emissao" <?= $dataAccess === 'emissao' ? 'selected' : '' ?>>DataEmissao</option>
                    <option value="sem_data" <?= $dataAccess === 'sem_data' ? 'selected' : '' ?>>Sem data</option>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label">Candidato</label><select name="candidato" class="form-select"><option value="todos" <?= $candidato === 'todos' ? 'selected' : '' ?>>Todos</option><option value="com" <?= $candidato === 'com' ? 'selected' : '' ?>>Com candidato</option><option value="sem" <?= $candidato === 'sem' ? 'selected' : '' ?>>Sem candidato</option></select></div>
            <div class="col-md-3"><label class="form-label">Historico</label><input type="text" name="historico" class="form-control" value="<?= acH($historico) ?>"></div>
            <div class="col-md-2"><label class="form-label">Valor inicial</label><input type="text" name="valor_min" class="form-control" value="<?= acH($valorMin) ?>" inputmode="decimal"></div>
            <div class="col-md-2"><label class="form-label">Valor final</label><input type="text" name="valor_max" class="form-control" value="<?= acH($valorMax) ?>" inputmode="decimal"></div>
            <div class="col-md-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Filtrar</button>
                <button class="btn btn-outline-primary" type="submit" name="buscar_candidatos" value="1">Buscar candidatos</button>
                <a href="access_caixa_banco.php" class="btn btn-outline-secondary">Limpar</a>
            </div>
        </form>
    </div>
</section>

<section class="card ac-card">
    <div class="card-header">Lancamentos Caixa/Banco</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover ac-table mb-0">
                <thead><tr><th>Mov</th><th>Data</th><th>Conta SuperDunga</th><th>Tipo</th><th>Valor</th><th>Historico</th><th>Candidatos</th></tr></thead>
                <tbody>
                <?php foreach ($linhas as $linha): ?>
                    <tr>
                        <td class="ac-code"><?= (int)$linha['MOVCONTADOR'] ?></td>
                        <td><?= acH(acData($linha['DTMOV'])) ?></td>
                        <td><?= acH($linha['nome_conta']) ?></td>
                        <td><?= acH($linha['TIPOMOV']) ?></td>
                        <td><?= acH(acMoeda($linha['VALORMOV'])) ?></td>
                        <td class="ac-truncate" title="<?= acH($linha['HISTMOV']) ?>"><?= acH($linha['HISTMOV']) ?></td>
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
                                    <summary>Ver candidatos Access para o mov <?= (int)$linha['MOVCONTADOR'] ?></summary>
                                    <div class="mt-2">
                                        <?= acRenderCandidatos($linha['candidatos'], 'bnc', (int)$linha['MOVCONTADOR']) ?>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (!$linhas): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
