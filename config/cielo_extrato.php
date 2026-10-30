<?php

if (defined('CIELO_EXTRATO_CONFIG_CARREGADO')) {
    return;
}

define('CIELO_EXTRATO_CONFIG_CARREGADO', true);

function cieloConfiguracaoVrs(): array
{
    $diretorioConfigurado = trim((string)getenv('SUPERDUNGA_CIELO_VRS_DIR'));
    $candidatos = [];

    if ($diretorioConfigurado !== '') {
        $candidatos[] = $diretorioConfigurado;
    }

    $appData = trim((string)getenv('APPDATA'));
    if ($appData !== '') {
        $candidatos[] = $appData . DIRECTORY_SEPARATOR . 'SuperDunga' . DIRECTORY_SEPARATOR
            . 'secrets' . DIRECTORY_SEPARATOR . 'cielo_api2_comercial_vrs';
    }

    $home = trim((string)getenv('HOME'));
    if ($home !== '') {
        $candidatos[] = $home . DIRECTORY_SEPARATOR . '.superdunga' . DIRECTORY_SEPARATOR
            . 'secrets' . DIRECTORY_SEPARATOR . 'cielo_api2_comercial_vrs';
    }

    // No cPanel, HOME pode nao estar disponivel para o processo do PHP.
    $candidatos[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.superdunga' . DIRECTORY_SEPARATOR
        . 'secrets' . DIRECTORY_SEPARATOR . 'cielo_api2_comercial_vrs';

    $diretorio = $diretorioConfigurado;
    foreach ($candidatos as $candidato) {
        if (is_dir($candidato)) {
            $diretorio = $candidato;
            break;
        }
    }

    return [
        'chave' => 'cielo_api2_comercial_vrs',
        'nome' => 'Cielo Comercial - VRS',
        'merchant_code' => '3003947763',
        'diretorio_credenciais' => $diretorio,
        'token_url' => 'https://api-internet.cielo.com.br/cielo-security-sys-web/oauth/v2/MulesoftPRD/protocol/openid-connect/token',
        'links_url' => 'https://api-internet.cielo.com.br/cielo-extc-serv-edi-link-exp-external/extc-serv-edi-link-external/v1/link/generate',
        'client_id' => 'client_id.txt',
        'client_secret' => 'access_token.txt',
        'hmac' => 'hmac_x_signature.txt',
        'certificado' => 'comercial-vrs-ltda-extc-prd-mulesoft-mtls.cer',
        'chave_privada' => 'comercial-vrs-ltda-extc-prd-mulesoft-mtls.key',
    ];
}

function cieloCaminhoCredencial(array $config, string $arquivo): string
{
    $diretorio = rtrim((string)$config['diretorio_credenciais'], '/\\');
    return $diretorio . DIRECTORY_SEPARATOR . $arquivo;
}

function cieloStatusCredenciais(array $config): array
{
    $faltantes = [];
    foreach (['client_id', 'client_secret', 'hmac', 'certificado', 'chave_privada'] as $campo) {
        $arquivo = cieloCaminhoCredencial($config, (string)$config[$campo]);
        if (!is_file($arquivo) || !is_readable($arquivo) || filesize($arquivo) === 0) {
            $faltantes[] = (string)$config[$campo];
        }
    }

    if (!function_exists('curl_init')) {
        $faltantes[] = 'extensao PHP cURL';
    }

    return [
        'disponivel' => empty($faltantes),
        'faltantes' => $faltantes,
        'diretorio' => (string)$config['diretorio_credenciais'],
    ];
}

function cieloLerSegredo(array $config, string $campo): string
{
    $arquivo = cieloCaminhoCredencial($config, (string)$config[$campo]);
    $conteudo = is_file($arquivo) ? file_get_contents($arquivo) : false;
    if ($conteudo === false || trim($conteudo) === '') {
        throw new RuntimeException('Credencial Cielo indisponivel: ' . (string)$config[$campo] . '.');
    }
    return trim($conteudo);
}

function cieloHttpPost(string $url, string $corpo, array $cabecalhos, array $config): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $corpo,
        CURLOPT_HTTPHEADER => $cabecalhos,
        CURLOPT_SSLCERT => cieloCaminhoCredencial($config, (string)$config['certificado']),
        CURLOPT_SSLKEY => cieloCaminhoCredencial($config, (string)$config['chave_privada']),
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 90,
    ]);

    $resposta = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        throw new RuntimeException('Falha de comunicacao com a Cielo: ' . $erro);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('A Cielo recusou a solicitacao (HTTP ' . $status . ').');
    }

    return (string)$resposta;
}

