<?php
require '../../config/auth.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['erro' => 'Metodo nao permitido.']);
        exit;
    }

    $arquivo = $_FILES['arquivo'] ?? null;
    if (!is_array($arquivo) || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        http_response_code(400);
        echo json_encode(['erro' => 'Envie um arquivo para leitura.']);
        exit;
    }

    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($arquivo['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['erro' => 'Nao foi possivel receber o arquivo.']);
        exit;
    }

    $resultado = extrairTextoDocumentoDC((string)$arquivo['tmp_name'], (string)($arquivo['name'] ?? ''));
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
