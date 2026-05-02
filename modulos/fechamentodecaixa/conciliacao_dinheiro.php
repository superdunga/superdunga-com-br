<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

/* =========================
   FILTRO MÊS
========================= */
$mes = $_GET['mes'] ?? date('Y-m');

/* =========================
   PERÍODO OPERACIONAL
   (07:00 até 03:00 do dia seguinte)
========================= */
$inicio = $mes . '-01 07:00:00';
$fim    = date('Y-m-d H:i:s'); // 🔥 pega até agora (dia atual incluído)

/* =========================
   QUERY PRINCIPAL
========================= */
$sql = "
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

/* 🔥 FILTRO DOS CAIXAS VÁLIDOS */
INNER JOIN (
    SELECT DISTINCT CODCX
    FROM armazem_zconfig005
    WHERE CODCX IS NOT NULL
) z ON z.CODCX = b.CBCONTADOR

WHERE b.DTLANC BETWEEN ? AND ?
  AND COALESCE(b.deletado, 'N') <> 'S'

GROUP BY 
    DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)),
    b.CBCONTADOR

ORDER BY 
    data_operacional DESC,
    b.CBCONTADOR
";

$stmt = $pdo_master->prepare($sql);
$stmt->execute([$inicio, $fim]);

$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card shadow-sm">
    
    <!-- HEADER -->
    <div class="card-header d-flex justify-content-between align-items-center">

        <h5 class="mb-0">💰 Conciliação de Dinheiro (Caixas Válidos)</h5>

        <div class="d-flex gap-2">

            <a href="menu_fechamento.php" class="btn btn-secondary">
                ← Voltar
            </a>

            <form method="GET" class="d-flex">
                <input type="month" name="mes" value="<?= $mes ?>" class="form-control me-2">
                <button class="btn btn-primary">Filtrar</button>
            </form>

        </div>

    </div>

    <div class="card-body table-responsive">

        <table class="table table-sm table-bordered text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Data</th>
                    <th>Caixa</th>
                    <th>Saldo Final</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>

<?php if (count($resultados) === 0): ?>

<tr>
    <td colspan="5" class="text-muted">
        Nenhum registro encontrado
    </td>
</tr>

<?php else: ?>

<?php foreach ($resultados as $r):

    $saldo = (float)$r['saldo_final'];
    $dataOp = $r['data_operacional'];
    $hoje   = date('Y-m-d');

    /* =========================
       STATUS
    ========================= */
    if ($dataOp == $hoje) {

        $status = 'EM ABERTO';
        $classe = 'warning';

    } else {

        if (abs($saldo) < 0.01) {
            $status = 'OK';
            $classe = 'success';
        } else {
            $status = 'DIVERGENTE';
            $classe = 'danger';
        }

    }
?>

<tr>
    <td><?= date('d/m/Y', strtotime($dataOp)) ?></td>

    <td><?= $r['CBCONTADOR'] ?></td>

    <td class="fw-bold text-<?= $classe ?>">
        R$ <?= number_format($saldo, 2, ',', '.') ?>
    </td>

    <td>
        <span class="badge bg-<?= $classe ?>">
            <?= $status ?>
        </span>
    </td>

    <td>
        <a href="extrato_caixa.php?data=<?= $dataOp ?>&caixa=<?= $r['CBCONTADOR'] ?>" 
           class="btn btn-sm btn-outline-dark">
           🔍
        </a>
    </td>
</tr>

<?php endforeach; ?>

<?php endif; ?>

            </tbody>
        </table>

    </div>
</div>

<?php require '../../layout/footer.php'; ?>
