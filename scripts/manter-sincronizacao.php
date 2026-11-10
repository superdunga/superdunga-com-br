<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execucao permitida somente por linha de comando.\n");
}

require __DIR__ . '/../config/conexao.php';

$dryRun = in_array('--dry-run', $argv, true);

$lock = $pdo_master->query("SELECT GET_LOCK('superdunga_manutencao_sincronizacao', 0)")->fetchColumn();
if ((int)$lock !== 1) {
    exit("Outra manutencao de sincronizacao esta em andamento.\n");
}

function excluirEmLotes(PDO $pdo, string $sql, array $params, int $tamanhoLote = 10000): int
{
    $total = 0;
    do {
        $stmt = $pdo->prepare($sql . ' LIMIT ' . $tamanhoLote);
        $stmt->execute($params);
        $removidos = $stmt->rowCount();
        $total += $removidos;
        if ($removidos > 0) {
            usleep(100000);
        }
    } while ($removidos === $tamanhoLote);

    return $total;
}

function tabelaExiste(PDO $pdo, string $tabela): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$tabela]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    if ($dryRun) {
        $consultas = [
            'Logs anteriores a 30 dias' => "SELECT COUNT(*) FROM tesouraria_sincronizacao_log WHERE data_execucao < DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'Snapshots genericos anteriores a 1 dia' => "SELECT COUNT(*) FROM sync_firebird_ativos_temp WHERE criado_em < DATE_SUB(NOW(), INTERVAL 1 DAY)",
            'Snapshots EST008 anteriores a 1 dia' => "SELECT COUNT(*) FROM sync_firebird_est008_temp WHERE criado_em < DATE_SUB(NOW(), INTERVAL 1 DAY)",
        ];
        foreach ($consultas as $rotulo => $sql) {
            try {
                echo $rotulo . ': ' . (int)$pdo_master->query($sql)->fetchColumn() . PHP_EOL;
            } catch (Throwable $e) {
                echo $rotulo . ': tabela indisponivel' . PHP_EOL;
            }
        }
        exit(0);
    }

    $logs = 0;
    if (tabelaExiste($pdo_master, 'tesouraria_sincronizacao_log')) {
        $empresas = $pdo_master->query("SELECT DISTINCT empresa_id FROM tesouraria_sincronizacao_log")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($empresas as $empresaId) {
            $logs += excluirEmLotes(
                $pdo_master,
                'DELETE FROM tesouraria_sincronizacao_log WHERE empresa_id = ? AND data_execucao < DATE_SUB(NOW(), INTERVAL 30 DAY)',
                [(int)$empresaId]
            );
        }
    }

    $snapshots = 0;
    if (tabelaExiste($pdo_master, 'sync_firebird_ativos_temp')) {
        $tabelas = $pdo_master->query("SELECT DISTINCT tabela FROM sync_firebird_ativos_temp")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tabelas as $tabela) {
            $snapshots += excluirEmLotes(
                $pdo_master,
                'DELETE FROM sync_firebird_ativos_temp WHERE tabela = ? AND criado_em < DATE_SUB(NOW(), INTERVAL 1 DAY)',
                [(string)$tabela]
            );
        }
    }
    $snapshotsEst008 = tabelaExiste($pdo_master, 'sync_firebird_est008_temp')
        ? excluirEmLotes(
            $pdo_master,
            'DELETE FROM sync_firebird_est008_temp WHERE criado_em < DATE_SUB(NOW(), INTERVAL 1 DAY)',
            []
        )
        : 0;

    if (tabelaExiste($pdo_master, 'tesouraria_sincronizacao_log')) {
        $stmtIndice = $pdo_master->prepare("
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tesouraria_sincronizacao_log'
              AND INDEX_NAME = 'idx_sync_log_empresa_tabela_status_data'
        ");
        $stmtIndice->execute();
        if ((int)$stmtIndice->fetchColumn() === 0) {
            $pdo_master->exec("
                ALTER TABLE tesouraria_sincronizacao_log
                ADD INDEX idx_sync_log_empresa_tabela_status_data (empresa_id, tabela, status, data_execucao)
            ");
        }
    }

    echo 'Logs removidos: ' . $logs . PHP_EOL;
    echo 'Snapshots genericos removidos: ' . $snapshots . PHP_EOL;
    echo 'Snapshots EST008 removidos: ' . $snapshotsEst008 . PHP_EOL;
} finally {
    $pdo_master->query("SELECT RELEASE_LOCK('superdunga_manutencao_sincronizacao')");
}
