<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

$data = $_GET['data'] ?? date('Y-m-d');
$empresa_id = (int)$_SESSION['empresa_id'];

$inicio = date('Y-m-d 07:00:00', strtotime($data));
$fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));

$stmtPendSistema = $pdo_master->prepare("
    SELECT r.*
    FROM armazem_conciliacao_recebimentos r
    WHERE r.data_venda BETWEEN ? AND ?
      AND r.empresa_id = ?
      AND r.CRCONTADOR IS NULL
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_cr001 c
          WHERE c.recebimento_id = r.id
            AND c.EMPRESA = ?
            AND COALESCE(c.excluido_firebird, 'N') = 'N'
            AND COALESCE(c.STATUS, '') <> 'QT'
      )
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_cr001 c
          WHERE ABS(r.valor_bruto) = ABS(c.VLRPARCELA)
            AND ABS(TIMESTAMPDIFF(MINUTE, r.data_venda, c.DTLANC)) <= 5
            AND c.DTLANC BETWEEN ? AND ?
            AND c.EMPRESA = ?
            AND c.recebimento_id IS NULL
            AND (c.validado IS NULL OR c.validado <> 'S')
            AND COALESCE(c.excluido_firebird, 'N') = 'N'
            AND COALESCE(c.STATUS, '') <> 'QT'
      )
    ORDER BY r.data_venda ASC, r.id ASC
");
$stmtPendSistema->execute([$inicio, $fim, $empresa_id, $empresa_id, $inicio, $fim, $empresa_id]);
$pendentesSistema = $stmtPendSistema->fetchAll(PDO::FETCH_ASSOC);

$stmtPendCR = $pdo_master->prepare("
    SELECT c.*
    FROM armazem_cr001 c
    WHERE c.DTLANC BETWEEN ? AND ?
      AND c.EMPRESA = ?
      AND c.CMCONTADOR <> 9
      AND c.recebimento_id IS NULL
      AND COALESCE(c.STATUS, '') <> 'QT'
      AND COALESCE(c.excluido_firebird, 'N') = 'N'
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_conciliacao_recebimentos r
          WHERE ABS(r.valor_bruto) = ABS(c.VLRPARCELA)
            AND r.empresa_id = ?
            AND r.CRCONTADOR IS NULL
            AND ABS(TIMESTAMPDIFF(MINUTE, r.data_venda, c.DTLANC)) <= 5
            AND r.data_venda BETWEEN ? AND ?
            AND NOT EXISTS (
                SELECT 1
                FROM armazem_cr001 cx
                WHERE cx.recebimento_id = r.id
                  AND cx.EMPRESA = ?
                  AND COALESCE(cx.excluido_firebird, 'N') = 'N'
                  AND COALESCE(cx.STATUS, '') <> 'QT'
            )
      )
    ORDER BY c.DTLANC ASC, c.CRCONTADOR ASC
");
$stmtPendCR->execute([$inicio, $fim, $empresa_id, $empresa_id, $inicio, $fim, $empresa_id]);
$pendentesCR001 = $stmtPendCR->fetchAll(PDO::FETCH_ASSOC);

