<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/whatsapp_lib.php';

header('Content-Type: application/json; charset=utf-8');
whatsappEnsureTables($pdo_master);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Metodo nao permitido.');
    }
    $token = trim((string)($_GET['token'] ?? $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ''));
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if ($token === '' || !is_array($payload)) {
        http_response_code(400);
        throw new RuntimeException('Requisicao de webhook invalida.');
    }
    $resultado = whatsappGerencialProcessarWebhook($pdo_master, $token, $payload);
    echo json_encode(['ok'=>true] + $resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $e) {
    if (http_response_code() < 400) {
        http_response_code(403);
    }
    echo json_encode(['ok'=>false, 'erro'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'erro'=>'Falha interna ao processar o evento.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
