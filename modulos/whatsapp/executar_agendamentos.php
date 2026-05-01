<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/whatsapp_lib.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

try {
    whatsappEnsureTables($pdo_master);

    $config = whatsappConfig($pdo_master);
    $tokenConfigurado = trim((string)($config['agendamento_token'] ?? ''));
    $tokenRecebido = trim((string)($_GET['token'] ?? ''));

    if ($tokenConfigurado === '' || !hash_equals($tokenConfigurado, $tokenRecebido)) {
        http_response_code(403);
        echo json_encode([
            'status' => 'erro',
            'mensagem' => 'Token invalido.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $resultado = whatsappExecutarAgendamentos($pdo_master);

    echo json_encode([
        'status' => 'ok',
        'executadas' => count($resultado),
        'resultado' => $resultado,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'erro',
        'mensagem' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
