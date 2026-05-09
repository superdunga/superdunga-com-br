<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../../config/conexao.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if ($token !== '123456') {
    echo json_encode(['erro' => 'Acesso negado']);
    exit;
}

$empresa = isset($_GET['empresa']) ? (int)$_GET['empresa'] : 1;
$limit = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 500;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

if ($empresa <= 0) {
    echo json_encode(['erro' => 'Empresa invalida']);
    exit;
}

try {
    $stmt = $pdo_master->prepare("
        SELECT
            CRCONTADOR,
            CAST(recebimento_id AS CHAR) AS CHAVEINTEGRACAO,
            CMCONTADOR,
            DATE_FORMAT(DTVENC, '%Y-%m-%d %H:%i:%s') AS DTVENC
        FROM armazem_cr001
        WHERE EMPRESA = ?
          AND recebimento_id IS NOT NULL
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        ORDER BY CRCONTADOR
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute([$empresa]);

    echo json_encode([
        'status' => 'ok',
        'empresa' => $empresa,
        'limit' => $limit,
        'offset' => $offset,
        'registros' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