function cieloHttpGet(string $url): string
{
    if (stripos($url, 'https://') !== 0) {
        throw new RuntimeException('A Cielo retornou um endereco de download invalido.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
    ]);
    $resposta = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        throw new RuntimeException('Falha ao baixar arquivo Cielo: ' . $erro);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Falha ao baixar arquivo Cielo (HTTP ' . $status . ').');
    }

    return (string)$resposta;
}

function cieloGerarLinks(array $config, string $dataInicial, string $dataFinal): array
{
    $status = cieloStatusCredenciais($config);
    if (!$status['disponivel']) {
        throw new RuntimeException('Integracao Cielo nao configurada no servidor.');
    }

    $tokenResposta = cieloHttpPost(
        (string)$config['token_url'],
        http_build_query([
            'client_id' => cieloLerSegredo($config, 'client_id'),
            'client_secret' => cieloLerSegredo($config, 'client_secret'),
            'grant_type' => 'client_credentials',
        ]),
        ['Content-Type: application/x-www-form-urlencoded'],
        $config
    );
    $tokenDados = json_decode($tokenResposta, true);
    $token = is_array($tokenDados) ? trim((string)($tokenDados['access_token'] ?? '')) : '';
    if ($token === '') {
        throw new RuntimeException('A Cielo nao retornou o token de acesso.');
    }

    $corpo = json_encode([
        'merchantCode' => (string)$config['merchant_code'],
        'fileType' => [3, 4, 9, 15, 16],
        'processType' => ['D', 'R'],
        'startDate' => $dataInicial,
        'endDate' => $dataFinal,
    ], JSON_UNESCAPED_SLASHES);
    if ($corpo === false) {
        throw new RuntimeException('Falha ao preparar a consulta Cielo.');
    }

    $assinatura = base64_encode(hash_hmac(
        'sha256',
        $corpo,
        cieloLerSegredo($config, 'hmac'),
        true
    ));
    $linksResposta = cieloHttpPost(
        (string)$config['links_url'],
        $corpo,
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'X-Signature: ' . $assinatura,
        ],
        $config
    );
    $linksDados = json_decode($linksResposta, true);
    if (!is_array($linksDados) || !isset($linksDados['links']) || !is_array($linksDados['links'])) {
        throw new RuntimeException('A Cielo retornou uma resposta de arquivos invalida.');
    }

    $links = [];
    foreach ($linksDados['links'] as $item) {
        $link = '';
        if (is_string($item)) {
            $link = trim($item);
        } elseif (is_array($item)) {
            $link = trim((string)($item['link'] ?? $item['url'] ?? $item['downloadLink'] ?? ''));
        }
        if ($link !== '') {
            $links[] = $link;
        }
    }

    return $links;
}

function cieloCampo(string $linha, int $inicio, int $tamanho): string
{
    return trim(substr($linha, $inicio - 1, $tamanho));
}

function cieloDataDma(string $valor): ?string
{
    $valor = trim($valor);
    if (!preg_match('/^([0-3][0-9])([0-1][0-9])([0-9]{4})$/', $valor, $partes)) {
        return null;
    }
    if (!checkdate((int)$partes[2], (int)$partes[1], (int)$partes[3])) {
        return null;
    }
    return $partes[3] . '-' . $partes[2] . '-' . $partes[1];
}

function cieloDataAamMdd(string $valor): ?string
{
    $valor = trim($valor);
    if (!preg_match('/^([0-9]{2})([0-1][0-9])([0-3][0-9])$/', $valor, $partes)) {
        return null;
    }
    $ano = 2000 + (int)$partes[1];
    if (!checkdate((int)$partes[2], (int)$partes[3], $ano)) {
        return null;
    }
    return sprintf('%04d-%s-%s', $ano, $partes[2], $partes[3]);
}

function cieloDataHora(string $data, string $hora): string
{
    $hora = preg_match('/^[0-2][0-9][0-5][0-9][0-5][0-9]$/', $hora) ? $hora : '000000';
    return $data . ' ' . substr($hora, 0, 2) . ':' . substr($hora, 2, 2) . ':' . substr($hora, 4, 2);
}

