<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

/* =========================
   FILTRO MÊS
========================= */
$mes = $_GET['mes'] ?? date('Y-m');

/* =========================
   BUSCAR DATAS (BASE SISTEMA)
========================= */
$stmtDatas = $pdo_master->prepare("
    SELECT DISTINCT DATE(data_venda) AS data_base
    FROM armazem_conciliacao_recebimentos
    WHERE DATE(data_venda) LIKE ?
    ORDER BY data_base DESC
");
$stmtDatas->execute([$mes . '%']);
$datas = $stmtDatas->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="card shadow-sm">

    <!-- HEADER -->
    <div class="card-header d-flex justify-content-between align-items-center">
        
        <h5 class="mb-0">📊 Conciliação de Vendas a Prazo</h5>

        <div class="d-flex gap-2">
            <a href="menu_fechamento.php" class="btn btn-secondary btn-sm">
                ← Voltar
            </a>

            <form method="get" class="d-flex">
                <input type="month" name="mes" value="<?= $mes ?>" class="form-control form-control-sm me-2">
                <button class="btn btn-primary btn-sm">Filtrar</button>
            </form>
        </div>

    </div>

    <div class="card-body">

        <table class="table table-bordered table-hover text-center">

            <thead class="table-dark">
                <tr>
                    <th>Data</th>
                    <th>Total Sistema</th>
                    <th>Total CR001</th>
                    <th>Diferença</th>
                    <th>Recebiveis nao conciliados</th>
                    <th>CR001 nao conciliados</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($datas as $data): 

                /* =========================
                   JANELA OPERACIONAL
                ========================= */
                $inicio = date('Y-m-d 07:00:00', strtotime($data));
                $fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));
                $stmt1 = $pdo_master->prepare("
                    SELECT COALESCE(SUM(r.valor_bruto), 0)
                    FROM armazem_conciliacao_recebimentos r
                    INNER JOIN armazem_cr001 c
                        ON c.recebimento_id = r.id
                    WHERE r.data_venda BETWEEN ? AND ?
                      AND c.DTLANC BETWEEN ? AND ?
                      AND COALESCE(c.excluido_firebird, 'N') = 'N'
                ");
                $stmt1->execute([$inicio, $fim, $inicio, $fim]);
                $totalSistema = (float)$stmt1->fetchColumn();

                $stmt2 = $pdo_master->prepare("
                    SELECT COALESCE(SUM(c.VLRPARCELA), 0)
                    FROM armazem_cr001 c
                    INNER JOIN armazem_conciliacao_recebimentos r
                        ON r.id = c.recebimento_id
                    WHERE c.DTLANC BETWEEN ? AND ?
                      AND r.data_venda BETWEEN ? AND ?
                      AND c.CMCONTADOR <> 9
                      AND NOT (c.CMCONTADOR = 1 AND c.STATUS = 'QT')
                      AND COALESCE(c.excluido_firebird, 'N') = 'N'
                ");
                $stmt2->execute([$inicio, $fim, $inicio, $fim]);
                $totalCR001 = (float)$stmt2->fetchColumn();

                $stmtPendRec = $pdo_master->prepare("
                    SELECT COUNT(*), COALESCE(SUM(r.valor_bruto), 0)
                    FROM armazem_conciliacao_recebimentos r
                    WHERE r.data_venda BETWEEN ? AND ?
                      AND NOT EXISTS (
                          SELECT 1
                          FROM armazem_cr001 c
                          WHERE c.recebimento_id = r.id
                            AND COALESCE(c.excluido_firebird, 'N') = 'N'
                      )
                ");
                $stmtPendRec->execute([$inicio, $fim]);
                [$pendentesSistema, $totalPendentesSistema] = $stmtPendRec->fetch(PDO::FETCH_NUM);
                $pendentesSistema = (int)$pendentesSistema;
                $totalPendentesSistema = (float)$totalPendentesSistema;

                $stmtPendCr = $pdo_master->prepare("
                    SELECT COUNT(*), COALESCE(SUM(c.VLRPARCELA), 0)
                    FROM armazem_cr001 c
                    WHERE c.DTLANC BETWEEN ? AND ?
                      AND c.CMCONTADOR <> 9
                      AND c.recebimento_id IS NULL
                      AND NOT (c.CMCONTADOR = 1 AND c.STATUS = 'QT')
                      AND COALESCE(c.excluido_firebird, 'N') = 'N'
                ");
                $stmtPendCr->execute([$inicio, $fim]);
                [$pendentesCR001, $totalPendentesCR001] = $stmtPendCr->fetch(PDO::FETCH_NUM);
                $pendentesCR001 = (int)$pendentesCR001;
                $totalPendentesCR001 = (float)$totalPendentesCR001;

                $subtotalSistema = $totalSistema + $totalPendentesSistema;
                $subtotalCR001 = $totalCR001 + $totalPendentesCR001;
                $diferenca = $subtotalSistema - $subtotalCR001;

                if ($pendentesSistema > 0 || $pendentesCR001 > 0) {
                    $status = '<span class="badge bg-warning text-dark">PENDENTE</span>';
                } elseif (abs($diferenca) < 0.01) {
                    $status = '<span class="badge bg-success">OK</span>';
                } else {
                    $status = '<span class="badge bg-danger">DIVERGENTE</span>';
                }

            ?>

                <tr>
                    <td><?= date('d/m/Y', strtotime($data)) ?></td>

                    <td><?= number_format($totalSistema, 2, ',', '.') ?></td>

                    <td><?= number_format($totalCR001, 2, ',', '.') ?></td>

                    <td class="<?= ($diferenca == 0 ? 'text-success' : 'text-danger') ?>">
                        <?= number_format($diferenca, 2, ',', '.') ?>
                    </td>

                    <td class="<?= ($pendentesSistema > 0 ? 'text-danger fw-bold' : 'text-success') ?>">
                        <?= $pendentesSistema ?> |
                        R$ <?= number_format($totalPendentesSistema, 2, ',', '.') ?>
                    </td>

                    <td class="<?= ($pendentesCR001 > 0 ? 'text-danger fw-bold' : 'text-success') ?>">
                        <?= $pendentesCR001 ?> |
                        R$ <?= number_format($totalPendentesCR001, 2, ',', '.') ?>
                    </td>

                    <td><?= $status ?></td>

                    <td>
                        <a href="diagnostico_divergencia.php?data=<?= $data ?>" 
                           class="btn btn-sm btn-outline-danger"
                           title="Ver diagnóstico da divergência">
                            🧠
                        </a>
                    </td>
                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

<?php require '../../layout/footer.php'; ?>
