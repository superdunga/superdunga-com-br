<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$data = $_GET['data'] ?? ($_SESSION['data_conciliacao'] ?? date('Y-m-d'));
$_SESSION['data_conciliacao'] = $data;

$inicio = date('Y-m-d 07:00:00', strtotime($data));
$fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));

/* =========================================================
   CONCILIADOS
========================================================= */
$stmtOk = $pdo_master->prepare("
    SELECT
        r.id AS rec_id,
        r.data_venda,
        r.valor_bruto,
        r.CMCONTADOR AS CM_REC,
        c.CRCONTADOR,
        c.DTLANC,
        c.VLRPARCELA,
        c.CMCONTADOR AS CM_CR
    FROM armazem_conciliacao_recebimentos r
    INNER JOIN armazem_cr001 c
        ON c.recebimento_id = r.id
    WHERE r.data_venda BETWEEN ? AND ?
    ORDER BY r.data_venda ASC, r.id ASC, c.CRCONTADOR ASC
");
$stmtOk->execute([$inicio, $fim]);
$conciliados = $stmtOk->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   MATCH SEGURO - EXATO
========================================================= */
$stmtSeguro = $pdo_master->prepare("
    WITH rec AS (
        SELECT
            r.id,
            r.data_venda,
            r.valor_bruto,
            r.CMCONTADOR,
            DATE_FORMAT(r.data_venda, '%Y-%m-%d %H:%i') AS dt_ref,
            ROW_NUMBER() OVER (
                PARTITION BY DATE_FORMAT(r.data_venda, '%Y-%m-%d %H:%i'), r.valor_bruto
                ORDER BY r.id
            ) AS rn,
            COUNT(*) OVER (
                PARTITION BY DATE_FORMAT(r.data_venda, '%Y-%m-%d %H:%i'), r.valor_bruto
            ) AS qtd_rec
        FROM armazem_conciliacao_recebimentos r
        WHERE r.data_venda BETWEEN ? AND ?
          AND NOT EXISTS (
              SELECT 1
              FROM armazem_cr001 cx
              WHERE cx.recebimento_id = r.id
          )
    ),
    cr AS (
        SELECT
            c.CRCONTADOR,
            c.DTLANC,
            c.VLRPARCELA,
            c.CMCONTADOR,
            DATE_FORMAT(c.DTLANC, '%Y-%m-%d %H:%i') AS dt_ref,
            ROW_NUMBER() OVER (
                PARTITION BY DATE_FORMAT(c.DTLANC, '%Y-%m-%d %H:%i'), c.VLRPARCELA
                ORDER BY c.CRCONTADOR
            ) AS rn,
            COUNT(*) OVER (
                PARTITION BY DATE_FORMAT(c.DTLANC, '%Y-%m-%d %H:%i'), c.VLRPARCELA
            ) AS qtd_cr
        FROM armazem_cr001 c
        WHERE c.DTLANC BETWEEN ? AND ?
          AND c.recebimento_id IS NULL
          AND (c.validado IS NULL OR c.validado <> 'S')
    )
    SELECT
        r.id AS rec_id,
        r.data_venda,
        r.valor_bruto,
        r.CMCONTADOR AS CM_REC,
        c.CRCONTADOR,
        c.DTLANC,
        c.VLRPARCELA,
        c.CMCONTADOR AS CM_CR
    FROM rec r
    INNER JOIN cr c
        ON ABS(r.valor_bruto) = ABS(c.VLRPARCELA)
       AND r.dt_ref = c.dt_ref
       AND r.rn = c.rn
    WHERE r.qtd_rec = c.qtd_cr
    ORDER BY r.data_venda ASC, r.id ASC, c.CRCONTADOR ASC
");
$stmtSeguro->execute([$inicio, $fim, $inicio, $fim]);
$matchSeguro = $stmtSeguro->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   MATCH APROXIMADO - VALOR + TOLERANCIA DE 5 MINUTOS
========================================================= */
$stmtAprox = $pdo_master->prepare("
    SELECT
        r.id AS rec_id,
        r.data_venda,
        r.valor_bruto,
        r.CMCONTADOR AS CM_REC,
        c.CRCONTADOR,
        c.DTLANC,
        c.VLRPARCELA,
        c.CMCONTADOR AS CM_CR
    FROM armazem_conciliacao_recebimentos r
    INNER JOIN armazem_cr001 c
        ON ABS(r.valor_bruto) = ABS(c.VLRPARCELA)
       AND ABS(TIMESTAMPDIFF(MINUTE, r.data_venda, c.DTLANC)) <= 5
       AND DATE_FORMAT(r.data_venda, '%Y-%m-%d %H:%i') <> DATE_FORMAT(c.DTLANC, '%Y-%m-%d %H:%i')
    WHERE r.data_venda BETWEEN ? AND ?
      AND c.DTLANC BETWEEN ? AND ?
      AND c.recebimento_id IS NULL
      AND (c.validado IS NULL OR c.validado <> 'S')
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_cr001 cx
          WHERE cx.recebimento_id = r.id
      )
    ORDER BY r.valor_bruto ASC, r.data_venda ASC, c.DTLANC ASC
");
$stmtAprox->execute([$inicio, $fim, $inicio, $fim]);
$matchAproximado = $stmtAprox->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   MATCH DUPLICADO - MANTIDO
========================================================= */
$stmtDup = $pdo_master->prepare("
    WITH rec AS (
        SELECT
            r.id,
            r.data_venda,
            r.valor_bruto,
            r.CMCONTADOR,
            DATE_FORMAT(r.data_venda, '%Y-%m-%d %H:%i') AS dt_ref,
            COUNT(*) OVER (
                PARTITION BY DATE_FORMAT(r.data_venda, '%Y-%m-%d %H:%i'), r.valor_bruto
            ) AS qtd_rec
        FROM armazem_conciliacao_recebimentos r
        WHERE r.data_venda BETWEEN ? AND ?
          AND NOT EXISTS (
              SELECT 1
              FROM armazem_cr001 cx
              WHERE cx.recebimento_id = r.id
          )
    ),
    cr AS (
        SELECT
            c.CRCONTADOR,
            c.DTLANC,
            c.VLRPARCELA,
            c.CMCONTADOR,
            DATE_FORMAT(c.DTLANC, '%Y-%m-%d %H:%i') AS dt_ref,
            COUNT(*) OVER (
                PARTITION BY DATE_FORMAT(c.DTLANC, '%Y-%m-%d %H:%i'), c.VLRPARCELA
            ) AS qtd_cr
        FROM armazem_cr001 c
        WHERE c.DTLANC BETWEEN ? AND ?
          AND c.recebimento_id IS NULL
          AND (c.validado IS NULL OR c.validado <> 'S')
    )
    SELECT
        r.id AS rec_id,
        r.data_venda,
        r.valor_bruto,
        r.CMCONTADOR AS CM_REC,
        c.CRCONTADOR,
        c.DTLANC,
        c.VLRPARCELA,
        c.CMCONTADOR AS CM_CR
    FROM rec r
    INNER JOIN cr c
        ON ABS(r.valor_bruto) = ABS(c.VLRPARCELA)
       AND r.dt_ref = c.dt_ref
    WHERE r.qtd_rec <> c.qtd_cr
    ORDER BY r.valor_bruto ASC, r.data_venda ASC, r.id ASC, c.CRCONTADOR ASC
");
$stmtDup->execute([$inicio, $fim, $inicio, $fim]);
$matchDuplicado = $stmtDup->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   RECEBÍVEIS NÃO CONCILIADOS
========================================================= */
$stmtReceb = $pdo_master->prepare("
    SELECT
        r.id,
        r.data_venda,
        r.valor_bruto,
        r.CMCONTADOR
    FROM armazem_conciliacao_recebimentos r
    WHERE r.data_venda BETWEEN ? AND ?
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_cr001 cx
          WHERE cx.recebimento_id = r.id
      )
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_cr001 c
          WHERE ABS(r.valor_bruto) = ABS(c.VLRPARCELA)
            AND ABS(TIMESTAMPDIFF(MINUTE, r.data_venda, c.DTLANC)) <= 5
            AND c.DTLANC BETWEEN ? AND ?
            AND c.recebimento_id IS NULL
            AND (c.validado IS NULL OR c.validado <> 'S')
      )
    ORDER BY r.data_venda ASC, r.id ASC
");
$stmtReceb->execute([$inicio, $fim, $inicio, $fim]);
$recebimentos = $stmtReceb->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   CR001 NÃO CONCILIADOS
========================================================= */
$stmtCr = $pdo_master->prepare("
    SELECT
        c.CRCONTADOR,
        c.DTLANC,
        c.VLRPARCELA,
        c.CMCONTADOR
    FROM armazem_cr001 c
    WHERE c.DTLANC BETWEEN ? AND ?
      AND c.recebimento_id IS NULL
      AND (c.validado IS NULL OR c.validado <> 'S')
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_conciliacao_recebimentos r
          WHERE ABS(r.valor_bruto) = ABS(c.VLRPARCELA)
            AND ABS(TIMESTAMPDIFF(MINUTE, r.data_venda, c.DTLANC)) <= 5
            AND r.data_venda BETWEEN ? AND ?
            AND NOT EXISTS (
                SELECT 1
                FROM armazem_cr001 cx
                WHERE cx.recebimento_id = r.id
            )
      )
    ORDER BY c.DTLANC ASC, c.CRCONTADOR ASC
");
$stmtCr->execute([$inicio, $fim, $inicio, $fim]);
$cr001 = $stmtCr->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card shadow-sm mb-3">
    <div class="card-header">
        <h5>Conciliação de Recebimentos</h5>

        <?php if (!empty($_GET['auto'])): ?>
            <?php
                $modoAuto = ($_GET['modo'] ?? '') === 'aproximado' ? 'aproximada' : 'segura';
                $qtdAuto = (int)($_GET['qtd'] ?? 0);
                $totalAuto = (int)($_GET['total'] ?? $qtdAuto);
                $loteAuto = (int)($_GET['lote'] ?? 50);
                $continuarAuto = !empty($_GET['continuar']) && ($_GET['modo'] ?? '') === 'seguro';
            ?>
            <div class="alert alert-success mt-2 mb-0">
                Conciliacao <?= $modoAuto ?> executada: <?= $qtdAuto ?> registro(s) atualizado(s) neste lote.
                Total nesta execucao: <?= $totalAuto ?>.

                <?php if ($continuarAuto): ?>
                    <a
                        href="conciliar_auto.php?modo=seguro&data=<?= urlencode($data) ?>&lote=<?= $loteAuto ?>&total=<?= $totalAuto ?>"
                        class="btn btn-sm btn-success ms-2"
                    >
                        Continuar proximo lote
                    </a>
                <?php endif; ?>
            </div>

        <?php endif; ?>

        <form method="GET" class="row mt-2">
            <div class="col-md-3">
                <input type="date" name="data" value="<?= htmlspecialchars($data) ?>" class="form-control">
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary w-100">Filtrar</button>
            </div>

            <div class="col-md-2">
                <a href="conciliar_auto.php?modo=seguro&data=<?= urlencode($data) ?>&lote=50" class="btn btn-success w-100">
                    Conciliar seguros
                </a>
            </div>

            <div class="col-md-2">
                <a href="conciliar_auto.php?modo=aproximado&data=<?= urlencode($data) ?>" class="btn btn-warning w-100">
                    Conciliar aproximados
                </a>
            </div>

            <div class="col-md-2">
                <a href="importar_recebimentos.php" class="btn btn-secondary w-100">
                    Voltar
                </a>
            </div>
        </form>
    </div>
</div>

<!-- CONCILIADOS -->
<div class="card shadow-sm mb-3">
    <div class="card-header bg-success text-white">🟢 CONCILIADOS</div>
    <div class="card-body p-2" style="max-height:300px; overflow:auto;">
        <table class="table table-sm table-bordered text-center mb-0">
            <thead>
                <tr class="table-light">
                    <th colspan="4">Recebível</th>
                    <th colspan="4">CR001</th>
                </tr>
                <tr>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Valor</th>
                    <th>CM</th>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Valor</th>
                    <th>CM</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($conciliados)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">Nenhum registro conciliado nesta data.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($conciliados as $c): ?>
                        <tr class="table-success">
                            <td><?= $c['rec_id'] ?></td>
                            <td><?= !empty($c['data_venda']) ? date('d/m/Y H:i', strtotime($c['data_venda'])) : '-' ?></td>
                            <td>R$ <?= number_format((float)$c['valor_bruto'], 2, ',', '.') ?></td>
                            <td><?= $c['CM_REC'] ?></td>
                            <td><?= $c['CRCONTADOR'] ?></td>
                            <td><?= !empty($c['DTLANC']) ? date('d/m/Y H:i', strtotime($c['DTLANC'])) : '-' ?></td>
                            <td>R$ <?= number_format((float)$c['VLRPARCELA'], 2, ',', '.') ?></td>
                            <td><?= $c['CM_CR'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MATCH SEGURO -->
<div class="card shadow-sm mb-3">
    <div class="card-header bg-info text-white">🟢 MATCH SEGURO (AUTO) - Valor + Minuto Igual</div>
    <div class="card-body p-2" style="max-height:300px; overflow:auto;">
        <table class="table table-sm table-bordered text-center mb-0">
            <thead>
                <tr class="table-light">
                    <th colspan="4">Recebível</th>
                    <th colspan="4">CR001</th>
                </tr>
                <tr>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Valor</th>
                    <th>CM</th>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Valor</th>
                    <th>CM</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($matchSeguro)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">Nenhum match seguro nesta data.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($matchSeguro as $m): ?>
                        <tr>
                            <td><?= $m['rec_id'] ?></td>
                            <td><?= !empty($m['data_venda']) ? date('d/m/Y H:i', strtotime($m['data_venda'])) : '-' ?></td>
                            <td>R$ <?= number_format((float)$m['valor_bruto'], 2, ',', '.') ?></td>
                            <td><?= $m['CM_REC'] ?></td>
                            <td><?= $m['CRCONTADOR'] ?></td>
                            <td><?= !empty($m['DTLANC']) ? date('d/m/Y H:i', strtotime($m['DTLANC'])) : '-' ?></td>
                            <td>R$ <?= number_format((float)$m['VLRPARCELA'], 2, ',', '.') ?></td>
                            <td><?= $m['CM_CR'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MATCH APROXIMADO -->
<div class="card shadow-sm mb-3">
    <div class="card-header text-white" style="background-color:#6f42c1;">MATCH APROXIMADO (+/- 5 MINUTOS)</div>
    <div class="card-body p-2" style="max-height:300px; overflow:auto;">
        <table class="table table-sm table-bordered text-center mb-0">
            <thead>
                <tr class="table-light">
                    <th colspan="4">Recebível</th>
                    <th colspan="4">CR001</th>
                </tr>
                <tr>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Valor</th>
                    <th>CM</th>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Valor</th>
                    <th>CM</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($matchAproximado)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">Nenhum match aproximado nesta data.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($matchAproximado as $m): ?>
                        <tr>
                            <td><?= $m['rec_id'] ?></td>
                            <td><?= !empty($m['data_venda']) ? date('d/m/Y H:i', strtotime($m['data_venda'])) : '-' ?></td>
                            <td>R$ <?= number_format((float)$m['valor_bruto'], 2, ',', '.') ?></td>
                            <td><?= $m['CM_REC'] ?></td>
                            <td><?= $m['CRCONTADOR'] ?></td>
                            <td><?= !empty($m['DTLANC']) ? date('d/m/Y H:i', strtotime($m['DTLANC'])) : '-' ?></td>
                            <td>R$ <?= number_format((float)$m['VLRPARCELA'], 2, ',', '.') ?></td>
                            <td><?= $m['CM_CR'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MATCH DUPLICADO -->
<div class="card shadow-sm mb-3">
    <div class="card-header bg-warning">🟠 MATCH DUPLICADO (MANUAL)</div>
    <div class="card-body p-2" style="max-height:300px; overflow:auto;">
        <table class="table table-sm table-bordered text-center mb-0">
            <thead>
                <tr class="table-light">
                    <th colspan="4">Recebível</th>
                    <th colspan="4">CR001</th>
                    <th rowspan="2">Ação</th>
                </tr>
                <tr>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Valor</th>
                    <th>CM</th>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Valor</th>
                    <th>CM</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($matchDuplicado)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted">Nenhum match duplicado nesta data.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($matchDuplicado as $m): ?>
                        <tr>
                            <td><?= $m['rec_id'] ?></td>
                            <td><?= !empty($m['data_venda']) ? date('d/m/Y H:i', strtotime($m['data_venda'])) : '-' ?></td>
                            <td>R$ <?= number_format((float)$m['valor_bruto'], 2, ',', '.') ?></td>
                            <td><?= $m['CM_REC'] ?></td>
                            <td><?= $m['CRCONTADOR'] ?></td>
                            <td><?= !empty($m['DTLANC']) ? date('d/m/Y H:i', strtotime($m['DTLANC'])) : '-' ?></td>
                            <td>R$ <?= number_format((float)$m['VLRPARCELA'], 2, ',', '.') ?></td>
                            <td><?= $m['CM_CR'] ?></td>
                            <td>
                                <a href="conciliar_manual.php?rec=<?= $m['rec_id'] ?>&data=<?= urlencode($data) ?>" class="btn btn-sm btn-primary">
                                    Selecionar
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- NÃO CONCILIADOS -->
<div class="row">
    <div class="col-md-6">
        <div class="card shadow-sm mb-3">
            <div class="card-header">Recebíveis não conciliados</div>
            <div class="card-body p-2" style="max-height:300px; overflow:auto;">
                <table class="table table-sm table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Data</th>
                            <th>Valor</th>
                            <th>CM</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recebimentos)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">Nenhum recebível não conciliado nesta data.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recebimentos as $r): ?>
                                <tr>
                                    <td><?= $r['id'] ?></td>
                                    <td><?= !empty($r['data_venda']) ? date('d/m/Y H:i', strtotime($r['data_venda'])) : '-' ?></td>
                                    <td>R$ <?= number_format((float)$r['valor_bruto'], 2, ',', '.') ?></td>
                                    <td><?= $r['CMCONTADOR'] ?? '-' ?></td>
                                    <td>
                                        <a href="conciliar_manual.php?rec=<?= $r['id'] ?>&data=<?= urlencode($data) ?>" class="btn btn-sm btn-primary">
                                            Selecionar
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm mb-3">
            <div class="card-header">CR001 não conciliados</div>
            <div class="card-body p-2" style="max-height:300px; overflow:auto;">
                <table class="table table-sm table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Data</th>
                            <th>Valor</th>
                            <th>CM</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cr001)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">Nenhum CR001 não conciliado nesta data.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($cr001 as $c): ?>
                                <tr>
                                    <td><?= $c['CRCONTADOR'] ?></td>
                                    <td><?= !empty($c['DTLANC']) ? date('d/m/Y H:i', strtotime($c['DTLANC'])) : '-' ?></td>
                                    <td>R$ <?= number_format((float)$c['VLRPARCELA'], 2, ',', '.') ?></td>
                                    <td><?= $c['CMCONTADOR'] ?? '-' ?></td>
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
