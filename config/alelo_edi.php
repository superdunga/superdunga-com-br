<?php

if (defined('ALELO_EDI_CONFIG_CARREGADO')) {
    return;
}

define('ALELO_EDI_CONFIG_CARREGADO', true);

require_once dirname(__DIR__) . '/lib/phpseclib2/autoload.php';

use phpseclib\Net\SFTP;

function aleloDiretorioCredenciais(): string
{
    $configurado = trim((string)getenv('SUPERDUNGA_ALELO_EMPRESA1_DIR'));
    $candidatos = [];
    if ($configurado !== '') {
        $candidatos[] = $configurado;
    }

    $appData = trim((string)getenv('APPDATA'));
    if ($appData !== '') {
        $candidatos[] = $appData . DIRECTORY_SEPARATOR . 'SuperDunga' . DIRECTORY_SEPARATOR
            . 'secrets' . DIRECTORY_SEPARATOR . 'alelo_empresa1';
    }

    $home = trim((string)getenv('HOME'));
    if ($home !== '') {
        $candidatos[] = $home . DIRECTORY_SEPARATOR . '.superdunga' . DIRECTORY_SEPARATOR
            . 'secrets' . DIRECTORY_SEPARATOR . 'alelo_empresa1';
    }

    $candidatos[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.superdunga' . DIRECTORY_SEPARATOR
        . 'secrets' . DIRECTORY_SEPARATOR . 'alelo_empresa1';

    foreach ($candidatos as $candidato) {
        if (is_dir($candidato)) {
            return $candidato;
        }
    }

    return $configurado;
}

function aleloConfiguracaoEmpresa1(): array
{
    return [
        'chave' => 'alelo_edi_empresa1_comercial',
        'nome' => 'ALELO Comercial',
        'diretorio_credenciais' => aleloDiretorioCredenciais(),
        'arquivo_credenciais' => 'credentials.txt',
        'diretorio_remoto' => '.',
        'host_key_sha256' => '915fbeb032ceb238b4518aa412cf7e268687672d0ba4b306c1f9f391c4770c8a',
    ];
}

function aleloCaminhoCredenciais(array $config): string
{
    return rtrim((string)$config['diretorio_credenciais'], '/\\') . DIRECTORY_SEPARATOR
        . (string)$config['arquivo_credenciais'];
}

function aleloNormalizarChave(string $chave): string
{
    $chave = strtolower(trim($chave));
    $chave = strtr($chave, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
        'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o',
        'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
    ]);
    return preg_replace('/[^a-z0-9]/', '', $chave);
}

function aleloLerCredenciais(array $config): array
{
    $arquivo = aleloCaminhoCredenciais($config);
    $conteudo = is_file($arquivo) ? file_get_contents($arquivo) : false;
    if ($conteudo === false || trim($conteudo) === '') {
        throw new RuntimeException('Credenciais ALELO nao instaladas neste servidor.');
    }

    $dados = [];
    foreach (preg_split('/\r\n|\r|\n/', $conteudo) as $linha) {
        if (!preg_match('/^\s*([^:=]+)\s*[:=]\s*(.*?)\s*$/u', $linha, $partes)) {
            continue;
        }
        $dados[aleloNormalizarChave($partes[1])] = trim($partes[2]);
    }

    $host = $dados['host'] ?? $dados['servidor'] ?? '';
    $porta = (int)($dados['porta'] ?? $dados['port'] ?? 22);
    $usuario = $dados['usuario'] ?? $dados['user'] ?? '';
    $senha = $dados['senha'] ?? $dados['password'] ?? '';
    if ($host === '' || $porta <= 0 || $usuario === '' || $senha === '') {
        throw new RuntimeException('Arquivo de credenciais ALELO incompleto.');
    }

    return ['host' => $host, 'porta' => $porta, 'usuario' => $usuario, 'senha' => $senha];
}

