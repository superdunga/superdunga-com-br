<?php

require_once __DIR__ . '/_access_legado_lib.php';

function acH($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function acFloat($valor): ?float
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return null;
    }
    $valor = str_replace(['R$', ' '], '', $valor);
    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }
    return is_numeric($valor) ? (float)$valor : null;
}

function acMoeda($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function acData($valor): string
{
    return $valor ? date('d/m/Y', strtotime((string)$valor)) : '-';
}

function acQueryAtual(array $remover = []): string
{
    $query = $_GET;
    foreach ($remover as $campo) {
        unset($query[$campo]);
    }
    return http_build_query($query);
}

function acCampoDataAccess(string $campo): string
{
    switch ($campo) {
        case 'bom_para':
            return 'l.data_bom_para_origem';
        case 'emissao':
            return 'l.data_emissao_origem';
        case 'sem_data':
            return '';
        default:
            return 'l.data_pagamento_origem';
    }
}

function acCampoDataAccessLabel(string $campo): string
{
    switch ($campo) {
        case 'bom_para':
            return 'DataBomPara';
        case 'emissao':
            return 'DataEmissao';
        case 'sem_data':
            return 'Sem data';
        default:
            return 'DataPagamento';
    }
}

function acTipoAccessSql(): string
{
    return "CASE WHEN l.debito_origem > 0 AND l.credito_origem <= 0 THEN 'D' WHEN l.credito_origem > 0 AND l.debito_origem <= 0 THEN 'C' ELSE '' END";
}

function acValorKey($valor): string
{
    return number_format((float)$valor, 2, '.', '');
}

function acDataKey($valor): string
{
    return $valor ? date('Y-m-d', strtotime((string)$valor)) : '';
}

function acDiasDiferenca(string $dataA, string $dataB): int
{
    $tsA = strtotime($dataA);
    $tsB = strtotime($dataB);
    if (!$tsA || !$tsB) {
        return 999999;
    }
    return (int)floor(abs($tsA - $tsB) / 86400);
}

function acGarantir(PDO $pdo): void
{
    garantirTabelaMovBaixaLancamentosAccess($pdo);
    garantirTabelaMovBaixaContasAccess($pdo);
}

function acTipoAccess(array $linha): string
{
    if ((float)($linha['debito_origem'] ?? 0) > 0 && (float)($linha['credito_origem'] ?? 0) <= 0) {
        return 'D';
    }
    if ((float)($linha['credito_origem'] ?? 0) > 0 && (float)($linha['debito_origem'] ?? 0) <= 0) {
        return 'C';
    }
    return '';
}

function acDataLancamentoAccess(array $linha, string $campo, string $dataFixa = ''): ?string
{
    if ($dataFixa !== '') {
        return date('Y-m-d', strtotime($dataFixa));
    }

    $mapa = [
        'pagamento' => 'data_pagamento_origem',
        'bom_para' => 'data_bom_para_origem',
        'emissao' => 'data_emissao_origem',
    ];
    $campoOrigem = $mapa[$campo] ?? '';
    if ($campoOrigem === '' || empty($linha[$campoOrigem])) {
        return null;
    }
    return date('Y-m-d', strtotime((string)$linha[$campoOrigem]));
}

function acDocumentoAccess(array $linha): string
{
    foreach (['documento_origem', 'cheque_n_origem', 'cheque_origem', 'nota_fiscal_origem', 'codigo_origem'] as $campo) {
        $valor = trim((string)($linha[$campo] ?? ''));
        if ($valor !== '') {
            return mb_substr($valor, 0, 80);
        }
    }
    return '';
}

function acHistoricoAccess(string $historicoBase, array $linha): string
{
    $partes = [trim($historicoBase)];
    $obs = trim((string)($linha['observacao_origem'] ?? ''));
    if ($obs !== '') {
        $partes[] = $obs;
    }
    $partes[] = 'Access linha ' . (int)($linha['linha_origem'] ?? 0);
    return mb_substr(implode(' - ', array_filter($partes)), 0, 255);
}

function acProximoMovcontador(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COALESCE(MAX(MOVCONTADOR), 0) + 1 FROM armazem_bnc001");
    return (int)$stmt->fetchColumn();
}

function acProximoCrcontador(PDO $pdo, int $empresaId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CRCONTADOR), 0) + 1 FROM armazem_cr001 WHERE EMPRESA = ?");
    $stmt->execute([$empresaId]);
    return (int)$stmt->fetchColumn();
}

