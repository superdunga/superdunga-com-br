<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/conexao.php';

header('Content-Type: application/json');

// 🔒 (opcional) proteção simples por token
$token = $_GET['token'] ?? '';

if ($token !== '123456') {
    echo json_encode(['erro' => 'Acesso negado']);
    exit;
}

// 🔹 LÊ JSON
$input = file_get_contents('php://input');

if (!$input) {
    echo json_encode(['erro' => 'Nenhum dado recebido']);
    exit;
}

$ids = json_decode($input, true);

if (!is_array($ids)) {
    echo json_encode(['erro' => 'JSON invalido']);
    exit;
}

// 🔹 LIMPA TABELA TEMPORÁRIA
$pdo_master->exec("TRUNCATE armazem_bnc001_ids_temp");

// 🔹 INSERE IDS ATUAIS
$stmt = $pdo_master->prepare("
    INSERT IGNORE INTO armazem_bnc001_ids_temp (MOVCONTADOR)
    VALUES (?)
");

foreach ($ids as $id) {
    $stmt->execute([$id]);
}

// 🔹 MARCA COMO DELETADO (sumiu do Firebird)
$pdo_master->exec("
    UPDATE armazem_bnc001 m
    LEFT JOIN armazem_bnc001_ids_temp t
        ON t.MOVCONTADOR = m.MOVCONTADOR
    SET m.deletado = 'S'
    WHERE t.MOVCONTADOR IS NULL
");

// 🔹 GARANTE QUE EXISTENTES VOLTEM PRA N
$pdo_master->exec("
    UPDATE armazem_bnc001 m
    INNER JOIN armazem_bnc001_ids_temp t
        ON t.MOVCONTADOR = m.MOVCONTADOR
    SET m.deletado = 'N'
");

echo json_encode([
    'status' => 'ok',
    'total_ids_recebidos' => count($ids)
]);