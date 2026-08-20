<?php

function mbaGarantirEstrutura(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS movimentacao_baixa_acertos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            clicontador INT NOT NULL,
            fcontador INT NOT NULL,
            cbcontador INT NOT NULL,
            data_acerto DATE NOT NULL,
            total_receber DECIMAL(15,4) NOT NULL DEFAULT 0,
            total_pagar DECIMAL(15,4) NOT NULL DEFAULT 0,
            saldo DECIMAL(15,4) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'FECHADO',
            usuario_id INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mba_acertos_empresa_data (empresa_id, data_acerto, status),
            INDEX idx_mba_acertos_pessoas (empresa_id, clicontador, fcontador)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS movimentacao_baixa_acerto_itens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            acerto_id INT NOT NULL,
            empresa_id INT NOT NULL,
            tipo_titulo CHAR(2) NOT NULL,
            titulo_contador INT NOT NULL,
            movcontador INT NOT NULL,
            valor DECIMAL(15,4) NOT NULL DEFAULT 0,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mba_acerto_titulo (empresa_id, tipo_titulo, titulo_contador),
            UNIQUE KEY uniq_mba_acerto_mov (empresa_id, movcontador),
            INDEX idx_mba_acerto_itens_acerto (acerto_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function mbaH($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function mbaMoeda($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function mbaData($valor): string
{
    return $valor ? date('d/m/Y', strtotime((string)$valor)) : '-';
}

function mbaIdsSelecionados($valor): array
{
    if (!is_array($valor)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map('intval', $valor), static fn(int $id): bool => $id > 0)));
}

function mbaAcertoFechadoPorMovimento(PDO $pdo, int $empresaId, int $movcontador): ?int
{
    $stmt = $pdo->prepare("
        SELECT a.id
        FROM movimentacao_baixa_acerto_itens i
        INNER JOIN movimentacao_baixa_acertos a
            ON a.id = i.acerto_id
           AND a.empresa_id = i.empresa_id
           AND a.status = 'FECHADO'
        WHERE i.empresa_id = ?
          AND i.movcontador = ?
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $movcontador]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function mbaAcertoFechadoPorTitulo(PDO $pdo, int $empresaId, string $tipo, int $tituloContador): ?int
{
    $stmt = $pdo->prepare("
        SELECT a.id
        FROM movimentacao_baixa_acerto_itens i
        INNER JOIN movimentacao_baixa_acertos a
            ON a.id = i.acerto_id
           AND a.empresa_id = i.empresa_id
           AND a.status = 'FECHADO'
        WHERE i.empresa_id = ?
          AND i.tipo_titulo = ?
          AND i.titulo_contador = ?
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $tipo, $tituloContador]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function mbaBuscarConta(PDO $pdo, int $empresaId, int $cbcontador): ?array
{
    $stmt = $pdo->prepare("
        SELECT CBCONTADOR, COALESCE(NULLIF(TITULAR, ''), NULLIF(DESCABREV, ''), CONCAT('Conta ', CBCONTADOR)) AS nome
        FROM armazem_bnc002
        WHERE EMPRESA = ?
          AND CBCONTADOR = ?
          AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $cbcontador]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function mbaBuscarTipoes(PDO $pdo, int $empresaId, int $tipoes): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_bnc005
        WHERE EMPRESA = ?
          AND ESCONTADOR = ?
          AND COALESCE(REGDISAB, 'N') <> 'S'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $tipoes]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function mbaProximoMovcontador(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COALESCE(MAX(MOVCONTADOR), 0) + 1 FROM armazem_bnc001")->fetchColumn();
}

function mbaCarregarTitulos(PDO $pdo, int $empresaId, string $tipo, array $ids, int $pessoaId, bool $bloquear = false): array
{
    if (!$ids || !in_array($tipo, ['CR', 'CP'], true)) {
        return [];
    }

    $tabela = $tipo === 'CR' ? 'armazem_cr001' : 'armazem_cp001';
    $contador = $tipo === 'CR' ? 'CRCONTADOR' : 'CPCONTADOR';
    $pessoa = $tipo === 'CR' ? 'CLICONTADOR' : 'FCONTADOR';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "
        SELECT *
        FROM {$tabela}
        WHERE EMPRESA = ?
          AND {$pessoa} = ?
          AND {$contador} IN ({$placeholders})
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(STATUS, 'AB') <> 'QT'
          AND COALESCE(NULLIF(VLRRESTANTE, 0), VLRPARCELA, 0) > 0
        ORDER BY DTVENC, {$contador}
    ";
    if ($bloquear) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$empresaId, $pessoaId], $ids));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function mbaInserirMovimentoBaixa(PDO $pdo, int $empresaId, int $usuarioId, int $cbcontador, string $dataAcerto, string $tipoTitulo, array $titulo, string $nomePessoa): int
{
    $ehCr = $tipoTitulo === 'CR';
    $contador = (int)$titulo[$ehCr ? 'CRCONTADOR' : 'CPCONTADOR'];
    $tipoes = (int)($titulo['TIPOES'] ?? 0);
    if ($tipoes <= 0) {
        throw new RuntimeException("O titulo {$tipoTitulo} #{$contador} nao possui TIPOES.");
    }

    $configTipo = mbaBuscarTipoes($pdo, $empresaId, $tipoes);
    if (!$configTipo) {
        throw new RuntimeException("O TIPOES {$tipoes} do titulo {$tipoTitulo} #{$contador} nao foi encontrado.");
    }

    $tipomov = strtoupper((string)($configTipo['TIPOMOV'] ?? ''));
    $tipomovEsperado = $ehCr ? 'C' : 'D';
    if ($tipomov !== $tipomovEsperado) {
        throw new RuntimeException("O TIPOES {$tipoes} do titulo {$tipoTitulo} #{$contador} deve ser {$tipomovEsperado} para este acerto.");
    }

    $valor = (float)($titulo['VLRRESTANTE'] ?? 0);
    if ($valor <= 0) {
        $valor = (float)($titulo['VLRPARCELA'] ?? 0);
    }
    if ($valor <= 0) {
        throw new RuntimeException("O titulo {$tipoTitulo} #{$contador} nao possui saldo para quitar.");
    }

    $documento = trim((string)($titulo['TITULO'] ?: ($titulo['NOTAFISCAL'] ?: $contador)));
    $historico = "ACERTO - BAIXA {$tipoTitulo} {$contador} - {$nomePessoa} - {$documento}";
    $movcontador = mbaProximoMovcontador($pdo);
    $campoPessoa = $ehCr ? 'CLICONTADOR' : 'FCONTADOR';
    $valorPessoa = (int)$titulo[$campoPessoa];

    $stmt = $pdo->prepare("
        INSERT INTO armazem_bnc001 (
            EMPRESA, MOVCONTADOR, DTMOV, NUMDOC, TIPOMOV, CBCONTADOR, TIPOES,
            {$campoPessoa}, HISTMOV, VALORMOV, TIPODOCORIGEM, NUMDOCORIGEM,
            REGSTAMP, USERBNCLANC, CONTRAPARTIDA, ORIGEMCPART, DTLANC, DTPROCESSADO, deletado
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'N', 0, NOW(), NOW(), 'N')
    ");
    $stmt->execute([
        $empresaId, $movcontador, $dataAcerto, $documento, $tipomov, $cbcontador,
        $tipoes, $valorPessoa, $historico, $valor, $tipoTitulo . '001', $contador,
        $usuarioId ?: null,
    ]);

    $tabela = $ehCr ? 'armazem_cr001' : 'armazem_cp001';
    $campoContador = $ehCr ? 'CRCONTADOR' : 'CPCONTADOR';
    $stmtTitulo = $pdo->prepare("
        UPDATE {$tabela}
        SET STATUS = 'QT', DTPAGTO = ?, VLRPAGO = ?, VLRRESTANTE = 0,
            CBCONTADOR = ?, TIPOES = ?, USERALT = ?, DTALT = NOW(), REGSTAMP = NOW()
        WHERE EMPRESA = ? AND {$campoContador} = ?
    ");
    $stmtTitulo->execute([$dataAcerto, $valor, $cbcontador, $tipoes, $usuarioId ?: null, $empresaId, $contador]);

    return $movcontador;
}

function mbaFecharAcerto(PDO $pdo, int $empresaId, int $usuarioId, array $dados): int
{
    $clicontador = (int)($dados['clicontador'] ?? 0);
    $fcontador = (int)($dados['fcontador'] ?? 0);
    $cbcontador = (int)($dados['cbcontador'] ?? 0);
    $dataAcerto = trim((string)($dados['data_acerto'] ?? ''));
    $crIds = mbaIdsSelecionados($dados['crcontadores'] ?? []);
    $cpIds = mbaIdsSelecionados($dados['cpcontadores'] ?? []);

    if ($clicontador <= 0 || $fcontador <= 0) {
        throw new RuntimeException('Selecione o cliente e o fornecedor.');
    }
    if (!$crIds && !$cpIds) {
        throw new RuntimeException('Selecione ao menos um titulo para o acerto.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAcerto)) {
        throw new RuntimeException('Informe uma data valida para o acerto.');
    }
    if (!mbaBuscarConta($pdo, $empresaId, $cbcontador)) {
        throw new RuntimeException('Selecione uma conta valida para quitar os titulos.');
    }

    $pdo->beginTransaction();
    try {
        $crTitulos = mbaCarregarTitulos($pdo, $empresaId, 'CR', $crIds, $clicontador, true);
        $cpTitulos = mbaCarregarTitulos($pdo, $empresaId, 'CP', $cpIds, $fcontador, true);
        if (count($crTitulos) !== count($crIds) || count($cpTitulos) !== count($cpIds)) {
            throw new RuntimeException('Um dos titulos selecionados nao esta mais aberto. Atualize a tela e tente novamente.');
        }

        $stmtCliente = $pdo->prepare("SELECT COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Cliente ', CLICONTADOR)) FROM armazem_cr002 WHERE EMPRESA = ? AND CLICONTADOR = ?");
        $stmtCliente->execute([$empresaId, $clicontador]);
        $nomeCliente = (string)($stmtCliente->fetchColumn() ?: "Cliente {$clicontador}");
        $stmtFornecedor = $pdo->prepare("SELECT COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Fornecedor ', FCONTADOR)) FROM armazem_cp003 WHERE EMPRESA = ? AND FCONTADOR = ?");
        $stmtFornecedor->execute([$empresaId, $fcontador]);
        $nomeFornecedor = (string)($stmtFornecedor->fetchColumn() ?: "Fornecedor {$fcontador}");

        $totalReceber = array_sum(array_map(static fn(array $t): float => (float)($t['VLRRESTANTE'] ?: $t['VLRPARCELA']), $crTitulos));
        $totalPagar = array_sum(array_map(static fn(array $t): float => (float)($t['VLRRESTANTE'] ?: $t['VLRPARCELA']), $cpTitulos));
        $saldo = round($totalReceber - $totalPagar, 2);

        $stmtAcerto = $pdo->prepare("
            INSERT INTO movimentacao_baixa_acertos
                (empresa_id, clicontador, fcontador, cbcontador, data_acerto,
                 total_receber, total_pagar, saldo, status, usuario_id, criado_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'FECHADO', ?, NOW())
        ");
        $stmtAcerto->execute([$empresaId, $clicontador, $fcontador, $cbcontador, $dataAcerto, $totalReceber, $totalPagar, $saldo, $usuarioId ?: null]);
        $acertoId = (int)$pdo->lastInsertId();

        $stmtItem = $pdo->prepare("
            INSERT INTO movimentacao_baixa_acerto_itens
                (acerto_id, empresa_id, tipo_titulo, titulo_contador, movcontador, valor, criado_em)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        foreach ($cpTitulos as $titulo) {
            $mov = mbaInserirMovimentoBaixa($pdo, $empresaId, $usuarioId, $cbcontador, $dataAcerto, 'CP', $titulo, $nomeFornecedor);
            $valor = (float)($titulo['VLRRESTANTE'] ?: $titulo['VLRPARCELA']);
            $stmtItem->execute([$acertoId, $empresaId, 'CP', (int)$titulo['CPCONTADOR'], $mov, $valor]);
        }
        foreach ($crTitulos as $titulo) {
            $mov = mbaInserirMovimentoBaixa($pdo, $empresaId, $usuarioId, $cbcontador, $dataAcerto, 'CR', $titulo, $nomeCliente);
            $valor = (float)($titulo['VLRRESTANTE'] ?: $titulo['VLRPARCELA']);
            $stmtItem->execute([$acertoId, $empresaId, 'CR', (int)$titulo['CRCONTADOR'], $mov, $valor]);
        }

        $pdo->commit();
        return $acertoId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
