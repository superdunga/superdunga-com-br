<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/inter_extrato.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$usuarioMaster = strtoupper((string)($_SESSION['nivel'] ?? '')) === 'MASTER';
$mensagemOk = '';
$mensagemErro = '';

function garantirTabelasConciliacaoExtratos(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_extratos_importacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            cbcontador INT NOT NULL,
            nome_arquivo VARCHAR(255) NOT NULL,
            arquivo_salvo VARCHAR(255) NULL,
            formato VARCHAR(20) NOT NULL,
            total_linhas INT NOT NULL DEFAULT 0,
            total_importado INT NOT NULL DEFAULT 0,
            total_duplicado INT NOT NULL DEFAULT 0,
            usuario_id INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fin_ext_imp_empresa (empresa_id, cbcontador, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_extrato_bancario (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            cbcontador INT NOT NULL,
            importacao_id INT NULL,
            data_movimento DATETIME NOT NULL,
            historico VARCHAR(500) NULL,
            documento VARCHAR(120) NULL,
            tipo CHAR(1) NOT NULL,
            valor DECIMAL(15,4) NOT NULL,
            identificador_banco VARCHAR(190) NOT NULL,
            bnc001_empresa INT NULL,
            bnc001_movcontador INT NULL,
            recebimento_id INT NULL,
            conciliado CHAR(1) NOT NULL DEFAULT 'N',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_fin_ext_identificador (empresa_id, cbcontador, identificador_banco),
            INDEX idx_fin_ext_pendentes (empresa_id, cbcontador, conciliado, data_movimento),
            INDEX idx_fin_ext_match (empresa_id, cbcontador, tipo, valor, data_movimento),
            INDEX idx_fin_ext_movcontador (bnc001_movcontador),
            INDEX idx_fin_ext_recebimento (recebimento_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmtColunaRecebimento = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'financeiro_extrato_bancario'
          AND column_name = 'recebimento_id'
    ");
    if ((int)$stmtColunaRecebimento->fetchColumn() === 0) {
        $pdo->exec("
            ALTER TABLE financeiro_extrato_bancario
            ADD COLUMN recebimento_id INT NULL AFTER bnc001_movcontador,
            ADD INDEX idx_fin_ext_recebimento (recebimento_id)
        ");
    }

    $stmtColunaBncEmpresa = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'financeiro_extrato_bancario'
          AND column_name = 'bnc001_empresa'
    ");
    if ((int)$stmtColunaBncEmpresa->fetchColumn() === 0) {
        $pdo->exec("
            ALTER TABLE financeiro_extrato_bancario
            ADD COLUMN bnc001_empresa INT NULL AFTER identificador_banco
        ");
    }

    $pdo->exec("
        UPDATE financeiro_extrato_bancario
        SET bnc001_empresa = empresa_id
        WHERE bnc001_movcontador IS NOT NULL
          AND bnc001_empresa IS NULL
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_extrato_conciliacoes_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            cbcontador INT NOT NULL,
            extrato_id INT NOT NULL,
            movcontador INT NOT NULL,
            tipo_match VARCHAR(30) NOT NULL,
            usuario_id INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fin_ext_log_empresa (empresa_id, cbcontador, criado_em),
            INDEX idx_fin_ext_log_extrato (extrato_id),
            INDEX idx_fin_ext_log_mov (movcontador)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_contas_extrato_regras (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            cbcontador INT NOT NULL,
            empresa_dona_extrato INT NOT NULL,
            cbcontador_dona_extrato INT NOT NULL,
            permitir_importacao CHAR(1) NOT NULL DEFAULT 'N',
            observacao VARCHAR(255) NULL,
            usuario_id INT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_fin_conta_ext_regra (empresa_id, cbcontador),
            INDEX idx_fin_conta_ext_dona (empresa_dona_extrato, cbcontador_dona_extrato)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmtColunaContaDona = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'financeiro_contas_extrato_regras'
          AND column_name = 'cbcontador_dona_extrato'
    ");
    if ((int)$stmtColunaContaDona->fetchColumn() === 0) {
        $pdo->exec("
            ALTER TABLE financeiro_contas_extrato_regras
            ADD COLUMN cbcontador_dona_extrato INT NOT NULL DEFAULT 0 AFTER empresa_dona_extrato
        ");
    }

    $pdo->exec("
        UPDATE financeiro_contas_extrato_regras
        SET cbcontador_dona_extrato = cbcontador
        WHERE cbcontador_dona_extrato = 0
    ");

    garantirIndiceConciliacaoExtratos($pdo, 'financeiro_extrato_bancario', 'idx_fin_ext_mov_empresa_bnc', "
        CREATE INDEX idx_fin_ext_mov_empresa_bnc
        ON financeiro_extrato_bancario (empresa_id, cbcontador, bnc001_empresa, bnc001_movcontador, conciliado)
    ");

    garantirIndiceConciliacaoExtratos($pdo, 'armazem_bnc001', 'idx_bnc001_conta_data', "
        CREATE INDEX idx_bnc001_conta_data
        ON armazem_bnc001 (EMPRESA, CBCONTADOR, DTMOV, MOVCONTADOR)
    ");

    garantirIndiceConciliacaoExtratos($pdo, 'armazem_bnc001', 'idx_bnc001_match_extrato', "
        CREATE INDEX idx_bnc001_match_extrato
        ON armazem_bnc001 (EMPRESA, CBCONTADOR, TIPOMOV, VALORMOV, DTMOV, MOVCONTADOR)
    ");
}

function garantirIndiceConciliacaoExtratos(PDO $pdo, string $tabela, string $indice, string $sql): void
{
    static $cache = [];
    $chave = $tabela . '.' . $indice;
    if (isset($cache[$chave])) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND index_name = ?
    ");
    $stmt->execute([$tabela, $indice]);

    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec($sql);
    }

    $cache[$chave] = true;
}

garantirTabelasConciliacaoExtratos($pdo_master);
garantirTabelaInterExtrato($pdo_master);

function moedaExtratoBanco($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function pagadorRecebivelExtratoBanco(string $historico): string
{
    $pagador = trim($historico);
    $pagador = preg_replace('/^pix\s+recebido\s*[:\-]\s*/i', '', $pagador) ?? $pagador;
    $pagador = trim($pagador, " \t\n\r\0\x0B\"'");

    if ($pagador === '') {
        return 'CLIENTE BANCO';
    }

    return mb_substr($pagador, 0, 150);
}

function descricaoRecebivelExtratoBanco(int $extratoId, int $cbcontador, string $documento): string
{
    $descricao = 'PIX/BANCO - Extrato #' . $extratoId . ' - Conta ' . $cbcontador;
    if ($documento !== '') {
        $descricao .= ' - Doc ' . $documento;
    }

    return mb_substr($descricao, 0, 255);
}

function dataHoraExtratoBanco($valor): string
{
    return $valor ? date('d/m/Y H:i', strtotime($valor)) : '-';
}

function queryConciliacaoExtratos(array $extra = []): string
{
    $params = $_GET;
    foreach ($extra as $chave => $valor) {
        if ($valor === null) {
            unset($params[$chave]);
        } else {
            $params[$chave] = $valor;
        }
    }

    return http_build_query($params);
}

function empresasParaRegraExtrato(PDO $pdo): array
{
    return $pdo->query("
        SELECT id, COALESCE(NULLIF(nome_fantasia, ''), NULLIF(razao_social, ''), CONCAT('Empresa ', id)) AS nome
        FROM empresas
        ORDER BY nome
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function contasParaRegraExtrato(PDO $pdo): array
{
    return $pdo->query("
        SELECT
            EMPRESA,
            CBCONTADOR,
            TRIM(COALESCE(NULLIF(TITULAR, ''), NULLIF(DESCABREV, ''), CONCAT('Conta ', CBCONTADOR))) AS nome_conta
        FROM armazem_bnc002
        WHERE COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
        ORDER BY EMPRESA, nome_conta, CBCONTADOR
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function buscarRegraContaExtrato(PDO $pdo, int $empresaId, int $cbcontador): ?array
{
    if ($empresaId <= 0 || $cbcontador <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            r.*,
            COALESCE(NULLIF(e.nome_fantasia, ''), NULLIF(e.razao_social, ''), CONCAT('Empresa ', e.id)) AS nome_empresa_dona,
            TRIM(COALESCE(NULLIF(c.TITULAR, ''), NULLIF(c.DESCABREV, ''), CONCAT('Conta ', r.cbcontador_dona_extrato))) AS nome_conta_dona
        FROM financeiro_contas_extrato_regras r
        LEFT JOIN empresas e ON e.id = r.empresa_dona_extrato
        LEFT JOIN armazem_bnc002 c ON c.EMPRESA = r.empresa_dona_extrato AND c.CBCONTADOR = r.cbcontador_dona_extrato
        WHERE r.empresa_id = ?
          AND r.cbcontador = ?
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $cbcontador]);
    $regra = $stmt->fetch(PDO::FETCH_ASSOC);

    return $regra ?: null;
}

function listarRegrasContaExtrato(PDO $pdo, int $empresaDonaExtrato, int $cbcontadorDonaExtrato): array
{
    if ($empresaDonaExtrato <= 0 || $cbcontadorDonaExtrato <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            r.*,
            COALESCE(NULLIF(e1.nome_fantasia, ''), NULLIF(e1.razao_social, ''), CONCAT('Empresa ', e1.id)) AS nome_empresa,
            COALESCE(NULLIF(e2.nome_fantasia, ''), NULLIF(e2.razao_social, ''), CONCAT('Empresa ', e2.id)) AS nome_empresa_dona,
            TRIM(COALESCE(NULLIF(c1.TITULAR, ''), NULLIF(c1.DESCABREV, ''), CONCAT('Conta ', r.cbcontador))) AS nome_conta,
            TRIM(COALESCE(NULLIF(c2.TITULAR, ''), NULLIF(c2.DESCABREV, ''), CONCAT('Conta ', r.cbcontador_dona_extrato))) AS nome_conta_dona
        FROM financeiro_contas_extrato_regras r
        LEFT JOIN empresas e1 ON e1.id = r.empresa_id
        LEFT JOIN empresas e2 ON e2.id = r.empresa_dona_extrato
        LEFT JOIN armazem_bnc002 c1 ON c1.EMPRESA = r.empresa_id AND c1.CBCONTADOR = r.cbcontador
        LEFT JOIN armazem_bnc002 c2 ON c2.EMPRESA = r.empresa_dona_extrato AND c2.CBCONTADOR = r.cbcontador_dona_extrato
        WHERE r.empresa_dona_extrato = ?
          AND r.cbcontador_dona_extrato = ?
        ORDER BY nome_empresa
    ");
    $stmt->execute([$empresaDonaExtrato, $cbcontadorDonaExtrato]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function conflitoDonoContaExtrato(PDO $pdo, int $empresaId, int $cbcontador): ?string
{
    if ($empresaId <= 0 || $cbcontador <= 0) {
        return null;
    }

    $regraUsuario = buscarRegraContaExtrato($pdo, $empresaId, $cbcontador);
    if ($regraUsuario
        && ((int)$regraUsuario['empresa_dona_extrato'] !== $empresaId || (int)$regraUsuario['cbcontador_dona_extrato'] !== $cbcontador)
    ) {
        return 'Esta conta ja esta configurada para usar o extrato da ' .
            (string)($regraUsuario['nome_empresa_dona'] ?? ('empresa ' . (int)$regraUsuario['empresa_dona_extrato'])) .
            ' / conta ' . (int)$regraUsuario['cbcontador_dona_extrato'] . '.';
    }

    $stmtExtratoProprio = $pdo->prepare("
        SELECT COUNT(*)
        FROM financeiro_extrato_bancario
        WHERE empresa_id = ?
          AND cbcontador = ?
    ");
    $stmtExtratoProprio->execute([$empresaId, $cbcontador]);
    if ((int)$stmtExtratoProprio->fetchColumn() > 0) {
        return null;
    }

    $stmtExtratoOutraEmpresa = $pdo->prepare("
        SELECT
            e.empresa_id,
            COUNT(*) AS qtd,
            COALESCE(NULLIF(emp.nome_fantasia, ''), NULLIF(emp.razao_social, ''), CONCAT('Empresa ', emp.id)) AS nome_empresa
        FROM financeiro_extrato_bancario e
        LEFT JOIN empresas emp ON emp.id = e.empresa_id
        WHERE e.cbcontador = ?
          AND e.empresa_id <> ?
        GROUP BY e.empresa_id, nome_empresa
        ORDER BY qtd DESC
        LIMIT 1
    ");
    $stmtExtratoOutraEmpresa->execute([$cbcontador, $empresaId]);
    $extratoOutraEmpresa = $stmtExtratoOutraEmpresa->fetch(PDO::FETCH_ASSOC);

    if ($extratoOutraEmpresa) {
        return 'Ja existem extratos importados desta conta na ' .
            (string)$extratoOutraEmpresa['nome_empresa'] .
            '. A regra deve ser administrada pela empresa dona do extrato.';
    }

    return null;
}

function validarImportacaoContaExtrato(PDO $pdo, int $empresaId, int $cbcontador): void
{
    $regra = buscarRegraContaExtrato($pdo, $empresaId, $cbcontador);
    if (!$regra) {
        $conflitoDono = conflitoDonoContaExtrato($pdo, $empresaId, $cbcontador);
        if ($conflitoDono !== null) {
            throw new RuntimeException($conflitoDono);
        }

        return;
    }

    if (($regra['permitir_importacao'] ?? 'N') === 'S') {
        return;
    }

    if ((int)$regra['empresa_dona_extrato'] === $empresaId && (int)$regra['cbcontador_dona_extrato'] === $cbcontador) {
        return;
    }

    $nomeDona = trim((string)($regra['nome_empresa_dona'] ?? ''));
    if ($nomeDona === '') {
        $nomeDona = 'empresa ' . (int)$regra['empresa_dona_extrato'];
    }

    throw new RuntimeException(
        'Esta conta esta configurada para usar o extrato da ' . $nomeDona .
        '. Importe por essa empresa para evitar duplicidade entre empresas.'
    );
}

function normalizarTextoExtrato($valor): string
{
    return trim((string)$valor);
}

function normalizarDecimalExtrato(string $valor): float
{
    $valor = trim($valor);
    $valor = preg_replace('/[^\d,\.\-]/', '', $valor);

    if ($valor === '' || $valor === '-' || $valor === null) {
        return 0.0;
    }

    $temVirgula = strpos($valor, ',') !== false;
    $temPonto = strpos($valor, '.') !== false;

    if ($temVirgula && $temPonto) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif ($temVirgula) {
        $valor = str_replace(',', '.', $valor);
    }

    return is_numeric($valor) ? (float)$valor : 0.0;
}

function normalizarDataExtrato(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }

    $formatos = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y'];
    foreach ($formatos as $formato) {
        $dt = DateTime::createFromFormat('!' . $formato, $valor);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $timestamp = strtotime($valor);
    return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
}

function gerarIdentificadorExtrato(int $empresaId, int $cbcontador, string $data, float $valor, string $tipo, string $historico, string $documento, string $identificadorOriginal = ''): string
{
    $base = $identificadorOriginal !== ''
        ? $identificadorOriginal
        : implode('|', [$empresaId, $cbcontador, date('Y-m-d H:i:s', strtotime($data)), number_format($valor, 4, '.', ''), $tipo, mb_strtolower($historico), mb_strtolower($documento)]);

    return sha1($base);
}

function gerarIdentificadorLinhaExtrato(int $empresaId, int $cbcontador, string $data, float $valor, string $tipo, string $historico, string $documento, string $identificadorOriginal = ''): string
{
    if ($identificadorOriginal !== '') {
        return sha1(implode('|', [
            $identificadorOriginal,
            date('Y-m-d H:i:s', strtotime($data)),
            $tipo,
            number_format($valor, 4, '.', ''),
            mb_strtolower(trim($historico)),
            mb_strtolower(trim($documento)),
        ]));
    }

    return gerarIdentificadorExtrato($empresaId, $cbcontador, $data, $valor, $tipo, $historico, $documento);
}

function gerarChaveNaturalExtrato(string $data, float $valor, string $tipo, string $historico, string $documento): string
{
    return implode('|', [
        date('Y-m-d', strtotime($data)),
        $tipo,
        number_format($valor, 4, '.', ''),
        mb_strtolower(trim($historico)),
        mb_strtolower(trim($documento)),
    ]);
}

function gerarIdentificadorNaturalExtrato(string $chaveNatural, int $ocorrenciaNatural): string
{
    return sha1('natural|' . $chaveNatural . '|' . max(1, $ocorrenciaNatural));
}

function detectarDelimitadorCsv(string $linha): string
{
    $delimitadores = [';', ',', "\t"];
    $melhor = ';';
    $maior = 0;

    foreach ($delimitadores as $delimitador) {
        $qtd = substr_count($linha, $delimitador);
        if ($qtd > $maior) {
            $maior = $qtd;
            $melhor = $delimitador;
        }
    }

    return $melhor;
}

function normalizarCabecalhoExtrato(string $valor): string
{
    $valor = mb_strtolower(trim($valor));
    $valor = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor) ?: $valor;
    return preg_replace('/[^a-z0-9]+/', '_', $valor);
}

function buscarCampoExtrato(array $linha, array $nomes): string
{
    foreach ($nomes as $nome) {
        if (array_key_exists($nome, $linha) && trim((string)$linha[$nome]) !== '') {
            return (string)$linha[$nome];
        }
    }

    return '';
}

function lerCsvExtrato(string $arquivo): array
{
    $handle = fopen($arquivo, 'r');
    if (!$handle) {
        return [];
    }

    $primeiraLinha = fgets($handle);
    if ($primeiraLinha === false) {
        fclose($handle);
        return [];
    }

    $primeiraLinha = preg_replace('/^\xEF\xBB\xBF/', '', $primeiraLinha);
    $delimitador = detectarDelimitadorCsv($primeiraLinha);
    $cabecalhos = array_map('normalizarCabecalhoExtrato', str_getcsv($primeiraLinha, $delimitador));
    $linhas = [];

    while (($dados = fgetcsv($handle, 0, $delimitador)) !== false) {
        if (count(array_filter($dados, static function ($v) {
            return trim((string)$v) !== '';
        })) === 0) {
            continue;
        }

        $linha = [];
        foreach ($cabecalhos as $idx => $cabecalho) {
            $linha[$cabecalho] = $dados[$idx] ?? '';
        }

        $tipoTexto = normalizarTextoExtrato(buscarCampoExtrato($linha, ['tipo', 'd_c', 'dc', 'debito_credito', 'entrada_saida']));
        $descricaoTexto = normalizarTextoExtrato(buscarCampoExtrato($linha, ['historico', 'descricao', 'descricao_lancamento', 'memo', 'titulo']));
        $historicoTexto = $descricaoTexto;
        if ($tipoTexto !== '' && !in_array(strtoupper(substr($tipoTexto, 0, 1)), ['C', 'D'], true)) {
            $historicoTexto = trim($tipoTexto . ($descricaoTexto !== '' ? ' - ' . $descricaoTexto : ''));
        }

        $linhas[] = [
            'data_movimento' => normalizarDataExtrato(buscarCampoExtrato($linha, ['data', 'data_movimento', 'dtmov', 'lancamento', 'data_lancamento'])),
            'historico' => $historicoTexto,
            'documento' => normalizarTextoExtrato(buscarCampoExtrato($linha, ['documento', 'doc', 'numero_documento', 'numdoc', 'identificador'])),
            'valor' => normalizarDecimalExtrato(buscarCampoExtrato($linha, ['valor', 'valor_movimento', 'valormov', 'amount'])),
            'tipo' => strtoupper(substr($tipoTexto, 0, 1)),
            'identificador' => normalizarTextoExtrato(buscarCampoExtrato($linha, ['id', 'identificador', 'nsu', 'fitid', 'codigo_da_transacao', 'codigo_transacao', 'codigo'])),
        ];
    }

    fclose($handle);
    return $linhas;
}

function lerOfxExtrato(string $arquivo): array
{
    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        return [];
    }
    $conteudoUtf8 = @mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
    if (is_string($conteudoUtf8) && $conteudoUtf8 !== '') {
        $conteudo = $conteudoUtf8;
    }

    preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $conteudo, $matches);
    $linhas = [];

    foreach ($matches[1] as $bloco) {
        $capturar = static function (string $tag) use ($bloco): string {
            if (preg_match('/<' . preg_quote($tag, '/') . '>([^<\r\n]+)/i', $bloco, $m)) {
                return trim($m[1]);
            }
            return '';
        };

        $valor = normalizarDecimalExtrato($capturar('TRNAMT'));
        $tipoOfx = strtoupper($capturar('TRNTYPE'));
        $dataRaw = $capturar('DTPOSTED');
        $data = null;
        if ($dataRaw !== '') {
            $data = substr($dataRaw, 0, 8);
            $data = substr($data, 0, 4) . '-' . substr($data, 4, 2) . '-' . substr($data, 6, 2) . ' 00:00:00';
        }

        $linhas[] = [
            'data_movimento' => $data,
            'historico' => normalizarTextoExtrato($capturar('MEMO') ?: $capturar('NAME')),
            'documento' => normalizarTextoExtrato($capturar('CHECKNUM') ?: $capturar('REFNUM')),
            'valor' => $valor,
            'tipo' => in_array($tipoOfx, ['CREDIT', 'DEP', 'DIRECTDEP', 'INT'], true) ? 'C' : 'D',
            'identificador' => normalizarTextoExtrato($capturar('FITID')),
        ];
    }

    return $linhas;
}

function pastaInterExtratoEmpresa(int $empresaId): string
{
    $base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'inter_extrato_config';
    $pasta = $base . DIRECTORY_SEPARATOR . 'empresa_' . $empresaId;

    if (!is_dir($base)) {
        mkdir($base, 0755, true);
    }

    $index = $base . DIRECTORY_SEPARATOR . 'index.php';
    if (!is_file($index)) {
        file_put_contents($index, "<?php\nhttp_response_code(403);\nexit;\n");
    }

    if (!is_dir($pasta)) {
        mkdir($pasta, 0755, true);
    }

    $indexEmpresa = $pasta . DIRECTORY_SEPARATOR . 'index.php';
    if (!is_file($indexEmpresa)) {
        file_put_contents($indexEmpresa, "<?php\nhttp_response_code(403);\nexit;\n");
    }

    return $pasta;
}

function nomeSeguroInterExtrato(string $nome): string
{
    $nome = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($nome));
    return trim($nome, '._') ?: ('arquivo_' . date('YmdHis'));
}

function salvarUploadInterExtrato(array $arquivo, int $empresaId, string $prefixo): ?string
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erro ao receber arquivo ' . $prefixo . '.');
    }

    $extensao = strtolower(pathinfo((string)$arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($extensao, ['crt', 'pem', 'cer', 'key', 'p12', 'pfx'], true)) {
        throw new RuntimeException('Arquivo ' . $prefixo . ' invalido. Use crt, pem, cer, key, p12 ou pfx.');
    }

    $destino = pastaInterExtratoEmpresa($empresaId) . DIRECTORY_SEPARATOR . $prefixo . '_' . date('YmdHis') . '_' . nomeSeguroInterExtrato((string)$arquivo['name']);
    if (!move_uploaded_file((string)$arquivo['tmp_name'], $destino)) {
        throw new RuntimeException('Nao foi possivel salvar arquivo ' . $prefixo . '.');
    }

    return $destino;
}

function salvarZipInterExtrato(array $arquivo, int $empresaId): array
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [];
    }

    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erro ao receber ZIP do Inter Extrato.');
    }

    if (strtolower(pathinfo((string)$arquivo['name'], PATHINFO_EXTENSION)) !== 'zip') {
        throw new RuntimeException('O pacote do Inter precisa ser um arquivo .zip.');
    }

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Extensao ZipArchive nao esta habilitada no PHP. Envie certificado e chave separadamente.');
    }

    $resultado = [];
    $zip = new ZipArchive();
    if ($zip->open((string)$arquivo['tmp_name']) !== true) {
        throw new RuntimeException('Nao foi possivel abrir o ZIP do Inter Extrato.');
    }

    $pasta = pastaInterExtratoEmpresa($empresaId);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nome = $zip->getNameIndex($i);
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (!in_array($ext, ['crt', 'pem', 'cer', 'key', 'p12', 'pfx'], true)) {
            continue;
        }

        $conteudo = $zip->getFromIndex($i);
        if ($conteudo === false || $conteudo === '') {
            continue;
        }

        $prefixo = $ext === 'key' ? 'key' : 'cert';
        $destino = $pasta . DIRECTORY_SEPARATOR . $prefixo . '_' . date('YmdHis') . '_' . nomeSeguroInterExtrato($nome);
        file_put_contents($destino, $conteudo);

        if ($prefixo === 'key') {
            $resultado['key_path'] = $destino;
        } else {
            $resultado['cert_path'] = $destino;
        }
    }

    $zip->close();

    if (empty($resultado['cert_path'])) {
        throw new RuntimeException('Nenhum certificado foi encontrado dentro do ZIP do Inter Extrato.');
    }

    return $resultado;
}

function importarLinhasExtratoBanco(PDO $pdo, int $empresaId, int $usuarioId, int $cbcontador, array $linhas, string $origem, ?string $arquivoSalvo = null): array
{
    if ($cbcontador <= 0) {
        throw new RuntimeException('Selecione a conta antes de importar o extrato.');
    }

    validarImportacaoContaExtrato($pdo, $empresaId, $cbcontador);

    $stmtImp = $pdo->prepare("
        INSERT INTO financeiro_extratos_importacoes
            (empresa_id, cbcontador, nome_arquivo, arquivo_salvo, formato, total_linhas, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtImp->execute([$empresaId, $cbcontador, $origem, $arquivoSalvo, $origem, count($linhas), $usuarioId]);
    $importacaoId = (int)$pdo->lastInsertId();

    $registrosImportacao = [];
    $identificadoresConsulta = [];
    $datasImportacao = [];
    $ocorrenciasNaturaisImportacao = [];
    foreach ($linhas as $linha) {
        if (empty($linha['data_movimento']) || (float)$linha['valor'] == 0.0) {
            continue;
        }

        $valor = abs((float)$linha['valor']);
        $tipo = in_array($linha['tipo'], ['C', 'D'], true)
            ? $linha['tipo']
            : (((float)$linha['valor'] >= 0) ? 'C' : 'D');
        $historicoLinha = (string)$linha['historico'];
        $documentoLinha = (string)$linha['documento'];
        $chaveNatural = gerarChaveNaturalExtrato((string)$linha['data_movimento'], $valor, $tipo, $historicoLinha, $documentoLinha);
        $ocorrenciasNaturaisImportacao[$chaveNatural] = ($ocorrenciasNaturaisImportacao[$chaveNatural] ?? 0) + 1;
        $ocorrenciaNatural = $ocorrenciasNaturaisImportacao[$chaveNatural];
        $datasImportacao[date('Y-m-d', strtotime((string)$linha['data_movimento']))] = true;
        $identificadorOriginal = (string)$linha['identificador'];
        $identificador = gerarIdentificadorNaturalExtrato($chaveNatural, $ocorrenciaNatural);
        $identificadoresLinha = [$identificador];
        if ($identificadorOriginal !== '') {
            $identificadoresLinha[] = gerarIdentificadorLinhaExtrato(
                $empresaId,
                $cbcontador,
                (string)$linha['data_movimento'],
                $valor,
                $tipo,
                $historicoLinha,
                $documentoLinha,
                $identificadorOriginal
            );
            $identificadoresLinha[] = gerarIdentificadorExtrato(
                $empresaId,
                $cbcontador,
                (string)$linha['data_movimento'],
                $valor,
                $tipo,
                $historicoLinha,
                $documentoLinha
            );
        }

        $registrosImportacao[$identificador] = [
            'dados' => [
                $empresaId,
                $cbcontador,
                $importacaoId,
                $linha['data_movimento'],
                mb_substr($historicoLinha, 0, 500),
                mb_substr($documentoLinha, 0, 120),
                $tipo,
                $valor,
                $identificador,
            ],
            'identificadores' => $identificadoresLinha,
            'chave_natural' => $chaveNatural,
            'ocorrencia_natural' => $ocorrenciaNatural,
            'tem_identificador_externo' => $identificadorOriginal !== '',
        ];
        foreach ($identificadoresLinha as $identificadorConsulta) {
            $identificadoresConsulta[$identificadorConsulta] = true;
        }
    }

    $identificadoresExistentes = [];
    foreach (array_chunk(array_keys($identificadoresConsulta), 500) as $loteIdentificadores) {
        if (empty($loteIdentificadores)) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($loteIdentificadores), '?'));
        $stmtExistentes = $pdo->prepare("
            SELECT identificador_banco
            FROM financeiro_extrato_bancario
            WHERE empresa_id = ?
              AND cbcontador = ?
              AND identificador_banco IN ({$placeholders})
        ");
        $stmtExistentes->execute(array_merge([$empresaId, $cbcontador], $loteIdentificadores));

        foreach ($stmtExistentes->fetchAll(PDO::FETCH_COLUMN) as $identificadorExistente) {
            $identificadoresExistentes[(string)$identificadorExistente] = true;
        }
    }

    $chavesNaturaisExistentes = [];
    if (!empty($datasImportacao)) {
        $datas = array_keys($datasImportacao);
        sort($datas);
        $dataInicial = $datas[0] . ' 00:00:00';
        $dataFinal = end($datas) . ' 23:59:59';
        $stmtNaturais = $pdo->prepare("
            SELECT data_movimento, tipo, valor, historico, documento, COUNT(*) AS qtd
            FROM financeiro_extrato_bancario
            WHERE empresa_id = ?
              AND cbcontador = ?
              AND data_movimento BETWEEN ? AND ?
            GROUP BY DATE(data_movimento), tipo, valor, LOWER(TRIM(historico)), LOWER(TRIM(documento))
        ");
        $stmtNaturais->execute([$empresaId, $cbcontador, $dataInicial, $dataFinal]);

        foreach ($stmtNaturais->fetchAll(PDO::FETCH_ASSOC) as $registroExistente) {
            $chavesNaturaisExistentes[gerarChaveNaturalExtrato(
                (string)$registroExistente['data_movimento'],
                (float)$registroExistente['valor'],
                (string)$registroExistente['tipo'],
                (string)$registroExistente['historico'],
                (string)$registroExistente['documento']
            )] = (int)$registroExistente['qtd'];
        }
    }

    $registrosNovos = [];
    foreach ($registrosImportacao as $identificador => $registroImportacao) {
        $existe = false;
        foreach ($registroImportacao['identificadores'] as $identificadorConsulta) {
            if (isset($identificadoresExistentes[$identificadorConsulta])) {
                $existe = true;
                break;
            }
        }

        $qtdNaturalExistente = (int)($chavesNaturaisExistentes[$registroImportacao['chave_natural']] ?? 0);
        $existePorChaveNatural = $qtdNaturalExistente >= (int)$registroImportacao['ocorrencia_natural'];

        if (!$existe && !$existePorChaveNatural) {
            $registrosNovos[] = $registroImportacao['dados'];
            $chavesNaturaisExistentes[$registroImportacao['chave_natural']] = max($qtdNaturalExistente, (int)$registroImportacao['ocorrencia_natural']);
        }
    }

    $importados = 0;
    foreach (array_chunk($registrosNovos, 300) as $loteRegistros) {
        if (empty($loteRegistros)) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($loteRegistros), '(?, ?, ?, ?, ?, ?, ?, ?, ?)'));
        $valoresInsert = [];
        foreach ($loteRegistros as $registroNovo) {
            array_push($valoresInsert, ...$registroNovo);
        }

        $stmtIns = $pdo->prepare("
            INSERT IGNORE INTO financeiro_extrato_bancario
                (empresa_id, cbcontador, importacao_id, data_movimento, historico, documento, tipo, valor, identificador_banco)
            VALUES {$placeholders}
        ");
        $stmtIns->execute($valoresInsert);
        $importados += $stmtIns->rowCount();
    }

    $duplicados = count($registrosImportacao) - $importados;
    $pdo->prepare("
        UPDATE financeiro_extratos_importacoes
        SET total_importado = ?, total_duplicado = ?
        WHERE id = ?
    ")->execute([$importados, $duplicados, $importacaoId]);

    return [
        'importados' => $importados,
        'duplicados' => $duplicados,
        'lidos' => count($registrosImportacao),
    ];
}

function conciliarExtratoComMovimento(PDO $pdo, int $empresaId, int $empresaExtratoId, int $cbcontadorSistema, int $usuarioId, int $extratoId, int $movcontador, string $tipoMatch = 'manual'): int
{
    if ($extratoId <= 0 || $movcontador <= 0) {
        throw new RuntimeException('Informe um match valido para conciliar.');
    }

    $stmtExtratoManual = $pdo->prepare("
        SELECT id, empresa_id, cbcontador, tipo, valor, conciliado, bnc001_movcontador
        FROM financeiro_extrato_bancario
        WHERE id = ?
          AND empresa_id = ?
        FOR UPDATE
    ");
    $stmtExtratoManual->execute([$extratoId, $empresaExtratoId]);
    $extratoManual = $stmtExtratoManual->fetch(PDO::FETCH_ASSOC);

    $stmtMovManual = $pdo->prepare("
        SELECT MOVCONTADOR, EMPRESA, CBCONTADOR, TIPOMOV, VALORMOV
        FROM armazem_bnc001
        WHERE MOVCONTADOR = ?
          AND EMPRESA = ?
          AND COALESCE(deletado, 'N') <> 'S'
        FOR UPDATE
    ");
    $stmtMovManual->execute([$movcontador, $empresaId]);
    $movManual = $stmtMovManual->fetch(PDO::FETCH_ASSOC);

    if (!$extratoManual || !$movManual) {
        throw new RuntimeException('Lancamento nao encontrado para esta empresa.');
    }

    if (($extratoManual['conciliado'] ?? 'N') === 'S' || !empty($extratoManual['bnc001_movcontador'])) {
        return 0;
    }

    if ($cbcontadorSistema > 0 && (int)$movManual['CBCONTADOR'] !== $cbcontadorSistema) {
        throw new RuntimeException('A conta bancaria do sistema nao confere com a conta selecionada.');
    }

    if ((string)$extratoManual['tipo'] !== (string)$movManual['TIPOMOV']) {
        throw new RuntimeException('O D/C do extrato e do sistema nao confere.');
    }

    if (abs(abs((float)$extratoManual['valor']) - abs((float)$movManual['VALORMOV'])) >= 0.01) {
        throw new RuntimeException('O valor do extrato e do sistema nao confere.');
    }

    $stmtMovJaConciliado = $pdo->prepare("
        SELECT COUNT(*)
        FROM financeiro_extrato_bancario
        WHERE bnc001_empresa = ?
          AND bnc001_movcontador = ?
          AND conciliado = 'S'
    ");
    $stmtMovJaConciliado->execute([$empresaId, $movcontador]);

    if ((int)$stmtMovJaConciliado->fetchColumn() > 0) {
        throw new RuntimeException('Este lancamento do sistema ja esta conciliado com outro extrato.');
    }

    $stmtUpdateManual = $pdo->prepare("
        UPDATE financeiro_extrato_bancario
        SET conciliado = 'S',
            bnc001_empresa = ?,
            bnc001_movcontador = ?
        WHERE id = ?
          AND empresa_id = ?
          AND conciliado = 'N'
          AND bnc001_movcontador IS NULL
    ");
    $stmtUpdateManual->execute([$empresaId, $movcontador, $extratoId, $empresaExtratoId]);

    if ($stmtUpdateManual->rowCount() <= 0) {
        return 0;
    }

    $stmtLogManual = $pdo->prepare("
        INSERT INTO financeiro_extrato_conciliacoes_log
            (empresa_id, cbcontador, extrato_id, movcontador, tipo_match, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmtLogManual->execute([$empresaId, (int)$movManual['CBCONTADOR'], $extratoId, $movcontador, $tipoMatch, $usuarioId]);

    return 1;
}

$stmtContas = $pdo_master->prepare("
    SELECT
        CBCONTADOR,
        TRIM(COALESCE(NULLIF(TITULAR, ''), NULLIF(DESCABREV, ''), CONCAT('Conta ', CBCONTADOR))) AS nome_conta
    FROM armazem_bnc002
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
      AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
      AND TRIM(COALESCE(CLASSIFICACAO, '')) IN ('1', '2')
    ORDER BY nome_conta ASC, CBCONTADOR ASC
");
$stmtContas->execute([$empresaId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

if (empty($contas)) {
    $stmtContasFallback = $pdo_master->prepare("
        SELECT DISTINCT CBCONTADOR, CONCAT('Conta ', CBCONTADOR) AS nome_conta
        FROM armazem_bnc001
        WHERE EMPRESA = ?
          AND CBCONTADOR IN (
              SELECT CBCONTADOR
              FROM armazem_bnc002
              WHERE EMPRESA = ?
                AND COALESCE(excluido_firebird, 'N') <> 'S'
                AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
                AND TRIM(COALESCE(CLASSIFICACAO, '')) IN ('1', '2')
          )
        ORDER BY CBCONTADOR
    ");
    $stmtContasFallback->execute([$empresaId, $empresaId]);
    $contas = $stmtContasFallback->fetchAll(PDO::FETCH_ASSOC);
}

$cbcontador = (int)($_GET['cbcontador'] ?? ($_POST['cbcontador'] ?? 0));
$dataIni = trim($_GET['data_ini'] ?? date('Y-m-01'));
$dataFim = trim($_GET['data_fim'] ?? date('Y-m-d'));
$dcFiltro = strtoupper(trim((string)($_GET['dc'] ?? '')));
$historicoFiltro = trim((string)($_GET['historico'] ?? ''));
$situacaoFiltro = trim((string)($_GET['situacao'] ?? 'pendentes'));

if (!in_array($dcFiltro, ['D', 'C'], true)) {
    $dcFiltro = '';
}
if (!in_array($situacaoFiltro, ['pendentes', 'conciliados', 'todos'], true)) {
    $situacaoFiltro = 'pendentes';
}

$dataIniSql = $dataIni !== '' ? $dataIni . ' 00:00:00' : '';
$dataFimExclusivoSql = $dataFim !== '' ? date('Y-m-d 00:00:00', strtotime($dataFim . ' +1 day')) : '';
$diasJanelaMatchManual = 15;
$dataIniSugestaoSql = $dataIni !== '' ? date('Y-m-d 00:00:00', strtotime($dataIni . " -{$diasJanelaMatchManual} days")) : '';
$dataFimSugestaoSql = $dataFim !== '' ? date('Y-m-d 00:00:00', strtotime($dataFim . " +{$diasJanelaMatchManual} days")) : '';
$configInterExtrato = buscarConfigInterExtrato($pdo_master, $empresaId) ?: [];
$regraContaSelecionada = $cbcontador > 0 ? buscarRegraContaExtrato($pdo_master, $empresaId, $cbcontador) : null;
$empresaExtratoId = $cbcontador > 0 && $regraContaSelecionada ? (int)$regraContaSelecionada['empresa_dona_extrato'] : $empresaId;
$cbcontadorExtrato = $cbcontador > 0 && $regraContaSelecionada ? (int)$regraContaSelecionada['cbcontador_dona_extrato'] : $cbcontador;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_inter_extrato') {
    $certPath = trim((string)($_POST['cert_path'] ?? ($configInterExtrato['cert_path'] ?? '')));
    $keyPath = trim((string)($_POST['key_path'] ?? ($configInterExtrato['key_path'] ?? '')));

    $dadosConfig = [
        'ambiente' => in_array(($_POST['ambiente'] ?? ''), ['producao', 'sandbox'], true) ? $_POST['ambiente'] : 'producao',
        'client_id' => trim((string)($_POST['client_id'] ?? '')),
        'client_secret' => trim((string)($_POST['client_secret'] ?? '')),
        'conta_corrente' => trim((string)($_POST['conta_corrente'] ?? '')),
        'cert_path' => $certPath,
        'key_path' => $keyPath,
        'cert_password' => trim((string)($_POST['cert_password'] ?? '')),
        'ativo' => ($_POST['ativo'] ?? 'S') === 'S' ? 'S' : 'N',
    ];

    try {
        if (isset($_FILES['pacote_inter_extrato'])) {
            $dadosConfig = array_merge($dadosConfig, salvarZipInterExtrato($_FILES['pacote_inter_extrato'], $empresaId));
        }
        if (isset($_FILES['certificado_inter_extrato'])) {
            $novoCert = salvarUploadInterExtrato($_FILES['certificado_inter_extrato'], $empresaId, 'cert');
            if ($novoCert !== null) {
                $dadosConfig['cert_path'] = $novoCert;
            }
        }
        if (isset($_FILES['chave_inter_extrato'])) {
            $novaKey = salvarUploadInterExtrato($_FILES['chave_inter_extrato'], $empresaId, 'key');
            if ($novaKey !== null) {
                $dadosConfig['key_path'] = $novaKey;
            }
        }

        salvarConfigInterExtrato($pdo_master, $empresaId, $dadosConfig);
        $configInterExtrato = buscarConfigInterExtrato($pdo_master, $empresaId) ?: [];
        $mensagemOk = 'Configuracao da API Inter Extrato salva com sucesso.';
    } catch (Throwable $e) {
        $mensagemErro = 'Erro ao salvar configuracao Inter Extrato: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_regra_conta_extrato') {
    try {
        if (!$usuarioMaster) {
            throw new RuntimeException('Somente usuario MASTER pode alterar a regra de importacao da conta.');
        }

        $cbcontadorRegra = (int)($_POST['cbcontador_regra'] ?? 0);
        $empresaRegraExtrato = (int)($_POST['empresa_regra_extrato'] ?? $empresaId);
        $cbcontadorDonaExtrato = (int)($_POST['cbcontador_dona_extrato'] ?? 0);
        $empresaDonaExtrato = $empresaId;
        $permitirImportacao = ($_POST['permitir_importacao'] ?? 'N') === 'S' ? 'S' : 'N';
        $observacaoRegra = trim((string)($_POST['observacao_regra'] ?? ''));

        if ($cbcontadorRegra <= 0) {
            throw new RuntimeException('Selecione a conta para configurar a regra de importacao.');
        }
        if ($empresaRegraExtrato <= 0) {
            throw new RuntimeException('Selecione a empresa que vai conciliar esta conta.');
        }
        if ($cbcontadorDonaExtrato <= 0) {
            throw new RuntimeException('Selecione a conta dona do extrato.');
        }

        $conflitoDono = conflitoDonoContaExtrato($pdo_master, $empresaId, $cbcontadorDonaExtrato);
        if ($conflitoDono !== null) {
            throw new RuntimeException($conflitoDono);
        }

        $stmtContaDona = $pdo_master->prepare("
            SELECT COUNT(*)
            FROM armazem_bnc002
            WHERE EMPRESA = ?
              AND CBCONTADOR = ?
              AND COALESCE(excluido_firebird, 'N') <> 'S'
              AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
        ");
        $stmtContaDona->execute([$empresaId, $cbcontadorDonaExtrato]);
        if ((int)$stmtContaDona->fetchColumn() === 0) {
            throw new RuntimeException('A conta dona informada nao pertence a empresa logada.');
        }

        $stmtContaAutorizada = $pdo_master->prepare("
            SELECT COUNT(*)
            FROM armazem_bnc002
            WHERE EMPRESA = ?
              AND CBCONTADOR = ?
              AND COALESCE(excluido_firebird, 'N') <> 'S'
              AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
        ");
        $stmtContaAutorizada->execute([$empresaRegraExtrato, $cbcontadorRegra]);
        if ((int)$stmtContaAutorizada->fetchColumn() === 0) {
            throw new RuntimeException('A conta autorizada nao pertence a empresa selecionada.');
        }

        $stmtRegra = $pdo_master->prepare("
            INSERT INTO financeiro_contas_extrato_regras
                (empresa_id, cbcontador, empresa_dona_extrato, cbcontador_dona_extrato, permitir_importacao, observacao, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                empresa_dona_extrato = VALUES(empresa_dona_extrato),
                cbcontador_dona_extrato = VALUES(cbcontador_dona_extrato),
                permitir_importacao = VALUES(permitir_importacao),
                observacao = VALUES(observacao),
                usuario_id = VALUES(usuario_id),
                atualizado_em = CURRENT_TIMESTAMP
        ");
        $stmtRegra->execute([
            $empresaRegraExtrato,
            $cbcontadorRegra,
            $empresaDonaExtrato,
            $cbcontadorDonaExtrato,
            $permitirImportacao,
            $observacaoRegra !== '' ? mb_substr($observacaoRegra, 0, 255) : null,
            $usuarioId > 0 ? $usuarioId : null,
        ]);

        header('Location: conciliacao_extratos.php?' . http_build_query([
            'cbcontador' => $cbcontadorDonaExtrato,
            'data_ini' => $dataIni,
            'data_fim' => $dataFim,
            'dc' => $dcFiltro,
            'historico' => $historicoFiltro,
            'situacao' => $situacaoFiltro,
            'ok_regra' => '1',
        ]));
        exit;
    } catch (Throwable $e) {
        $mensagemErro = 'Erro ao salvar regra da conta: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'testar_inter_extrato') {
    try {
        $configTeste = buscarConfigInterExtrato($pdo_master, $empresaId);
        if (!$configTeste || ($configTeste['ativo'] ?? 'N') !== 'S') {
            throw new RuntimeException('Configuracao da API Inter Extrato nao cadastrada ou inativa.');
        }
        obterTokenInterExtrato($pdo_master, $empresaId, $configTeste);
        $mensagemOk = 'Conexao com a API Inter Extrato realizada com sucesso.';
    } catch (Throwable $e) {
        $mensagemErro = 'Erro no teste da API Inter Extrato: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'consultar_inter_extrato') {
    $cbcontadorPost = (int)($_POST['cbcontador'] ?? 0);
    $dataIniPost = trim((string)($_POST['data_ini'] ?? $dataIni));
    $dataFimPost = trim((string)($_POST['data_fim'] ?? $dataFim));

    try {
        if ($cbcontadorPost <= 0) {
            throw new RuntimeException('Selecione a conta antes de consultar a API Inter Extrato.');
        }
        if ($dataIniPost === '' || $dataFimPost === '') {
            throw new RuntimeException('Informe data inicial e final para consultar a API Inter Extrato.');
        }
        if ((strtotime($dataFimPost) - strtotime($dataIniPost)) > 90 * 86400) {
            throw new RuntimeException('A API de extrato do Inter permite no maximo 90 dias por consulta.');
        }

        validarImportacaoContaExtrato($pdo_master, $empresaId, $cbcontadorPost);

        $diagnosticoInterExtrato = [];
        $linhasInter = listarInterExtrato($pdo_master, $empresaId, $dataIniPost, $dataFimPost, $diagnosticoInterExtrato);
        $resultadoInter = importarLinhasExtratoBanco(
            $pdo_master,
            $empresaId,
            $usuarioId,
            $cbcontadorPost,
            $linhasInter,
            'API_INTER_EXTRATO',
            null
        );

        header('Location: conciliacao_extratos.php?' . http_build_query([
            'cbcontador' => $cbcontadorPost,
            'data_ini' => $dataIniPost,
            'data_fim' => $dataFimPost,
            'ok' => '1',
            'importados' => $resultadoInter['importados'],
            'duplicados' => $resultadoInter['duplicados'],
            'inter_lidos' => $resultadoInter['lidos'],
        ]));
        exit;
    } catch (Throwable $e) {
        $mensagemErro = 'Erro na consulta Inter Extrato: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'conciliar_manual') {
    $extratoId = (int)($_POST['extrato_id'] ?? 0);
    $movcontador = (int)($_POST['movcontador'] ?? 0);

    try {
        $pdo_master->beginTransaction();
        conciliarExtratoComMovimento($pdo_master, $empresaId, $empresaExtratoId, $cbcontador, $usuarioId, $extratoId, $movcontador, 'manual');
        $pdo_master->commit();

        header('Location: conciliacao_extratos.php?' . queryConciliacaoExtratos(['ok_match' => 'manual']));
        exit;
    } catch (Throwable $e) {
        if ($pdo_master->inTransaction()) {
            $pdo_master->rollBack();
        }
        $mensagemErro = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'conciliar_manual_lote') {
    $matchesSelecionados = $_POST['matches'] ?? [];

    try {
        if (!is_array($matchesSelecionados) || empty($matchesSelecionados)) {
            throw new RuntimeException('Selecione ao menos um match manual para conciliar.');
        }

        $pdo_master->beginTransaction();
        $conciliadosLote = 0;

        foreach ($matchesSelecionados as $extratoIdLote => $movcontadorLote) {
            $extratoIdLote = (int)$extratoIdLote;
            $movcontadorLote = (int)$movcontadorLote;

            if ($extratoIdLote <= 0 || $movcontadorLote <= 0) {
                continue;
            }

            $conciliadosLote += conciliarExtratoComMovimento(
                $pdo_master,
                $empresaId,
                $empresaExtratoId,
                $cbcontador,
                $usuarioId,
                $extratoIdLote,
                $movcontadorLote,
                'manual_lote'
            );
        }

        $pdo_master->commit();

        header('Location: conciliacao_extratos.php?' . queryConciliacaoExtratos([
            'ok_match' => 'manual_lote',
            'qtd_manual' => $conciliadosLote,
        ]));
        exit;
    } catch (Throwable $e) {
        if ($pdo_master->inTransaction()) {
            $pdo_master->rollBack();
        }
        $mensagemErro = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'gerar_recebiveis_banco') {
    $extratosSelecionados = $_POST['extratos_recebiveis'] ?? [];
    $cmRecebivel = (int)($_POST['cm_recebivel'] ?? 12);

    try {
        if (!is_array($extratosSelecionados) || empty($extratosSelecionados)) {
            throw new RuntimeException('Selecione ao menos um lancamento do extrato para virar recebivel.');
        }

        if ($cmRecebivel <= 0) {
            throw new RuntimeException('Informe um CMCONTADOR valido.');
        }

        $pdo_master->beginTransaction();

        $stmtExtratoRecebivel = $pdo_master->prepare("
            SELECT id, empresa_id, cbcontador, data_movimento, historico, documento, tipo, valor, identificador_banco, recebimento_id
            FROM financeiro_extrato_bancario
            WHERE id = ?
              AND empresa_id = ?
            FOR UPDATE
        ");
        $stmtInsertRecebivel = $pdo_master->prepare("
            INSERT INTO armazem_conciliacao_recebimentos (
                empresa_id, origem, data_venda, data_prevista, data_recebimento,
                valor_bruto, valor_desconto, valor_liquido, identificador, descricao,
                pagador, parcela, total_parcelas, status, arquivo_origem, CMCONTADOR,
                tipo_operacao, bandeira, nsu_transacao, autorizacao, numero_estabelecimento
            ) VALUES (?, 'EXTRATO_BANCARIO', ?, ?, ?, ?, 0, ?, ?, ?, ?, 1, 1, 'LIQUIDADA', 'financeiro_extrato_bancario', ?, ?, 'PIX/BANCO', ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                pagador = VALUES(pagador),
                descricao = VALUES(descricao),
                CMCONTADOR = VALUES(CMCONTADOR),
                tipo_operacao = VALUES(tipo_operacao),
                bandeira = VALUES(bandeira),
                nsu_transacao = VALUES(nsu_transacao),
                autorizacao = VALUES(autorizacao),
                numero_estabelecimento = VALUES(numero_estabelecimento),
                status = VALUES(status),
                data_prevista = VALUES(data_prevista),
                data_recebimento = VALUES(data_recebimento)
        ");
        $stmtUpdateExtratoRecebivel = $pdo_master->prepare("
            UPDATE financeiro_extrato_bancario
            SET recebimento_id = ?
            WHERE id = ?
              AND empresa_id = ?
        ");

        $gerados = 0;
        foreach ($extratosSelecionados as $extratoIdSelecionado) {
            $extratoIdSelecionado = (int)$extratoIdSelecionado;
            if ($extratoIdSelecionado <= 0) {
                continue;
            }

            $stmtExtratoRecebivel->execute([$extratoIdSelecionado, $empresaExtratoId]);
            $extratoRecebivel = $stmtExtratoRecebivel->fetch(PDO::FETCH_ASSOC);

            if (!$extratoRecebivel || !empty($extratoRecebivel['recebimento_id'])) {
                continue;
            }

            if (($extratoRecebivel['tipo'] ?? '') !== 'C') {
                continue;
            }

            $dataVenda = (string)$extratoRecebivel['data_movimento'];
            $dataRecebimento = date('Y-m-d', strtotime($dataVenda));
            $valor = abs((float)$extratoRecebivel['valor']);
            $historico = trim((string)($extratoRecebivel['historico'] ?? ''));
            $documento = trim((string)($extratoRecebivel['documento'] ?? ''));
            $descricao = descricaoRecebivelExtratoBanco($extratoIdSelecionado, (int)$extratoRecebivel['cbcontador'], $documento);
            $pagador = pagadorRecebivelExtratoBanco($historico);
            $identificador = 'EXTRATO_BANCO_' . $empresaId . '_' . $extratoIdSelecionado;
            $tipoOperacaoRecebivel = in_array((string)$extratoRecebivel['tipo'], ['C', 'D'], true) ? (string)$extratoRecebivel['tipo'] : 'C';
            $nsuTransacao = mb_substr((string)$extratoRecebivel['identificador_banco'], 0, 50);
            $autorizacao = mb_substr($documento, 0, 20);
            $numeroEstabelecimento = (string)$extratoRecebivel['cbcontador'];

            $stmtInsertRecebivel->execute([
                $empresaId,
                $dataVenda,
                $dataRecebimento,
                $dataRecebimento,
                $valor,
                $valor,
                $identificador,
                mb_substr($descricao, 0, 255),
                $pagador,
                $cmRecebivel,
                $tipoOperacaoRecebivel,
                $nsuTransacao,
                $autorizacao,
                $numeroEstabelecimento,
            ]);

            $recebimentoId = (int)$pdo_master->lastInsertId();
            if ($recebimentoId > 0) {
            $stmtUpdateExtratoRecebivel->execute([$recebimentoId, $extratoIdSelecionado, $empresaExtratoId]);
                $gerados++;
            }
        }

        $pdo_master->commit();

        header('Location: conciliacao_extratos.php?' . queryConciliacaoExtratos([
            'ok_recebiveis' => '1',
            'qtd_recebiveis' => $gerados,
        ]));
        exit;
    } catch (Throwable $e) {
        if ($pdo_master->inTransaction()) {
            $pdo_master->rollBack();
        }
        $mensagemErro = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'conciliar_auto_seguro') {
    try {
        if ($cbcontador <= 0 || $dataIniSql === '' || $dataFimExclusivoSql === '') {
            throw new RuntimeException('Selecione conta e periodo para executar a conciliacao automatica.');
        }

        $whereAutoFiltros = '';
        $paramsAutoFiltros = [];

        if ($dcFiltro !== '') {
            $whereAutoFiltros .= ' AND e.tipo = ?';
            $paramsAutoFiltros[] = $dcFiltro;
        }
        if ($historicoFiltro !== '') {
            $whereAutoFiltros .= ' AND (e.historico LIKE ? OR b.HISTMOV LIKE ?)';
            $paramsAutoFiltros[] = '%' . $historicoFiltro . '%';
            $paramsAutoFiltros[] = '%' . $historicoFiltro . '%';
        }

        $stmtAuto = $pdo_master->prepare("
            WITH candidatos AS (
                SELECT
                    e.id AS extrato_id,
                    e.cbcontador,
                    b.MOVCONTADOR,
                    COUNT(*) OVER (PARTITION BY e.id) AS qtd_extrato,
                    COUNT(*) OVER (PARTITION BY b.MOVCONTADOR) AS qtd_sistema
                FROM financeiro_extrato_bancario e
                STRAIGHT_JOIN armazem_bnc001 b FORCE INDEX (idx_bnc001_match_extrato)
                   ON b.EMPRESA = ?
                   AND b.CBCONTADOR = ?
                   AND b.TIPOMOV = e.tipo
                   AND b.VALORMOV = e.valor
                   AND b.DTMOV >= DATE(e.data_movimento)
                   AND b.DTMOV < DATE_ADD(DATE(e.data_movimento), INTERVAL 1 DAY)
                   AND (b.deletado IS NULL OR b.deletado <> 'S')
                WHERE e.empresa_id = ?
                  AND e.cbcontador = ?
                  AND e.conciliado = 'N'
                  AND e.bnc001_movcontador IS NULL
                  AND e.data_movimento >= ?
                  AND e.data_movimento < ?
                  {$whereAutoFiltros}
                  AND NOT EXISTS (
                      SELECT 1
                      FROM financeiro_extrato_bancario ex2
                      WHERE ex2.bnc001_empresa = b.EMPRESA
                        AND ex2.bnc001_movcontador = b.MOVCONTADOR
                        AND ex2.conciliado = 'S'
                  )
            )
            SELECT extrato_id, cbcontador, MOVCONTADOR
            FROM candidatos
            WHERE qtd_extrato = 1
              AND qtd_sistema = 1
            LIMIT 500
        ");
        $stmtAuto->execute(array_merge([$empresaId, $cbcontador, $empresaExtratoId, $cbcontadorExtrato, $dataIniSql, $dataFimExclusivoSql], $paramsAutoFiltros));
        $matchesAuto = $stmtAuto->fetchAll(PDO::FETCH_ASSOC);

        $pdo_master->beginTransaction();

        $stmtUpdateAuto = $pdo_master->prepare("
            UPDATE financeiro_extrato_bancario
            SET conciliado = 'S',
                bnc001_empresa = ?,
                bnc001_movcontador = ?
            WHERE id = ?
              AND empresa_id = ?
              AND cbcontador = ?
              AND conciliado = 'N'
              AND bnc001_movcontador IS NULL
        ");
        $stmtLogAuto = $pdo_master->prepare("
            INSERT INTO financeiro_extrato_conciliacoes_log
                (empresa_id, cbcontador, extrato_id, movcontador, tipo_match, usuario_id)
            VALUES (?, ?, ?, ?, 'auto_seguro', ?)
        ");

        $conciliadosAuto = 0;
        foreach ($matchesAuto as $matchAuto) {
            $movAuto = (int)$matchAuto['MOVCONTADOR'];
            $extratoAuto = (int)$matchAuto['extrato_id'];
            $contaAuto = (int)$matchAuto['cbcontador'];

            $stmtUpdateAuto->execute([$empresaId, $movAuto, $extratoAuto, $empresaExtratoId, $contaAuto]);
            if ($stmtUpdateAuto->rowCount() > 0) {
                $stmtLogAuto->execute([$empresaId, $contaAuto, $extratoAuto, $movAuto, $usuarioId]);
                $conciliadosAuto++;
            }
        }

        $pdo_master->commit();

        header('Location: conciliacao_extratos.php?' . queryConciliacaoExtratos(['ok_match' => 'auto', 'qtd_auto' => $conciliadosAuto]));
        exit;
    } catch (Throwable $e) {
        if ($pdo_master->inTransaction()) {
            $pdo_master->rollBack();
        }
        $mensagemErro = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'importar') {
    $cbcontadorPost = (int)($_POST['cbcontador'] ?? 0);

    if ($cbcontadorPost <= 0) {
        $mensagemErro = 'Selecione a conta antes de importar o extrato.';
    } elseif (empty($_FILES['arquivo_extrato']['tmp_name']) || !is_uploaded_file($_FILES['arquivo_extrato']['tmp_name'])) {
        $mensagemErro = 'Selecione um arquivo de extrato.';
    } else {
        try {
            validarImportacaoContaExtrato($pdo_master, $empresaId, $cbcontadorPost);
        } catch (Throwable $e) {
            $mensagemErro = $e->getMessage();
        }

        if ($mensagemErro === '') {
        $nomeOriginal = basename((string)$_FILES['arquivo_extrato']['name']);
        $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));

        if (!in_array($extensao, ['csv', 'ofx'], true)) {
            $mensagemErro = 'Nesta primeira versao, envie arquivos CSV ou OFX. XLSX entra quando definirmos o layout do banco.';
        } else {
            $pastaUpload = __DIR__ . '/../../uploads/extratos_bancarios';
            if (!is_dir($pastaUpload)) {
                mkdir($pastaUpload, 0775, true);
            }

            $nomeSalvo = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $nomeOriginal);
            $destino = $pastaUpload . '/' . $nomeSalvo;

            if (!move_uploaded_file($_FILES['arquivo_extrato']['tmp_name'], $destino)) {
                $mensagemErro = 'Nao foi possivel salvar o arquivo enviado.';
            } else {
                $linhas = $extensao === 'ofx' ? lerOfxExtrato($destino) : lerCsvExtrato($destino);

                $stmtImp = $pdo_master->prepare("
                    INSERT INTO financeiro_extratos_importacoes
                        (empresa_id, cbcontador, nome_arquivo, arquivo_salvo, formato, total_linhas, usuario_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtImp->execute([$empresaId, $cbcontadorPost, $nomeOriginal, 'uploads/extratos_bancarios/' . $nomeSalvo, strtoupper($extensao), count($linhas), $usuarioId]);
                $importacaoId = (int)$pdo_master->lastInsertId();

                $registrosImportacao = [];
                $identificadoresConsulta = [];
                $datasImportacao = [];
                $ocorrenciasNaturaisImportacao = [];
                foreach ($linhas as $linha) {
                    if (empty($linha['data_movimento']) || (float)$linha['valor'] == 0.0) {
                        continue;
                    }

                    $valor = abs((float)$linha['valor']);
                    $tipo = in_array($linha['tipo'], ['C', 'D'], true)
                        ? $linha['tipo']
                        : (((float)$linha['valor'] >= 0) ? 'C' : 'D');
                    $historicoLinha = (string)$linha['historico'];
                    $documentoLinha = (string)$linha['documento'];
                    $chaveNatural = gerarChaveNaturalExtrato((string)$linha['data_movimento'], $valor, $tipo, $historicoLinha, $documentoLinha);
                    $ocorrenciasNaturaisImportacao[$chaveNatural] = ($ocorrenciasNaturaisImportacao[$chaveNatural] ?? 0) + 1;
                    $ocorrenciaNatural = $ocorrenciasNaturaisImportacao[$chaveNatural];
                    $datasImportacao[date('Y-m-d', strtotime((string)$linha['data_movimento']))] = true;
                    $identificadorOriginal = (string)$linha['identificador'];
                    $identificador = gerarIdentificadorNaturalExtrato($chaveNatural, $ocorrenciaNatural);
                    $identificadoresLinha = [$identificador];
                    if ($identificadorOriginal !== '') {
                        $identificadoresLinha[] = gerarIdentificadorLinhaExtrato(
                            $empresaId,
                            $cbcontadorPost,
                            (string)$linha['data_movimento'],
                            $valor,
                            $tipo,
                            $historicoLinha,
                            $documentoLinha,
                            $identificadorOriginal
                        );
                        $identificadoresLinha[] = gerarIdentificadorExtrato(
                            $empresaId,
                            $cbcontadorPost,
                            (string)$linha['data_movimento'],
                            $valor,
                            $tipo,
                            $historicoLinha,
                            $documentoLinha
                        );
                    }

                    $registrosImportacao[$identificador] = [
                        'dados' => [
                            $empresaId,
                            $cbcontadorPost,
                            $importacaoId,
                            $linha['data_movimento'],
                            mb_substr($historicoLinha, 0, 500),
                            mb_substr($documentoLinha, 0, 120),
                            $tipo,
                            $valor,
                            $identificador,
                        ],
                        'identificadores' => $identificadoresLinha,
                        'chave_natural' => $chaveNatural,
                        'ocorrencia_natural' => $ocorrenciaNatural,
                        'tem_identificador_externo' => $identificadorOriginal !== '',
                    ];
                    foreach ($identificadoresLinha as $identificadorConsulta) {
                        $identificadoresConsulta[$identificadorConsulta] = true;
                    }
                }

                $identificadoresExistentes = [];
                foreach (array_chunk(array_keys($identificadoresConsulta), 500) as $loteIdentificadores) {
                    if (empty($loteIdentificadores)) {
                        continue;
                    }

                    $placeholders = implode(',', array_fill(0, count($loteIdentificadores), '?'));
                    $stmtExistentes = $pdo_master->prepare("
                        SELECT identificador_banco
                        FROM financeiro_extrato_bancario
                        WHERE empresa_id = ?
                          AND cbcontador = ?
                          AND identificador_banco IN ({$placeholders})
                    ");
                    $stmtExistentes->execute(array_merge([$empresaId, $cbcontadorPost], $loteIdentificadores));

                    foreach ($stmtExistentes->fetchAll(PDO::FETCH_COLUMN) as $identificadorExistente) {
                        $identificadoresExistentes[(string)$identificadorExistente] = true;
                    }
                }

                $chavesNaturaisExistentes = [];
                if (!empty($datasImportacao)) {
                    $datas = array_keys($datasImportacao);
                    sort($datas);
                    $dataInicial = $datas[0] . ' 00:00:00';
                    $dataFinal = end($datas) . ' 23:59:59';
                    $stmtNaturais = $pdo_master->prepare("
                        SELECT data_movimento, tipo, valor, historico, documento, COUNT(*) AS qtd
                        FROM financeiro_extrato_bancario
                        WHERE empresa_id = ?
                          AND cbcontador = ?
                          AND data_movimento BETWEEN ? AND ?
                        GROUP BY DATE(data_movimento), tipo, valor, LOWER(TRIM(historico)), LOWER(TRIM(documento))
                    ");
                    $stmtNaturais->execute([$empresaId, $cbcontadorPost, $dataInicial, $dataFinal]);

                    foreach ($stmtNaturais->fetchAll(PDO::FETCH_ASSOC) as $registroExistente) {
                        $chavesNaturaisExistentes[gerarChaveNaturalExtrato(
                            (string)$registroExistente['data_movimento'],
                            (float)$registroExistente['valor'],
                            (string)$registroExistente['tipo'],
                            (string)$registroExistente['historico'],
                            (string)$registroExistente['documento']
                        )] = (int)$registroExistente['qtd'];
                    }
                }

                $registrosNovos = [];
                foreach ($registrosImportacao as $identificador => $registroImportacao) {
                    $existe = false;
                    foreach ($registroImportacao['identificadores'] as $identificadorConsulta) {
                        if (isset($identificadoresExistentes[$identificadorConsulta])) {
                            $existe = true;
                            break;
                        }
                    }

                    $qtdNaturalExistente = (int)($chavesNaturaisExistentes[$registroImportacao['chave_natural']] ?? 0);
                    $existePorChaveNatural = $qtdNaturalExistente >= (int)$registroImportacao['ocorrencia_natural'];

                    if (!$existe && !$existePorChaveNatural) {
                        $registrosNovos[] = $registroImportacao['dados'];
                        $chavesNaturaisExistentes[$registroImportacao['chave_natural']] = max($qtdNaturalExistente, (int)$registroImportacao['ocorrencia_natural']);
                    }
                }

                $importados = 0;
                foreach (array_chunk($registrosNovos, 300) as $loteRegistros) {
                    if (empty($loteRegistros)) {
                        continue;
                    }

                    $placeholders = implode(',', array_fill(0, count($loteRegistros), '(?, ?, ?, ?, ?, ?, ?, ?, ?)'));
                    $valoresInsert = [];
                    foreach ($loteRegistros as $registroNovo) {
                        array_push($valoresInsert, ...$registroNovo);
                    }

                    $stmtIns = $pdo_master->prepare("
                        INSERT IGNORE INTO financeiro_extrato_bancario
                            (empresa_id, cbcontador, importacao_id, data_movimento, historico, documento, tipo, valor, identificador_banco)
                        VALUES {$placeholders}
                    ");
                    $stmtIns->execute($valoresInsert);
                    $importados += $stmtIns->rowCount();
                }

                $duplicados = count($registrosImportacao) - $importados;

                $stmtUpd = $pdo_master->prepare("
                    UPDATE financeiro_extratos_importacoes
                    SET total_importado = ?, total_duplicado = ?
                    WHERE id = ?
                ");
                $stmtUpd->execute([$importados, $duplicados, $importacaoId]);

                header('Location: conciliacao_extratos.php?' . http_build_query([
                    'cbcontador' => $cbcontadorPost,
                    'ok' => '1',
                    'importados' => $importados,
                    'duplicados' => $duplicados,
                ]));
                exit;
            }
        }
        }
    }
}

if (($_GET['ok'] ?? '') === '1') {
    $mensagemOk = 'Extrato importado. Novos lancamentos: ' . (int)($_GET['importados'] ?? 0) . '. Duplicados ignorados: ' . (int)($_GET['duplicados'] ?? 0) . '.';
}

if (($_GET['ok_regra'] ?? '') === '1') {
    $mensagemOk = 'Regra de importacao da conta salva com sucesso.';
}

if (($_GET['ok_match'] ?? '') === 'manual') {
    $mensagemOk = 'Match conciliado manualmente.';
} elseif (($_GET['ok_match'] ?? '') === 'manual_lote') {
    $mensagemOk = 'Matches manuais conciliados: ' . (int)($_GET['qtd_manual'] ?? 0) . '.';
} elseif (($_GET['ok_match'] ?? '') === 'auto') {
    $mensagemOk = 'Conciliacao automatica segura concluida. Registros conciliados: ' . (int)($_GET['qtd_auto'] ?? 0) . '.';
}

if (($_GET['ok_recebiveis'] ?? '') === '1') {
    $mensagemOk = 'Recebiveis gerados a partir do extrato bancario: ' . (int)($_GET['qtd_recebiveis'] ?? 0) . '.';
}

$empresasExtrato = $usuarioMaster ? empresasParaRegraExtrato($pdo_master) : [];
$contasRegraExtrato = $usuarioMaster ? contasParaRegraExtrato($pdo_master) : [];
$regrasContaSelecionada = $usuarioMaster && $cbcontador > 0 ? listarRegrasContaExtrato($pdo_master, $empresaId, $cbcontador) : [];
$conflitoDonoContaSelecionada = $usuarioMaster && $cbcontador > 0 ? conflitoDonoContaExtrato($pdo_master, $empresaId, $cbcontador) : null;
$podeAdministrarRegraConta = $usuarioMaster && $cbcontador > 0 && $conflitoDonoContaSelecionada === null;
$bloqueioImportacaoConta = null;
if ($cbcontador > 0) {
    try {
        validarImportacaoContaExtrato($pdo_master, $empresaId, $cbcontador);
    } catch (Throwable $e) {
        $bloqueioImportacaoConta = $e->getMessage();
    }
}

$paramsExtrato = [$empresaExtratoId];
$whereExtrato = ['e.empresa_id = ?'];

if ($situacaoFiltro === 'pendentes') {
    $whereExtrato[] = "e.conciliado = 'N'";
} elseif ($situacaoFiltro === 'conciliados') {
    $whereExtrato[] = "e.conciliado = 'S'";
}

if ($cbcontador > 0) {
    $whereExtrato[] = 'e.cbcontador = ?';
    $paramsExtrato[] = $cbcontadorExtrato;
}
if ($dataIni !== '') {
    $whereExtrato[] = 'e.data_movimento >= ?';
    $paramsExtrato[] = $dataIniSql;
}
if ($dataFim !== '') {
    $whereExtrato[] = 'e.data_movimento < ?';
    $paramsExtrato[] = $dataFimExclusivoSql;
}
if ($dcFiltro !== '') {
    $whereExtrato[] = 'e.tipo = ?';
    $paramsExtrato[] = $dcFiltro;
}
if ($historicoFiltro !== '') {
    $whereExtrato[] = 'e.historico LIKE ?';
    $paramsExtrato[] = '%' . $historicoFiltro . '%';
}

$whereExtratoSql = implode(' AND ', $whereExtrato);

$stmtExtrato = $pdo_master->prepare("
    SELECT
        e.*,
        c.nome_conta,
        b.DTMOV AS data_sistema,
        b.HISTMOV AS historico_sistema,
        b.TIPOMOV AS tipo_sistema,
        b.VALORMOV AS valor_sistema
    FROM financeiro_extrato_bancario e
    LEFT JOIN (
        SELECT CBCONTADOR, TRIM(COALESCE(NULLIF(TITULAR, ''), NULLIF(DESCABREV, ''), CONCAT('Conta ', CBCONTADOR))) AS nome_conta
        FROM armazem_bnc002
        WHERE EMPRESA = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
          AND TRIM(COALESCE(CLASSIFICACAO, '')) IN ('1', '2')
    ) c ON c.CBCONTADOR = e.cbcontador
    LEFT JOIN armazem_bnc001 b
      ON b.EMPRESA = COALESCE(e.bnc001_empresa, e.empresa_id)
     AND b.MOVCONTADOR = e.bnc001_movcontador
    WHERE {$whereExtratoSql}
    ORDER BY e.data_movimento DESC, e.id DESC
    LIMIT 300
");
$stmtExtrato->execute(array_merge([$empresaId], $paramsExtrato));
$extratosPendentes = $stmtExtrato->fetchAll(PDO::FETCH_ASSOC);

$paramsBnc = [$empresaId];
$whereBnc = [
    'b.EMPRESA = ?',
    "(b.deletado IS NULL OR b.deletado <> 'S')",
];
if ($cbcontador > 0) {
    $whereBnc[] = 'b.CBCONTADOR = ?';
    $paramsBnc[] = $cbcontador;
}
if ($dataIni !== '') {
    $whereBnc[] = 'b.DTMOV >= ?';
    $paramsBnc[] = $dataIniSql;
}
if ($dataFim !== '') {
    $whereBnc[] = 'b.DTMOV < ?';
    $paramsBnc[] = $dataFimExclusivoSql;
}
if ($dcFiltro !== '') {
    $whereBnc[] = 'b.TIPOMOV = ?';
    $paramsBnc[] = $dcFiltro;
}
if ($historicoFiltro !== '') {
    $whereBnc[] = 'b.HISTMOV LIKE ?';
    $paramsBnc[] = '%' . $historicoFiltro . '%';
}
$whereBncSql = implode(' AND ', $whereBnc);

$stmtBnc = $pdo_master->prepare("
    SELECT
        b.MOVCONTADOR,
        b.CBCONTADOR,
        b.DTMOV,
        b.TIPOMOV,
        b.VALORMOV,
        b.HISTMOV,
        b.NUMDOC,
        c.nome_conta
    FROM armazem_bnc001 b
    LEFT JOIN (
        SELECT CBCONTADOR, TRIM(COALESCE(NULLIF(TITULAR, ''), NULLIF(DESCABREV, ''), CONCAT('Conta ', CBCONTADOR))) AS nome_conta
        FROM armazem_bnc002
        WHERE EMPRESA = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
          AND TRIM(COALESCE(CLASSIFICACAO, '')) IN ('1', '2')
    ) c ON c.CBCONTADOR = b.CBCONTADOR
    WHERE {$whereBncSql}
      AND NOT EXISTS (
          SELECT 1
          FROM financeiro_extrato_bancario e
          WHERE e.bnc001_empresa = b.EMPRESA
            AND e.bnc001_movcontador = b.MOVCONTADOR
            AND e.conciliado = 'S'
      )
    ORDER BY b.DTMOV DESC, b.MOVCONTADOR DESC
    LIMIT 300
");
$stmtBnc->execute(array_merge([$empresaId], $paramsBnc));
$sistemaPendentes = $stmtBnc->fetchAll(PDO::FETCH_ASSOC);

$sugestoes = [];
if ($situacaoFiltro !== 'conciliados' && $cbcontador > 0 && $dataIni !== '' && $dataFim !== '') {
    $whereSugestoesFiltros = '';
    $paramsSugestoesFiltros = [];

    if ($dcFiltro !== '') {
        $whereSugestoesFiltros .= ' AND e.tipo = ?';
        $paramsSugestoesFiltros[] = $dcFiltro;
    }
    if ($historicoFiltro !== '') {
        $whereSugestoesFiltros .= ' AND (e.historico LIKE ? OR b.HISTMOV LIKE ?)';
        $paramsSugestoesFiltros[] = '%' . $historicoFiltro . '%';
        $paramsSugestoesFiltros[] = '%' . $historicoFiltro . '%';
    }

    $stmtSug = $pdo_master->prepare("
        WITH candidatos AS (
            SELECT
                e.id AS extrato_id,
                e.data_movimento AS data_extrato,
                e.valor AS valor_extrato,
                e.tipo AS tipo_extrato,
                e.historico AS historico_extrato,
                b.MOVCONTADOR,
                b.DTMOV,
                b.VALORMOV,
                b.TIPOMOV,
                b.HISTMOV,
                ABS(TIMESTAMPDIFF(DAY, e.data_movimento, b.DTMOV)) AS dias_diferenca,
                COUNT(*) OVER (PARTITION BY e.id) AS qtd_por_extrato,
                COUNT(*) OVER (PARTITION BY b.MOVCONTADOR) AS qtd_por_sistema
            FROM financeiro_extrato_bancario e
            STRAIGHT_JOIN armazem_bnc001 b FORCE INDEX (idx_bnc001_match_extrato)
                ON b.EMPRESA = ?
               AND b.CBCONTADOR = ?
               AND b.VALORMOV = e.valor
               AND b.TIPOMOV = e.tipo
               AND b.DTMOV >= DATE_SUB(e.data_movimento, INTERVAL {$diasJanelaMatchManual} DAY)
               AND b.DTMOV < DATE_ADD(e.data_movimento, INTERVAL " . ($diasJanelaMatchManual + 1) . " DAY)
               AND b.DTMOV >= ?
               AND b.DTMOV < ?
               AND (b.deletado IS NULL OR b.deletado <> 'S')
            WHERE e.empresa_id = ?
              AND e.cbcontador = ?
              AND e.conciliado = 'N'
              AND e.bnc001_movcontador IS NULL
              AND e.data_movimento >= ?
              AND e.data_movimento < ?
              {$whereSugestoesFiltros}
              AND NOT EXISTS (
                  SELECT 1
                  FROM financeiro_extrato_bancario ex2
                  WHERE ex2.bnc001_empresa = b.EMPRESA
                    AND ex2.bnc001_movcontador = b.MOVCONTADOR
                    AND ex2.conciliado = 'S'
              )
        )
        SELECT *,
            CASE
                WHEN qtd_por_extrato = 1
                 AND qtd_por_sistema = 1
                 AND dias_diferenca = 0
                THEN 'auto'
                ELSE 'manual'
            END AS tipo_sugestao
        FROM candidatos
        ORDER BY tipo_sugestao ASC, dias_diferenca ASC, data_extrato DESC
        LIMIT 100
    ");
    $stmtSug->execute(array_merge([$empresaId, $cbcontador, $dataIniSugestaoSql, $dataFimSugestaoSql, $empresaExtratoId, $cbcontadorExtrato, $dataIniSql, $dataFimExclusivoSql], $paramsSugestoesFiltros));
    $sugestoes = $stmtSug->fetchAll(PDO::FETCH_ASSOC);
}

$sugestoesAutomaticas = [];
$sugestoesManuais = [];
foreach ($sugestoes as $sugestaoContagem) {
    if (($sugestaoContagem['tipo_sugestao'] ?? '') === 'auto') {
        $sugestoesAutomaticas[] = $sugestaoContagem;
    } else {
        $sugestoesManuais[] = $sugestaoContagem;
    }
}

$qtdSugestoesAutomaticas = count($sugestoesAutomaticas);
$qtdSugestoesManuais = count($sugestoesManuais);

$sugestoesManuaisPorExtrato = [];
foreach ($sugestoesManuais as $sugestaoManual) {
    $extratoIdGrupo = (int)$sugestaoManual['extrato_id'];
    if (!isset($sugestoesManuaisPorExtrato[$extratoIdGrupo])) {
        $sugestoesManuaisPorExtrato[$extratoIdGrupo] = [
            'extrato_id' => $extratoIdGrupo,
            'data_extrato' => $sugestaoManual['data_extrato'],
            'historico_extrato' => $sugestaoManual['historico_extrato'],
            'valor_extrato' => $sugestaoManual['valor_extrato'],
            'tipo_extrato' => $sugestaoManual['tipo_extrato'],
            'opcoes' => [],
        ];
    }
    $sugestoesManuaisPorExtrato[$extratoIdGrupo]['opcoes'][] = $sugestaoManual;
}

require '../../layout/header.php';
?>

<style>
.extrato-bancario-quadro {
    overflow-x: visible;
}

.extrato-bancario-quadro table {
    table-layout: fixed;
    width: 100%;
    font-size: 12px;
}

.extrato-bancario-quadro th,
.extrato-bancario-quadro td {
    white-space: normal;
    overflow-wrap: anywhere;
    vertical-align: middle;
}

.extrato-bancario-quadro th:nth-child(1),
.extrato-bancario-quadro td:nth-child(1) {
    width: 34px;
}

.extrato-bancario-quadro th:nth-child(2),
.extrato-bancario-quadro td:nth-child(2) {
    width: 46px;
}

.extrato-bancario-quadro th:nth-child(3),
.extrato-bancario-quadro td:nth-child(3) {
    width: 126px;
}

.extrato-bancario-quadro th:nth-child(5),
.extrato-bancario-quadro td:nth-child(5) {
    width: 42px;
    text-align: center;
}

.extrato-bancario-quadro th:nth-child(6),
.extrato-bancario-quadro td:nth-child(6) {
    width: 78px;
    white-space: nowrap;
}

.extrato-bancario-quadro th:nth-child(7),
.extrato-bancario-quadro td:nth-child(7) {
    width: 102px;
}

.historico-extrato {
    min-width: 0;
}

.extrato-bancario-quadro .badge {
    white-space: normal;
}

@media (max-width: 768px) {
    .extrato-bancario-quadro {
        overflow-x: auto;
    }
}
</style>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-warning mb-3">Financeiro</span>
                <h1 class="h3 fw-bold mb-2">Conciliacao de Extratos</h1>
                <p class="text-muted mb-0">Importe extratos bancarios e compare com os lancamentos do sistema antes de conciliar.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_financeiro.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagemOk): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensagemOk) ?></div>
<?php endif; ?>
<?php if ($mensagemErro): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div>
<?php endif; ?>
<?php if ($cbcontador > 0 && $empresaExtratoId !== $empresaId && $regraContaSelecionada): ?>
    <div class="alert alert-info">
        Esta conta esta compartilhada. A tela esta conciliando os lancamentos da empresa logada com o extrato importado pela
        <strong><?= htmlspecialchars((string)$regraContaSelecionada['nome_empresa_dona']) ?></strong>.
    </div>
<?php endif; ?>
<?php if ($bloqueioImportacaoConta !== null): ?>
    <div class="alert alert-warning">
        Importacao bloqueada para esta empresa/conta: <?= htmlspecialchars($bloqueioImportacaoConta) ?>
    </div>
<?php endif; ?>

<section class="mb-4">
    <div class="bg-white border rounded-2 shadow-sm p-3">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-lg-3">
                <label class="form-label">Conta</label>
                <select name="cbcontador" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach ($contas as $conta): ?>
                        <option value="<?= (int)$conta['CBCONTADOR'] ?>" <?= $cbcontador === (int)$conta['CBCONTADOR'] ? 'selected' : '' ?>>
                            <?= (int)$conta['CBCONTADOR'] ?> - <?= htmlspecialchars($conta['nome_conta']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Data inicial</label>
                <input type="date" name="data_ini" class="form-control" value="<?= htmlspecialchars($dataIni) ?>">
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Data final</label>
                <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($dataFim) ?>">
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">D/C</label>
                <select name="dc" class="form-select">
                    <option value="">Debito e credito</option>
                    <option value="D" <?= $dcFiltro === 'D' ? 'selected' : '' ?>>Debito</option>
                    <option value="C" <?= $dcFiltro === 'C' ? 'selected' : '' ?>>Credito</option>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label">Historico</label>
                <input type="text" name="historico" class="form-control" value="<?= htmlspecialchars($historicoFiltro) ?>" placeholder="Buscar no historico">
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Situacao</label>
                <select name="situacao" class="form-select">
                    <option value="pendentes" <?= $situacaoFiltro === 'pendentes' ? 'selected' : '' ?>>Nao conciliados</option>
                    <option value="conciliados" <?= $situacaoFiltro === 'conciliados' ? 'selected' : '' ?>>Conciliados</option>
                    <option value="todos" <?= $situacaoFiltro === 'todos' ? 'selected' : '' ?>>Todos</option>
                </select>
            </div>
            <div class="col-md-3 col-lg-1">
                <button type="submit" class="btn btn-primary w-100">Filtrar</button>
            </div>
        </form>
    </div>
</section>

<section class="mb-4">
    <div class="bg-white border rounded-2 shadow-sm p-3">
        <h2 class="h6 fw-bold mb-3">Importar extrato</h2>
        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
            <input type="hidden" name="acao" value="importar">
            <div class="col-lg-4">
                <label class="form-label">Conta do extrato</label>
                <select name="cbcontador" class="form-select" required>
                    <option value="">Selecione</option>
                    <?php foreach ($contas as $conta): ?>
                        <option value="<?= (int)$conta['CBCONTADOR'] ?>" <?= $cbcontador === (int)$conta['CBCONTADOR'] ? 'selected' : '' ?>>
                            <?= (int)$conta['CBCONTADOR'] ?> - <?= htmlspecialchars($conta['nome_conta']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-5">
                <label class="form-label">Arquivo CSV ou OFX</label>
                <input type="file" name="arquivo_extrato" class="form-control" accept=".csv,.ofx" required>
                <div class="form-text">CSV generico: colunas data, historico, documento, valor e tipo. OFX usa os campos padrao do extrato.</div>
            </div>
            <div class="col-lg-3">
                <button type="submit" class="btn btn-success w-100" <?= $bloqueioImportacaoConta !== null ? 'disabled' : '' ?>>Importar</button>
            </div>
        </form>
    </div>
</section>

<?php if ($usuarioMaster && $cbcontador > 0 && !$podeAdministrarRegraConta && $conflitoDonoContaSelecionada !== null): ?>
    <div class="alert alert-warning">
        Regra de conta compartilhada: <?= htmlspecialchars($conflitoDonoContaSelecionada) ?>
    </div>
<?php endif; ?>

<?php if ($podeAdministrarRegraConta): ?>
<section class="mb-4">
    <div class="bg-white border rounded-2 shadow-sm p-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h6 fw-bold mb-1">Regra de conta compartilhada</h2>
                <div class="text-muted small">Configure, pela empresa dona, quais empresas e contas podem conciliar usando este extrato importado.</div>
            </div>
            <?php if ($regraContaSelecionada): ?>
                <span class="badge text-bg-info align-self-lg-start">
                    Usando extrato: <?= htmlspecialchars((string)$regraContaSelecionada['nome_empresa_dona']) ?> /
                    <?= (int)$regraContaSelecionada['cbcontador_dona_extrato'] ?> - <?= htmlspecialchars((string)$regraContaSelecionada['nome_conta_dona']) ?>
                </span>
            <?php endif; ?>
        </div>

        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="acao" value="salvar_regra_conta_extrato">
            <div class="col-lg-3">
                <label class="form-label">Conta dona do extrato</label>
                <select name="cbcontador_dona_extrato" class="form-select" required>
                    <option value="">Selecione</option>
                    <?php foreach ($contas as $conta): ?>
                        <?php $contaNumero = (int)$conta['CBCONTADOR']; ?>
                        <option value="<?= $contaNumero ?>" <?= $cbcontador === $contaNumero ? 'selected' : '' ?>>
                            <?= $contaNumero ?> - <?= htmlspecialchars($conta['nome_conta']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Somente a empresa logada cadastra as regras das contas dela.</div>
            </div>
            <div class="col-lg-3">
                <label class="form-label">Empresa autorizada</label>
                <select name="empresa_regra_extrato" class="form-select" required>
                    <?php foreach ($empresasExtrato as $empresaExtrato): ?>
                        <option value="<?= (int)$empresaExtrato['id'] ?>" <?= $empresaId === (int)$empresaExtrato['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string)$empresaExtrato['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label">Conta da empresa autorizada</label>
                <select name="cbcontador_regra" class="form-select" required>
                    <option value="">Selecione</option>
                    <?php foreach ($contasRegraExtrato as $contaRegra): ?>
                        <option value="<?= (int)$contaRegra['CBCONTADOR'] ?>" data-empresa="<?= (int)$contaRegra['EMPRESA'] ?>" <?= ((int)$contaRegra['EMPRESA'] === $empresaId && $cbcontador === (int)$contaRegra['CBCONTADOR']) ? 'selected' : '' ?>>
                            Empresa <?= (int)$contaRegra['EMPRESA'] ?> - <?= (int)$contaRegra['CBCONTADOR'] ?> - <?= htmlspecialchars((string)$contaRegra['nome_conta']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 col-lg-3">
                <label class="form-label">Empresa autorizada pode importar?</label>
                <select name="permitir_importacao" class="form-select">
                    <option value="N" <?= (($regraContaSelecionada['permitir_importacao'] ?? 'N') !== 'S') ? 'selected' : '' ?>>Nao</option>
                    <option value="S" <?= (($regraContaSelecionada['permitir_importacao'] ?? 'N') === 'S') ? 'selected' : '' ?>>Sim</option>
                </select>
            </div>
            <div class="col-lg-9">
                <label class="form-label">Observacao</label>
                <input type="text" name="observacao_regra" class="form-control" maxlength="255" value="<?= htmlspecialchars((string)($regraContaSelecionada['observacao'] ?? '')) ?>" placeholder="Opcional">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-outline-primary">Salvar regra da conta</button>
            </div>
        </form>

        <?php if (!empty($regrasContaSelecionada)): ?>
            <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Empresa autorizada</th>
                            <th>Conta autorizada</th>
                            <th>Conta dona do extrato</th>
                            <th>Pode importar</th>
                            <th>Observacao</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regrasContaSelecionada as $regraConta): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$regraConta['nome_empresa']) ?></td>
                                <td><?= (int)$regraConta['cbcontador'] ?> - <?= htmlspecialchars((string)$regraConta['nome_conta']) ?></td>
                                <td><?= (int)$regraConta['cbcontador_dona_extrato'] ?> - <?= htmlspecialchars((string)$regraConta['nome_conta_dona']) ?></td>
                                <td><?= ($regraConta['permitir_importacao'] ?? 'N') === 'S' ? 'Sim' : 'Nao' ?></td>
                                <td><?= htmlspecialchars((string)($regraConta['observacao'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var empresaSelect = document.querySelector('select[name="empresa_regra_extrato"]');
    var contaSelect = document.querySelector('select[name="cbcontador_regra"]');
    if (!empresaSelect || !contaSelect) {
        return;
    }

    function filtrarContasAutorizadas() {
        var empresa = empresaSelect.value;
        var primeiraVisivel = '';
        Array.prototype.forEach.call(contaSelect.options, function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }
            var visivel = option.getAttribute('data-empresa') === empresa;
            option.hidden = !visivel;
            if (visivel && primeiraVisivel === '') {
                primeiraVisivel = option.value;
            }
        });

        var selecionada = contaSelect.options[contaSelect.selectedIndex];
        if (selecionada && selecionada.hidden) {
            contaSelect.value = primeiraVisivel;
        }
    }

    empresaSelect.addEventListener('change', filtrarContasAutorizadas);
    filtrarContasAutorizadas();
});
</script>
<?php endif; ?>

<section class="mb-4">
    <div class="bg-white border rounded-2 shadow-sm p-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h6 fw-bold mb-1">API Inter Extrato</h2>
                <div class="text-muted small">Consulta o extrato bancario do Inter e grava os lancamentos na mesma base usada pelo upload.</div>
            </div>
            <form method="POST" class="d-inline">
                <input type="hidden" name="acao" value="testar_inter_extrato">
                <button type="submit" class="btn btn-outline-primary btn-sm">Testar conexao</button>
            </form>
        </div>

        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end mb-3">
            <input type="hidden" name="acao" value="salvar_inter_extrato">
            <input type="hidden" name="cert_path" value="<?= htmlspecialchars((string)($configInterExtrato['cert_path'] ?? '')) ?>">
            <input type="hidden" name="key_path" value="<?= htmlspecialchars((string)($configInterExtrato['key_path'] ?? '')) ?>">

            <div class="col-md-3 col-lg-2">
                <label class="form-label">Ambiente</label>
                <select name="ambiente" class="form-select">
                    <option value="producao" <?= (($configInterExtrato['ambiente'] ?? 'producao') === 'producao') ? 'selected' : '' ?>>Producao</option>
                    <option value="sandbox" <?= (($configInterExtrato['ambiente'] ?? '') === 'sandbox') ? 'selected' : '' ?>>Sandbox</option>
                </select>
            </div>
            <div class="col-md-5 col-lg-3">
                <label class="form-label">Client ID</label>
                <input type="text" name="client_id" class="form-control" value="<?= htmlspecialchars((string)($configInterExtrato['client_id'] ?? '')) ?>">
            </div>
            <div class="col-md-4 col-lg-3">
                <label class="form-label">Client Secret</label>
                <input type="password" name="client_secret" class="form-control" placeholder="<?= !empty($configInterExtrato['client_secret']) ? 'Preenchido - informe apenas para trocar' : '' ?>">
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label">Conta corrente</label>
                <input type="text" name="conta_corrente" class="form-control" value="<?= htmlspecialchars((string)($configInterExtrato['conta_corrente'] ?? '')) ?>">
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label">Ativo</label>
                <select name="ativo" class="form-select">
                    <option value="S" <?= (($configInterExtrato['ativo'] ?? 'S') === 'S') ? 'selected' : '' ?>>Sim</option>
                    <option value="N" <?= (($configInterExtrato['ativo'] ?? '') === 'N') ? 'selected' : '' ?>>Nao</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">ZIP chave/certificado</label>
                <input type="file" name="pacote_inter_extrato" class="form-control" accept=".zip">
            </div>
            <div class="col-md-4">
                <label class="form-label">Certificado separado</label>
                <input type="file" name="certificado_inter_extrato" class="form-control" accept=".crt,.pem,.cer,.p12,.pfx">
            </div>
            <div class="col-md-4">
                <label class="form-label">Chave separada</label>
                <input type="file" name="chave_inter_extrato" class="form-control" accept=".key,.pem">
            </div>
            <div class="col-md-4">
                <label class="form-label">Senha do certificado</label>
                <input type="password" name="cert_password" class="form-control" placeholder="<?= !empty($configInterExtrato['cert_password']) ? 'Preenchida - informe apenas para trocar' : '' ?>">
            </div>
            <div class="col-md-8">
                <div class="small text-muted">
                    Certificado: <?= !empty($configInterExtrato['cert_path']) ? 'configurado' : 'nao configurado' ?> |
                    Chave: <?= !empty($configInterExtrato['key_path']) ? 'configurada' : 'nao configurada' ?>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-secondary">Salvar configuracao Inter Extrato</button>
            </div>
        </form>

        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="acao" value="consultar_inter_extrato">
            <div class="col-lg-4">
                <label class="form-label">Conta do extrato</label>
                <select name="cbcontador" class="form-select" required>
                    <option value="">Selecione</option>
                    <?php foreach ($contas as $conta): ?>
                        <option value="<?= (int)$conta['CBCONTADOR'] ?>" <?= $cbcontador === (int)$conta['CBCONTADOR'] ? 'selected' : '' ?>>
                            <?= (int)$conta['CBCONTADOR'] ?> - <?= htmlspecialchars($conta['nome_conta']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Data inicial</label>
                <input type="date" name="data_ini" class="form-control" value="<?= htmlspecialchars($dataIni) ?>" required>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Data final</label>
                <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($dataFim) ?>" required>
            </div>
            <div class="col-md-6 col-lg-4">
                <button type="submit" class="btn btn-success w-100" <?= $bloqueioImportacaoConta !== null ? 'disabled' : '' ?> onclick="return confirm('Consultar o extrato no Inter e gravar novos lancamentos?')">
                    Consultar e importar pela API
                </button>
            </div>
        </form>
    </div>
</section>

<section class="mb-4">
    <div class="row g-3">
        <div class="col-md-4">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="text-muted small">Extrato bancario pendente</div>
                <div class="h4 mb-0"><?= count($extratosPendentes) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="text-muted small">Sistema pendente</div>
                <div class="h4 mb-0"><?= count($sistemaPendentes) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="text-muted small">Sugestoes automaticas seguras</div>
                <div class="h4 mb-0"><?= $qtdSugestoesAutomaticas ?></div>
                <div class="text-muted small"><?= $qtdSugestoesManuais ?> sugestao(oes) para conferencia manual</div>
            </div>
        </div>
    </div>
</section>

<?php if ($cbcontador > 0 && $situacaoFiltro !== 'conciliados'): ?>
    <section class="mb-4">
        <div class="bg-white border rounded-2 shadow-sm overflow-hidden">
            <div class="p-3 border-bottom bg-light">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
                    <div>
                        <h2 class="h6 fw-bold mb-1">Matches seguros automaticos</h2>
                        <div class="text-muted small">Par unico na mesma data, mesma conta, mesmo valor e mesmo D/C.</div>
                    </div>
                    <form method="POST" onsubmit="return confirm('Conciliar automaticamente apenas matches seguros deste filtro?');">
                        <input type="hidden" name="acao" value="conciliar_auto_seguro">
                        <input type="hidden" name="cbcontador" value="<?= (int)$cbcontador ?>">
                        <button type="submit" class="btn btn-success btn-sm" <?= empty($sugestoesAutomaticas) ? 'disabled' : '' ?>>
                            Conciliar <?= $qtdSugestoesAutomaticas ?> seguro(s)
                        </button>
                    </form>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th>Extrato</th>
                            <th>Data banco</th>
                            <th>Historico banco</th>
                            <th>Sistema</th>
                            <th>Data sistema</th>
                            <th>Historico sistema</th>
                            <th>D/C</th>
                            <th class="text-end">Valor</th>
                            <th>Dias</th>
                            <th>Acao</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sugestoesAutomaticas as $sugestao): ?>
                            <tr>
                                <td><?= (int)$sugestao['extrato_id'] ?></td>
                                <td><?= dataHoraExtratoBanco($sugestao['data_extrato']) ?></td>
                                <td><?= htmlspecialchars($sugestao['historico_extrato'] ?: '-') ?></td>
                                <td><?= (int)$sugestao['MOVCONTADOR'] ?></td>
                                <td><?= dataHoraExtratoBanco($sugestao['DTMOV']) ?></td>
                                <td><?= htmlspecialchars($sugestao['HISTMOV'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($sugestao['tipo_extrato']) ?></td>
                                <td class="text-end"><?= moedaExtratoBanco($sugestao['valor_extrato']) ?></td>
                                <td><?= (int)$sugestao['dias_diferenca'] ?></td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('Confirmar conciliacao deste match?');">
                                        <input type="hidden" name="acao" value="conciliar_manual">
                                        <input type="hidden" name="extrato_id" value="<?= (int)$sugestao['extrato_id'] ?>">
                                        <input type="hidden" name="movcontador" value="<?= (int)$sugestao['MOVCONTADOR'] ?>">
                                        <button type="submit" class="btn btn-sm btn-primary">Conciliar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($sugestoesAutomaticas)): ?>
                            <tr><td colspan="10" class="text-center text-muted py-3">Nenhum match seguro automatico para os filtros atuais.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="mb-4">
        <div class="bg-white border rounded-2 shadow-sm overflow-hidden">
            <div class="p-3 border-bottom bg-light">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
                    <div>
                        <h2 class="h6 fw-bold mb-1">Conferencia manual guiada</h2>
                        <div class="text-muted small">Cada linha e um lancamento do extrato. Escolha o movimento correto do sistema e concilie varios de uma vez.</div>
                    </div>
                    <button type="submit" form="form-manual-lote" class="btn btn-primary btn-sm" <?= empty($sugestoesManuaisPorExtrato) ? 'disabled' : '' ?>>
                        Conciliar selecionados
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <form method="POST" id="form-manual-lote" onsubmit="return confirm('Conciliar todos os matches selecionados?');">
                    <input type="hidden" name="acao" value="conciliar_manual_lote">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-primary">
                            <tr>
                                <th>Extrato</th>
                                <th>Data banco</th>
                                <th>Historico banco</th>
                                <th>D/C</th>
                                <th class="text-end">Valor</th>
                                <th style="min-width: 360px;">Escolher lancamento do sistema</th>
                                <th>Motivos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sugestoesManuaisPorExtrato as $grupoManual): ?>
                                <tr>
                                    <td class="fw-semibold"><?= (int)$grupoManual['extrato_id'] ?></td>
                                    <td><?= dataHoraExtratoBanco($grupoManual['data_extrato']) ?></td>
                                    <td><?= htmlspecialchars($grupoManual['historico_extrato'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($grupoManual['tipo_extrato']) ?></td>
                                    <td class="text-end"><?= moedaExtratoBanco($grupoManual['valor_extrato']) ?></td>
                                    <td>
                                        <select name="matches[<?= (int)$grupoManual['extrato_id'] ?>]" class="form-select form-select-sm">
                                            <option value="">Nao conciliar agora</option>
                                            <?php foreach ($grupoManual['opcoes'] as $opcaoManual): ?>
                                                <option value="<?= (int)$opcaoManual['MOVCONTADOR'] ?>">
                                                    <?= (int)$opcaoManual['MOVCONTADOR'] ?>
                                                    | <?= dataHoraExtratoBanco($opcaoManual['DTMOV']) ?>
                                                    | <?= htmlspecialchars(mb_substr((string)$opcaoManual['HISTMOV'], 0, 80)) ?>
                                                    | dias <?= (int)$opcaoManual['dias_diferenca'] ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <?php
                                            $temDataProxima = false;
                                            $temDuplicidade = false;
                                            foreach ($grupoManual['opcoes'] as $opcaoManual) {
                                                $temDataProxima = $temDataProxima || (int)($opcaoManual['dias_diferenca'] ?? 0) !== 0;
                                                $temDuplicidade = $temDuplicidade || (int)($opcaoManual['qtd_por_extrato'] ?? 0) > 1 || (int)($opcaoManual['qtd_por_sistema'] ?? 0) > 1;
                                            }
                                        ?>
                                        <?php if ($temDataProxima): ?>
                                            <span class="badge text-bg-secondary">Data proxima</span>
                                        <?php endif; ?>
                                        <?php if ($temDuplicidade): ?>
                                            <span class="badge text-bg-warning">Duplicidade</span>
                                        <?php endif; ?>
                                        <span class="badge text-bg-light border text-dark"><?= count($grupoManual['opcoes']) ?> opcao(oes)</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($sugestoesManuaisPorExtrato)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">Nenhum match manual para os filtros atuais.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </form>
            </div>
            <?php if (!empty($sugestoesManuaisPorExtrato)): ?>
                <div class="p-3 border-top bg-light text-end">
                    <button type="submit" form="form-manual-lote" class="btn btn-primary">Conciliar selecionados</button>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<section class="mb-4">
    <div class="row g-3">
        <div class="col-xl-6">
            <div class="bg-white border rounded-2 shadow-sm overflow-hidden">
                <div class="p-3 border-bottom bg-light">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
                        <div>
                            <h2 class="h6 fw-bold mb-1">
                                Extrato bancario
                                <?php if ($situacaoFiltro === 'conciliados'): ?>
                                    conciliado
                                <?php elseif ($situacaoFiltro === 'todos'): ?>
                                    todos
                                <?php else: ?>
                                    nao conciliado
                                <?php endif; ?>
                            </h2>
                            <div class="text-muted small">Use o filtro de situacao para consultar pendentes, conciliados ou todos os lancamentos.</div>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <select name="cm_recebivel" form="form-gerar-recebiveis" class="form-select form-select-sm" style="width: 120px;">
                                <option value="12" selected>CM 12</option>
                                <option value="7">CM 7</option>
                                <option value="2">CM 2</option>
                                <option value="3">CM 3</option>
                                <option value="6">CM 6</option>
                                <option value="14">CM 14</option>
                            </select>
                            <button type="submit" form="form-gerar-recebiveis" class="btn btn-sm btn-success" onclick="return confirm('Gerar recebiveis para os creditos selecionados?');">
                                Gerar recebiveis
                            </button>
                        </div>
                    </div>
                </div>
                <div class="table-responsive extrato-bancario-quadro">
                    <form method="POST" id="form-gerar-recebiveis">
                        <input type="hidden" name="acao" value="gerar_recebiveis_banco">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-primary">
                                <tr>
                                    <th style="width: 34px;"></th>
                                    <th>ID</th>
                                    <th>Conta / Data</th>
                                    <th>Historico</th>
                                    <th>D/C</th>
                                    <th class="text-end">Valor</th>
                                    <th>Status / Vinculos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($extratosPendentes as $item): ?>
                                    <tr>
                                        <td>
                                            <?php if (($item['conciliado'] ?? 'N') === 'N' && ($item['tipo'] ?? '') === 'C' && empty($item['recebimento_id'])): ?>
                                                <input type="checkbox" name="extratos_recebiveis[]" value="<?= (int)$item['id'] ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td><?= (int)$item['id'] ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= (int)$item['cbcontador'] ?> - <?= htmlspecialchars($item['nome_conta'] ?: 'Conta') ?></div>
                                            <div class="small text-muted"><?= dataHoraExtratoBanco($item['data_movimento']) ?></div>
                                        </td>
                                        <td class="historico-extrato">
                                            <div><?= htmlspecialchars($item['historico'] ?: '-') ?></div>
                                            <?php if (!empty($item['documento'])): ?>
                                                <div class="small text-muted">Doc: <?= htmlspecialchars($item['documento']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($item['tipo']) ?></td>
                                        <td class="text-end"><?= moedaExtratoBanco($item['valor']) ?></td>
                                        <td>
                                            <?php if (($item['conciliado'] ?? 'N') === 'S'): ?>
                                                <span class="badge text-bg-success">Conciliado</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-warning">Pendente</span>
                                            <?php endif; ?>

                                            <?php if (!empty($item['bnc001_movcontador'])): ?>
                                                <div class="small fw-semibold mt-1">MOV <?= (int)$item['bnc001_movcontador'] ?></div>
                                                <div class="small text-muted"><?= dataHoraExtratoBanco($item['data_sistema'] ?? null) ?></div>
                                                <div class="small"><?= htmlspecialchars(mb_substr((string)($item['historico_sistema'] ?? ''), 0, 70) ?: '-') ?></div>
                                            <?php endif; ?>

                                            <?php if (!empty($item['recebimento_id'])): ?>
                                                <div class="mt-1"><span class="badge text-bg-success">Recebivel #<?= (int)$item['recebimento_id'] ?></span></div>
                                            <?php elseif (($item['tipo'] ?? '') !== 'C'): ?>
                                                <div class="mt-1"><span class="badge text-bg-secondary">Recebivel nao aplicavel</span></div>
                                            <?php else: ?>
                                                <div class="mt-1"><span class="badge text-bg-light border text-dark">Recebivel pendente</span></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($extratosPendentes)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-3">Nenhum extrato bancario encontrado para os filtros atuais.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="bg-white border rounded-2 shadow-sm overflow-hidden">
                <div class="p-3 border-bottom bg-light">
                    <h2 class="h6 fw-bold mb-0">Sistema BNC001 nao conciliado</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-primary">
                            <tr>
                                <th>MOV</th>
                                <th>Conta</th>
                                <th>Data</th>
                                <th>Historico</th>
                                <th>D/C</th>
                                <th class="text-end">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sistemaPendentes as $item): ?>
                                <tr>
                                    <td><?= (int)$item['MOVCONTADOR'] ?></td>
                                    <td><?= (int)$item['CBCONTADOR'] ?> - <?= htmlspecialchars($item['nome_conta'] ?: 'Conta') ?></td>
                                    <td><?= dataHoraExtratoBanco($item['DTMOV']) ?></td>
                                    <td><?= htmlspecialchars($item['HISTMOV'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($item['TIPOMOV']) ?></td>
                                    <td class="text-end"><?= moedaExtratoBanco($item['VALORMOV']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($sistemaPendentes)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">Nenhum lancamento do sistema pendente.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
