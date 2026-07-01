<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

function crbGarantirEstrutura(PDO $pdo)
{
    $colunas = [
        'CONTROLE' => "ALTER TABLE armazem_cr001 ADD CONTROLE VARCHAR(60) NULL",
        'CHAVEINTEGRACAO' => "ALTER TABLE armazem_cr001 ADD CHAVEINTEGRACAO VARCHAR(120) NULL",
        'financeiro_verificado' => "ALTER TABLE armazem_cr001 ADD financeiro_verificado CHAR(1) NOT NULL DEFAULT 'N'",
        'USERALT' => "ALTER TABLE armazem_cr001 ADD USERALT INT NULL",
        'DTALT' => "ALTER TABLE armazem_cr001 ADD DTALT DATETIME NULL",
        'CBCONTADOR' => "ALTER TABLE armazem_cr001 ADD CBCONTADOR INT NULL",
        'TIPOES' => "ALTER TABLE armazem_cr001 ADD TIPOES INT NULL",
        'excluido_firebird' => "ALTER TABLE armazem_cr001 ADD excluido_firebird CHAR(1) NOT NULL DEFAULT 'N'",
        'data_exclusao_firebird' => "ALTER TABLE armazem_cr001 ADD data_exclusao_firebird DATETIME NULL",
        'motivo_sync' => "ALTER TABLE armazem_cr001 ADD motivo_sync VARCHAR(100) NULL",
    ];

    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'armazem_cr001'
    ");
    $stmt->execute();
    $existentes = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    foreach ($colunas as $coluna => $sql) {
        if (!isset($existentes[$coluna])) {
            $pdo->exec($sql);
        }
    }
}

crbGarantirEstrutura($pdo);

function crbH($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function crbFloat($valor)
{
    if ($valor === null || $valor === '') {
        return 0.0;
    }
    $valor = trim((string)$valor);
    $valor = str_replace(['R$', ' '], '', $valor);
    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }
    return (float)$valor;
}

function crbMoeda($valor)
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function crbData($valor)
{
    return $valor ? date('d/m/Y', strtotime($valor)) : '';
}

function crbProximoCrcontador(PDO $pdo, $empresaId)
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CRCONTADOR), 0) + 1 FROM armazem_cr001 WHERE EMPRESA = ?");
    $stmt->execute([$empresaId]);
    return (int)$stmt->fetchColumn();
}

function crbProximoMovcontador(PDO $pdo)
{
    $stmt = $pdo->query("SELECT COALESCE(MAX(MOVCONTADOR), 0) + 1 FROM armazem_bnc001");
    return (int)$stmt->fetchColumn();
}

function crbSomarMesComDia(DateTime $dataBase, $meses, $diaFixo)
{
    $ano = (int)$dataBase->format('Y');
    $mes = (int)$dataBase->format('m') + (int)$meses;

    while ($mes > 12) {
        $mes -= 12;
        $ano++;
    }

    $ultimoDia = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
    $dia = min(max(1, (int)$diaFixo), $ultimoDia);

    return new DateTime(sprintf('%04d-%02d-%02d', $ano, $mes, $dia));
}

function crbVencimentoParcela($primeiroVencimento, $indice, $modo, $diaFixo)
{
    $data = new DateTime($primeiroVencimento);

    if ($indice <= 1) {
        return $data->format('Y-m-d');
    }

    if ($modo === 'fixo') {
        $dia = $diaFixo > 0 ? $diaFixo : (int)$data->format('d');
        return crbSomarMesComDia($data, $indice - 1, $dia)->format('Y-m-d');
    }

    $intervalosDias = [
        '7dias' => 7,
        '10dias' => 10,
        '15dias' => 15,
        '30dias' => 30,
    ];
    $dias = $intervalosDias[$modo] ?? 30;
    $data->modify('+' . ($dias * ($indice - 1)) . ' days');
    return $data->format('Y-m-d');
}

function crbBuscarCliente(PDO $pdo, $empresaId, $clicontador)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_cr002
        WHERE EMPRESA = ?
          AND CLICONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(INATIVO, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $clicontador]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function crbBuscarTipoes(PDO $pdo, $empresaId, $tipoes)
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
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function crbValidar(PDO $pdo, $empresaId, array $dados)
{
    $erros = [];
    $qtdParcelas = max(1, (int)($dados['qtd_parcelas'] ?? 1));

    if (empty($dados['dtvenda'])) {
        $erros[] = 'Informe a data da venda.';
    }
    if (empty($dados['dtvenc'])) {
        $erros[] = 'Informe a data de vencimento.';
    }
    if (empty($dados['clicontador']) || !crbBuscarCliente($pdo, $empresaId, (int)$dados['clicontador'])) {
        $erros[] = 'Informe um cliente valido.';
    }
    if (empty($dados['tipoes']) || !crbBuscarTipoes($pdo, $empresaId, (int)$dados['tipoes'])) {
        $erros[] = 'Informe um TIPOES valido.';
    }
    if (crbFloat($dados['valor']) <= 0) {
        $erros[] = 'Informe um valor maior que zero.';
    }
    if ($qtdParcelas > 120) {
        $erros[] = 'Numero de parcelas invalido.';
    }
    if ($qtdParcelas > 1 && !in_array(($dados['vencimento_modo'] ?? ''), ['fixo', '7dias', '10dias', '15dias', '30dias'], true)) {
        $erros[] = 'Informe como calcular o vencimento das proximas parcelas.';
    }
    if ($qtdParcelas > 1 && ($dados['vencimento_modo'] ?? '') === 'fixo') {
        $diaFixo = (int)($dados['dia_fixo'] ?? 0);
        if ($diaFixo < 1 || $diaFixo > 31) {
            $erros[] = 'Informe um dia fixo valido para o vencimento.';
        }
    }
    if (trim((string)($dados['titulo'] ?? '')) === '') {
        $erros[] = 'Informe o documento/titulo.';
    }

    return $erros;
}

