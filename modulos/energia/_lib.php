<?php

function garantirTabelasEnergia(PDO $pdo): void
{
    static $executado = false;
    if ($executado) {
        return;
    }
    $executado = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS energia_contas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            arquivo_nome VARCHAR(255) NULL,
            arquivo_caminho VARCHAR(255) NULL,
            logradouro_complemento VARCHAR(255) NULL,
            unidade_consumidora VARCHAR(40) NULL,
            referencia VARCHAR(12) NULL,
            vencimento DATE NULL,
            valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
            data_emissao DATE NULL,
            consumo_kwh DECIMAL(15,3) NOT NULL DEFAULT 0,
            valor_unitario_kw DECIMAL(15,6) NOT NULL DEFAULT 0,
            franquia_minima DECIMAL(15,3) NOT NULL DEFAULT 0,
            custo_disponibilidade DECIMAL(15,2) NOT NULL DEFAULT 0,
            contribuicao_iluminacao DECIMAL(15,2) NOT NULL DEFAULT 0,
            texto_extraido MEDIUMTEXT NULL,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_energia_contas_empresa_ref (empresa_id, referencia),
            INDEX idx_energia_contas_empresa_venc (empresa_id, vencimento),
            INDEX idx_energia_contas_unidade (empresa_id, unidade_consumidora)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    garantirColunaEnergia($pdo, 'energia_contas', 'valor_unitario_kw', 'DECIMAL(15,6) NOT NULL DEFAULT 0 AFTER consumo_kwh');
    garantirColunaEnergia($pdo, 'energia_contas', 'franquia_minima', 'DECIMAL(15,3) NOT NULL DEFAULT 0 AFTER valor_unitario_kw');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS energia_operacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            conta_id INT NOT NULL,
            quantidade_kw_injetada DECIMAL(15,3) NOT NULL DEFAULT 0,
            percentual_desconto_venda DECIMAL(10,4) NOT NULL DEFAULT 0,
            valor_conta_com_desconto DECIMAL(15,2) NOT NULL DEFAULT 0,
            valor_total_pago_fornecedor DECIMAL(15,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'ABERTA',
            fornecedor_id INT NULL,
            cliente_id INT NULL,
            mov_contas_pagar INT NULL,
            mov_comissao INT NULL,
            crcontador INT NULL,
            fechado_por INT NULL,
            fechado_em DATETIME NULL,
            observacao TEXT NULL,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_energia_operacoes_empresa_conta (empresa_id, conta_id),
            INDEX idx_energia_operacoes_status (empresa_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    garantirColunaEnergia($pdo, 'energia_operacoes', 'fornecedor_id', 'INT NULL AFTER status');
    garantirColunaEnergia($pdo, 'energia_operacoes', 'cliente_id', 'INT NULL AFTER fornecedor_id');
    garantirColunaEnergia($pdo, 'energia_operacoes', 'mov_contas_pagar', 'INT NULL AFTER cliente_id');
    garantirColunaEnergia($pdo, 'energia_operacoes', 'mov_comissao', 'INT NULL AFTER mov_contas_pagar');
    garantirColunaEnergia($pdo, 'energia_operacoes', 'crcontador', 'INT NULL AFTER mov_comissao');
    garantirColunaEnergia($pdo, 'energia_operacoes', 'fechado_por', 'INT NULL AFTER crcontador');
    garantirColunaEnergia($pdo, 'energia_operacoes', 'fechado_em', 'DATETIME NULL AFTER fechado_por');
}

function garantirColunaEnergia(PDO $pdo, string $tabela, string $coluna, string $definicao): void
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

function normalizarTextoEnergia(string $texto): string
{
    if ($texto !== '' && function_exists('mb_check_encoding') && !mb_check_encoding($texto, 'UTF-8')) {
        $convertido = @mb_convert_encoding($texto, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
        if (is_string($convertido) && $convertido !== '') {
            $texto = $convertido;
        }
    } elseif ($texto !== '' && !preg_match('//u', $texto)) {
        $convertido = @iconv('Windows-1252', 'UTF-8//IGNORE', $texto);
        if (is_string($convertido) && $convertido !== '') {
            $texto = $convertido;
        }
    }

    $texto = str_replace(["\r\n", "\r"], "\n", $texto);
    $texto = preg_replace('/[ \t]+/', ' ', $texto) ?? $texto;
    $texto = preg_replace("/\n{3,}/", "\n\n", $texto) ?? $texto;
    return trim($texto);
}

function extrairTextoPdfEnergia(string $arquivoPdf): string
{
    if (!is_file($arquivoPdf)) {
        throw new RuntimeException('Arquivo PDF nao encontrado para leitura.');
    }

    $tmpTxt = tempnam(sys_get_temp_dir(), 'energia_pdf_');
    if ($tmpTxt === false) {
        throw new RuntimeException('Nao foi possivel criar arquivo temporario para leitura do PDF.');
    }

    $tentativas = [];
    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        $pdftotextWindows = trim((string)@shell_exec('where pdftotext 2>NUL'));
        if ($pdftotextWindows !== '') {
            $primeiro = strtok($pdftotextWindows, "\r\n");
            if ($primeiro) {
                $tentativas[] = [escapeshellarg($primeiro), '-layout', escapeshellarg($arquivoPdf), escapeshellarg($tmpTxt)];
            }
        }
    } else {
        $pdftotext = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
        if ($pdftotext !== '') {
            $tentativas[] = [escapeshellcmd($pdftotext), '-layout', escapeshellarg($arquivoPdf), escapeshellarg($tmpTxt)];
        }
    }

    foreach ($tentativas as $partes) {
        @shell_exec(implode(' ', $partes) . ' 2>&1');
        $texto = is_file($tmpTxt) ? (string)file_get_contents($tmpTxt) : '';
        $texto = normalizarTextoEnergia($texto);
        if ($texto !== '') {
            @unlink($tmpTxt);
            return $texto;
        }
    }

    $pythonScript = tempnam(sys_get_temp_dir(), 'energia_pdf_py_');
    if ($pythonScript === false) {
        @unlink($tmpTxt);
        throw new RuntimeException('Nao foi possivel criar script temporario para leitura do PDF.');
    }

    $pythonCodigo = "import sys\n"
        . "from pypdf import PdfReader\n\n"
        . "reader = PdfReader(sys.argv[1])\n"
        . "for page in reader.pages:\n"
        . "    print(page.extract_text() or '')\n";
    file_put_contents($pythonScript, $pythonCodigo);

    $pythonCandidates = array_filter([
        getenv('ENERGIA_PYTHON') ?: '',
        getenv('PYTHON_BIN') ?: '',
        getenv('PYTHON') ?: '',
        'C:\\Users\\user\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python\\python.exe',
        '/usr/local/bin/python3',
        '/usr/bin/python3',
        'python3',
        'python',
        'py',
    ]);

    foreach ($pythonCandidates as $python) {
        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($pythonScript) . ' ' . escapeshellarg($arquivoPdf) . ' 2>&1';
        $saida = normalizarTextoEnergia((string)@shell_exec($cmd));
        if ($saida !== '' && stripos($saida, 'Traceback') === false && stripos($saida, 'No module named') === false) {
            @unlink($tmpTxt);
            @unlink($pythonScript);
            return $saida;
        }
    }

    @unlink($tmpTxt);
    @unlink($pythonScript);
    throw new RuntimeException('Nao foi possivel extrair texto do PDF. Verifique se o servidor possui pdftotext ou Python com pypdf.');
}

function valorPtEnergia(string $valor): float
{
    $valor = trim($valor);
    $valor = preg_replace('/[^\d,\.\-]/', '', $valor) ?? $valor;
    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }
    return (float)$valor;
}

function quantidadePtEnergia(string $valor): float
{
    $valor = trim($valor);
    $valor = preg_replace('/[^\d,\.\-]/', '', $valor) ?? $valor;
    if (strpos($valor, ',') !== false) {
        return valorPtEnergia($valor);
    }
    if (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $valor)) {
        $valor = str_replace('.', '', $valor);
    }
    return (float)$valor;
}

