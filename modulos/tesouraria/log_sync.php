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
    $pdo_master->exec("
        CREATE TABLE IF NOT EXISTS tesouraria_sincronizacao_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL DEFAULT 1,
            tabela VARCHAR(50) NULL,
            registros INT NULL,
            status VARCHAR(20) NULL,
            mensagem TEXT NULL,
            data_execucao DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sync_log_empresa_data (empresa_id, data_execucao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmtColunaEmpresa = $pdo_master->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tesouraria_sincronizacao_log'
          AND COLUMN_NAME = 'empresa_id'
    ");
    $stmtColunaEmpresa->execute();
    if ((int)$stmtColunaEmpresa->fetchColumn() === 0) {
        $pdo_master->exec("ALTER TABLE tesouraria_sincronizacao_log ADD empresa_id INT NOT NULL DEFAULT 1 AFTER id");
    }

    $stmtIndice = $pdo_master->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tesouraria_sincronizacao_log'
          AND INDEX_NAME = 'idx_sync_log_empresa_data'
    ");
    $stmtIndice->execute();
    if ((int)$stmtIndice->fetchColumn() === 0) {
        $pdo_master->exec("ALTER TABLE tesouraria_sincronizacao_log ADD INDEX idx_sync_log_empresa_data (empresa_id, data_execucao)");
    }

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
