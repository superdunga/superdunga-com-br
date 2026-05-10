<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

$empresa_id = (int)($_SESSION['empresa_id'] ?? 1);

$stmtColunaEmpresa = $pdo_master->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tesouraria_sincronizacao_log'
      AND COLUMN_NAME = 'empresa_id'
");
$stmtColunaEmpresa->execute();
if ((int)$stmtColunaEmpresa->fetchColumn() === 0) {
    $pdo_master->exec("ALTER TABLE tesouraria_sincronizacao_log ADD empresa_id INT NOT NULL DEFAULT 1 AFTER id");
}

$stmtIndice = $pdo_master->prepare("
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tesouraria_sincronizacao_log'
      AND INDEX_NAME = 'idx_sync_log_empresa_data'
");
$stmtIndice->execute();
if ((int)$stmtIndice->fetchColumn() === 0) {
    $pdo_master->exec("ALTER TABLE tesouraria_sincronizacao_log ADD INDEX idx_sync_log_empresa_data (empresa_id, data_execucao)");
}

/* =========================
   BUSCAR LOGS
========================= */
$stmt = $pdo_master->prepare("
    SELECT *
    FROM tesouraria_sincronizacao_log
    WHERE empresa_id = ?
    ORDER BY data_execucao DESC
    LIMIT 20
");
$stmt->execute([$empresa_id]);

$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card shadow-sm">
    <div class="card-header">
        <h4>🔄 Status das Sincronizações</h4>
    </div>

    <div class="card-body">

        <?php if (empty($logs)): ?>

            <div class="alert alert-warning">
                Nenhuma sincronização registrada ainda.
            </div>

        <?php else: ?>

            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Data/Hora</th>
                        <th>Tabela</th>
                        <th>Registros</th>
                        <th>Status</th>
                        <th>Mensagem</th>
                    </tr>
                </thead>
                <tbody>

                    <?php foreach ($logs as $l): ?>

                        <tr>
                            <td><?= date('d/m/Y H:i:s', strtotime($l['data_execucao'])) ?></td>
                            <td><?= htmlspecialchars($l['tabela']) ?></td>
                            <td><?= (int)$l['registros'] ?></td>

                            <td>
                                <?php if ($l['status'] === 'OK'): ?>
                                    <span class="badge bg-success">OK</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">ERRO</span>
                                <?php endif; ?>
                            </td>

                            <td><?= htmlspecialchars($l['mensagem']) ?></td>
                        </tr>

                    <?php endforeach; ?>

                </tbody>
            </table>

        <?php endif; ?>

    </div>
</div>

<?php require '../../layout/footer.php'; ?>
