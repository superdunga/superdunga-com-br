<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$id = $_GET['id'] ?? 0;

if (!$id) {
    echo "<div class='alert alert-danger'>Inventário não informado</div>";
    exit;
}

/* =========================
   BUSCAR INVENTÁRIO
========================= */
$stmt = $pdo_master->prepare("
    SELECT *
    FROM tesouraria_inventarios
    WHERE id = ?
");
$stmt->execute([$id]);
$inventario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$inventario) {
    echo "<div class='alert alert-danger'>Inventário não encontrado</div>";
    exit;
}

/* =========================
   BUSCAR DETALHES
========================= */
$stmt = $pdo_master->prepare("
    SELECT 
        d.*,
        t.descricao
    FROM tesouraria_inventarios_detalhe d
    JOIN tesouraria_tipos_dinheiro t 
        ON t.id = d.tipo_dinheiro_id
    WHERE d.inventario_id = ?
    ORDER BY t.valor DESC
");
$stmt->execute([$id]);
$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   EXPORTAR EXCEL (BR)
========================= */
if (isset($_GET['exportar'])) {

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=inventario_{$id}.csv");

    // Força Excel usar separador brasileiro
    echo "sep=;\n";

    echo "Cedula;Qtd Sistema;Qtd Fisico;Diferenca;Valor Unitario;Total Sistema;Total Fisico\n";

    foreach ($itens as $item) {

        echo $item['descricao'] . ";"
           . (int)$item['quantidade_sistema'] . ";"
           . (int)$item['quantidade_fisica'] . ";"
           . (int)$item['diferenca'] . ";"
           . number_format($item['valor_unitario'], 2, ',', '.') . ";"
           . number_format($item['valor_total_sistema'], 2, ',', '.') . ";"
           . number_format($item['valor_total_fisico'], 2, ',', '.') . "\n";
    }

    echo "\n";

    echo "TOTAL SISTEMA;;;;" . number_format($inventario['valor_sistema'], 2, ',', '.') . "\n";
    echo "TOTAL FISICO;;;;" . number_format($inventario['valor_fisico'], 2, ',', '.') . "\n";
    echo "DIFERENCA;;;;" . number_format($inventario['diferenca'], 2, ',', '.') . "\n";

    exit;
}

require '../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-body">

        <a href="inventario.php" class="btn btn-outline-secondary mb-3 w-100">
            <i class="bi bi-arrow-left"></i> Novo Inventário
        </a>

        <!-- BOTÃO EXPORTAR -->
        <div class="mb-3">
            <a href="inventario_resultado.php?id=<?= $id ?>&exportar=1" class="btn btn-success w-100">
                📥 Exportar para Excel
            </a>
        </div>

        <h5 class="mb-2">Resultado do Inventário</h5>

        <div class="mb-3">
            <span class="badge bg-<?= $inventario['status'] == 'OK' ? 'success' : 'danger' ?>">
                <?= $inventario['status'] ?>
            </span>
        </div>

        <div class="row g-2">

            <?php foreach ($itens as $item): 

                $classe = ($item['diferenca'] == 0) ? 'border-success' : 'border-danger';
                $corTexto = ($item['diferenca'] == 0) ? 'text-success' : 'text-danger';
            ?>

                <div class="col-12">
                    <div class="border <?= $classe ?> rounded p-2">

                        <div class="d-flex justify-content-between">
                            <strong><?= $item['descricao'] ?></strong>
                            <span class="<?= $corTexto ?>">
                                <?= $item['diferenca'] > 0 ? '+' : '' ?><?= $item['diferenca'] ?>
                            </span>
                        </div>

                        <div class="small text-muted">
                            Sistema: <?= $item['quantidade_sistema'] ?> | 
                            Físico: <?= $item['quantidade_fisica'] ?>
                        </div>

                        <div class="d-flex justify-content-between mt-1">
                            <span class="small">
                                R$ <?= number_format($item['valor_total_sistema'], 2, ',', '.') ?>
                            </span>

                            <span class="fw-bold <?= $corTexto ?>">
                                R$ <?= number_format($item['valor_total_fisico'], 2, ',', '.') ?>
                            </span>
                        </div>

                    </div>
                </div>

            <?php endforeach; ?>

        </div>

        <!-- TOTAL GERAL -->
        <div class="mt-3 p-3 bg-light rounded text-center">

            <div class="small text-muted">Total Sistema</div>
            <div class="fw-bold">
                R$ <?= number_format($inventario['valor_sistema'], 2, ',', '.') ?>
            </div>

            <hr>

            <div class="small text-muted">Total Físico</div>
            <div class="fw-bold">
                R$ <?= number_format($inventario['valor_fisico'], 2, ',', '.') ?>
            </div>

            <hr>

            <div class="small text-muted">Diferença</div>
            <div class="fs-4 fw-bold text-<?= $inventario['diferenca'] == 0 ? 'success' : 'danger' ?>">
                R$ <?= number_format($inventario['diferenca'], 2, ',', '.') ?>
            </div>

        </div>

    </div>
</div>

<?php require '../../layout/footer.php'; ?>