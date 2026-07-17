<?php

function garantirTabelasValeCompras(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vale_compras_vales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            identificacao VARCHAR(120) NOT NULL,
            saldo_inicial DECIMAL(15,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_vcv_empresa_status (empresa_id, status),
            INDEX idx_vcv_identificacao (empresa_id, identificacao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try {
        $pdo->exec("ALTER TABLE vale_compras_vales ADD COLUMN saldo_inicial DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER identificacao");
    } catch (Throwable $e) {
        // Coluna ja existente em bases atualizadas.
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vale_compras_movimentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vale_id INT NOT NULL,
            empresa_id INT NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            data_movimento DATE NOT NULL,
            fornecedor_id INT NULL,
            cliente_id INT NULL,
            estabelecimento VARCHAR(180) NULL,
            valor_nominal DECIMAL(15,2) NOT NULL DEFAULT 0,
            taxa_desconto DECIMAL(8,4) NOT NULL DEFAULT 0,
            valor_liquido DECIMAL(15,2) NOT NULL DEFAULT 0,
            valor_pago DECIMAL(15,2) NOT NULL DEFAULT 0,
            valor_desagio DECIMAL(15,2) NOT NULL DEFAULT 0,
            valor DECIMAL(15,2) NOT NULL DEFAULT 0,
            vencimento DATE NULL,
            mov_nominal INT NULL,
            mov_desagio INT NULL,
            crcontador INT NULL,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_vcm_vale (vale_id),
            INDEX idx_vcm_tipo (empresa_id, tipo),
            INDEX idx_vcm_fornecedor (empresa_id, fornecedor_id),
            INDEX idx_vcm_cliente (empresa_id, cliente_id),
            INDEX idx_vcm_data (empresa_id, data_movimento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vale_compras_estabelecimentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            nome VARCHAR(180) NOT NULL,
            nome_normalizado VARCHAR(180) NOT NULL,
            ultimo_uso DATETIME NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_vce_empresa_nome (empresa_id, nome_normalizado),
            INDEX idx_vce_empresa_nome (empresa_id, nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        INSERT IGNORE INTO vale_compras_estabelecimentos (empresa_id, nome, nome_normalizado, ultimo_uso)
        SELECT empresa_id, TRIM(estabelecimento), UPPER(TRIM(estabelecimento)), MAX(criado_em)
        FROM vale_compras_movimentos
        WHERE tipo = 'VENDA'
          AND TRIM(COALESCE(estabelecimento, '')) <> ''
        GROUP BY empresa_id, UPPER(TRIM(estabelecimento)), TRIM(estabelecimento)
    ");
}

function vcH($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function vcFloat($valor): float
{
    if ($valor === null || $valor === '') {
        return 0.0;
    }
    $valor = str_replace(['R$', ' '], '', trim((string)$valor));
    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }
    return (float)$valor;
}

function vcMoeda($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function vcData($data): string
{
    if (!$data) {
        return '-';
    }
    return date('d/m/Y', strtotime((string)$data));
}

function vcNormalizarEstabelecimento(string $nome): string
{
    $nome = preg_replace('/\s+/', ' ', trim($nome));
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($nome, 'UTF-8');
    }
    return strtoupper($nome);
}

function vcRegistrarEstabelecimento(PDO $pdo, int $empresaId, string $nome): string
{
    $nome = preg_replace('/\s+/', ' ', trim($nome));
    if ($nome === '') {
        return '';
    }

    $normalizado = vcNormalizarEstabelecimento($nome);
    $stmt = $pdo->prepare("
        SELECT nome
        FROM vale_compras_estabelecimentos
        WHERE empresa_id = ?
          AND nome_normalizado = ?
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $normalizado]);
    $existente = $stmt->fetchColumn();

    if ($existente) {
        $pdo->prepare("
            UPDATE vale_compras_estabelecimentos
            SET ultimo_uso = NOW()
            WHERE empresa_id = ? AND nome_normalizado = ?
        ")->execute([$empresaId, $normalizado]);
        return (string)$existente;
    }

    $stmt = $pdo->prepare("
        INSERT INTO vale_compras_estabelecimentos (empresa_id, nome, nome_normalizado, ultimo_uso)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$empresaId, $nome, $normalizado]);
    return $nome;
}

function vcProximoMovcontador(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COALESCE(MAX(MOVCONTADOR), 0) + 1 FROM armazem_bnc001");
    return (int)$stmt->fetchColumn();
}

function vcProximoCrcontador(PDO $pdo, int $empresaId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CRCONTADOR), 0) + 1 FROM armazem_cr001 WHERE EMPRESA = ?");
    $stmt->execute([$empresaId]);
    return (int)$stmt->fetchColumn();
}

function vcCalcularVencimento(string $dataCompra): string
{
    $base = new DateTimeImmutable($dataCompra);
    $minimo = $base->modify('+40 days');
    $vencimento = new DateTimeImmutable($minimo->format('Y-m-15'));
    if ($vencimento < $minimo) {
        $proximoMes = $minimo->modify('first day of next month');
        $vencimento = $proximoMes->setDate((int)$proximoMes->format('Y'), (int)$proximoMes->format('m'), 15);
    }
    return $vencimento->format('Y-m-d');
}

function vcBuscarFornecedor(PDO $pdo, int $empresaId, int $fornecedorId): ?array
{
    $stmt = $pdo->prepare("
        SELECT FCONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Fornecedor ', FCONTADOR)) AS nome
        FROM armazem_cp003
        WHERE EMPRESA = ?
          AND FCONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $fornecedorId]);
    $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fornecedor ?: null;
}

function vcBuscarCliente(PDO $pdo, int $empresaId, int $clienteId): ?array
{
    $stmt = $pdo->prepare("
        SELECT CLICONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Cliente ', CLICONTADOR)) AS nome
        FROM armazem_cr002
        WHERE EMPRESA = ?
          AND CLICONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $clienteId]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    return $cliente ?: null;
}

function vcResumoVale(PDO $pdo, int $valeId): array
{
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(MAX(v.saldo_inicial), 0) AS saldo_inicial,
            COALESCE(SUM(CASE WHEN tipo = 'COMPRA' THEN valor_nominal ELSE 0 END), 0) AS total_compras,
            COALESCE(SUM(CASE WHEN tipo = 'VENDA' THEN valor ELSE 0 END), 0) AS total_vendas,
            COALESCE(SUM(CASE WHEN tipo = 'VENDA' AND crcontador IS NOT NULL THEN valor ELSE 0 END), 0) AS total_vendas_lancadas,
            COUNT(*) AS qtd_movimentos,
            SUM(CASE WHEN tipo = 'COMPRA' AND (mov_nominal IS NULL OR (valor_desagio > 0 AND mov_desagio IS NULL)) THEN 1 ELSE 0 END) AS compras_pendentes,
            SUM(CASE WHEN tipo = 'VENDA' AND crcontador IS NULL THEN 1 ELSE 0 END) AS vendas_pendentes
        FROM vale_compras_vales v
        LEFT JOIN vale_compras_movimentos m ON m.vale_id = v.id
        WHERE v.id = ?
    ");
    $stmt->execute([$valeId]);
    $resumo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$resumo) {
        $resumo = [];
    }
    $resumo['saldo_inicial'] = (float)($resumo['saldo_inicial'] ?? 0);
    $resumo['total_compras'] = (float)($resumo['total_compras'] ?? 0);
    $resumo['total_vendas'] = (float)($resumo['total_vendas'] ?? 0);
    $resumo['total_vendas_lancadas'] = (float)($resumo['total_vendas_lancadas'] ?? 0);
    $resumo['saldo'] = round($resumo['saldo_inicial'] + $resumo['total_compras'] - $resumo['total_vendas'], 2);
    return $resumo;
}