function acProximoCpcontador(PDO $pdo, int $empresaId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CPCONTADOR), 0) + 1 FROM armazem_cp001 WHERE EMPRESA = ?");
    $stmt->execute([$empresaId]);
    return (int)$stmt->fetchColumn();
}

function acMarcarCriadoSuperDunga(PDO $pdo, int $empresaId, int $usuarioId, int $accessId, string $tabelaDestino, int $idDestino): void
{
    $mov = $tabelaDestino === 'armazem_bnc001' ? $idDestino : null;
    $cr = $tabelaDestino === 'armazem_cr001' ? $idDestino : null;
    $cp = $tabelaDestino === 'armazem_cp001' ? $idDestino : null;

    $stmt = $pdo->prepare("
        UPDATE mov_baixa_lancamentos_access
        SET status_controle = 'VINCULADO',
            resultado_comparacao = 'CRIADO_SUPERDUNGA',
            chave_comparacao = ?,
            enviado_superdunga = 'S',
            enviado_em = NOW(),
            enviado_por = ?,
            tabela_destino = ?,
            id_destino = ?,
            movcontador_destino = ?,
            crcontador_destino = ?,
            cpcontador_destino = ?,
            comparado_em = NOW(),
            comparado_por = ?,
            observacao_controle = ?
        WHERE empresa_id = ?
          AND id = ?
          AND COALESCE(enviado_superdunga, 'N') <> 'S'
          AND tabela_destino IS NULL
          AND id_destino IS NULL
    ");
    $stmt->execute([
        sha1($empresaId . '|' . $tabelaDestino . '|' . $idDestino),
        $usuarioId ?: null,
        $tabelaDestino,
        $idDestino,
        $mov,
        $cr,
        $cp,
        $usuarioId ?: null,
        'Criado no SuperDunga pela rotina de pendentes Access em ' . date('d/m/Y H:i:s'),
        $empresaId,
        $accessId,
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Lancamento Access #' . $accessId . ' ja foi vinculado por outra rotina.');
    }
}

function acCriarBncDeAccess(PDO $pdo, int $empresaId, int $usuarioId, array $linha, array $dados): int
{
    $dtmov = acDataLancamentoAccess($linha, (string)$dados['data_origem'], (string)$dados['data_fixa']);
    if (!$dtmov) {
        throw new RuntimeException('Linha Access ' . (int)$linha['linha_origem'] . ' sem data para lancamento.');
    }

    $movcontador = acProximoMovcontador($pdo);
    $documento = acDocumentoAccess($linha);
    $historico = acHistoricoAccess((string)$dados['historico'], $linha);
    $stmt = $pdo->prepare("
        INSERT INTO armazem_bnc001
            (EMPRESA, MOVCONTADOR, DTMOV, NUMDOC, CBCONTADOR, TIPOES, TIPOMOV, HISTMOV, VALORMOV,
             TIPODOCORIGEM, NUMDOCORIGEM, CONTRAPARTIDA, ORIGEMCPART, USERBNCLANC, DTLANC,
             DTPROCESSADO, REGSTAMP, deletado)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, 'SUPERDUNGA', ?, 'N', 0, ?, NOW(), NOW(), NOW(), 'N')
    ");
    $stmt->execute([
        $empresaId,
        $movcontador,
        $dtmov,
        $documento !== '' ? $documento : null,
        (int)$dados['cbcontador'],
        (int)$dados['tipoes'],
        (string)$dados['tipomov'],
        $historico,
        (float)$linha['valor_origem'],
        $documento !== '' ? $documento : $movcontador,
        $usuarioId ?: null,
    ]);

    acMarcarCriadoSuperDunga($pdo, $empresaId, $usuarioId, (int)$linha['id'], 'armazem_bnc001', $movcontador);
    return $movcontador;
}

function acCriarCrDeAccess(PDO $pdo, int $empresaId, int $usuarioId, array $linha, array $dados): int
{
    $data = acDataLancamentoAccess($linha, (string)$dados['data_origem'], (string)$dados['data_fixa']);
    if (!$data) {
        throw new RuntimeException('Linha Access ' . (int)$linha['linha_origem'] . ' sem data para lancamento.');
    }

    $crcontador = acProximoCrcontador($pdo, $empresaId);
    $documento = acDocumentoAccess($linha);
    $titulo = acHistoricoAccess((string)$dados['historico'], $linha);
    $valor = (float)$linha['valor_origem'];
    $chave = 'ACCESS-CR-' . $empresaId . '-' . $crcontador;

    $stmt = $pdo->prepare("
        INSERT INTO armazem_cr001 (
            EMPRESA, CRCONTADOR, DTVENDA, NUMPARCELA, TITULO, VALORVENDA,
            CLICONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
            VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
            TIPOCR, TIPOES, NOTAFISCAL, REGSTAMP, USERLANC, DTLANC,
            USERALT, DTALT, CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
        ) VALUES (
            ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, '1/1', ?, ?, 0, 'AB', 'SUPERDUNGA', ?, 'MOVIMENTACAO_BAIXA',
            'CR', ?, ?, NOW(), ?, NOW(), ?, NOW(), ?, 'N', 'N'
        )
    ");
    $stmt->execute([
        $empresaId,
        $crcontador,
        $data,
        $titulo,
        $valor,
        (int)$dados['clicontador'],
        $titulo,
        $data,
        $valor,
        $data,
        $valor,
        $crcontador,
        (int)$dados['tipoes'],
        $documento !== '' ? $documento : null,
        $usuarioId ?: null,
        $usuarioId ?: null,
        $chave,
    ]);

    acMarcarCriadoSuperDunga($pdo, $empresaId, $usuarioId, (int)$linha['id'], 'armazem_cr001', $crcontador);
    return $crcontador;
}

function acCriarCpDeAccess(PDO $pdo, int $empresaId, int $usuarioId, array $linha, array $dados): int
{
    $data = acDataLancamentoAccess($linha, (string)$dados['data_origem'], (string)$dados['data_fixa']);
    if (!$data) {
        throw new RuntimeException('Linha Access ' . (int)$linha['linha_origem'] . ' sem data para lancamento.');
    }

    $cpcontador = acProximoCpcontador($pdo, $empresaId);
    $documento = acDocumentoAccess($linha);
    $titulo = acHistoricoAccess((string)$dados['historico'], $linha);
    $valor = (float)$linha['valor_origem'];
    $chave = 'ACCESS-CP-' . $empresaId . '-' . $cpcontador;

    $stmt = $pdo->prepare("
        INSERT INTO armazem_cp001 (
            EMPRESA, CPCONTADOR, DTCOMPRA, NUMPARCELA, TITULO, VALORCOMPRA,
            FCONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
            VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
            TIPOCP, TIPOES, NOTAFISCAL, REGSTAMP, REGIMPORT, USERLANC, DTLANC,
            USERALT, DTALT, CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
        ) VALUES (
            ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, '1/1', ?, ?, 0, 'AB', 'SUPERDUNGA', ?, 'MOVIMENTACAO_BAIXA',
            'CP', ?, ?, NOW(), 'S', ?, NOW(), ?, NOW(), ?, 'N', 'N'
        )
    ");
    $stmt->execute([
        $empresaId,
        $cpcontador,
        $data,
        $titulo,
        $valor,
        (int)$dados['fcontador'],
        $titulo,
        $data,
        $valor,
        $data,
        $valor,
        $cpcontador,
        (int)$dados['tipoes'],
        $documento !== '' ? $documento : null,
        $usuarioId ?: null,
        $usuarioId ?: null,
        $chave,
    ]);

    acMarcarCriadoSuperDunga($pdo, $empresaId, $usuarioId, (int)$linha['id'], 'armazem_cp001', $cpcontador);
    return $cpcontador;
}

function acAmarrarAccess(PDO $pdo, int $empresaId, int $usuarioId, int $accessId, string $destinoTipo, int $destinoId): void
{
    if ($accessId <= 0 || $destinoId <= 0 || !in_array($destinoTipo, ['bnc', 'cr', 'cp'], true)) {
        throw new RuntimeException('Dados insuficientes para amarrar o lancamento.');
    }

    $mapaDestino = [
        'bnc' => ['tabela' => 'armazem_bnc001', 'campo' => 'MOVCONTADOR', 'destino' => 'movcontador_destino'],
        'cr' => ['tabela' => 'armazem_cr001', 'campo' => 'CRCONTADOR', 'destino' => 'crcontador_destino'],
        'cp' => ['tabela' => 'armazem_cp001', 'campo' => 'CPCONTADOR', 'destino' => 'cpcontador_destino'],
    ];
    $cfg = $mapaDestino[$destinoTipo];

    $stmtAccess = $pdo->prepare("SELECT id FROM mov_baixa_lancamentos_access WHERE empresa_id = ? AND id = ?");
    $stmtAccess->execute([$empresaId, $accessId]);
    if (!$stmtAccess->fetchColumn()) {
        throw new RuntimeException('Lancamento Access nao encontrado.');
    }

    $stmtDestinoExiste = $pdo->prepare("
        SELECT {$cfg['campo']}
        FROM {$cfg['tabela']}
        WHERE EMPRESA = ?
          AND {$cfg['campo']} = ?
        LIMIT 1
    ");
    $stmtDestinoExiste->execute([$empresaId, $destinoId]);
    if (!$stmtDestinoExiste->fetchColumn()) {
        throw new RuntimeException('Destino SuperDunga nao encontrado.');
    }

    $stmtDestino = $pdo->prepare("
        SELECT id
        FROM mov_baixa_lancamentos_access
        WHERE empresa_id = ?
          AND tabela_destino = ?
          AND id_destino = ?
          AND id <> ?
        LIMIT 1
    ");
    $stmtDestino->execute([$empresaId, $cfg['tabela'], $destinoId, $accessId]);
    if ($stmtDestino->fetchColumn()) {
        throw new RuntimeException('Este destino ja esta amarrado a outro lancamento Access.');
    }

    $mov = $destinoTipo === 'bnc' ? $destinoId : null;
    $cr = $destinoTipo === 'cr' ? $destinoId : null;
    $cp = $destinoTipo === 'cp' ? $destinoId : null;

    $stmt = $pdo->prepare("
        UPDATE mov_baixa_lancamentos_access
        SET status_controle = 'VINCULADO',
            resultado_comparacao = 'VINCULADO_MANUAL',
            chave_comparacao = ?,
            enviado_superdunga = 'S',
            enviado_em = NOW(),
            enviado_por = ?,
            tabela_destino = ?,
            id_destino = ?,
            movcontador_destino = ?,
            crcontador_destino = ?,
            cpcontador_destino = ?,
            comparado_em = NOW(),
            comparado_por = ?,
            observacao_controle = ?
        WHERE empresa_id = ?
          AND id = ?
    ");
    $stmt->execute([
        sha1($empresaId . '|' . $cfg['tabela'] . '|' . $destinoId),
        $usuarioId ?: null,
        $cfg['tabela'],
        $destinoId,
        $mov,
        $cr,
        $cp,
        $usuarioId ?: null,
        'Vinculado manualmente pela comparacao Access em ' . date('d/m/Y H:i:s'),
        $empresaId,
        $accessId,
    ]);
}

function acCandidatosCaixa(PDO $pdo, int $empresaId, array $linha, string $dataAccessCampo): array
{
    $dataAccessSql = acCampoDataAccess($dataAccessCampo);
    $usarData = $dataAccessSql !== '';
    $filtroData = $usarData ? "AND {$dataAccessSql} IS NOT NULL AND DATE({$dataAccessSql}) BETWEEN DATE_SUB(DATE(?), INTERVAL 3 DAY) AND DATE_ADD(DATE(?), INTERVAL 3 DAY)" : '';
    $stmt = $pdo->prepare("
        SELECT l.*, ca.descricao_conta, ca.banco_bnc002
        FROM mov_baixa_lancamentos_access l
        LEFT JOIN mov_baixa_contas_access ca
                ON ca.empresa_id = l.empresa_id
               AND ca.cod_conta_origem = l.cod_conta_origem
        WHERE l.empresa_id = ?
          AND COALESCE(l.enviado_superdunga, 'N') <> 'S'
          AND l.tabela_destino IS NULL
          AND l.id_destino IS NULL
          {$filtroData}
          AND ROUND(COALESCE(l.valor_origem, 0), 2) = ROUND(?, 2)
        ORDER BY l.linha_origem
        LIMIT 10
    ");
    $params = [$empresaId];
    if ($usarData) {
        $params[] = $linha['DTMOV'];
        $params[] = $linha['DTMOV'];
    }
    $params[] = (float)$linha['VALORMOV'];
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function acMapaCandidatosCaixa(PDO $pdo, int $empresaId, array $linhas, string $dataAccessCampo): array
{
    if (!$linhas) {
        return [];
    }

    $dataAccessSql = acCampoDataAccess($dataAccessCampo);
    $usarData = $dataAccessSql !== '';
    $valores = [];
    $datas = [];
    foreach ($linhas as $linha) {
        if ($usarData && empty($linha['DTMOV'])) {
            continue;
        }
        $valores[acValorKey($linha['VALORMOV'] ?? 0)] = true;
        if ($usarData) {
            $datas[] = acDataKey($linha['DTMOV']);
        }
    }
    $datas = array_values(array_filter($datas));
    if (!$valores || ($usarData && !$datas)) {
        return [];
    }

    if ($usarData) {
        sort($datas);
        $dataMin = date('Y-m-d', strtotime($datas[0] . ' -3 days'));
        $dataMax = date('Y-m-d', strtotime($datas[count($datas) - 1] . ' +3 days'));
    }
    $valorLista = array_keys($valores);
    $valorPlaceholders = implode(',', array_fill(0, count($valorLista), '?'));
    $selectData = $usarData ? "DATE({$dataAccessSql}) AS data_access_ref" : "NULL AS data_access_ref";
    $filtroData = $usarData ? "AND {$dataAccessSql} IS NOT NULL AND DATE({$dataAccessSql}) BETWEEN ? AND ?" : '';
    $ordemData = $usarData ? "DATE({$dataAccessSql}), " : '';

    $stmt = $pdo->prepare("
        SELECT l.*, ca.descricao_conta, ca.banco_bnc002, {$selectData}
        FROM mov_baixa_lancamentos_access l
        LEFT JOIN mov_baixa_contas_access ca
               ON ca.empresa_id = l.empresa_id
              AND ca.cod_conta_origem = l.cod_conta_origem
        WHERE l.empresa_id = ?
          AND COALESCE(l.enviado_superdunga, 'N') <> 'S'
          AND l.tabela_destino IS NULL
          AND l.id_destino IS NULL
          {$filtroData}
          AND ROUND(COALESCE(l.valor_origem, 0), 2) IN ({$valorPlaceholders})
        ORDER BY {$ordemData}ROUND(COALESCE(l.valor_origem, 0), 2), l.linha_origem
    ");
    $params = [$empresaId];
    if ($usarData) {
        $params[] = $dataMin;
        $params[] = $dataMax;
    }
    $stmt->execute(array_merge($params, array_map('floatval', $valorLista)));
    $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mapa = [];
    foreach ($linhas as $linha) {
        $mov = (int)($linha['MOVCONTADOR'] ?? 0);
        $dataMov = acDataKey($linha['DTMOV'] ?? '');
        $valorMov = acValorKey($linha['VALORMOV'] ?? 0);
        if ($mov <= 0 || ($usarData && $dataMov === '')) {
            continue;
        }

        $mapa[$mov] = [];
        foreach ($candidatos as $cand) {
            if (acValorKey($cand['valor_origem'] ?? 0) !== $valorMov) {
                continue;
            }
            if ($usarData && acDiasDiferenca($dataMov, acDataKey($cand['data_access_ref'] ?? '')) > 3) {
                continue;
            }
            $mapa[$mov][] = $cand;
            if (count($mapa[$mov]) >= 10) {
                break;
            }
        }
    }

    return $mapa;
}

function acCandidatosTitulo(PDO $pdo, int $empresaId, array $linha, string $dataAccessCampo, string $dataSuperCampo, string $valorCampo): array
{
    $dataAccessSql = acCampoDataAccess($dataAccessCampo);
    $usarData = $dataAccessSql !== '';
    if ($usarData && empty($linha[$dataSuperCampo])) {
        return [];
    }
    $filtroData = $usarData ? "AND {$dataAccessSql} IS NOT NULL AND DATE({$dataAccessSql}) BETWEEN DATE_SUB(DATE(?), INTERVAL 3 DAY) AND DATE_ADD(DATE(?), INTERVAL 3 DAY)" : '';

    $stmt = $pdo->prepare("
        SELECT l.*, ca.descricao_conta, ca.banco_bnc002
        FROM mov_baixa_lancamentos_access l
        LEFT JOIN mov_baixa_contas_access ca
               ON ca.empresa_id = l.empresa_id
              AND ca.cod_conta_origem = l.cod_conta_origem
        WHERE l.empresa_id = ?
          AND COALESCE(l.enviado_superdunga, 'N') <> 'S'
          AND l.tabela_destino IS NULL
          AND l.id_destino IS NULL
          {$filtroData}
          AND ROUND(COALESCE(l.valor_origem, 0), 2) = ROUND(?, 2)
        ORDER BY l.linha_origem
        LIMIT 10
    ");
    $params = [$empresaId];
    if ($usarData) {
        $params[] = $linha[$dataSuperCampo];
        $params[] = $linha[$dataSuperCampo];
    }
    $params[] = (float)$linha[$valorCampo];
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function acMapaCandidatosTitulo(PDO $pdo, int $empresaId, array $linhas, string $dataAccessCampo, string $dataSuperCampo, string $valorCampo, string $idCampo): array
{
    if (!$linhas) {
        return [];
    }

    $dataAccessSql = acCampoDataAccess($dataAccessCampo);
    $usarData = $dataAccessSql !== '';
    $valores = [];
    $datas = [];
    foreach ($linhas as $linha) {
        if ($usarData && empty($linha[$dataSuperCampo])) {
            continue;
        }
        $valores[acValorKey($linha[$valorCampo] ?? 0)] = true;
        if ($usarData) {
            $datas[] = acDataKey($linha[$dataSuperCampo]);
        }
    }
    $datas = array_values(array_filter($datas));
    if (!$valores || ($usarData && !$datas)) {
        return [];
    }

    if ($usarData) {
        sort($datas);
        $dataMin = date('Y-m-d', strtotime($datas[0] . ' -3 days'));
        $dataMax = date('Y-m-d', strtotime($datas[count($datas) - 1] . ' +3 days'));
    }
    $valorLista = array_keys($valores);
    $valorPlaceholders = implode(',', array_fill(0, count($valorLista), '?'));
    $selectData = $usarData ? "DATE({$dataAccessSql}) AS data_access_ref" : "NULL AS data_access_ref";
    $filtroData = $usarData ? "AND {$dataAccessSql} IS NOT NULL AND DATE({$dataAccessSql}) BETWEEN ? AND ?" : '';
    $ordemData = $usarData ? "DATE({$dataAccessSql}), " : '';

    $stmt = $pdo->prepare("
        SELECT l.*, ca.descricao_conta, ca.banco_bnc002, {$selectData}
        FROM mov_baixa_lancamentos_access l
        LEFT JOIN mov_baixa_contas_access ca
               ON ca.empresa_id = l.empresa_id
              AND ca.cod_conta_origem = l.cod_conta_origem
        WHERE l.empresa_id = ?
          AND COALESCE(l.enviado_superdunga, 'N') <> 'S'
          AND l.tabela_destino IS NULL
          AND l.id_destino IS NULL
          {$filtroData}
          AND ROUND(COALESCE(l.valor_origem, 0), 2) IN ({$valorPlaceholders})
        ORDER BY {$ordemData}ROUND(COALESCE(l.valor_origem, 0), 2), l.linha_origem
    ");
    $params = [$empresaId];
    if ($usarData) {
        $params[] = $dataMin;
        $params[] = $dataMax;
    }
    $stmt->execute(array_merge($params, array_map('floatval', $valorLista)));
    $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mapa = [];
    foreach ($linhas as $linha) {
        $id = (int)($linha[$idCampo] ?? 0);
        $dataSuper = acDataKey($linha[$dataSuperCampo] ?? '');
        $valorSuper = acValorKey($linha[$valorCampo] ?? 0);
        if ($id <= 0 || ($usarData && $dataSuper === '')) {
            continue;
        }

        $mapa[$id] = [];
        foreach ($candidatos as $cand) {
            if (acValorKey($cand['valor_origem'] ?? 0) !== $valorSuper) {
                continue;
            }
            if ($usarData && acDiasDiferenca($dataSuper, acDataKey($cand['data_access_ref'] ?? '')) > 3) {
                continue;
            }
            $mapa[$id][] = $cand;
            if (count($mapa[$id]) >= 10) {
                break;
            }
        }
    }

    return $mapa;
}

function acRenderCandidatos(array $candidatos, string $destinoTipo, int $destinoId): void
{
    if (!$candidatos) {
        echo '<span class="badge text-bg-light">Sem candidato no Access</span>';
        return;
    }

    foreach ($candidatos as $cand) {
        $tipoAccess = '';
        if ((float)($cand['debito_origem'] ?? 0) > 0 && (float)($cand['credito_origem'] ?? 0) <= 0) {
            $tipoAccess = 'D';
        } elseif ((float)($cand['credito_origem'] ?? 0) > 0 && (float)($cand['debito_origem'] ?? 0) <= 0) {
            $tipoAccess = 'C';
        }
        ?>
        <div class="ac-candidato">
            <div class="ac-candidato-info">
                <strong>Access linha <?= (int)$cand['linha_origem'] ?> | Codigo <?= acH($cand['codigo_origem']) ?></strong>
                <div><?= acH(acData($cand['data_pagamento_origem'])) ?> / <?= acH(acData($cand['data_bom_para_origem'])) ?> / <?= acH(acData($cand['data_emissao_origem'])) ?> | <?= acH(acMoeda($cand['valor_origem'])) ?> | Tipo: <?= acH($tipoAccess ?: '-') ?></div>
                <div class="text-muted">Documento: <?= acH($cand['documento_origem'] ?: '-') ?> | Cheque_N: <?= acH($cand['cheque_n_origem'] ?: '-') ?></div>
                <div class="text-muted"><?= acH($cand['cod_conta_origem']) ?> <?= $cand['descricao_conta'] ? '- ' . acH($cand['descricao_conta']) : '' ?> <?= $cand['banco_bnc002'] ? '| BNC002 ' . (int)$cand['banco_bnc002'] : '' ?></div>
                <div class="text-muted ac-truncate" title="<?= acH($cand['observacao_origem']) ?>"><?= acH($cand['observacao_origem'] ?: $cand['cod_historico_origem']) ?></div>
            </div>
            <form method="post" class="m-0" onsubmit="return confirm('Amarrar este registro do SuperDunga ao lancamento Access linha <?= (int)$cand['linha_origem'] ?>?');">
                <input type="hidden" name="acao" value="amarrar_access">
                <input type="hidden" name="access_id" value="<?= (int)$cand['id'] ?>">
                <input type="hidden" name="destino_tipo" value="<?= acH($destinoTipo) ?>">
                <input type="hidden" name="destino_id" value="<?= (int)$destinoId ?>">
                <input type="hidden" name="redirect_query" value="<?= acH(acQueryAtual(['ok'])) ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary">Amarrar</button>
            </form>
        </div>
        <?php
    }
}

function acCss(): string
{
    return '
    <style>
        .ac-card { background:#fff; border:1px solid #dbe3ef; border-radius:8px; box-shadow:0 8px 24px rgba(15,23,42,.06); }
        .ac-card .card-header { background:#f8fafc; border-bottom:1px solid #e2e8f0; font-weight:700; }
        .ac-kpi { border:1px solid #e2e8f0; border-radius:8px; padding:14px; background:#fff; min-height:82px; }
        .ac-kpi small { display:block; color:#64748b; font-size:12px; text-transform:uppercase; font-weight:700; }
        .ac-kpi strong { display:block; color:#0f172a; font-size:19px; margin-top:6px; }
        .ac-table { font-size:12px; }
        .ac-table th { white-space:nowrap; color:#334155; background:#f1f5f9; }
        .ac-table td { vertical-align:middle; }
        .ac-candidato { display:flex; justify-content:space-between; gap:10px; border:1px solid #e2e8f0; border-radius:6px; padding:7px 8px; margin-bottom:6px; background:#fff; }
        .ac-candidato:last-child { margin-bottom:0; }
        .ac-candidato-info { min-width:0; }
        .ac-truncate { max-width:420px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .ac-code { font-family:Consolas, monospace; font-size:12px; }
        .ac-candidatos-row td { background:#f8fafc; border-top:0; }
        .ac-candidatos-row summary { cursor:pointer; font-weight:700; color:#1d4ed8; }
    </style>';
}
