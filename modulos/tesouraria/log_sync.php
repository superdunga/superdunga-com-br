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
    $empresaId = (int)($dados['empresa_id'] ?? $dados['empresa'] ?? 1);
    if ($empresaId <= 0) {
        $empresaId = 1;
    }

    $stmt = $pdo_master->prepare("
        INSERT INTO tesouraria_sincronizacao_log
        (empresa_id, tabela, registros, status, mensagem)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $empresaId,
        strtoupper(trim((string)($dados['tabela'] ?? ''))),
        $dados['registros'] ?? 0,
        strtoupper(trim((string)($dados['status'] ?? 'ERRO'))),
        substr($dados['mensagem'] ?? '', 0, 1000)
    ]);

    echo json_encode(["status" => "ok"]);

} catch (Exception $e) {

    echo json_encode([
        "erro" => $e->getMessage()
    ]);
}
