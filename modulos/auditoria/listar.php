<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

$empresa_id = (int)$_SESSION['empresa_id'];
$dataIni = $_GET['data_ini'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-d');
$fornecedor = trim($_GET['fornecedor'] ?? '');
$documento = trim($_GET['documento'] ?? '');

$where = [
    "c.EMPRESA = ?",
    "COALESCE(c.excluido_firebird, 'N') <> 'S'",
    "COALESCE(c.CANCELADO, 'N') <> 'S'",
    "DATE(c.DTEMISSAO) BETWEEN ? AND ?"
];
$params = [$empresa_id, $dataIni, $dataFim];

if ($fornecedor !== '') {
    $where[] = "(f.NOME LIKE ? OR f.APELIDO LIKE ? OR c.FORNECEDOR = ?)";
    $params[] = "%$fornecedor%";
    $params[] = "%$fornecedor%";
    $params[] = ctype_digit($fornecedor) ? (int)$fornecedor : 0;
}

if ($documento !== '') {
    $where[] = "c.NUMDOC LIKE ?";
    $params[] = "%$documento%";
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo_master->prepare("
    SELECT
        c.COMPRACONTADOR,
        c.DTEMISSAO,
        c.NUMDOC,
        c.TOTGERAL,
        c.FORNECEDOR,
        COALESCE(f.NOME, f.APELIDO, CONCAT('Fornecedor ', c.FORNECEDOR)) AS fornecedor_nome
    FROM armazem_est005 c
    LEFT JOIN armazem_cp003 f
        ON f.FCONTADOR = c.FORNECEDOR
       AND f.EMPRESA = c.EMPRESA
    WHERE $whereSql
    ORDER BY c.DTEMISSAO DESC, c.COMPRACONTADOR DESC
    LIMIT 200
");
$stmt->execute($params);
$compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

$itensPorCompra = [];
$comprasIds = array_values(array_filter(array_map(function ($compra) {
    return (int)($compra['COMPRACONTADOR'] ?? 0);
}, $compras)));

if (!empty($comprasIds)) {
    $placeholders = implode(',', array_fill(0, count($comprasIds), '?'));
    $stmtItens = $pdo_master->prepare("
        SELECT
            i.ITEMCOMPRACONTADOR,
            i.COMPRACONTA,
            i.PRODUTO,
            i.QTDE,
            i.TOTPRODCHEIO,
            p.CODPRODUTO,
            p.DESCPRODUTO,
            p.PRECOFINAL,
            p.PVENDA1ANT
        FROM armazem_est006 i
        LEFT JOIN armazem_est004 p
            ON p.CONTAPRODUTO = i.PRODUTO
           AND p.EMPRESA = i.EMPRESA
        WHERE i.ITEMCOMPRACONTADOR IN ($placeholders)
          AND i.EMPRESA = ?
          AND COALESCE(i.excluido_firebird, 'N') <> 'S'
          AND COALESCE(i.CANCELADO, 'N') <> 'S'
        ORDER BY i.ITEMCOMPRACONTADOR, i.COMPRACONTA
    ");
    $stmtItens->execute(array_merge($comprasIds, [$empresa_id]));

    while ($item = $stmtItens->fetch(PDO::FETCH_ASSOC)) {
        $itensPorCompra[(int)$item['ITEMCOMPRACONTADOR']][] = $item;
    }
}

function moeda($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function numero($valor, int $casas = 2): string
{
    return number_format((float)$valor, $casas, ',', '.');
}
?>

<div class="card shadow-sm">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1 class="h5 mb-1">Auditoria das Compras</h1>
            <small class="text-muted">Acompanhe compras e margens por item.</small>
        </div>
        <a href="../../index.php" class="btn btn-outline-secondary">Voltar</a>
    </div>

    <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-2">
                <label class="form-label small text-muted">Data inicial</label>
                <input type="date" name="data_ini" class="form-control" value="<?= htmlspecialchars($dataIni) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Data final</label>
                <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($dataFim) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted">Fornecedor</label>
                <input type="text" name="fornecedor" class="form-control" value="<?= htmlspecialchars($fornecedor) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Documento</label>
                <input type="text" name="documento" class="form-control" value="<?= htmlspecialchars($documento) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-primary w-100">Filtrar</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead>
                    <tr>
                        <th>Data da Compra</th>
                        <th>Fornecedor</th>
                        <th>Codigo</th>
                        <th>Documento</th>
                        <th class="text-end">Valor Total</th>
                        <th class="text-center">Detalhes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($compras)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">Nenhuma compra encontrada.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($compras as $compra): ?>
                        <?php
                            $compraId = (int)$compra['COMPRACONTADOR'];
                            $collapseId = 'compra-itens-' . $compraId;
                            $itens = $itensPorCompra[$compraId] ?? [];
                        ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($compra['DTEMISSAO'])) ?></td>
                            <td><?= htmlspecialchars($compra['fornecedor_nome']) ?></td>
                            <td><?= $compraId ?></td>
                            <td><?= htmlspecialchars($compra['NUMDOC'] ?? '') ?></td>
                            <td class="text-end"><?= moeda($compra['TOTGERAL']) ?></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= $collapseId ?>">
                                    Detalhes
                                </button>
                            </td>
                        </tr>
                        <tr class="collapse" id="<?= $collapseId ?>">
                            <td colspan="5" class="bg-light">
                                <?php if (empty($itens)): ?>
                                    <div class="text-muted small">Nenhum item encontrado para a compra <?= $compraId ?>.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Codigo</th>
                                                    <th>Descricao</th>
                                                    <th class="text-end">Quantidade</th>
                                                    <th class="text-end">Valor Total</th>
                                                    <th class="text-end">Custo Unitario</th>
                                                    <th class="text-end">Preco Venda Dia</th>
                                                    <th class="text-end">Margem</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($itens as $item): ?>
                                                    <?php
                                                        $precoFinal = (float)($item['PRECOFINAL'] ?? 0);
                                                        $precoVenda = (float)($item['PVENDA1ANT'] ?? 0);
                                                        $margem = $precoFinal > 0 ? (($precoVenda / $precoFinal) - 1) * 100 : null;
                                                    ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($item['CODPRODUTO'] ?? $item['PRODUTO']) ?></td>
                                                        <td><?= htmlspecialchars($item['DESCPRODUTO'] ?? '') ?></td>
                                                        <td class="text-end"><?= numero($item['QTDE'], 3) ?></td>
                                                        <td class="text-end"><?= moeda($item['TOTPRODCHEIO']) ?></td>
                                                        <td class="text-end"><?= moeda($precoFinal) ?></td>
                                                        <td class="text-end"><?= moeda($precoVenda) ?></td>
                                                        <td class="text-end <?= $margem !== null && $margem < 0 ? 'text-danger' : '' ?>">
                                                            <?= $margem === null ? '-' : numero($margem, 2) . '%' ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (count($compras) >= 200): ?>
            <div class="alert alert-info mt-3 mb-0">Exibindo os 200 registros mais recentes do filtro.</div>
        <?php endif; ?>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
