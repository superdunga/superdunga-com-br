<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/auth.php';
require '../../config/conexao.php';

$data = $_GET['data'] ?? date('Y-m-d');

$inicio = date('Y-m-d 07:00:00', strtotime($data));
$fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));

/* =========================================================
   1. MATCH SEGURO (EXATO)
========================================================= */
$stmtSeguro = $pdo_master->prepare("
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
        WHERE r.data_venda BETWEEN ? AND ?
          AND NOT EXISTS (
              SELECT 1 FROM armazem_cr001 cx WHERE cx.recebimento_id = r.id
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
        WHERE c.DTLANC BETWEEN ? AND ?
          AND c.recebimento_id IS NULL
          AND (c.validado IS NULL OR c.validado <> 'S')
    )
    SELECT r.id rec_id, r.CMCONTADOR, c.CRCONTADOR
    FROM rec r
    JOIN cr c
      ON ABS(r.valor_bruto) = ABS(c.VLRPARCELA)
     AND r.dt_ref = c.dt_ref
     AND r.rn = c.rn
    WHERE r.qtd_rec = c.qtd_cr
");

$stmtSeguro->execute([$inicio, $fim, $inicio, $fim]);
$seguros = $stmtSeguro->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   2. MATCH APROXIMADO (±1 MINUTO)
========================================================= */
$stmtAprox = $pdo_master->prepare("
    SELECT 
        r.id AS rec_id,
        r.CMCONTADOR,
        c.CRCONTADOR
    FROM armazem_conciliacao_recebimentos r
    JOIN armazem_cr001 c
      ON ABS(r.valor_bruto) = ABS(c.VLRPARCELA)
     AND ABS(TIMESTAMPDIFF(MINUTE, r.data_venda, c.DTLANC)) <= 1
    WHERE r.data_venda BETWEEN ? AND ?
      AND c.DTLANC BETWEEN ? AND ?
      AND c.recebimento_id IS NULL
      AND (c.validado IS NULL OR c.validado <> 'S')
      AND NOT EXISTS (
          SELECT 1 FROM armazem_cr001 cx WHERE cx.recebimento_id = r.id
      )
");

$stmtAprox->execute([$inicio, $fim, $inicio, $fim]);
$aprox = $stmtAprox->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   FUNÇÃO DE UPDATE SEGURA
========================================================= */
function conciliar($pdo, $rec_id, $cm, $crcontador) {

    // evita sobrescrever
    $check = $pdo->prepare("
        SELECT recebimento_id 
        FROM armazem_cr001 
        WHERE CRCONTADOR = ?
    ");
    $check->execute([$crcontador]);
    $existe = $check->fetch(PDO::FETCH_ASSOC);

    if (!empty($existe['recebimento_id'])) {
        return false;
    }

    $update = $pdo->prepare("
        UPDATE armazem_cr001
        SET recebimento_id = ?, CMCONTADOR = ?
        WHERE CRCONTADOR = ?
        AND recebimento_id IS NULL
    ");

    return $update->execute([$rec_id, $cm, $crcontador]);
}

/* =========================================================
   EXECUÇÃO
========================================================= */
$total = 0;

/* --- 1. EXECUTA SEGURO PRIMEIRO --- */
foreach ($seguros as $m) {
    if (conciliar($pdo_master, $m['rec_id'], $m['CMCONTADOR'], $m['CRCONTADOR'])) {
        $total++;
    }
}

/* --- 2. EXECUTA APROXIMADO DEPOIS --- */
foreach ($aprox as $m) {
    if (conciliar($pdo_master, $m['rec_id'], $m['CMCONTADOR'], $m['CRCONTADOR'])) {
        $total++;
    }
}

/* =========================================================
   REDIRECIONA
========================================================= */
header("Location: conciliar_recebimentos.php?data=".$data."&auto=1&qtd=".$total);
exit;