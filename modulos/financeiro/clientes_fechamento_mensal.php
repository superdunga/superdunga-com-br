<?php
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/conexao.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
if (empty($_SESSION['csrf_fechamento_mensal'])) {
    $_SESSION['csrf_fechamento_mensal'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_fechamento_mensal'];
$alerta = null;
$erro = null;

$pdo_master->exec("
    CREATE TABLE IF NOT EXISTS financeiro_clientes_whatsapp (
        id INT AUTO_INCREMENT PRIMARY KEY,
        empresa_id INT NOT NULL,
        clicontador INT NOT NULL,
        ativo_whatsapp CHAR(1) NOT NULL DEFAULT 'N',
        observacao VARCHAR(255) NULL,
        usuario_id INT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_fin_cli_whatsapp (empresa_id, clicontador),
        KEY idx_fin_cli_whatsapp_ativo (empresa_id, ativo_whatsapp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new Exception('Sessao expirada. Recarregue a pagina.');
        }
        $clientes = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['clientes'] ?? [])))));
        if (empty($clientes)) {
            throw new Exception('Selecione pelo menos um cliente.');
        }
        $ativo = ($_POST['acao'] ?? '') === 'marcar' ? 'S' : 'N';
        $stmt = $pdo_master->prepare("
            INSERT INTO financeiro_clientes_whatsapp (empresa_id, clicontador, ativo_whatsapp, usuario_id)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE ativo_whatsapp=VALUES(ativo_whatsapp), usuario_id=VALUES(usuario_id), atualizado_em=NOW()
        ");
        $pdo_master->beginTransaction();
        foreach ($clientes as $clicontador) {
            $stmt->execute([$empresaId, $clicontador, $ativo, $usuarioId]);
        }
        $pdo_master->commit();
        $alerta = count($clientes) . ' cliente(s) ' . ($ativo === 'S' ? 'marcado(s)' : 'desmarcado(s)') . ' para fechamento mensal.';
    } catch (Throwable $e) {
        if ($pdo_master->inTransaction()) {
            $pdo_master->rollBack();
        }
        $erro = $e->getMessage();
    }
}

