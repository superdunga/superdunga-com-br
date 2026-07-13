<?php

function garantirTabelasDescontoCheques(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS desconto_cheques_clientes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            nome VARCHAR(180) NOT NULL,
            celular VARCHAR(40) NULL,
            taxa_desconto DECIMAL(10,4) NOT NULL DEFAULT 0,
            usa_adicional_prazo CHAR(1) NOT NULL DEFAULT 'S',
            limite_credito DECIMAL(15,2) NOT NULL DEFAULT 0,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_dc_clientes_empresa_nome (empresa_id, nome),
            INDEX idx_dc_clientes_ativo (empresa_id, ativo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS desconto_cheques_prazos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            descricao VARCHAR(80) NOT NULL,
            dias_inicio INT NOT NULL,
            dias_fim INT NULL,
            adicional_percentual DECIMAL(10,4) NOT NULL DEFAULT 0,
            minimo_valor DECIMAL(15,2) NOT NULL DEFAULT 0,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            ordem INT NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_dc_prazo_empresa_ordem (empresa_id, ordem)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS desconto_cheques_feriados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            dia TINYINT NOT NULL,
            mes TINYINT NOT NULL,
            descricao VARCHAR(120) NOT NULL,
            tipo VARCHAR(20) NOT NULL DEFAULT 'REGIONAL',
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_dc_feriado_empresa_data (empresa_id, dia, mes),
            INDEX idx_dc_feriado_empresa_mes_dia (empresa_id, mes, dia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS desconto_cheques_feriados_variaveis (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            data_feriado DATE NOT NULL,
            descricao VARCHAR(120) NOT NULL,
            tipo VARCHAR(20) NOT NULL DEFAULT 'NACIONAL',
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_dc_feriado_variavel_empresa_data (empresa_id, data_feriado),
            INDEX idx_dc_feriado_variavel_empresa_data (empresa_id, data_feriado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS desconto_cheques_operacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            cliente_id INT NOT NULL,
            data_referencia DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'ABERTA',
            valor_bruto DECIMAL(15,2) NOT NULL DEFAULT 0,
            valor_desconto DECIMAL(15,2) NOT NULL DEFAULT 0,
            valor_taxas_tarifas DECIMAL(15,2) NOT NULL DEFAULT 0,
            historico_taxas_tarifas VARCHAR(255) NULL,
            valor_descontar DECIMAL(15,2) NOT NULL DEFAULT 0,
            historico_descontar VARCHAR(255) NULL,
            operacao_origem_id INT NULL,
            valor_liquido DECIMAL(15,2) NOT NULL DEFAULT 0,
            observacao TEXT NULL,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_dc_operacoes_empresa_data (empresa_id, data_referencia),
            INDEX idx_dc_operacoes_cliente (empresa_id, cliente_id),
            INDEX idx_dc_operacoes_status (empresa_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    garantirColunaDC($pdo, 'desconto_cheques_operacoes', 'valor_taxas_tarifas', "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER valor_desconto");
    garantirColunaDC($pdo, 'desconto_cheques_operacoes', 'historico_taxas_tarifas', "VARCHAR(255) NULL AFTER valor_taxas_tarifas");
    garantirColunaDC($pdo, 'desconto_cheques_operacoes', 'valor_descontar', "DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER historico_taxas_tarifas");
    garantirColunaDC($pdo, 'desconto_cheques_operacoes', 'historico_descontar', "VARCHAR(255) NULL AFTER valor_descontar");
    garantirColunaDC($pdo, 'desconto_cheques_operacoes', 'mov_bruto', "INT NULL AFTER historico_descontar");
    garantirColunaDC($pdo, 'desconto_cheques_operacoes', 'mov_desconto', "INT NULL AFTER mov_bruto");
    garantirColunaDC($pdo, 'desconto_cheques_operacoes', 'mov_taxas', "INT NULL AFTER mov_desconto");
    garantirColunaDC($pdo, 'desconto_cheques_operacoes', 'mov_outros', "INT NULL AFTER mov_taxas");
    garantirColunaDC($pdo, 'desconto_cheques_operacoes', 'operacao_origem_id', "INT NULL AFTER historico_descontar");
    garantirIndiceDC($pdo, 'desconto_cheques_operacoes', 'idx_dc_operacoes_origem', ['empresa_id', 'operacao_origem_id']);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS desconto_cheques_documentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            operacao_id INT NOT NULL,
            tipo_documento VARCHAR(20) NOT NULL DEFAULT 'CHEQUE',
            numero_documento VARCHAR(80) NULL,
            cnpj_cpf_emissor VARCHAR(20) NULL,
            nome_emissor VARCHAR(180) NULL,
            arquivo_nome VARCHAR(255) NULL,
            arquivo_caminho VARCHAR(255) NULL,
            arquivo_frente_nome VARCHAR(255) NULL,
            arquivo_frente_caminho VARCHAR(255) NULL,
            arquivo_verso_nome VARCHAR(255) NULL,
            arquivo_verso_caminho VARCHAR(255) NULL,
            valor DECIMAL(15,2) NOT NULL DEFAULT 0,
            data_vencimento DATE NOT NULL,
            data_compensacao DATE NOT NULL,
            prazo_dias INT NOT NULL DEFAULT 0,
            taxa_cliente DECIMAL(10,4) NOT NULL DEFAULT 0,
            adicional_percentual DECIMAL(10,4) NOT NULL DEFAULT 0,
            adicional_valor DECIMAL(15,2) NOT NULL DEFAULT 0,
            desconto_valor DECIMAL(15,2) NOT NULL DEFAULT 0,
            valor_liquido DECIMAL(15,2) NOT NULL DEFAULT 0,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dc_documentos_operacao (operacao_id),
            INDEX idx_dc_documentos_vencimento (data_vencimento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    garantirColunaDC($pdo, 'desconto_cheques_documentos', 'cnpj_cpf_emissor', "VARCHAR(20) NULL AFTER numero_documento");
    garantirColunaDC($pdo, 'desconto_cheques_documentos', 'nome_emissor', "VARCHAR(180) NULL AFTER cnpj_cpf_emissor");
    garantirColunaDC($pdo, 'desconto_cheques_documentos', 'crcontador', "INT NULL AFTER nome_emissor");
    garantirColunaDC($pdo, 'desconto_cheques_documentos', 'arquivo_frente_nome', "VARCHAR(255) NULL AFTER arquivo_caminho");
    garantirColunaDC($pdo, 'desconto_cheques_documentos', 'arquivo_frente_caminho', "VARCHAR(255) NULL AFTER arquivo_frente_nome");
    garantirColunaDC($pdo, 'desconto_cheques_documentos', 'arquivo_verso_nome', "VARCHAR(255) NULL AFTER arquivo_frente_caminho");
    garantirColunaDC($pdo, 'desconto_cheques_documentos', 'arquivo_verso_caminho', "VARCHAR(255) NULL AFTER arquivo_verso_nome");
    garantirIndiceDC($pdo, 'desconto_cheques_documentos', 'idx_dc_documentos_emissor_vencimento', ['cnpj_cpf_emissor', 'data_vencimento']);
    garantirIndiceDC($pdo, 'desconto_cheques_documentos', 'idx_dc_documentos_crcontador', ['crcontador']);
}

function normalizarTextoDC(string $texto): string
{
    $texto = str_replace(["\r\n", "\r"], "\n", $texto);
    $texto = preg_replace('/[ \t]+/', ' ', $texto) ?? $texto;
    return trim($texto);
}

function localizarComandoDC(string $comando): ?string
{
    if (!function_exists('shell_exec')) {
        return null;
    }

    $comandoSeguro = preg_replace('/[^a-zA-Z0-9_.-]/', '', $comando);
    if (!$comandoSeguro) {
        return null;
    }

    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        $saida = trim((string)@shell_exec('where ' . $comandoSeguro . ' 2>NUL'));
    } else {
        $saida = trim((string)@shell_exec('command -v ' . escapeshellarg($comandoSeguro) . ' 2>/dev/null'));
    }

    if ($saida === '') {
        return null;
    }

    $primeiro = strtok($saida, "\r\n");
    return $primeiro ? $primeiro : null;
}

function executarComandoTextoDC(array $partes): string
{
    if (!function_exists('shell_exec')) {
        return '';
    }

    $saida = (string)@shell_exec(implode(' ', $partes) . ' 2>&1');
    if (
        stripos($saida, 'not found') !== false
        || stripos($saida, 'nao foi encontrado') !== false
        || stripos($saida, 'Traceback') !== false
    ) {
        return '';
    }

    return $saida;
}

function extrairTextoPdfDC(string $arquivo): string
{
    $pdftotext = localizarComandoDC('pdftotext');
    if ($pdftotext) {
        $tmpTxt = tempnam(sys_get_temp_dir(), 'dc_pdf_');
        if ($tmpTxt !== false) {
            executarComandoTextoDC([
                escapeshellarg($pdftotext),
                '-layout',
                escapeshellarg($arquivo),
                escapeshellarg($tmpTxt),
            ]);
            $texto = is_file($tmpTxt) ? (string)file_get_contents($tmpTxt) : '';
            @unlink($tmpTxt);
            if (trim($texto) !== '') {
                return normalizarTextoDC($texto);
            }
        }
    }

    $pythonScript = tempnam(sys_get_temp_dir(), 'dc_pdf_py_');
    if ($pythonScript === false) {
        return '';
    }

    $pythonCodigo = "import sys\n"
        . "from pypdf import PdfReader\n"
        . "reader = PdfReader(sys.argv[1])\n"
        . "for page in reader.pages:\n"
        . "    print(page.extract_text() or '')\n";
    file_put_contents($pythonScript, $pythonCodigo);

    $pythonCandidates = array_filter([
        getenv('PYTHON_BIN') ?: '',
        getenv('PYTHON') ?: '',
        'python3',
        'python',
        'py',
        'C:\\Users\\user\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python\\python.exe',
    ]);

    foreach ($pythonCandidates as $python) {
        $saida = executarComandoTextoDC([
            escapeshellarg($python),
            escapeshellarg($pythonScript),
            escapeshellarg($arquivo),
        ]);
        if (trim($saida) !== '' && stripos($saida, 'No module named') === false) {
            @unlink($pythonScript);
            return normalizarTextoDC($saida);
        }
    }

    @unlink($pythonScript);
    return '';
}

function extrairTextoImagemDC(string $arquivo): string
{
    $tesseract = localizarComandoDC('tesseract');
    if (!$tesseract) {
        return '';
    }

    $saida = executarComandoTextoDC([
        escapeshellarg($tesseract),
        escapeshellarg($arquivo),
        'stdout',
        '-l',
        'por+eng',
        '--psm',
        '6',
    ]);

    return normalizarTextoDC($saida);
}

function extrairTextoDocumentoDC(string $arquivo, string $nomeOriginal = ''): array
{
    $ext = strtolower(pathinfo($nomeOriginal ?: $arquivo, PATHINFO_EXTENSION));
    $mime = function_exists('mime_content_type') ? (string)@mime_content_type($arquivo) : '';
    $texto = '';
    $metodo = '';
    $avisos = [];

    if ($ext === 'pdf' || stripos($mime, 'pdf') !== false) {
        $texto = extrairTextoPdfDC($arquivo);
        $metodo = $texto !== '' ? 'PDF texto' : '';
        if ($texto === '') {
            $avisos[] = 'Nao foi possivel extrair texto do PDF. Se for PDF escaneado, instale OCR no servidor.';
        }
    } elseif (strpos($mime, 'image/') === 0 || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tif', 'tiff'], true)) {
        $texto = extrairTextoImagemDC($arquivo);
        $metodo = $texto !== '' ? 'OCR imagem' : '';
        if ($texto === '') {
            $avisos[] = 'OCR de imagem indisponivel no servidor. Instale Tesseract para ler fotos.';
        }
    } else {
        $avisos[] = 'Tipo de arquivo nao suportado para leitura automatica.';
    }

    if ($texto === '' && $nomeOriginal !== '') {
        $texto = $nomeOriginal;
        $metodo = 'Nome do arquivo';
        $avisos[] = 'Usei apenas o nome do arquivo como tentativa de leitura.';
    }

    $dados = interpretarTextoDocumentoDC($texto);
    $dados['texto'] = function_exists('mb_substr') ? mb_substr($texto, 0, 2500) : substr($texto, 0, 2500);
    $dados['metodo'] = $metodo;
    $dados['avisos'] = $avisos;
    $dados['sucesso'] = (
        $dados['valor'] !== null
        || $dados['data_vencimento'] !== null
        || $dados['numero_documento'] !== null
        || $dados['cnpj_cpf_emissor'] !== null
        || $dados['nome_emissor'] !== null
    );

    return $dados;
}

function interpretarTextoDocumentoDC(string $texto): array
{
    $texto = normalizarTextoDC($texto);
    $linhas = array_values(array_filter(array_map('trim', explode("\n", $texto)), static function ($linha) {
        return $linha !== '';
    }));
    $textoPlano = implode(' ', $linhas);

    $data = null;
    if (preg_match_all('/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})\b/', $textoPlano, $datas, PREG_SET_ORDER)) {
        $datasValidas = [];
        foreach ($datas as $d) {
            $ano = (int)$d[3];
            $ano = $ano < 100 ? 2000 + $ano : $ano;
            $mes = (int)$d[2];
            $dia = (int)$d[1];
            if (checkdate($mes, $dia, $ano)) {
                $datasValidas[] = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
            }
        }
        if (!empty($datasValidas)) {
            sort($datasValidas);
            $data = end($datasValidas) ?: null;
        }
    } elseif (preg_match_all('/\b(20\d{2})-(\d{1,2})-(\d{1,2})\b/', $textoPlano, $datasIso, PREG_SET_ORDER)) {
        $datasValidas = [];
        foreach ($datasIso as $d) {
            if (checkdate((int)$d[2], (int)$d[3], (int)$d[1])) {
                $datasValidas[] = sprintf('%04d-%02d-%02d', (int)$d[1], (int)$d[2], (int)$d[3]);
            }
        }
        if (!empty($datasValidas)) {
            sort($datasValidas);
            $data = end($datasValidas) ?: null;
        }
    }

    $valor = null;
    $candidatosValor = [];
    if (preg_match_all('/(?:R\$\s*)?(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2})\b/u', $textoPlano, $valores, PREG_SET_ORDER)) {
        foreach ($valores as $match) {
            $numero = decimalDC($match[1]);
            if ($numero > 0 && $numero < 100000000) {
                $candidatosValor[] = $numero;
            }
        }
    }
    if (!empty($candidatosValor)) {
        $valor = max($candidatosValor);
    }

    $numeroDocumento = null;
    if (preg_match('/\b(\d[\d .-]{42,60}\d)\b/', $textoPlano, $linhaDigitavel)) {
        $numeroDocumento = preg_replace('/\D+/', '', $linhaDigitavel[1]);
    }

    if (!$numeroDocumento && preg_match('/(?:cheque|boleto|documento|doc\.?|numero|no\.?|n\.?)[^\d]{0,20}(\d{4,20})/iu', $textoPlano, $numeroComRotulo)) {
        $numeroDocumento = $numeroComRotulo[1];
    }

    if (!$numeroDocumento && preg_match_all('/\b\d{5,20}\b/', $textoPlano, $numeros, PREG_SET_ORDER)) {
        foreach ($numeros as $numero) {
            $n = $numero[0];
            if ($data && strpos(str_replace('-', '', $data), $n) !== false) {
                continue;
            }
            $numeroDocumento = $n;
            break;
        }
    }

    $cnpjCpfEmissor = null;
    if (preg_match('/\b(\d{3}\.?\d{3}\.?\d{3}-?\d{2})\b/', $textoPlano, $cpf)) {
        $cnpjCpfEmissor = preg_replace('/\D+/', '', $cpf[1]);
    }
    if (!$cnpjCpfEmissor && preg_match('/\b(\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2})\b/', $textoPlano, $cnpj)) {
        $cnpjCpfEmissor = preg_replace('/\D+/', '', $cnpj[1]);
    }

    $nomeEmissor = null;
    $padroesNome = [
        '/(?:emissor|emitente|sacado|pagador|cliente|cedente|beneficiario)\s*[:\-]?\s*([A-Z0-9][A-Z0-9 .,&\-]{4,120})/iu',
        '/(?:nome)\s+(?:do\s+)?(?:emissor|emitente|sacado|pagador)\s*[:\-]?\s*([A-Z0-9][A-Z0-9 .,&\-]{4,120})/iu',
    ];
    foreach ($padroesNome as $padraoNome) {
        if (preg_match($padraoNome, $textoPlano, $nomeMatch)) {
            $nomeEmissor = trim(preg_replace('/\s+/', ' ', $nomeMatch[1]) ?? $nomeMatch[1]);
            $nomeEmissor = preg_replace('/\s+(CPF|CNPJ|VALOR|VENCIMENTO|DATA|DOCUMENTO|NUMERO|N)\b.*$/iu', '', $nomeEmissor) ?? $nomeEmissor;
            $nomeEmissor = trim($nomeEmissor, " \t\n\r\0\x0B:-");
            break;
        }
    }

    return [
        'valor' => $valor,
        'valor_formatado' => $valor !== null ? number_format($valor, 2, ',', '.') : null,
        'data_vencimento' => $data,
        'numero_documento' => $numeroDocumento,
        'cnpj_cpf_emissor' => $cnpjCpfEmissor,
        'nome_emissor' => $nomeEmissor ?: null,
    ];
}

function garantirColunaDC(PDO $pdo, string $tabela, string $coluna, string $definicao): void
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tabela, $coluna]);

    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$tabela}` ADD COLUMN `{$coluna}` {$definicao}");
    }
}

function garantirIndiceDC(PDO $pdo, string $tabela, string $indice, array $colunas): void
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $stmt->execute([$tabela, $indice]);

    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $colunasSql = implode(', ', array_map(static function ($coluna) {
        return '`' . str_replace('`', '', $coluna) . '`';
    }, $colunas));
    $pdo->exec("ALTER TABLE `{$tabela}` ADD INDEX `{$indice}` ({$colunasSql})");
}

