<?php
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/conexao.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
if (empty($_SESSION['csrf_cobranca_automatica'])) {
    $_SESSION['csrf_cobranca_automatica'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_cobranca_automatica'];
$alerta = null;
$erro = null;

$pdo_master->exec("
    CREATE TABLE IF NOT EXISTS financeiro_clientes_cobranca_automatica (
        id INT AUTO_INCREMENT PRIMARY KEY,
        empresa_id INT NOT NULL,
        clicontador INT NOT NULL,
        ativo CHAR(1) NOT NULL DEFAULT 'N',
        usuario_id INT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_fin_cli_cobranca (empresa_id, clicontador),
        KEY idx_fin_cli_cobranca_ativo (empresa_id, ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new Exception('Sessao expirada. Recarregue a pagina.');
        }
        $clientesSelecionados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['clientes'] ?? [])))));
        if (!$clientesSelecionados) {
            throw new Exception('Selecione pelo menos um cliente.');
        }
        $ativo = ($_POST['acao'] ?? '') === 'marcar' ? 'S' : 'N';
        $stmt = $pdo_master->prepare("
            INSERT INTO financeiro_clientes_cobranca_automatica (empresa_id,clicontador,ativo,usuario_id)
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE ativo=VALUES(ativo),usuario_id=VALUES(usuario_id),atualizado_em=NOW()
        ");
        $pdo_master->beginTransaction();
        foreach ($clientesSelecionados as $clicontador) {
            $stmt->execute([$empresaId, $clicontador, $ativo, $usuarioId]);
        }
        $pdo_master->commit();
        $alerta = count($clientesSelecionados) . ' cliente(s) ' . ($ativo === 'S' ? 'marcado(s)' : 'desmarcado(s)') . ' para cobranca automatica.';
    } catch (Throwable $e) {
        if ($pdo_master->inTransaction()) {
            $pdo_master->rollBack();
        }
        $erro = $e->getMessage();
    }
}

$nome = trim((string)($_GET['nome'] ?? ''));
$vencimentoInicial = trim((string)($_GET['vencimento_inicial'] ?? ''));
$vencimentoFinal = trim((string)($_GET['vencimento_final'] ?? ''));
$diasAtrasoMinimo = max(0, (int)($_GET['dias_atraso_minimo'] ?? 0));
$semCelular = ($_GET['sem_celular'] ?? '') === 'S';
$situacao = in_array(($_GET['situacao'] ?? ''), ['marcados','nao_marcados'], true) ? $_GET['situacao'] : '';

$where = ['c.EMPRESA=?', 'saldo.quantidade_vencidos>0'];
$params = [$empresaId];
if ($nome !== '') {
    $where[] = "COALESCE(NULLIF(c.NOME,''),NULLIF(c.APELIDO,''),'') LIKE ?";
    $params[] = '%' . $nome . '%';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $vencimentoInicial)) {
    $where[] = 'saldo.primeiro_vencimento>=?';
    $params[] = $vencimentoInicial;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $vencimentoFinal)) {
    $where[] = 'saldo.primeiro_vencimento<=?';
    $params[] = $vencimentoFinal;
}
if ($diasAtrasoMinimo > 0) {
    $where[] = 'DATEDIFF(CURDATE(),saldo.primeiro_vencimento)>=?';
    $params[] = $diasAtrasoMinimo;
}
if ($semCelular) {
    $where[] = "COALESCE(NULLIF(TRIM(c.CELULAR),''),'')=''";
}
if ($situacao === 'marcados') {
    $where[] = "COALESCE(ca.ativo,'N')='S'";
} elseif ($situacao === 'nao_marcados') {
    $where[] = "COALESCE(ca.ativo,'N')<>'S'";
}

