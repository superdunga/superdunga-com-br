<?php

if (defined('INTER_PIX_CONFIG_CARREGADO')) {
    return;
}

define('INTER_PIX_CONFIG_CARREGADO', true);

function garantirTabelaInterPix(PDO $pdo): void
{
    static $executado = false;
    if ($executado) {
        return;
    }
    $executado = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fechamento_inter_pix_config (
            empresa_id INT NOT NULL PRIMARY KEY,
            ambiente VARCHAR(20) NOT NULL DEFAULT 'producao',
            client_id VARCHAR(255) NULL,
            client_secret TEXT NULL,
            conta_corrente VARCHAR(30) NULL,
            cert_path VARCHAR(500) NULL,
            key_path VARCHAR(500) NULL,
            cert_password TEXT NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            token_cache TEXT NULL,
            token_expira_em DATETIME NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function buscarConfigInterPix(PDO $pdo, int $empresaId): ?array
{
    garantirTabelaInterPix($pdo);

    $stmt = $pdo->prepare("
        SELECT *
        FROM fechamento_inter_pix_config
        WHERE empresa_id = ?
        LIMIT 1
    ");
    $stmt->execute([$empresaId]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    return $config ?: null;
}

function salvarConfigInterPix(PDO $pdo, int $empresaId, array $dados): void
{
    garantirTabelaInterPix($pdo);

    $atual = buscarConfigInterPix($pdo, $empresaId);
    $clientSecret = trim((string)($dados['client_secret'] ?? ''));
    $certPassword = trim((string)($dados['cert_password'] ?? ''));

    if ($atual) {
        $sql = "
            UPDATE fechamento_inter_pix_config
            SET ambiente = ?,
                client_id = ?,
                conta_corrente = ?,
                cert_path = ?,
                key_path = ?,
                ativo = ?,
                token_cache = NULL,
                token_expira_em = NULL
        ";
        $params = [
            $dados['ambiente'],
            $dados['client_id'],
            $dados['conta_corrente'],
            $dados['cert_path'],
            $dados['key_path'],
            $dados['ativo'],
        ];

        if ($clientSecret !== '') {
            $sql .= ", client_secret = ?";
            $params[] = $clientSecret;
        }

        if ($certPassword !== '') {
            $sql .= ", cert_password = ?";
            $params[] = $certPassword;
        }

        $sql .= " WHERE empresa_id = ?";
        $params[] = $empresaId;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO fechamento_inter_pix_config (
            empresa_id, ambiente, client_id, client_secret, conta_corrente,
            cert_path, key_path, cert_password, ativo
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $empresaId,
        $dados['ambiente'],
        $dados['client_id'],
        $clientSecret,
        $dados['conta_corrente'],
        $dados['cert_path'],
        $dados['key_path'],
        $certPassword,
        $dados['ativo'],
    ]);
}

function interPixBaseUrl(array $config, string $servico): string
{
    $sandbox = ($config['ambiente'] ?? 'producao') === 'sandbox';

    if ($servico === 'pix') {
        return $sandbox
            ? 'https://cdpj-sandbox.partners.uatinter.co/pix/v2'
            : 'https://cdpj.partners.bancointer.com.br/pix/v2';
    }

    return $sandbox
        ? 'https://cdpj-sandbox.partners.uatinter.co/oauth/v2'
        : 'https://cdpj.partners.bancointer.com.br/oauth/v2';
}

function interPixAplicarCertificado($curl, array $config): void
{
    $certPath = trim((string)($config['cert_path'] ?? ''));
    $keyPath = trim((string)($config['key_path'] ?? ''));
    $certPassword = trim((string)($config['cert_password'] ?? ''));

    if ($certPath === '') {
        throw new RuntimeException('Caminho do certificado do Inter nao configurado.');
    }

    if (!is_file($certPath)) {
        throw new RuntimeException('Certificado do Inter nao encontrado no caminho configurado.');
    }

    curl_setopt($curl, CURLOPT_SSLCERT, $certPath);

    $ext = strtolower(pathinfo($certPath, PATHINFO_EXTENSION));
    if (in_array($ext, ['p12', 'pfx'], true)) {
        curl_setopt($curl, CURLOPT_SSLCERTTYPE, 'P12');
        if ($certPassword !== '') {
            curl_setopt($curl, CURLOPT_SSLCERTPASSWD, $certPassword);
        }
        return;
    }

    if ($keyPath !== '') {
        if (!is_file($keyPath)) {
            throw new RuntimeException('Chave privada do Inter nao encontrada no caminho configurado.');
        }
        curl_setopt($curl, CURLOPT_SSLKEY, $keyPath);
    }

    if ($certPassword !== '') {
        curl_setopt($curl, CURLOPT_KEYPASSWD, $certPassword);
    }
}

function interPixHttp(array $config, string $method, string $url, array $headers = [], $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extensao cURL nao esta habilitada no PHP.');
    }

    $curl = curl_init($url);
    interPixAplicarCertificado($curl, $config);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
    ]);

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($curl);
    $erro = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('Erro cURL Inter: ' . $erro);
    }

    $json = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $mensagem = is_array($json)
            ? ($json['detail'] ?? $json['title'] ?? json_encode($json, JSON_UNESCAPED_UNICODE))
            : $response;
        throw new RuntimeException("Inter retornou HTTP {$status}: {$mensagem}");
    }

    return is_array($json) ? $json : [];
}

