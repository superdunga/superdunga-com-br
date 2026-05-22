<?php

if (defined('INTER_EXTRATO_CONFIG_CARREGADO')) {
    return;
}

define('INTER_EXTRATO_CONFIG_CARREGADO', true);

function garantirTabelaInterExtrato(PDO $pdo): void
{
    static $executado = false;
    if ($executado) {
        return;
    }
    $executado = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_inter_extrato_config (
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

function buscarConfigInterExtrato(PDO $pdo, int $empresaId): ?array
{
    garantirTabelaInterExtrato($pdo);

    $stmt = $pdo->prepare("
        SELECT *
        FROM financeiro_inter_extrato_config
        WHERE empresa_id = ?
        LIMIT 1
    ");
    $stmt->execute([$empresaId]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    return $config ?: null;
}

function salvarConfigInterExtrato(PDO $pdo, int $empresaId, array $dados): void
{
    garantirTabelaInterExtrato($pdo);

    $atual = buscarConfigInterExtrato($pdo, $empresaId);
    $clientSecret = trim((string)($dados['client_secret'] ?? ''));
    $certPassword = trim((string)($dados['cert_password'] ?? ''));

    if ($atual) {
        $sql = "
            UPDATE financeiro_inter_extrato_config
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

        $pdo->prepare($sql)->execute($params);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO financeiro_inter_extrato_config (
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

function interExtratoBaseUrl(array $config, string $servico): string
{
    $sandbox = ($config['ambiente'] ?? 'producao') === 'sandbox';

    if ($servico === 'banking') {
        return $sandbox
            ? 'https://cdpj-sandbox.partners.uatinter.co/banking/v2'
            : 'https://cdpj.partners.bancointer.com.br/banking/v2';
    }

    return $sandbox
        ? 'https://cdpj-sandbox.partners.uatinter.co/oauth/v2'
        : 'https://cdpj.partners.bancointer.com.br/oauth/v2';
}

function interExtratoAplicarCertificado($curl, array $config): void
{
    $certPath = trim((string)($config['cert_path'] ?? ''));
    $keyPath = trim((string)($config['key_path'] ?? ''));
    $certPassword = trim((string)($config['cert_password'] ?? ''));

    if ($certPath === '' || !is_file($certPath)) {
        throw new RuntimeException('Certificado do Inter Extrato nao encontrado.');
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
            throw new RuntimeException('Chave privada do Inter Extrato nao encontrada.');
        }
        curl_setopt($curl, CURLOPT_SSLKEY, $keyPath);
    }

    if ($certPassword !== '') {
        curl_setopt($curl, CURLOPT_KEYPASSWD, $certPassword);
    }
}

function interExtratoHttp(array $config, string $method, string $url, array $headers = [], $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Extensao cURL nao esta habilitada no PHP.');
    }

    $curl = curl_init($url);
    interExtratoAplicarCertificado($curl, $config);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 90,
    ]);

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($curl);
    $erro = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('Erro cURL Inter Extrato: ' . $erro);
    }

    $json = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $mensagem = is_array($json)
            ? ($json['detail'] ?? $json['title'] ?? json_encode($json, JSON_UNESCAPED_UNICODE))
            : $response;
        throw new RuntimeException("Inter Extrato retornou HTTP {$status}: {$mensagem}");
    }

    return is_array($json) ? $json : [];
}

function obterTokenInterExtrato(PDO $pdo, int $empresaId, array $config): string
{
    if (!empty($config['token_cache']) && !empty($config['token_expira_em'])) {
        if (strtotime($config['token_expira_em']) > time() + 60) {
            return $config['token_cache'];
        }
    }

    foreach (['client_id', 'client_secret'] as $campo) {
        if (trim((string)($config[$campo] ?? '')) === '') {
            throw new RuntimeException('Client ID/Secret do Inter Extrato nao configurados.');
        }
    }

    $body = http_build_query([
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'grant_type' => 'client_credentials',
        'scope' => 'extrato.read',
    ]);

    $retorno = interExtratoHttp(
        $config,
        'POST',
        interExtratoBaseUrl($config, 'oauth') . '/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        $body
    );

    $token = (string)($retorno['access_token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('Token Inter Extrato nao retornado.');
    }

    $expiraEm = date('Y-m-d H:i:s', time() + (int)($retorno['expires_in'] ?? 3600));
    $pdo->prepare("
        UPDATE financeiro_inter_extrato_config
        SET token_cache = ?, token_expira_em = ?
        WHERE empresa_id = ?
    ")->execute([$token, $expiraEm, $empresaId]);

    return $token;
}

function interExtratoDataHoraMysql(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }

    try {
        return (new DateTime($valor))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function interExtratoValor($valor): float
{
    $valor = trim((string)$valor);
    $valor = str_replace(',', '.', $valor);
    return is_numeric($valor) ? (float)$valor : 0.0;
}

function interExtratoDetalhesTexto(array $transacao): string
{
    $detalhes = $transacao['detalhes'] ?? [];
    if (!is_array($detalhes)) {
        return '';
    }

    $nomes = [
        $detalhes['nomePagador'] ?? '',
        $detalhes['nomeRemetente'] ?? '',
        $detalhes['nomeDestinatario'] ?? '',
        $detalhes['nomeBeneficiario'] ?? '',
        $detalhes['pagador']['nome'] ?? '',
        $detalhes['recebedor']['nome'] ?? '',
    ];

    foreach ($nomes as $nome) {
        $nome = trim((string)$nome);
        if ($nome !== '') {
            return $nome;
        }
    }

    return '';
}

function listarInterExtrato(PDO $pdo, int $empresaId, string $dataIni, string $dataFim, ?array &$diagnostico = null): array
{
    $config = buscarConfigInterExtrato($pdo, $empresaId);
    if (!$config || ($config['ativo'] ?? 'N') !== 'S') {
        throw new RuntimeException('Configuracao da API Inter Extrato nao cadastrada ou inativa.');
    }

    $token = obterTokenInterExtrato($pdo, $empresaId, $config);
    $contaCorrente = preg_replace('/\D+/', '', (string)($config['conta_corrente'] ?? ''));
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];

    if ($contaCorrente !== '') {
        $headers[] = 'x-conta-corrente: ' . $contaCorrente;
        $headers[] = 'x-inter-conta-corrente: ' . $contaCorrente;
    }

    $pagina = 0;
    $tamanhoPagina = 1000;
    $transacoesNormalizadas = [];
    $diagnostico = [
        'endpoint' => '/extrato/completo',
        'paginas_lidas' => 0,
        'total_bruto' => 0,
        'total_normalizado' => 0,
        'total_elementos' => 0,
        'conta_corrente_enviada' => $contaCorrente !== '',
        'chaves_primeira_resposta' => [],
    ];

    do {
        $params = [
            'dataInicio' => $dataIni,
            'dataFim' => $dataFim,
            'pagina' => $pagina,
            'tamanhoPagina' => $tamanhoPagina,
        ];

        $url = interExtratoBaseUrl($config, 'banking') . '/extrato/completo?' . http_build_query($params);
        $retorno = interExtratoHttp($config, 'GET', $url, $headers);

        if ($pagina === 0) {
            $diagnostico['chaves_primeira_resposta'] = array_keys($retorno);
            $diagnostico['total_elementos'] = (int)($retorno['totalElementos'] ?? 0);
        }

        $lista = isset($retorno['transacoes']) && is_array($retorno['transacoes']) ? $retorno['transacoes'] : [];
        $diagnostico['paginas_lidas']++;
        $diagnostico['total_bruto'] += count($lista);

        foreach ($lista as $transacao) {
            if (!is_array($transacao)) {
                continue;
            }

            $dataMovimento = interExtratoDataHoraMysql((string)($transacao['dataTransacao'] ?? $transacao['dataInclusao'] ?? ''));
            $valor = abs(interExtratoValor($transacao['valor'] ?? 0));
            $tipo = strtoupper(substr((string)($transacao['tipoOperacao'] ?? ''), 0, 1));

            if ($dataMovimento === null || $valor <= 0 || !in_array($tipo, ['C', 'D'], true)) {
                continue;
            }

            $idTransacao = trim((string)($transacao['idTransacao'] ?? ''));
            $numeroDocumento = trim((string)($transacao['numeroDocumento'] ?? ''));
            $titulo = trim((string)($transacao['titulo'] ?? ''));
            $descricao = trim((string)($transacao['descricao'] ?? ''));
            $tipoTransacao = trim((string)($transacao['tipoTransacao'] ?? ''));
            $nomeDetalhe = interExtratoDetalhesTexto($transacao);

            $historico = trim(implode(' - ', array_filter([$titulo, $descricao, $nomeDetalhe])));
            if ($historico === '') {
                $historico = $tipoTransacao !== '' ? $tipoTransacao : 'INTER EXTRATO';
            }

            $identificador = $idTransacao !== ''
                ? 'INTER_EXTRATO_' . $idTransacao
                : sha1(json_encode($transacao, JSON_UNESCAPED_UNICODE));

            $transacoesNormalizadas[] = [
                'data_movimento' => $dataMovimento,
                'historico' => mb_substr($historico, 0, 500),
                'documento' => mb_substr($numeroDocumento !== '' ? $numeroDocumento : $idTransacao, 0, 120),
                'tipo' => $tipo,
                'valor' => $valor,
                'identificador' => $identificador,
                'tipo_transacao' => $tipoTransacao,
                'raw' => $transacao,
            ];
        }

        $ultimaPagina = (bool)($retorno['ultimaPagina'] ?? true);
        $pagina++;
    } while (!$ultimaPagina && $pagina < 100);

    $diagnostico['total_normalizado'] = count($transacoesNormalizadas);

    return $transacoesNormalizadas;
}
