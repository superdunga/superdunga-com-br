<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

$data = $_GET['data'] ?? date('Y-m-d');

$inicio = date('Y-m-d 07:00:00', strtotime($data));
$fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));

$stmtPendSistema = $pdo_master->prepare("
    SELECT r.*
    FROM armazem_conciliacao_recebimentos r
    WHERE r.data_venda BETWEEN ? AND ?
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_cr001 c
          WHERE c.recebimento_id = r.id
            AND COALESCE(c.excluido_firebird, 'N') = 'N'
      )
    ORDER BY r.data_venda ASC, r.id ASC
");
$stmtPendSistema->execute([$inicio, $fim]);
$pendentesSistema = $stmtPendSistema->fetchAll(PDO::FETCH_ASSOC);

$stmtPendCR = $pdo_master->prepare("
    SELECT c.*
    FROM armazem_cr001 c
    WHERE c.DTLANC BETWEEN ? AND ?
      AND c.CMCONTADOR <> 9
      AND c.recebimento_id IS NULL
      AND NOT (c.CMCONTADOR = 1 AND c.STATUS = 'QT')
      AND COALESCE(c.excluido_firebird, 'N') = 'N'
    ORDER BY c.DTLANC ASC, c.CRCONTADOR ASC
");
$stmtPendCR->execute([$inicio, $fim]);
$pendentesCR001 = $stmtPendCR->fetchAll(PDO::FETCH_ASSOC);

$stmtExcluidosCR = $pdo_master->prepare("
    SELECT c.*
    FROM armazem_cr001 c
    WHERE c.DTLANC BETWEEN ? AND ?
      AND COALESCE(c.excluido_firebird, 'N') = 'S'
    ORDER BY c.DTLANC ASC, c.CRCONTADOR ASC
");
$stmtExcluidosCR->execute([$inicio, $fim]);
$excluidosCR001 = $stmtExcluidosCR->fetchAll(PDO::FETCH_ASSOC);

$totalPendenteSistema = array_sum(array_column($pendentesSistema, 'valor_bruto'));
$totalPendenteCR001 = array_sum(array_column($pendentesCR001, 'VLRPARCELA'));
$diferencaPendentes = $totalPendenteSistema - $totalPendenteCR001;
?>

<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Pendencias de Vendas a Prazo</h5>
        <a href="resumo_prazo.php" class="btn btn-secondary btn-sm">Voltar</a>
    </div>

    <div class="card-body">
        <p><strong>Data:</strong> <?= date('d/m/Y', strtotime($data)) ?> | <?= date('d/m/Y H:i', strtotime($inicio)) ?> ate <?= date('d/m/Y H:i', strtotime($fim)) ?></p>

        <div class="row mb-3">
            <div class="col-md-4">
                <div class="alert <?= empty($pendentesSistema) ? 'alert-success' : 'alert-danger' ?> text-center">
                    <strong>Recebiveis nao conciliados</strong><br>
                    <?= count($pendentesSistema) ?> registro(s)<br>
                    R$ <?= number_format($totalPendenteSistema, 2, ',', '.') ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="alert <?= empty($pendentesCR001) ? 'alert-success' : 'alert-danger' ?> text-center">
                    <strong>CR001 nao conciliados</strong><br>
                    <?= count($pendentesCR001) ?> registro(s)<br>
                    R$ <?= number_format($totalPendenteCR001, 2, ',', '.') ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="alert <?= abs($diferencaPendentes) < 0.01 ? 'alert-success' : 'alert-warning' ?> text-center">
                    <strong>Diferenca dos pendentes</strong><br>
                    R$ <?= number_format($diferencaPendentes, 2, ',', '.') ?>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card border-danger h-100">
                    <div class="card-header bg-danger text-white">Recebiveis nao conciliados</div>
                    <div class="card-body p-2" style="max-height:420px; overflow:auto;">
                        <table class="table table-sm table-bordered text-center mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Data</th>
                                    <th>Valor</th>
                                    <th>CM</th>
                                    <th>Origem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pendentesSistema)): ?>
                                    <tr><td colspan="5" class="text-muted">Nenhum registro</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pendentesSistema as $r): ?>
                                        <tr>
                                            <td><?= $r['id'] ?></td>
                                            <td><?= date('d/m/Y H:i', strtotime($r['data_venda'])) ?></td>
                                            <td>R$ <?= number_format($r['valor_bruto'], 2, ',', '.') ?></td>
                                            <td><?= $r['CMCONTADOR'] ?></td>
                                            <td><?= htmlspecialchars($r['origem'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-danger h-100">
                    <div class="card-header bg-danger text-white">CR001 nao conciliados</div>
                    <div class="card-body p-2" style="max-height:420px; overflow:auto;">
                        <table class="table table-sm table-bordered text-center mb-0">
                            <thead>
                                <tr>
                                    <th>CR001</th>
                                    <th>Data</th>
                                    <th>Valor</th>
                                    <th>CM</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pendentesCR001)): ?>
                                    <tr><td colspan="4" class="text-muted">Nenhum registro</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pendentesCR001 as $c): ?>
                                        <tr>
                                            <td><?= $c['CRCONTADOR'] ?></td>
                                            <td><?= date('d/m/Y H:i', strtotime($c['DTLANC'])) ?></td>
                                            <td>R$ <?= number_format($c['VLRPARCELA'], 2, ',', '.') ?></td>
                                            <td><?= $c['CMCONTADOR'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-secondary mt-3">
            <div class="card-header bg-secondary text-white">CR001 excluidos no Firebird</div>
            <div class="card-body p-2" style="max-height:320px; overflow:auto;">
                <table class="table table-sm table-bordered text-center mb-0">
                    <thead>
                        <tr>
                            <th>CR001</th>
                            <th>Data</th>
                            <th>Valor</th>
                            <th>CM</th>
                            <th>Recebivel</th>
                            <th>Marcado em</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($excluidosCR001)): ?>
                            <tr><td colspan="6" class="text-muted">Nenhum registro</td></tr>
                        <?php else: ?>
                            <?php foreach ($excluidosCR001 as $c): ?>
                                <tr>
                                    <td><?= $c['CRCONTADOR'] ?></td>
                                    <td><?= !empty($c['DTLANC']) ? date('d/m/Y H:i', strtotime($c['DTLANC'])) : '-' ?></td>
                                    <td>R$ <?= number_format($c['VLRPARCELA'], 2, ',', '.') ?></td>
                                    <td><?= $c['CMCONTADOR'] ?></td>
                                    <td><?= $c['recebimento_id'] ?: '-' ?></td>
                                    <td><?= !empty($c['data_exclusao_firebird']) ? date('d/m/Y H:i', strtotime($c['data_exclusao_firebird'])) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
