<?php

function whatsappEnsureTables(PDO $pdo): void
{
    static $executado = false;
    if ($executado) {
        return;
    }
    $executado = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_config (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            nome VARCHAR(100) NOT NULL DEFAULT 'Principal',
            token VARCHAR(255) NOT NULL DEFAULT '',
            api_base_url VARCHAR(255) NOT NULL DEFAULT 'https://api-whatsapp.wascript.com.br/api/enviar-texto',
            agendamento_token VARCHAR(64) NULL,
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
            categoria VARCHAR(80) NOT NULL DEFAULT 'Geral',
            titulo VARCHAR(150) NOT NULL,
            descricao VARCHAR(255) NULL,
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
    whatsappEnsureColumn($pdo, 'whatsapp_config', 'empresa_id', 'ALTER TABLE whatsapp_config ADD empresa_id INT NOT NULL DEFAULT 1 AFTER id');
    whatsappEnsureColumn($pdo, 'whatsapp_config', 'agendamento_token', 'ALTER TABLE whatsapp_config ADD agendamento_token VARCHAR(64) NULL AFTER api_base_url');
    whatsappEnsureColumn($pdo, 'whatsapp_destinatarios', 'empresa_id', 'ALTER TABLE whatsapp_destinatarios ADD empresa_id INT NOT NULL DEFAULT 1 AFTER id');
    whatsappEnsureColumn($pdo, 'whatsapp_mensagens', 'empresa_id', 'ALTER TABLE whatsapp_mensagens ADD empresa_id INT NOT NULL DEFAULT 1 AFTER id');
    whatsappEnsureColumn($pdo, 'whatsapp_mensagens', 'categoria', "ALTER TABLE whatsapp_mensagens ADD categoria VARCHAR(80) NOT NULL DEFAULT 'Geral' AFTER id");
    whatsappEnsureColumn($pdo, 'whatsapp_mensagens', 'descricao', 'ALTER TABLE whatsapp_mensagens ADD descricao VARCHAR(255) NULL AFTER titulo');
    whatsappEnsureColumn($pdo, 'whatsapp_rotinas', 'empresa_id', 'ALTER TABLE whatsapp_rotinas ADD empresa_id INT NOT NULL DEFAULT 1 AFTER id');
    whatsappEnsureColumn($pdo, 'whatsapp_envios', 'empresa_id', 'ALTER TABLE whatsapp_envios ADD empresa_id INT NOT NULL DEFAULT 1 AFTER id');
    whatsappEnsureColumn($pdo, 'whatsapp_rotinas', 'origem_mensagem', "ALTER TABLE whatsapp_rotinas ADD origem_mensagem ENUM('TEXTO','SISTEMA') NOT NULL DEFAULT 'TEXTO' AFTER mensagem_id");
    whatsappEnsureColumn($pdo, 'whatsapp_rotinas', 'gerador_sistema', 'ALTER TABLE whatsapp_rotinas ADD gerador_sistema VARCHAR(80) NULL AFTER origem_mensagem');
    whatsappEnsureColumn($pdo, 'whatsapp_rotinas', 'periodicidade', "ALTER TABLE whatsapp_rotinas ADD periodicidade ENUM('MANUAL','DIARIO','SEMANAL','MENSAL') NOT NULL DEFAULT 'MANUAL' AFTER evitar_duplicidade_diaria");
    whatsappEnsureColumn($pdo, 'whatsapp_rotinas', 'horario', 'ALTER TABLE whatsapp_rotinas ADD horario TIME NULL AFTER periodicidade');
    whatsappEnsureColumn($pdo, 'whatsapp_rotinas', 'dias_semana', 'ALTER TABLE whatsapp_rotinas ADD dias_semana VARCHAR(30) NULL AFTER horario');
    whatsappEnsureColumn($pdo, 'whatsapp_rotinas', 'dia_mes', 'ALTER TABLE whatsapp_rotinas ADD dia_mes TINYINT UNSIGNED NULL AFTER dias_semana');
    whatsappEnsureColumn($pdo, 'whatsapp_rotinas', 'proxima_execucao', 'ALTER TABLE whatsapp_rotinas ADD proxima_execucao DATETIME NULL AFTER dia_mes');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_rotinas (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(80) NOT NULL,
            nome VARCHAR(150) NOT NULL,
            descricao TEXT NULL,
            mensagem_id INT UNSIGNED NULL,
            origem_mensagem ENUM('TEXTO','SISTEMA') NOT NULL DEFAULT 'TEXTO',
            gerador_sistema VARCHAR(80) NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            evitar_duplicidade_diaria CHAR(1) NOT NULL DEFAULT 'N',
            periodicidade ENUM('MANUAL','DIARIO','SEMANAL','MENSAL') NOT NULL DEFAULT 'MANUAL',
            horario TIME NULL,
            dias_semana VARCHAR(30) NULL,
            dia_mes TINYINT UNSIGNED NULL,
            proxima_execucao DATETIME NULL,
            ultima_execucao DATETIME NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_whatsapp_rotinas_codigo (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    whatsappEnsureCompanyIndexes($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_rotina_destinatarios (
            rotina_id INT UNSIGNED NOT NULL,
            destinatario_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (rotina_id, destinatario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_rotina_agendamentos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            rotina_id INT UNSIGNED NOT NULL,
            periodicidade ENUM('DIARIO','SEMANAL','MENSAL') NOT NULL DEFAULT 'DIARIO',
            horario TIME NOT NULL,
            dias_semana VARCHAR(30) NULL,
            dia_mes TINYINT UNSIGNED NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            proxima_execucao DATETIME NULL,
            ultima_execucao DATETIME NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_wra_proxima (ativo, proxima_execucao),
            INDEX idx_wra_rotina (rotina_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->query("SELECT COUNT(*) FROM whatsapp_config WHERE empresa_id = 1");
    if ((int)$stmt->fetchColumn() === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO whatsapp_config (id, empresa_id, nome, token, api_base_url, ativo)
            VALUES (1, 1, 'Principal', '', 'https://api-whatsapp.wascript.com.br/api/enviar-texto', 'S')
        ");
        $stmt->execute();
    }

    $stmt = $pdo->query("SELECT agendamento_token FROM whatsapp_config WHERE empresa_id = 1 LIMIT 1");
    if (trim((string)$stmt->fetchColumn()) === '') {
        $token = function_exists('random_bytes') ? bin2hex(random_bytes(24)) : md5(uniqid('', true));
        $stmt = $pdo->prepare("UPDATE whatsapp_config SET agendamento_token = ? WHERE empresa_id = 1");
        $stmt->execute([$token]);
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM whatsapp_destinatarios WHERE empresa_id = 1");
    if ((int)$stmt->fetchColumn() === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO whatsapp_destinatarios (empresa_id, nome, tipo, numero, ativo)
            VALUES (1, 'Grupo Resumo Diario', 'GRUPO', ?, 'S')
        ");
        $stmt->execute(['120363161715233488']);
    }

    $stmt = $pdo->prepare("SELECT id FROM whatsapp_rotinas WHERE empresa_id = 1 AND codigo = 'resumo_diario'");
    $stmt->execute();
    $rotinaId = (int)$stmt->fetchColumn();

    if ($rotinaId <= 0) {
        $stmt = $pdo->prepare("
            INSERT INTO whatsapp_rotinas
                (empresa_id, codigo, nome, descricao, mensagem_id, origem_mensagem, gerador_sistema, ativo, evitar_duplicidade_diaria, periodicidade, horario, proxima_execucao)
            VALUES
                (1, 'resumo_diario', 'Resumo Diario', 'Resumo automatico com vendas, caixa sistema, tesouraria e diferenca.', NULL, 'SISTEMA', 'resumo_diario', 'S', 'S', 'DIARIO', '21:00:00', NULL)
        ");
        $stmt->execute();
        $rotinaId = (int)$pdo->lastInsertId();
        whatsappAtualizarProximaExecucao($pdo, $rotinaId);
    } else {
        $stmt = $pdo->prepare("
            UPDATE whatsapp_rotinas
            SET periodicidade = 'DIARIO',
                horario = COALESCE(horario, '21:00:00'),
                origem_mensagem = 'SISTEMA',
                gerador_sistema = 'resumo_diario',
                mensagem_id = NULL
            WHERE id = ?
              AND codigo = 'resumo_diario'
              AND empresa_id = 1
        ");
        $stmt->execute([$rotinaId]);
        whatsappAtualizarProximaExecucao($pdo, $rotinaId);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_rotina_agendamentos WHERE rotina_id = ?");
    $stmt->execute([$rotinaId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO whatsapp_rotina_agendamentos
                (rotina_id, periodicidade, horario, dias_semana, dia_mes, ativo, proxima_execucao)
            SELECT id, periodicidade, COALESCE(horario, '21:00:00'), dias_semana, dia_mes, 'S', NULL
            FROM whatsapp_rotinas
            WHERE id = ?
              AND periodicidade <> 'MANUAL'
        ");
        $stmt->execute([$rotinaId]);

        $stmt = $pdo->prepare("SELECT id FROM whatsapp_rotina_agendamentos WHERE rotina_id = ?");
        $stmt->execute([$rotinaId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $agendamentoId) {
            whatsappAtualizarProximaAgendamento($pdo, (int)$agendamentoId);
        }
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

function whatsappEnsureCompanyIndexes(PDO $pdo): void
{
    try {
        $pdo->exec("ALTER TABLE whatsapp_config ADD UNIQUE KEY uq_whatsapp_config_empresa (empresa_id)");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE whatsapp_destinatarios ADD INDEX idx_whatsapp_dest_empresa (empresa_id, ativo)");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE whatsapp_mensagens ADD INDEX idx_whatsapp_msg_empresa (empresa_id, ativo)");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE whatsapp_envios ADD INDEX idx_whatsapp_envios_empresa (empresa_id, enviado_em)");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE whatsapp_rotinas DROP INDEX uq_whatsapp_rotinas_codigo");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE whatsapp_rotinas ADD UNIQUE KEY uq_whatsapp_rotinas_empresa_codigo (empresa_id, codigo)");
    } catch (Throwable $e) {
    }
}

function whatsappConfig(PDO $pdo, int $empresaId = 1): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_config WHERE empresa_id = ? LIMIT 1");
    $stmt->execute([$empresaId]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    return $config ?: null;
}

function whatsappGeradoresSistema(): array
{
    return [
        'resumo_diario' => [
            'nome' => 'Resumo diario do caixa',
            'descricao' => 'Calcula vendas, caixa sistema, tesouraria e diferenca do dia.',
            'arquivo' => 'modulos/whatsapp/whatsapp_lib.php',
            'funcao' => 'whatsappMensagemResumoDiario',
        ],
        'acompanhamento_vendas' => [
            'nome' => 'Acompanhamento das Vendas',
            'descricao' => 'Mostra venda do dia anterior, acumulado do mes atual e venda do mes anterior.',
            'arquivo' => 'modulos/whatsapp/whatsapp_lib.php',
            'funcao' => 'whatsappMensagemAcompanhamentoVendas',
        ],
        'conciliacao_tesouraria' => [
            'nome' => 'Conciliacao Tesouraria',
            'descricao' => 'Lista lancamentos de tesouraria e Firebird ainda nao conciliados.',
            'arquivo' => 'modulos/whatsapp/whatsapp_lib.php',
            'funcao' => 'whatsappMensagemConciliacaoTesouraria',
        ],
        'clientes_vencidos' => [
            'nome' => 'Clientes vencidos',
            'descricao' => 'Lista clientes com valores vencidos por faixa de atraso.',
            'arquivo' => 'modulos/whatsapp/whatsapp_lib.php',
            'funcao' => 'whatsappMensagemClientesVencidos',
        ],
    ];
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
    $empresaId = (int)($destinatario['empresa_id'] ?? 1);
    $stmt = $pdo->prepare("
        INSERT INTO whatsapp_envios
            (empresa_id, rotina_id, mensagem_id, destinatario_id, destino_nome, destino_numero, mensagem, status, resposta_api, erro, usuario_id)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $empresaId,
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
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_rotinas WHERE codigo = ? AND empresa_id = ?");
    $stmt->execute([$codigo, (int)($_SESSION['empresa_id'] ?? 1)]);
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
          AND d.empresa_id = (
              SELECT empresa_id FROM whatsapp_rotinas WHERE id = ?
          )
          AND d.ativo = 'S'
        ORDER BY d.tipo, d.nome
    ");
    $stmt->execute([$rotinaId, $rotinaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function whatsappEnviarRotina(PDO $pdo, array $rotina, string $mensagem, ?int $mensagemId, ?int $usuarioId): array
{
    $empresaId = (int)($rotina['empresa_id'] ?? 1);
    $config = whatsappConfig($pdo, $empresaId);
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
    whatsappAtualizarProximaExecucao($pdo, (int)$rotina['id']);

    return ['ok' => $ok, 'falha' => $falha];
}

function whatsappCalcularProximaExecucao(array $rotina, ?DateTime $base = null): ?string
{
    $periodicidade = $rotina['periodicidade'] ?? 'MANUAL';
    if ($periodicidade === 'MANUAL') {
        return null;
    }

    $base = $base ?: new DateTime('now');
    $horario = trim((string)($rotina['horario'] ?? ''));
    if ($horario === '') {
        $horario = '08:00:00';
    }

    if ($periodicidade === 'DIARIO') {
        $proxima = new DateTime($base->format('Y-m-d') . ' ' . $horario);
        if ($proxima <= $base) {
            $proxima->modify('+1 day');
        }
        return $proxima->format('Y-m-d H:i:s');
    }

    if ($periodicidade === 'SEMANAL') {
        $dias = array_filter(array_map('intval', explode(',', (string)($rotina['dias_semana'] ?? ''))));
        if (empty($dias)) {
            $dias = [(int)$base->format('N')];
        }

        for ($i = 0; $i <= 14; $i++) {
            $candidata = clone $base;
            if ($i > 0) {
                $candidata->modify('+' . $i . ' day');
            }

            if (in_array((int)$candidata->format('N'), $dias, true)) {
                $proxima = new DateTime($candidata->format('Y-m-d') . ' ' . $horario);
                if ($proxima > $base) {
                    return $proxima->format('Y-m-d H:i:s');
                }
            }
        }
    }

    if ($periodicidade === 'MENSAL') {
        $diaMes = (int)($rotina['dia_mes'] ?? 1);
        $diaMes = max(1, min(31, $diaMes));

        for ($i = 0; $i <= 13; $i++) {
            $mes = clone $base;
            $mes->modify('first day of +' . $i . ' month');
            $ultimoDia = (int)$mes->format('t');
            $dia = min($diaMes, $ultimoDia);
            $proxima = new DateTime($mes->format('Y-m-') . str_pad((string)$dia, 2, '0', STR_PAD_LEFT) . ' ' . $horario);
            if ($proxima > $base) {
                return $proxima->format('Y-m-d H:i:s');
            }
        }
    }

    return null;
}

function whatsappAtualizarProximaExecucao(PDO $pdo, int $rotinaId): void
{
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_rotinas WHERE id = ?");
    $stmt->execute([$rotinaId]);
    $rotina = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rotina) {
        return;
    }

    $proxima = whatsappCalcularProximaExecucao($rotina);
    $stmt = $pdo->prepare("UPDATE whatsapp_rotinas SET proxima_execucao = ? WHERE id = ?");
    $stmt->execute([$proxima, $rotinaId]);
}

function whatsappAtualizarProximaAgendamento(PDO $pdo, int $agendamentoId): void
{
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_rotina_agendamentos WHERE id = ?");
    $stmt->execute([$agendamentoId]);
    $agendamento = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$agendamento) {
        return;
    }

    $proxima = (($agendamento['ativo'] ?? 'N') === 'S') ? whatsappCalcularProximaExecucao($agendamento) : null;
    $stmt = $pdo->prepare("UPDATE whatsapp_rotina_agendamentos SET proxima_execucao = ? WHERE id = ?");
    $stmt->execute([$proxima, $agendamentoId]);
}

function whatsappMensagemRotina(PDO $pdo, array $rotina): array
{
    $empresaId = (int)($rotina['empresa_id'] ?? 1);

    if (($rotina['origem_mensagem'] ?? 'TEXTO') === 'SISTEMA') {
        $gerador = $rotina['gerador_sistema'] ?? '';

        if ($gerador === 'resumo_diario') {
            return [whatsappMensagemResumoDiario($pdo, $empresaId), null];
        }

        if ($gerador === 'acompanhamento_vendas') {
            return [whatsappMensagemAcompanhamentoVendas($pdo, null, $empresaId), null];
        }

        if ($gerador === 'conciliacao_tesouraria') {
            return [whatsappMensagemConciliacaoTesouraria($pdo, $empresaId), null];
        }

        if ($gerador === 'clientes_vencidos') {
            return [whatsappMensagemClientesVencidos($pdo, null, $empresaId), null];
        }

        throw new Exception('Gerador de mensagem do sistema nao encontrado.');
    }

    $mensagemId = (int)($rotina['mensagem_id'] ?? 0);
    if ($mensagemId <= 0) {
        throw new Exception('Esta rotina nao possui mensagem vinculada.');
    }

    $stmt = $pdo->prepare("SELECT * FROM whatsapp_mensagens WHERE id = ? AND empresa_id = ? AND ativo = 'S'");
    $stmt->execute([$mensagemId, $empresaId]);
    $mensagemRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mensagemRow) {
        throw new Exception('Mensagem ativa da rotina nao encontrada.');
    }

    return [$mensagemRow['conteudo'], $mensagemId];
}

function whatsappExecutarAgendamentos(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            a.id AS agendamento_id,
            r.*
        FROM whatsapp_rotina_agendamentos a
        INNER JOIN whatsapp_rotinas r ON r.id = a.rotina_id
        WHERE r.ativo = 'S'
          AND a.ativo = 'S'
          AND a.proxima_execucao IS NOT NULL
          AND a.proxima_execucao <= NOW()
        ORDER BY a.proxima_execucao ASC, a.id ASC
        LIMIT 10
    ");
    $rotinas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultado = [];
    foreach ($rotinas as $rotina) {
        try {
            list($mensagem, $mensagemId) = whatsappMensagemRotina($pdo, $rotina);
            $envio = whatsappEnviarRotina($pdo, $rotina, $mensagem, $mensagemId, null);
            $resultado[] = [
                'rotina' => $rotina['codigo'],
                'agendamento_id' => (int)$rotina['agendamento_id'],
                'status' => 'OK',
                'ok' => $envio['ok'],
                'falha' => $envio['falha'],
            ];
            $stmt = $pdo->prepare("UPDATE whatsapp_rotina_agendamentos SET ultima_execucao = NOW() WHERE id = ?");
            $stmt->execute([(int)$rotina['agendamento_id']]);
            whatsappAtualizarProximaAgendamento($pdo, (int)$rotina['agendamento_id']);
        } catch (Exception $e) {
            whatsappAtualizarProximaAgendamento($pdo, (int)$rotina['agendamento_id']);
            $resultado[] = [
                'rotina' => $rotina['codigo'],
                'agendamento_id' => (int)$rotina['agendamento_id'],
                'status' => 'ERRO',
                'erro' => $e->getMessage(),
            ];
        }
    }

    return $resultado;
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
          AND EMPRESA = ?
          AND CANCELADO = 'N'
    ");
    $stmt->execute([$inicio, $fim, $empresaId]);
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
                  AND EMPRESA = ?
            ) z ON z.CODCX = b.CBCONTADOR
            WHERE b.DTLANC BETWEEN ? AND ?
              AND b.EMPRESA = ?
              AND COALESCE(b.deletado, 'N') <> 'S'
            GROUP BY
                DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)),
                b.CBCONTADOR
        ) x
    ");
    $stmt->execute([$empresaId, $inicio, $fim, $empresaId]);
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

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(r.valor_bruto), 0)
        FROM armazem_conciliacao_recebimentos r
        INNER JOIN armazem_cr001 c
            ON c.recebimento_id = r.id
        WHERE r.data_venda BETWEEN ? AND ?
          AND r.empresa_id = ?
          AND c.DTLANC BETWEEN ? AND ?
          AND c.EMPRESA = ?
          AND COALESCE(c.excluido_firebird, 'N') = 'N'
    ");
    $stmt->execute([$inicio, $fim, $empresaId, $inicio, $fim, $empresaId]);
    $totalSistemaRecebiveis = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.VLRPARCELA), 0)
        FROM armazem_cr001 c
        INNER JOIN armazem_conciliacao_recebimentos r
            ON r.id = c.recebimento_id
        WHERE c.DTLANC BETWEEN ? AND ?
          AND c.EMPRESA = ?
          AND r.data_venda BETWEEN ? AND ?
          AND r.empresa_id = ?
          AND c.CMCONTADOR <> 9
          AND NOT (c.CMCONTADOR = 1 AND c.STATUS = 'QT')
          AND COALESCE(c.excluido_firebird, 'N') = 'N'
    ");
    $stmt->execute([$inicio, $fim, $empresaId, $inicio, $fim, $empresaId]);
    $totalCR001Recebiveis = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(r.valor_bruto), 0)
        FROM armazem_conciliacao_recebimentos r
        WHERE r.data_venda BETWEEN ? AND ?
          AND r.empresa_id = ?
          AND NOT EXISTS (
              SELECT 1
              FROM armazem_cr001 c
              WHERE c.recebimento_id = r.id
                AND c.EMPRESA = ?
                AND COALESCE(c.excluido_firebird, 'N') = 'N'
          )
    ");
    $stmt->execute([$inicio, $fim, $empresaId, $empresaId]);
    $totalRecebiveisNaoConciliados = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(c.VLRPARCELA), 0)
        FROM armazem_cr001 c
        WHERE c.DTLANC BETWEEN ? AND ?
          AND c.EMPRESA = ?
          AND c.CMCONTADOR <> 9
          AND c.recebimento_id IS NULL
          AND NOT (c.CMCONTADOR = 1 AND c.STATUS = 'QT')
          AND COALESCE(c.excluido_firebird, 'N') = 'N'
    ");
    $stmt->execute([$inicio, $fim, $empresaId]);
    $totalCR001NaoConciliados = (float)$stmt->fetchColumn();

    $diferencaRecebiveis = ($totalSistemaRecebiveis + $totalRecebiveisNaoConciliados) - ($totalCR001Recebiveis + $totalCR001NaoConciliados);

    $stmt = $pdo->prepare("
        SELECT
            v.CLIENTE,
            COALESCE(NULLIF(c.NOME, ''), CONCAT('Cliente ', v.CLIENTE)) AS nome_cliente,
            SUM(v.TOTGERAL) AS total
        FROM armazem_est007 v
        LEFT JOIN armazem_cr002 c
            ON c.CLICONTADOR = v.CLIENTE
           AND c.EMPRESA = v.EMPRESA
        WHERE v.DTLANC BETWEEN ? AND ?
          AND v.EMPRESA = ?
          AND v.CANCELADO = 'N'
          AND v.CMCONTADOR = 9
        GROUP BY v.CLIENTE, nome_cliente
        ORDER BY total DESC
        LIMIT 10
    ");
    $stmt->execute([$inicio, $fim, $empresaId]);
    $maioresClientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT
            i.PRODUTO,
            COALESCE(NULLIF(p.DESCPRODUTO, ''), CONCAT('Produto ', i.PRODUTO)) AS descricao,
            SUM(i.QTDE) AS quantidade
        FROM armazem_est008 i
        INNER JOIN armazem_est007 v
            ON v.VENDACONTADOR = i.ITEMVENDACONTADOR
           AND v.EMPRESA = i.EMPRESA
        LEFT JOIN armazem_est004 p
            ON p.CONTAPRODUTO = i.PRODUTO
           AND p.EMPRESA = i.EMPRESA
        WHERE v.DTLANC BETWEEN ? AND ?
          AND v.EMPRESA = ?
          AND v.CANCELADO = 'N'
          AND COALESCE(i.CANCELADO, 'N') = 'N'
          AND COALESCE(i.excluido_firebird, 'N') = 'N'
        GROUP BY i.PRODUTO, descricao
        ORDER BY quantidade DESC
        LIMIT 10
    ");
    $stmt->execute([$inicio, $fim, $empresaId]);
    $produtosQuantidade = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT
            i.PRODUTO,
            COALESCE(NULLIF(p.DESCPRODUTO, ''), CONCAT('Produto ', i.PRODUTO)) AS descricao,
            SUM(i.TOTPROD) AS total
        FROM armazem_est008 i
        INNER JOIN armazem_est007 v
            ON v.VENDACONTADOR = i.ITEMVENDACONTADOR
           AND v.EMPRESA = i.EMPRESA
        LEFT JOIN armazem_est004 p
            ON p.CONTAPRODUTO = i.PRODUTO
           AND p.EMPRESA = i.EMPRESA
        WHERE v.DTLANC BETWEEN ? AND ?
          AND v.EMPRESA = ?
          AND v.CANCELADO = 'N'
          AND COALESCE(i.CANCELADO, 'N') = 'N'
          AND COALESCE(i.excluido_firebird, 'N') = 'N'
        GROUP BY i.PRODUTO, descricao
        ORDER BY total DESC
        LIMIT 10
    ");
    $stmt->execute([$inicio, $fim, $empresaId]);
    $produtosValor = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $msg = "*Resumo do Dia*\n\n";
    $msg .= date('d/m/Y') . "\n\n";
    $msg .= "Vendas: R$ " . number_format($vendas, 2, ',', '.') . "\n";
    $msg .= "Caixa Sistema: R$ " . number_format($sistema, 2, ',', '.') . "\n";
    $msg .= "Tesouraria: R$ " . number_format($tesouraria, 2, ',', '.') . "\n";
    $msg .= "Diferenca Caixa: R$ " . number_format($diferenca, 2, ',', '.') . "\n";
    $msg .= "Diferenca Recebiveis: R$ " . number_format($diferencaRecebiveis, 2, ',', '.') . "\n\n";
    $msg .= abs((float)$diferenca) < 0.01 && abs((float)$diferencaRecebiveis) < 0.01
        ? "Caixa e recebiveis conferidos"
        : "Ha divergencias para conferir";

    $msg .= "\n\n*10 Maiores clientes do dia*\n";
    if (empty($maioresClientes)) {
        $msg .= "Sem vendas CM 9 no periodo\n";
    } else {
        foreach ($maioresClientes as $idx => $cliente) {
            $msg .= ($idx + 1) . ". " . $cliente['nome_cliente'] . " - R$ " . number_format((float)$cliente['total'], 2, ',', '.') . "\n";
        }
    }

    $msg .= "\n*10 Produtos mais vendidos (qtd)*\n";
    if (empty($produtosQuantidade)) {
        $msg .= "Sem itens vendidos no periodo\n";
    } else {
        foreach ($produtosQuantidade as $idx => $produto) {
            $msg .= ($idx + 1) . ". " . $produto['descricao'] . " - " . number_format((float)$produto['quantidade'], 2, ',', '.') . "\n";
        }
    }

    $msg .= "\n*10 Produtos mais vendidos (valor)*\n";
    if (empty($produtosValor)) {
        $msg .= "Sem itens vendidos no periodo\n";
    } else {
        foreach ($produtosValor as $idx => $produto) {
            $msg .= ($idx + 1) . ". " . $produto['descricao'] . " - R$ " . number_format((float)$produto['total'], 2, ',', '.') . "\n";
        }
    }

    return $msg;
}

function whatsappMensagemAcompanhamentoVendas(PDO $pdo, ?DateTime $base = null, int $empresaId = 1): string
{
    date_default_timezone_set('America/Sao_Paulo');

    $base = $base ?: new DateTime('now');

    $diaAnterior = clone $base;
    $diaAnterior->modify('-1 day');

    $inicioDiaAnterior = $diaAnterior->format('Y-m-d') . ' 07:00:00';
    $fimDiaAnterior = $base->format('Y-m-d') . ' 03:00:00';

    $inicioMesAtual = $base->format('Y-m-01') . ' 00:00:00';
    $fimHoje = $base->format('Y-m-d') . ' 23:59:59';

    $inicioMesAnterior = (clone $base)->modify('first day of previous month')->format('Y-m-d') . ' 00:00:00';
    $fimMesAnterior = (clone $base)->modify('last day of previous month')->format('Y-m-d') . ' 23:59:59';

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(TOTGERAL), 0)
        FROM armazem_est007
        WHERE DTLANC BETWEEN ? AND ?
          AND EMPRESA = ?
          AND CANCELADO = 'N'
    ");

    $stmt->execute([$inicioDiaAnterior, $fimDiaAnterior, $empresaId]);
    $vendaDiaAnterior = (float)$stmt->fetchColumn();

    $stmt->execute([$inicioMesAtual, $fimHoje, $empresaId]);
    $vendaMesAtual = (float)$stmt->fetchColumn();

    $stmt->execute([$inicioMesAnterior, $fimMesAnterior, $empresaId]);
    $vendaMesAnterior = (float)$stmt->fetchColumn();

    $msg = "*Acompanhamento das Vendas*\n\n";
    $msg .= "Data base: " . $base->format('d/m/Y') . "\n\n";
    $msg .= "Venda do dia anterior (" . $diaAnterior->format('d/m/Y') . " 07:00 ate " . $base->format('d/m/Y') . " 03:00):\n";
    $msg .= "R$ " . number_format($vendaDiaAnterior, 2, ',', '.') . "\n\n";
    $msg .= "Venda acumulada do mes atual (" . $base->format('01/m/Y') . " ate " . $base->format('d/m/Y') . "):\n";
    $msg .= "R$ " . number_format($vendaMesAtual, 2, ',', '.') . "\n\n";
    $msg .= "Venda do mes anterior (" . (clone $base)->modify('first day of previous month')->format('m/Y') . "):\n";
    $msg .= "R$ " . number_format($vendaMesAnterior, 2, ',', '.');

    return $msg;
}

function whatsappMensagemClientesVencidos(PDO $pdo, ?DateTime $base = null, int $empresaId = 1): string
{
    date_default_timezone_set('America/Sao_Paulo');

    $base = $base ?: new DateTime('now');
    $hoje = $base->format('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT
            cr.CLICONTADOR,
            COALESCE(NULLIF(cli.NOME, ''), NULLIF(cli.APELIDO, ''), CONCAT('Cliente ', cr.CLICONTADOR)) AS nome_cliente,
            SUM(
                CASE
                    WHEN DATEDIFF(?, DATE(cr.DTVENC)) > 60 THEN cr.VLRRESTANTE
                    ELSE 0
                END
            ) AS acima_60,
            SUM(
                CASE
                    WHEN DATEDIFF(?, DATE(cr.DTVENC)) BETWEEN 31 AND 60 THEN cr.VLRRESTANTE
                    ELSE 0
                END
            ) AS entre_30_60,
            SUM(
                CASE
                    WHEN DATEDIFF(?, DATE(cr.DTVENC)) BETWEEN 1 AND 30 THEN cr.VLRRESTANTE
                    ELSE 0
                END
            ) AS abaixo_30,
            SUM(cr.VLRRESTANTE) AS total
        FROM armazem_cr001 cr
        LEFT JOIN armazem_cr002 cli
            ON cli.CLICONTADOR = cr.CLICONTADOR
           AND cli.EMPRESA = cr.EMPRESA
        WHERE DATE(cr.DTVENC) < ?
          AND cr.EMPRESA = ?
          AND cr.CMCONTADOR = 9
          AND COALESCE(cr.VLRRESTANTE, 0) > 0
          AND COALESCE(cr.excluido_firebird, 'N') = 'N'
          AND (cr.STATUS IS NULL OR cr.STATUS <> 'QT')
        GROUP BY cr.CLICONTADOR, nome_cliente
        HAVING total > 0
        ORDER BY acima_60 DESC, total DESC, nome_cliente ASC
    ");
    $stmt->execute([$hoje, $hoje, $hoje, $hoje, $empresaId]);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN DATEDIFF(?, DATE(DTVENC)) > 60 THEN VLRRESTANTE ELSE 0 END) AS acima_60,
            SUM(CASE WHEN DATEDIFF(?, DATE(DTVENC)) BETWEEN 31 AND 60 THEN VLRRESTANTE ELSE 0 END) AS entre_30_60,
            SUM(CASE WHEN DATEDIFF(?, DATE(DTVENC)) BETWEEN 1 AND 30 THEN VLRRESTANTE ELSE 0 END) AS abaixo_30,
            SUM(VLRRESTANTE) AS total
        FROM armazem_cr001
        WHERE DATE(DTVENC) < ?
          AND EMPRESA = ?
          AND CMCONTADOR = 9
          AND COALESCE(VLRRESTANTE, 0) > 0
          AND COALESCE(excluido_firebird, 'N') = 'N'
          AND (STATUS IS NULL OR STATUS <> 'QT')
    ");
    $stmt->execute([$hoje, $hoje, $hoje, $hoje, $empresaId]);
    $totais = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $arquivoUrl = null;
    $visualizacaoUrl = null;
    if (!empty($clientes)) {
        $pastaRelatorios = realpath(__DIR__ . '/../../uploads');
        if ($pastaRelatorios === false) {
            $pastaRelatorios = __DIR__ . '/../../uploads';
        }

        $pastaRelatorios .= '/relatorios/whatsapp';
        if (!is_dir($pastaRelatorios)) {
            mkdir($pastaRelatorios, 0755, true);
        }

        $baseArquivo = 'clientes_vencidos_' . $base->format('Ymd') . '_' . bin2hex(random_bytes(4));
        $nomeArquivo = $baseArquivo . '.xls';
        $nomeVisualizacao = $baseArquivo . '.html';
        $caminhoArquivo = $pastaRelatorios . '/' . $nomeArquivo;
        $caminhoVisualizacao = $pastaRelatorios . '/' . $nomeVisualizacao;

        $html = "<html><head><meta charset=\"UTF-8\"></head><body>";
        $html .= "<table border=\"1\">";
        $html .= "<thead><tr>";
        $html .= "<th>NOME DO CLIENTE</th>";
        $html .= "<th>Valores com mais de 60 dias de vencido</th>";
        $html .= "<th>Valores entre 30 e 60 dias de vencido</th>";
        $html .= "<th>Valores com menos de 30 dias de vencido</th>";
        $html .= "<th>Valor Total</th>";
        $html .= "</tr></thead><tbody>";

        foreach ($clientes as $cliente) {
            $html .= "<tr>";
            $html .= "<td>" . htmlspecialchars($cliente['nome_cliente'], ENT_QUOTES, 'UTF-8') . "</td>";
            $html .= "<td style=\"mso-number-format:'0,00';\">" . number_format((float)$cliente['acima_60'], 2, ',', '') . "</td>";
            $html .= "<td style=\"mso-number-format:'0,00';\">" . number_format((float)$cliente['entre_30_60'], 2, ',', '') . "</td>";
            $html .= "<td style=\"mso-number-format:'0,00';\">" . number_format((float)$cliente['abaixo_30'], 2, ',', '') . "</td>";
            $html .= "<td style=\"mso-number-format:'0,00';\">" . number_format((float)$cliente['total'], 2, ',', '') . "</td>";
            $html .= "</tr>";
        }

        $html .= "</tbody></table>";
        $html .= "</body></html>";

        if (
            file_put_contents($caminhoArquivo, $html) !== false
            && file_put_contents($caminhoVisualizacao, $html) !== false
        ) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
            $host = $_SERVER['HTTP_HOST'] ?? 'www.superdunga.com.br';
            $arquivoUrl = $scheme . '://' . $host . '/uploads/relatorios/whatsapp/' . rawurlencode($nomeArquivo);
            $visualizacaoUrl = $scheme . '://' . $host . '/uploads/relatorios/whatsapp/' . rawurlencode($nomeVisualizacao);
        }
    }

    $msg = "*Clientes Vencidos*\n\n";
    $msg .= $base->format('d/m/Y') . "\n\n";
    $msg .= "Total >60 dias: R$ " . number_format((float)($totais['acima_60'] ?? 0), 2, ',', '.') . "\n";
    $msg .= "Total 30 a 60 dias: R$ " . number_format((float)($totais['entre_30_60'] ?? 0), 2, ',', '.') . "\n";
    $msg .= "Total <30 dias: R$ " . number_format((float)($totais['abaixo_30'] ?? 0), 2, ',', '.') . "\n";
    $msg .= "Valor Total: R$ " . number_format((float)($totais['total'] ?? 0), 2, ',', '.') . "\n\n";

    if (empty($clientes)) {
        return $msg . "Nenhum cliente com valor vencido.";
    }

    $msg .= "Relatorio completo cliente por cliente em arquivo Excel.\n";
    $msg .= "Clientes no arquivo: " . count($clientes) . "\n";
    $msg .= "Ordenado por maior valor acima de 60 dias.\n";

    if ($arquivoUrl) {
        $msg .= "\nVisualizar: " . $visualizacaoUrl;
        $msg .= "\nExcel: " . $arquivoUrl;
    } else {
        $msg .= "\nNao foi possivel gerar o arquivo do relatorio.";
    }

    return trim($msg);
}

function whatsappMensagemConciliacaoTesouraria(PDO $pdo, int $empresaId = 1): string
{
    date_default_timezone_set('America/Sao_Paulo');

    $limiteListagem = 50;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS qtd, COALESCE(SUM(valor_operacao), 0) AS total
        FROM tesouraria_movimentacoes
        WHERE conciliado = 'N'
          AND empresa_id = ?
          AND tipo_operacao <> 'T'
    ");
    $stmt->execute([$empresaId]);
    $resumoTesouraria = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT id, data_mov, valor_operacao, observacao
        FROM tesouraria_movimentacoes
        WHERE conciliado = 'N'
          AND empresa_id = ?
          AND tipo_operacao <> 'T'
        ORDER BY data_mov DESC, id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $empresaId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limiteListagem, PDO::PARAM_INT);
    $stmt->execute();
    $tesouraria = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS qtd, COALESCE(SUM(f.VALORMOV), 0) AS total
        FROM armazem_bnc001 f
        WHERE f.EMPRESA = ?
          AND f.CBCONTADOR = 8
          AND f.DTMOV > '2026-04-15'
          AND (
              COALESCE(f.deletado, 'N') <> 'S'
              OR f.DTMOV >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          )
          AND CAST(f.VALORMOV AS CHAR) REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'
          AND NOT EXISTS (
              SELECT 1
              FROM tesouraria_movimentacoes tx
              WHERE tx.firebird_id = f.MOVCONTADOR
                AND tx.empresa_id = ?
          )
          AND NOT EXISTS (
              SELECT 1
              FROM tesouraria_firebird_conferidos fc
              WHERE fc.movcontador = f.MOVCONTADOR
                AND fc.empresa_id = ?
          )
    ");
    $stmt->execute([$empresaId, $empresaId, $empresaId]);
    $resumoFirebird = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT f.MOVCONTADOR, f.DTMOV, f.VALORMOV, f.HISTMOV
        FROM armazem_bnc001 f
        WHERE f.EMPRESA = ?
          AND f.CBCONTADOR = 8
          AND f.DTMOV > '2026-04-15'
          AND (
              COALESCE(f.deletado, 'N') <> 'S'
              OR f.DTMOV >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          )
          AND CAST(f.VALORMOV AS CHAR) REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'
          AND NOT EXISTS (
              SELECT 1
              FROM tesouraria_movimentacoes tx
              WHERE tx.firebird_id = f.MOVCONTADOR
                AND tx.empresa_id = ?
          )
          AND NOT EXISTS (
              SELECT 1
              FROM tesouraria_firebird_conferidos fc
              WHERE fc.movcontador = f.MOVCONTADOR
                AND fc.empresa_id = ?
          )
        ORDER BY f.DTMOV DESC, f.MOVCONTADOR DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $empresaId, PDO::PARAM_INT);
    $stmt->bindValue(2, $empresaId, PDO::PARAM_INT);
    $stmt->bindValue(3, $empresaId, PDO::PARAM_INT);
    $stmt->bindValue(4, $limiteListagem, PDO::PARAM_INT);
    $stmt->execute();
    $firebird = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $msg = "*Conciliacao Tesouraria*\n\n";
    $msg .= "Gerado em: " . date('d/m/Y H:i') . "\n\n";

    $msg .= "*Pendentes de Conciliacao*\n";
    $msg .= "Qtde: " . (int)($resumoTesouraria['qtd'] ?? 0) . " | Total: R$ " . number_format((float)($resumoTesouraria['total'] ?? 0), 2, ',', '.') . "\n";
    if (empty($tesouraria)) {
        $msg .= "Nenhum lancamento.\n";
    } else {
        foreach ($tesouraria as $t) {
            $obs = trim((string)($t['observacao'] ?? ''));
            $obs = $obs !== '' ? $obs : '-';
            $msg .= "#" . (int)$t['id'] . " | " . date('d/m/Y H:i', strtotime($t['data_mov'])) . " | R$ " . number_format((float)$t['valor_operacao'], 2, ',', '.') . " | " . $obs . "\n";
        }
        if ((int)($resumoTesouraria['qtd'] ?? 0) > $limiteListagem) {
            $msg .= "... exibindo os primeiros {$limiteListagem} lancamentos.\n";
        }
    }

    $msg .= "\n*Firebird BNC001 nao conciliados*\n";
    $msg .= "Qtde: " . (int)($resumoFirebird['qtd'] ?? 0) . " | Total: R$ " . number_format((float)($resumoFirebird['total'] ?? 0), 2, ',', '.') . "\n";
    if (empty($firebird)) {
        $msg .= "Nenhum lancamento.";
    } else {
        foreach ($firebird as $f) {
            $hist = trim((string)($f['HISTMOV'] ?? ''));
            $hist = $hist !== '' ? $hist : '-';
            $msg .= "#" . (int)$f['MOVCONTADOR'] . " | " . date('d/m/Y H:i', strtotime($f['DTMOV'])) . " | R$ " . number_format((float)$f['VALORMOV'], 2, ',', '.') . " | " . $hist . "\n";
        }
        if ((int)($resumoFirebird['qtd'] ?? 0) > $limiteListagem) {
            $msg .= "... exibindo os primeiros {$limiteListagem} lancamentos.";
        }
    }

    return $msg;
}