$busca = trim((string)($_GET['q'] ?? ''));
$nomeFiltro = trim((string)($_GET['nome'] ?? ''));
$primeiraCompraInicial = trim((string)($_GET['primeira_compra_inicial'] ?? ''));
$primeiraCompraFinal = trim((string)($_GET['primeira_compra_final'] ?? ''));
$ultimaCompraInicial = trim((string)($_GET['ultima_compra_inicial'] ?? ''));
$ultimaCompraFinal = trim((string)($_GET['ultima_compra_final'] ?? ''));
$semCelularFiltro = ($_GET['sem_celular'] ?? '') === 'S';
$exibirZerados = ($_GET['zerados'] ?? 'S') === 'N' ? 'N' : 'S';
$situacao = in_array(($_GET['situacao'] ?? ''), ['marcados', 'nao_marcados', 'sem_celular'], true) ? $_GET['situacao'] : '';
$ultimoDiaMesAnterior = date('Y-m-t', strtotime('first day of previous month'));
$where = ['c.EMPRESA = ?'];
$params = [$empresaId];
if ($busca !== '') {
    $where[] = '(CAST(c.CLICONTADOR AS CHAR) LIKE ? OR c.NOME LIKE ? OR c.APELIDO LIKE ? OR c.CELULAR LIKE ?)';
    $termo = '%' . $busca . '%';
    array_push($params, $termo, $termo, $termo, $termo);
}
if ($nomeFiltro !== '') {
    $where[] = "COALESCE(NULLIF(c.NOME,''),NULLIF(c.APELIDO,''),'') LIKE ?";
    $params[] = '%' . $nomeFiltro . '%';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $primeiraCompraInicial)) {
    $where[] = 'saldo.data_primeira_compra_aberta >= ?';
    $params[] = $primeiraCompraInicial;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $primeiraCompraFinal)) {
    $where[] = 'saldo.data_primeira_compra_aberta <= ?';
    $params[] = $primeiraCompraFinal;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ultimaCompraInicial)) {
    $where[] = 'compras.data_ultima_compra >= ?';
    $params[] = $ultimaCompraInicial;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ultimaCompraFinal)) {
    $where[] = 'compras.data_ultima_compra <= ?';
    $params[] = $ultimaCompraFinal;
}
if ($semCelularFiltro) {
    $where[] = "COALESCE(NULLIF(TRIM(c.CELULAR), ''), '') = ''";
}
if ($exibirZerados === 'N') {
    $where[] = 'COALESCE(saldo.total_em_aberto, 0) > 0';
}
if ($situacao === 'marcados') {
    $where[] = "COALESCE(w.ativo_whatsapp, 'N') = 'S'";
} elseif ($situacao === 'nao_marcados') {
    $where[] = "COALESCE(w.ativo_whatsapp, 'N') <> 'S'";
} elseif ($situacao === 'sem_celular') {
    $where[] = "COALESCE(NULLIF(TRIM(c.CELULAR), ''), '') = ''";
}
$stmt = $pdo_master->prepare("
    SELECT c.CLICONTADOR, COALESCE(NULLIF(c.NOME,''), NULLIF(c.APELIDO,''), CONCAT('Cliente ',c.CLICONTADOR)) AS NOME,
           c.CELULAR, COALESCE(w.ativo_whatsapp,'N') AS fechamento_mensal, w.atualizado_em, w.usuario_id,
           COALESCE(saldo.total_em_aberto, 0) AS total_em_aberto,
           saldo.data_primeira_compra_aberta,
           compras.data_ultima_compra
    FROM armazem_cr002 c
    LEFT JOIN financeiro_clientes_whatsapp w ON w.empresa_id=c.EMPRESA AND w.clicontador=c.CLICONTADOR
    LEFT JOIN (
        SELECT cr.EMPRESA, cr.CLICONTADOR, SUM(cr.VLRRESTANTE) AS total_em_aberto,
               MIN(DATE(COALESCE(v.DTVENDA,cr.DTEMISSAO))) AS data_primeira_compra_aberta
        FROM armazem_cr001 cr
        LEFT JOIN armazem_est007 v ON v.EMPRESA=cr.EMPRESA AND v.VENDACONTADOR=cr.NUMDOCORIGEM
        WHERE cr.EMPRESA=?
          AND cr.CMCONTADOR=9
          AND DATE(COALESCE(v.DTVENDA,cr.DTEMISSAO))<=?
          AND (cr.STATUS IS NULL OR cr.STATUS<>'QT')
          AND COALESCE(cr.VLRRESTANTE,0)>0
          AND COALESCE(cr.excluido_firebird,'N')<>'S'
        GROUP BY cr.EMPRESA,cr.CLICONTADOR
    ) saldo ON saldo.EMPRESA=c.EMPRESA AND saldo.CLICONTADOR=c.CLICONTADOR
    LEFT JOIN (
        SELECT cr.EMPRESA,cr.CLICONTADOR,MAX(DATE(COALESCE(v.DTVENDA,cr.DTEMISSAO))) AS data_ultima_compra
        FROM armazem_cr001 cr
        LEFT JOIN armazem_est007 v ON v.EMPRESA=cr.EMPRESA AND v.VENDACONTADOR=cr.NUMDOCORIGEM
        WHERE cr.EMPRESA=?
          AND cr.CMCONTADOR=9
          AND COALESCE(cr.excluido_firebird,'N')<>'S'
        GROUP BY cr.EMPRESA,cr.CLICONTADOR
    ) compras ON compras.EMPRESA=c.EMPRESA AND compras.CLICONTADOR=c.CLICONTADOR
    WHERE " . implode(' AND ', $where) . "
    ORDER BY NOME, c.CLICONTADOR
");
$stmt->execute(array_merge([$empresaId, $ultimoDiaMesAnterior, $empresaId], $params));
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo_master->prepare("SELECT COUNT(*) FROM financeiro_clientes_whatsapp WHERE empresa_id=? AND ativo_whatsapp='S'");
$stmt->execute([$empresaId]);
$totalMarcados = (int)$stmt->fetchColumn();

require __DIR__ . '/../../layout/header.php';
?>
<div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
    <div><h1 class="h3 fw-bold mb-1">Clientes com Fechamento Mensal</h1><p class="text-muted mb-0">Clientes da CR002 selecionados para receber o PDF pelo WhatsApp Operacional.</p></div>
    <div class="d-flex gap-2"><a href="../whatsapp_operacional/index.php" class="btn btn-outline-success">WhatsApp Operacional</a><a href="contas_receber.php" class="btn btn-outline-secondary">Voltar</a></div>
</div>
<?php if ($alerta): ?><div class="alert alert-success"><?= htmlspecialchars($alerta) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <strong>Filtros</strong>
        <span class="badge text-bg-primary fs-6"><?= $totalMarcados ?> cliente(s) marcado(s)</span>
    </div>
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-lg-4 col-md-6"><label class="form-label">Nome</label><input name="nome" class="form-control" value="<?= htmlspecialchars($nomeFiltro) ?>" placeholder="Nome do cliente"></div>
            <div class="col-lg-4 col-md-6"><label class="form-label">Primeira compra em aberto - inicial</label><input type="date" name="primeira_compra_inicial" class="form-control" value="<?= htmlspecialchars($primeiraCompraInicial) ?>"></div>
            <div class="col-lg-4 col-md-6"><label class="form-label">Primeira compra em aberto - final</label><input type="date" name="primeira_compra_final" class="form-control" value="<?= htmlspecialchars($primeiraCompraFinal) ?>"></div>
            <div class="col-lg-4 col-md-6"><label class="form-label">Última compra - inicial</label><input type="date" name="ultima_compra_inicial" class="form-control" value="<?= htmlspecialchars($ultimaCompraInicial) ?>"></div>
            <div class="col-lg-4 col-md-6"><label class="form-label">Última compra - final</label><input type="date" name="ultima_compra_final" class="form-control" value="<?= htmlspecialchars($ultimaCompraFinal) ?>"></div>
            <div class="col-lg-4 col-md-6 d-flex align-items-end"><div class="form-check pb-2"><input class="form-check-input" type="checkbox" name="sem_celular" value="S" id="filtro-sem-celular" <?= $semCelularFiltro?'checked':'' ?>><label class="form-check-label" for="filtro-sem-celular">Somente sem celular</label></div></div>
            <div class="col-lg-3 col-md-6"><label class="form-label">Fechamento mensal</label><select name="situacao" class="form-select"><option value="">Todos</option><option value="marcados" <?= $situacao==='marcados'?'selected':'' ?>>Marcados</option><option value="nao_marcados" <?= $situacao==='nao_marcados'?'selected':'' ?>>Nao marcados</option></select></div>
            <div class="col-lg-3 col-md-6"><label class="form-label">Exibir valores zerados</label><select name="zerados" class="form-select"><option value="S" <?= $exibirZerados==='S'?'selected':'' ?>>Sim</option><option value="N" <?= $exibirZerados==='N'?'selected':'' ?>>Nao</option></select></div>
            <div class="col-lg-3"><button class="btn btn-primary w-100">Filtrar</button></div>
            <div class="col-lg-3"><a href="clientes_fechamento_mensal.php" class="btn btn-outline-secondary w-100">Limpar filtros</a></div>
        </form>
    </div>
</div>
<form method="post" id="form-clientes-fechamento">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div class="card shadow-sm"><div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><strong>Relacao de clientes</strong><div class="d-flex gap-2"><button name="acao" value="desmarcar" class="btn btn-outline-secondary btn-sm">Desmarcar selecionados</button><button name="acao" value="marcar" class="btn btn-success btn-sm">Marcar fechamento mensal</button></div></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th style="width:42px"><input type="checkbox" id="marcar-todos" title="Selecionar todos"></th><th style="width:110px">CLICONTADOR</th><th>NOME</th><th>CELULAR</th><th class="text-end">Em aberto ate <?= htmlspecialchars(date('d/m/Y', strtotime($ultimoDiaMesAnterior))) ?></th><th>Primeira compra em aberto</th><th>Ultima compra</th><th style="width:150px">Fechamento mensal</th><th>Ultima alteracao</th></tr></thead><tbody>
    <?php foreach ($clientes as $c): $marcado=$c['fechamento_mensal']==='S'; ?><tr><td><input type="checkbox" name="clientes[]" value="<?= (int)$c['CLICONTADOR'] ?>"></td><td class="fw-semibold"><?= (int)$c['CLICONTADOR'] ?></td><td><?= htmlspecialchars($c['NOME']) ?></td><td><?= htmlspecialchars($c['CELULAR'] ?: 'Sem celular') ?></td><td class="text-end fw-semibold">R$ <?= number_format((float)$c['total_em_aberto'],2,',','.') ?></td><td><?= $c['data_primeira_compra_aberta'] ? htmlspecialchars(date('d/m/Y',strtotime($c['data_primeira_compra_aberta']))) : '-' ?></td><td><?= $c['data_ultima_compra'] ? htmlspecialchars(date('d/m/Y',strtotime($c['data_ultima_compra']))) : '-' ?></td><td><span class="badge <?= $marcado?'text-bg-success':'text-bg-secondary' ?>"><?= $marcado?'Sim':'Nao' ?></span></td><td><?= $c['atualizado_em'] ? htmlspecialchars(date('d/m/Y H:i',strtotime($c['atualizado_em']))) : '-' ?></td></tr><?php endforeach; ?>
    <?php if (!$clientes): ?><tr><td colspan="9" class="text-center text-muted py-4">Nenhum cliente encontrado.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</form>
<script>document.getElementById('marcar-todos').addEventListener('change',function(){document.querySelectorAll('input[name="clientes[]"]').forEach(function(c){c.checked=document.getElementById('marcar-todos').checked;});});</script>
<?php require __DIR__ . '/../../layout/footer.php'; ?>