function cieloValor(string $sinal, string $digitos): float
{
    $digitos = preg_replace('/\D/', '', $digitos);
    $valor = $digitos === '' ? 0.0 : ((float)$digitos / 100);
    return $sinal === '-' ? -$valor : $valor;
}

function cieloIdentificador(string $base): string
{
    return 'CIELO_' . substr(hash('sha256', $base), 0, 58);
}

function cieloRegistroVenda(string $linha, array $regra, array $cabecalho): ?array
{
    if (strlen($linha) < 637 || substr($linha, 0, 1) !== 'E') {
        return null;
    }

    $tipoLancamento = cieloCampo($linha, 28, 2);
    if (!in_array($tipoLancamento, ['01', '02', '03'], true)) {
        return null;
    }
    if (cieloCampo($linha, 164, 1) === 'S') {
        return null;
    }

    if ($tipoLancamento === '01') {
        $cm = (int)$regra['cm_debito'];
        $tipoOperacao = 'D';
        $modalidade = 'DEBITO';
    } else {
        $cm = (int)$regra['cm_credito'];
        $tipoOperacao = 'C';
        $modalidade = $tipoLancamento === '03' ? 'CREDITO PARCELADO' : 'CREDITO';
    }
    if ($cm <= 0) {
        return null;
    }

    $dataVenda = cieloDataDma(cieloCampo($linha, 566, 8));
    $dataPrevista = cieloDataDma(cieloCampo($linha, 630, 8));
    $codigoTransacao = cieloCampo($linha, 130, 22);
    if ($dataVenda === null || $dataPrevista === null || $codigoTransacao === '') {
        return null;
    }

    $valorBruto = cieloValor(cieloCampo($linha, 261, 1), cieloCampo($linha, 262, 13));
    $valorLiquido = cieloValor(cieloCampo($linha, 275, 1), cieloCampo($linha, 276, 13));
    if ($valorBruto <= 0 || $valorLiquido < 0) {
        return null;
    }

    $parcela = max(1, (int)cieloCampo($linha, 18, 2));
    $totalParcelas = max(1, (int)cieloCampo($linha, 20, 2));
    $bandeira = cieloCampo($linha, 493, 3);
    if ($bandeira === '') {
        $bandeira = cieloCampo($linha, 12, 3);
    }
    $nsu = cieloCampo($linha, 176, 6);
    $autorizacao = cieloCampo($linha, 22, 6);
    $terminal = cieloCampo($linha, 544, 8);
    $identificador = cieloIdentificador(implode('|', [
        $cabecalho['matriz'],
        $codigoTransacao,
        (string)$parcela,
        $dataPrevista,
        $tipoLancamento,
    ]));

    return [
        'origem' => (string)$regra['origem'],
        'data_venda' => cieloDataHora($dataVenda, cieloCampo($linha, 471, 6)),
        'data_prevista' => $dataPrevista,
        'valor_bruto' => round($valorBruto, 2),
        'valor_desconto' => round(max(0, $valorBruto - $valorLiquido), 2),
        'valor_liquido' => round($valorLiquido, 2),
        'identificador' => $identificador,
        'descricao' => 'CIELO - ' . $modalidade . ' - BANDEIRA ' . $bandeira,
        'pagador' => 'CIELO',
        'parcela' => $parcela,
        'total_parcelas' => $totalParcelas,
        'status' => 'APROVADA',
        'cm' => $cm,
        'tipo_operacao' => $tipoOperacao,
        'bandeira' => substr('CIELO ' . $bandeira, 0, 20),
        'nsu' => $nsu,
        'autorizacao' => $autorizacao,
        'terminal' => $terminal,
        'id_transacao' => substr($codigoTransacao, 0, 50),
    ];
}