function aleloStatusCredenciais(array $config): array
{
    $faltantes = [];
    $arquivo = aleloCaminhoCredenciais($config);
    if (!is_file($arquivo) || !is_readable($arquivo) || filesize($arquivo) === 0) {
        $faltantes[] = (string)$config['arquivo_credenciais'];
    }
    if (!class_exists('phpseclib\\Net\\SFTP')) {
        $faltantes[] = 'biblioteca SFTP';
    }

    return [
        'disponivel' => empty($faltantes),
        'faltantes' => $faltantes,
        'diretorio' => (string)$config['diretorio_credenciais'],
    ];
}

function aleloConectar(array $config): SFTP
{
    $credenciais = aleloLerCredenciais($config);
    $sftp = new SFTP($credenciais['host'], $credenciais['porta'], 20);
    if (!$sftp->login($credenciais['usuario'], $credenciais['senha'])) {
        throw new RuntimeException('A ALELO recusou as credenciais SFTP.');
    }

    $chaveServidor = $sftp->getServerPublicHostKey();
    if (!is_string($chaveServidor) || $chaveServidor === '') {
        throw new RuntimeException('Nao foi possivel validar a identidade do servidor ALELO.');
    }
    $partesChave = preg_split('/\s+/', trim($chaveServidor));
    $chaveBinaria = isset($partesChave[1]) ? base64_decode($partesChave[1], true) : false;
    if ($chaveBinaria === false) {
        throw new RuntimeException('Formato da identidade do servidor ALELO nao reconhecido.');
    }
    $fingerprint = hash('sha256', $chaveBinaria);
    if (!hash_equals(strtolower((string)$config['host_key_sha256']), strtolower($fingerprint))) {
        throw new RuntimeException('A identidade do servidor SFTP ALELO mudou. Sincronizacao interrompida.');
    }

    return $sftp;
}

function aleloCampo(string $linha, int $inicio, int $tamanho): string
{
    return trim(substr($linha, $inicio - 1, $tamanho));
}

function aleloData(string $valor): ?string
{
    $valor = trim($valor);
    $data = DateTime::createFromFormat('!Ymd', $valor);
    return $data && $data->format('Ymd') === $valor ? $data->format('Y-m-d') : null;
}

function aleloDataHora(string $data, string $hora): string
{
    $hora = preg_match('/^\d{6}$/', $hora) ? $hora : '000000';
    return $data . ' ' . substr($hora, 0, 2) . ':' . substr($hora, 2, 2) . ':' . substr($hora, 4, 2);
}

function aleloValor(string $sinal, string $valor): float
{
    $numero = ((float)preg_replace('/\D/', '', $valor)) / 1000000;
    return trim($sinal) === '-' ? -$numero : $numero;
}

function aleloNomeProduto(string $codigo): string
{
    $produtos = [
        '45' => 'REFEICAO AUXILIO', '46' => 'ALIMENTACAO AUXILIO',
        '47' => 'REFEICAO PAT', '48' => 'ALIMENTACAO PAT',
        '57' => 'MULTIBENEFICIOS SALDO', '58' => 'MULTIBENEFICIOS LIMITE',
        '59' => 'VELOE GO', '60' => 'NATAL', '61' => 'CULTURA',
    ];
    $normalizado = ltrim($codigo, '0');
    return $produtos[$normalizado] ?? ('PRODUTO ' . $codigo);
}