$stmt = $pdo_master->prepare("
    SELECT c.CLICONTADOR,
           COALESCE(NULLIF(c.NOME,''),NULLIF(c.APELIDO,''),CONCAT('Cliente ',c.CLICONTADOR)) AS NOME,
           c.CELULAR,COALESCE(ca.ativo,'N') AS cobranca_automatica,ca.atualizado_em,
           saldo.quantidade_abertos,saldo.total_aberto,saldo.quantidade_vencidos,saldo.total_vencido,
           saldo.primeiro_vencimento,saldo.ultima_compra_aberta,
           DATEDIFF(CURDATE(),saldo.primeiro_vencimento) AS dias_atraso
    FROM armazem_cr002 c
    INNER JOIN (
        SELECT cr.EMPRESA,cr.CLICONTADOR,
               COUNT(*) AS quantidade_abertos,
               SUM(COALESCE(cr.VLRRESTANTE,0)) AS total_aberto,
               SUM(CASE WHEN DATE(cr.DTVENC)<CURDATE() THEN 1 ELSE 0 END) AS quantidade_vencidos,
               SUM(CASE WHEN DATE(cr.DTVENC)<CURDATE() THEN COALESCE(cr.VLRRESTANTE,0) ELSE 0 END) AS total_vencido,
               MIN(CASE WHEN DATE(cr.DTVENC)<CURDATE() THEN DATE(cr.DTVENC) END) AS primeiro_vencimento,
               MAX(DATE(COALESCE(v.DTVENDA,cr.DTEMISSAO))) AS ultima_compra_aberta
        FROM armazem_cr001 cr
        LEFT JOIN armazem_est007 v ON v.EMPRESA=cr.EMPRESA AND v.VENDACONTADOR=cr.NUMDOCORIGEM
        WHERE cr.EMPRESA=? AND cr.CMCONTADOR=9
          AND (cr.STATUS IS NULL OR cr.STATUS<>'QT')
          AND COALESCE(cr.VLRRESTANTE,0)>0
          AND COALESCE(cr.excluido_firebird,'N')<>'S'
        GROUP BY cr.EMPRESA,cr.CLICONTADOR
    ) saldo ON saldo.EMPRESA=c.EMPRESA AND saldo.CLICONTADOR=c.CLICONTADOR
    LEFT JOIN financeiro_clientes_cobranca_automatica ca ON ca.empresa_id=c.EMPRESA AND ca.clicontador=c.CLICONTADOR
    WHERE " . implode(' AND ', $where) . "
    ORDER BY saldo.primeiro_vencimento,c.NOME,c.CLICONTADOR
");
$stmt->execute(array_merge([$empresaId], $params));
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo_master->prepare("SELECT COUNT(*) FROM financeiro_clientes_cobranca_automatica WHERE empresa_id=? AND ativo='S'");
$stmt->execute([$empresaId]);
$totalMarcados = (int)$stmt->fetchColumn();

require __DIR__ . '/../../layout/header.php';
?>
<div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
    <div><h1 class="h3 fw-bold mb-1">Clientes com Cobranca Automatica</h1><p class="text-muted mb-0">Clientes com titulos vencidos que poderao receber cobrancas pelo WhatsApp Operacional.</p></div>
    <a href="contas_receber.php" class="btn btn-outline-secondary">Voltar</a>
</div>
<?php if ($alerta): ?><div class="alert alert-success"><?= htmlspecialchars($alerta) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2"><strong>Filtros</strong><span class="badge text-bg-primary fs-6"><?= $totalMarcados ?> cliente(s) marcado(s)</span></div>
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-lg-4 col-md-6"><label class="form-label">Nome</label><input name="nome" class="form-control" value="<?= htmlspecialchars($nome) ?>" placeholder="Nome do cliente"></div>
            <div class="col-lg-2 col-md-6"><label class="form-label">Vencido desde</label><input type="date" name="vencimento_inicial" class="form-control" value="<?= htmlspecialchars($vencimentoInicial) ?>"></div>
            <div class="col-lg-2 col-md-6"><label class="form-label">Vencido ate</label><input type="date" name="vencimento_final" class="form-control" value="<?= htmlspecialchars($vencimentoFinal) ?>"></div>
            <div class="col-lg-2 col-md-6"><label class="form-label">Atraso minimo (dias)</label><input type="number" min="0" name="dias_atraso_minimo" class="form-control" value="<?= $diasAtrasoMinimo ?>"></div>
            <div class="col-lg-2 col-md-6"><label class="form-label">Cobranca automatica</label><select name="situacao" class="form-select"><option value="">Todos</option><option value="marcados" <?= $situacao==='marcados'?'selected':'' ?>>Marcados</option><option value="nao_marcados" <?= $situacao==='nao_marcados'?'selected':'' ?>>Nao marcados</option></select></div>
            <div class="col-lg-3 col-md-6 d-flex align-items-end"><div class="form-check pb-2"><input class="form-check-input" type="checkbox" name="sem_celular" value="S" id="sem-celular" <?= $semCelular?'checked':'' ?>><label class="form-check-label" for="sem-celular">Somente sem celular</label></div></div>
            <div class="col-lg-3"><button class="btn btn-primary w-100">Filtrar</button></div>
            <div class="col-lg-3"><a href="clientes_cobranca_automatica.php" class="btn btn-outline-secondary w-100">Limpar filtros</a></div>
        </form>
    </div>
</div>
<form method="post" id="form-clientes-cobranca">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div class="card shadow-sm">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><strong>Clientes em atraso</strong><div class="d-flex gap-2"><button name="acao" value="desmarcar" class="btn btn-outline-secondary btn-sm">Desmarcar selecionados</button><button name="acao" value="marcar" class="btn btn-warning btn-sm">Marcar cobranca automatica</button></div></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th style="width:42px"><input type="checkbox" id="marcar-todos" title="Selecionar todos"></th><th style="width:100px">Cliente</th><th>Nome</th><th>Celular</th><th class="text-end">Titulos vencidos</th><th class="text-end">Valor vencido</th><th>Vencido desde</th><th class="text-end">Dias</th><th class="text-end">Todos em aberto</th><th class="text-end">Total em aberto</th><th style="width:150px">Cobranca automatica</th></tr></thead><tbody>
        <?php foreach ($clientes as $cliente): $marcado=$cliente['cobranca_automatica']==='S'; ?><tr><td><input type="checkbox" name="clientes[]" value="<?= (int)$cliente['CLICONTADOR'] ?>"></td><td class="fw-semibold"><?= (int)$cliente['CLICONTADOR'] ?></td><td><?= htmlspecialchars($cliente['NOME']) ?></td><td><?= htmlspecialchars($cliente['CELULAR'] ?: 'Sem celular') ?></td><td class="text-end"><?= (int)$cliente['quantidade_vencidos'] ?></td><td class="text-end fw-semibold text-danger">R$ <?= number_format((float)$cliente['total_vencido'],2,',','.') ?></td><td><?= htmlspecialchars(date('d/m/Y',strtotime($cliente['primeiro_vencimento']))) ?></td><td class="text-end"><?= (int)$cliente['dias_atraso'] ?></td><td class="text-end"><?= (int)$cliente['quantidade_abertos'] ?></td><td class="text-end fw-semibold">R$ <?= number_format((float)$cliente['total_aberto'],2,',','.') ?></td><td><span class="badge <?= $marcado?'text-bg-success':'text-bg-secondary' ?>"><?= $marcado?'Sim':'Nao' ?></span></td></tr><?php endforeach; ?>
        <?php if (!$clientes): ?><tr><td colspan="11" class="text-center text-muted py-4">Nenhum cliente em atraso encontrado com os filtros informados.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
</form>
<script>document.getElementById('marcar-todos').addEventListener('change',function(){document.querySelectorAll('input[name="clientes[]"]').forEach(function(c){c.checked=document.getElementById('marcar-todos').checked;});});</script>
<?php require __DIR__ . '/../../layout/footer.php'; ?>
