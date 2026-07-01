<?php

function garantirTabelasCartaoCredito(PDO $pdo): void
{
    $stmtTipoEsCp003 = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'armazem_cp003'
          AND COLUMN_NAME = 'TIPOES'
    ");
    if ((int)$stmtTipoEsCp003->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE armazem_cp003 ADD TIPOES INT NULL AFTER TIPOFORN");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_cartao_faturas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            competencia CHAR(7) NOT NULL,
            data_vencimento DATE NOT NULL,
            cartao_nome VARCHAR(120) NOT NULL,
            nome_arquivo VARCHAR(255) NULL,
            total_compras DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_pagamentos DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_liquido DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_linhas INT NOT NULL DEFAULT 0,
            usuario_id INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_fin_cartao_faturas_empresa (empresa_id, competencia, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_cartao_lancamentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fatura_id INT NOT NULL,
            empresa_id INT NOT NULL,
            data_compra DATE NOT NULL,
            descricao VARCHAR(255) NOT NULL,
            categoria VARCHAR(120) NULL,
            tipo_lancamento VARCHAR(120) NULL,
            valor DECIMAL(15,2) NOT NULL,
            natureza CHAR(1) NOT NULL DEFAULT 'D',
            fornecedor_fcontador INT NULL,
            cpcontador INT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',
            hash_linha CHAR(64) NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_fin_cartao_linha (fatura_id, hash_linha),
            INDEX idx_fin_cartao_fatura (fatura_id, status),
            INDEX idx_fin_cartao_empresa_desc (empresa_id, descricao(80)),
            INDEX idx_fin_cartao_cp (empresa_id, cpcontador)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_cartao_fornecedor_map (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            descricao_norm VARCHAR(190) NOT NULL,
            descricao_original VARCHAR(255) NOT NULL,
            fcontador INT NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_fin_cartao_map (empresa_id, descricao_norm),
            INDEX idx_fin_cartao_map_fornecedor (empresa_id, fcontador)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

}

function moedaCartaoCredito($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function dataCartaoCredito($valor): string
{
    if (!$valor) {
        return '';
    }
    return date('d/m/Y', strtotime((string)$valor));
}

function normalizarDescricaoCartao(string $texto): string
{
    $texto = strtoupper(trim($texto));
    $acentos = [
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A',
        'É' => 'E', 'Ê' => 'E',
        'Í' => 'I',
        'Ó' => 'O', 'Õ' => 'O', 'Ô' => 'O',
        'Ú' => 'U',
        'Ç' => 'C',
        'á' => 'A', 'à' => 'A', 'ã' => 'A', 'â' => 'A',
        'é' => 'E', 'ê' => 'E',
        'í' => 'I',
        'ó' => 'O', 'õ' => 'O', 'ô' => 'O',
        'ú' => 'U',
        'ç' => 'C',
    ];
    $texto = strtr($texto, $acentos);
    $texto = preg_replace('/\s+/', ' ', $texto) ?? $texto;
    return substr($texto, 0, 190);
}

function sugerirNomeFornecedorCartao(string $descricao): string
{
    $descricao = trim($descricao);
    $descricao = preg_replace('/\s+/', ' ', $descricao) ?? $descricao;
    return substr($descricao, 0, 180);
}

function decimalCartaoCredito(string $valor): float
{
    $valor = trim($valor);
    $valor = str_replace("\xc2\xa0", ' ', $valor);
    $valor = preg_replace('/[^0-9,\.\-]/', '', $valor) ?? '';
    $valor = str_replace('.', '', $valor);
    $valor = str_replace(',', '.', $valor);
    return round((float)$valor, 2);
}

function dataCsvCartaoCredito(string $valor): ?string
{
    $valor = trim($valor);
    if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $valor, $m)) {
        return null;
    }
    return $m[3] . '-' . $m[2] . '-' . $m[1];
}

function lerCsvFaturaCartao(string $arquivo): array
{
    $handle = fopen($arquivo, 'r');
    if (!$handle) {
        throw new RuntimeException('Nao foi possivel abrir o arquivo CSV.');
    }

    $cabecalho = fgetcsv($handle, 0, ',');
    if (!$cabecalho) {
        fclose($handle);
        throw new RuntimeException('Arquivo CSV vazio.');
    }

    if (isset($cabecalho[0])) {
        $cabecalho[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cabecalho[0]) ?? (string)$cabecalho[0];
    }

    $indices = [];
    foreach ($cabecalho as $i => $coluna) {
        $coluna = trim((string)$coluna, " \t\n\r\0\x0B\"");
        $chave = normalizarDescricaoCartao($coluna);
        $indices[$chave] = $i;
    }

    foreach (['DATA', 'LANCAMENTO', 'CATEGORIA', 'TIPO', 'VALOR'] as $obrigatoria) {
        if (!isset($indices[$obrigatoria])) {
            fclose($handle);
            throw new RuntimeException('Coluna obrigatoria ausente no CSV: ' . $obrigatoria);
        }
    }

    $linhas = [];
    $linhaNumero = 1;
    while (($row = fgetcsv($handle, 0, ',')) !== false) {
        $linhaNumero++;
        if (count(array_filter($row, 'strlen')) === 0) {
            continue;
        }

        $data = dataCsvCartaoCredito((string)($row[$indices['DATA']] ?? ''));
        $descricao = trim((string)($row[$indices['LANCAMENTO']] ?? ''));
        $valor = decimalCartaoCredito((string)($row[$indices['VALOR']] ?? ''));

        if (!$data || $descricao === '') {
            continue;
        }

        $categoria = trim((string)($row[$indices['CATEGORIA']] ?? ''));
        $tipo = trim((string)($row[$indices['TIPO']] ?? ''));
        $natureza = $valor < 0 ? 'C' : 'D';
        $valorAbs = abs($valor);
        $hash = hash('sha256', implode('|', [$data, $descricao, $categoria, $tipo, number_format($valor, 2, '.', ''), $linhaNumero]));

        $linhas[] = [
            'data_compra' => $data,
            'descricao' => $descricao,
            'categoria' => $categoria,
            'tipo_lancamento' => $tipo,
            'valor' => $valorAbs,
            'valor_original' => $valor,
            'natureza' => $natureza,
            'hash_linha' => $hash,
        ];
    }

    fclose($handle);
    return $linhas;
}

function buscarEmpresasCartaoCredito(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, COALESCE(nome_fantasia, razao_social, CONCAT('Empresa ', id)) AS nome
        FROM empresas
        WHERE status = 'ATIVA'
        ORDER BY id
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarFornecedoresCartaoCredito(PDO $pdo, int $empresaId): array
{
    $stmt = $pdo->prepare("
        SELECT FCONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Fornecedor ', FCONTADOR)) AS nome
        FROM armazem_cp003
        WHERE EMPRESA = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND (INATIVO IS NULL OR INATIVO NOT IN ('S', '1'))
        ORDER BY nome
        LIMIT 600
    ");
    $stmt->execute([$empresaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function aplicarMapeamentosCartaoCredito(PDO $pdo, int $faturaId): void
{
    $stmt = $pdo->prepare("
        UPDATE financeiro_cartao_lancamentos l
        INNER JOIN financeiro_cartao_fornecedor_map m
            ON m.empresa_id = l.empresa_id
           AND m.descricao_norm = ?
        SET l.fornecedor_fcontador = m.fcontador
        WHERE l.id = ?
          AND l.fornecedor_fcontador IS NULL
    ");

    $linhas = $pdo->prepare("
        SELECT id, empresa_id, descricao
        FROM financeiro_cartao_lancamentos
        WHERE fatura_id = ?
          AND natureza = 'D'
          AND fornecedor_fcontador IS NULL
    ");
    $linhas->execute([$faturaId]);

    foreach ($linhas->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $stmt->execute([normalizarDescricaoCartao($linha['descricao']), (int)$linha['id']]);
    }
}

function fornecedorCartaoExiste(PDO $pdo, int $empresaId, int $fcontador): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM armazem_cp003
        WHERE EMPRESA = ?
          AND FCONTADOR = ?
    ");
    $stmt->execute([$empresaId, $fcontador]);
    return (int)$stmt->fetchColumn() > 0;
}

function normalizarNomeFornecedorCartao(string $nome): string
{
    return normalizarDescricaoCartao(preg_replace('/\s+/', ' ', trim($nome)) ?? $nome);
}

function buscarFornecedorCartaoPorNome(PDO $pdo, int $empresaId, string $nome): ?int
{
    $nomeNorm = normalizarNomeFornecedorCartao($nome);
    if ($nomeNorm === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT FCONTADOR, NOME
        FROM armazem_cp003
        WHERE EMPRESA = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND (INATIVO IS NULL OR INATIVO NOT IN ('S', '1'))
        ORDER BY FCONTADOR
    ");
    $stmt->execute([$empresaId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fornecedor) {
        if (normalizarNomeFornecedorCartao((string)$fornecedor['NOME']) === $nomeNorm) {
            return (int)$fornecedor['FCONTADOR'];
        }
    }

    return null;
}

function criarFornecedorCartaoCredito(PDO $pdo, int $empresaId, string $nome, int $usuarioId): int
{
    $nome = sugerirNomeFornecedorCartao($nome);
    $existente = buscarFornecedorCartaoPorNome($pdo, $empresaId, $nome);
    if ($existente) {
        return $existente;
    }

    $stmtMax = $pdo->prepare("
        SELECT COALESCE(MAX(FCONTADOR), 0)
        FROM armazem_cp003
        WHERE EMPRESA = ?
    ");
    $stmtMax->execute([$empresaId]);
    $proximo = max(900000, (int)$stmtMax->fetchColumn() + 1);

    $stmt = $pdo->prepare("
        INSERT INTO armazem_cp003 (
            EMPRESA, FCONTADOR, NOME, APELIDO, TIPOFORN, TIPOES, REGSTAMP, REGIMPORT,
            REGDISAB, INATIVO, USERLANC, DTLANC, USERALT, DTALT
        ) VALUES (?, ?, ?, ?, 'CARTAO', NULL, NOW(), 'S', NULL, 'N', ?, NOW(), ?, NOW())
    ");
    $stmt->execute([$empresaId, $proximo, $nome, substr($nome, 0, 120), $usuarioId ?: null, $usuarioId ?: null]);

    return $proximo;
}

function salvarMapeamentoFornecedorCartao(PDO $pdo, int $empresaId, string $descricao, int $fcontador): void
{
    $norm = normalizarDescricaoCartao($descricao);
    $stmt = $pdo->prepare("
        INSERT INTO financeiro_cartao_fornecedor_map (empresa_id, descricao_norm, descricao_original, fcontador)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            descricao_original = VALUES(descricao_original),
            fcontador = VALUES(fcontador),
            atualizado_em = NOW()
    ");
    $stmt->execute([$empresaId, $norm, $descricao, $fcontador]);
}

function proximoCpcontadorCartao(PDO $pdo, int $empresaId): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(CPCONTADOR), 0)
        FROM armazem_cp001
        WHERE EMPRESA = ?
    ");
    $stmt->execute([$empresaId]);
    return max(900000, (int)$stmt->fetchColumn() + 1);
}

function gerarCp001CartaoCredito(PDO $pdo, int $lancamentoId, int $usuarioId): int
{
    $stmt = $pdo->prepare("
        SELECT l.*, f.competencia, f.data_vencimento, f.cartao_nome
        FROM financeiro_cartao_lancamentos l
        INNER JOIN financeiro_cartao_faturas f ON f.id = l.fatura_id
        WHERE l.id = ?
        LIMIT 1
    ");
    $stmt->execute([$lancamentoId]);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$linha) {
        throw new RuntimeException('Lancamento nao encontrado.');
    }
    if ($linha['natureza'] !== 'D') {
        throw new RuntimeException('Pagamentos/creditos da fatura nao geram CP001.');
    }
    if ((int)($linha['fornecedor_fcontador'] ?? 0) <= 0) {
        throw new RuntimeException('Informe o fornecedor antes de gerar o CP001.');
    }
    if (!empty($linha['cpcontador'])) {
        return (int)$linha['cpcontador'];
    }

    $empresaId = (int)$linha['empresa_id'];
    $chave = 'CARTAO:' . $empresaId . ':' . (int)$linha['fatura_id'] . ':' . (int)$linha['id'];

    $stmtExiste = $pdo->prepare("
        SELECT CPCONTADOR
        FROM armazem_cp001
        WHERE EMPRESA = ?
          AND CHAVEINTEGRACAO = ?
        LIMIT 1
    ");
    $stmtExiste->execute([$empresaId, $chave]);
    $existente = $stmtExiste->fetchColumn();
    if ($existente) {
        $pdo->prepare("UPDATE financeiro_cartao_lancamentos SET cpcontador = ?, status = 'GERADO' WHERE id = ?")
            ->execute([(int)$existente, $lancamentoId]);
        return (int)$existente;
    }

    $cpcontador = proximoCpcontadorCartao($pdo, $empresaId);
    $titulo = 'CARTAO ' . $linha['cartao_nome'] . ' - ' . $linha['descricao'];
    $observacao = 'Importado da fatura de cartao ' . $linha['cartao_nome']
        . ' competencia ' . $linha['competencia']
        . '. Categoria: ' . ($linha['categoria'] ?: '-')
        . '. Tipo: ' . ($linha['tipo_lancamento'] ?: '-');
    $stmtFornecedorTipoes = $pdo->prepare("
        SELECT TIPOES
        FROM armazem_cp003
        WHERE EMPRESA = ?
          AND FCONTADOR = ?
        LIMIT 1
    ");
    $stmtFornecedorTipoes->execute([$empresaId, (int)$linha['fornecedor_fcontador']]);
    $tipoesFornecedor = (int)$stmtFornecedorTipoes->fetchColumn();
    $tipoesCartao = $tipoesFornecedor > 0 ? $tipoesFornecedor : 301;

    $stmtInsert = $pdo->prepare("
        INSERT INTO armazem_cp001 (
            EMPRESA, CPCONTADOR, DTCOMPRA, NUMPARCELA, TITULO, VALORCOMPRA,
            FCONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
            VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
            TIPOCP, TIPOES, REGSTAMP, REGIMPORT, USERLANC, DTLANC, USERALT, DTALT,
            CHAVEINTEGRACAO, financeiro_verificado
        ) VALUES (
            ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'AB', 'CARTAO', ?, 'SUPERDUNGA_CARTAO',
            'CARTAO', ?, NOW(), 'S', ?, NOW(), ?, NOW(), ?, 'N'
        )
    ");
    $stmtInsert->execute([
        $empresaId,
        $cpcontador,
        $linha['data_compra'],
        $titulo,
        $linha['valor'],
        (int)$linha['fornecedor_fcontador'],
        $observacao,
        $linha['data_compra'],
        $linha['valor'],
        $linha['tipo_lancamento'],
        $linha['data_vencimento'],
        $linha['valor'],
        'CARTAO-' . (int)$linha['fatura_id'] . '-' . (int)$linha['id'],
        $tipoesCartao,
        $usuarioId ?: null,
        $usuarioId ?: null,
        $chave,
    ]);

    $pdo->prepare("UPDATE financeiro_cartao_lancamentos SET cpcontador = ?, status = 'GERADO' WHERE id = ?")
        ->execute([$cpcontador, $lancamentoId]);

    return $cpcontador;
}
