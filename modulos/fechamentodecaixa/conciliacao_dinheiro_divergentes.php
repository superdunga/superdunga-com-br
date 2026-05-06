<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

$mes = $_GET['mes'] ?? date('Y-m');
$inicio = $mes . '-01 07:00:00';
$fim = date('Y-m-d H:i:s');

$stmt = $pdo_master->prepare("
    SELECT *
    FROM (
        SELECT
            DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)) AS data_operacional,
            b.CBCONTADOR,
            SUM(
                CASE
                    WHEN b.TIPOMOV = 'C' THEN b.VALORMOV
                    WHEN b.TIPOMOV = 'D' THEN -b.VALORMOV
                    ELSE 0
                END
            ) AS saldo_final
        FROM armazem_bnc001 b
        INNER JOIN (
            SELECT DISTINCT CODCX
            FROM armazem_zconfig005
            WHERE CODCX IS NOT NULL
        ) z ON z.CODCX = b.CBCONTADOR
        WHERE b.DTLANC BETWEEN ? AND ?
          AND COALESCE(b.deletado, 'N') <> 'S'
        GROUP BY DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)), b.CBCONTADOR
    ) x
    WHERE x.data_operacional <> CURDATE()
      AND ABS(x.saldo_final) >= 0.01
    ORDER BY x.data_operacional DESC, x.CBCONTADOR
");
$stmt->execute([$inicio, $fim]);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card shadow-sm">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1 class="h5 mb-1">Conciliacao de dinheiro divergentes</h1>
            <small class="text-muted">Caixas do mes corrente com saldo final diferente de zero.</small>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a href="../operador/pendencias.php" class="btn btn-outline-secondary">Voltar as pendencias</a>
            <form method="GET" class="d-flex gap-2">
                <input type="month" name="mes" value="<?= htmlspecialchars($mes) ?>" class="form-control">
                <button class="btn btn-primary">Filtrar</button>
            </form>
        </div>
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-bordered text-center align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Data</th>
                        <th>Caixa</th>
                        <th>Saldo final</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$resultados): ?>
                        <tr>
                            <td colspan="5" class="text-muted">Nenhum caixa divergente encontrado.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($resultados as $r): ?>
                        <?php
                            $dataOp = $r['data_operacional'];
                            $caixa = $r['CBCONTADOR'];
                            $saldo = (float)$r['saldo_final'];
                        ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($dataOp)) ?></td>
                            <td><?= htmlspecialchars((string)$caixa) ?></td>
                            <td class="fw-bold text-danger">R$ <?= number_format($saldo, 2, ',', '.') ?></td>
                            <td><span class="badge bg-danger">DIVERGENTE</span></td>
                            <td>
                                <a href="extrato_caixa.php?data=<?= urlencode($dataOp) ?>&caixa=<?= urlencode($caixa) ?>" class="btn btn-sm btn-outline-dark">
                                    Detalhar
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