function garantirPrazosPadraoDescontoCheques(PDO $pdo, int $empresaId): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM desconto_cheques_prazos WHERE empresa_id = ?");
    $stmt->execute([$empresaId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $stmtInsert = $pdo->prepare("
        INSERT INTO desconto_cheques_prazos
            (empresa_id, descricao, dias_inicio, dias_fim, adicional_percentual, minimo_valor, ordem)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmtInsert->execute([$empresaId, 'De 1 a 15 dias', 1, 15, 1.0000, 10.00, 1]);
    $stmtInsert->execute([$empresaId, 'De 16 a 29 dias', 16, 29, 0.5000, 0.00, 2]);
    $stmtInsert->execute([$empresaId, 'Acima de 30 dias', 30, null, 0.0000, 0.00, 3]);
}

function garantirFeriadosNacionaisFixosDC(PDO $pdo, int $empresaId): void
{
    $feriados = [
        [1, 1, 'Confraternizacao Universal'],
        [21, 4, 'Tiradentes'],
        [1, 5, 'Dia do Trabalhador'],
        [7, 9, 'Independencia do Brasil'],
        [12, 10, 'Nossa Senhora Aparecida'],
        [2, 11, 'Finados'],
        [15, 11, 'Proclamacao da Republica'],
        [25, 12, 'Natal'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO desconto_cheques_feriados
            (empresa_id, dia, mes, descricao, tipo, ativo)
        VALUES (?, ?, ?, ?, 'NACIONAL', 'S')
        ON DUPLICATE KEY UPDATE
            descricao = VALUES(descricao),
            tipo = IF(tipo = 'NACIONAL', VALUES(tipo), tipo),
            ativo = ativo
    ");

    foreach ($feriados as $feriado) {
        $stmt->execute([$empresaId, $feriado[0], $feriado[1], $feriado[2]]);
    }
}

function dataPascoaDC(int $ano): DateTimeImmutable
{
    $a = $ano % 19;
    $b = intdiv($ano, 100);
    $c = $ano % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, $dia));
}

function feriadosVariaveisCalculadosDC(int $ano): array
{
    $pascoa = dataPascoaDC($ano);

    return [
        ['data' => $pascoa->modify('-48 days')->format('Y-m-d'), 'descricao' => 'Carnaval - segunda-feira'],
        ['data' => $pascoa->modify('-47 days')->format('Y-m-d'), 'descricao' => 'Carnaval - terça-feira'],
        ['data' => $pascoa->modify('-2 days')->format('Y-m-d'), 'descricao' => 'Sexta-feira Santa'],
        ['data' => $pascoa->modify('+60 days')->format('Y-m-d'), 'descricao' => 'Corpus Christi'],
    ];
}

function garantirFeriadosVariaveisDC(PDO $pdo, int $empresaId, int $anoInicial = 0, int $anosFuturos = 5): void
{
    $anoInicial = $anoInicial > 0 ? $anoInicial : (int)date('Y');
    $anoFinal = $anoInicial + max(0, $anosFuturos);

    $stmt = $pdo->prepare("
        INSERT INTO desconto_cheques_feriados_variaveis
            (empresa_id, data_feriado, descricao, tipo, ativo)
        VALUES (?, ?, ?, 'NACIONAL', 'S')
        ON DUPLICATE KEY UPDATE
            descricao = VALUES(descricao),
            tipo = VALUES(tipo),
            ativo = ativo
    ");

    for ($ano = $anoInicial; $ano <= $anoFinal; $ano++) {
        foreach (feriadosVariaveisCalculadosDC($ano) as $feriado) {
            $stmt->execute([$empresaId, $feriado['data'], $feriado['descricao']]);
        }
    }
}

function moedaDC($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function percentualDC($valor): string
{
    return number_format((float)$valor, 2, ',', '.') . '%';
}

function formatarCpfCnpjDC(?string $valor): string
{
    $digitos = preg_replace('/\D+/', '', (string)$valor);
    if (strlen($digitos) === 11) {
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digitos) ?? $digitos;
    }
    if (strlen($digitos) === 14) {
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digitos) ?? $digitos;
    }

    return $digitos ?: (string)$valor;
}

function decimalDC($valor): float
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return 0.0;
    }

    $valor = str_replace(['R$', ' ', '.'], '', $valor);
    $valor = str_replace(',', '.', $valor);
    return round((float)$valor, 4);
}

