<?php

function whatsappOperacionalEnsureTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_operacional_config (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL DEFAULT 'Operacional',
            evolution_token VARCHAR(255) NOT NULL DEFAULT '',
            evolution_api_base_url VARCHAR(255) NOT NULL DEFAULT '',
            instancia VARCHAR(120) NOT NULL DEFAULT '',
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_whatsapp_operacional_empresa (empresa_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_operacional_destinatarios (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            nome VARCHAR(120) NOT NULL,
            tipo ENUM('PESSOA','GRUPO') NOT NULL DEFAULT 'PESSOA',
            numero VARCHAR(80) NOT NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_wo_dest_empresa (empresa_id, ativo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_operacional_envios (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            destinatario_id INT UNSIGNED NULL,
            destino_nome VARCHAR(120) NOT NULL,
            destino_numero VARCHAR(80) NOT NULL,
            mensagem TEXT NOT NULL,
            status ENUM('OK','ERRO') NOT NULL,
            resposta_api TEXT NULL,
            erro TEXT NULL,
            usuario_id INT NULL,
            enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wo_envios_empresa (empresa_id, enviado_em),
            INDEX idx_wo_envios_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        INSERT IGNORE INTO whatsapp_operacional_config
            (empresa_id, nome, evolution_token, evolution_api_base_url, instancia, ativo)
        SELECT 1, 'Operacional - Empresa 1', evolution_token, evolution_api_base_url, instancia, ativo
        FROM whatsapp_config
        WHERE empresa_id = 1
          AND instancia = 'Superdunga_Armazem'
        LIMIT 1
    ");
}

function whatsappOperacionalConfig(PDO $pdo, int $empresaId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_operacional_config WHERE empresa_id = ? LIMIT 1");
    $stmt->execute([$empresaId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function whatsappOperacionalTestar(array $config): array
{
    $url = rtrim(trim((string)($config['evolution_api_base_url'] ?? '')), '/');
    $token = trim((string)($config['evolution_token'] ?? ''));
    $instancia = trim((string)($config['instancia'] ?? ''));
    if ($url === '' || $token === '' || $instancia === '') {
        throw new Exception('Informe URL, API Key e instancia da Evolution.');
    }

    $ch = curl_init($url . '/instance/connectionState/' . rawurlencode($instancia));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['apikey: ' . $token],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resposta = curl_exec($ch);
    $erro = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resposta === false || $http < 200 || $http >= 300) {
        throw new Exception($resposta === false ? 'Falha de conexao: ' . $erro : 'Evolution respondeu HTTP ' . $http . '.');
    }
    $json = json_decode($resposta, true);
    $estado = strtolower((string)($json['instance']['state'] ?? $json['state'] ?? ''));
    return ['conectado' => in_array($estado, ['open', 'connected'], true), 'estado' => $estado ?: 'desconhecido'];
}

function whatsappOperacionalEnviar(PDO $pdo, array $config, array $destinatario, string $mensagem, ?int $usuarioId): array
{
    if (($config['ativo'] ?? 'N') !== 'S') {
        throw new Exception('A instancia operacional desta empresa esta inativa.');
    }
    $url = rtrim(trim((string)$config['evolution_api_base_url']), '/');
    $token = trim((string)$config['evolution_token']);
    $instancia = trim((string)$config['instancia']);
    $numero = trim((string)$destinatario['numero']);
    if ($url === '' || $token === '' || $instancia === '') {
        throw new Exception('A configuracao operacional esta incompleta.');
    }
    if (($destinatario['tipo'] ?? 'PESSOA') === 'GRUPO') {
        $numero = preg_replace('/\D+/', '', preg_replace('/@g\.us$/i', '', $numero)) . '@g.us';
    }

    $ch = curl_init($url . '/message/sendText/' . rawurlencode($instancia));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['number' => $numero, 'text' => $mensagem], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . $token],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resposta = curl_exec($ch);
    $erroCurl = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = $resposta !== false && $http >= 200 && $http < 300;
    $erro = $ok ? null : ($resposta === false ? $erroCurl : 'HTTP ' . $http . ' - ' . $resposta);

    $stmt = $pdo->prepare("
        INSERT INTO whatsapp_operacional_envios
            (empresa_id, destinatario_id, destino_nome, destino_numero, mensagem, status, resposta_api, erro, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int)$destinatario['empresa_id'], (int)$destinatario['id'], $destinatario['nome'], $destinatario['numero'],
        $mensagem, $ok ? 'OK' : 'ERRO', $resposta === false ? null : $resposta, $erro, $usuarioId,
    ]);
    return ['ok' => $ok, 'erro' => $erro];
}