function cieloRegistroPix(string $linha, array $regra, array $cabecalho): ?array
{
    if (strlen($linha) < 224 || substr($linha, 0, 1) !== '8') {
        return null;
    }
    if (cieloCampo($linha, 12, 2) !== '01' || (int)$regra['cm_pix'] <= 0) {
        return null;
    }

    $dataVenda = cieloDataAamMdd(cieloCampo($linha, 14, 6));
    $dataPrevista = cieloDataAamMdd(cieloCampo($linha, 68, 6));
    $idPix = cieloCampo($linha, 26, 36);
    if ($dataVenda === null || $dataPrevista === null || $idPix === '') {
        return null;
    }

    $valorBruto = cieloValor(cieloCampo($linha, 74, 1), cieloCampo($linha, 75, 13));
    $valorLiquido = cieloValor(cieloCampo($linha, 102, 1), cieloCampo($linha, 103, 13));
    if ($valorBruto <= 0 || $valorLiquido < 0) {
        return null;
    }

    $statusTransferencia = cieloCampo($linha, 223, 2);
    $identificador = cieloIdentificador(implode('|', [
        $cabecalho['matriz'],
        'PIX',
        $idPix,
    ]));

    return [
        'origem' => (string)$regra['origem'],
        'data_venda' => cieloDataHora($dataVenda, cieloCampo($linha, 20, 6)),
        'data_prevista' => $dataPrevista,
        'valor_bruto' => round($valorBruto, 2),
        'valor_desconto' => round(max(0, $valorBruto - $valorLiquido), 2),
        'valor_liquido' => round($valorLiquido, 2),
        'identificador' => $identificador,
        'descricao' => 'CIELO - PIX',
        'pagador' => 'CIELO',
        'parcela' => 1,
        'total_parcelas' => 1,
        'status' => in_array($statusTransferencia, ['01', '05'], true) ? 'PAGO' : 'PROCESSADO',
        'cm' => (int)$regra['cm_pix'],
        'tipo_operacao' => 'P',
        'bandeira' => 'PIX',
        'nsu' => cieloCampo($linha, 62, 6),
        'autorizacao' => '',
        'terminal' => cieloCampo($linha, 162, 8),
        'id_transacao' => substr($idPix, 0, 50),
    ];
}

function cieloLerArquivo(string $conteudo, array $regra): array
{
    $linhas = preg_split('/\r\n|\r|\n/', $conteudo, -1, PREG_SPLIT_NO_EMPTY);
    if (!$linhas || substr((string)$linhas[0], 0, 1) !== '0') {
        throw new RuntimeException('Arquivo Cielo sem header valido.');
    }

    $header = (string)$linhas[0];
    $cabecalho = [
        'matriz' => cieloCampo($header, 2, 10),
        'data_processamento' => cieloCampo($header, 12, 8),
        'periodo_inicial' => cieloCampo($header, 20, 8),
        'periodo_final' => cieloCampo($header, 28, 8),
        'sequencia' => cieloCampo($header, 36, 7),
        'tipo_arquivo' => cieloCampo($header, 48, 2),
        'versao' => cieloCampo($header, 71, 3),
    ];
    if ($cabecalho['versao'] !== '015') {
        throw new RuntimeException('Layout Cielo nao suportado: versao ' . $cabecalho['versao'] . '.');
    }

    $registros = [];
    $ignorados = 0;
    foreach ($linhas as $linha) {
        $registro = null;
        if ($cabecalho['tipo_arquivo'] === '03' && substr($linha, 0, 1) === 'E') {
            $registro = cieloRegistroVenda($linha, $regra, $cabecalho);
            if ($registro === null) {
                $ignorados++;
            }
        } elseif ($cabecalho['tipo_arquivo'] === '16' && substr($linha, 0, 1) === '8') {
            $registro = cieloRegistroPix($linha, $regra, $cabecalho);
            if ($registro === null) {
                $ignorados++;
            }
        }
        if ($registro !== null) {
            $registros[$registro['identificador']] = $registro;
        }
    }

    return [
        'cabecalho' => $cabecalho,
        'registros' => array_values($registros),
        'ignorados' => $ignorados,
        'total_linhas' => max(0, count($linhas) - 2),
    ];
}

