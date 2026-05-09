<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/conexao.php';

header('Content-Type: application/json');

$empresa = isset($_GET['empresa']) ? (int)$_GET['empresa'] : null;
$where = '';
$params = [];

if ($empresa !== null && $empresa > 0) {
    $where = "WHERE EMPRESA = ?";
    $params[] = $empresa;
}

$stmt = $pdo_master->prepare("
    SELECT MAX(REGSTAMP) AS ultima_regstamp
    FROM armazem_bnc001
    $where
");
$stmt->execute($params);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    "ultima_regstamp" => $row['ultima_regstamp'] ?: '1900-01-01 00:00:00'
]);
