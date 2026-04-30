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

                /* =========================
                   TOTAL SISTEMA
                ========================= */
                $stmt1 = $pdo_master->prepare("
                    SELECT SUM(valor_bruto)
                    FROM armazem_conciliacao_recebimentos
                    WHERE data_venda BETWEEN ? AND ?
                ");
                $stmt1->execute([$inicio, $fim]);
                $totalSistema = $stmt1->fetchColumn() ?? 0;

                /* =========================
                   TOTAL CR001 (CORRETO)
                   👉 NÃO depende da conciliação
                ========================= */
                $stmt2 = $pdo_master->prepare("
                    SELECT SUM(VLRPARCELA)
                    FROM armazem_cr001
                    WHERE DTLANC BETWEEN ? AND ?
                      AND CMCONTADOR <> 9
                      AND NOT (CMCONTADOR = 1 AND STATUS = 'QT')
                ");
                $stmt2->execute([$inicio, $fim]);
                $totalCR001 = $stmt2->fetchColumn() ?? 0;

                $diferenca = $totalSistema - $totalCR001;

                /* =========================
                   STATUS
                ========================= */
                if (abs($diferenca) < 0.01) {
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