function crbSalvar(PDO $pdo, $empresaId, $usuarioId, array $dados, $crcontadorEdicao = null)
{
    $erros = crbValidar($pdo, $empresaId, $dados);
    if ($erros) {
        throw new RuntimeException(implode(' ', $erros));
    }

    $valor = crbFloat($dados['valor']);
    $qtdParcelas = max(1, (int)($dados['qtd_parcelas'] ?? 1));
    $titulo = trim((string)$dados['titulo']);
    $observacao = trim((string)($dados['observacao'] ?? ''));
    $notaFiscal = trim((string)($dados['notafiscal'] ?? ''));
    $parcela = trim((string)($dados['parcela'] ?? '1/1'));
    $numParcela = (int)($dados['numparcela'] ?? 1);
    $numParcela = max(1, $numParcela);

    if ($crcontadorEdicao) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM armazem_cr001
            WHERE EMPRESA = ?
              AND CRCONTADOR = ?
              AND TIPODOCORIGEM = 'SUPERDUNGA'
              AND CONTROLE = 'MOVIMENTACAO_BAIXA'
              AND COALESCE(excluido_firebird, 'N') <> 'S'
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $crcontadorEdicao]);
        $atual = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$atual) {
            throw new RuntimeException('Titulo nao encontrado para edicao.');
        }

        if (($atual['STATUS'] ?? '') === 'QT') {
            throw new RuntimeException('Titulo quitado nao pode ser editado nesta tela.');
        }

        $stmt = $pdo->prepare("
            UPDATE armazem_cr001
            SET DTVENDA = ?,
                DTEMISSAO = ?,
                DTVENC = ?,
                CLICONTADOR = ?,
                TIPOES = ?,
                TITULO = ?,
                NOTAFISCAL = ?,
                OBSERVACAO = ?,
                NUMPARCELA = ?,
                PARCELA = ?,
                VALORVENDA = ?,
                VLRPARCELA = ?,
                VLRRESTANTE = ?,
                USERALT = ?,
                DTALT = NOW(),
                REGSTAMP = NOW()
            WHERE EMPRESA = ?
              AND CRCONTADOR = ?
        ");
        $stmt->execute([
            $dados['dtvenda'],
            $dados['dtvenda'],
            $dados['dtvenc'],
            (int)$dados['clicontador'],
            (int)$dados['tipoes'],
            $titulo,
            $notaFiscal !== '' ? $notaFiscal : null,
            $observacao !== '' ? $observacao : null,
            $numParcela,
            $parcela,
            $valor,
            $valor,
            $valor,
            $usuarioId ?: null,
            $empresaId,
            $crcontadorEdicao,
        ]);

        return (int)$crcontadorEdicao;
    }

    $valorParcela = round($valor / $qtdParcelas, 2);
    $totalParcelas = [];
    for ($i = 1; $i <= $qtdParcelas; $i++) {
        $totalParcelas[$i] = $valorParcela;
    }
    $diferenca = round($valor - array_sum($totalParcelas), 2);
    $totalParcelas[$qtdParcelas] = round($totalParcelas[$qtdParcelas] + $diferenca, 2);

    $crcontadores = [];
    $modoVencimento = $dados['vencimento_modo'] ?? '30dias';
    $diaFixo = (int)($dados['dia_fixo'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO armazem_cr001 (
            EMPRESA, CRCONTADOR, DTVENDA, NUMPARCELA, TITULO, VALORVENDA,
            CLICONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
            VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
            TIPOCR, TIPOES, NOTAFISCAL, REGSTAMP, USERLANC, DTLANC,
            USERALT, DTALT, CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'AB', 'SUPERDUNGA', ?, 'MOVIMENTACAO_BAIXA',
            'CR', ?, ?, NOW(), ?, NOW(), ?, NOW(), ?, 'N', 'N'
        )
    ");

    for ($i = 1; $i <= $qtdParcelas; $i++) {
        $crcontador = crbProximoCrcontador($pdo, $empresaId);
        $crcontadores[] = $crcontador;
        $chave = 'MOVBAIXA-CR-' . $empresaId . '-' . $crcontador;
        $parcelaAtual = $qtdParcelas === 1 ? '1/1' : $i . '/' . $qtdParcelas;
        $vencimentoParcela = crbVencimentoParcela($dados['dtvenc'], $i, $modoVencimento, $diaFixo);
        $valorAtual = $totalParcelas[$i];

        $stmt->execute([
            $empresaId,
            $crcontador,
            $dados['dtvenda'],
            $i,
            $titulo,
            $valor,
            (int)$dados['clicontador'],
            $observacao !== '' ? $observacao : null,
            $dados['dtvenda'],
            $valorAtual,
            $parcelaAtual,
            $vencimentoParcela,
            $valorAtual,
            $crcontador,
            (int)$dados['tipoes'],
            $notaFiscal !== '' ? $notaFiscal : null,
            $usuarioId ?: null,
            $usuarioId ?: null,
            $chave,
        ]);
    }

    return $crcontadores;
}

function crbCarregarTitulosParaBaixa(PDO $pdo, $empresaId, array $crcontadores)
{
    $crcontadores = array_values(array_unique(array_filter(array_map('intval', $crcontadores))));
    if (!$crcontadores) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($crcontadores), '?'));
    $stmt = $pdo->prepare("
        SELECT cp.*,
               COALESCE(NULLIF(f.APELIDO, ''), f.NOME, CONCAT('Cliente ', cp.CLICONTADOR)) AS cliente_nome
        FROM armazem_cr001 cp
        LEFT JOIN armazem_cr002 f
          ON f.EMPRESA = cp.EMPRESA
         AND f.CLICONTADOR = cp.CLICONTADOR
        WHERE cp.EMPRESA = ?
          AND cp.CRCONTADOR IN ($placeholders)
          AND COALESCE(cp.excluido_firebird, 'N') <> 'S'
          AND COALESCE(cp.STATUS, 'AB') <> 'QT'
        ORDER BY cp.DTVENC ASC, cp.CRCONTADOR ASC
    ");
    $stmt->execute(array_merge([$empresaId], $crcontadores));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function crbBuscarContaBaixa(PDO $pdo, $empresaId, $cbcontador)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_bnc002
        WHERE EMPRESA = ?
          AND CBCONTADOR = ?
          AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $cbcontador]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function crbBaixarTitulos(PDO $pdo, $empresaId, $usuarioId, array $dados)
{
    $crcontadores = array_values(array_unique(array_filter(array_map('intval', (array)($dados['crcontadores'] ?? [])))));
    if (!$crcontadores) {
        throw new RuntimeException('Selecione ao menos um titulo para baixar.');
    }

    $dataBaixa = $dados['data_baixa'] ?? '';
    if (!$dataBaixa) {
        throw new RuntimeException('Informe a data da baixa.');
    }

    $cbcontador = (int)($dados['cbcontador_baixa'] ?? 0);
    if (!$cbcontador || !crbBuscarContaBaixa($pdo, $empresaId, $cbcontador)) {
        throw new RuntimeException('Informe uma conta valida para baixa.');
    }

    $tipoesBaixa = (int)($dados['tipoes_baixa'] ?? 0);
    $valoresBaixa = is_array($dados['valor_baixa'] ?? null) ? $dados['valor_baixa'] : [];
    $titulos = crbCarregarTitulosParaBaixa($pdo, $empresaId, $crcontadores);
    if (!$titulos) {
        throw new RuntimeException('Nenhum titulo aberto encontrado para baixa.');
    }

    $pdo->beginTransaction();

    try {
        $baixados = [];

        foreach ($titulos as $titulo) {
            $tipoes = $tipoesBaixa > 0 ? $tipoesBaixa : (int)($titulo['TIPOES'] ?? 0);
            if ($tipoes <= 0) {
                throw new RuntimeException('O titulo CR #' . (int)$titulo['CRCONTADOR'] . ' nao possui TIPOES. Informe um TIPOES para a baixa.');
            }

            $tipo = crbBuscarTipoes($pdo, $empresaId, $tipoes);
            if (!$tipo || empty($tipo['TIPOMOV'])) {
                throw new RuntimeException('O TIPOES ' . $tipoes . ' nao possui TIPOMOV configurado.');
            }

            $valor = (float)($titulo['VLRRESTANTE'] ?? 0);
            if ($valor <= 0) {
                $valor = (float)($titulo['VLRPARCELA'] ?? 0);
            }
            $valorInformado = $valoresBaixa[(int)$titulo['CRCONTADOR']] ?? null;
            if ($valorInformado !== null && trim((string)$valorInformado) !== '') {
                $valor = crbFloat($valorInformado);
            }
            if ($valor <= 0) {
                throw new RuntimeException('Informe um valor valido para o CR #' . (int)$titulo['CRCONTADOR'] . '.');
            }

            $movcontador = crbProximoMovcontador($pdo);
            $documento = $titulo['TITULO'] ?: ($titulo['NOTAFISCAL'] ?: $titulo['CRCONTADOR']);
            $historico = trim('BAIXA CR ' . (int)$titulo['CRCONTADOR'] . ' - ' . ($titulo['cliente_nome'] ?? '') . ' - ' . $documento);

            $stmtBnc = $pdo->prepare("
                INSERT INTO armazem_bnc001 (
                    EMPRESA, MOVCONTADOR, DTMOV, NUMDOC, TIPOMOV, CBCONTADOR, TIPOES,
                    CLICONTADOR, HISTMOV, VALORMOV, TIPODOCORIGEM, NUMDOCORIGEM, REGSTAMP,
                    USERBNCLANC, CONTRAPARTIDA, ORIGEMCPART, DTLANC, DTPROCESSADO, deletado
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'CR001', ?, NOW(),
                    ?, 'N', 0, NOW(), NOW(), 'N'
                )
            ");
            $stmtBnc->execute([
                $empresaId,
                $movcontador,
                $dataBaixa,
                $documento,
                strtoupper((string)$tipo['TIPOMOV']),
                $cbcontador,
                $tipoes,
                (int)$titulo['CLICONTADOR'],
                $historico,
                $valor,
                (int)$titulo['CRCONTADOR'],
                $usuarioId ?: null,
            ]);

            $stmtCp = $pdo->prepare("
                UPDATE armazem_cr001
                SET STATUS = 'QT',
                    DTPAGTO = ?,
                    VLRPAGO = ?,
                    VLRRESTANTE = 0,
                    CBCONTADOR = ?,
                    TIPOES = ?,
                    USERALT = ?,
                    DTALT = NOW(),
                    REGSTAMP = NOW()
                WHERE EMPRESA = ?
                  AND CRCONTADOR = ?
            ");
            $stmtCp->execute([
                $dataBaixa,
                $valor,
                $cbcontador,
                $tipoes,
                $usuarioId ?: null,
                $empresaId,
                (int)$titulo['CRCONTADOR'],
            ]);

            $baixados[] = (int)$titulo['CRCONTADOR'];
        }

        $pdo->commit();
        return $baixados;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function crbAgruparTitulos(PDO $pdo, $empresaId, $usuarioId, array $dados)
{
    $crcontadores = array_values(array_unique(array_filter(array_map('intval', (array)($dados['crcontadores'] ?? [])))));
    if (count($crcontadores) < 2) {
        throw new RuntimeException('Selecione ao menos dois titulos para agrupar.');
    }

    $novoVencimento = $dados['novo_vencimento'] ?? '';
    $novoTitulo = trim((string)($dados['novo_titulo'] ?? ''));
    $observacao = trim((string)($dados['observacao_agrupamento'] ?? ''));
    $tipoes = (int)($dados['tipoes_agrupamento'] ?? 0);

    if ($novoVencimento === '') {
        throw new RuntimeException('Informe o vencimento do novo titulo.');
    }
    if ($novoTitulo === '') {
        throw new RuntimeException('Informe o documento/titulo do agrupamento.');
    }
    if ($tipoes <= 0 || !crbBuscarTipoes($pdo, $empresaId, $tipoes)) {
        throw new RuntimeException('Informe um TIPOES valido para o novo titulo.');
    }

    $titulos = crbCarregarTitulosParaBaixa($pdo, $empresaId, $crcontadores);
    if (count($titulos) !== count($crcontadores)) {
        throw new RuntimeException('Um ou mais titulos selecionados nao estao abertos para agrupamento.');
    }

    $clientes = array_values(array_unique(array_map(static function ($titulo) {
        return (int)($titulo['CLICONTADOR'] ?? 0);
    }, $titulos)));
    if (count($clientes) !== 1 || (int)$clientes[0] <= 0) {
        throw new RuntimeException('Para agrupar, todos os titulos devem ser do mesmo cliente.');
    }

    $total = 0.0;
    $origens = [];
    foreach ($titulos as $titulo) {
        if (($titulo['CONTROLE'] ?? '') === 'AGRUPADO_MOV_BAIXA') {
            throw new RuntimeException('O CR #' . (int)$titulo['CRCONTADOR'] . ' ja esta agrupado.');
        }
        $valor = (float)($titulo['VLRRESTANTE'] ?? 0);
        if ($valor <= 0) {
            $valor = (float)($titulo['VLRPARCELA'] ?? 0);
        }
        if ($valor <= 0) {
            throw new RuntimeException('O CR #' . (int)$titulo['CRCONTADOR'] . ' nao possui valor para agrupamento.');
        }
        $total += $valor;
        $origens[] = (int)$titulo['CRCONTADOR'];
    }
    $total = round($total, 2);

    $pdo->beginTransaction();
    try {
        $novoCrcontador = crbProximoCrcontador($pdo, $empresaId);
        $chave = 'MOVBAIXA-CR-AGRUP-' . $empresaId . '-' . $novoCrcontador;
        $obsNovo = trim(($observacao !== '' ? $observacao . ' | ' : '') . 'Agrupa CR ' . implode(', CR ', $origens));

        $stmtNovo = $pdo->prepare("
            INSERT INTO armazem_cr001 (
                EMPRESA, CRCONTADOR, DTVENDA, NUMPARCELA, TITULO, VALORVENDA,
                CLICONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
                VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
                TIPOCR, TIPOES, REGSTAMP, USERLANC, DTLANC, USERALT, DTALT,
                CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
            ) VALUES (
                ?, ?, CURDATE(), 1, ?, ?, ?, ?, CURDATE(), ?, '1/1', ?,
                ?, 0, 'AB', 'SUPERDUNGA', ?, 'AGRUPAMENTO_MOV_BAIXA',
                'CR', ?, NOW(), ?, NOW(), ?, NOW(), ?, 'N', 'N'
            )
        ");
        $stmtNovo->execute([
            $empresaId,
            $novoCrcontador,
            $novoTitulo,
            $total,
            (int)$clientes[0],
            $obsNovo,
            $total,
            $novoVencimento,
            $total,
            $novoCrcontador,
            $tipoes,
            $usuarioId ?: null,
            $usuarioId ?: null,
            $chave,
        ]);

        $stmtOrigem = $pdo->prepare("
            UPDATE armazem_cr001
            SET STATUS = 'QT',
                VLRPAGO = COALESCE(NULLIF(VLRRESTANTE, 0), VLRPARCELA, 0),
                VLRRESTANTE = 0,
                NUMDOCORIGEM = ?,
                CONTROLE = 'AGRUPADO_MOV_BAIXA',
                OBSERVACAO = TRIM(CONCAT(COALESCE(OBSERVACAO, ''), CASE WHEN COALESCE(OBSERVACAO, '') <> '' THEN ' | ' ELSE '' END, ?)),
                USERALT = ?,
                DTALT = NOW(),
                REGSTAMP = NOW()
            WHERE EMPRESA = ?
              AND CRCONTADOR = ?
        ");
        foreach ($titulos as $titulo) {
            $stmtOrigem->execute([
                $novoCrcontador,
                'Agrupado no CR #' . $novoCrcontador,
                $usuarioId ?: null,
                $empresaId,
                (int)$titulo['CRCONTADOR'],
            ]);
        }

        $pdo->commit();
        return $novoCrcontador;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function crbTituloVinculadoAcerto(PDO $pdo, $empresaId, $crcontador)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM armazem_bnc001 b
        INNER JOIN financeiro_acertos_extrato_itens ai
            ON ai.empresa_id = b.EMPRESA
           AND ai.movcontador = b.MOVCONTADOR
        INNER JOIN financeiro_acertos_extrato a
            ON a.id = ai.acerto_id
           AND a.status = 'ATIVO'
        WHERE b.EMPRESA = ?
          AND b.TIPODOCORIGEM = 'CR001'
          AND b.NUMDOCORIGEM = ?
          AND COALESCE(b.deletado, 'N') <> 'S'
    ");
    $stmt->execute([$empresaId, (string)(int)$crcontador]);
    return (int)$stmt->fetchColumn() > 0;
}

function crbMovimentosBaixaPorTitulo(PDO $pdo, $empresaId, array $crcontadores)
{
    $crcontadores = array_values(array_unique(array_filter(array_map('intval', $crcontadores))));
    if (!$crcontadores) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($crcontadores), '?'));
    $stmt = $pdo->prepare("
        SELECT
            b.NUMDOCORIGEM AS crcontador,
            b.MOVCONTADOR,
            b.DTMOV,
            b.CBCONTADOR,
            COALESCE(c.DESCABREV, c.TITULAR, CONCAT('Conta ', b.CBCONTADOR)) AS conta_nome,
            b.TIPOES,
            t.DESCES AS tipoes_desc,
            b.TIPOMOV,
            b.HISTMOV,
            b.VALORMOV,
            a.id AS acerto_id
        FROM armazem_bnc001 b
        LEFT JOIN armazem_bnc002 c
          ON c.EMPRESA = b.EMPRESA
         AND c.CBCONTADOR = b.CBCONTADOR
        LEFT JOIN armazem_bnc005 t
          ON t.EMPRESA = b.EMPRESA
         AND t.ESCONTADOR = b.TIPOES
        LEFT JOIN financeiro_acertos_extrato_itens ai
          ON ai.empresa_id = b.EMPRESA
         AND ai.movcontador = b.MOVCONTADOR
        LEFT JOIN financeiro_acertos_extrato a
          ON a.id = ai.acerto_id
         AND a.status = 'ATIVO'
        WHERE b.EMPRESA = ?
          AND b.TIPODOCORIGEM = 'CR001'
          AND b.NUMDOCORIGEM IN ($placeholders)
          AND COALESCE(b.deletado, 'N') <> 'S'
        ORDER BY b.NUMDOCORIGEM, b.DTMOV, b.MOVCONTADOR
    ");
    $stmt->execute(array_merge([$empresaId], array_map('strval', $crcontadores)));

    $porTitulo = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $movimento) {
        $porTitulo[(int)$movimento['crcontador']][] = $movimento;
    }

    return $porTitulo;
}

function crbExcluirTitulo(PDO $pdo, $empresaId, $usuarioId, $crcontador)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_cr001
        WHERE EMPRESA = ?
          AND CRCONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $crcontador]);
    $titulo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$titulo) {
        throw new RuntimeException('Titulo nao encontrado para exclusao.');
    }

    if (($titulo['STATUS'] ?? '') === 'QT') {
        throw new RuntimeException('Titulo quitado nao pode ser excluido.');
    }

    if (($titulo['TIPODOCORIGEM'] ?? '') !== 'SUPERDUNGA' || ($titulo['CONTROLE'] ?? '') !== 'MOVIMENTACAO_BAIXA') {
        throw new RuntimeException('Somente titulos criados pelo Mov/Baixa podem ser excluidos nesta tela.');
    }

    if (crbTituloVinculadoAcerto($pdo, $empresaId, $crcontador)) {
        throw new RuntimeException('Titulo vinculado a acerto ativo nao pode ser excluido.');
    }

    $stmt = $pdo->prepare("
        UPDATE armazem_cr001
        SET excluido_firebird = 'S',
            data_exclusao_firebird = NOW(),
            motivo_sync = 'EXCLUIDO_MOVIMENTACAO_BAIXA',
            USERALT = ?,
            DTALT = NOW(),
            REGSTAMP = NOW()
        WHERE EMPRESA = ?
          AND CRCONTADOR = ?
    ");
    $stmt->execute([$usuarioId ?: null, $empresaId, $crcontador]);
}

function crbExcluirBaixaTitulo(PDO $pdo, $empresaId, $usuarioId, $movcontador)
{
    $stmt = $pdo->prepare("
        SELECT b.*, cp.CRCONTADOR, cp.VLRPARCELA
        FROM armazem_bnc001 b
        INNER JOIN armazem_cr001 cp
            ON cp.EMPRESA = b.EMPRESA
           AND cp.CRCONTADOR = CAST(b.NUMDOCORIGEM AS UNSIGNED)
        WHERE b.EMPRESA = ?
          AND b.MOVCONTADOR = ?
          AND b.TIPODOCORIGEM = 'CR001'
          AND COALESCE(b.deletado, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $movcontador]);
    $baixa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$baixa) {
        throw new RuntimeException('Baixa nao encontrada para exclusao.');
    }

    $stmtAcerto = $pdo->prepare("
        SELECT COUNT(*)
        FROM financeiro_acertos_extrato_itens ai
        INNER JOIN financeiro_acertos_extrato a
            ON a.id = ai.acerto_id
           AND a.status = 'ATIVO'
        WHERE ai.empresa_id = ?
          AND ai.movcontador = ?
    ");
    $stmtAcerto->execute([$empresaId, $movcontador]);
    if ((int)$stmtAcerto->fetchColumn() > 0) {
        throw new RuntimeException('Baixa vinculada a acerto ativo nao pode ser excluida.');
    }

    $crcontador = (int)$baixa['CRCONTADOR'];
    $valorParcela = (float)($baixa['VLRPARCELA'] ?? 0);

    $pdo->beginTransaction();
    try {
        $stmtDelete = $pdo->prepare("
            UPDATE armazem_bnc001
            SET deletado = 'S',
                data_delecao_firebird = NOW(),
                motivo_sync = 'BAIXA_CR_EXCLUIDA_MOVIMENTACAO_BAIXA',
                USERBNCALT = ?,
                DTALT = NOW(),
                REGSTAMP = NOW()
            WHERE EMPRESA = ?
              AND MOVCONTADOR = ?
        ");
        $stmtDelete->execute([$usuarioId ?: null, $empresaId, $movcontador]);

        $stmtCp = $pdo->prepare("
            UPDATE armazem_cr001
            SET STATUS = 'AB',
                DTPAGTO = NULL,
                VLRPAGO = 0,
                VLRRESTANTE = ?,
                CBCONTADOR = NULL,
                USERALT = ?,
                DTALT = NOW(),
                REGSTAMP = NOW()
            WHERE EMPRESA = ?
              AND CRCONTADOR = ?
        ");
        $stmtCp->execute([$valorParcela, $usuarioId ?: null, $empresaId, $crcontador]);

        $pdo->commit();
        return [$crcontador, $movcontador];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$mensagem = '';
$erro = '';
$titulosBaixa = [];
$idsBaixaSelecionados = [];
$titulosAgrupamento = [];
$idsAgrupamentoSelecionados = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? 'salvar_titulo';

    if ($acao === 'preparar_baixa') {
        $idsBaixaSelecionados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['crcontadores'] ?? [])))));
        $titulosBaixa = crbCarregarTitulosParaBaixa($pdo, $empresaId, $idsBaixaSelecionados);
        if (!$titulosBaixa) {
            $erro = 'Selecione ao menos um titulo aberto para baixa.';
        }
    } elseif ($acao === 'preparar_agrupamento') {
        $idsAgrupamentoSelecionados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['crcontadores'] ?? [])))));
        $titulosAgrupamento = crbCarregarTitulosParaBaixa($pdo, $empresaId, $idsAgrupamentoSelecionados);
        if (count($titulosAgrupamento) < 2) {
            $erro = 'Selecione ao menos dois titulos abertos para agrupar.';
        }
    } elseif ($acao === 'confirmar_baixa') {
        try {
            $baixados = crbBaixarTitulos($pdo, $empresaId, $usuarioId, $_POST);
            $mensagem = count($baixados) . ' titulo(s) baixado(s): #' . implode(', #', $baixados) . '.';
        } catch (Throwable $e) {
            $erro = $e->getMessage();
            $idsBaixaSelecionados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['crcontadores'] ?? [])))));
            $titulosBaixa = crbCarregarTitulosParaBaixa($pdo, $empresaId, $idsBaixaSelecionados);
        }
    } elseif ($acao === 'confirmar_agrupamento') {
        try {
            $novoCr = crbAgruparTitulos($pdo, $empresaId, $usuarioId, $_POST);
            $mensagem = 'Titulos agrupados no novo CR #' . (int)$novoCr . '.';
        } catch (Throwable $e) {
            $erro = $e->getMessage();
            $idsAgrupamentoSelecionados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['crcontadores'] ?? [])))));
            $titulosAgrupamento = crbCarregarTitulosParaBaixa($pdo, $empresaId, $idsAgrupamentoSelecionados);
        }
    } elseif ($acao === 'excluir_titulo') {
        try {
            $crcontadorExcluir = (int)($_POST['crcontador'] ?? 0);
            crbExcluirTitulo($pdo, $empresaId, $usuarioId, $crcontadorExcluir);
            $mensagem = 'Titulo CR #' . $crcontadorExcluir . ' excluido com sucesso.';
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    } elseif ($acao === 'excluir_baixa') {
        try {
            $movcontadorExcluir = (int)($_POST['movcontador'] ?? 0);
            list($crcontadorReaberto, $movcontadorExcluido) = crbExcluirBaixaTitulo($pdo, $empresaId, $usuarioId, $movcontadorExcluir);
            $mensagem = 'Baixa MOV #' . $movcontadorExcluido . ' excluida e titulo CR #' . $crcontadorReaberto . ' reaberto.';
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    } else {
        try {
            $crcontadorEdicao = !empty($_POST['crcontador_edicao']) ? (int)$_POST['crcontador_edicao'] : null;
            $resultado = crbSalvar($pdo, $empresaId, $usuarioId, $_POST, $crcontadorEdicao);
            $mensagem = $crcontadorEdicao
                ? 'Titulo CR #' . (int)$resultado . ' atualizado com sucesso.'
                : count($resultado) . ' titulo(s) gravado(s) com sucesso: #' . implode(', #', array_map('intval', $resultado)) . '.';
            $_GET['editar'] = null;
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    }
}

$stmtClientes = $pdo->prepare("
    SELECT CLICONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Cliente ', CLICONTADOR)) AS nome
    FROM armazem_cr002
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY nome, CLICONTADOR
");
$stmtClientes->execute([$empresaId]);
$clientes = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);

$stmtTipoes = $pdo->prepare("
    SELECT ESCONTADOR, DESCES, TIPOMOV
    FROM armazem_bnc005
    WHERE EMPRESA = ?
      AND TIPOMOV = 'C'
      AND COALESCE(REGDISAB, 'N') <> 'S'
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY DESCES, ESCONTADOR
");
$stmtTipoes->execute([$empresaId]);
$tipos = $stmtTipoes->fetchAll(PDO::FETCH_ASSOC);

$stmtContas = $pdo->prepare("
    SELECT CBCONTADOR, NUMERO, DESCABREV, TITULAR
    FROM armazem_bnc002
    WHERE EMPRESA = ?
      AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY DESCABREV, NUMERO, CBCONTADOR
");
$stmtContas->execute([$empresaId]);
$contasBaixa = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

$editarCrcontador = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$tituloEdicao = null;

if ($editarCrcontador > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_cr001
        WHERE EMPRESA = ?
          AND CRCONTADOR = ?
          AND TIPODOCORIGEM = 'SUPERDUNGA'
          AND CONTROLE = 'MOVIMENTACAO_BAIXA'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $editarCrcontador]);
    $tituloEdicao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tituloEdicao) {
        $erro = $erro ?: 'Titulo nao encontrado para edicao.';
    }
}

$form = [
    'crcontador_edicao' => $tituloEdicao['CRCONTADOR'] ?? '',
    'dtvenda' => $tituloEdicao ? date('Y-m-d', strtotime($tituloEdicao['DTVENDA'])) : date('Y-m-d'),
    'dtvenc' => $tituloEdicao && !empty($tituloEdicao['DTVENC']) ? date('Y-m-d', strtotime($tituloEdicao['DTVENC'])) : date('Y-m-d'),
    'clicontador' => $tituloEdicao['CLICONTADOR'] ?? '',
    'tipoes' => $tituloEdicao['TIPOES'] ?? '',
    'titulo' => $tituloEdicao['TITULO'] ?? '',
    'notafiscal' => $tituloEdicao['NOTAFISCAL'] ?? '',
    'numparcela' => $tituloEdicao['NUMPARCELA'] ?? 1,
    'parcela' => $tituloEdicao['PARCELA'] ?? '1/1',
    'valor' => $tituloEdicao ? number_format((float)$tituloEdicao['VLRPARCELA'], 2, ',', '.') : '',
    'observacao' => $tituloEdicao['OBSERVACAO'] ?? '',
    'qtd_parcelas' => 1,
    'vencimento_modo' => 'fixo',
    'dia_fixo' => $tituloEdicao && !empty($tituloEdicao['DTVENC']) ? date('d', strtotime($tituloEdicao['DTVENC'])) : date('d'),
];

$fCliente = trim((string)($_GET['cliente'] ?? ''));
$fDocumento = trim((string)($_GET['documento'] ?? ''));
$fTipoes = trim((string)($_GET['tipoes'] ?? ''));
$fValorMin = trim((string)($_GET['valor_min'] ?? ''));
$fValorMax = trim((string)($_GET['valor_max'] ?? ''));
$fUsarData = ($_GET['usar_data'] ?? '') === 'S';
$fVendaIni = $_GET['venda_ini'] ?? '';
$fVendaFim = $_GET['venda_fim'] ?? '';
$fVencIni = $_GET['venc_ini'] ?? '';
$fVencFim = $_GET['venc_fim'] ?? '';
$fStatus = $_GET['status'] ?? '';
$fOrigem = $_GET['origem'] ?? 'todos';

$where = [
    "cp.EMPRESA = ?",
    "COALESCE(cp.excluido_firebird, 'N') <> 'S'",
];
$params = [$empresaId];

if ($fOrigem === 'movimentacao_baixa') {
    $where[] = "cp.TIPODOCORIGEM = 'SUPERDUNGA'";
    $where[] = "cp.CONTROLE = 'MOVIMENTACAO_BAIXA'";
} elseif ($fOrigem === 'superdunga') {
    $where[] = "cp.TIPODOCORIGEM = 'SUPERDUNGA'";
} elseif ($fOrigem === 'firebird') {
    $where[] = "(cp.TIPODOCORIGEM IS NULL OR cp.TIPODOCORIGEM <> 'SUPERDUNGA')";
}

if ($fCliente !== '') {
    $where[] = "(f.NOME LIKE ? OR f.APELIDO LIKE ? OR cp.CLICONTADOR LIKE ?)";
    $like = '%' . $fCliente . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($fDocumento !== '') {
    $where[] = "(cp.TITULO LIKE ? OR cp.NOTAFISCAL LIKE ? OR cp.NUMDOCORIGEM LIKE ?)";
    $like = '%' . $fDocumento . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($fTipoes !== '') {
    $where[] = "cp.TIPOES = ?";
    $params[] = (int)$fTipoes;
}
if ($fValorMin !== '') {
    $where[] = "cp.VLRPARCELA >= ?";
    $params[] = crbFloat($fValorMin);
}
if ($fValorMax !== '') {
    $where[] = "cp.VLRPARCELA <= ?";
    $params[] = crbFloat($fValorMax);
}
if ($fUsarData && $fVendaIni !== '') {
    $where[] = "DATE(cp.DTVENDA) >= ?";
    $params[] = $fVendaIni;
}
if ($fUsarData && $fVendaFim !== '') {
    $where[] = "DATE(cp.DTVENDA) <= ?";
    $params[] = $fVendaFim;
}
if ($fUsarData && $fVencIni !== '') {
    $where[] = "DATE(cp.DTVENC) >= ?";
    $params[] = $fVencIni;
}
if ($fUsarData && $fVencFim !== '') {
    $where[] = "DATE(cp.DTVENC) <= ?";
    $params[] = $fVencFim;
}
if ($fStatus !== '') {
    $where[] = "cp.STATUS = ?";
    $params[] = $fStatus;
}

$whereSql = implode(' AND ', $where);

$stmtLista = $pdo->prepare("
    SELECT cp.*,
           COALESCE(NULLIF(f.APELIDO, ''), f.NOME, CONCAT('Cliente ', cp.CLICONTADOR)) AS cliente_nome,
           t.DESCES AS tipoes_desc,
           CASE
               WHEN cp.TIPODOCORIGEM = 'SUPERDUNGA' AND cp.CONTROLE = 'MOVIMENTACAO_BAIXA' THEN 'Mov/Baixa'
               WHEN cp.TIPODOCORIGEM = 'SUPERDUNGA' THEN 'SuperDunga'
               ELSE 'Firebird'
           END AS origem_titulo
    FROM armazem_cr001 cp
    LEFT JOIN armazem_cr002 f
      ON f.EMPRESA = cp.EMPRESA
     AND f.CLICONTADOR = cp.CLICONTADOR
    LEFT JOIN armazem_bnc005 t
      ON t.EMPRESA = cp.EMPRESA
     AND t.ESCONTADOR = cp.TIPOES
    WHERE {$whereSql}
    ORDER BY cp.DTVENC DESC, cp.CRCONTADOR DESC
    LIMIT 200
");
$stmtLista->execute($params);
$titulos = $stmtLista->fetchAll(PDO::FETCH_ASSOC);

$totalValorLista = 0.0;
$totalRestanteLista = 0.0;
foreach ($titulos as $tituloTotal) {
    $totalValorLista += (float)($tituloTotal['VLRPARCELA'] ?? 0);
    $totalRestanteLista += (float)($tituloTotal['VLRRESTANTE'] ?? 0);
}

$idsTitulosLista = array_map(static function ($titulo) {
    return (int)$titulo['CRCONTADOR'];
}, $titulos);
$baixasPorTitulo = crbMovimentosBaixaPorTitulo($pdo, $empresaId, $idsTitulosLista);
$agrupadosPorTitulo = [];
if ($idsTitulosLista) {
    $placeholdersAgrupados = implode(',', array_fill(0, count($idsTitulosLista), '?'));
    $stmtAgrupados = $pdo->prepare("
        SELECT *
        FROM armazem_cr001
        WHERE EMPRESA = ?
          AND CONTROLE = 'AGRUPADO_MOV_BAIXA'
          AND NUMDOCORIGEM IN ($placeholdersAgrupados)
        ORDER BY NUMDOCORIGEM, DTVENC, CRCONTADOR
    ");
    $stmtAgrupados->execute(array_merge([$empresaId], $idsTitulosLista));
    foreach ($stmtAgrupados as $agrupado) {
        $agrupadosPorTitulo[(int)$agrupado['NUMDOCORIGEM']][] = $agrupado;
    }
}

require '../../layout/header.php';
?>

<style>
    .crb-wrap { max-width: 1180px; margin: 0 auto; padding: 18px; }
    .crb-hero { background: #143b63; color: #fff; border-radius: 6px; padding: 22px; margin-bottom: 18px; display:flex; justify-content:space-between; gap:16px; align-items:center; }
    .crb-hero h1 { margin:0 0 6px; font-size:1.55rem; }
    .crb-card { background:#fff; border:1px solid #d8dee8; border-radius:6px; padding:16px; margin-bottom:16px; box-shadow:0 2px 10px rgba(15,23,42,.04); }
    .crb-title { margin:0 0 14px; font-size:1.05rem; color:#1f2937; }
    .crb-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:12px; }
    .crb-field { grid-column:span 3; min-width:0; }
    .crb-field.w2 { grid-column:span 2; }
    .crb-field.w4 { grid-column:span 4; }
    .crb-field.w6 { grid-column:span 6; }
    .crb-field.w12 { grid-column:span 12; }
    .crb-field label { display:block; margin-bottom:5px; font-weight:600; color:#334155; font-size:.88rem; }
    .crb-field input, .crb-field select, .crb-field textarea { width:100%; border:1px solid #cbd5e1; border-radius:5px; padding:8px 9px; font-size:.95rem; background:#fff; }
    .crb-field textarea { min-height:76px; resize:vertical; }
    .crb-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:14px; }
    .crb-btn { border:0; border-radius:5px; padding:9px 13px; background:#173b73; color:#fff; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; }
    .crb-btn.secondary { background:#64748b; }
    .crb-btn.light { background:#e2e8f0; color:#0f172a; }
    .crb-alert { border-radius:5px; padding:11px 13px; margin-bottom:14px; }
    .crb-alert.ok { background:#dcfce7; color:#166534; border:1px solid #86efac; }
    .crb-alert.err { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
    .crb-summary { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px; margin-bottom:12px; }
    .crb-summary-item { border:1px solid #dbe3ef; background:#f8fafc; border-radius:6px; padding:10px 12px; }
    .crb-summary-label { color:#64748b; font-size:.82rem; font-weight:700; text-transform:uppercase; }
    .crb-summary-value { color:#0f172a; font-size:1.05rem; font-weight:800; margin-top:3px; }
    .crb-table-wrap { overflow-x:auto; }
    .crb-table { width:100%; border-collapse:collapse; min-width:1000px; }
    .crb-table th, .crb-table td { border-bottom:1px solid #e2e8f0; padding:9px 8px; text-align:left; vertical-align:top; font-size:.9rem; }
    .crb-table th { background:#12336b; color:#fff; font-size:.82rem; text-transform:uppercase; }
    .crb-badge { display:inline-block; border-radius:999px; padding:3px 8px; font-size:.78rem; font-weight:700; background:#e2e8f0; color:#0f172a; }
    .crb-badge.open { background:#fff7ed; color:#9a3412; }
    .crb-badge.paid { background:#dcfce7; color:#166534; }
    @media (max-width: 820px) {
        .crb-wrap { padding:12px; }
        .crb-hero { display:block; padding:18px; }
        .crb-grid { grid-template-columns:1fr; }
        .crb-summary { grid-template-columns:1fr; }
        .crb-field, .crb-field.w2, .crb-field.w4, .crb-field.w6, .crb-field.w12 { grid-column:span 1; }
        .crb-actions .crb-btn { width:100%; }
    }
</style>

<div class="crb-wrap">
    <div class="crb-hero">
        <div>
            <h1>Contas a Receber</h1>
            <div>Lancamento direto de titulos em CR001 para baixa posterior.</div>
        </div>
        <a class="crb-btn light" href="menu_movimentacao_baixa.php">Voltar</a>
    </div>

    <?php if ($mensagem): ?>
        <div class="crb-alert ok"><?= crbH($mensagem) ?></div>
    <?php endif; ?>
    <?php if ($erro): ?>
        <div class="crb-alert err"><?= crbH($erro) ?></div>
    <?php endif; ?>

    <?php if ($titulosBaixa): ?>
        <?php
            $dataBaixaPadrao = $_POST['data_baixa'] ?? '';
            if ($dataBaixaPadrao === '') {
                $vencimentosBaixa = array_filter(array_map(static function ($titulo) {
                    return !empty($titulo['DTVENC']) ? date('Y-m-d', strtotime($titulo['DTVENC'])) : null;
                }, $titulosBaixa));
                sort($vencimentosBaixa);
                $dataBaixaPadrao = $vencimentosBaixa[0] ?? date('Y-m-d');
            }
        ?>
        <div class="crb-card" id="baixa">
            <h2 class="crb-title">Confirmar baixa dos titulos selecionados</h2>
            <form method="post" autocomplete="off" onsubmit="return confirm('Confirmar baixa dos titulos selecionados?');">
                <input type="hidden" name="acao" value="confirmar_baixa">
                <?php foreach ($titulosBaixa as $tituloBaixa): ?>
                    <input type="hidden" name="crcontadores[]" value="<?= (int)$tituloBaixa['CRCONTADOR'] ?>">
                <?php endforeach; ?>

                <div class="crb-grid">
                    <div class="crb-field w3">
                        <label for="data_baixa">Data da baixa</label>
                        <input type="date" id="data_baixa" name="data_baixa" value="<?= crbH($dataBaixaPadrao) ?>" required>
                    </div>
                    <div class="crb-field w5">
                        <label for="cbcontador_baixa">Conta de baixa</label>
                        <select id="cbcontador_baixa" name="cbcontador_baixa" required>
                            <option value="">Selecione</option>
                            <?php foreach ($contasBaixa as $conta): ?>
                                <?php
                                    $descricaoConta = trim((string)($conta['DESCABREV'] ?: $conta['TITULAR'] ?: $conta['NUMERO']));
                                    $nomeConta = trim($descricaoConta . ' (' . ($conta['CBCONTADOR'] ?? '') . ($conta['NUMERO'] ? ' - ' . $conta['NUMERO'] : '') . ')');
                                    $selecionada = (string)($_POST['cbcontador_baixa'] ?? '') === (string)$conta['CBCONTADOR'];
                                ?>
                                <option value="<?= (int)$conta['CBCONTADOR'] ?>" <?= $selecionada ? 'selected' : '' ?>>
                                    <?= crbH($nomeConta) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="crb-field w4">
                        <label for="tipoes_baixa">TIPOES da baixa</label>
                        <select id="tipoes_baixa" name="tipoes_baixa">
                            <option value="">Manter TIPOES de cada titulo</option>
                            <?php foreach ($tipos as $tipo): ?>
                                <option value="<?= (int)$tipo['ESCONTADOR'] ?>" <?= (string)($_POST['tipoes_baixa'] ?? '') === (string)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                                    <?= crbH(($tipo['DESCES'] ?? '') . ' (' . $tipo['ESCONTADOR'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="crb-table-wrap" style="margin-top:14px;">
                    <table class="crb-table">
                        <thead>
                            <tr>
                                <th>CR</th>
                                <th>Vencimento</th>
                                <th>Cliente</th>
                                <th>Documento</th>
                                <th>TIPOES atual</th>
                                <th>Valor da baixa</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $totalBaixa = 0; ?>
                            <?php foreach ($titulosBaixa as $tituloBaixa): ?>
                                <?php
                                    $valorBaixa = (float)($tituloBaixa['VLRRESTANTE'] ?? 0);
                                    if ($valorBaixa <= 0) {
                                        $valorBaixa = (float)($tituloBaixa['VLRPARCELA'] ?? 0);
                                    }
                                    $valorCampo = $_POST['valor_baixa'][$tituloBaixa['CRCONTADOR']] ?? number_format($valorBaixa, 2, ',', '.');
                                    $totalBaixa += crbFloat($valorCampo);
                                ?>
                                <tr>
                                    <td><?= (int)$tituloBaixa['CRCONTADOR'] ?></td>
                                    <td><?= crbH(crbData($tituloBaixa['DTVENC'])) ?></td>
                                    <td><?= crbH(($tituloBaixa['CLICONTADOR'] ?? '') . ' - ' . ($tituloBaixa['cliente_nome'] ?? '')) ?></td>
                                    <td><?= crbH($tituloBaixa['TITULO'] ?? '') ?></td>
                                    <td><?= crbH($tituloBaixa['TIPOES'] ?? '') ?></td>
                                    <td>
                                        <input type="text" name="valor_baixa[<?= (int)$tituloBaixa['CRCONTADOR'] ?>]" value="<?= crbH($valorCampo) ?>" inputmode="decimal" style="width:120px;text-align:right;">
                                        <div class="text-muted small">Restante: <?= crbH(crbMoeda($valorBaixa)) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="5" style="text-align:right;font-weight:700;">Total</td>
                                <td style="font-weight:700;"><?= crbH(crbMoeda($totalBaixa)) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="crb-actions">
                    <button type="submit" class="crb-btn">Confirmar baixa</button>
                    <a href="contas_receber.php" class="crb-btn light">Cancelar</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($titulosAgrupamento): ?>
        <?php
            $totalAgrupamento = 0.0;
            $clientesAgrupamento = [];
            $vencimentosAgrupamento = [];
            foreach ($titulosAgrupamento as $tituloAgrupar) {
                $valorAgrupar = (float)($tituloAgrupar['VLRRESTANTE'] ?? 0);
                if ($valorAgrupar <= 0) {
                    $valorAgrupar = (float)($tituloAgrupar['VLRPARCELA'] ?? 0);
                }
                $totalAgrupamento += $valorAgrupar;
                $clientesAgrupamento[(int)($tituloAgrupar['CLICONTADOR'] ?? 0)] = true;
                if (!empty($tituloAgrupar['DTVENC'])) {
                    $vencimentosAgrupamento[] = date('Y-m-d', strtotime($tituloAgrupar['DTVENC']));
                }
            }
            sort($vencimentosAgrupamento);
            $novoVencimentoPadrao = $_POST['novo_vencimento'] ?? ($vencimentosAgrupamento[0] ?? date('Y-m-d'));
        ?>
        <div class="crb-card" id="agrupamento">
            <h2 class="crb-title">Agrupar titulos selecionados</h2>
            <?php if (count($clientesAgrupamento) !== 1): ?>
                <div class="crb-alert err">Para agrupar, todos os titulos devem ser do mesmo cliente.</div>
            <?php endif; ?>
            <form method="post" autocomplete="off" onsubmit="return confirm('Confirmar agrupamento dos titulos selecionados?');">
                <input type="hidden" name="acao" value="confirmar_agrupamento">
                <?php foreach ($titulosAgrupamento as $tituloAgrupar): ?>
                    <input type="hidden" name="crcontadores[]" value="<?= (int)$tituloAgrupar['CRCONTADOR'] ?>">
                <?php endforeach; ?>

                <div class="crb-grid">
                    <div class="crb-field w3">
                        <label for="novo_vencimento">Vencimento do novo titulo</label>
                        <input type="date" id="novo_vencimento" name="novo_vencimento" value="<?= crbH($novoVencimentoPadrao) ?>" required>
                    </div>
                    <div class="crb-field w4">
                        <label for="novo_titulo">Documento/Titulo do agrupamento</label>
                        <input type="text" id="novo_titulo" name="novo_titulo" value="<?= crbH($_POST['novo_titulo'] ?? ('AGRUPAMENTO ' . date('d/m/Y'))) ?>" required>
                    </div>
                    <div class="crb-field w5">
                        <label for="tipoes_agrupamento">TIPOES do novo titulo</label>
                        <select id="tipoes_agrupamento" name="tipoes_agrupamento" required>
                            <option value="">Selecione</option>
                            <?php foreach ($tipos as $tipo): ?>
                                <option value="<?= (int)$tipo['ESCONTADOR'] ?>" <?= (string)($_POST['tipoes_agrupamento'] ?? ($titulosAgrupamento[0]['TIPOES'] ?? '')) === (string)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                                    <?= crbH(($tipo['DESCES'] ?? '') . ' (' . $tipo['ESCONTADOR'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="crb-field w12">
                        <label for="observacao_agrupamento">Observacao</label>
                        <input type="text" id="observacao_agrupamento" name="observacao_agrupamento" value="<?= crbH($_POST['observacao_agrupamento'] ?? '') ?>">
                    </div>
                </div>

                <div class="crb-table-wrap" style="margin-top:14px;">
                    <table class="crb-table">
                        <thead>
                            <tr>
                                <th>CR origem</th>
                                <th>Vencimento</th>
                                <th>Cliente</th>
                                <th>Documento</th>
                                <th>Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($titulosAgrupamento as $tituloAgrupar): ?>
                                <?php
                                    $valorAgrupar = (float)($tituloAgrupar['VLRRESTANTE'] ?? 0);
                                    if ($valorAgrupar <= 0) {
                                        $valorAgrupar = (float)($tituloAgrupar['VLRPARCELA'] ?? 0);
                                    }
                                ?>
                                <tr>
                                    <td><?= (int)$tituloAgrupar['CRCONTADOR'] ?></td>
                                    <td><?= crbH(crbData($tituloAgrupar['DTVENC'])) ?></td>
                                    <td><?= crbH(($tituloAgrupar['CLICONTADOR'] ?? '') . ' - ' . ($tituloAgrupar['cliente_nome'] ?? '')) ?></td>
                                    <td><?= crbH($tituloAgrupar['TITULO'] ?? '') ?></td>
                                    <td><?= crbH(crbMoeda($valorAgrupar)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="4" style="text-align:right;font-weight:700;">Novo titulo</td>
                                <td style="font-weight:700;"><?= crbH(crbMoeda($totalAgrupamento)) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="crb-actions">
                    <button type="submit" class="crb-btn" <?= count($clientesAgrupamento) !== 1 ? 'disabled' : '' ?>>Confirmar agrupamento</button>
                    <a href="contas_receber.php" class="crb-btn light">Cancelar</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="crb-card">
        <h2 class="crb-title"><?= $tituloEdicao ? 'Editar titulo CR #' . (int)$tituloEdicao['CRCONTADOR'] : 'Novo titulo a receber' ?></h2>
        <form method="post" autocomplete="off">
            <input type="hidden" name="acao" value="salvar_titulo">
            <input type="hidden" name="crcontador_edicao" value="<?= crbH($form['crcontador_edicao']) ?>">

            <div class="crb-grid">
                <div class="crb-field w2">
                    <label for="dtvenda">Data venda</label>
                    <input type="date" id="dtvenda" name="dtvenda" value="<?= crbH($form['dtvenda']) ?>" required>
                </div>
                <div class="crb-field w2">
                    <label for="dtvenc">Vencimento</label>
                    <input type="date" id="dtvenc" name="dtvenc" value="<?= crbH($form['dtvenc']) ?>" required>
                </div>
                <div class="crb-field w4">
                    <label for="clicontador">
                        Cliente
                        <?php if ($empresaId === 2): ?>
                            <a href="clientes.php" class="crb-btn light" style="padding:3px 8px;font-size:12px;margin-left:8px;">Cadastrar</a>
                        <?php endif; ?>
                    </label>
                    <select id="clicontador" name="clicontador" required>
                        <option value="">Selecione</option>
                        <?php foreach ($clientes as $cliente): ?>
                            <option value="<?= (int)$cliente['CLICONTADOR'] ?>" <?= (string)$form['clicontador'] === (string)$cliente['CLICONTADOR'] ? 'selected' : '' ?>>
                                <?= crbH($cliente['nome'] . ' (' . $cliente['CLICONTADOR'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($empresaId === 2 && empty($clientes)): ?>
                        <small class="text-muted">Nenhum cliente cadastrado. Cadastre em Mov/Baixa &gt; Clientes.</small>
                    <?php endif; ?>
                </div>
                <div class="crb-field w4">
                    <label for="tipoes">TIPOES</label>
                    <select id="tipoes" name="tipoes" required>
                        <option value="">Selecione</option>
                        <?php foreach ($tipos as $tipo): ?>
                            <option value="<?= (int)$tipo['ESCONTADOR'] ?>" <?= (string)$form['tipoes'] === (string)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                                <?= crbH(($tipo['DESCES'] ?? '') . ' (' . $tipo['ESCONTADOR'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="crb-field w4">
                    <label for="titulo">Documento/Titulo</label>
                    <input type="text" id="titulo" name="titulo" value="<?= crbH($form['titulo']) ?>" required>
                </div>
                <div class="crb-field w3">
                    <label for="notafiscal">Nota fiscal</label>
                    <input type="text" id="notafiscal" name="notafiscal" value="<?= crbH($form['notafiscal']) ?>">
                </div>
                <?php if ($tituloEdicao): ?>
                    <div class="crb-field w2">
                        <label for="numparcela">N. parcela</label>
                        <input type="number" id="numparcela" name="numparcela" min="1" value="<?= crbH($form['numparcela']) ?>" readonly>
                    </div>
                    <div class="crb-field w2">
                        <label for="parcela">Parcela</label>
                        <input type="text" id="parcela" name="parcela" value="<?= crbH($form['parcela']) ?>" readonly>
                    </div>
                    <input type="hidden" name="qtd_parcelas" value="1">
                    <input type="hidden" name="vencimento_modo" value="30dias">
                <?php else: ?>
                    <div class="crb-field w2">
                        <label for="qtd_parcelas">N. parcelas</label>
                        <input type="number" id="qtd_parcelas" name="qtd_parcelas" min="1" max="120" value="<?= crbH($form['qtd_parcelas']) ?>" required>
                    </div>
                    <div class="crb-field w3" id="box_modo_vencimento">
                        <label for="vencimento_modo">Proximos vencimentos</label>
                        <select id="vencimento_modo" name="vencimento_modo">
                            <option value="fixo" <?= $form['vencimento_modo'] === 'fixo' ? 'selected' : '' ?>>Dia fixo de vencimento</option>
                            <option value="7dias" <?= $form['vencimento_modo'] === '7dias' ? 'selected' : '' ?>>Acrescentar 7 dias</option>
                            <option value="10dias" <?= $form['vencimento_modo'] === '10dias' ? 'selected' : '' ?>>Acrescentar 10 dias</option>
                            <option value="15dias" <?= $form['vencimento_modo'] === '15dias' ? 'selected' : '' ?>>Acrescentar 15 dias</option>
                            <option value="30dias" <?= $form['vencimento_modo'] === '30dias' ? 'selected' : '' ?>>Acrescentar 30 dias</option>
                        </select>
                    </div>
                    <div class="crb-field w2" id="box_dia_fixo">
                        <label for="dia_fixo">Dia fixo</label>
                        <input type="number" id="dia_fixo" name="dia_fixo" min="1" max="31" value="<?= crbH($form['dia_fixo']) ?>">
                    </div>
                <?php endif; ?>
                <div class="crb-field w3">
                    <label for="valor"><?= $tituloEdicao ? 'Valor da parcela' : 'Valor da venda' ?></label>
                    <input type="text" id="valor" name="valor" inputmode="decimal" value="<?= crbH($form['valor']) ?>" required>
                </div>
                <div class="crb-field w12">
                    <label for="observacao">Observacao</label>
                    <textarea id="observacao" name="observacao"><?= crbH($form['observacao']) ?></textarea>
                </div>
            </div>

            <div class="crb-actions">
                <button type="submit" class="crb-btn"><?= $tituloEdicao ? 'Salvar edicao' : 'Salvar titulo' ?></button>
                <?php if ($tituloEdicao): ?>
                    <a href="contas_receber.php" class="crb-btn secondary">Novo titulo</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="crb-card">
        <h2 class="crb-title">Filtros</h2>
        <form method="get" class="crb-grid" autocomplete="off">
            <div class="crb-field w3">
                <label for="cliente">Cliente</label>
                <input type="text" id="cliente" name="cliente" value="<?= crbH($fCliente) ?>">
            </div>
            <div class="crb-field w3">
                <label for="documento">Documento</label>
                <input type="text" id="documento" name="documento" value="<?= crbH($fDocumento) ?>">
            </div>
            <div class="crb-field w2">
                <label for="valor_min">Valor inicial</label>
                <input type="text" id="valor_min" name="valor_min" inputmode="decimal" value="<?= crbH($fValorMin) ?>" placeholder="0,00">
            </div>
            <div class="crb-field w2">
                <label for="valor_max">Valor final</label>
                <input type="text" id="valor_max" name="valor_max" inputmode="decimal" value="<?= crbH($fValorMax) ?>" placeholder="0,00">
            </div>
            <div class="crb-field w2">
                <label for="usar_data">Filtro de data</label>
                <select id="usar_data" name="usar_data">
                    <option value="" <?= !$fUsarData ? 'selected' : '' ?>>Nao usar datas</option>
                    <option value="S" <?= $fUsarData ? 'selected' : '' ?>>Usar datas</option>
                </select>
            </div>
            <div class="crb-field w2">
                <label for="venda_ini">Venda inicial</label>
                <input type="date" id="venda_ini" name="venda_ini" value="<?= crbH($fVendaIni) ?>">
            </div>
            <div class="crb-field w2">
                <label for="venda_fim">Venda final</label>
                <input type="date" id="venda_fim" name="venda_fim" value="<?= crbH($fVendaFim) ?>">
            </div>
            <div class="crb-field w2">
                <label for="venc_ini">Venc. inicial</label>
                <input type="date" id="venc_ini" name="venc_ini" value="<?= crbH($fVencIni) ?>">
            </div>
            <div class="crb-field w2">
                <label for="venc_fim">Venc. final</label>
                <input type="date" id="venc_fim" name="venc_fim" value="<?= crbH($fVencFim) ?>">
            </div>
            <div class="crb-field w2">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Todos</option>
                    <option value="AB" <?= $fStatus === 'AB' ? 'selected' : '' ?>>Aberto</option>
                    <option value="QT" <?= $fStatus === 'QT' ? 'selected' : '' ?>>Quitado</option>
                </select>
            </div>
            <div class="crb-field w3">
                <label for="origem">Origem</label>
                <select id="origem" name="origem">
                    <option value="todos" <?= $fOrigem === 'todos' ? 'selected' : '' ?>>Todos CR001</option>
                    <option value="movimentacao_baixa" <?= $fOrigem === 'movimentacao_baixa' ? 'selected' : '' ?>>Mov/Baixa</option>
                    <option value="superdunga" <?= $fOrigem === 'superdunga' ? 'selected' : '' ?>>SuperDunga</option>
                    <option value="firebird" <?= $fOrigem === 'firebird' ? 'selected' : '' ?>>Firebird</option>
                </select>
            </div>
            <div class="crb-field w3">
                <label for="tipoes_filtro">TIPOES</label>
                <select id="tipoes_filtro" name="tipoes">
                    <option value="">Todos</option>
                    <?php foreach ($tipos as $tipo): ?>
                        <option value="<?= (int)$tipo['ESCONTADOR'] ?>" <?= (string)$fTipoes === (string)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                            <?= crbH(($tipo['DESCES'] ?? '') . ' (' . $tipo['ESCONTADOR'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="crb-field w12">
                <div class="crb-actions">
                    <button class="crb-btn" type="submit">Filtrar</button>
                    <a class="crb-btn light" href="contas_receber.php">Limpar</a>
                </div>
            </div>
        </form>
    </div>

    <div class="crb-card">
        <h2 class="crb-title">Titulos lancados</h2>
        <form method="post" autocomplete="off" id="form-titulos-selecionados"></form>
        <div class="crb-summary">
                <div class="crb-summary-item">
                    <div class="crb-summary-label">Total valor</div>
                    <div class="crb-summary-value"><?= crbH(crbMoeda($totalValorLista)) ?></div>
                </div>
                <div class="crb-summary-item">
                    <div class="crb-summary-label">Total restante</div>
                    <div class="crb-summary-value"><?= crbH(crbMoeda($totalRestanteLista)) ?></div>
                </div>
                <div class="crb-summary-item">
                    <div class="crb-summary-label">Total selecionado</div>
                    <div class="crb-summary-value" id="totalSelecionado">R$ 0,00</div>
                </div>
            </div>
            <div class="crb-actions" style="margin-top:0;margin-bottom:12px;">
                <button type="submit" name="acao" value="preparar_baixa" form="form-titulos-selecionados" class="crb-btn">Baixar titulos selecionados</button>
                <button type="submit" name="acao" value="preparar_agrupamento" form="form-titulos-selecionados" class="crb-btn secondary">Agrupar selecionados</button>
            </div>
            <div class="crb-table-wrap">
                <table class="crb-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>CR</th>
                            <th>Venda</th>
                            <th>Vencimento</th>
                            <th>Cliente</th>
                            <th>Documento</th>
                            <th>TIPOES</th>
                            <th>Origem</th>
                            <th>Valor</th>
                            <th>Restante</th>
                            <th>Status</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$titulos): ?>
                            <tr>
                                <td colspan="12" style="text-align:center;color:#64748b;">Nenhum titulo encontrado.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($titulos as $titulo): ?>
                            <?php $statusLinha = (string)($titulo['STATUS'] ?? ''); ?>
                            <?php
                                $crIdLinha = (int)$titulo['CRCONTADOR'];
                                $baixasLinha = $baixasPorTitulo[$crIdLinha] ?? [];
                                $agrupadosLinha = $agrupadosPorTitulo[$crIdLinha] ?? [];
                            ?>
                            <tr>
                                <td>
                                    <?php if ($statusLinha !== 'QT'): ?>
                                        <input
                                            type="checkbox"
                                            name="crcontadores[]"
                                            value="<?= (int)$titulo['CRCONTADOR'] ?>"
                                            data-restante="<?= crbH((float)($titulo['VLRRESTANTE'] ?? 0)) ?>"
                                            form="form-titulos-selecionados"
                                        >
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)$titulo['CRCONTADOR'] ?></td>
                                <td><?= crbH(crbData($titulo['DTVENDA'])) ?></td>
                                <td><?= crbH(crbData($titulo['DTVENC'])) ?></td>
                                <td><?= crbH(($titulo['CLICONTADOR'] ?? '') . ' - ' . ($titulo['cliente_nome'] ?? '')) ?></td>
                                <td>
                                    <strong><?= crbH($titulo['TITULO'] ?? '') ?></strong>
                                    <?php if (!empty($titulo['NOTAFISCAL'])): ?>
                                        <div class="text-muted small">NF <?= crbH($titulo['NOTAFISCAL']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= crbH(($titulo['TIPOES'] ?? '') . ' - ' . ($titulo['tipoes_desc'] ?? '')) ?></td>
                                <td><?= crbH($titulo['origem_titulo'] ?? '') ?></td>
                                <td><?= crbH(crbMoeda($titulo['VLRPARCELA'])) ?></td>
                                <td><?= crbH(crbMoeda($titulo['VLRRESTANTE'])) ?></td>
                                <td>
                                    <span class="crb-badge <?= $statusLinha === 'QT' ? 'paid' : 'open' ?>">
                                        <?= crbH($statusLinha ?: 'SEM STATUS') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($statusLinha === 'QT'): ?>
                                        <button type="button" class="crb-btn light crb-toggle-baixa" data-target="detalhe-baixa-<?= $crIdLinha ?>">
                                            Baixa
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($agrupadosLinha): ?>
                                        <button type="button" class="crb-btn light crb-toggle-baixa" data-target="detalhe-agrupamento-<?= $crIdLinha ?>">
                                            Desdobramento
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($statusLinha !== 'QT' && ($titulo['TIPODOCORIGEM'] ?? '') === 'SUPERDUNGA' && ($titulo['CONTROLE'] ?? '') === 'MOVIMENTACAO_BAIXA'): ?>
                                        <a class="crb-btn light" href="contas_receber.php?editar=<?= (int)$titulo['CRCONTADOR'] ?>">Editar</a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Excluir este titulo aberto? Esta acao nao sera permitida se ele estiver vinculado a acerto.');">
                                            <input type="hidden" name="acao" value="excluir_titulo">
                                            <input type="hidden" name="crcontador" value="<?= (int)$titulo['CRCONTADOR'] ?>">
                                            <button type="submit" class="crb-btn secondary">Excluir</button>
                                        </form>
                                    <?php elseif ($statusLinha === 'QT'): ?>
                                        <span class="text-muted small">Quitado</span>
                                    <?php else: ?>
                                        <span class="text-muted small">Somente consulta</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($agrupadosLinha): ?>
                                <tr id="detalhe-agrupamento-<?= $crIdLinha ?>" class="crb-detalhe-baixa" style="display:none;">
                                    <td colspan="12">
                                        <div class="crb-table-wrap">
                                            <table class="crb-table" style="min-width:760px;">
                                                <thead>
                                                    <tr>
                                                        <th>CR origem</th>
                                                        <th>Vencimento</th>
                                                        <th>Documento</th>
                                                        <th>Valor</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php $totalAgrupadoLinha = 0.0; ?>
                                                    <?php foreach ($agrupadosLinha as $agrupado): ?>
                                                        <?php
                                                            $valorAgrupadoLinha = (float)($agrupado['VLRPAGO'] ?? 0);
                                                            if ($valorAgrupadoLinha <= 0) {
                                                                $valorAgrupadoLinha = (float)($agrupado['VLRPARCELA'] ?? 0);
                                                            }
                                                            $totalAgrupadoLinha += $valorAgrupadoLinha;
                                                        ?>
                                                        <tr>
                                                            <td><?= (int)$agrupado['CRCONTADOR'] ?></td>
                                                            <td><?= crbH(crbData($agrupado['DTVENC'])) ?></td>
                                                            <td><?= crbH($agrupado['TITULO'] ?? '') ?></td>
                                                            <td><?= crbH(crbMoeda($valorAgrupadoLinha)) ?></td>
                                                            <td><?= crbH($agrupado['STATUS'] ?? '') ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <tr>
                                                        <td colspan="3" style="text-align:right;font-weight:700;">Total agrupado</td>
                                                        <td style="font-weight:700;"><?= crbH(crbMoeda($totalAgrupadoLinha)) ?></td>
                                                        <td></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr id="detalhe-baixa-<?= $crIdLinha ?>" class="crb-detalhe-baixa" style="display:none;">
                                <td colspan="12">
                                    <?php if ($baixasLinha): ?>
                                        <div class="crb-table-wrap">
                                            <table class="crb-table" style="min-width:820px;">
                                                <thead>
                                                    <tr>
                                                        <th>Mov.</th>
                                                        <th>Data</th>
                                                        <th>Conta</th>
                                                        <th>TIPOES</th>
                                                        <th>D/C</th>
                                                        <th>Historico</th>
                                                        <th>Valor</th>
                                                        <th>Acerto</th>
                                                        <th>Acoes</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($baixasLinha as $baixa): ?>
                                                        <tr>
                                                            <td><?= (int)$baixa['MOVCONTADOR'] ?></td>
                                                            <td><?= crbH(crbData($baixa['DTMOV'])) ?></td>
                                                            <td><?= crbH(($baixa['CBCONTADOR'] ?? '') . ' - ' . ($baixa['conta_nome'] ?? '')) ?></td>
                                                            <td><?= crbH(($baixa['TIPOES'] ?? '') . ' - ' . ($baixa['tipoes_desc'] ?? '')) ?></td>
                                                            <td><?= crbH($baixa['TIPOMOV'] ?? '') ?></td>
                                                            <td><?= crbH($baixa['HISTMOV'] ?? '') ?></td>
                                                            <td><?= crbH(crbMoeda($baixa['VALORMOV'])) ?></td>
                                                            <td>
                                                                <?php if (!empty($baixa['acerto_id'])): ?>
                                                                    Acerto #<?= (int)$baixa['acerto_id'] ?>
                                                                <?php else: ?>
                                                                    -
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if (empty($baixa['acerto_id'])): ?>
                                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Excluir esta baixa e reabrir o titulo?');">
                                                                        <input type="hidden" name="acao" value="excluir_baixa">
                                                                        <input type="hidden" name="movcontador" value="<?= (int)$baixa['MOVCONTADOR'] ?>">
                                                                        <button type="submit" class="crb-btn secondary">Excluir baixa</button>
                                                                    </form>
                                                                <?php else: ?>
                                                                    <span class="text-muted small">Bloqueada</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-muted small">Nenhuma baixa encontrada para este titulo.</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
    </div>
</div>

<script>
(function () {
    const qtd = document.getElementById('qtd_parcelas');
    const modo = document.getElementById('vencimento_modo');
    const boxModo = document.getElementById('box_modo_vencimento');
    const boxDia = document.getElementById('box_dia_fixo');
    const dia = document.getElementById('dia_fixo');

    if (!qtd || !modo || !boxModo || !boxDia) {
        return;
    }

    function atualizarParcelas() {
        const parcelas = parseInt(qtd.value || '1', 10);
        const mostrarOpcoes = parcelas > 1;
        boxModo.style.display = mostrarOpcoes ? '' : 'none';
        boxDia.style.display = mostrarOpcoes && modo.value === 'fixo' ? '' : 'none';
        if (dia) {
            dia.required = mostrarOpcoes && modo.value === 'fixo';
        }
    }

    qtd.addEventListener('input', atualizarParcelas);
    modo.addEventListener('change', atualizarParcelas);
    atualizarParcelas();
})();

(function () {
    const totalSelecionado = document.getElementById('totalSelecionado');
    const checks = Array.from(document.querySelectorAll('input[type="checkbox"][name="crcontadores[]"]'));

    function moeda(valor) {
        return valor.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function atualizarTotalSelecionado() {
        if (!totalSelecionado) {
            return;
        }

        const total = checks.reduce((soma, check) => {
            if (!check.checked) {
                return soma;
            }
            return soma + parseFloat(check.getAttribute('data-restante') || '0');
        }, 0);

        totalSelecionado.textContent = moeda(total);
    }

    checks.forEach(check => check.addEventListener('change', atualizarTotalSelecionado));
    atualizarTotalSelecionado();
})();

(function () {
    const usarData = document.getElementById('usar_data');
    const camposData = ['venda_ini', 'venda_fim', 'venc_ini', 'venc_fim']
        .map(id => document.getElementById(id))
        .filter(Boolean);

    if (!usarData) {
        return;
    }

    function atualizarCamposData() {
        const ativo = usarData.value === 'S';
        camposData.forEach(campo => {
            campo.disabled = !ativo;
            campo.style.background = ativo ? '#fff' : '#f1f5f9';
        });
    }

    usarData.addEventListener('change', atualizarCamposData);
    atualizarCamposData();
})();

(function () {
    document.querySelectorAll('.crb-toggle-baixa').forEach(function (botao) {
        botao.addEventListener('click', function () {
            const alvoId = botao.getAttribute('data-target');
            const alvo = document.getElementById(alvoId);
            if (!alvo) {
                return;
            }
            const aberto = alvo.style.display !== 'none';
            alvo.style.display = aberto ? 'none' : '';
            const labelFechado = alvoId.indexOf('agrupamento') !== -1 ? 'Desdobramento' : 'Baixa';
            botao.textContent = aberto ? labelFechado : 'Ocultar';
        });
    });
})();
</script>

<?php require '../../layout/footer.php'; ?>

