<?php
require __DIR__ . '/../../config/conexao.php';

header('Content-Type: application/json');

if (($_GET['token'] ?? '') !== '123456') {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso negado']);
    exit;
}

$empresa = (int)($_GET['empresa'] ?? 0);
$limit = max(1, min(500, (int)($_GET['limit'] ?? 500)));

try {
    $pdo_master->exec("
        CREATE TABLE IF NOT EXISTS conciliacao_recebimentos_desvinculos_firebird (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            recebimento_id INT NOT NULL,
            crcontador INT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
            tentativas INT NOT NULL DEFAULT 0,
            ultimo_erro TEXT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sincronizado_em DATETIME NULL,
            INDEX idx_desvinculo_fb_fila (empresa_id, status, id),
            INDEX idx_desvinculo_fb_cr (empresa_id, crcontador)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $stmt = $pdo_master->prepare("
        SELECT id AS FILA_ID, crcontador AS CRCONTADOR,
               CAST(recebimento_id AS CHAR) AS CHAVEINTEGRACAO_ANTERIOR
        FROM conciliacao_recebimentos_desvinculos_firebird
        WHERE empresa_id = ?
          AND status IN ('PENDENTE', 'ERRO')
        ORDER BY id
        LIMIT $limit
    ");
    $stmt->execute([$empresa]);
    echo json_encode(['status' => 'ok', 'registros' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
