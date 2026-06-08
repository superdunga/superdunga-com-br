<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

$empresa_id = (int)$_SESSION['empresa_id'];
$filtroVendaTotal = "AND COALESCE(CMCONTADOR, 0) <> 10";

$mes = isset($_GET['mes']) && $_GET['mes'] != '' ? $_GET['mes'] : date('Y-m');

$inicio = $mes . '-01 07:00:00';
$fim    = date('Y-m-d 03:00:00', strtotime($mes . '-01 +1 month'));
$dataCaixaSql = "DATE(CASE WHEN TIME(DTLANC) < '03:00:00' THEN DATE_SUB(DTLANC, INTERVAL 1 DAY) ELSE DTLANC END)";

$paramsMes = [$inicio, $fim, $empresa_id];

$stmtVenda = $pdo_master->prepare("
    SELECT data, USERLANC, SUM(valor) AS total_venda
    FROM (
        SELECT $dataCaixaSql AS data, USERLANC, NUMDOC, MAX(TOTGERAL) AS valor
        FROM armazem_est007
        WHERE DTLANC >= ?
          AND DTLANC <= ?
          AND EMPRESA = ?
          AND CANCELADO = 'N'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND (TIME(DTLANC) >= '07:00:00' OR TIME(DTLANC) < '03:00:00')
          $filtroVendaTotal
        GROUP BY $dataCaixaSql, USERLANC, NUMDOC
    ) x
    GROUP BY data, USERLANC
");
$stmtVenda->execute($paramsMes);
$resumos = [];

while ($row = $stmtVenda->fetch(PDO::FETCH_ASSOC)) {
    $key = $row['data'] . '|' . $row['USERLANC'];
    $resumos[$key] = [
        'data' => $row['data'],
        'USERLANC' => $row['USERLANC'],
        'total_venda' => (float)$row['total_venda'],
        'total_vista' => 0.0,
        'total_prazo' => 0.0,
    ];
}

$stmtVista = $pdo_master->prepare("
    SELECT e.data, e.USERLANC,
           COALESCE(SUM(
               CASE
                   WHEN b.TIPOMOV = 'C' THEN b.VALORMOV
                   WHEN b.TIPOMOV = 'D' THEN -b.VALORMOV
                   ELSE 0
               END
           ), 0) AS total_vista
    FROM (
        SELECT DISTINCT $dataCaixaSql AS data, USERLANC, VENDACONTADOR
        FROM armazem_est007
        WHERE DTLANC >= ?
          AND DTLANC <= ?
          AND EMPRESA = ?
          AND CANCELADO = 'N'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND (TIME(DTLANC) >= '07:00:00' OR TIME(DTLANC) < '03:00:00')
          $filtroVendaTotal
    ) e
    INNER JOIN armazem_bnc001 b
        ON b.EMPRESA = ?
       AND b.NUMDOCORIGEM = e.VENDACONTADOR
       AND b.TIPODOCORIGEM = 'VENDA'
       AND COALESCE(b.deletado, 'N') <> 'S'
    GROUP BY e.data, e.USERLANC
");
$stmtVista->execute([$inicio, $fim, $empresa_id, $empresa_id]);

while ($row = $stmtVista->fetch(PDO::FETCH_ASSOC)) {
    $key = $row['data'] . '|' . $row['USERLANC'];
    if (!isset($resumos[$key])) {
        continue;
    }
    $resumos[$key]['total_vista'] = (float)$row['total_vista'];
}

$stmtPrazo = $pdo_master->prepare("
    SELECT e.data, e.USERLANC, COALESCE(SUM(c.VLRPARCELA), 0) AS total_prazo
    FROM (
        SELECT DISTINCT $dataCaixaSql AS data, USERLANC, VENDACONTADOR
        FROM armazem_est007
        WHERE DTLANC >= ?
          AND DTLANC <= ?
          AND EMPRESA = ?
          AND CANCELADO = 'N'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND (TIME(DTLANC) >= '07:00:00' OR TIME(DTLANC) < '03:00:00')
          $filtroVendaTotal
    ) e
    INNER JOIN armazem_cr001 c
        ON c.EMPRESA = ?
       AND c.NUMDOCORIGEM = e.VENDACONTADOR
       AND COALESCE(c.excluido_firebird, 'N') <> 'S'
    GROUP BY e.data, e.USERLANC
");
$stmtPrazo->execute([$inicio, $fim, $empresa_id, $empresa_id]);

while ($row = $stmtPrazo->fetch(PDO::FETCH_ASSOC)) {
    $key = $row['data'] . '|' . $row['USERLANC'];
    if (!isset($resumos[$key])) {
        continue;
    }
    $resumos[$key]['total_prazo'] = (float)$row['total_prazo'];
}

$caixas = array_values($resumos);
usort($caixas, function ($a, $b) {
    if ($a['data'] === $b['data']) {
        return ((int)$a['USERLANC']) <=> ((int)$b['USERLANC']);
    }
    return strcmp($b['data'], $a['data']);
});

/* =========================
   TOTAIS
========================= */
$total_venda_geral = 0;
$total_vista_geral = 0;
$total_prazo_geral = 0;
$total_diferenca_geral = 0;
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        
        <h5>📊 Fechamento de Caixa</h5>

        <div class="d-flex gap-2">

            <!-- 🔙 BOTÃO VOLTAR -->
            <a href="menu_fechamento.php" class="btn btn-secondary">
                ← Voltar
            </a>

            <!-- FILTRO -->
            <form method="GET" class="d-flex">
                <input type="month" name="mes" value="<?php echo $mes; ?>" class="form-control me-2">
                <button class="btn btn-primary">Filtrar</button>
            </form>

        </div>

    </div>

    <div class="card-body table-responsive">
        <table class="table table-sm table-bordered align-middle text-center">
            <thead class="table-dark">
                <tr>
                    <th>Data</th>
                    <th>Operador</th>
                    <th>Venda</th>
                    <th>Vista</th>
                    <th>Prazo</th>
                    <th>Diferença</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>

<?php foreach ($caixas as $cx): ?>

<?php
    $data = $cx['data'];
    $usuario = $cx['USERLANC'];

    $data_inicio = date('Y-m-d 07:00:00', strtotime($data));
    $data_fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));

    try {

        $total_venda = (float)($cx['total_venda'] ?? 0);
        $total_vista = (float)($cx['total_vista'] ?? 0);
        $total_prazo = (float)($cx['total_prazo'] ?? 0);

        /* FINAL */
        $calculado = $total_vista + $total_prazo;
        $diferenca = $total_venda - $calculado;

        /* SOMAR GERAL */
        $total_venda_geral += $total_venda;
        $total_vista_geral += $total_vista;
        $total_prazo_geral += $total_prazo;
        $total_diferenca_geral += $diferenca;

        if (abs($diferenca) < 0.01) {
            $status = 'OK';
            $classe = 'success';
        } else {
            $status = 'DIVERGENTE';
            $classe = 'danger';
        }

    } catch (Exception $e) {
        echo "<tr><td colspan='8' style='color:red'>Erro: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
        continue;
    }
?>

<tr>
    <td><?php echo date('d/m/Y', strtotime($data)); ?></td>
    <td><?php echo htmlspecialchars($usuario); ?></td>

    <td>R$ <?php echo number_format($total_venda, 2, ',', '.'); ?></td>
    <td>R$ <?php echo number_format($total_vista, 2, ',', '.'); ?></td>
    <td>R$ <?php echo number_format($total_prazo, 2, ',', '.'); ?></td>

    <td class="fw-bold text-<?php echo $classe; ?>">
        R$ <?php echo number_format($diferenca, 2, ',', '.'); ?>
    </td>

    <td>
        <span class="badge bg-<?php echo $classe; ?>">
            <?php echo $status; ?>
        </span>
    </td>

    <td>
        <a href="detalhar_fechamento.php?data=<?php echo $data; ?>&user=<?php echo $usuario; ?>" 
           class="btn btn-sm btn-outline-dark">🔍</a>
    </td>
</tr>

<?php endforeach; ?>

<!-- 🔥 LINHA DE TOTAIS -->
<tr class="table-dark fw-bold">
    <td colspan="2">TOTAL</td>

    <td>R$ <?php echo number_format($total_venda_geral, 2, ',', '.'); ?></td>
    <td>R$ <?php echo number_format($total_vista_geral, 2, ',', '.'); ?></td>
    <td>R$ <?php echo number_format($total_prazo_geral, 2, ',', '.'); ?></td>

    <td>R$ <?php echo number_format($total_diferenca_geral, 2, ',', '.'); ?></td>

    <td colspan="2"></td>
</tr>

            </tbody>
        </table>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
