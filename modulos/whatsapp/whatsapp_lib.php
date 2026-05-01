<?php

function whatsappEnsureTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_config (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            nome VARCHAR(100) NOT NULL DEFAULT 'Principal',
            token VARCHAR(255) NOT NULL DEFAULT '',
            api_base_url VARCHAR(255) NOT NULL DEFAULT 'https://api-whatsapp.wascript.com.br/api/enviar-texto',
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_destinatarios (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            tipo ENUM('PESSOA','GRUPO') NOT NULL DEFAULT 'PESSOA',
            numero VARCHAR(80) NOT NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_mensagens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(150) NOT NULL,
            conteudo TEXT NOT NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_envios (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            rotina_id INT UNSIGNED NULL,
            mensagem_id INT UNSIGNED NULL,
            destinatario_id INT UNSIGNED NULL,
            destino_nome VARCHAR(120) NOT NULL,
            destino_numero VARCHAR(80) NOT NULL,
            mensagem TEXT NOT NULL,
            status ENUM('OK','ERRO') NOT NULL,
            resposta_api TEXT NULL,
            erro TEXT NULL,
            usuario_id INT NULL,
            enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_whatsapp_envios_enviado_em (enviado_em),
            INDEX idx_whatsapp_envios_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    whatsappEnsureColumn($pdo, 'whatsapp_envios', 'rotina_id', 'ALTER TABLE whatsapp_envios ADD rotina_id INT UNSIGNED NULL AFTER id');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_rotinas (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(80) NOT NULL,
            nome VARCHAR(150) NOT NULL,
            descricao TEXT NULL,
            mensagem_id INT UNSIGNED NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            evitar_duplicidade_diaria CHAR(1) NOT NULL DEFAULT 'N',
            ultima_execucao DATETIME NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_whatsapp_rotinas_codigo (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_rotina_destinatarios (
            rotina_id INT UNSIGNED NOT NULL,
            destinatario_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (rotina_id, destinatario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->query("SELECT COUNT(*) FROM whatsapp_config");
    if ((int)$stmt->fetchColumn() === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO whatsapp_config (id, nome, token, api_base_url, ativo)
            VALUES (1, 'Principal', '', 'https://api-whatsapp.wascript.com.br/api/enviar-texto', 'S')
        ");
        $stmt->execute();
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM whatsapp_destinatarios");
    if ((int)$stmt->fetchColumn() === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO whatsapp_destinatarios (nome, tipo, numero, ativo)
            VALUES ('Grupo Resumo Diario', 'GRUPO', ?, 'S')
        ");
        $stmt->execute(['120363161715233488']);
    }

    $stmt = $pdo->prepare("SELECT id FROM whatsapp_rotinas WHERE codigo = 'resumo_diario'");
    $stmt->execute();
    $rotinaId = (int)$stmt->fetchColumn();

    if ($rotinaId <= 0) {
        $stmt = $pdo->prepare("
            INSERT INTO whatsapp_rotinas
                (codigo, nome, descricao, ativo, evitar_duplicidade_diaria)
            VALUES
                ('resumo_diario', 'Resumo Diario', 'Resumo automatico com vendas, caixa sistema, tesouraria e diferenca.', 'S', 'S')
        ");
        $stmt->execute();
        $rotinaId = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_rotina_destinatarios WHERE rotina_id = ?");
    $stmt->execute([$rotinaId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $destinatarioId = (int)$pdo->query("SELECT id FROM whatsapp_destinatarios ORDER BY id LIMIT 1")->fetchColumn();
        if ($destinatarioId > 0) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO whatsapp_rotina_destinatarios (rotina_id, destinatario_id) VALUES (?, ?)");
            $stmt->execute([$rotinaId, $destinatarioId]);
        }
    }
}

function whatsappEnsureColumn(PDO $pdo, string $table, string $column, string $alterSql): void
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec($alterSql);
    }
}

function whatsappConfig(PDO $pdo): ?array
{
    $stmt = $pdo->query("SELECT * FROM whatsapp_config WHERE id = 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    return $config ?: null;
}

function whatsappSend(PDO $pdo, array $config, array $destinatario, string $mensagem, ?int $mensagemId, ?int $usuarioId, ?int $rotinaId = null): array
{
    $token = trim($config['token'] ?? '');
    $apiBase = rtrim(trim($config['api_base_url'] ?? ''), '/');
    $numero = trim($destinatario['numero'] ?? '');
    $nome = trim($destinatario['nome'] ?? '');

    if ($token === '' || $apiBase === '') {
        return whatsappRegisterSend($pdo, $mensagemId, $destinatario, $mensagem, 'ERRO', null, 'Token ou URL da API nao configurado.', $usuarioId, $rotinaId);
    }

    if ($numero === '') {
        return whatsappRegisterSend($pdo, $mensagemId, $destinatario, $mensagem, 'ERRO', null, 'Numero do destinatario vazio.', $usuarioId, $rotinaId);
    }

    $url = $apiBase . '/' . rawurlencode($token);
    $payload = [
        'phone' => $numero,
        'message' => $mensagem,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return whatsappRegisterSend($pdo, $mensagemId, $destinatario, $mensagem, 'ERRO', null, $curlError ?: 'Erro desconhecido no cURL.', $usuarioId, $rotinaId);
    }

    $decoded = json_decode($response, true);
    $apiSuccess = is_array($decoded) && array_key_exists('success', $decoded) ? (bool)$decoded['success'] : ($httpCode >= 200 && $httpCode < 300);
    $status = $apiSuccess ? 'OK' : 'ERRO';
    $erro = $apiSuccess ? null : ('HTTP ' . $httpCode . ' - ' . $response);

    return whatsappRegisterSend($pdo, $mensagemId, $destinatario, $mensagem, $status, $response, $erro, $usuarioId, $rotinaId);
}

function whatsappRegisterSend(PDO $pdo, ?int $mensagemId, array $destinatario, string $mensagem, string $status, ?string $resposta, ?string $erro, ?int $usuarioId, ?int $rotinaId = null): array
{
    $stmt = $pdo->prepare("
        INSERT INTO whatsapp_envios
            (rotina_id, mensagem_id, destinatario_id, destino_nome, destino_numero, mensagem, status, resposta_api, erro, usuario_id)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $rotinaId,
        $mensagemId,
        $destinatario['id'] ?? null,
        $destinatario['nome'] ?? '',
        $destinatario['numero'] ?? '',
        $mensagem,
        $status,
        $resposta,
        $erro,
        $usuarioId,
    ]);

    return [
        'status' => $status,
        'destino' => $destinatario['nome'] ?? '',
        'erro' => $erro,
        'envio_id' => (int)$pdo->lastInsertId(),
    ];
}

function whatsappRotinaPorCodigo(PDO $pdo, string $codigo): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_rotinas WHERE codigo = ?");
    $stmt->execute([$codigo]);
    $rotina = $stmt->fetch(PDO::FETCH_ASSOC);
    return $rotina ?: null;
}

function whatsappDestinatariosRotina(PDO $pdo, int $rotinaId): array
{
    $stmt = $pdo->prepare("
        SELECT d.*
        FROM whatsapp_rotina_destinatarios rd
        INNER JOIN whatsapp_destinatarios d ON d.id = rd.destinatario_id
        WHERE rd.rotina_id = ?
          AND d.ativo = 'S'
        ORDER BY d.tipo, d.nome
    ");
    $stmt->execute([$rotinaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function whatsappEnviarRotina(PDO $pdo, array $rotina, string $mensagem, ?int $mensagemId, ?int $usuarioId): array
{
    $config = whatsappConfig($pdo);
    if (!$config || $config['ativo'] !== 'S') {
        throw new Exception('Configuracao do WhatsApp inativa.');
    }

    if (($rotina['ativo'] ?? 'N') !== 'S') {
        throw new Exception('Rotina inativa.');
    }

    $destinatarios = whatsappDestinatariosRotina($pdo, (int)$rotina['id']);
    if (empty($destinatarios)) {
        throw new Exception('Nenhum destinatario ativo vinculado a rotina.');
    }

    $ok = 0;
    $falha = 0;
    foreach ($destinatarios as $destinatario) {
        $resultado = whatsappSend($pdo, $config, $destinatario, $mensagem, $mensagemId, $usuarioId, (int)$rotina['id']);
        if ($resultado['status'] === 'OK') {
            $ok++;
        } else {
            $falha++;
        }
    }

    $stmt = $pdo->prepare("UPDATE whatsapp_rotinas SET ultima_execucao = NOW() WHERE id = ?");
    $stmt->execute([(int)$rotina['id']]);

    return ['ok' => $ok, 'falha' => $falha];
}

function whatsappMensagemResumoDiario(PDO $pdo, int $empresaId = 1): string
{
    date_default_timezone_set('America/Sao_Paulo');

    $dataBase = date('Y-m-d');
    $inicio = $dataBase . ' 07:00:00';
    $fim = date('Y-m-d 03:00:00', strtotime($dataBase . ' +1 day'));

    $stmt = $pdo->prepare("
        SELECT SUM(TOTGERAL)
        FROM armazem_est007
        WHERE DTLANC BETWEEN ? AND ?
          AND CANCELADO = 'N'
    ");
    $stmt->execute([$inicio, $fim]);
    $vendas = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("
        SELECT SUM(saldo_final) FROM (
            SELECT
                DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)) AS data_operacional,
                b.CBCONTADOR,
                SUM(
                    CASE
                        WHEN b.TIPOMOV = 'C' THEN b.VALORMOV
                        WHEN b.TIPOMOV = 'D' THEN -b.VALORMOV
                        ELSE 0
                    END
                ) AS saldo_final
            FROM armazem_bnc001 b
            INNER JOIN (
                SELECT DISTINCT CODCX
                FROM armazem_zconfig005
                WHERE CODCX IS NOT NULL
            ) z ON z.CODCX = b.CBCONTADOR
            LEFT JOIN armazem_bnc001_ids_temp t
                ON t.MOVCONTADOR = b.MOVCONTADOR
            WHERE b.DTLANC BETWEEN ? AND ?
            GROUP BY
                DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)),
                b.CBCONTADOR
        ) x
    ");
    $stmt->execute([$inicio, $fim]);
    $sistema = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("
        SELECT SUM(
            CASE
                WHEN d.tipo = 'entrada' THEN d.quantidade * d.valor_unitario
                WHEN d.tipo = 'saida' THEN - (d.quantidade * d.valor_unitario)
                ELSE 0
            END
        )
        FROM tesouraria_movimentacoes_detalhes d
        INNER JOIN tesouraria_movimentacoes m
            ON m.id = d.movimentacao_id
        WHERE m.data_mov >= ?
          AND m.data_mov < ?
          AND m.empresa_id = ?
    ");
    $stmt->execute([$inicio, $fim, $empresaId]);
    $tesouraria = $stmt->fetchColumn() ?: 0;

    $diferenca = $sistema - $tesouraria;

    $msg = "*Resumo do Dia*\n\n";
    $msg .= date('d/m/Y') . "\n\n";
    $msg .= "Vendas: R$ " . number_format($vendas, 2, ',', '.') . "\n";
    $msg .= "Caixa Sistema: R$ " . number_format($sistema, 2, ',', '.') . "\n";
    $msg .= "Tesouraria: R$ " . number_format($tesouraria, 2, ',', '.') . "\n";
    $msg .= "Diferenca: R$ " . number_format($diferenca, 2, ',', '.') . "\n\n";
    $msg .= abs((float)$diferenca) < 0.01 ? "Caixa conferido" : "Divergencia no caixa";

    return $msg;
}