function dataBRDC(?string $data): string
{
    if (!$data) {
        return '-';
    }

    $ts = strtotime($data);
    return $ts ? date('d/m/Y', $ts) : '-';
}

function feriadosRecorrentesDC(PDO $pdo, int $empresaId): array
{
    $stmt = $pdo->prepare("
        SELECT dia, mes, descricao
        FROM desconto_cheques_feriados
        WHERE empresa_id = ?
          AND ativo = 'S'
    ");
    $stmt->execute([$empresaId]);

    $feriados = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $feriado) {
        $feriados[sprintf('%02d-%02d', (int)$feriado['mes'], (int)$feriado['dia'])] = $feriado['descricao'];
    }

    return $feriados;
}

function feriadosEspecificosDC(PDO $pdo, int $empresaId, int $anoInicial, int $anoFinal): array
{
    $stmt = $pdo->prepare("
        SELECT data_feriado, descricao
        FROM desconto_cheques_feriados_variaveis
        WHERE empresa_id = ?
          AND ativo = 'S'
          AND data_feriado BETWEEN ? AND ?
    ");
    $stmt->execute([
        $empresaId,
        sprintf('%04d-01-01', $anoInicial),
        sprintf('%04d-12-31', $anoFinal),
    ]);

    $feriados = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $feriado) {
        $feriados[$feriado['data_feriado']] = $feriado['descricao'];
    }

    return $feriados;
}