function obterTokenInterPix(PDO $pdo, int $empresaId, array $config): string
{
    if (!empty($config['token_cache']) && !empty($config['token_expira_em'])) {
        if (strtotime($config['token_expira_em']) > time() + 60) {
            return $config['token_cache'];
        }
    }

    foreach (['client_id', 'client_secret'] as $campo) {
        if (trim((string)($config[$campo] ?? '')) === '') {
            throw new RuntimeException('Client ID/Secret do Inter nao configurados.');
        }
    }

    $body = http_build_query([
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'grant_type' => 'client_credentials',
        'scope' => 'pix.read',
    ]);

    $retorno = interPixHttp(
        $config,
        'POST',
        interPixBaseUrl($config, 'oauth') . '/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        $body
    );

    $token = (string)($retorno['access_token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('Token Inter nao retornado.');
    }

    $expiraEm = date('Y-m-d H:i:s', time() + (int)($retorno['expires_in'] ?? 3600));
    $stmt = $pdo->prepare("
        UPDATE fechamento_inter_pix_config
        SET token_cache = ?, token_expira_em = ?
        WHERE empresa_id = ?
    ");
    $stmt->execute([$token, $expiraEm, $empresaId]);

    return $token;
}

function interPixDataHoraMysql(string $valor): ?string
{
    if (trim($valor) === '') {
        return null;
    }

    try {
        $dt = new DateTime($valor);
        $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function listarInterPix(PDO $pdo, int $empresaId, string $dataIni, string $dataFim): array
{
    $config = buscarConfigInterPix($pdo, $empresaId);
    if (!$config || ($config['ativo'] ?? 'N') !== 'S') {
        throw new RuntimeException('Configuracao da API Inter Pix nao cadastrada ou inativa.');
    }

    $inicio = (new DateTime($dataIni . ' 00:00:00', new DateTimeZone('America/Sao_Paulo')))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s\Z');
    $fim = (new DateTime($dataFim . ' 23:59:59', new DateTimeZone('America/Sao_Paulo')))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s\Z');

    $token = obterTokenInterPix($pdo, $empresaId, $config);
    $pagina = 0;
    $pixNormalizados = [];

    do {
        $params = [
            'inicio' => $inicio,
            'fim' => $fim,
            'paginacao.paginaAtual' => $pagina,
            'paginacao.itensPorPagina' => 100,
        ];

        $url = interPixBaseUrl($config, 'pix') . '/pix?' . http_build_query($params);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        $contaCorrente = preg_replace('/\D+/', '', (string)($config['conta_corrente'] ?? ''));
        if ($contaCorrente !== '') {
            $headers[] = 'x-conta-corrente: ' . $contaCorrente;
        }

        $retorno = interPixHttp($config, 'GET', $url, $headers);
        $listaPix = $retorno['pix'] ?? [];

        foreach ($listaPix as $pix) {
            $e2e = trim((string)($pix['endToEndId'] ?? ''));
            $valor = (float)($pix['valor'] ?? 0);
            $dataVenda = interPixDataHoraMysql((string)($pix['horario'] ?? ''));

            if ($e2e === '' || $valor <= 0 || $dataVenda === null) {
                continue;
            }

            $pagador = trim((string)($pix['pagador']['nome'] ?? ''));
            $documento = trim((string)($pix['pagador']['cpfCnpj'] ?? ''));
            if ($pagador === '' && $documento !== '') {
                $pagador = $documento;
            }
            if ($pagador === '') {
                $pagador = 'PIX INTER';
            }

            $identificador = 'INTER_PIX_' . $e2e;
            $descricao = trim((string)($pix['infoPagador'] ?? ''));
            if ($descricao === '') {
                $descricao = 'PIX - INTER';
            }

            $pixNormalizados[] = [
                'endToEndId' => $e2e,
                'identificador' => $identificador,
                'data_venda' => $dataVenda,
                'data_recebimento' => substr($dataVenda, 0, 10),
                'valor' => $valor,
                'pagador' => $pagador,
                'documento' => $documento,
                'descricao' => $descricao,
                'conta_corrente' => $contaCorrente,
                'raw' => $pix,
            ];
        }

        $paginacao = $retorno['parametros']['paginacao'] ?? [];
        $quantidadePaginas = (int)($paginacao['quantidadeDePaginas'] ?? 1);
        $pagina++;
    } while ($pagina < $quantidadePaginas);

    return $pixNormalizados;
}

function consultarInterPix(PDO $pdo, int $empresaId, array $regraImportacao, string $dataIni, string $dataFim): array
{
    $listaPix = listarInterPix($pdo, $empresaId, $dataIni, $dataFim);
    $importados = 0;
    $atualizados = 0;
    $ignorados = 0;

    foreach ($listaPix as $pixNormalizado) {
        $e2e = $pixNormalizado['endToEndId'];
        $valor = (float)$pixNormalizado['valor'];
        $dataVenda = $pixNormalizado['data_venda'];
        $dataRecebimento = $pixNormalizado['data_recebimento'];
        $pagador = $pixNormalizado['pagador'];
        $descricao = $pixNormalizado['descricao'];
        $identificador = $pixNormalizado['identificador'];
        $contaCorrente = $pixNormalizado['conta_corrente'];

        if ($e2e === '' || $valor <= 0 || $dataVenda === null) {
            $ignorados++;
            continue;
        }

            $check = $pdo->prepare("
                SELECT id
                FROM armazem_conciliacao_recebimentos
                WHERE empresa_id = ?
                  AND identificador = ?
                LIMIT 1
            ");
            $check->execute([$empresaId, $identificador]);
            $existente = $check->fetch(PDO::FETCH_ASSOC);

            if ($existente) {
                $update = $pdo->prepare("
                    UPDATE armazem_conciliacao_recebimentos
                    SET pagador = ?,
                        descricao = ?
                    WHERE id = ?
                      AND (pagador IS NULL OR pagador = '' OR pagador IN ('PIX', 'PIX INTER', 'GRANITO PIX'))
                ");
                $update->execute([$pagador, $descricao, $existente['id']]);
                $atualizados += $update->rowCount();
                continue;
            }

            $stmt = $pdo->prepare("
                INSERT INTO armazem_conciliacao_recebimentos (
                    empresa_id, origem, data_venda, data_prevista, data_recebimento,
                    valor_bruto, valor_desconto, valor_liquido, identificador, descricao,
                    pagador, parcela, total_parcelas, status, arquivo_origem, CMCONTADOR,
                    tipo_operacao, bandeira, nsu_transacao, numero_estabelecimento
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?,
                    1, 1, 'Pago', 'API INTER PIX', ?, 'P', 'PIX', ?, ?
                )
            ");
            $stmt->execute([
                $empresaId,
                $regraImportacao['origem'],
                $dataVenda,
                $dataRecebimento,
                $dataRecebimento,
                $valor,
                $valor,
                $identificador,
                $descricao,
                $pagador,
                (int)$regraImportacao['cm_pix'],
                $e2e,
                $contaCorrente,
            ]);

            $importados++;
    }

    return [
        'lidos' => count($listaPix),
        'importados' => $importados,
        'atualizados' => $atualizados,
        'ignorados' => $ignorados,
    ];
}