function vcGerarMovimentoCompra(PDO $pdo, int $empresaId, int $usuarioId, int $movimentoId, int $valeId, int $fornecedorId, string $data, string $tipomov, int $tipoes, float $valor, string $historico): ?int
{
    if ($valor <= 0) {
        return null;
    }

    $movcontador = vcProximoMovcontador($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO armazem_bnc001 (
            EMPRESA, MOVCONTADOR, DTMOV, NUMDOC, TIPOMOV, CBCONTADOR, TIPOES,
            FCONTADOR, HISTMOV, VALORMOV, TIPODOCORIGEM, NUMDOCORIGEM, REGSTAMP,
            USERBNCLANC, CONTRAPARTIDA, ORIGEMCPART, DTLANC, DTPROCESSADO, deletado
        ) VALUES (
            ?, ?, ?, ?, ?, 38, ?, ?, ?, ?, 'VALE_COMPRAS', ?, NOW(),
            ?, 'N', 0, NOW(), NOW(), 'N'
        )
    ");
    $stmt->execute([
        $empresaId,
        $movcontador,
        $data,
        'VALE-' . $valeId . '-' . $movimentoId,
        $tipomov,
        $tipoes,
        $fornecedorId,
        $historico,
        $valor,
        $movimentoId,
        $usuarioId ?: null,
    ]);

    return $movcontador;
}

function vcGerarTituloReceber(PDO $pdo, int $empresaId, int $usuarioId, array $vale, array $movimento, string $fornecedorNome, string $clienteNome): int
{
    $crcontador = vcProximoCrcontador($pdo, $empresaId);
    $historico = 'Compra ' . trim((string)$movimento['estabelecimento']) . ' com vale compras do ' . $fornecedorNome;
    $chave = 'VALE-COMPRAS-MOV-' . $empresaId . '-' . (int)$movimento['id'];

    $stmt = $pdo->prepare("
        INSERT INTO armazem_cr001 (
            EMPRESA, CRCONTADOR, DTVENDA, NUMPARCELA, TITULO, VALORVENDA,
            CLICONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
            VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
            TIPOCR, TIPOES, NOTAFISCAL, REGSTAMP, USERLANC, DTLANC,
            USERALT, DTALT, CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
        ) VALUES (
            ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, '1/1', ?, ?, 0, 'AB', 'VALE_COMPRAS', ?, 'VALE_COMPRAS',
            'CR', 50, NULL, NOW(), ?, NOW(), ?, NOW(), ?, 'N', 'N'
        )
    ");
    $stmt->execute([
        $empresaId,
        $crcontador,
        $movimento['data_movimento'],
        'VALE-COMPRAS #' . (int)$vale['id'] . ' MOV #' . (int)$movimento['id'],
        (float)$movimento['valor'],
        (int)$movimento['cliente_id'],
        $historico . ' | Cliente: ' . $clienteNome . ' | Vale: ' . ($vale['identificacao'] ?? ('#' . (int)$vale['id'])),
        $movimento['data_movimento'],
        (float)$movimento['valor'],
        $movimento['vencimento'],
        (float)$movimento['valor'],
        (int)$movimento['id'],
        $usuarioId ?: null,
        $usuarioId ?: null,
        $chave,
    ]);

    $pdo->prepare("UPDATE vale_compras_movimentos SET crcontador = ? WHERE id = ? AND vale_id = ?")
        ->execute([$crcontador, (int)$movimento['id'], (int)$vale['id']]);

    return $crcontador;
}
