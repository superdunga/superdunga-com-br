<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

$data = $_GET['data'] ?? '';
$usuario = $_GET['user'] ?? '';

if (!$data || !$usuario) {
    echo "<div class='alert alert-danger'>Parâmetros inválidos</div>";
    exit;
}

$data_inicio = date('Y-m-d 07:00:00', strtotime($data));
$data_fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));
?>

<div class="card shadow-sm">
    <div class="card-header">
        <h5>🔍 Detalhamento do Caixa</h5>
        <small>Data: <?php echo date('d/m/Y', strtotime($data)); ?> | Operador: <?php echo $usuario; ?></small>
    </div>

    <div class="card-body">

        <!-- =========================
             VENDAS
        ========================== -->
        <h6>🧾 Vendas</h6>

        <table class="table table-sm table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>Venda</th>
                    <th>CM</th>
                    <th>Valor</th>
                    <th>Detalhe</th>
                </tr>
            </thead>
            <tbody>

<?php
$stmt = $pdo_master->prepare("
    SELECT
        VENDACONTADOR,
        CMCONTADOR,
        TOTGERAL AS valor
    FROM armazem_est007
    WHERE DTLANC BETWEEN ? AND ?
      AND USERLANC = ?
      AND CANCELADO = 'N'
    ORDER BY VENDACONTADOR
");
$stmt->execute([$data_inicio, $data_fim, $usuario]);
$vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$itensPorVenda = [];
$vendasIds = array_values(array_filter(array_map(function ($venda) {
    return (int)($venda['VENDACONTADOR'] ?? 0);
}, $vendas)));

if (!empty($vendasIds)) {
    $placeholders = implode(',', array_fill(0, count($vendasIds), '?'));
    $stmtItens = $pdo_master->prepare("
        SELECT
            i.VENDACONTA,
            i.ITEMVENDACONTADOR,
            i.PRODUTO,
            i.QTDE,
            i.VALOR,
            i.TOTPROD,
            p.CODPRODUTO,
            p.DESCPRODUTO,
            p.UNIDADE
        FROM armazem_est008 i
        LEFT JOIN armazem_est004 p
            ON p.CONTAPRODUTO = i.PRODUTO
        WHERE i.VENDACONTA IN ($placeholders)
        ORDER BY i.VENDACONTA, i.ITEMVENDACONTADOR
    ");
    $stmtItens->execute($vendasIds);

    while ($item = $stmtItens->fetch(PDO::FETCH_ASSOC)) {
        $itensPorVenda[(int)$item['VENDACONTA']][] = $item;
    }
}

$total_venda = 0;

foreach ($vendas as $v) {
    $total_venda += $v['valor'];
    $vendaId = (int)$v['VENDACONTADOR'];
    $collapseId = 'itens-venda-' . $vendaId;
    $itens = $itensPorVenda[$vendaId] ?? [];
?>
<tr>
    <td><?= $vendaId ?></td>
    <td><?= htmlspecialchars($v['CMCONTADOR'] ?? '') ?></td>
    <td>R$ <?= number_format((float)$v['valor'], 2, ',', '.') ?></td>
    <td>
        <button class="btn btn-sm btn-outline-primary"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#<?= $collapseId ?>">
            Detalhe
        </button>
    </td>
</tr>
<tr class="collapse" id="<?= $collapseId ?>">
    <td colspan="4" class="bg-light">
        <?php if (empty($itens)): ?>
            <div class="text-muted small">Nenhum item encontrado para esta venda.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Produto</th>
                            <th>Descricao</th>
                            <th>Qtde</th>
                            <th>Unitario</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itens as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['ITEMVENDACONTADOR']) ?></td>
                                <td><?= htmlspecialchars($item['CODPRODUTO'] ?? $item['PRODUTO']) ?></td>
                                <td><?= htmlspecialchars($item['DESCPRODUTO'] ?? '') ?></td>
                                <td><?= number_format((float)$item['QTDE'], 3, ',', '.') ?> <?= htmlspecialchars($item['UNIDADE'] ?? '') ?></td>
                                <td>R$ <?= number_format((float)$item['VALOR'], 2, ',', '.') ?></td>
                                <td>R$ <?= number_format((float)$item['TOTPROD'], 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </td>
</tr>
<?php
}
?>

<tr class="table-secondary fw-bold">
    <td colspan="2">Total</td>
    <td>R$ <?php echo number_format($total_venda, 2, ',', '.'); ?></td>
    <td></td>
</tr>

            </tbody>
        </table>

        <!-- =========================
             VISTA
        ========================== -->
        <h6 class="mt-4">💰 Recebimentos (Vista)</h6>

        <table class="table table-sm table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>NUMDOCORIGEM</th>
                    <th>TIPOMOV</th>
                    <th>VALOR</th>
                </tr>
            </thead>
            <tbody>

<?php
$stmt = $pdo_master->prepare("
    SELECT b.NUMDOCORIGEM, b.TIPOMOV,
           CASE 
               WHEN b.TIPOMOV = 'C' THEN b.VALORMOV
               WHEN b.TIPOMOV = 'D' THEN -b.VALORMOV
               ELSE 0
           END AS valor
    FROM armazem_bnc001 b
    INNER JOIN (
        SELECT DISTINCT VENDACONTADOR
        FROM armazem_est007
        WHERE DTLANC BETWEEN ? AND ?
          AND USERLANC = ?
          AND CANCELADO = 'N'
    ) e ON e.VENDACONTADOR = b.NUMDOCORIGEM
    WHERE b.DTLANC BETWEEN ? AND ?
      AND b.TIPODOCORIGEM = 'VENDA'
    ORDER BY b.NUMDOCORIGEM
");
$stmt->execute([$data_inicio, $data_fim, $usuario, $data_inicio, $data_fim]);

$total_vista = 0;

while ($v = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $total_vista += $v['valor'];

    echo "<tr>
            <td>{$v['NUMDOCORIGEM']}</td>
            <td>{$v['TIPOMOV']}</td>
            <td>R$ " . number_format($v['valor'], 2, ',', '.') . "</td>
          </tr>";
}
?>

<tr class="table-secondary fw-bold">
    <td colspan="2">Total</td>
    <td>R$ <?php echo number_format($total_vista, 2, ',', '.'); ?></td>
</tr>

            </tbody>
        </table>

        <!-- =========================
             PRAZO
        ========================== -->
        <h6 class="mt-4">📅 Recebimentos (Prazo)</h6>

        <table class="table table-sm table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>NUMDOCORIGEM</th>
                    <th>PARCELA</th>
                    <th>VALOR</th>
                </tr>
            </thead>
            <tbody>

<?php
$stmt = $pdo_master->prepare("
    SELECT c.NUMDOCORIGEM, c.CMCONTADOR, c.VLRPARCELA
    FROM armazem_cr001 c
    INNER JOIN (
        SELECT DISTINCT VENDACONTADOR
        FROM armazem_est007
        WHERE DTLANC BETWEEN ? AND ?
          AND USERLANC = ?
          AND CANCELADO = 'N'
    ) e ON e.VENDACONTADOR = c.NUMDOCORIGEM
");
$stmt->execute([$data_inicio, $data_fim, $usuario]);

$total_prazo = 0;

while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $total_prazo += $p['VLRPARCELA'];

    echo "<tr>
            <td>{$p['NUMDOCORIGEM']}</td>
            <td>{$p['CMCONTADOR']}</td>
            <td>R$ " . number_format($p['VLRPARCELA'], 2, ',', '.') . "</td>
          </tr>";
}
?>

<tr class="table-secondary fw-bold">
    <td colspan="2">Total</td>
    <td>R$ <?php echo number_format($total_prazo, 2, ',', '.'); ?></td>
</tr>

            </tbody>
        </table>

        <!-- =========================
             RESULTADO FINAL
        ========================== -->
        <div class="mt-4 alert alert-info">
            <strong>Resumo:</strong><br>
            Venda: R$ <?php echo number_format($total_venda, 2, ',', '.'); ?><br>
            Vista: R$ <?php echo number_format($total_vista, 2, ',', '.'); ?><br>
            Prazo: R$ <?php echo number_format($total_prazo, 2, ',', '.'); ?><br>

            <hr>

            <strong>Diferença:</strong>
            R$ <?php echo number_format($total_venda - ($total_vista + $total_prazo), 2, ',', '.'); ?>
        </div>

    </div>
</div>

<?php require '../../layout/footer.php'; ?>