$stmtExcluidosCR = $pdo_master->prepare("
    SELECT c.*
    FROM armazem_cr001 c
    WHERE c.DTLANC BETWEEN ? AND ?
      AND c.EMPRESA = ?
      AND COALESCE(c.excluido_firebird, 'N') = 'S'
    ORDER BY c.DTLANC ASC, c.CRCONTADOR ASC
");
$stmtExcluidosCR->execute([$inicio, $fim, $empresa_id]);
$excluidosCR001 = $stmtExcluidosCR->fetchAll(PDO::FETCH_ASSOC);

$vendasCR001 = [];
foreach (array_merge($pendentesCR001, $excluidosCR001) as $registroCR) {
    $vendaOrigem = (int)($registroCR['NUMDOCORIGEM'] ?? 0);
    if ($vendaOrigem > 0) {
        $vendasCR001[$vendaOrigem] = true;
    }
}

$itensPorVenda = [];
if (!empty($vendasCR001)) {
    $idsVenda = array_keys($vendasCR001);
    $placeholders = implode(',', array_fill(0, count($idsVenda), '?'));
    $stmtItensVenda = $pdo_master->prepare("
        SELECT
            i.ITEMVENDACONTADOR,
            i.VENDACONTA,
            i.PRODUTO,
            i.QTDE,
            i.VALOR,
            i.TOTPROD,
            p.CODPRODUTO,
            p.DESCPRODUTO,
            p.UNIDADE
        FROM armazem_est008 i
        LEFT JOIN armazem_est004 p
            ON p.EMPRESA = i.EMPRESA
           AND p.CONTAPRODUTO = i.PRODUTO
        WHERE i.EMPRESA = ?
          AND i.ITEMVENDACONTADOR IN ($placeholders)
          AND COALESCE(i.CANCELADO, 'N') <> 'S'
        ORDER BY i.ITEMVENDACONTADOR ASC, i.VENDACONTA ASC
    ");
    $stmtItensVenda->execute(array_merge([$empresa_id], $idsVenda));
    while ($itemVenda = $stmtItensVenda->fetch(PDO::FETCH_ASSOC)) {
        $itensPorVenda[(int)$itemVenda['ITEMVENDACONTADOR']][] = $itemVenda;
    }
}

function renderizarItensVendaDiagnostico(array $itens): void
{
    if (empty($itens)) {
        echo '<div class="text-muted small">Nenhum item encontrado para esta venda.</div>';
        return;
    }
    ?>
    <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Produto</th>
                    <th>Descricao</th>
                    <th class="text-end">Qtde</th>
                    <th class="text-end">Unitario</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itens as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['VENDACONTA'] ?? '') ?></td>
                        <td><?= htmlspecialchars($item['CODPRODUTO'] ?? $item['PRODUTO'] ?? '') ?></td>
                        <td class="text-start"><?= htmlspecialchars($item['DESCPRODUTO'] ?? '') ?></td>
                        <td class="text-end"><?= number_format((float)($item['QTDE'] ?? 0), 3, ',', '.') ?> <?= htmlspecialchars($item['UNIDADE'] ?? '') ?></td>
                        <td class="text-end">R$ <?= number_format((float)($item['VALOR'] ?? 0), 2, ',', '.') ?></td>
                        <td class="text-end">R$ <?= number_format((float)($item['TOTPROD'] ?? 0), 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

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
                                    <th>Pagador</th>
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
                                            <td><?= htmlspecialchars($r['pagador'] ?? '') ?></td>
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
                                    <th>NUMDOCORIGEM</th>
                                    <th>Data</th>
                                    <th>Valor</th>
                                    <th>CM</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pendentesCR001)): ?>
                                    <tr><td colspan="5" class="text-muted">Nenhum registro</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pendentesCR001 as $c): ?>
                                        <?php
                                            $vendaOrigem = (int)($c['NUMDOCORIGEM'] ?? 0);
                                            $collapseId = 'itens-cr-' . (int)$c['CRCONTADOR'];
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($c['NUMDOCORIGEM'] ?? '') ?></td>
                                            <td><?= date('d/m/Y H:i', strtotime($c['DTLANC'])) ?></td>
                                            <td>R$ <?= number_format($c['VLRPARCELA'], 2, ',', '.') ?></td>
                                            <td><?= $c['CMCONTADOR'] ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary"
                                                        type="button"
                                                        data-bs-toggle="collapse"
                                                        data-bs-target="#<?= $collapseId ?>"
                                                        title="Ver itens da venda">
                                                    &#128269;
                                                </button>
                                            </td>
                                        </tr>
                                        <tr class="collapse" id="<?= $collapseId ?>">
                                            <td colspan="5" class="bg-light">
                                                <?php renderizarItensVendaDiagnostico($itensPorVenda[$vendaOrigem] ?? []); ?>
                                            </td>
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
                            <th>NUMDOCORIGEM</th>
                            <th>Data</th>
                            <th>Valor</th>
                            <th>CM</th>
                            <th></th>
                            <th>Recebivel</th>
                            <th>Marcado em</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($excluidosCR001)): ?>
                            <tr><td colspan="7" class="text-muted">Nenhum registro</td></tr>
                        <?php else: ?>
                            <?php foreach ($excluidosCR001 as $c): ?>
                                <?php
                                    $vendaOrigem = (int)($c['NUMDOCORIGEM'] ?? 0);
                                    $collapseId = 'itens-cr-excluido-' . (int)$c['CRCONTADOR'];
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($c['NUMDOCORIGEM'] ?? '') ?></td>
                                    <td><?= !empty($c['DTLANC']) ? date('d/m/Y H:i', strtotime($c['DTLANC'])) : '-' ?></td>
                                    <td>R$ <?= number_format($c['VLRPARCELA'], 2, ',', '.') ?></td>
                                    <td><?= $c['CMCONTADOR'] ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary"
                                                type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#<?= $collapseId ?>"
                                                title="Ver itens da venda">
                                            &#128269;
                                        </button>
                                    </td>
                                    <td><?= $c['recebimento_id'] ?: '-' ?></td>
                                    <td><?= !empty($c['data_exclusao_firebird']) ? date('d/m/Y H:i', strtotime($c['data_exclusao_firebird'])) : '-' ?></td>
                                </tr>
                                <tr class="collapse" id="<?= $collapseId ?>">
                                    <td colspan="7" class="bg-light">
                                        <?php renderizarItensVendaDiagnostico($itensPorVenda[$vendaOrigem] ?? []); ?>
                                    </td>
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
