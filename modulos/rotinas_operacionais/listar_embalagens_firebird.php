<?php
require '../../config/conexao.php';

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? '';
if ($token !== '123456') {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso negado']);
    exit;
}

$empresa = isset($_GET['empresa']) ? (int)$_GET['empresa'] : 1;
$limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 200;

if ($empresa <= 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'Empresa invalida']);
    exit;
}

try {
    $stmt = $pdo_master->prepare("
        SELECT
            i.id,
            i.recebimento_id,
            r.id_firebird,
            r.empresa_id,
            i.codproduto,
            i.codigo_barras,
            i.descproduto,
            CAST(i.emb_qtde_atual AS DECIMAL(15,4)) AS emb_qtde_atual,
            CAST(i.quantidade_por_caixa AS DECIMAL(15,4)) AS emb_qtde_nova
        FROM recebimento_mercadorias_itens i
        INNER JOIN recebimento_mercadorias r ON r.id = i.recebimento_id
        WHERE r.empresa_id = ?
          AND r.status = 'finalizado'
          AND i.status IN ('pendente_firebird', 'erro_firebird')
          AND i.enviado_firebird IN ('N', 'E')
          AND i.produto_encontrado = 'S'
          AND i.codproduto IS NOT NULL
          AND i.codproduto <> ''
        ORDER BY r.finalizado_em, i.recebimento_id, i.ordem, i.id
        LIMIT {$limit}
    ");
    $stmt->execute([$empresa]);

    echo json_encode([
        'status' => 'ok',
        'empresa' => $empresa,
        'registros' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
