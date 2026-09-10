<?php

date_default_timezone_set('America/Sao_Paulo');

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
        CREATE TABLE IF NOT EXISTS whatsapp_operacional_fechamentos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            clicontador INT NOT NULL,
            data_inicio DATE NOT NULL,
            data_fim DATE NOT NULL,
            quantidade_titulos INT NOT NULL DEFAULT 0,
            valor_aberto DECIMAL(15,2) NOT NULL DEFAULT 0,
            arquivo VARCHAR(255) NULL,
            status ENUM('OK','ERRO') NOT NULL,
            resposta_api TEXT NULL,
            erro TEXT NULL,
            usuario_id INT NULL,
            enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wo_fech_empresa_periodo (empresa_id, data_inicio, data_fim),
            INDEX idx_wo_fech_cliente (empresa_id, clicontador, enviado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    whatsappOperacionalEnsureColumn($pdo, 'whatsapp_operacional_config', 'fechamento_ativo', "ALTER TABLE whatsapp_operacional_config ADD fechamento_ativo CHAR(1) NOT NULL DEFAULT 'N' AFTER ativo");
    whatsappOperacionalEnsureColumn($pdo, 'whatsapp_operacional_config', 'fechamento_dia_util', "ALTER TABLE whatsapp_operacional_config ADD fechamento_dia_util TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER fechamento_ativo");
    whatsappOperacionalEnsureColumn($pdo, 'whatsapp_operacional_config', 'fechamento_horario', "ALTER TABLE whatsapp_operacional_config ADD fechamento_horario TIME NOT NULL DEFAULT '10:00:00' AFTER fechamento_dia_util");
    whatsappOperacionalEnsureColumn($pdo, 'whatsapp_operacional_config', 'fechamento_intervalo_segundos', "ALTER TABLE whatsapp_operacional_config ADD fechamento_intervalo_segundos SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER fechamento_horario");
    whatsappOperacionalEnsureColumn($pdo, 'whatsapp_operacional_config', 'fechamento_proxima_data', "ALTER TABLE whatsapp_operacional_config ADD fechamento_proxima_data DATE NULL AFTER fechamento_intervalo_segundos");
    whatsappOperacionalEnsureColumn($pdo, 'whatsapp_operacional_config', 'fechamento_ultima_competencia', "ALTER TABLE whatsapp_operacional_config ADD fechamento_ultima_competencia DATE NULL AFTER fechamento_proxima_data");
    whatsappOperacionalEnsureColumn($pdo, 'whatsapp_operacional_config', 'webhook_token', "ALTER TABLE whatsapp_operacional_config ADD webhook_token VARCHAR(64) NULL AFTER instancia");
    whatsappOperacionalEnsureColumn($pdo, 'whatsapp_operacional_config', 'webhook_configurado_em', "ALTER TABLE whatsapp_operacional_config ADD webhook_configurado_em DATETIME NULL AFTER webhook_token");

    $configsSemToken = $pdo->query("SELECT id FROM whatsapp_operacional_config WHERE webhook_token IS NULL OR webhook_token='' ")->fetchAll(PDO::FETCH_COLUMN);
    $atualizarToken = $pdo->prepare("UPDATE whatsapp_operacional_config SET webhook_token=? WHERE id=? AND (webhook_token IS NULL OR webhook_token='')");
    foreach ($configsSemToken as $configId) {
        $atualizarToken->execute([bin2hex(random_bytes(24)), (int)$configId]);
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_operacional_fila_fechamentos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            competencia DATE NOT NULL,
            data_limite DATE NOT NULL,
            clicontador INT NOT NULL,
            telefone VARCHAR(40) NULL,
            status ENUM('AGUARDANDO','PROCESSANDO','ENVIADO','ERRO','IGNORADO','CANCELADO') NOT NULL DEFAULT 'AGUARDANDO',
            tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
            proxima_tentativa DATETIME NULL,
            quantidade_titulos INT NOT NULL DEFAULT 0,
            valor_aberto DECIMAL(15,2) NOT NULL DEFAULT 0,
            arquivo VARCHAR(255) NULL,
            resposta_api TEXT NULL,
            erro TEXT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            iniciado_em DATETIME NULL,
            enviado_em DATETIME NULL,
            atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wo_fila_competencia_cliente (empresa_id, competencia, clicontador),
            INDEX idx_wo_fila_processamento (status, proxima_tentativa),
            INDEX idx_wo_fila_empresa_competencia (empresa_id, competencia, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_operacional_fila (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            rotina_codigo VARCHAR(80) NOT NULL,
            rotina_nome VARCHAR(150) NOT NULL,
            referencia VARCHAR(40) NOT NULL,
            competencia DATE NULL,
            data_limite DATE NULL,
            destinatario_id VARCHAR(80) NOT NULL,
            destinatario_nome VARCHAR(180) NOT NULL,
            telefone VARCHAR(80) NULL,
            payload_json LONGTEXT NULL,
            status ENUM('AGUARDANDO','PROCESSANDO','ACEITO','ENTREGUE','ERRO','IGNORADO','CANCELADO') NOT NULL DEFAULT 'AGUARDANDO',
            tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
            proxima_tentativa DATETIME NULL,
            quantidade_itens INT NOT NULL DEFAULT 0,
            valor DECIMAL(15,2) NOT NULL DEFAULT 0,
            arquivo VARCHAR(255) NULL,
            mensagem_id VARCHAR(120) NULL,
            resposta_api TEXT NULL,
            erro TEXT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            iniciado_em DATETIME NULL,
            aceito_em DATETIME NULL,
            entregue_em DATETIME NULL,
            atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wo_fila_rotina_referencia_destino (empresa_id,rotina_codigo,referencia,destinatario_id),
            INDEX idx_wo_fila_processamento (status,proxima_tentativa),
            INDEX idx_wo_fila_empresa_rotina (empresa_id,rotina_codigo,referencia,status),
            INDEX idx_wo_fila_mensagem (empresa_id,mensagem_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_operacional_webhook_eventos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            instancia VARCHAR(120) NOT NULL,
            evento VARCHAR(80) NOT NULL,
            mensagem_id VARCHAR(120) NULL,
            status_recebido VARCHAR(40) NULL,
            fila_id BIGINT UNSIGNED NULL,
            resultado VARCHAR(40) NOT NULL,
            recebido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wo_webhook_empresa_data (empresa_id,recebido_em),
            INDEX idx_wo_webhook_mensagem (empresa_id,mensagem_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        INSERT IGNORE INTO whatsapp_operacional_fila
            (empresa_id,rotina_codigo,rotina_nome,referencia,competencia,data_limite,destinatario_id,destinatario_nome,telefone,status,tentativas,proxima_tentativa,quantidade_itens,valor,arquivo,resposta_api,erro,criado_em,iniciado_em,aceito_em)
        SELECT q.empresa_id,'fechamento_mensal_clientes','Fechamento mensal de clientes',DATE_FORMAT(q.competencia,'%Y-%m'),q.competencia,q.data_limite,
               CAST(q.clicontador AS CHAR),COALESCE(NULLIF(c.NOME,''),NULLIF(c.APELIDO,''),CONCAT('Cliente ',q.clicontador)),q.telefone,
               CASE WHEN q.status='ENVIADO' THEN 'ACEITO' ELSE q.status END,q.tentativas,q.proxima_tentativa,q.quantidade_titulos,q.valor_aberto,q.arquivo,q.resposta_api,q.erro,q.criado_em,q.iniciado_em,q.enviado_em
        FROM whatsapp_operacional_fila_fechamentos q
        LEFT JOIN armazem_cr002 c ON c.EMPRESA=q.empresa_id AND c.CLICONTADOR=q.clicontador
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

function whatsappOperacionalEnsureColumn(PDO $pdo, string $tabela, string $coluna, string $sql): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->execute([$tabela, $coluna]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec($sql);
    }
}

function whatsappOperacionalConfig(PDO $pdo, int $empresaId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_operacional_config WHERE empresa_id = ? LIMIT 1");
    $stmt->execute([$empresaId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function whatsappOperacionalWebhookUrl(array $config): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'www.superdunga.com.br');
    if (preg_match('/^(127\.0\.0\.1|localhost)(:\d+)?$/i', $host)) {
        $host = 'www.superdunga.com.br';
    }
    return 'https://' . $host . '/modulos/whatsapp_operacional/webhook_evolution.php?token=' . rawurlencode((string)$config['webhook_token']);
}

function whatsappOperacionalConfigurarWebhook(array $config): array
{
    $url = rtrim((string)$config['evolution_api_base_url'], '/') . '/webhook/set/' . rawurlencode((string)$config['instancia']);
    $payload = [
        'url' => whatsappOperacionalWebhookUrl($config),
        'webhook_by_events' => false,
        'webhook_base64' => false,
        'events' => ['MESSAGES_UPDATE', 'SEND_MESSAGE_UPDATE'],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . $config['evolution_token']],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resposta = curl_exec($ch);
    $erroCurl = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = $resposta !== false && $http >= 200 && $http < 300;
    return ['ok'=>$ok, 'http'=>$http, 'resposta'=>$resposta, 'erro'=>$ok?null:($resposta===false?$erroCurl:'HTTP '.$http.' - '.$resposta)];
}

function whatsappOperacionalProcessarWebhook(PDO $pdo, string $token, array $payload): array
{
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_operacional_config WHERE webhook_token=? AND webhook_token<>'' LIMIT 1");
    $stmt->execute([$token]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$config) {
        throw new RuntimeException('Token de webhook invalido.');
    }

    $instanciaPayload = $payload['instance'] ?? null;
    $instancia = is_array($instanciaPayload)
        ? (string)($instanciaPayload['instanceName'] ?? $instanciaPayload['name'] ?? '')
        : (string)($instanciaPayload ?? $payload['instanceName'] ?? '');
    if ($instancia !== '' && $instancia !== (string)$config['instancia']) {
        throw new RuntimeException('Instancia do evento nao corresponde a empresa.');
    }
    $evento = strtoupper(str_replace(['.', '-'], '_', (string)($payload['event'] ?? '')));
    $dados = $payload['data'] ?? [];
    if (isset($dados[0]) && is_array($dados[0])) {
        $dados = $dados[0];
    }
    $mensagemId = (string)($dados['key']['id'] ?? $dados['id'] ?? $dados['messageId'] ?? $payload['messageId'] ?? '');
    $status = strtoupper((string)($dados['update']['status'] ?? $dados['status'] ?? $payload['status'] ?? ''));
    $statusNumerico = ['0'=>'ERROR','1'=>'PENDING','2'=>'SERVER_ACK','3'=>'DELIVERY_ACK','4'=>'READ','5'=>'PLAYED'];
    if (isset($statusNumerico[$status])) {
        $status = $statusNumerico[$status];
    }

    $filaId = null;
    $resultado = 'IGNORADO';
    if ($mensagemId !== '') {
        $stmt = $pdo->prepare("SELECT id,status FROM whatsapp_operacional_fila WHERE empresa_id=? AND mensagem_id=? LIMIT 1");
        $stmt->execute([(int)$config['empresa_id'], $mensagemId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fila) {
            $filaId = (int)$fila['id'];
            if (in_array($status, ['DELIVERY_ACK','READ','PLAYED'], true)) {
                $pdo->prepare("UPDATE whatsapp_operacional_fila SET status='ENTREGUE',entregue_em=COALESCE(entregue_em,NOW()),erro=NULL WHERE id=? AND status IN ('ACEITO','ENTREGUE')")->execute([$filaId]);
                $resultado = 'ENTREGUE';
            } elseif ($status === 'ERROR') {
                $pdo->prepare("UPDATE whatsapp_operacional_fila SET status='ERRO',erro='Falha de entrega informada pela Evolution',proxima_tentativa=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE id=? AND status='ACEITO'")->execute([$filaId]);
                $resultado = 'ERRO';
            } else {
                $resultado = 'SEM_ALTERACAO';
            }
        }
    }
    $stmt = $pdo->prepare("INSERT INTO whatsapp_operacional_webhook_eventos (empresa_id,instancia,evento,mensagem_id,status_recebido,fila_id,resultado) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([(int)$config['empresa_id'],(string)$config['instancia'],$evento,$mensagemId?:null,$status?:null,$filaId,$resultado]);
    return ['resultado'=>$resultado,'fila_id'=>$filaId,'mensagem_id'=>$mensagemId,'status'=>$status];
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

function whatsappOperacionalClientesFechamento(PDO $pdo, int $empresaId, string $inicio, string $fim): array
{
    $stmt = $pdo->prepare("
        SELECT c.CLICONTADOR, COALESCE(NULLIF(c.NOME,''),NULLIF(c.APELIDO,''),CONCAT('Cliente ',c.CLICONTADOR)) AS nome_cliente,
               c.CELULAR, COUNT(cr.CRCONTADOR) AS quantidade_titulos, COALESCE(SUM(cr.VLRRESTANTE),0) AS valor_aberto,
               MAX(ok.enviado_em) AS ultimo_envio
        FROM armazem_cr002 c
        INNER JOIN financeiro_clientes_whatsapp w ON w.empresa_id=c.EMPRESA AND w.clicontador=c.CLICONTADOR AND w.ativo_whatsapp='S'
        INNER JOIN armazem_cr001 cr ON cr.EMPRESA=c.EMPRESA AND cr.CLICONTADOR=c.CLICONTADOR
        LEFT JOIN armazem_est007 v ON v.EMPRESA=cr.EMPRESA AND v.VENDACONTADOR=cr.NUMDOCORIGEM
        LEFT JOIN (
            SELECT empresa_id,clicontador,data_inicio,data_fim,MAX(enviado_em) AS enviado_em
            FROM whatsapp_operacional_fechamentos
            WHERE status='OK'
            GROUP BY empresa_id,clicontador,data_inicio,data_fim
        ) ok ON ok.empresa_id=c.EMPRESA AND ok.clicontador=c.CLICONTADOR
             AND ok.data_inicio=? AND ok.data_fim=?
        WHERE c.EMPRESA=?
          AND cr.CMCONTADOR=9
          AND DATE(COALESCE(v.DTVENDA,cr.DTEMISSAO)) BETWEEN ? AND ?
          AND (cr.STATUS IS NULL OR cr.STATUS<>'QT')
          AND COALESCE(cr.VLRRESTANTE,0)>0
          AND COALESCE(cr.excluido_firebird,'N')<>'S'
        GROUP BY c.CLICONTADOR,nome_cliente,c.CELULAR
        ORDER BY nome_cliente,c.CLICONTADOR
    ");
    $stmt->execute([$inicio, $fim, $empresaId, $inicio, $fim]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function whatsappOperacionalTitulosCliente(PDO $pdo, int $empresaId, int $clicontador, string $inicio, string $fim): array
{
    $stmt = $pdo->prepare("
        SELECT cr.CRCONTADOR, cr.CLICONTADOR,
               COALESCE(NULLIF(cli.NOME,''),NULLIF(cli.APELIDO,''),CONCAT('Cliente ',cr.CLICONTADOR)) AS nome_cliente,
               cli.CELULAR, cr.DTVENC, cr.DTEMISSAO, cr.DTPAGTO, cr.VLRPARCELA, cr.VLRPAGO, cr.VLRRESTANTE,
               COALESCE(NULLIF(cr.STATUS,''),'AB') AS STATUS
        FROM armazem_cr001 cr
        INNER JOIN armazem_cr002 cli ON cli.EMPRESA=cr.EMPRESA AND cli.CLICONTADOR=cr.CLICONTADOR
        LEFT JOIN armazem_est007 v ON v.EMPRESA=cr.EMPRESA AND v.VENDACONTADOR=cr.NUMDOCORIGEM
        WHERE cr.EMPRESA=? AND cr.CLICONTADOR=?
          AND cr.CMCONTADOR=9
          AND DATE(COALESCE(v.DTVENDA,cr.DTEMISSAO)) BETWEEN ? AND ?
          AND (cr.STATUS IS NULL OR cr.STATUS<>'QT')
          AND COALESCE(cr.VLRRESTANTE,0)>0
          AND COALESCE(cr.excluido_firebird,'N')<>'S'
        ORDER BY cr.DTVENC,cr.CRCONTADOR
    ");
    $stmt->execute([$empresaId, $clicontador, $inicio, $fim]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function whatsappOperacionalPdfTexto(string $texto, int $limite = 0): string
{
    $texto = preg_replace('/\s+/', ' ', trim($texto));
    if ($limite > 0 && strlen($texto) > $limite) {
        $texto = substr($texto, 0, max(0, $limite - 3)) . '...';
    }
    $convertido = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto);
    $texto = $convertido === false ? $texto : $convertido;
    return str_replace(['\\','(',')'], ['\\\\','\(','\)'], $texto);
}

function whatsappOperacionalPdfTextoCmd(float $x, float $y, int $tamanho, string $texto, bool $negrito = false): string
{
    return 'BT /' . ($negrito ? 'F2' : 'F1') . ' ' . $tamanho . ' Tf 1 0 0 1 ' . number_format($x,2,'.','') . ' ' . number_format($y,2,'.','') . ' Tm (' . whatsappOperacionalPdfTexto($texto) . ") Tj ET\n";
}

function whatsappOperacionalGerarPdf(string $arquivo, string $empresa, array $cliente, string $inicio, string $fim, array $titulos): void
{
    $largura=595; $altura=842; $margem=28; $linhaAltura=16; $topoTabela=700; $porPagina=39;
    $total=array_sum(array_map(function($t){return (float)$t['VLRRESTANTE'];},$titulos));
    $colunas=[
        ['CR',52],['Cod.',45],['Cliente',250],['Emissao',70],['Valor',78],['Status',44],
    ];
    $paginas=[]; $totalPaginas=max(1,(int)ceil(count($titulos)/$porPagina));
    for($pagina=0;$pagina<$totalPaginas;$pagina++){
        $conteudo="0.05 0.20 0.45 rg\n0 724 595 118 re f\n1 1 1 rg\n";
        $conteudo.=whatsappOperacionalPdfTextoCmd($margem,812,17,'Contas a Receber - Clientes - Analitico',true);
        $conteudo.=whatsappOperacionalPdfTextoCmd($margem,791,8,'Empresa: '.$empresa);
        $conteudo.=whatsappOperacionalPdfTextoCmd($margem,778,8,'Cliente: '.$cliente['nome_cliente'].' | Codigo: '.(int)$cliente['CLICONTADOR']);
        $conteudo.=whatsappOperacionalPdfTextoCmd($margem,765,8,'Celular: '.($cliente['CELULAR'] ?: 'Nao informado'));
        $periodoCabecalho = $inicio === 'ATE'
            ? 'Compras realizadas ate: ' . date('d/m/Y', strtotime($fim))
            : 'Intervalo de compras: ' . date('d/m/Y', strtotime($inicio)) . ' a ' . date('d/m/Y', strtotime($fim));
        $conteudo.=whatsappOperacionalPdfTextoCmd($margem,752,8,$periodoCabecalho);
        $conteudo.="1 0.78 0 rg\n330 738 237 27 re f\n0.05 0.12 0.22 rg\n";
        $conteudo.=whatsappOperacionalPdfTextoCmd(340,747,12,'TOTAL EM ABERTO: R$ '.number_format($total,2,',','.'),true);
        $conteudo.="0.88 0.93 0.98 rg\n28 696 539 20 re f\n0 g\n";
        $x=$margem+3;
        foreach($colunas as $coluna){$conteudo.="0.62 0.69 0.78 RG 0.45 w ".number_format($x-3,2,'.','')." 696 ".$coluna[1]." 20 re S\n";$conteudo.=whatsappOperacionalPdfTextoCmd($x,$topoTabela+2,8,$coluna[0],true);$x+=$coluna[1];}
        $y=$topoTabela-16;
        foreach(array_slice($titulos,$pagina*$porPagina,$porPagina) as $t){
            $valores=[(string)$t['CRCONTADOR'],(string)$t['CLICONTADOR'],$t['nome_cliente'],date('d/m/Y',strtotime($t['DTEMISSAO'])),'R$ '.number_format((float)$t['VLRPARCELA'],2,',','.'),$t['STATUS']];
            $x=$margem+3;
            foreach($colunas as $i=>$coluna){$conteudo.="0.80 0.83 0.87 RG 0.35 w ".number_format($x-3,2,'.','').' '.number_format($y-5,2,'.','')." ".$coluna[1]." 16 re S\n";$limite=$i===2?38:16;$conteudo.=whatsappOperacionalPdfTextoCmd($x,$y,8,whatsappOperacionalPdfTexto((string)$valores[$i],$limite));$x+=$coluna[1];}
            $y-=$linhaAltura;
        }
        $conteudo.=whatsappOperacionalPdfTextoCmd($margem,22,8,'Gerado em '.date('d/m/Y H:i').' - Pagina '.($pagina+1).' de '.$totalPaginas);
        $paginas[]=$conteudo;
    }
    $objetos=[1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
    $kids=[];$id=5;
    foreach($paginas as $conteudo){$paginaId=$id++;$streamId=$id++;$kids[]=$paginaId.' 0 R';$objetos[$paginaId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$largura.' '.$altura.'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$streamId.' 0 R >>';$objetos[$streamId]='<< /Length '.strlen($conteudo).">>\nstream\n".$conteudo."endstream";}
    $objetos[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';ksort($objetos);
    $pdf="%PDF-1.4\n";$offsets=[0];foreach($objetos as $oid=>$obj){$offsets[$oid]=strlen($pdf);$pdf.=$oid." 0 obj\n".$obj."\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objetos));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++){$pdf.=str_pad((string)($offsets[$i]??0),10,'0',STR_PAD_LEFT)." 00000 n \n";}$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    if(!is_dir(dirname($arquivo))){mkdir(dirname($arquivo),0775,true);}file_put_contents($arquivo,$pdf);
}

function whatsappOperacionalEnviarDocumento(array $config, string $numero, string $arquivo, string $nomeArquivo, string $legenda): array
{
    $numero=preg_replace('/\D+/','',$numero);
    if(strlen($numero)===10||strlen($numero)===11){$numero='55'.$numero;}
    $url=rtrim(trim((string)$config['evolution_api_base_url']),'/').'/message/sendMedia/'.rawurlencode($config['instancia']);
    $payload=['number'=>$numero,'mediatype'=>'document','mimetype'=>'application/pdf','caption'=>$legenda,'media'=>base64_encode((string)file_get_contents($arquivo)),'fileName'=>$nomeArquivo];
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json','apikey: '.$config['evolution_token']],CURLOPT_TIMEOUT=>60]);$resposta=curl_exec($ch);$erroCurl=curl_error($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $ok=$resposta!==false&&$http>=200&&$http<300;$json=$ok?json_decode((string)$resposta,true):null;$mensagemId=(string)($json['key']['id']??$json['messageId']??'');return ['ok'=>$ok,'resposta'=>$resposta===false?null:$resposta,'erro'=>$ok?null:($resposta===false?$erroCurl:'HTTP '.$http.' - '.$resposta),'mensagem_id'=>$mensagemId];
}

function whatsappOperacionalFeriados(PDO $pdo, int $empresaId, int $ano): array
{
    $feriados = [];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='desconto_cheques_feriados'");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() > 0) {
        $stmt = $pdo->prepare("SELECT dia,mes FROM desconto_cheques_feriados WHERE empresa_id=? AND ativo='S'");
        $stmt->execute([$empresaId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $feriados[sprintf('%04d-%02d-%02d', $ano, (int)$f['mes'], (int)$f['dia'])] = true;
        }
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='desconto_cheques_feriados_variaveis'");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() > 0) {
        $stmt = $pdo->prepare("SELECT data_feriado FROM desconto_cheques_feriados_variaveis WHERE empresa_id=? AND ativo='S' AND YEAR(data_feriado)=?");
        $stmt->execute([$empresaId, $ano]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $data) {
            $feriados[(string)$data] = true;
        }
    }
    return $feriados;
}

function whatsappOperacionalSincronizacaoAtualizada(PDO $pdo, int $empresaId, int $minutos = 30): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tesouraria_sincronizacao_log'");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tesouraria_sincronizacao_log WHERE empresa_id=? AND UPPER(tabela)='CR001' AND UPPER(status)='OK' AND data_execucao>=DATE_SUB(NOW(),INTERVAL ? MINUTE)");
    $stmt->execute([$empresaId, max(5, $minutos)]);
    return (int)$stmt->fetchColumn() > 0;
}

function whatsappOperacionalDataEnvio(PDO $pdo, int $empresaId, string $competencia, int $diaUtil, string $horario, ?string $excecao = null): DateTimeImmutable
{
    $mesEnvio = (new DateTimeImmutable($competencia))->modify('first day of next month');
    if ($excecao && preg_match('/^\d{4}-\d{2}-\d{2}$/', $excecao) && substr($excecao, 0, 7) === $mesEnvio->format('Y-m')) {
        return new DateTimeImmutable($excecao . ' ' . $horario);
    }
    $feriados = whatsappOperacionalFeriados($pdo, $empresaId, (int)$mesEnvio->format('Y'));
    $data = $mesEnvio;
    $contados = 0;
    while ($contados < max(1, $diaUtil)) {
        if ((int)$data->format('N') <= 5 && !isset($feriados[$data->format('Y-m-d')])) {
            $contados++;
            if ($contados === max(1, $diaUtil)) {
                break;
            }
        }
        $data = $data->modify('+1 day');
    }
    return new DateTimeImmutable($data->format('Y-m-d') . ' ' . $horario);
}

function whatsappOperacionalTitulosAte(PDO $pdo, int $empresaId, int $clicontador, string $dataLimite): array
{
    $stmt = $pdo->prepare("
        SELECT cr.CRCONTADOR,cr.CLICONTADOR,
               COALESCE(NULLIF(cli.NOME,''),NULLIF(cli.APELIDO,''),CONCAT('Cliente ',cr.CLICONTADOR)) AS nome_cliente,
               cli.CELULAR,cr.DTVENC,cr.DTEMISSAO,cr.DTPAGTO,cr.VLRPARCELA,cr.VLRPAGO,cr.VLRRESTANTE,
               COALESCE(NULLIF(cr.STATUS,''),'AB') AS STATUS
        FROM armazem_cr001 cr
        INNER JOIN armazem_cr002 cli ON cli.EMPRESA=cr.EMPRESA AND cli.CLICONTADOR=cr.CLICONTADOR
        LEFT JOIN armazem_est007 v ON v.EMPRESA=cr.EMPRESA AND v.VENDACONTADOR=cr.NUMDOCORIGEM
        WHERE cr.EMPRESA=? AND cr.CLICONTADOR=? AND cr.CMCONTADOR=9
          AND DATE(COALESCE(v.DTVENDA,cr.DTEMISSAO))<=?
          AND (cr.STATUS IS NULL OR cr.STATUS<>'QT')
          AND COALESCE(cr.VLRRESTANTE,0)>0
          AND COALESCE(cr.excluido_firebird,'N')<>'S'
        ORDER BY DATE(COALESCE(v.DTVENDA,cr.DTEMISSAO)),cr.DTVENC,cr.CRCONTADOR
    ");
    $stmt->execute([$empresaId, $clicontador, $dataLimite]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function whatsappOperacionalClientesFechamentoAte(PDO $pdo, int $empresaId, string $dataLimite): array
{
    $stmt = $pdo->prepare("
        SELECT c.CLICONTADOR,COALESCE(NULLIF(c.NOME,''),NULLIF(c.APELIDO,''),CONCAT('Cliente ',c.CLICONTADOR)) AS nome_cliente,
               c.CELULAR,COUNT(cr.CRCONTADOR) AS quantidade_titulos,COALESCE(SUM(cr.VLRRESTANTE),0) AS valor_aberto,
               (SELECT MAX(f.enviado_em) FROM whatsapp_operacional_fechamentos f WHERE f.empresa_id=c.EMPRESA AND f.clicontador=c.CLICONTADOR AND f.data_fim=? AND f.status='OK') AS ultimo_envio
        FROM armazem_cr002 c
        INNER JOIN financeiro_clientes_whatsapp w ON w.empresa_id=c.EMPRESA AND w.clicontador=c.CLICONTADOR AND w.ativo_whatsapp='S'
        INNER JOIN armazem_cr001 cr ON cr.EMPRESA=c.EMPRESA AND cr.CLICONTADOR=c.CLICONTADOR
        LEFT JOIN armazem_est007 v ON v.EMPRESA=cr.EMPRESA AND v.VENDACONTADOR=cr.NUMDOCORIGEM
        WHERE c.EMPRESA=? AND cr.CMCONTADOR=9
          AND DATE(COALESCE(v.DTVENDA,cr.DTEMISSAO))<=?
          AND (cr.STATUS IS NULL OR cr.STATUS<>'QT') AND COALESCE(cr.VLRRESTANTE,0)>0
          AND COALESCE(cr.excluido_firebird,'N')<>'S'
        GROUP BY c.CLICONTADOR,nome_cliente,c.CELULAR
        ORDER BY nome_cliente,c.CLICONTADOR
    ");
    $stmt->execute([$dataLimite,$empresaId,$dataLimite]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function whatsappOperacionalPrepararFila(PDO $pdo, array $config, string $competencia, DateTimeImmutable $agendada): int
{
    $empresaId = (int)$config['empresa_id'];
    $dataLimite = (new DateTimeImmutable($competencia))->modify('last day of this month')->format('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT c.CLICONTADOR,COALESCE(NULLIF(c.NOME,''),NULLIF(c.APELIDO,''),CONCAT('Cliente ',c.CLICONTADOR)) AS nome_cliente,c.CELULAR
        FROM armazem_cr002 c
        INNER JOIN financeiro_clientes_whatsapp w ON w.empresa_id=c.EMPRESA AND w.clicontador=c.CLICONTADOR AND w.ativo_whatsapp='S'
        WHERE c.EMPRESA=?
          AND EXISTS (
              SELECT 1 FROM armazem_cr001 cr
              LEFT JOIN armazem_est007 v ON v.EMPRESA=cr.EMPRESA AND v.VENDACONTADOR=cr.NUMDOCORIGEM
              WHERE cr.EMPRESA=c.EMPRESA AND cr.CLICONTADOR=c.CLICONTADOR AND cr.CMCONTADOR=9
                AND DATE(COALESCE(v.DTVENDA,cr.DTEMISSAO))<=?
                AND (cr.STATUS IS NULL OR cr.STATUS<>'QT') AND COALESCE(cr.VLRRESTANTE,0)>0
                AND COALESCE(cr.excluido_firebird,'N')<>'S'
          )
        ORDER BY c.CLICONTADOR
    ");
    $stmt->execute([$empresaId, $dataLimite]);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare("
        INSERT IGNORE INTO whatsapp_operacional_fila
            (empresa_id,rotina_codigo,rotina_nome,referencia,competencia,data_limite,destinatario_id,destinatario_nome,telefone,status,proxima_tentativa)
        VALUES (?,'fechamento_mensal_clientes','Fechamento mensal de clientes',?,?,?,?,?,?,'AGUARDANDO',?)
    ");
    $criados = 0;
    foreach ($clientes as $i => $cliente) {
        $telefone = trim((string)$cliente['CELULAR']);
        $status = $telefone === '' ? 'IGNORADO' : 'AGUARDANDO';
        $proxima = $agendada->modify('+' . ($i * max(10, (int)$config['fechamento_intervalo_segundos'])) . ' seconds')->format('Y-m-d H:i:s');
        if ($status === 'IGNORADO') {
            $insertIgnorado = $pdo->prepare("INSERT IGNORE INTO whatsapp_operacional_fila (empresa_id,rotina_codigo,rotina_nome,referencia,competencia,data_limite,destinatario_id,destinatario_nome,telefone,status,proxima_tentativa,erro) VALUES (?,'fechamento_mensal_clientes','Fechamento mensal de clientes',?,?,?,?,?,?,'IGNORADO',NULL,'Cliente sem celular')");
            $insertIgnorado->execute([$empresaId, substr($competencia,0,7), $competencia, $dataLimite, (string)$cliente['CLICONTADOR'], (string)$cliente['nome_cliente'], $telefone]);
            $criados += $insertIgnorado->rowCount();
        } else {
            $insert->execute([$empresaId, substr($competencia,0,7), $competencia, $dataLimite, (string)$cliente['CLICONTADOR'], (string)$cliente['nome_cliente'], $telefone, $proxima]);
            $criados += $insert->rowCount();
        }
    }
    return $criados;
}

function whatsappOperacionalExecutarAutomacao(PDO $pdo): array
{
    whatsappOperacionalEnsureTables($pdo);
    $agora = new DateTimeImmutable('now');
    $competencia = $agora->modify('first day of previous month')->format('Y-m-01');
    $configs = $pdo->query("SELECT * FROM whatsapp_operacional_config WHERE ativo='S' AND fechamento_ativo='S'")->fetchAll(PDO::FETCH_ASSOC);
    $resultado = ['filas_criadas'=>0,'processados'=>0,'aguardando_sincronizacao'=>[]];
    foreach ($configs as $config) {
        $agendada = whatsappOperacionalDataEnvio($pdo,(int)$config['empresa_id'],$competencia,(int)$config['fechamento_dia_util'],(string)$config['fechamento_horario'],$config['fechamento_proxima_data'] ?: null);
        $competenciaJaPreparada = !empty($config['fechamento_ultima_competencia']) && (string)$config['fechamento_ultima_competencia'] >= $competencia;
        if (!$competenciaJaPreparada && $agora >= $agendada) {
            if (!whatsappOperacionalSincronizacaoAtualizada($pdo,(int)$config['empresa_id'])) {
                $resultado['aguardando_sincronizacao'][] = (int)$config['empresa_id'];
                continue;
            }
            $resultado['filas_criadas'] += whatsappOperacionalPrepararFila($pdo,$config,$competencia,$agendada);
            $pdo->prepare("UPDATE whatsapp_operacional_config SET fechamento_ultima_competencia=?,fechamento_proxima_data=NULL WHERE empresa_id=?")->execute([$competencia,(int)$config['empresa_id']]);
        }
    }
    $pdo->exec("UPDATE whatsapp_operacional_fila SET status='ERRO',erro='Processamento interrompido; aguardando nova tentativa',proxima_tentativa=NOW() WHERE status='PROCESSANDO' AND iniciado_em<DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
    $stmt = $pdo->query("
        SELECT q.*
        FROM whatsapp_operacional_fila q
        INNER JOIN whatsapp_operacional_config c ON c.empresa_id=q.empresa_id AND c.ativo='S' AND c.fechamento_ativo='S'
        WHERE q.rotina_codigo='fechamento_mensal_clientes' AND q.status IN ('AGUARDANDO','ERRO') AND q.tentativas<3 AND q.proxima_tentativa<=NOW()
          AND (
              (SELECT MAX(u.aceito_em) FROM whatsapp_operacional_fila u WHERE u.empresa_id=q.empresa_id AND u.status IN ('ACEITO','ENTREGUE')) IS NULL
              OR TIMESTAMPDIFF(SECOND,(SELECT MAX(u.aceito_em) FROM whatsapp_operacional_fila u WHERE u.empresa_id=q.empresa_id AND u.status IN ('ACEITO','ENTREGUE')),NOW())>=c.fechamento_intervalo_segundos
          )
        ORDER BY q.proxima_tentativa,q.id LIMIT 1
    ");
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        return $resultado;
    }
    if (!whatsappOperacionalSincronizacaoAtualizada($pdo,(int)$item['empresa_id'])) {
        if (!in_array((int)$item['empresa_id'],$resultado['aguardando_sincronizacao'],true)) {
            $resultado['aguardando_sincronizacao'][] = (int)$item['empresa_id'];
        }
        return $resultado;
    }
    $lock = $pdo->prepare("UPDATE whatsapp_operacional_fila SET status='PROCESSANDO',iniciado_em=NOW(),tentativas=tentativas+1 WHERE id=? AND status IN ('AGUARDANDO','ERRO')");
    $lock->execute([(int)$item['id']]);
    if ($lock->rowCount() !== 1) {
        return $resultado;
    }
    $config = whatsappOperacionalConfig($pdo,(int)$item['empresa_id']);
    $titulos = whatsappOperacionalTitulosAte($pdo,(int)$item['empresa_id'],(int)$item['destinatario_id'],(string)$item['data_limite']);
    if (!$config || !$titulos) {
        $motivo = !$config ? 'Instancia operacional indisponivel' : 'Sem titulos em aberto no momento do envio';
        $pdo->prepare("UPDATE whatsapp_operacional_fila SET status='IGNORADO',erro=? WHERE id=?")->execute([$motivo,(int)$item['id']]);
        $resultado['processados']++;
        return $resultado;
    }
    $cliente=['CLICONTADOR'=>(int)$item['destinatario_id'],'nome_cliente'=>$titulos[0]['nome_cliente'],'CELULAR'=>$titulos[0]['CELULAR']];
    $diretorio=dirname(__DIR__,2).'/storage/whatsapp_fechamentos_operacionais/empresa_'.(int)$item['empresa_id'].'/'.str_replace('-','_',substr((string)$item['competencia'],0,7));
    $nome='fechamento_'.(int)$item['destinatario_id'].'_ate_'.str_replace('-','',(string)$item['data_limite']).'.pdf';
    $arquivo=$diretorio.'/'.$nome;
    whatsappOperacionalGerarPdf($arquivo,whatsappNomeEmpresa($pdo,(int)$item['empresa_id']),$cliente,'ATE',(string)$item['data_limite'],$titulos);
    $envio=whatsappOperacionalEnviarDocumento($config,(string)$cliente['CELULAR'],$arquivo,$nome,'Relacao de compras em aberto ate '.date('d/m/Y',strtotime((string)$item['data_limite'])));
    $total=array_sum(array_map(function($t){return (float)$t['VLRRESTANTE'];},$titulos));
    if ($envio['ok']) {
        $pdo->prepare("UPDATE whatsapp_operacional_fila SET status='ACEITO',quantidade_itens=?,valor=?,arquivo=?,mensagem_id=?,resposta_api=?,erro=NULL,aceito_em=NOW() WHERE id=?")->execute([count($titulos),$total,$nome,$envio['mensagem_id'],$envio['resposta'],(int)$item['id']]);
    } else {
        $pdo->prepare("UPDATE whatsapp_operacional_fila SET status='ERRO',quantidade_itens=?,valor=?,arquivo=?,resposta_api=?,erro=?,proxima_tentativa=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE id=?")->execute([count($titulos),$total,$nome,$envio['resposta'],$envio['erro'],(int)$item['id']]);
    }
    $resultado['processados']++;
    return $resultado;
}
