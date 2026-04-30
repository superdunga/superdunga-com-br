<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

/* =========================
   BUSCAR LOGS
========================= */
$stmt = $pdo_master->query("
    SELECT *
    FROM tesouraria_sincronizacao_log
    ORDER BY data_execucao DESC
    LIMIT 20
");

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