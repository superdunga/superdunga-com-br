<?php
require '../../config/conexao.php';

header('Content-Type: application/json; charset=utf-8');

if (($_GET['token'] ?? '') !== '123456') {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso negado']);
    exit;
}

$empresa = (int)($_GET['empresa'] ?? 0);
$dados = json_decode(file_get_contents('php://input'), true);
if ($empresa <= 0 || !is_array($dados)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Empresa ou dados invalidos']);
    exit;
}

try {
    $colunas = [
        'ESTOQUE_GERAL' => "ALTER TABLE armazem_est004 ADD ESTOQUE_GERAL DECIMAL(18,4) NULL",
        'ESTOQUE_RESERVADO' => "ALTER TABLE armazem_est004 ADD ESTOQUE_RESERVADO DECIMAL(18,4) NULL",
        'ESTOQUE_DISPONIVEL' => "ALTER TABLE armazem_est004 ADD ESTOQUE_DISPONIVEL DECIMAL(18,4) NULL",
        'ESTOQUE_CALCULADO_EM' => "ALTER TABLE armazem_est004 ADD ESTOQUE_CALCULADO_EM DATETIME NULL",
    ];
    $verificar = $pdo_master->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'armazem_est004'
          AND COLUMN_NAME = ?
    ");
    foreach ($colunas as $coluna => $ddl) {
        $verificar->execute([$coluna]);
        if ((int)$verificar->fetchColumn() === 0) {
            $pdo_master->exec($ddl);
        }
    }

    $stmt = $pdo_master->prepare("
        UPDATE armazem_est004
        SET ESTOQUE_GERAL = ?,
            ESTOQUE_RESERVADO = ?,
            ESTOQUE_DISPONIVEL = ?,
            ESTOQUE_CALCULADO_EM = NOW()
        WHERE EMPRESA = ?
          AND CODPRODUTO = ?
    ");

    $processados = 0;
    $pdo_master->beginTransaction();
    foreach ($dados as $item) {
        if ((int)($item['EMPRESA'] ?? 0) !== $empresa || empty($item['CODPRODUTO'])) {
            continue;
        }
        $stmt->execute([
            (float)($item['ESTOQUE_GERAL'] ?? 0),
            (float)($item['ESTOQUE_RESERVADO'] ?? 0),
            (float)($item['ESTOQUE_DISPONIVEL'] ?? 0),
            $empresa,
            trim((string)$item['CODPRODUTO']),
        ]);
        $processados += $stmt->rowCount();
    }
    $pdo_master->commit();

    echo json_encode(['status' => 'ok', 'processados' => $processados]);
} catch (Throwable $e) {
    if ($pdo_master->inTransaction()) {
        $pdo_master->rollBack();
    }
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
