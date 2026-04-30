<?php
require '../../config/conexao.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(["erro" => "JSON vazio"]);
    exit;
}

$ids = [];

foreach ($input as $item) {
    if (!empty($item['CRCONTADOR'])) {
        $ids[] = (int)$item['CRCONTADOR'];
    }
}

if (empty($ids)) {
    echo json_encode(["erro" => "Nenhum ID recebido"]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

$sql = "
UPDATE armazem_cr001
SET 
    enviado_firebird = 'S',
    data_envio_firebird = NOW(),
    tentativa_envio = tentativa_envio + 1
WHERE CRCONTADOR IN ($placeholders)
";

$stmt = $pdo_master->prepare($sql);
$stmt->execute($ids);

echo json_encode([
    "status" => "ok",
    "atualizados" => count($ids)
]);