function aleloLerArquivo(string $conteudo, array $regra): array
{
    $linhas = preg_split('/\r\n|\r|\n/', $conteudo, -1, PREG_SPLIT_NO_EMPTY);
    if (!$linhas || strlen((string)$linhas[0]) !== 500 || aleloCampo((string)$linhas[0], 1, 2) !== '00') {
        throw new RuntimeException('Arquivo ALELO sem header valido de 500 posicoes.');
    }

    $header = (string)$linhas[0];
    $cabecalho = [
        'agrupamento' => aleloCampo($header, 3, 15),
        'data_processamento' => aleloCampo($header, 18, 8),
        'periodo_inicial' => aleloCampo($header, 26, 8),
        'periodo_final' => aleloCampo($header, 34, 8),
        'sequencia' => aleloCampo($header, 43, 7),
        'adquirente' => aleloCampo($header, 50, 5),
        'tipo_arquivo' => aleloCampo($header, 55, 2),
        'versao' => aleloCampo($header, 107, 5),
        'cnpj_adquirente' => aleloCampo($header, 112, 14),
    ];
    if ($cabecalho['adquirente'] !== 'ALELO') {
        throw new RuntimeException('Arquivo nao pertence a ALELO.');
    }
    if (!in_array($cabecalho['tipo_arquivo'], ['01', '02'], true)) {
        throw new RuntimeException('Tipo de arquivo ALELO ainda nao suportado: ' . $cabecalho['tipo_arquivo'] . '.');
    }

    $registros = [];
    $pagamentos = [];
    $ignorados = 0;
    foreach ($linhas as $linha) {
        if (strlen($linha) !== 500) {
            $ignorados++;
            continue;
        }
        $tipoRegistro = aleloCampo($linha, 1, 2);
        if ($tipoRegistro === '01' && $cabecalho['tipo_arquivo'] === '01') {
            $tipoMovimento = aleloCampo($linha, 71, 3);
            if ($tipoMovimento !== '01') {
                $ignorados++;
                continue;
            }
            $dataVenda = aleloData(aleloCampo($linha, 111, 8));
            $dataPrevista = aleloData(aleloCampo($linha, 125, 8));
            $valorBruto = aleloValor(aleloCampo($linha, 133, 1), aleloCampo($linha, 134, 13));
            $valorLiquido = aleloValor(aleloCampo($linha, 147, 1), aleloCampo($linha, 148, 13));
            $idMovimento = aleloCampo($linha, 279, 18);
            if ($dataVenda === null || $dataPrevista === null || $valorBruto <= 0 || $valorLiquido < 0) {
                $ignorados++;
                continue;
            }
            $baseIdentificador = implode('|', [
                $cabecalho['agrupamento'], aleloCampo($linha, 3, 30), $idMovimento,
                aleloCampo($linha, 250, 6), $dataVenda, number_format($valorBruto, 6, '.', ''),
            ]);
            $identificador = 'ALELO_' . hash('sha256', $baseIdentificador);
            $produto = aleloNomeProduto(aleloCampo($linha, 201, 4));
            $registros[$identificador] = [
                'origem' => (string)$regra['origem'],
                'data_venda' => aleloDataHora($dataVenda, aleloCampo($linha, 119, 6)),
                'data_prevista' => $dataPrevista,
                'valor_bruto' => round($valorBruto, 2),
                'valor_desconto' => round(max(0, $valorBruto - $valorLiquido), 2),
                'valor_liquido' => round($valorLiquido, 2),
                'identificador' => $identificador,
                'descricao' => 'ALELO - ' . $produto,
                'pagador' => 'ALELO',
                'parcela' => 1,
                'total_parcelas' => 1,
                'status' => 'APROVADA',
                'cm' => (int)$regra['cm_debito'],
                'tipo_operacao' => 'D',
                'bandeira' => 'ALELO',
                'nsu' => aleloCampo($linha, 250, 6),
                'autorizacao' => aleloCampo($linha, 242, 8),
                'estabelecimento' => aleloCampo($linha, 48, 15),
                'id_transacao' => substr($idMovimento !== '' ? $idMovimento : hash('sha256', $baseIdentificador), 0, 50),
            ];
        } elseif ($tipoRegistro === '02' && $cabecalho['tipo_arquivo'] === '02') {
            $chavePagamento = aleloCampo($linha, 41, 30);
            $dataPagamento = aleloData(aleloCampo($linha, 89, 8));
            if ($chavePagamento === '' || $dataPagamento === null) {
                $ignorados++;
                continue;
            }
            $pagamentos[$chavePagamento] = [
                'chave_pagamento' => $chavePagamento,
                'estabelecimento' => aleloCampo($linha, 3, 15),
                'matriz_pagamento' => aleloCampo($linha, 26, 15),
                'tipo_pagamento' => aleloCampo($linha, 71, 1),
                'status_pagamento' => aleloCampo($linha, 72, 3),
                'data_original' => aleloData(aleloCampo($linha, 81, 8)),
                'data_pagamento' => $dataPagamento,
                'data_envio_banco' => aleloData(aleloCampo($linha, 97, 8)),
                'valor' => round(aleloValor(aleloCampo($linha, 105, 1), aleloCampo($linha, 106, 18)), 2),
            ];
        }
    }

    return [
        'cabecalho' => $cabecalho,
        'registros' => array_values($registros),
        'pagamentos' => array_values($pagamentos),
        'ignorados' => $ignorados,
        'total_linhas' => max(0, count($linhas) - 2),
    ];
}

