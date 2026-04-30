<?php
require '../../config/conexao.php';

header('Content-Type: application/json');

$input = file_get_contents("php://input");
$dados = json_decode($input, true);

if (!$dados) {
    echo json_encode(["erro" => "Dados inválidos"]);
    exit;
}

try {

    $stmt = $pdo_master->prepare("
        INSERT INTO tesouraria_sincronizacao_log
        (tabela, registros, status, mensagem)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $dados['tabela'] ?? '',
        $dados['registros'] ?? 0,
        $dados['status'] ?? 'ERRO',
        substr($dados['mensagem'] ?? '', 0, 1000)
    ]);

    echo json_encode(["status" => "ok"]);

} catch (Exception $e) {

    echo json_encode([
        "erro" => $e->getMessage()
    ]);
}