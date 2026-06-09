<?php

function garantirTabelasUnimed(PDO $pdo): void
{
    static $executado = false;
    if ($executado) {
        return;
    }
    $executado = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS unimed_beneficiarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            codigo_completo VARCHAR(24) NOT NULL,
            unidade_unimed VARCHAR(4) NOT NULL,
            contrato_unimed VARCHAR(4) NOT NULL,
            familia VARCHAR(6) NOT NULL,
            dependente VARCHAR(2) NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            nome VARCHAR(160) NOT NULL,
            responsavel_pagamento_id INT NULL,
            contrato_venda VARCHAR(80) NULL,
            plano VARCHAR(160) NULL,
            status_operacao CHAR(1) NULL,
            telefone_whatsapp VARCHAR(30) NULL,
            valor_mensalidade DECIMAL(12,2) NOT NULL DEFAULT 0,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_unimed_beneficiario_empresa_codigo (empresa_id, codigo_completo),
            INDEX idx_unimed_beneficiario_empresa_familia (empresa_id, familia),
            INDEX idx_unimed_beneficiario_responsavel (responsavel_pagamento_id),
            INDEX idx_unimed_beneficiario_nome (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmtColunaTelefone = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unimed_beneficiarios'
          AND COLUMN_NAME = 'telefone_whatsapp'
    ");
    $stmtColunaTelefone->execute();
    if ((int)$stmtColunaTelefone->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE unimed_beneficiarios ADD COLUMN telefone_whatsapp VARCHAR(30) NULL AFTER status_operacao");
    }

    $stmtColunaResponsavel = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unimed_beneficiarios'
          AND COLUMN_NAME = 'responsavel_pagamento_id'
    ");
    $stmtColunaResponsavel->execute();
    if ((int)$stmtColunaResponsavel->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE unimed_beneficiarios ADD COLUMN responsavel_pagamento_id INT NULL AFTER nome");
        $pdo->exec("ALTER TABLE unimed_beneficiarios ADD INDEX idx_unimed_beneficiario_responsavel (responsavel_pagamento_id)");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS unimed_faturas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            competencia CHAR(6) NOT NULL,
            numero_fatura VARCHAR(30) NOT NULL,
            unidade_unimed VARCHAR(4) NULL,
            contrato_unimed VARCHAR(4) NULL,
            cnpj VARCHAR(20) NULL,
            cliente VARCHAR(180) NULL,
            empresa_contratante_codigo VARCHAR(20) NULL,
            empresa_contratante_nome VARCHAR(180) NULL,
            competencia_utilizacao CHAR(6) NULL,
            numero_fatura_utilizacao VARCHAR(30) NULL,
            total_mensalidade DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_utilizacao DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_fatura DECIMAL(12,2) NOT NULL DEFAULT 0,
            arquivo_nome VARCHAR(255) NULL,
            arquivo_utilizacao_nome VARCHAR(255) NULL,
            fechado CHAR(1) NOT NULL DEFAULT 'N',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_unimed_fatura_empresa_competencia_numero (empresa_id, competencia, numero_fatura),
            INDEX idx_unimed_fatura_empresa_competencia (empresa_id, competencia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    foreach ([
        'competencia_utilizacao' => "ALTER TABLE unimed_faturas ADD COLUMN competencia_utilizacao CHAR(6) NULL AFTER empresa_contratante_nome",
        'numero_fatura_utilizacao' => "ALTER TABLE unimed_faturas ADD COLUMN numero_fatura_utilizacao VARCHAR(30) NULL AFTER competencia_utilizacao",
        'arquivo_utilizacao_nome' => "ALTER TABLE unimed_faturas ADD COLUMN arquivo_utilizacao_nome VARCHAR(255) NULL AFTER arquivo_nome",
    ] as $coluna => $sqlAlter) {
        $stmtColunaFatura = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'unimed_faturas'
              AND COLUMN_NAME = ?
        ");
        $stmtColunaFatura->execute([$coluna]);
        if ((int)$stmtColunaFatura->fetchColumn() === 0) {
            $pdo->exec($sqlAlter);
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS unimed_fatura_itens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fatura_id INT NOT NULL,
            beneficiario_id INT NULL,
            empresa_id INT NOT NULL,
            competencia CHAR(6) NOT NULL,
            codigo_completo VARCHAR(24) NOT NULL,
            unidade_unimed VARCHAR(4) NOT NULL,
            contrato_unimed VARCHAR(4) NOT NULL,
            familia VARCHAR(6) NOT NULL,
            dependente VARCHAR(2) NOT NULL,
            nome VARCHAR(160) NOT NULL,
            lancamento VARCHAR(80) NOT NULL DEFAULT 'MENSALIDADE',
            quantidade DECIMAL(12,5) NOT NULL DEFAULT 1,
            valor_mensalidade DECIMAL(12,2) NOT NULL DEFAULT 0,
            valor_utilizacao DECIMAL(12,2) NOT NULL DEFAULT 0,
            valor_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            bonificacao DECIMAL(12,2) NOT NULL DEFAULT 0,
            centro_custo VARCHAR(20) NULL,
            filial VARCHAR(20) NULL,
            status_operacao CHAR(1) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_unimed_item_fatura_codigo (fatura_id, codigo_completo, lancamento),
            INDEX idx_unimed_item_empresa_competencia (empresa_id, competencia),
            CONSTRAINT fk_unimed_fatura_itens_fatura
                FOREIGN KEY (fatura_id) REFERENCES unimed_faturas(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_unimed_fatura_itens_beneficiario
                FOREIGN KEY (beneficiario_id) REFERENCES unimed_beneficiarios(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS unimed_utilizacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fatura_id INT NOT NULL,
            beneficiario_id INT NULL,
            empresa_id INT NOT NULL,
            competencia CHAR(6) NOT NULL,
            codigo_completo VARCHAR(24) NOT NULL,
            unidade_unimed VARCHAR(4) NOT NULL,
            contrato_unimed VARCHAR(4) NOT NULL,
            familia VARCHAR(6) NOT NULL,
            dependente VARCHAR(2) NOT NULL,
            nome VARCHAR(160) NOT NULL,
            data_atendimento DATE NULL,
            prestador VARCHAR(180) NULL,
            tipo_documento VARCHAR(10) NULL,
            documento VARCHAR(30) NULL,
            quantidade DECIMAL(12,5) NOT NULL DEFAULT 1,
            valor_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_unimed_utilizacao_linha (fatura_id, codigo_completo, data_atendimento, documento, prestador, valor_total),
            INDEX idx_unimed_utilizacoes_empresa_competencia (empresa_id, competencia),
            INDEX idx_unimed_utilizacoes_familia (empresa_id, familia),
            CONSTRAINT fk_unimed_utilizacoes_fatura
                FOREIGN KEY (fatura_id) REFERENCES unimed_faturas(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_unimed_utilizacoes_beneficiario
                FOREIGN KEY (beneficiario_id) REFERENCES unimed_beneficiarios(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmtIndiceUtilizacaoLinha = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unimed_utilizacoes'
          AND INDEX_NAME = 'idx_unimed_utilizacao_linha'
    ");
    $stmtIndiceUtilizacaoLinha->execute();
    if ((int)$stmtIndiceUtilizacaoLinha->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE unimed_utilizacoes ADD INDEX idx_unimed_utilizacao_linha (fatura_id, codigo_completo, data_atendimento, documento, prestador, valor_total)");
    }

    $stmtIndiceUtilizacao = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unimed_utilizacoes'
          AND INDEX_NAME = 'uniq_unimed_utilizacao_linha'
    ");
    $stmtIndiceUtilizacao->execute();
    if ((int)$stmtIndiceUtilizacao->fetchColumn() > 0) {
        $pdo->exec("ALTER TABLE unimed_utilizacoes DROP INDEX uniq_unimed_utilizacao_linha");
    }
}

function moedaUnimed($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function competenciaUnimed(string $competencia): string
{
    if (preg_match('/^\d{6}$/', $competencia)) {
        return substr($competencia, 4, 2) . '/' . substr($competencia, 0, 4);
    }

    return $competencia;
}

function codigoUnimed(string $unidade, string $contrato, string $familia, string $dependente): string
{
    return $unidade . '.' . $contrato . '.' . $familia . '-' . $dependente;
}

function valorDecimalUnimed(string $valor): float
{
    $valor = trim($valor);
    $valor = str_replace(['.', ' '], ['', ''], $valor);
    $valor = str_replace(',', '.', $valor);

    return (float)$valor;
}

function textoLimpoUnimed(string $texto): string
{
    $texto = preg_replace('/\s+/', ' ', trim($texto));
    return $texto ?? '';
}

function salvarUploadUnimed(array $arquivo, string $tipo): array
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($arquivo['tmp_name']) || !is_uploaded_file($arquivo['tmp_name'])) {
        throw new RuntimeException('Selecione um arquivo PDF valido.');
    }

    $nomeOriginal = (string)($arquivo['name'] ?? 'arquivo.pdf');
    $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    if ($extensao !== 'pdf') {
        throw new RuntimeException('A importacao da Unimed aceita somente arquivos PDF.');
    }

    $pasta = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'unimed_faturas';
    if (!is_dir($pasta) && !mkdir($pasta, 0775, true) && !is_dir($pasta)) {
        throw new RuntimeException('Nao foi possivel criar a pasta de uploads da Unimed.');
    }

    $nomeSalvo = date('Ymd_His') . '_' . $tipo . '_' . bin2hex(random_bytes(4)) . '.pdf';
    $destino = $pasta . DIRECTORY_SEPARATOR . $nomeSalvo;
    if (!move_uploaded_file((string)$arquivo['tmp_name'], $destino)) {
        throw new RuntimeException('Nao foi possivel salvar o arquivo enviado.');
    }

    return [
        'original' => $nomeOriginal,
        'salvo' => 'uploads/unimed_faturas/' . $nomeSalvo,
        'absoluto' => $destino,
    ];
}

function extrairTextoPdfUnimed(string $arquivoPdf): string
{
    if (!is_file($arquivoPdf)) {
        throw new RuntimeException('Arquivo PDF nao encontrado para conversao.');
    }

    $tmpTxt = tempnam(sys_get_temp_dir(), 'unimed_pdf_');
    if ($tmpTxt === false) {
        throw new RuntimeException('Nao foi possivel criar arquivo temporario para conversao do PDF.');
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
        if (trim($texto) !== '') {
            @unlink($tmpTxt);
            return $texto;
        }
    }

    $pythonScript = tempnam(sys_get_temp_dir(), 'unimed_pdf_py_');
    if ($pythonScript === false) {
        @unlink($tmpTxt);
        throw new RuntimeException('Nao foi possivel criar script temporario para conversao do PDF.');
    }

    $pythonCodigo = "import sys\n"
        . "from pypdf import PdfReader\n\n"
        . "reader = PdfReader(sys.argv[1])\n"
        . "for page in reader.pages:\n"
        . "    print(page.extract_text() or \"\")\n";
    file_put_contents($pythonScript, $pythonCodigo);

    $pythonCandidates = array_filter([
        getenv('UNIMED_PYTHON') ?: '',
        getenv('PYTHON_BIN') ?: '',
        getenv('PYTHON') ?: '',
        'python3',
        'python',
        'py',
        'C:\\Users\\user\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python\\python.exe',
    ]);

    foreach ($pythonCandidates as $python) {
        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($pythonScript) . ' ' . escapeshellarg($arquivoPdf) . ' 2>&1';
        $saida = (string)@shell_exec($cmd);
        if (
            trim($saida) !== ''
            && (
                stripos($saida, 'ANAL') !== false
                || stripos($saida, 'FATURA') !== false
                || stripos($saida, 'BENEFICI') !== false
            )
            && stripos($saida, 'Traceback') === false
            && stripos($saida, 'No module named') === false
            && stripos($saida, 'Python não foi encontrado') === false
            && stripos($saida, 'Python nao foi encontrado') === false
        ) {
            @unlink($tmpTxt);
            @unlink($pythonScript);
            return $saida;
        }
    }

    @unlink($tmpTxt);
    @unlink($pythonScript);
    throw new RuntimeException('Nao foi possivel extrair texto do PDF. Instale pdftotext no servidor ou configure Python com pypdf em UNIMED_PYTHON.');
}

function parseValorTotalUnimed(array $linhas): float
{
    $total = 0.0;
    foreach ($linhas as $linha) {
        $total += (float)($linha['valor_total'] ?? 0);
    }

    return round($total, 2);
}

function parseFaturaMensalidadeUnimed(string $texto): array
{
    $numeroFatura = null;
    $competencia = null;

    if (preg_match('/FATURA\s+NRO\s+(\d+)/i', $texto, $mFatura)) {
        $numeroFatura = $mFatura[1];
    }

    if (preg_match('/Compet.{0,3}ncia:\s*(\d{6})/i', $texto, $mCompetencia)) {
        $competencia = $mCompetencia[1];
    }

    if (!$numeroFatura || !$competencia) {
        $linhasCabecalho = preg_split('/\R/', $texto);
        foreach ($linhasCabecalho as $i => $linha) {
            if (stripos($linha, 'COMPET') === false) {
                continue;
            }

            for ($j = $i + 1; $j <= min($i + 4, count($linhasCabecalho) - 1); $j++) {
                if (preg_match('/\b(?P<competencia>20\d{4})\s+(?P<fatura>\d{6,})\b/', $linhasCabecalho[$j], $mLinha)) {
                    $competencia = $competencia ?: $mLinha['competencia'];
                    $numeroFatura = $numeroFatura ?: $mLinha['fatura'];
                    break 2;
                }
            }
        }
    }

    if (!$numeroFatura) {
        throw new RuntimeException('Nao foi possivel identificar o numero da fatura de mensalidade.');
    }

    if (!$competencia) {
        throw new RuntimeException('Nao foi possivel identificar a competencia da fatura de mensalidade.');
    }

    preg_match('/CNPJ:\s*([0-9.\/-]+)/i', $texto, $mCnpj);
    preg_match('/Cliente\s+(.+)/i', $texto, $mCliente);
    preg_match('/EMPRESA\s+CONTRA\s*T\s*ANTE:\s*(\d+)\s*-\s*(.+)/i', $texto, $mEmpresaContratante);

    $itens = [];
    foreach (preg_split('/\R/', $texto) as $linha) {
        if (strpos($linha, 'MENSALIDADE') === false || !preg_match('/\d{4}\.\d{4}\.\d{6}-\d{2}/', $linha)) {
            continue;
        }

        if (!preg_match('/(?P<codigo>\d{4}\.\d{4}\.\d{6}-\d{2})\s+(?P<nome>.+?)\s+MENSALIDADE\s+(?P<mensalidade>[0-9.,]+)\s+(?P<bonificacao>[0-9.,]+)\s+(?P<total>[0-9.,]+)\s+(?P<centro>\d+)\s+(?P<filial>\d+)\s+(?P<status>[A-Z])\s+(?P<quantidade>[0-9.,]+)/', $linha, $m)) {
            continue;
        }

        [$unidade, $contrato, $familia, $dependente] = partesCodigoUnimed($m['codigo']);
        $itens[] = [
            'codigo_completo' => $m['codigo'],
            'unidade_unimed' => $unidade,
            'contrato_unimed' => $contrato,
            'familia' => $familia,
            'dependente' => $dependente,
            'tipo' => $dependente === '00' ? 'TITULAR' : 'DEPENDENTE',
            'nome' => textoLimpoUnimed($m['nome']),
            'lancamento' => 'MENSALIDADE',
            'quantidade' => valorDecimalUnimed($m['quantidade']),
            'valor_mensalidade' => valorDecimalUnimed($m['mensalidade']),
            'valor_utilizacao' => 0.0,
            'valor_total' => valorDecimalUnimed($m['total']),
            'bonificacao' => valorDecimalUnimed($m['bonificacao']),
            'centro_custo' => $m['centro'],
            'filial' => $m['filial'],
            'status_operacao' => $m['status'],
        ];
    }

    if (empty($itens)) {
        if (stripos($texto, 'RECIBO DO SACADO') !== false || stripos($texto, 'PAGAVEL EM QUALQUER BANCO') !== false || stripos($texto, 'PAGÁVEL EM QUALQUER BANCO') !== false) {
            throw new RuntimeException('Este PDF e o recibo/boleto da fatura ' . $numeroFatura . ' competencia ' . competenciaUnimed($competencia) . '. Ele nao contem os beneficiarios. Envie o arquivo ANALITICO DE TAXA para importar mensalidades por usuario e familia.');
        }

        throw new RuntimeException('Nenhum item de mensalidade foi encontrado no PDF enviado.');
    }

    return [
        'numero_fatura' => $numeroFatura,
        'competencia' => $competencia,
        'cnpj' => $mCnpj[1] ?? null,
        'cliente' => isset($mCliente[1]) ? textoLimpoUnimed($mCliente[1]) : null,
        'empresa_contratante_codigo' => $mEmpresaContratante[1] ?? null,
        'empresa_contratante_nome' => isset($mEmpresaContratante[2]) ? textoLimpoUnimed($mEmpresaContratante[2]) : null,
        'unidade_unimed' => $itens[0]['unidade_unimed'],
        'contrato_unimed' => $itens[0]['contrato_unimed'],
        'itens' => $itens,
        'total_mensalidade' => parseValorTotalUnimed($itens),
    ];
}

function parseFaturaUtilizacaoUnimed(string $texto): array
{
    $linhasTexto = preg_split('/\R/', $texto);
    $numeroFatura = null;
    foreach ($linhasTexto as $i => $linha) {
        if (stripos($linha, 'ANAL') !== false && stripos($linha, 'SERVI') !== false) {
            for ($j = $i + 1; $j <= min($i + 5, count($linhasTexto) - 1); $j++) {
                if (preg_match('/^\s*(\d{6,})\s*$/', $linhasTexto[$j], $m)) {
                    $numeroFatura = $m[1];
                    break 2;
                }
            }
        }
    }

    $competencia = null;
    if (preg_match('/Compet.{0,3}ncia\s+de:.*?(20\d{4})/is', $texto, $mCompetencia)) {
        $competencia = $mCompetencia[1];
    }
    if ($competencia === null) {
        foreach ($linhasTexto as $i => $linha) {
            if (stripos($linha, 'Compet') !== false) {
                for ($j = $i + 1; $j <= min($i + 8, count($linhasTexto) - 1); $j++) {
                    if (preg_match('/^\s*(20\d{4})\s*$/', $linhasTexto[$j], $m)) {
                        $competencia = $m[1];
                        break 2;
                    }
                }
            }
        }
    }

    if (!$numeroFatura) {
        throw new RuntimeException('Nao foi possivel identificar o numero da fatura de utilizacao.');
    }

    $familiaAtual = null;
    $unidadeAtual = null;
    $contratoAtual = null;
    $dependenteAtual = null;
    $nomeDependenteAtual = null;
    $itens = [];

    foreach ($linhasTexto as $linha) {
        $linhaCodigo = preg_replace('/(\d{4}\.\d{4}\.\d{5})\s+(\d)/', '$1$2', $linha);
        if (preg_match('/(?P<unidade>\d{4})\.(?P<contrato>\d{4})\.(?P<familia>\d{6})\s+TIT\s*:/', $linhaCodigo, $mFamilia)) {
            $unidadeAtual = $mFamilia['unidade'];
            $contratoAtual = $mFamilia['contrato'];
            $familiaAtual = $mFamilia['familia'];
            $dependenteAtual = null;
            $nomeDependenteAtual = null;
            continue;
        }

        if ($familiaAtual && preg_match('/^\s*(?P<dependente>\d{2})-(?P<nome>.+?)\s*$/', $linha, $mDep)) {
            $dependenteAtual = $mDep['dependente'];
            $nomeDependenteAtual = textoLimpoUnimed($mDep['nome']);
            continue;
        }

        if (!$familiaAtual || !$dependenteAtual || !preg_match('/^\s*(?P<valor>[0-9 ]+,\d{2})\s+[0-9 ]+,\d{2}\s+[0-9 ]+,\d{2}\s+(?P<quantidade>\d+,\d{5})\s+(?P<tipo>\d+)\s+(?P<documento>\d+).*?(?P<data>\d{1,2}\s*\/\s*\d{2}\s*\/\s*\d{4}|\d\s+\d\s*\/\s*\d{2}\s*\/\s*\d{4})\s+(?P<prestador>.+?)\s*$/', $linha, $mItem)) {
            continue;
        }

        $dataTexto = preg_replace('/\s+/', '', $mItem['data']);
        $dataAtendimento = DateTime::createFromFormat('d/m/Y', $dataTexto);
        if (!$dataAtendimento) {
            continue;
        }

        $codigo = codigoUnimed($unidadeAtual, $contratoAtual, $familiaAtual, $dependenteAtual);
        $itens[] = [
            'codigo_completo' => $codigo,
            'unidade_unimed' => $unidadeAtual,
            'contrato_unimed' => $contratoAtual,
            'familia' => $familiaAtual,
            'dependente' => $dependenteAtual,
            'tipo' => $dependenteAtual === '00' ? 'TITULAR' : 'DEPENDENTE',
            'nome' => $nomeDependenteAtual ?: $codigo,
            'data_atendimento' => $dataAtendimento->format('Y-m-d'),
            'prestador' => textoLimpoUnimed($mItem['prestador']),
            'tipo_documento' => $mItem['tipo'],
            'documento' => $mItem['documento'],
            'quantidade' => valorDecimalUnimed($mItem['quantidade']),
            'valor_total' => valorDecimalUnimed($mItem['valor']),
        ];
    }

    if (empty($itens)) {
        throw new RuntimeException('Nenhuma utilizacao foi encontrada no PDF enviado.');
    }

    if (!$competencia) {
        $maiorData = null;
        foreach ($itens as $item) {
            if ($maiorData === null || $item['data_atendimento'] > $maiorData) {
                $maiorData = $item['data_atendimento'];
            }
        }
        if ($maiorData) {
            $competencia = substr($maiorData, 0, 4) . substr($maiorData, 5, 2);
        }
    }

    if (!$competencia) {
        throw new RuntimeException('Nao foi possivel identificar a competencia da fatura de utilizacao.');
    }

    return [
        'numero_fatura' => $numeroFatura,
        'competencia' => $competencia,
        'itens' => $itens,
        'total_utilizacao' => parseValorTotalUnimed($itens),
    ];
}

function partesCodigoUnimed(string $codigo): array
{
    $codigo = str_replace(' ', '', $codigo);
    if (!preg_match('/^(\d{4})\.(\d{4})\.(\d{6})-(\d{2})$/', $codigo, $m)) {
        throw new RuntimeException('Codigo Unimed invalido: ' . $codigo);
    }

    return [$m[1], $m[2], $m[3], $m[4]];
}

function buscarBeneficiarioUnimed(PDO $pdo, int $empresaId, string $codigo): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM unimed_beneficiarios WHERE empresa_id = ? AND codigo_completo = ? LIMIT 1");
    $stmt->execute([$empresaId, $codigo]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int)$id : null;
}

function upsertBeneficiarioUnimed(PDO $pdo, int $empresaId, array $item): int
{
    $stmt = $pdo->prepare("
        INSERT INTO unimed_beneficiarios (
            empresa_id, codigo_completo, unidade_unimed, contrato_unimed, familia, dependente,
            tipo, nome, contrato_venda, plano, status_operacao, valor_mensalidade, ativo
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'S')
        ON DUPLICATE KEY UPDATE
            unidade_unimed = VALUES(unidade_unimed),
            contrato_unimed = VALUES(contrato_unimed),
            familia = VALUES(familia),
            dependente = VALUES(dependente),
            tipo = VALUES(tipo),
            nome = VALUES(nome),
            status_operacao = VALUES(status_operacao),
            valor_mensalidade = VALUES(valor_mensalidade),
            ativo = 'S'
    ");
    $stmt->execute([
        $empresaId,
        $item['codigo_completo'],
        $item['unidade_unimed'],
        $item['contrato_unimed'],
        $item['familia'],
        $item['dependente'],
        $item['tipo'],
        $item['nome'],
        $item['contrato_venda'] ?? null,
        $item['plano'] ?? null,
        $item['status_operacao'] ?? null,
        $item['valor_mensalidade'] ?? 0,
    ]);

    $id = buscarBeneficiarioUnimed($pdo, $empresaId, $item['codigo_completo']);
    if (!$id) {
        throw new RuntimeException('Nao foi possivel gravar o beneficiario ' . $item['codigo_completo']);
    }

    return $id;
}

function atualizarResponsaveisPadraoUnimed(PDO $pdo, int $empresaId): void
{
    $stmtTitulares = $pdo->prepare("
        SELECT id, familia
        FROM unimed_beneficiarios
        WHERE empresa_id = ?
          AND dependente = '00'
          AND ativo = 'S'
    ");
    $stmtTitulares->execute([$empresaId]);
    $titulares = $stmtTitulares->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmtAtualizaFamilia = $pdo->prepare("
        UPDATE unimed_beneficiarios
        SET responsavel_pagamento_id = ?
        WHERE empresa_id = ?
          AND familia = ?
          AND responsavel_pagamento_id IS NULL
    ");
    foreach ($titulares as $titularId => $familia) {
        $stmtAtualizaFamilia->execute([(int)$titularId, $empresaId, $familia]);
    }

    $pdo->prepare("
        UPDATE unimed_beneficiarios
        SET responsavel_pagamento_id = id
        WHERE empresa_id = ?
          AND responsavel_pagamento_id IS NULL
    ")->execute([$empresaId]);
}

function importarFaturaMensalidadeUnimed(PDO $pdo, int $empresaId, string $arquivoPdf, string $nomeOriginal): array
{
    $dados = parseFaturaMensalidadeUnimed(extrairTextoPdfUnimed($arquivoPdf));
    $totalMensalidade = round((float)$dados['total_mensalidade'], 2);

    $pdo->beginTransaction();
    try {
        $stmtFatura = $pdo->prepare("
            INSERT INTO unimed_faturas (
                empresa_id, competencia, numero_fatura, unidade_unimed, contrato_unimed, cnpj,
                cliente, empresa_contratante_codigo, empresa_contratante_nome,
                total_mensalidade, total_utilizacao, total_fatura, arquivo_nome
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)
            ON DUPLICATE KEY UPDATE
                unidade_unimed = VALUES(unidade_unimed),
                contrato_unimed = VALUES(contrato_unimed),
                cnpj = VALUES(cnpj),
                cliente = VALUES(cliente),
                empresa_contratante_codigo = VALUES(empresa_contratante_codigo),
                empresa_contratante_nome = VALUES(empresa_contratante_nome),
                total_mensalidade = VALUES(total_mensalidade),
                total_fatura = VALUES(total_mensalidade) + total_utilizacao,
                arquivo_nome = VALUES(arquivo_nome)
        ");
        $stmtFatura->execute([
            $empresaId,
            $dados['competencia'],
            $dados['numero_fatura'],
            $dados['unidade_unimed'],
            $dados['contrato_unimed'],
            $dados['cnpj'],
            $dados['cliente'],
            $dados['empresa_contratante_codigo'],
            $dados['empresa_contratante_nome'],
            $totalMensalidade,
            $totalMensalidade,
            $nomeOriginal,
        ]);

        $stmtBuscaFatura = $pdo->prepare("SELECT id FROM unimed_faturas WHERE empresa_id = ? AND competencia = ? AND numero_fatura = ? LIMIT 1");
        $stmtBuscaFatura->execute([$empresaId, $dados['competencia'], $dados['numero_fatura']]);
        $faturaId = (int)$stmtBuscaFatura->fetchColumn();

        $pdo->prepare("DELETE FROM unimed_fatura_itens WHERE fatura_id = ?")->execute([$faturaId]);

        $stmtItem = $pdo->prepare("
            INSERT INTO unimed_fatura_itens (
                fatura_id, beneficiario_id, empresa_id, competencia, codigo_completo, unidade_unimed,
                contrato_unimed, familia, dependente, nome, lancamento, quantidade,
                valor_mensalidade, valor_utilizacao, valor_total, bonificacao, centro_custo, filial, status_operacao
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)
        ");

        foreach ($dados['itens'] as $item) {
            $beneficiarioId = upsertBeneficiarioUnimed($pdo, $empresaId, $item);
            $stmtItem->execute([
                $faturaId,
                $beneficiarioId,
                $empresaId,
                $dados['competencia'],
                $item['codigo_completo'],
                $item['unidade_unimed'],
                $item['contrato_unimed'],
                $item['familia'],
                $item['dependente'],
                $item['nome'],
                $item['lancamento'],
                $item['quantidade'],
                $item['valor_mensalidade'],
                $item['valor_total'],
                $item['bonificacao'],
                $item['centro_custo'],
                $item['filial'],
                $item['status_operacao'],
            ]);
        }

        atualizarResponsaveisPadraoUnimed($pdo, $empresaId);
        $pdo->commit();

        return [
            'fatura_id' => $faturaId,
            'numero_fatura' => $dados['numero_fatura'],
            'competencia' => $dados['competencia'],
            'itens' => count($dados['itens']),
            'total' => $totalMensalidade,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function importarFaturaUtilizacaoUnimed(PDO $pdo, int $empresaId, int $faturaId, string $arquivoPdf, string $nomeOriginal): array
{
    $dados = parseFaturaUtilizacaoUnimed(extrairTextoPdfUnimed($arquivoPdf));
    $totalUtilizacao = round((float)$dados['total_utilizacao'], 2);

    $stmtFatura = $pdo->prepare("SELECT id, total_mensalidade FROM unimed_faturas WHERE id = ? AND empresa_id = ? LIMIT 1");
    $stmtFatura->execute([$faturaId, $empresaId]);
    $fatura = $stmtFatura->fetch(PDO::FETCH_ASSOC);
    if (!$fatura) {
        throw new RuntimeException('Selecione uma fatura mensal valida para vincular as utilizacoes.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE unimed_faturas
            SET competencia_utilizacao = ?,
                numero_fatura_utilizacao = ?,
                total_utilizacao = ?,
                total_fatura = total_mensalidade + ?,
                arquivo_utilizacao_nome = ?
            WHERE id = ?
              AND empresa_id = ?
        ")->execute([
            $dados['competencia'],
            $dados['numero_fatura'],
            $totalUtilizacao,
            $totalUtilizacao,
            $nomeOriginal,
            $faturaId,
            $empresaId,
        ]);

        $pdo->prepare("DELETE FROM unimed_utilizacoes WHERE fatura_id = ?")->execute([$faturaId]);

        $stmtItem = $pdo->prepare("
            INSERT INTO unimed_utilizacoes (
                fatura_id, beneficiario_id, empresa_id, competencia, codigo_completo, unidade_unimed,
                contrato_unimed, familia, dependente, nome, data_atendimento, prestador,
                tipo_documento, documento, quantidade, valor_total
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($dados['itens'] as $item) {
            $beneficiarioId = buscarBeneficiarioUnimed($pdo, $empresaId, $item['codigo_completo']);
            if (!$beneficiarioId) {
                $beneficiarioId = upsertBeneficiarioUnimed($pdo, $empresaId, $item);
            }

            $stmtItem->execute([
                $faturaId,
                $beneficiarioId,
                $empresaId,
                $dados['competencia'],
                $item['codigo_completo'],
                $item['unidade_unimed'],
                $item['contrato_unimed'],
                $item['familia'],
                $item['dependente'],
                $item['nome'],
                $item['data_atendimento'],
                $item['prestador'],
                $item['tipo_documento'],
                $item['documento'],
                $item['quantidade'],
                $item['valor_total'],
            ]);
        }

        atualizarResponsaveisPadraoUnimed($pdo, $empresaId);
        $pdo->commit();

        return [
            'fatura_id' => $faturaId,
            'numero_fatura' => $dados['numero_fatura'],
            'competencia' => $dados['competencia'],
            'itens' => count($dados['itens']),
            'total' => $totalUtilizacao,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