function aleloValidarPeriodo(string $dataInicial, string $dataFinal): void
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

function garantirTabelasAlelo(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS fechamento_alelo_sincronizacoes (
        id INT AUTO_INCREMENT PRIMARY KEY, empresa_id INT NOT NULL, integracao VARCHAR(60) NOT NULL,
        data_inicial DATE NOT NULL, data_final DATE NOT NULL, status VARCHAR(20) NOT NULL,
        arquivos_recebidos INT NOT NULL DEFAULT 0, arquivos_duplicados INT NOT NULL DEFAULT 0,
        registros_importados INT NOT NULL DEFAULT 0, registros_atualizados INT NOT NULL DEFAULT 0,
        pagamentos_processados INT NOT NULL DEFAULT 0, registros_ignorados INT NOT NULL DEFAULT 0,
        mensagem VARCHAR(500) NULL, iniciado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finalizado_em DATETIME NULL, INDEX idx_alelo_sync_empresa (empresa_id, integracao, iniciado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fechamento_alelo_arquivos (
        id INT AUTO_INCREMENT PRIMARY KEY, empresa_id INT NOT NULL, integracao VARCHAR(60) NOT NULL,
        nome_arquivo VARCHAR(255) NOT NULL, tipo_arquivo VARCHAR(2) NOT NULL,
        data_processamento CHAR(8) NOT NULL, periodo_inicial CHAR(8) NOT NULL,
        periodo_final CHAR(8) NOT NULL, sequencia VARCHAR(7) NOT NULL, sha256 CHAR(64) NOT NULL,
        quantidade_bytes INT NOT NULL, registros_importados INT NOT NULL DEFAULT 0,
        registros_atualizados INT NOT NULL DEFAULT 0, pagamentos_processados INT NOT NULL DEFAULT 0,
        registros_ignorados INT NOT NULL DEFAULT 0, processado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_alelo_arquivo_hash (empresa_id, integracao, sha256),
        INDEX idx_alelo_arquivo_periodo (empresa_id, integracao, periodo_inicial, periodo_final)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fechamento_alelo_pagamentos (
        id INT AUTO_INCREMENT PRIMARY KEY, empresa_id INT NOT NULL, chave_pagamento VARCHAR(30) NOT NULL,
        estabelecimento VARCHAR(15) NULL, matriz_pagamento VARCHAR(15) NULL,
        tipo_pagamento CHAR(1) NULL, status_pagamento VARCHAR(3) NULL,
        data_original DATE NULL, data_pagamento DATE NULL, data_envio_banco DATE NULL,
        valor DECIMAL(15,2) NOT NULL DEFAULT 0, arquivo_origem VARCHAR(255) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY ux_alelo_pagamento (empresa_id, chave_pagamento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function aleloListarArquivos(SFTP $sftp, array $config, string $dataInicial, string $dataFinal): array
{
    $lista = $sftp->nlist((string)$config['diretorio_remoto']);
    if (!is_array($lista)) {
        throw new RuntimeException('Nao foi possivel listar a caixa postal SFTP da ALELO.');
    }
    $inicial = str_replace('-', '', $dataInicial);
    $final = str_replace('-', '', $dataFinal);
    $arquivos = [];
    foreach ($lista as $nome) {
        $nome = basename((string)$nome);
        if (!preg_match('/^(\d{8})_\d{4}_ALELO_(01|02)_[A-Z0-9_]+\.txt$/i', $nome, $partes)) {
            continue;
        }
        if ($partes[1] >= $inicial && $partes[1] <= $final) {
            $arquivos[] = $nome;
        }
    }
    sort($arquivos, SORT_STRING);
    return $arquivos;
}

function aleloSincronizarRecebimentos(PDO $pdo, int $empresaId, array $regra, array $config, string $dataInicial, string $dataFinal): array
{
    aleloValidarPeriodo($dataInicial, $dataFinal);
    garantirTabelasAlelo($pdo);

    $stmtInicio = $pdo->prepare("INSERT INTO fechamento_alelo_sincronizacoes
        (empresa_id, integracao, data_inicial, data_final, status) VALUES (?, ?, ?, ?, 'EXECUTANDO')");
    $stmtInicio->execute([$empresaId, $config['chave'], $dataInicial, $dataFinal]);
    $sincronizacaoId = (int)$pdo->lastInsertId();
    $resultado = ['arquivos_recebidos' => 0, 'arquivos_duplicados' => 0, 'registros_importados' => 0,
        'registros_atualizados' => 0, 'pagamentos_processados' => 0, 'registros_ignorados' => 0];

    try {
        $sftp = aleloConectar($config);
        $arquivos = aleloListarArquivos($sftp, $config, $dataInicial, $dataFinal);
        $resultado['arquivos_recebidos'] = count($arquivos);
        $verificarArquivo = $pdo->prepare('SELECT id FROM fechamento_alelo_arquivos WHERE empresa_id = ? AND integracao = ? AND sha256 = ? LIMIT 1');
        $verificarRecebivel = $pdo->prepare('SELECT id FROM armazem_conciliacao_recebimentos WHERE empresa_id = ? AND identificador = ? LIMIT 1');
        $inserirRecebivel = $pdo->prepare("INSERT INTO armazem_conciliacao_recebimentos
            (empresa_id, origem, data_venda, data_prevista, valor_bruto, valor_desconto, valor_liquido,
             identificador, descricao, pagador, parcela, total_parcelas, status, arquivo_origem,
             CMCONTADOR, tipo_operacao, bandeira, nsu_transacao, autorizacao, numero_estabelecimento, id_transacao)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $atualizarRecebivel = $pdo->prepare("UPDATE armazem_conciliacao_recebimentos SET
            origem=?, data_venda=?, data_prevista=?, valor_bruto=?, valor_desconto=?, valor_liquido=?,
            descricao=?, pagador=?, parcela=?, total_parcelas=?, status=?, arquivo_origem=?, CMCONTADOR=?,
            tipo_operacao=?, bandeira=?, nsu_transacao=?, autorizacao=?, numero_estabelecimento=?, id_transacao=?
            WHERE id=? AND empresa_id=?");
        $salvarPagamento = $pdo->prepare("INSERT INTO fechamento_alelo_pagamentos
            (empresa_id, chave_pagamento, estabelecimento, matriz_pagamento, tipo_pagamento,
             status_pagamento, data_original, data_pagamento, data_envio_banco, valor, arquivo_origem)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE estabelecimento=VALUES(estabelecimento), matriz_pagamento=VALUES(matriz_pagamento),
             tipo_pagamento=VALUES(tipo_pagamento), status_pagamento=VALUES(status_pagamento),
             data_original=VALUES(data_original), data_pagamento=VALUES(data_pagamento),
             data_envio_banco=VALUES(data_envio_banco), valor=VALUES(valor), arquivo_origem=VALUES(arquivo_origem)");
        $inserirArquivo = $pdo->prepare("INSERT INTO fechamento_alelo_arquivos
            (empresa_id, integracao, nome_arquivo, tipo_arquivo, data_processamento, periodo_inicial,
             periodo_final, sequencia, sha256, quantidade_bytes, registros_importados,
             registros_atualizados, pagamentos_processados, registros_ignorados)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        foreach ($arquivos as $nomeArquivo) {
            $caminho = rtrim((string)$config['diretorio_remoto'], '/\\') . '/' . $nomeArquivo;
            $conteudo = $sftp->get($caminho);
            if (!is_string($conteudo)) {
                throw new RuntimeException('Falha ao baixar o arquivo ALELO ' . $nomeArquivo . '.');
            }
            $hash = hash('sha256', $conteudo);
            $verificarArquivo->execute([$empresaId, $config['chave'], $hash]);
            if ($verificarArquivo->fetchColumn()) {
                $resultado['arquivos_duplicados']++;
                continue;
            }
            $arquivo = aleloLerArquivo($conteudo, $regra);
            $importados = 0;
            $atualizados = 0;
            $pagamentos = 0;
            $pdo->beginTransaction();
            try {
                foreach ($arquivo['registros'] as $registro) {
                    $verificarRecebivel->execute([$empresaId, $registro['identificador']]);
                    $recebivelId = (int)$verificarRecebivel->fetchColumn();
                    if ($recebivelId > 0) {
                        $atualizarRecebivel->execute([$registro['origem'], $registro['data_venda'], $registro['data_prevista'],
                            $registro['valor_bruto'], $registro['valor_desconto'], $registro['valor_liquido'],
                            $registro['descricao'], $registro['pagador'], $registro['parcela'], $registro['total_parcelas'],
                            $registro['status'], $nomeArquivo, $registro['cm'], $registro['tipo_operacao'],
                            $registro['bandeira'], $registro['nsu'], $registro['autorizacao'], $registro['estabelecimento'],
                            $registro['id_transacao'], $recebivelId, $empresaId]);
                        $atualizados++;
                    } else {
                        $inserirRecebivel->execute([$empresaId, $registro['origem'], $registro['data_venda'],
                            $registro['data_prevista'], $registro['valor_bruto'], $registro['valor_desconto'],
                            $registro['valor_liquido'], $registro['identificador'], $registro['descricao'],
                            $registro['pagador'], $registro['parcela'], $registro['total_parcelas'], $registro['status'],
                            $nomeArquivo, $registro['cm'], $registro['tipo_operacao'], $registro['bandeira'],
                            $registro['nsu'], $registro['autorizacao'], $registro['estabelecimento'], $registro['id_transacao']]);
                        $importados++;
                    }
                }
                foreach ($arquivo['pagamentos'] as $pagamento) {
                    $salvarPagamento->execute([$empresaId, $pagamento['chave_pagamento'], $pagamento['estabelecimento'],
                        $pagamento['matriz_pagamento'], $pagamento['tipo_pagamento'], $pagamento['status_pagamento'],
                        $pagamento['data_original'], $pagamento['data_pagamento'], $pagamento['data_envio_banco'],
                        $pagamento['valor'], $nomeArquivo]);
                    $pagamentos++;
                }
                $cab = $arquivo['cabecalho'];
                $inserirArquivo->execute([$empresaId, $config['chave'], $nomeArquivo, $cab['tipo_arquivo'],
                    $cab['data_processamento'], $cab['periodo_inicial'], $cab['periodo_final'], $cab['sequencia'],
                    $hash, strlen($conteudo), $importados, $atualizados, $pagamentos, (int)$arquivo['ignorados']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
            $resultado['registros_importados'] += $importados;
            $resultado['registros_atualizados'] += $atualizados;
            $resultado['pagamentos_processados'] += $pagamentos;
            $resultado['registros_ignorados'] += (int)$arquivo['ignorados'];
        }

        $mensagem = count($arquivos) === 0 ? 'A ALELO nao disponibilizou arquivos para o periodo.' : 'Sincronizacao concluida.';
        $stmtFim = $pdo->prepare("UPDATE fechamento_alelo_sincronizacoes SET status='CONCLUIDA',
            arquivos_recebidos=?, arquivos_duplicados=?, registros_importados=?, registros_atualizados=?,
            pagamentos_processados=?, registros_ignorados=?, mensagem=?, finalizado_em=NOW() WHERE id=?");
        $stmtFim->execute([$resultado['arquivos_recebidos'], $resultado['arquivos_duplicados'],
            $resultado['registros_importados'], $resultado['registros_atualizados'], $resultado['pagamentos_processados'],
            $resultado['registros_ignorados'], $mensagem, $sincronizacaoId]);
        $resultado['mensagem'] = $mensagem;
        return $resultado;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $stmtErro = $pdo->prepare("UPDATE fechamento_alelo_sincronizacoes SET status='ERRO', mensagem=?, finalizado_em=NOW() WHERE id=?");
        $stmtErro->execute([substr($e->getMessage(), 0, 500), $sincronizacaoId]);
        throw $e;
    }
}