function garantirTabelasCielo(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fechamento_cielo_sincronizacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            integracao VARCHAR(60) NOT NULL,
            data_inicial DATE NOT NULL,
            data_final DATE NOT NULL,
            status VARCHAR(20) NOT NULL,
            arquivos_recebidos INT NOT NULL DEFAULT 0,
            arquivos_duplicados INT NOT NULL DEFAULT 0,
            registros_importados INT NOT NULL DEFAULT 0,
            registros_atualizados INT NOT NULL DEFAULT 0,
            registros_ignorados INT NOT NULL DEFAULT 0,
            mensagem VARCHAR(500) NULL,
            iniciado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finalizado_em DATETIME NULL,
            INDEX idx_cielo_sync_empresa (empresa_id, integracao, iniciado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fechamento_cielo_arquivos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            integracao VARCHAR(60) NOT NULL,
            tipo_arquivo VARCHAR(2) NOT NULL,
            data_processamento CHAR(8) NOT NULL,
            periodo_inicial CHAR(8) NOT NULL,
            periodo_final CHAR(8) NOT NULL,
            sequencia VARCHAR(7) NOT NULL,
            sha256 CHAR(64) NOT NULL,
            quantidade_bytes INT NOT NULL,
            registros_importados INT NOT NULL DEFAULT 0,
            registros_atualizados INT NOT NULL DEFAULT 0,
            registros_ignorados INT NOT NULL DEFAULT 0,
            processado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY ux_cielo_arquivo_hash (empresa_id, integracao, sha256),
            INDEX idx_cielo_arquivo_periodo (empresa_id, integracao, periodo_inicial, periodo_final)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function cieloValidarPeriodo(string $dataInicial, string $dataFinal): void
{
    $inicial = DateTime::createFromFormat('!Y-m-d', $dataInicial);
    $final = DateTime::createFromFormat('!Y-m-d', $dataFinal);
    if (!$inicial || !$final || $inicial->format('Y-m-d') !== $dataInicial || $final->format('Y-m-d') !== $dataFinal) {
        throw new RuntimeException('Informe um periodo valido para a sincronizacao.');
    }
    if ($final < $inicial) {
        throw new RuntimeException('A data final nao pode ser anterior a data inicial.');
    }
    if ((int)$inicial->diff($final)->format('%a') > 31) {
        throw new RuntimeException('Consulte no maximo 32 dias por sincronizacao.');
    }
}

function cieloSincronizarRecebimentos(
    PDO $pdo,
    int $empresaId,
    array $regra,
    array $config,
    string $dataInicial,
    string $dataFinal
): array {
    cieloValidarPeriodo($dataInicial, $dataFinal);
    garantirTabelasCielo($pdo);

    $stmtInicio = $pdo->prepare("
        INSERT INTO fechamento_cielo_sincronizacoes (
            empresa_id, integracao, data_inicial, data_final, status
        ) VALUES (?, ?, ?, ?, 'EXECUTANDO')
    ");
    $stmtInicio->execute([$empresaId, $config['chave'], $dataInicial, $dataFinal]);
    $sincronizacaoId = (int)$pdo->lastInsertId();

    $resultado = [
        'arquivos_recebidos' => 0,
        'arquivos_duplicados' => 0,
        'registros_importados' => 0,
        'registros_atualizados' => 0,
        'registros_ignorados' => 0,
    ];

    try {
        $links = cieloGerarLinks($config, $dataInicial, $dataFinal);
        $resultado['arquivos_recebidos'] = count($links);

        $verificarArquivo = $pdo->prepare("
            SELECT id FROM fechamento_cielo_arquivos
            WHERE empresa_id = ? AND integracao = ? AND sha256 = ?
            LIMIT 1
        ");
        $verificarRecebivel = $pdo->prepare("
            SELECT id FROM armazem_conciliacao_recebimentos
            WHERE empresa_id = ? AND identificador = ?
            LIMIT 1
        ");
        $inserirRecebivel = $pdo->prepare("
            INSERT INTO armazem_conciliacao_recebimentos (
                empresa_id, origem, data_venda, data_prevista, valor_bruto, valor_desconto,
                valor_liquido, identificador, descricao, pagador, parcela, total_parcelas,
                status, arquivo_origem, CMCONTADOR, tipo_operacao, bandeira, nsu_transacao,
                autorizacao, numero_estabelecimento, id_transacao
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $atualizarRecebivel = $pdo->prepare("
            UPDATE armazem_conciliacao_recebimentos
            SET origem = ?, data_venda = ?, data_prevista = ?, valor_bruto = ?,
                valor_desconto = ?, valor_liquido = ?, descricao = ?, pagador = ?,
                parcela = ?, total_parcelas = ?, status = ?, arquivo_origem = ?,
                CMCONTADOR = ?, tipo_operacao = ?, bandeira = ?, nsu_transacao = ?,
                autorizacao = ?, numero_estabelecimento = ?, id_transacao = ?
            WHERE id = ? AND empresa_id = ?
        ");
        $inserirArquivo = $pdo->prepare("
            INSERT INTO fechamento_cielo_arquivos (
                empresa_id, integracao, tipo_arquivo, data_processamento,
                periodo_inicial, periodo_final, sequencia, sha256, quantidade_bytes,
                registros_importados, registros_atualizados, registros_ignorados
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($links as $link) {
            $conteudo = cieloHttpGet($link);
            $hash = hash('sha256', $conteudo);
            $verificarArquivo->execute([$empresaId, $config['chave'], $hash]);
            if ($verificarArquivo->fetchColumn()) {
                $resultado['arquivos_duplicados']++;
                continue;
            }

            $arquivo = cieloLerArquivo($conteudo, $regra);
            $cabecalho = $arquivo['cabecalho'];
            $nomeArquivo = 'CIELO' . $cabecalho['tipo_arquivo'] . '_'
                . $cabecalho['periodo_final'] . '_' . $cabecalho['sequencia'] . '.txt';
            $importadosArquivo = 0;
            $atualizadosArquivo = 0;

            $pdo->beginTransaction();
            try {
                foreach ($arquivo['registros'] as $registro) {
                    if (empty($registro['data_prevista'])) {
                        $arquivo['ignorados']++;
                        continue;
                    }

                    $verificarRecebivel->execute([$empresaId, $registro['identificador']]);
                    $recebivelId = (int)$verificarRecebivel->fetchColumn();
                    if ($recebivelId > 0) {
                        $atualizarRecebivel->execute([
                            $registro['origem'], $registro['data_venda'], $registro['data_prevista'],
                            $registro['valor_bruto'], $registro['valor_desconto'], $registro['valor_liquido'],
                            $registro['descricao'], $registro['pagador'], $registro['parcela'],
                            $registro['total_parcelas'], $registro['status'], $nomeArquivo,
                            $registro['cm'], $registro['tipo_operacao'], $registro['bandeira'],
                            $registro['nsu'], $registro['autorizacao'], $cabecalho['matriz'],
                            $registro['id_transacao'], $recebivelId, $empresaId,
                        ]);
                        $atualizadosArquivo++;
                    } else {
                        $inserirRecebivel->execute([
                            $empresaId, $registro['origem'], $registro['data_venda'],
                            $registro['data_prevista'], $registro['valor_bruto'],
                            $registro['valor_desconto'], $registro['valor_liquido'],
                            $registro['identificador'], $registro['descricao'], $registro['pagador'],
                            $registro['parcela'], $registro['total_parcelas'], $registro['status'],
                            $nomeArquivo, $registro['cm'], $registro['tipo_operacao'],
                            $registro['bandeira'], $registro['nsu'], $registro['autorizacao'],
                            $cabecalho['matriz'], $registro['id_transacao'],
                        ]);
                        $importadosArquivo++;
                    }
                }

                $inserirArquivo->execute([
                    $empresaId, $config['chave'], $cabecalho['tipo_arquivo'],
                    $cabecalho['data_processamento'], $cabecalho['periodo_inicial'],
                    $cabecalho['periodo_final'], $cabecalho['sequencia'], $hash,
                    strlen($conteudo), $importadosArquivo, $atualizadosArquivo,
                    (int)$arquivo['ignorados'],
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            $resultado['registros_importados'] += $importadosArquivo;
            $resultado['registros_atualizados'] += $atualizadosArquivo;
            $resultado['registros_ignorados'] += (int)$arquivo['ignorados'];
        }

        $mensagem = count($links) === 0
            ? 'A Cielo nao disponibilizou arquivos para o periodo.'
            : 'Sincronizacao concluida.';
        $stmtFim = $pdo->prepare("
            UPDATE fechamento_cielo_sincronizacoes
            SET status = 'CONCLUIDA', arquivos_recebidos = ?, arquivos_duplicados = ?,
                registros_importados = ?, registros_atualizados = ?, registros_ignorados = ?,
                mensagem = ?, finalizado_em = NOW()
            WHERE id = ?
        ");
        $stmtFim->execute([
            $resultado['arquivos_recebidos'], $resultado['arquivos_duplicados'],
            $resultado['registros_importados'], $resultado['registros_atualizados'],
            $resultado['registros_ignorados'], $mensagem, $sincronizacaoId,
        ]);
        $resultado['mensagem'] = $mensagem;
        return $resultado;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $mensagemErro = substr($e->getMessage(), 0, 500);
        $stmtErro = $pdo->prepare("
            UPDATE fechamento_cielo_sincronizacoes
            SET status = 'ERRO', mensagem = ?, finalizado_em = NOW()
            WHERE id = ?
        ");
        $stmtErro->execute([$mensagemErro, $sincronizacaoId]);
        throw $e;
    }
}