function ehDiaUtilDC(DateTimeImmutable $data, array $feriadosRecorrentes = [], array $feriadosEspecificos = []): bool
{
    if (in_array((int)$data->format('N'), [6, 7], true)) {
        return false;
    }

    return !isset($feriadosRecorrentes[$data->format('m-d')]) && !isset($feriadosEspecificos[$data->format('Y-m-d')]);
}

function proximoDiaUtilDC(DateTimeImmutable $data, array $feriadosRecorrentes = [], array $feriadosEspecificos = []): DateTimeImmutable
{
    while (!ehDiaUtilDC($data, $feriadosRecorrentes, $feriadosEspecificos)) {
        $data = $data->modify('+1 day');
    }

    return $data;
}

function dataCompensacaoDescontoChequesDC(DateTimeImmutable $vencimento, int $diasUteisCompensacao, array $feriadosRecorrentes = [], array $feriadosEspecificos = []): DateTimeImmutable
{
    $data = $vencimento;
    $diasContados = 0;

    while ($diasContados < $diasUteisCompensacao) {
        $data = $data->modify('+1 day');
        if (ehDiaUtilDC($data, $feriadosRecorrentes, $feriadosEspecificos)) {
            $diasContados++;
        }
    }

    return proximoDiaUtilDC($data->modify('+1 day'), $feriadosRecorrentes, $feriadosEspecificos);
}

