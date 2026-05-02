<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/auth.php';
require '../../config/conexao.php';

/* =========================
   RECEBER PARÂMETROS
========================= */
$rec_id = $_GET['rec'] ?? null;
$cr_id  = $_GET['cr'] ?? null;
$data   = $_GET['data'] ?? null;

/* =========================
   VALIDAÇÕES
========================= */
if (!$rec_id || !$cr_id) {
    die("Parâmetros inválidos.");
}

if (!$data) {
    die("Data não informada.");
}

/* =========================
   BUSCAR RECEBÍVEL
========================= */
$stmt = $pdo_master->prepare("
    SELECT CMCONTADOR
    FROM armazem_conciliacao_recebimentos
    WHERE id = ?
");
$stmt->execute([$rec_id]);
$rec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rec) {
    die("Recebível não encontrado.");
}

/* =========================
   VALIDAR SE CR001 JÁ FOI USADO
========================= */
$stmt = $pdo_master->prepare("
    SELECT recebimento_id
    FROM armazem_cr001
    WHERE CRCONTADOR = ?
      AND COALESCE(excluido_firebird, 'N') = 'N'
");
$stmt->execute([$cr_id]);
$cr = $stmt->fetch(PDO::FETCH_ASSOC);

if (!empty($cr['recebimento_id'])) {
    die("Este CR001 já está vinculado a outro recebível.");
}

/* =========================
   FAZER VÍNCULO REAL
========================= */
$stmt = $pdo_master->prepare("
    UPDATE armazem_cr001
    SET 
        CMCONTADOR = ?,
        recebimento_id = ?
    WHERE CRCONTADOR = ?
    AND recebimento_id IS NULL
    AND COALESCE(excluido_firebird, 'N') = 'N'
");
$stmt->execute([
    $rec['CMCONTADOR'],
    $rec_id,
    $cr_id
]);

/* =========================
   REDIRECIONAR (DATA GARANTIDA)
========================= */
header("Location: conciliar_recebimentos.php?data=" . urlencode($data));
exit;
