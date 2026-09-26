<?php
declare(strict_types=1);

require __DIR__ . '/../../config/conexao.php';
header('Content-Type: application/json; charset=utf-8');

function responderAuxiliar(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderAuxiliar(405, ['erro' => 'Metodo nao permitido']);
}

try {
    $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Corpo JSON invalido');
    $source = (string)($input['source'] ?? '');
    $fbCompany = (int)($input['firebird_company'] ?? 0);
    $company = (int)($input['company'] ?? 0);
    $allowed = ['armazem:1:1' => true, 'emporio:1:4' => true, 'emporio:6:5' => true];
    if (!isset($allowed["$source:$fbCompany:$company"])) {
        throw new InvalidArgumentException('Mapeamento de empresa invalido');
    }
    $table = (string)($input['table'] ?? '');
    $tables = [
        'est007_auxiliar' => ['name' => 'armazem_est007_auxiliar', 'key' => 'VENDACONTADOR'],
        'est026_auxiliar' => ['name' => 'armazem_est026_auxiliar', 'key' => 'NFCONTADOR'],
    ];
    if (!isset($tables[$table])) {
        throw new InvalidArgumentException('Tabela auxiliar invalida');
    }
    $token = (string)($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
        responderAuxiliar(401, ['erro' => 'Nao autorizado']);
    }
    $auth = $pdo_master->prepare('SELECT token_sha256 FROM auxiliar_sync_tokens WHERE source = ? AND active = 1');
    $auth->execute([$source]);
    $expectedHash = $auth->fetchColumn();
    if (!$expectedHash) {
        responderAuxiliar(503, ['erro' => 'Sincronizacao auxiliar nao configurada']);
    }
    if (!hash_equals(strtolower((string)$expectedHash), hash('sha256', $token))) {
        responderAuxiliar(401, ['erro' => 'Nao autorizado']);
    }
    $tableName = $tables[$table]['name'];
    $keyColumn = $tables[$table]['key'];
    $action = (string)($input['action'] ?? '');
    if ($action === 'ping') {
        responderAuxiliar(200, ['status' => 'ok', 'source' => $source, 'table' => $table, 'company' => $company]);
    }
    $syncId = (string)($input['sync_id'] ?? '');
    if (!preg_match('/^[a-f0-9-]{36}$/D', $syncId)) {
        throw new InvalidArgumentException('sync_id invalido');
    }

    if ($action === 'start') {
        $schema = $input['schema'] ?? null;
        if (!is_array($schema) || !array_is_list($schema) || count($schema) < 2 || count($schema) > 500) {
            throw new InvalidArgumentException('Esquema auxiliar invalido');
        }
        $columns = [];
        foreach ($schema as $field) {
            $name = (string)($field['name'] ?? '');
            $type = (string)($field['type'] ?? '');
            if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $name) || isset($columns[$name]) ||
                !preg_match('/^(?:SMALLINT|INT|BIGINT|DOUBLE|TINYINT|DATE|TIME|DATETIME|TEXT|LONGTEXT|DECIMAL\([1-9][0-9]?,[0-9]{1,2}\))$/D', $type)) {
                throw new InvalidArgumentException('Campo ou tipo invalido no esquema auxiliar');
            }
            $columns[$name] = $type;
        }
        if (($columns['EMPRESA'] ?? '') !== 'INT' && ($columns['EMPRESA'] ?? '') !== 'SMALLINT') {
            throw new InvalidArgumentException('EMPRESA deve ser numerica');
        }
        if (!in_array($columns[$keyColumn] ?? '', ['INT', 'BIGINT'], true)) {
            throw new InvalidArgumentException("$keyColumn deve ser numerico");
        }
        $metadata = ['empresa_firebird_origem', 'sync_id', 'excluido_firebird', 'data_exclusao_firebird', 'motivo_sync', 'ultima_presenca_firebird'];
        foreach ($columns as $name => $_type) {
            if (in_array(strtolower($name), $metadata, true)) {
                throw new InvalidArgumentException('Nome de campo reservado no esquema auxiliar');
            }
        }
        $definitions = [];
        foreach ($columns as $name => $type) $definitions[] = "`$name` $type NULL";
        $definitions[] = 'empresa_firebird_origem INT NOT NULL';
        $definitions[] = 'sync_id CHAR(36) NULL';
        $definitions[] = "excluido_firebird CHAR(1) NOT NULL DEFAULT 'N'";
        $definitions[] = 'data_exclusao_firebird DATETIME NULL';
        $definitions[] = 'motivo_sync VARCHAR(100) NULL';
        $definitions[] = 'ultima_presenca_firebird DATETIME NULL';
        $definitions[] = "UNIQUE KEY uniq_auxiliar (EMPRESA, $keyColumn)";
        $definitions[] = 'KEY idx_auxiliar_sync (EMPRESA, sync_id)';
        $pdo_master->exec("CREATE TABLE IF NOT EXISTS $tableName (" . implode(',', $definitions) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC');
        $present = $pdo_master->query("SHOW COLUMNS FROM $tableName")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columns as $name => $type) {
            if (!in_array($name, $present, true)) {
                $pdo_master->exec("ALTER TABLE $tableName ADD COLUMN `$name` $type NULL");
            }
        }
        $pdo_master->exec('CREATE TABLE IF NOT EXISTS auxiliar_sync (tabela VARCHAR(20) NOT NULL, empresa INT NOT NULL, sync_id CHAR(36) NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (tabela, empresa)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo_master->beginTransaction();
        $pdo_master->prepare('INSERT INTO auxiliar_sync (tabela, empresa, sync_id, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE sync_id=VALUES(sync_id), updated_at=NOW()')
            ->execute([$table, $company, $syncId]);
        $pdo_master->commit();
        responderAuxiliar(200, ['status' => 'ok', 'action' => 'start']);
    }

    $pdo_master->beginTransaction();
    $lock = $pdo_master->prepare('SELECT sync_id FROM auxiliar_sync WHERE tabela = ? AND empresa = ? FOR UPDATE');
    $lock->execute([$table, $company]);
    if ($lock->fetchColumn() !== $syncId) {
        throw new RuntimeException('Snapshot nao iniciado ou substituido por outro');
    }
    if ($action === 'batch') {
        $rows = $input['rows'] ?? null;
        if (!is_array($rows) || !$rows || count($rows) > 1000 || !array_is_list($rows)) {
            throw new InvalidArgumentException('Lote deve conter de 1 a 1000 registros');
        }
        $columns = $pdo_master->query("SHOW COLUMNS FROM $tableName")->fetchAll(PDO::FETCH_COLUMN);
        $excluded = ['empresa_firebird_origem', 'sync_id', 'excluido_firebird', 'data_exclusao_firebird', 'motivo_sync', 'ultima_presenca_firebird'];
        $dataColumns = array_values(array_diff($columns, $excluded));
        $writeColumns = array_merge($dataColumns, ['empresa_firebird_origem', 'sync_id', 'excluido_firebird', 'data_exclusao_firebird', 'motivo_sync', 'ultima_presenca_firebird']);
        $quoted = implode(',', array_map(static fn($c) => "`$c`", $writeColumns));
        $placeholders = implode(',', array_fill(0, count($writeColumns), '?'));
        $updates = implode(',', array_map(static fn($c) => "`$c`=VALUES(`$c`)", array_diff($writeColumns, ['EMPRESA', $keyColumn])));
        $stmt = $pdo_master->prepare("INSERT INTO $tableName ($quoted) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates");
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int)($row['EMPRESA'] ?? 0) !== $fbCompany || (int)($row[$keyColumn] ?? 0) <= 0) {
                throw new InvalidArgumentException('Registro auxiliar de outra empresa ou sem chave');
            }
            $key = (int)$row[$keyColumn];
            if (isset($seen[$key])) throw new InvalidArgumentException('Chave duplicada no lote');
            $seen[$key] = true;
            $values = [];
            foreach ($dataColumns as $column) {
                $values[] = $column === 'EMPRESA' ? $company : ($row[$column] ?? null);
            }
            array_push($values, $fbCompany, $syncId, 'N', null, null, date('Y-m-d H:i:s'));
            $stmt->execute($values);
        }
        $pdo_master->prepare('UPDATE auxiliar_sync SET updated_at=NOW() WHERE tabela=? AND empresa=?')->execute([$table, $company]);
        $pdo_master->commit();
        responderAuxiliar(200, ['status' => 'ok', 'received' => count($rows)]);
    }
    if ($action === 'finish') {
        $expected = (int)($input['expected'] ?? -1);
        if ($expected <= 0) throw new InvalidArgumentException('Snapshot vazio nao pode marcar registros ausentes');
        $count = $pdo_master->prepare("SELECT COUNT(*) FROM $tableName WHERE EMPRESA=? AND sync_id=?");
        $count->execute([$company, $syncId]);
        $actual = (int)$count->fetchColumn();
        if ($actual !== $expected) throw new RuntimeException("Snapshot incompleto: esperado $expected, recebido $actual");
        $delete = $pdo_master->prepare("UPDATE $tableName SET excluido_firebird='S', data_exclusao_firebird=NOW(), motivo_sync='AUSENTE_AUXILIAR' WHERE EMPRESA=? AND (sync_id IS NULL OR sync_id<>?) AND excluido_firebird<>'S'");
        $delete->execute([$company, $syncId]);
        $deleted = $delete->rowCount();
        $pdo_master->prepare('DELETE FROM auxiliar_sync WHERE tabela=? AND empresa=? AND sync_id=?')->execute([$table, $company, $syncId]);
        $pdo_master->commit();
        responderAuxiliar(200, ['status' => 'ok', 'received' => $actual, 'marked_absent' => $deleted]);
    }
    throw new InvalidArgumentException('Acao invalida');
} catch (Throwable $error) {
    if ($pdo_master->inTransaction()) $pdo_master->rollBack();
    $status = $error instanceof InvalidArgumentException ? 422 : ($error instanceof RuntimeException ? 409 : 500);
    error_log('Firebird auxiliar: ' . $error->getMessage());
    responderAuxiliar($status, ['erro' => $status === 500 ? 'Falha interna na sincronizacao auxiliar' : $error->getMessage()]);
}
