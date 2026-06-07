<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

$empresa_id = (int)$_SESSION['empresa_id'];
$filtroVendaTotal = "AND COALESCE(CMCONTADOR, 0) <> 10";

$mes = isset($_GET['mes']) && $_GET['mes'] != '' ? $_GET['mes'] : date('Y-m');

$inicio = $mes . '-01 07:00:00';
$fim    = date('Y-m-d 03:00:00', strtotime($mes . '-01 +1 month'));

$sql = "
SELECT 
    DATE(DTLANC) AS data,
    USERLANC
FROM armazem_est007
WHERE DTLANC >= '$inicio'
  AND DTLANC <= '$fim'
  AND EMPRESA = $empresa_id
  AND CANCELADO = 'N'
  AND COALESCE(excluido_firebird, 'N') <> 'S'
GROUP BY DATE(DTLANC), USERLANC
ORDER BY data DESC
";

$caixas = $pdo_master->query($sql)->fetchAll(PDO::FETCH_ASSOC);

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

        /* VENDA */
        $stmt = $pdo_master->prepare("
            SELECT COALESCE(SUM(valor),0)
            FROM (
                SELECT MAX(TOTGERAL) AS valor
                FROM armazem_est007
                WHERE DTLANC BETWEEN ? AND ?
                  AND USERLANC = ?
                  AND EMPRESA = ?
                  AND CANCELADO = 'N'
                  AND COALESCE(excluido_firebird, 'N') <> 'S'
                  $filtroVendaTotal
                GROUP BY NUMDOC
            ) x
        ");
        $stmt->execute([$data_inicio, $data_fim, $usuario, $empresa_id]);
        $total_venda = (float)$stmt->fetchColumn();

        /* VISTA */
        $stmt = $pdo_master->prepare("
            SELECT COALESCE(SUM(
                CASE 
                    WHEN TIPOMOV = 'C' THEN VALORMOV
                    WHEN TIPOMOV = 'D' THEN -VALORMOV
                    ELSE 0
                END
            ),0)
            FROM armazem_bnc001 b
            INNER JOIN (
                SELECT DISTINCT VENDACONTADOR
                FROM armazem_est007
                WHERE DTLANC BETWEEN ? AND ?
                  AND USERLANC = ?
                  AND EMPRESA = ?
                  AND CANCELADO = 'N'
                  AND COALESCE(excluido_firebird, 'N') <> 'S'
                  $filtroVendaTotal
            ) e ON e.VENDACONTADOR = b.NUMDOCORIGEM
            WHERE b.DTLANC BETWEEN ? AND ?
              AND b.EMPRESA = ?
              AND b.TIPODOCORIGEM = 'VENDA'
              AND COALESCE(b.deletado, 'N') <> 'S'
        ");
        $stmt->execute([$data_inicio, $data_fim, $usuario, $empresa_id, $data_inicio, $data_fim, $empresa_id]);
        $total_vista = (float)$stmt->fetchColumn();

        /* PRAZO */
        $stmt = $pdo_master->prepare("
            SELECT COALESCE(SUM(c.VLRPARCELA),0)
            FROM armazem_cr001 c
            INNER JOIN (
                SELECT DISTINCT VENDACONTADOR
                FROM armazem_est007
                WHERE DTLANC BETWEEN ? AND ?
                  AND USERLANC = ?
                  AND EMPRESA = ?
                  AND CANCELADO = 'N'
                  AND COALESCE(excluido_firebird, 'N') <> 'S'
                  $filtroVendaTotal
            ) e ON e.VENDACONTADOR = c.NUMDOCORIGEM
            WHERE c.EMPRESA = ?
              AND COALESCE(c.excluido_firebird, 'N') <> 'S'
        ");
        $stmt->execute([$data_inicio, $data_fim, $usuario, $empresa_id, $empresa_id]);
        $total_prazo = (float)$stmt->fetchColumn();

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
