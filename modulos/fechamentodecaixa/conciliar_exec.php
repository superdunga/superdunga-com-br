<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/auth.php';
require '../../config/conexao.php';

$rec_id = $_GET['rec'] ?? null;
$cr_id  = $_GET['cr'] ?? null;
$data   = $_GET['data'] ?? null;
$empresa_id = (int)$_SESSION['empresa_id'];

if (!$rec_id || !$cr_id) {
    die("Parametros invalidos.");
}

if (!$data) {
    die("Data nao informada.");
}

$stmt = $pdo_master->prepare("
    SELECT CMCONTADOR, CRCONTADOR
    FROM armazem_conciliacao_recebimentos
    WHERE id = ?
      AND empresa_id = ?
");
$stmt->execute([$rec_id, $empresa_id]);
$rec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rec) {
    die("Recebivel nao encontrado.");
}

if (!empty($rec['CRCONTADOR'])) {
    die("Este recebivel ja esta vinculado ao CR001 " . (int)$rec['CRCONTADOR'] . ".");
}

$stmt = $pdo_master->prepare("
    SELECT recebimento_id
    FROM armazem_cr001
    WHERE CRCONTADOR = ?
      AND EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') = 'N'
      AND COALESCE(STATUS, '') <> 'QT'
");
$stmt->execute([$cr_id, $empresa_id]);
$cr = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cr) {
    die("CR001 nao encontrado ou indisponivel.");
}

if (!empty($cr['recebimento_id'])) {
    die("Este CR001 ja esta vinculado a outro recebivel.");
}

$pdo_master->beginTransaction();

try {
    $stmt = $pdo_master->prepare("
        UPDATE armazem_cr001
        SET
            recebimento_id = ?,
            CMCONTADOR = ?,
            enviado_firebird = 'N',
            data_envio_firebird = NULL
        WHERE CRCONTADOR = ?
          AND EMPRESA = ?
          AND recebimento_id IS NULL
          AND COALESCE(excluido_firebird, 'N') = 'N'
          AND COALESCE(STATUS, '') <> 'QT'
    ");
    $stmt->execute([
        $rec_id,
        $rec['CMCONTADOR'],
        $cr_id,
        $empresa_id
    ]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException("Nao foi possivel vincular o CR001 informado.");
    }

    $stmt = $pdo_master->prepare("
        UPDATE armazem_conciliacao_recebimentos
        SET CRCONTADOR = ?
        WHERE id = ?
          AND empresa_id = ?
          AND CRCONTADOR IS NULL
    ");
    $stmt->execute([$cr_id, $rec_id, $empresa_id]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException("Nao foi possivel gravar o CRCONTADOR no recebivel.");
    }

    $pdo_master->commit();
} catch (Throwable $e) {
    $pdo_master->rollBack();
    die($e->getMessage());
}

header("Location: conciliar_recebimentos.php?data=" . urlencode($data));
exit;
