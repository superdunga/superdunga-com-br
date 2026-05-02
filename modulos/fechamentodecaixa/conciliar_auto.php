<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/auth.php';
require '../../config/conexao.php';

$modo = $_GET['modo'] ?? 'seguro';
$data = $_GET['data'] ?? date('Y-m-d');
$lote = (int)($_GET['lote'] ?? 50);
$totalAnterior = (int)($_GET['total'] ?? 0);

if (!in_array($modo, ['seguro', 'aproximado'], true)) {
    $modo = 'seguro';
}

if ($lote < 1) {
    $lote = 50;
}

if ($lote > 200) {
    $lote = 200;
}

$inicio = date('Y-m-d 07:00:00', strtotime($data));
$fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));

/* =========================================================
   MATCH SEGURO (EXATO) - TODOS OS REGISTROS PENDENTES
========================================================= */
$sqlSeguro = "
    WITH rec AS (
        SELECT
            r.id,
            r.CMCONTADOR,
            r.valor_bruto,
            DATE_FORMAT(r.data_venda, '%Y-%m-%d %H:%i') AS dt_ref,
            ROW_NUMBER() OVER (
                PARTITION BY DATE_FORMAT(r.data_venda, '%Y-%m-%d %H:%i'), r.valor_bruto
                ORDER BY r.id
            ) AS rn,
            COUNT(*) OVER (
                PARTITION BY DATE_FORMAT(r.data_venda, '%Y-%m-%d %H:%i'), r.valor_bruto
            ) AS qtd_rec
        FROM armazem_conciliacao_recebimentos r
        WHERE NOT EXISTS (
            SELECT 1
            FROM armazem_cr001 cx
            WHERE cx.recebimento_id = r.id
              AND COALESCE(cx.excluido_firebird, 'N') = 'N'
        )
    ),
    cr AS (
        SELECT
            c.CRCONTADOR,
            c.CMCONTADOR,
            c.VLRPARCELA,
            DATE_FORMAT(c.DTLANC, '%Y-%m-%d %H:%i') AS dt_ref,
            ROW_NUMBER() OVER (
                PARTITION BY DATE_FORMAT(c.DTLANC, '%Y-%m-%d %H:%i'), c.VLRPARCELA
                ORDER BY c.CRCONTADOR
            ) AS rn,
            COUNT(*) OVER (
                PARTITION BY DATE_FORMAT(c.DTLANC, '%Y-%m-%d %H:%i'), c.VLRPARCELA
            ) AS qtd_cr
        FROM armazem_cr001 c
        WHERE c.recebimento_id IS NULL
          AND (c.validado IS NULL OR c.validado <> 'S')
          AND COALESCE(c.excluido_firebird, 'N') = 'N'
    )
    SELECT r.id rec_id, r.CMCONTADOR, c.CRCONTADOR
    FROM rec r
    JOIN cr c
      ON ABS(r.valor_bruto) = ABS(c.VLRPARCELA)
     AND r.dt_ref = c.dt_ref
     AND r.rn = c.rn
    WHERE r.qtd_rec = c.qtd_cr
    ORDER BY r.dt_ref ASC, r.id ASC, c.CRCONTADOR ASC
    LIMIT $lote
";

$seguros = [];

if ($modo === 'seguro') {
    $stmtSeguro = $pdo_master->prepare($sqlSeguro);
    $stmtSeguro->execute();
    $seguros = $stmtSeguro->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================================================
   MATCH APROXIMADO (+/- 5 MINUTOS) - DATA FILTRADA
========================================================= */
$sqlAproximado = "
    SELECT
        r.id AS rec_id,
        r.CMCONTADOR,
        c.CRCONTADOR
    FROM armazem_conciliacao_recebimentos r
    JOIN armazem_cr001 c
      ON ABS(r.valor_bruto) = ABS(c.VLRPARCELA)
     AND ABS(TIMESTAMPDIFF(MINUTE, r.data_venda, c.DTLANC)) <= 5
     AND DATE_FORMAT(r.data_venda, '%Y-%m-%d %H:%i') <> DATE_FORMAT(c.DTLANC, '%Y-%m-%d %H:%i')
    WHERE r.data_venda BETWEEN ? AND ?
      AND c.DTLANC BETWEEN ? AND ?
      AND c.recebimento_id IS NULL
      AND (c.validado IS NULL OR c.validado <> 'S')
      AND COALESCE(c.excluido_firebird, 'N') = 'N'
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_cr001 cx
          WHERE cx.recebimento_id = r.id
            AND COALESCE(cx.excluido_firebird, 'N') = 'N'
      )
";

$aprox = [];

if ($modo === 'aproximado') {
    $stmtAprox = $pdo_master->prepare($sqlAproximado);
    $stmtAprox->execute([$inicio, $fim, $inicio, $fim]);
    $aprox = $stmtAprox->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================================================
   FUNCAO DE UPDATE SEGURA
========================================================= */
function conciliar($pdo, $rec_id, $cm, $crcontador) {

    // Evita reutilizar o mesmo CR001.
    $check = $pdo->prepare("
        SELECT recebimento_id
        FROM armazem_cr001
        WHERE CRCONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') = 'N'
    ");
    $check->execute([$crcontador]);
    $existe = $check->fetch(PDO::FETCH_ASSOC);

    if (!empty($existe['recebimento_id'])) {
        return false;
    }

    // Evita reutilizar o mesmo recebivel em outro CR001.
    $checkRec = $pdo->prepare("
        SELECT 1
        FROM armazem_cr001
        WHERE recebimento_id = ?
          AND COALESCE(excluido_firebird, 'N') = 'N'
        LIMIT 1
    ");
    $checkRec->execute([$rec_id]);

    if ($checkRec->fetchColumn()) {
        return false;
    }

    $update = $pdo->prepare("
        UPDATE armazem_cr001
        SET recebimento_id = ?, CMCONTADOR = ?
        WHERE CRCONTADOR = ?
        AND recebimento_id IS NULL
        AND COALESCE(excluido_firebird, 'N') = 'N'
    ");

    return $update->execute([$rec_id, $cm, $crcontador]);
}

/* =========================================================
   EXECUCAO
========================================================= */
$total = 0;
$encontrados = count($seguros) + count($aprox);

foreach ($seguros as $m) {
    if (conciliar($pdo_master, $m['rec_id'], $m['CMCONTADOR'], $m['CRCONTADOR'])) {
        $total++;
    }
}

foreach ($aprox as $m) {
    if (conciliar($pdo_master, $m['rec_id'], $m['CMCONTADOR'], $m['CRCONTADOR'])) {
        $total++;
    }
}

$totalGeral = $totalAnterior + $total;
$temMais = $modo === 'seguro' && count($seguros) === $lote && $total > 0;

$params = [
    'data' => $data,
    'auto' => 1,
    'modo' => $modo,
    'qtd' => $total,
    'total' => $totalGeral,
    'lote' => $lote,
    'encontrados' => $encontrados,
];

if ($temMais) {
    $params['continuar'] = 1;
}

header("Location: conciliar_recebimentos.php?" . http_build_query($params));
exit;
