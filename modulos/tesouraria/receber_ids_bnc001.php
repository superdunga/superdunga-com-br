<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/conexao.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';

if ($token !== '123456') {
    echo json_encode(['erro' => 'Acesso negado']);
    exit;
}

$input = file_get_contents('php://input');

if (!$input) {
    echo json_encode(['erro' => 'Nenhum dado recebido']);
    exit;
}

$ids = json_decode($input, true);

if (!is_array($ids)) {
    echo json_encode(['erro' => 'JSON invalido']);
    exit;
}

function garantirControleDelecaoBNC001(PDO $pdo): void
{
    $colunas = [
        'deletado' => "ALTER TABLE armazem_bnc001 ADD deletado CHAR(1) NULL DEFAULT 'N'",
        'data_delecao_firebird' => "ALTER TABLE armazem_bnc001 ADD data_delecao_firebird DATETIME NULL",
        'motivo_sync' => "ALTER TABLE armazem_bnc001 ADD motivo_sync VARCHAR(100) NULL",
        'ultima_presenca_firebird' => "ALTER TABLE armazem_bnc001 ADD ultima_presenca_firebird DATETIME NULL",
    ];

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'armazem_bnc001'
          AND COLUMN_NAME = ?
    ");

    foreach ($colunas as $coluna => $sql) {
        $stmt->execute([$coluna]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'armazem_bnc001'
          AND INDEX_NAME = 'idx_bnc001_deletado_dtlanc'
    ");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE armazem_bnc001 ADD INDEX idx_bnc001_deletado_dtlanc (deletado, DTLANC)");
    }
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

if (empty($ids) && ($_GET['confirmar_vazio'] ?? '') !== '1') {
    echo json_encode([
        'erro' => 'Lista de MOVCONTADOR vazia. Para marcar todos como deletados, envie confirmar_vazio=1.'
    ]);
    exit;
}

garantirControleDelecaoBNC001($pdo_master);

$pdo_master->beginTransaction();

try {
    $pdo_master->exec("TRUNCATE armazem_bnc001_ids_temp");

    $stmt = $pdo_master->prepare("
        INSERT IGNORE INTO armazem_bnc001_ids_temp (MOVCONTADOR)
        VALUES (?)
    ");

    foreach ($ids as $id) {
        $stmt->execute([$id]);
    }

    $stmtAtivos = $pdo_master->prepare("
        UPDATE armazem_bnc001 m
        INNER JOIN armazem_bnc001_ids_temp t
            ON t.MOVCONTADOR = m.MOVCONTADOR
        SET m.deletado = 'N',
            m.data_delecao_firebird = NULL,
            m.motivo_sync = NULL,
            m.ultima_presenca_firebird = NOW()
    ");
    $stmtAtivos->execute();
    $reativados = $stmtAtivos->rowCount();

    $stmtDeletados = $pdo_master->prepare("
        UPDATE armazem_bnc001 m
        LEFT JOIN armazem_bnc001_ids_temp t
            ON t.MOVCONTADOR = m.MOVCONTADOR
        SET m.deletado = 'S',
            m.data_delecao_firebird = NOW(),
            m.motivo_sync = 'Nao encontrado na foto BNC001 do Firebird'
        WHERE t.MOVCONTADOR IS NULL
          AND COALESCE(m.deletado, 'N') <> 'S'
    ");
    $stmtDeletados->execute();
    $marcadosDeletados = $stmtDeletados->rowCount();

    $pdo_master->commit();
} catch (Throwable $e) {
    if ($pdo_master->inTransaction()) {
        $pdo_master->rollBack();
    }

    echo json_encode(['erro' => $e->getMessage()]);
    exit;
}

echo json_encode([
    'status' => 'ok',
    'total_ids_recebidos' => count($ids),
    'reativados' => $reativados,
    'marcados_deletados' => $marcadosDeletados
]);
