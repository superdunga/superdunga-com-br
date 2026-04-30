<?php
require '../../config/conexao.php';
header('Content-Type: application/json');

$stmt = $pdo_master->query("
    SELECT MAX(REGSTAMP) AS ultima_regstamp
    FROM armazem_cr002
");

$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'ultima_regstamp' => $row['ultima_regstamp'] ?: '1900-01-01 00:00:00'
]);