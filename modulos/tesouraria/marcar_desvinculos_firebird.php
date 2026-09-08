<?php
require __DIR__ . '/../../config/conexao.php';

header('Content-Type: application/json');
$dados = json_decode(file_get_contents('php://input'), true);

if (($dados['token'] ?? '') !== '123456') {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso negado']);
    exit;
}

$empresa = (int)($dados['empresa'] ?? 0);
$resultados = $dados['resultados'] ?? [];
$permitidos = ['SINCRONIZADO', 'OBSOLETO', 'ERRO'];

try {
    $pdo_master->beginTransaction();
    $stmt = $pdo_master->prepare("
        UPDATE conciliacao_recebimentos_desvinculos_firebird
        SET status = ?, tentativas = tentativas + 1, ultimo_erro = ?,
            sincronizado_em = IF(? IN ('SINCRONIZADO', 'OBSOLETO'), NOW(), NULL)
        WHERE id = ? AND empresa_id = ?
    ");
    $atualizados = 0;
    foreach ($resultados as $resultado) {
        $status = strtoupper((string)($resultado['status'] ?? ''));
        $id = (int)($resultado['FILA_ID'] ?? 0);
        if ($id <= 0 || !in_array($status, $permitidos, true)) {
            continue;
        }
        $erro = trim((string)($resultado['erro'] ?? ''));
        $stmt->execute([$status, $erro !== '' ? $erro : null, $status, $id, $empresa]);
        $atualizados += $stmt->rowCount();
    }
    $pdo_master->commit();
    echo json_encode(['status' => 'ok', 'atualizados' => $atualizados]);
} catch (Throwable $e) {
    if ($pdo_master->inTransaction()) {
        $pdo_master->rollBack();
    }
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
