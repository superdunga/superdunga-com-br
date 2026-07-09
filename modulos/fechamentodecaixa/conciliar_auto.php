<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/auth.php';
require '../../config/conexao.php';

$modo = $_GET['modo'] ?? 'seguro';
$data = $_GET['data'] ?? date('Y-m-d');
$lote = (int)($_GET['lote'] ?? 50);
$totalAnterior = (int)($_GET['total'] ?? 0);
$empresa_id = (int)$_SESSION['empresa_id'];

if (!in_array($modo, ['seguro', 'movimento', 'aproximado'], true)) {
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
              AND cx.EMPRESA = $empresa_id
              AND COALESCE(cx.excluido_firebird, 'N') = 'N'
                AND COALESCE(cx.STATUS, '') <> 'QT'
        )
          AND r.empresa_id = $empresa_id
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
          AND c.EMPRESA = $empresa_id
          AND (c.validado IS NULL OR c.validado <> 'S')
          AND COALESCE(c.excluido_firebird, 'N') = 'N'
      AND COALESCE(c.STATUS, '') <> 'QT'
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
$movimento = [];

if ($modo === 'seguro') {
    $stmtSeguro = $pdo_master->prepare($sqlSeguro);
    $stmtSeguro->execute();
    $seguros = $stmtSeguro->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================================================
   MATCH POR MOVIMENTO - VALOR + DATA_VENDA = DTEMISSAO
========================================================= */
$sqlMovimento = "
    WITH rec AS (
        SELECT
            r.id,
            r.CMCONTADOR,
            r.valor_bruto,
            DATE(r.data_venda) AS data_ref,
            ABS(r.valor_bruto) AS valor_ref,
            ROW_NUMBER() OVER (
                PARTITION BY DATE(r.data_venda), ABS(r.valor_bruto)
                ORDER BY r.id
            ) AS rn,
            COUNT(*) OVER (
                PARTITION BY DATE(r.data_venda), ABS(r.valor_bruto)
            ) AS qtd_rec
        FROM armazem_conciliacao_recebimentos r
        WHERE NOT EXISTS (
            SELECT 1
            FROM armazem_cr001 cx
            WHERE cx.recebimento_id = r.id
              AND cx.EMPRESA = $empresa_id
              AND COALESCE(cx.excluido_firebird, 'N') = 'N'
                AND COALESCE(cx.STATUS, '') <> 'QT'
        )
          AND r.empresa_id = $empresa_id
    ),
    cr AS (
        SELECT
            c.CRCONTADOR,
            c.CMCONTADOR,
            c.VLRPARCELA,
            DATE(c.DTEMISSAO) AS data_ref,
            ABS(c.VLRPARCELA) AS valor_ref,
            ROW_NUMBER() OVER (
                PARTITION BY DATE(c.DTEMISSAO), ABS(c.VLRPARCELA)
                ORDER BY c.DTLANC ASC, c.CRCONTADOR ASC
            ) AS rn,
            COUNT(*) OVER (
                PARTITION BY DATE(c.DTEMISSAO), ABS(c.VLRPARCELA)
            ) AS qtd_cr
        FROM armazem_cr001 c
        WHERE c.recebimento_id IS NULL
          AND c.EMPRESA = $empresa_id
          AND c.CMCONTADOR <> 9
          AND (c.validado IS NULL OR c.validado <> 'S')
          AND COALESCE(c.excluido_firebird, 'N') = 'N'
      AND COALESCE(c.STATUS, '') <> 'QT'
    )
    SELECT r.id rec_id, r.CMCONTADOR, c.CRCONTADOR
    FROM rec r
    JOIN cr c
      ON r.valor_ref = c.valor_ref
     AND r.data_ref = c.data_ref
     AND r.rn = c.rn
    WHERE r.qtd_rec = c.qtd_cr
    ORDER BY r.data_ref ASC, r.id ASC, c.CRCONTADOR ASC
    LIMIT $lote
";

if ($modo === 'movimento') {
    $stmtMovimento = $pdo_master->prepare($sqlMovimento);
    $stmtMovimento->execute();
    $movimento = $stmtMovimento->fetchAll(PDO::FETCH_ASSOC);
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
      AND r.empresa_id = $empresa_id
      AND c.DTLANC BETWEEN ? AND ?
      AND c.EMPRESA = $empresa_id
      AND c.recebimento_id IS NULL
      AND (c.validado IS NULL OR c.validado <> 'S')
      AND COALESCE(c.excluido_firebird, 'N') = 'N'
      AND COALESCE(c.STATUS, '') <> 'QT'
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_cr001 cx
          WHERE cx.recebimento_id = r.id
            AND cx.EMPRESA = $empresa_id
            AND COALESCE(cx.excluido_firebird, 'N') = 'N'
                AND COALESCE(cx.STATUS, '') <> 'QT'
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
function conciliar($pdo, $rec_id, $cm, $crcontador, $empresa_id) {

    // Evita reutilizar o mesmo recebivel quando o vinculo ja foi gravado nele.
    $checkRecebivel = $pdo->prepare("
        SELECT CRCONTADOR
        FROM armazem_conciliacao_recebimentos
        WHERE id = ?
          AND empresa_id = ?
        LIMIT 1
    ");
    $checkRecebivel->execute([$rec_id, $empresa_id]);
    $recebivel = $checkRecebivel->fetch(PDO::FETCH_ASSOC);

    if (!$recebivel || !empty($recebivel['CRCONTADOR'])) {
        return false;
    }

    // Evita reutilizar o mesmo CR001.
    $check = $pdo->prepare("
        SELECT recebimento_id
        FROM armazem_cr001
        WHERE CRCONTADOR = ?
          AND EMPRESA = ?
          AND COALESCE(excluido_firebird, 'N') = 'N'
          AND COALESCE(STATUS, '') <> 'QT'
    ");
    $check->execute([$crcontador, $empresa_id]);
    $existe = $check->fetch(PDO::FETCH_ASSOC);

    if (!empty($existe['recebimento_id'])) {
        return false;
    }

    // Evita reutilizar o mesmo recebivel em outro CR001.
    $checkRec = $pdo->prepare("
        SELECT 1
        FROM armazem_cr001
        WHERE recebimento_id = ?
          AND EMPRESA = ?
          AND COALESCE(excluido_firebird, 'N') = 'N'
          AND COALESCE(STATUS, '') <> 'QT'
        LIMIT 1
    ");
    $checkRec->execute([$rec_id, $empresa_id]);

    if ($checkRec->fetchColumn()) {
        return false;
    }

    $pdo->beginTransaction();

    try {
        $update = $pdo->prepare("
            UPDATE armazem_cr001
            SET recebimento_id = ?,
                CMCONTADOR = ?,
                enviado_firebird = 'N',
                data_envio_firebird = NULL
            WHERE CRCONTADOR = ?
            AND EMPRESA = ?
            AND recebimento_id IS NULL
            AND COALESCE(excluido_firebird, 'N') = 'N'
            AND COALESCE(STATUS, '') <> 'QT'
        ");

        $update->execute([$rec_id, $cm, $crcontador, $empresa_id]);

        if ($update->rowCount() < 1) {
            $pdo->rollBack();
            return false;
        }

        $updateRecebivel = $pdo->prepare("
            UPDATE armazem_conciliacao_recebimentos
            SET CRCONTADOR = ?
            WHERE id = ?
              AND empresa_id = ?
              AND CRCONTADOR IS NULL
        ");
        $updateRecebivel->execute([$crcontador, $rec_id, $empresa_id]);

        if ($updateRecebivel->rowCount() < 1) {
            $pdo->rollBack();
            return false;
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/* =========================================================
   EXECUCAO
========================================================= */
$total = 0;
$encontrados = count($seguros) + count($movimento) + count($aprox);

foreach ($seguros as $m) {
    if (conciliar($pdo_master, $m['rec_id'], $m['CMCONTADOR'], $m['CRCONTADOR'], $empresa_id)) {
        $total++;
    }
}

foreach ($movimento as $m) {
    if (conciliar($pdo_master, $m['rec_id'], $m['CMCONTADOR'], $m['CRCONTADOR'], $empresa_id)) {
        $total++;
    }
}

foreach ($aprox as $m) {
    if (conciliar($pdo_master, $m['rec_id'], $m['CMCONTADOR'], $m['CRCONTADOR'], $empresa_id)) {
        $total++;
    }
}

$totalGeral = $totalAnterior + $total;
$temMais = in_array($modo, ['seguro', 'movimento'], true)
    && (($modo === 'seguro' && count($seguros) === $lote) || ($modo === 'movimento' && count($movimento) === $lote))
    && $total > 0;

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