function dataPtEnergia(string $data): ?string
{
    if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', trim($data), $m)) {
        return null;
    }
    return $m[3] . '-' . $m[2] . '-' . $m[1];
}

function parseContaEnergiaCemig(string $texto): array
{
    $texto = normalizarTextoEnergia($texto);
    $dados = [
        'logradouro_complemento' => '',
        'unidade_consumidora' => '',
        'referencia' => '',
        'vencimento' => '',
        'valor_total' => 0.0,
        'data_emissao' => '',
        'consumo_kwh' => 0.0,
        'custo_disponibilidade' => 0.0,
        'contribuicao_iluminacao' => 0.0,
    ];

    $linhas = array_values(array_filter(array_map('trim', explode("\n", $texto)), static fn($linha) => $linha !== ''));
    foreach ($linhas as $linha) {
        if (preg_match('/^(AV|AV\.|RUA|R\.|ROD|ROD\.|PRACA|PCA|AL|EST|TRAV|TV)\b/i', $linha)) {
            $dados['logradouro_complemento'] = $linha;
            break;
        }
    }

    foreach ($linhas as $idx => $linha) {
        if (stripos($linha, 'UNIDADE CONSUMIDORA') !== false && isset($linhas[$idx + 1]) && preg_match('/^[\d\.\-]+$/', $linhas[$idx + 1])) {
            $dados['unidade_consumidora'] = trim($linhas[$idx + 1]);
            break;
        }
    }
    if ($dados['unidade_consumidora'] === '' && preg_match('/N\.?[ºO]?\s*DA\s+UNIDADE\s+CONSUMIDORA\s*([\d\.\-]+)/i', $texto, $m)) {
        $dados['unidade_consumidora'] = trim($m[1]);
    } elseif ($dados['unidade_consumidora'] === '' && preg_match('/N\.?[ºO]?\s*da\s+Unidade\s+Consumidora\s+Vencimento\s+Total a pagar\s+([\d\.\-]+)/i', $texto, $m)) {
        $dados['unidade_consumidora'] = trim($m[1]);
    }

    foreach ($linhas as $idx => $linha) {
        if (stripos($linha, 'Referente a') !== false && isset($linhas[$idx + 1]) && preg_match('/^([A-Z]{3}\/\d{4})\s+(\d{2}\/\d{2}\/\d{4})\s+([\d\.,]+)/i', $linhas[$idx + 1], $m)) {
            $dados['referencia'] = trim($m[1]);
            $dados['vencimento'] = dataPtEnergia($m[2]) ?: '';
            $dados['valor_total'] = valorPtEnergia($m[3]);
            break;
        }
    }
    if ($dados['referencia'] === '' && preg_match('/Referente a\s+Vencimento\s+Valor a pagar \(R\$\)\s+([A-Z]{3}\/\d{4})\s+(\d{2}\/\d{2}\/\d{4})\s+([\d\.,]+)/i', $texto, $m)) {
        $dados['referencia'] = trim($m[1]);
        $dados['vencimento'] = dataPtEnergia($m[2]) ?: '';
        $dados['valor_total'] = valorPtEnergia($m[3]);
    }
    if (($dados['vencimento'] === '' || $dados['valor_total'] <= 0) && preg_match('/N\.?[ºO]?\s*da\s+Unidade\s+Consumidora\s+Vencimento\s+Total a pagar\s+[\r\n ]+[\d\.\-]+\s+(\d{2}\/\d{2}\/\d{4})\s+R\$\s*([\d\.,]+)/i', $texto, $m)) {
        $dados['vencimento'] = dataPtEnergia($m[1]) ?: $dados['vencimento'];
        $dados['valor_total'] = valorPtEnergia($m[2]);
    }

    if (preg_match('/Data de emiss\S*:\s*(\d{2}\/\d{2}\/\d{4})/i', $texto, $m)) {
        $dados['data_emissao'] = dataPtEnergia($m[1]) ?: '';
    }

    if (preg_match('/Energia\s+kWh\s+\S+\s+[\d\.\,]+\s+[\d\.\,]+\s+\d+\s+([\d\.\,]+)/i', $texto, $m)) {
        $dados['consumo_kwh'] = quantidadePtEnergia($m[1]);
    } elseif (preg_match('/ANO\s+Cons\.?\s+kWh.*?\n[A-Z]{3}\/\d{2}\s+([\d\.\,]+)/is', $texto, $m)) {
        $dados['consumo_kwh'] = quantidadePtEnergia($m[1]);
    }

    if (preg_match('/Custo de Disponibilidade\s+([\d\.,]+)/i', $texto, $m)) {
        $dados['custo_disponibilidade'] = valorPtEnergia($m[1]);
    }

    if (preg_match('/Contrib\s+Ilum\S*\s+Publica\s+Municipal\s+([\d\.,]+)/i', $texto, $m)) {
        $dados['contribuicao_iluminacao'] = valorPtEnergia($m[1]);
    }

    return $dados;
}

function salvarArquivoContaEnergia(array $arquivo): array
{
    $nomeOriginal = (string)($arquivo['name'] ?? '');
    $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    if ($extensao !== 'pdf') {
        throw new RuntimeException('Envie um arquivo PDF da conta de energia.');
    }
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Nao foi possivel receber o arquivo PDF.');
    }

    $dir = __DIR__ . '/../../uploads/energia';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Nao foi possivel criar a pasta de uploads de energia.');
    }

    $nomeSalvo = date('Ymd_His') . '_conta_energia_' . bin2hex(random_bytes(4)) . '.pdf';
    $destino = $dir . '/' . $nomeSalvo;
    if (!move_uploaded_file((string)$arquivo['tmp_name'], $destino)) {
        throw new RuntimeException('Nao foi possivel salvar o PDF enviado.');
    }

    return [
        'nome' => $nomeOriginal,
        'caminho' => 'uploads/energia/' . $nomeSalvo,
        'absoluto' => $destino,
    ];
}
