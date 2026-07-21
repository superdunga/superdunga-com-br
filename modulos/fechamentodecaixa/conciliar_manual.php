<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

/* =========================
   PARAMETROS
========================= */
$rec_id = $_GET['rec'] ?? null;
$data   = $_GET['data'] ?? date('Y-m-d');
$empresa_id = (int)$_SESSION['empresa_id'];

if (!$rec_id) {
    die("Recebível não informado.");
}

/* =========================
   JANELA OPERACIONAL
========================= */
$inicio = date('Y-m-d 07:00:00', strtotime($data));
$fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));

/* =========================
   BUSCAR RECEBÍVEL
========================= */
$stmt = $pdo_master->prepare("
    SELECT *
    FROM armazem_conciliacao_recebimentos
    WHERE id = ?
      AND empresa_id = ?
");
$stmt->execute([$rec_id, $empresa_id]);
$rec = $stmt->fetch(PDO::FETCH_ASSOC);

if ($rec && !empty($rec['CRCONTADOR'])) {
    $stmtCrVinculado = $pdo_master->prepare("
        SELECT
            CRCONTADOR,
            NUMDOCORIGEM,
            DTLANC,
            DTEMISSAO,
            VLRPARCELA,
            CMCONTADOR,
            recebimento_id
        FROM armazem_cr001
        WHERE CRCONTADOR = ?
          AND EMPRESA = ?
        LIMIT 1
    ");
    $stmtCrVinculado->execute([(int)$rec['CRCONTADOR'], $empresa_id]);
    $crVinculado = $stmtCrVinculado->fetch(PDO::FETCH_ASSOC);
    ?>

    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Conciliacao Manual</h5>
            <a href="conciliar_recebimentos.php?data=<?= htmlspecialchars($data) ?>" class="btn btn-sm btn-secondary">Voltar</a>
        </div>
    </div>

    <div class="alert alert-info">
        Este recebivel ja esta vinculado ao CR001 <strong><?= (int)$rec['CRCONTADOR'] ?></strong>.
        Por isso ele nao deve exibir novos possiveis matches.
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <strong>Recebivel selecionado:</strong><br>
            ID: <?= (int)$rec['id'] ?><br>
            CM: <strong><?= htmlspecialchars((string)$rec['CMCONTADOR']) ?></strong><br>
            Valor: <strong>R$ <?= number_format((float)$rec['valor_bruto'], 2, ',', '.') ?></strong><br>
            Data: <?= !empty($rec['data_venda']) ? date('d/m/Y H:i', strtotime($rec['data_venda'])) : '-' ?>
        </div>
    </div>

    <?php if ($crVinculado): ?>
        <div class="card">
            <div class="card-header">CR001 vinculado</div>
            <div class="card-body">
                <div>Venda: <strong><?= htmlspecialchars((string)($crVinculado['NUMDOCORIGEM'] ?? '')) ?></strong></div>
                <div>CM: <strong><?= htmlspecialchars((string)$crVinculado['CMCONTADOR']) ?></strong></div>
                <div>Data: <?= !empty($crVinculado['DTLANC']) ? date('d/m/Y H:i', strtotime($crVinculado['DTLANC'])) : '-' ?></div>
                <div>Data do Movimento: <?= !empty($crVinculado['DTEMISSAO']) ? date('d/m/Y', strtotime($crVinculado['DTEMISSAO'])) : '-' ?></div>
                <div>Valor: <strong>R$ <?= number_format((float)$crVinculado['VLRPARCELA'], 2, ',', '.') ?></strong></div>
            </div>
        </div>
    <?php endif; ?>

    <?php
    require '../../layout/footer.php';
    exit;
}

if (!$rec) {
    die("Recebível não encontrado.");
}

/* =========================
   BUSCAR CR001 DISPONÍVEIS
   (REGRA CORRETA)
========================= */
$stmtCr = $pdo_master->prepare("
    SELECT 
        c.CRCONTADOR,
        c.NUMDOCORIGEM,
        c.DTLANC,
        c.DTEMISSAO,
        c.DTVENDA,
        c.VLRPARCELA,
        c.CMCONTADOR,
        CASE
            WHEN DATE(c.DTEMISSAO) = DATE(?) AND c.CMCONTADOR = ? THEN 0
            WHEN DATE(c.DTVENDA) = DATE(?) AND c.CMCONTADOR = ? THEN 1
            WHEN DATE(c.DTEMISSAO) = DATE(?) THEN 2
            WHEN DATE(c.DTVENDA) = DATE(?) THEN 3
            WHEN c.DTLANC BETWEEN ? AND ? THEN 4
            ELSE 5
        END AS prioridade_match,
        CASE
            WHEN DATE(c.DTEMISSAO) = DATE(?) THEN 'Data movimento'
            WHEN DATE(c.DTVENDA) = DATE(?) THEN 'Data venda'
            WHEN c.DTLANC BETWEEN ? AND ? THEN 'Data lancamento'
            ELSE 'Aproximado'
        END AS criterio_match
    FROM armazem_cr001 c
    WHERE c.recebimento_id IS NULL
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_conciliacao_recebimentos r2
          WHERE r2.empresa_id = c.EMPRESA
            AND r2.CRCONTADOR = c.CRCONTADOR
      )
      AND c.EMPRESA = ?
      AND c.CMCONTADOR <> 9
      AND COALESCE(c.STATUS, '') <> 'QT'
      AND (c.validado IS NULL OR c.validado <> 'S')
      AND COALESCE(c.excluido_firebird, 'N') = 'N'

      AND ABS(c.VLRPARCELA) = ABS(?)
      AND (
          DATE(c.DTEMISSAO) = DATE(?)
          OR DATE(c.DTVENDA) = DATE(?)
          OR c.DTLANC BETWEEN ? AND ?
      )

    ORDER BY prioridade_match ASC, c.DTLANC ASC, c.CRCONTADOR ASC
");

$stmtCr->execute([
    $rec['data_venda'],
    $rec['CMCONTADOR'],
    $rec['data_venda'],
    $rec['CMCONTADOR'],
    $rec['data_venda'],
    $rec['data_venda'],
    $inicio,
    $fim,
    $rec['data_venda'],
    $rec['data_venda'],
    $inicio,
    $fim,
    $empresa_id,
    $rec['valor_bruto'],
    $rec['data_venda'],
    $rec['data_venda'],
    $inicio,
    $fim
]);

$crs = $stmtCr->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   FALLBACK (se não achar nada)
========================= */
$modoFallback = false;

if (empty($crs)) {

    $modoFallback = true;

    $stmtCr = $pdo_master->prepare("
        SELECT 
            c.CRCONTADOR,
            c.NUMDOCORIGEM,
            c.DTLANC,
            c.DTEMISSAO,
            c.VLRPARCELA,
            c.CMCONTADOR,
            ABS(c.VLRPARCELA - ?) AS diferenca,
            ABS(TIMESTAMPDIFF(MINUTE, ?, c.DTLANC)) AS distancia_minutos
        FROM armazem_cr001 c
        WHERE c.recebimento_id IS NULL
          AND NOT EXISTS (
              SELECT 1
              FROM armazem_conciliacao_recebimentos r2
              WHERE r2.empresa_id = c.EMPRESA
                AND r2.CRCONTADOR = c.CRCONTADOR
          )
          AND c.EMPRESA = ?
          AND c.CMCONTADOR <> 9
          AND COALESCE(c.STATUS, '') <> 'QT'
          AND (c.validado IS NULL OR c.validado <> 'S')
          AND COALESCE(c.excluido_firebird, 'N') = 'N'

          AND c.DTLANC BETWEEN DATE_SUB(?, INTERVAL 5 DAY)
                           AND DATE_ADD(?, INTERVAL 5 DAY)

        ORDER BY diferenca ASC, distancia_minutos ASC, c.DTLANC ASC
        LIMIT 15
    ");

    $stmtCr->execute([
        $rec['valor_bruto'],
        $rec['data_venda'],
        $empresa_id,
        $rec['data_venda'],
        $rec['data_venda']
    ]);

    $crs = $stmtCr->fetchAll(PDO::FETCH_ASSOC);
}

if ($modoFallback && empty($crs)) {
    $stmtCr = $pdo_master->prepare("
        SELECT
            c.CRCONTADOR,
            c.NUMDOCORIGEM,
            c.DTLANC,
            c.DTEMISSAO,
            c.VLRPARCELA,
            c.CMCONTADOR,
            0 AS diferenca,
            ABS(TIMESTAMPDIFF(MINUTE, ?, c.DTLANC)) AS distancia_minutos
        FROM armazem_cr001 c
        WHERE c.recebimento_id IS NULL
          AND NOT EXISTS (
              SELECT 1
              FROM armazem_conciliacao_recebimentos r2
              WHERE r2.empresa_id = c.EMPRESA
                AND r2.CRCONTADOR = c.CRCONTADOR
          )
          AND c.EMPRESA = ?
          AND c.CMCONTADOR <> 9
          AND COALESCE(c.STATUS, '') <> 'QT'
          AND (c.validado IS NULL OR c.validado <> 'S')
          AND COALESCE(c.excluido_firebird, 'N') = 'N'
          AND ABS(c.VLRPARCELA) = ABS(?)
        ORDER BY distancia_minutos ASC, c.DTLANC ASC
        LIMIT 15
    ");

    $stmtCr->execute([
        $rec['data_venda'],
        $empresa_id,
        $rec['valor_bruto']
    ]);

    $crsFallbackValorCm = $stmtCr->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($crsFallbackValorCm)) {
        $crs = $crsFallbackValorCm;
    }
}
?>

<div class="card shadow-sm mb-3">
    <div class="card-header">
        <h5>Conciliação Manual</h5>
    </div>
</div>

<!-- RECEBÍVEL -->
<div class="card mb-3">
    <div class="card-body">
        <strong>Recebível selecionado:</strong><br>

        ID: <?= $rec['id'] ?><br>

        CM: <strong><?= $rec['CMCONTADOR'] ?></strong><br>

        Valor: <strong>R$ <?= number_format($rec['valor_bruto'],2,',','.') ?></strong><br>

        Data: <?= date('d/m/Y H:i', strtotime($rec['data_venda'])) ?>
    </div>
</div>

<!-- LISTA CR001 -->
<div class="card">
    <div class="card-header">

        <?php if ($modoFallback): ?>
            <span class="text-danger">
                ⚠ Nenhum match direto encontrado. Mostrando aproximações:
            </span>
        <?php else: ?>
            <span class="text-success">
                ✔ Possíveis correspondências:
            </span>
        <?php endif; ?>

    </div>

    <div class="card-body">

        <table class="table table-sm table-bordered table-hover">
            <thead>
                <tr>
                    <th>Venda</th>
                    <th>CM</th>
                    <th>Data</th>
                    <th>Data do Movimento</th>
                    <th>Valor</th>
                    <th>Criterio</th>

                    <?php if ($modoFallback): ?>
                        <th>Diferença</th>
                    <?php endif; ?>

                    <th>Ação</th>
                </tr>
            </thead>

            <tbody>

                <?php if (empty($crs)): ?>
                    <tr>
                        <td colspan="<?= $modoFallback ? 8 : 7 ?>" class="text-center text-muted">
                            Nenhum lançamento encontrado.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($crs as $c): ?>
                <tr>

                    <td><?= htmlspecialchars((string)($c['NUMDOCORIGEM'] ?? '')) ?></td>

                    <td><?= $c['CMCONTADOR'] ?></td>

                    <td><?= date('d/m/Y H:i', strtotime($c['DTLANC'])) ?></td>

                    <td><?= !empty($c['DTEMISSAO']) ? date('d/m/Y', strtotime($c['DTEMISSAO'])) : '-' ?></td>

                    <td>
                        R$ <?= number_format($c['VLRPARCELA'],2,',','.') ?>
                    </td>

                    <td><?= htmlspecialchars($c['criterio_match'] ?? ($modoFallback ? 'Aproximado' : '-')) ?></td>

                    <?php if ($modoFallback): ?>
                    <td>
                        R$ <?= number_format($c['diferenca'],2,',','.') ?>
                    </td>
                    <?php endif; ?>

                    <td>
                        <a href="conciliar_exec.php?rec=<?= $rec['id'] ?>&cr=<?= $c['CRCONTADOR'] ?>&data=<?= $data ?>" 
                           class="btn btn-sm btn-success">
                           ✔ Vincular
                        </a>
                    </td>

                </tr>
                <?php endforeach; ?>

            </tbody>
        </table>

    </div>
</div>

<?php require '../../layout/footer.php'; ?>