function buscarPrazosDescontoCheques(PDO $pdo, int $empresaId): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM desconto_cheques_prazos
        WHERE empresa_id = ?
          AND ativo = 'S'
        ORDER BY ordem, dias_inicio
    ");
    $stmt->execute([$empresaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function selecionarFaixaPrazoDC(array $faixas, int $prazoDias): ?array
{
    foreach ($faixas as $faixa) {
        $inicio = (int)$faixa['dias_inicio'];
        $fim = $faixa['dias_fim'] === null ? null : (int)$faixa['dias_fim'];
        if ($prazoDias >= $inicio && ($fim === null || $prazoDias <= $fim)) {
            return $faixa;
        }
    }

    return null;
}

function calcularDocumentoDescontoCheques(float $valor, string $dataReferencia, string $dataVencimento, array $cliente, array $faixas, array $feriadosRecorrentes = [], array $feriadosEspecificos = []): array
{
    $referencia = new DateTimeImmutable($dataReferencia);
    $vencimento = new DateTimeImmutable($dataVencimento);
    $compensacao = dataCompensacaoDescontoChequesDC($vencimento, 2, $feriadosRecorrentes, $feriadosEspecificos);
    $prazoDias = max(0, (int)$referencia->diff($compensacao)->format('%r%a'));
    $taxaCliente = (float)$cliente['taxa_desconto'];
    $usaAdicional = ($cliente['usa_adicional_prazo'] ?? 'S') === 'S';
    $faixa = $usaAdicional ? selecionarFaixaPrazoDC($faixas, max(1, $prazoDias)) : null;
    $adicionalPercentual = $faixa ? (float)$faixa['adicional_percentual'] : 0.0;
    $minimoValor = $faixa ? (float)$faixa['minimo_valor'] : 0.0;
    $taxaClienteProporcional = ($taxaCliente / 30) * $prazoDias;
    $adicionalProporcional = ($adicionalPercentual / 30) * $prazoDias;
    $descontoCliente = round($valor * ($taxaClienteProporcional / 100), 2);
    $adicionalValor = round($valor * ($adicionalProporcional / 100), 2);

    if ($adicionalPercentual > 0 && $minimoValor > 0) {
        $adicionalValor = max($adicionalValor, $minimoValor);
    }

    $descontoValor = min($valor, round($descontoCliente + $adicionalValor, 2));
    $valorLiquido = round($valor - $descontoValor, 2);
    $taxaTotalProporcional = $valor > 0 ? round(($descontoValor / $valor) * 100, 4) : 0.0;

    return [
        'data_compensacao' => $compensacao->format('Y-m-d'),
        'prazo_dias' => $prazoDias,
        'taxa_cliente' => $taxaCliente,
        'adicional_percentual' => $adicionalPercentual,
        'taxa_cliente_proporcional' => $taxaClienteProporcional,
        'adicional_proporcional' => $adicionalProporcional,
        'taxa_total_proporcional' => $taxaTotalProporcional,
        'adicional_valor' => $adicionalValor,
        'desconto_valor' => $descontoValor,
        'valor_liquido' => $valorLiquido,
    ];
}

function taxaTotalDocumentoDC(array $documento): float
{
    $valor = (float)($documento['valor'] ?? 0);
    if ($valor <= 0) {
        return 0.0;
    }

    return round(((float)($documento['desconto_valor'] ?? 0) / $valor) * 100, 4);
}

function buscarResumoEmissorAVencerDC(PDO $pdo, int $empresaId, string $cnpjCpf, int $ignorarDocumentoId = 0): array
{
    $digitos = preg_replace('/\D+/', '', $cnpjCpf);
    if (!$digitos) {
        return [
            'cnpj_cpf' => '',
            'quantidade' => 0,
            'valor_total' => 0.0,
            'valor_total_formatado' => moedaDC(0),
            'proximo_vencimento' => null,
            'documentos' => [],
        ];
    }

    $digitosConsulta = [$digitos];
    if (strlen($digitos) === 15 && preg_match('/^(\d{8})\d(0001\d{2})$/', $digitos, $match)) {
        $digitosConsulta[] = $match[1] . $match[2];
    }
    $digitosConsulta = array_values(array_unique($digitosConsulta));
    $placeholdersDigitos = implode(',', array_fill(0, count($digitosConsulta), '?'));

    $params = array_merge([$empresaId], $digitosConsulta, [date('Y-m-d')]);
    $filtroIgnorar = '';
    if ($ignorarDocumentoId > 0) {
        $filtroIgnorar = ' AND d.id <> ?';
        $params[] = $ignorarDocumentoId;
    }

    $stmt = $pdo->prepare("
        SELECT
            d.id,
            d.operacao_id,
            d.tipo_documento,
            d.numero_documento,
            d.nome_emissor,
            d.valor,
            d.data_vencimento,
            o.status,
            cr.CRCONTADOR,
            cr.VLRRESTANTE,
            cr.STATUS AS status_cr
        FROM desconto_cheques_documentos d
        INNER JOIN desconto_cheques_operacoes o ON o.id = d.operacao_id
        LEFT JOIN armazem_cr001 cr
               ON cr.EMPRESA = o.empresa_id
              AND cr.CRCONTADOR = d.crcontador
              AND COALESCE(cr.excluido_firebird, 'N') = 'N'
        WHERE o.empresa_id = ?
          AND d.cnpj_cpf_emissor IN ({$placeholdersDigitos})
          AND d.data_vencimento >= ?
          AND (
              o.status IN ('ABERTA', 'CONFIRMADA')
              OR (
                  o.status = 'LANCADA'
                  AND cr.CRCONTADOR IS NOT NULL
                  AND COALESCE(cr.STATUS, '') <> 'QT'
                  AND COALESCE(cr.VLRRESTANTE, cr.VLRPARCELA, 0) > 0
              )
          )
          {$filtroIgnorar}
        ORDER BY d.data_vencimento, d.id
    ");
    $stmt->execute($params);
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = 0.0;
    foreach ($documentos as &$documento) {
        $documento['valor'] = (float)$documento['valor'];
        $documento['valor_formatado'] = moedaDC($documento['valor']);
        $documento['data_vencimento_br'] = dataBRDC($documento['data_vencimento']);
        $total += $documento['valor'];
    }
    unset($documento);

    $nomeEmissor = '';
    foreach ($documentos as $documento) {
        if (!empty($documento['nome_emissor'])) {
            $nomeEmissor = (string)$documento['nome_emissor'];
            break;
        }
    }

    if ($nomeEmissor === '') {
        $paramsNome = array_merge([$empresaId], $digitosConsulta);
        $stmtNome = $pdo->prepare("
            SELECT d.nome_emissor
            FROM desconto_cheques_documentos d
            INNER JOIN desconto_cheques_operacoes o ON o.id = d.operacao_id
            WHERE o.empresa_id = ?
              AND d.cnpj_cpf_emissor IN ({$placeholdersDigitos})
              AND COALESCE(d.nome_emissor, '') <> ''
            ORDER BY d.id DESC
            LIMIT 1
        ");
        $stmtNome->execute($paramsNome);
        $nomeEmissor = (string)($stmtNome->fetchColumn() ?: '');
    }

    return [
        'cnpj_cpf' => $digitos,
        'cnpj_cpf_consulta' => $digitosConsulta,
        'cnpj_cpf_formatado' => formatarCpfCnpjDC($digitos),
        'nome_emissor' => $nomeEmissor,
        'quantidade' => count($documentos),
        'valor_total' => round($total, 2),
        'valor_total_formatado' => moedaDC($total),
        'proximo_vencimento' => $documentos[0]['data_vencimento'] ?? null,
        'proximo_vencimento_br' => isset($documentos[0]) ? dataBRDC($documentos[0]['data_vencimento']) : null,
        'documentos' => array_slice($documentos, 0, 5),
    ];
}

function buscarResumoCreditoClienteDC(PDO $pdo, int $empresaId, int $clienteId, int $ignorarOperacaoId = 0): array
{
    $filtroOperacao = $ignorarOperacaoId > 0 ? ' AND o.id <> ' . (int)$ignorarOperacaoId : '';
    $stmt = $pdo->prepare("
        SELECT
            c.limite_credito,
            COALESCE(SUM(CASE WHEN o.status IN ('ABERTA', 'CONFIRMADA') THEN o.valor_bruto ELSE 0 END), 0) AS credito_utilizado
        FROM desconto_cheques_clientes c
        LEFT JOIN desconto_cheques_operacoes o
               ON o.cliente_id = c.id
              AND o.empresa_id = c.empresa_id
              {$filtroOperacao}
        WHERE c.empresa_id = ?
          AND c.id = ?
        GROUP BY c.id, c.limite_credito
    ");
    $stmt->execute([$empresaId, $clienteId]);
    $resumo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['limite_credito' => 0, 'credito_utilizado' => 0];
    $resumo['credito_disponivel'] = (float)$resumo['limite_credito'] - (float)$resumo['credito_utilizado'];
    return $resumo;
}

function salvarUploadDocumentoDC(?array $arquivo): array
{
    if ($arquivo === null) {
        return ['nome' => null, 'caminho' => null];
    }

    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['nome' => null, 'caminho' => null];
    }

    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Nao foi possivel anexar um dos documentos.');
    }

    $baseDir = dirname(__DIR__, 2) . '/uploads/desconto_cheques/' . date('Y/m');
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true)) {
        throw new RuntimeException('Nao foi possivel criar a pasta de anexos.');
    }

    $nomeOriginal = preg_replace('/[^\w.\- ]+/u', '_', (string)$arquivo['name']);
    $ext = pathinfo($nomeOriginal, PATHINFO_EXTENSION);
    $nomeFinal = bin2hex(random_bytes(8)) . ($ext ? '.' . strtolower($ext) : '');
    $destino = $baseDir . '/' . $nomeFinal;

    if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
        throw new RuntimeException('Nao foi possivel salvar um dos anexos.');
    }

    $relativo = 'uploads/desconto_cheques/' . date('Y/m') . '/' . $nomeFinal;
    return ['nome' => $nomeOriginal, 'caminho' => $relativo];
}
