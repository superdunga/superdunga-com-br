<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

function cpbH($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function cpbFloat($valor)
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

function cpbMoeda($valor)
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function cpbData($valor)
{
    return $valor ? date('d/m/Y', strtotime($valor)) : '';
}

function cpbProximoCpcontador(PDO $pdo, $empresaId)
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CPCONTADOR), 0) + 1 FROM armazem_cp001 WHERE EMPRESA = ?");
    $stmt->execute([$empresaId]);
    return (int)$stmt->fetchColumn();
}

function cpbProximoMovcontador(PDO $pdo)
{
    $stmt = $pdo->query("SELECT COALESCE(MAX(MOVCONTADOR), 0) + 1 FROM armazem_bnc001");
    return (int)$stmt->fetchColumn();
}

function cpbInvertirTipomov($tipomov)
{
    return strtoupper((string)$tipomov) === 'D' ? 'C' : 'D';
}

function cpbSomarMesComDia(DateTime $dataBase, $meses, $diaFixo)
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

function cpbVencimentoParcela($primeiroVencimento, $indice, $modo, $diaFixo)
{
    $data = new DateTime($primeiroVencimento);

    if ($indice <= 1) {
        return $data->format('Y-m-d');
    }

    if ($modo === 'fixo') {
        $dia = $diaFixo > 0 ? $diaFixo : (int)$data->format('d');
        return cpbSomarMesComDia($data, $indice - 1, $dia)->format('Y-m-d');
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

function cpbBuscarFornecedor(PDO $pdo, $empresaId, $fcontador)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_cp003
        WHERE EMPRESA = ?
          AND FCONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(INATIVO, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $fcontador]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function cpbBuscarTipoes(PDO $pdo, $empresaId, $tipoes)
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

function cpbValidar(PDO $pdo, $empresaId, array $dados)
{
    $erros = [];
    $qtdParcelas = max(1, (int)($dados['qtd_parcelas'] ?? 1));

    if (empty($dados['dtcompra'])) {
        $erros[] = 'Informe a data da compra.';
    }
    if (empty($dados['dtvenc'])) {
        $erros[] = 'Informe a data de vencimento.';
    }
    if (empty($dados['fcontador']) || !cpbBuscarFornecedor($pdo, $empresaId, (int)$dados['fcontador'])) {
        $erros[] = 'Informe um fornecedor valido.';
    }
    if (empty($dados['tipoes']) || !cpbBuscarTipoes($pdo, $empresaId, (int)$dados['tipoes'])) {
        $erros[] = 'Informe um TIPOES valido.';
    }
    if (cpbFloat($dados['valor']) <= 0) {
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

function cpbSalvar(PDO $pdo, $empresaId, $usuarioId, array $dados, $cpcontadorEdicao = null)
{
    $erros = cpbValidar($pdo, $empresaId, $dados);
    if ($erros) {
        throw new RuntimeException(implode(' ', $erros));
    }

    $valor = cpbFloat($dados['valor']);
    $qtdParcelas = max(1, (int)($dados['qtd_parcelas'] ?? 1));
    $titulo = trim((string)$dados['titulo']);
    $observacao = trim((string)($dados['observacao'] ?? ''));
    $notaFiscal = trim((string)($dados['notafiscal'] ?? ''));
    $parcela = trim((string)($dados['parcela'] ?? '1/1'));
    $numParcela = (int)($dados['numparcela'] ?? 1);
    $numParcela = max(1, $numParcela);

    if ($cpcontadorEdicao) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM armazem_cp001
            WHERE EMPRESA = ?
              AND CPCONTADOR = ?
              AND TIPODOCORIGEM = 'SUPERDUNGA'
              AND CONTROLE = 'MOVIMENTACAO_BAIXA'
              AND COALESCE(excluido_firebird, 'N') <> 'S'
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $cpcontadorEdicao]);
        $atual = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$atual) {
            throw new RuntimeException('Titulo nao encontrado para edicao.');
        }

        if (($atual['STATUS'] ?? '') === 'QT') {
            throw new RuntimeException('Titulo quitado nao pode ser editado nesta tela.');
        }

        $stmt = $pdo->prepare("
            UPDATE armazem_cp001
            SET DTCOMPRA = ?,
                DTEMISSAO = ?,
                DTVENC = ?,
                FCONTADOR = ?,
                TIPOES = ?,
                TITULO = ?,
                NOTAFISCAL = ?,
                OBSERVACAO = ?,
                NUMPARCELA = ?,
                PARCELA = ?,
                VALORCOMPRA = ?,
                VLRPARCELA = ?,
                VLRRESTANTE = ?,
                USERALT = ?,
                DTALT = NOW(),
                REGSTAMP = NOW()
            WHERE EMPRESA = ?
              AND CPCONTADOR = ?
        ");
        $stmt->execute([
            $dados['dtcompra'],
            $dados['dtcompra'],
            $dados['dtvenc'],
            (int)$dados['fcontador'],
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
            $cpcontadorEdicao,
        ]);

        return (int)$cpcontadorEdicao;
    }

    $valorParcela = round($valor / $qtdParcelas, 2);
    $totalParcelas = [];
    for ($i = 1; $i <= $qtdParcelas; $i++) {
        $totalParcelas[$i] = $valorParcela;
    }
    $diferenca = round($valor - array_sum($totalParcelas), 2);
    $totalParcelas[$qtdParcelas] = round($totalParcelas[$qtdParcelas] + $diferenca, 2);

    $cpcontadores = [];
    $modoVencimento = $dados['vencimento_modo'] ?? '30dias';
    $diaFixo = (int)($dados['dia_fixo'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO armazem_cp001 (
            EMPRESA, CPCONTADOR, DTCOMPRA, NUMPARCELA, TITULO, VALORCOMPRA,
            FCONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
            VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
            TIPOCP, TIPOES, NOTAFISCAL, REGSTAMP, REGIMPORT, USERLANC, DTLANC,
            USERALT, DTALT, CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'AB', 'SUPERDUNGA', ?, 'MOVIMENTACAO_BAIXA',
            'CP', ?, ?, NOW(), 'S', ?, NOW(), ?, NOW(), ?, 'N', 'N'
        )
    ");

    for ($i = 1; $i <= $qtdParcelas; $i++) {
        $cpcontador = cpbProximoCpcontador($pdo, $empresaId);
        $cpcontadores[] = $cpcontador;
        $chave = 'MOVBAIXA-CP-' . $empresaId . '-' . $cpcontador;
        $parcelaAtual = $qtdParcelas === 1 ? '1/1' : $i . '/' . $qtdParcelas;
        $vencimentoParcela = cpbVencimentoParcela($dados['dtvenc'], $i, $modoVencimento, $diaFixo);
        $valorAtual = $totalParcelas[$i];

        $stmt->execute([
            $empresaId,
            $cpcontador,
            $dados['dtcompra'],
            $i,
            $titulo,
            $valor,
            (int)$dados['fcontador'],
            $observacao !== '' ? $observacao : null,
            $dados['dtcompra'],
            $valorAtual,
            $parcelaAtual,
            $vencimentoParcela,
            $valorAtual,
            $cpcontador,
            (int)$dados['tipoes'],
            $notaFiscal !== '' ? $notaFiscal : null,
            $usuarioId ?: null,
            $usuarioId ?: null,
            $chave,
        ]);
    }

    return $cpcontadores;
}

function cpbCarregarTitulosParaBaixa(PDO $pdo, $empresaId, array $cpcontadores)
{
    $cpcontadores = array_values(array_unique(array_filter(array_map('intval', $cpcontadores))));
    if (!$cpcontadores) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($cpcontadores), '?'));
    $stmt = $pdo->prepare("
        SELECT cp.*,
               COALESCE(NULLIF(f.APELIDO, ''), f.NOME, CONCAT('Fornecedor ', cp.FCONTADOR)) AS fornecedor_nome
        FROM armazem_cp001 cp
        LEFT JOIN armazem_cp003 f
          ON f.EMPRESA = cp.EMPRESA
         AND f.FCONTADOR = cp.FCONTADOR
        WHERE cp.EMPRESA = ?
          AND cp.CPCONTADOR IN ($placeholders)
          AND COALESCE(cp.excluido_firebird, 'N') <> 'S'
          AND COALESCE(cp.STATUS, 'AB') <> 'QT'
        ORDER BY cp.DTVENC ASC, cp.CPCONTADOR ASC
    ");
    $stmt->execute(array_merge([$empresaId], $cpcontadores));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cpbBuscarContaBaixa(PDO $pdo, $empresaId, $cbcontador)
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

function cpbBaixarTitulos(PDO $pdo, $empresaId, $usuarioId, array $dados)
{
    $cpcontadores = array_values(array_unique(array_filter(array_map('intval', (array)($dados['cpcontadores'] ?? [])))));
    if (!$cpcontadores) {
        throw new RuntimeException('Selecione ao menos um titulo para baixar.');
    }

    $dataBaixa = $dados['data_baixa'] ?? '';
    if (!$dataBaixa) {
        throw new RuntimeException('Informe a data da baixa.');
    }

    $cbcontador = (int)($dados['cbcontador_baixa'] ?? 0);
    if (!$cbcontador || !cpbBuscarContaBaixa($pdo, $empresaId, $cbcontador)) {
        throw new RuntimeException('Informe uma conta valida para baixa.');
    }

    $tipoesBaixa = (int)($dados['tipoes_baixa'] ?? 0);
    $titulos = cpbCarregarTitulosParaBaixa($pdo, $empresaId, $cpcontadores);
    if (!$titulos) {
        throw new RuntimeException('Nenhum titulo aberto encontrado para baixa.');
    }

    $pdo->beginTransaction();

    try {
        $baixados = [];

        foreach ($titulos as $titulo) {
            $tipoes = $tipoesBaixa > 0 ? $tipoesBaixa : (int)($titulo['TIPOES'] ?? 0);
            if ($tipoes <= 0) {
                throw new RuntimeException('O titulo CP #' . (int)$titulo['CPCONTADOR'] . ' nao possui TIPOES. Informe um TIPOES para a baixa.');
            }

            $tipo = cpbBuscarTipoes($pdo, $empresaId, $tipoes);
            if (!$tipo || empty($tipo['TIPOMOV'])) {
                throw new RuntimeException('O TIPOES ' . $tipoes . ' nao possui TIPOMOV configurado.');
            }

            $tipomovPrincipal = strtoupper((string)$tipo['TIPOMOV']);
            $contrapTipoes = !empty($tipo['CONTRAP_TIPOES']) ? (int)$tipo['CONTRAP_TIPOES'] : 0;
            $exigeContrap = $contrapTipoes > 0;
            $contrapCbcontador = $exigeContrap ? (int)($tipo['CONTRAP_CBCONTADOR'] ?? 0) : 0;
            $contrapTipomov = null;

            if ($exigeContrap) {
                if ($contrapCbcontador <= 0 || !cpbBuscarContaBaixa($pdo, $empresaId, $contrapCbcontador)) {
                    throw new RuntimeException('O TIPOES ' . $tipoes . ' exige contrapartida, mas nao possui conta de investimento/contrapartida valida.');
                }
                $tipoContrap = cpbBuscarTipoes($pdo, $empresaId, $contrapTipoes);
                if (!$tipoContrap) {
                    throw new RuntimeException('O TIPOES de contrapartida ' . $contrapTipoes . ' nao foi encontrado.');
                }
                $contrapTipomov = strtoupper((string)($tipo['CONTRAP_TIPOMOV'] ?: cpbInvertirTipomov($tipomovPrincipal)));
            }

            $valor = (float)($titulo['VLRRESTANTE'] ?? 0);
            if ($valor <= 0) {
                $valor = (float)($titulo['VLRPARCELA'] ?? 0);
            }
            if ($valor <= 0) {
                continue;
            }

            $movcontador = cpbProximoMovcontador($pdo);
            $documento = $titulo['TITULO'] ?: ($titulo['NOTAFISCAL'] ?: $titulo['CPCONTADOR']);
            $historico = trim('BAIXA CP ' . (int)$titulo['CPCONTADOR'] . ' - ' . ($titulo['fornecedor_nome'] ?? '') . ' - ' . $documento);

            $stmtBnc = $pdo->prepare("
                INSERT INTO armazem_bnc001 (
                    EMPRESA, MOVCONTADOR, DTMOV, NUMDOC, TIPOMOV, CBCONTADOR, TIPOES,
                    FCONTADOR, HISTMOV, VALORMOV, TIPODOCORIGEM, NUMDOCORIGEM, REGSTAMP,
                    USERBNCLANC, CONTRAPARTIDA, ORIGEMCPART, DTLANC, DTPROCESSADO, deletado
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'CP001', ?, NOW(),
                    ?, ?, 0, NOW(), NOW(), 'N'
                )
            ");
            $stmtBnc->execute([
                $empresaId,
                $movcontador,
                $dataBaixa,
                $documento,
                $tipomovPrincipal,
                $cbcontador,
                $tipoes,
                (int)$titulo['FCONTADOR'],
                $historico,
                $valor,
                (int)$titulo['CPCONTADOR'],
                $usuarioId ?: null,
                $exigeContrap ? 'S' : 'N',
            ]);

            if ($exigeContrap) {
                $movcontadorContrap = cpbProximoMovcontador($pdo);
                $stmtBnc->execute([
                    $empresaId,
                    $movcontadorContrap,
                    $dataBaixa,
                    $documento,
                    $contrapTipomov,
                    $contrapCbcontador,
                    $contrapTipoes,
                    (int)$titulo['FCONTADOR'],
                    'CONTRAPARTIDA - ' . $historico,
                    $valor,
                    (int)$titulo['CPCONTADOR'],
                    $usuarioId ?: null,
                    'N',
                ]);

                $pdo->prepare("
                    UPDATE armazem_bnc001
                    SET ORIGEMCPART = ?
                    WHERE EMPRESA = ?
                      AND MOVCONTADOR = ?
                ")->execute([$movcontador, $empresaId, $movcontadorContrap]);
            }

            $stmtCp = $pdo->prepare("
                UPDATE armazem_cp001
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
                  AND CPCONTADOR = ?
            ");
            $stmtCp->execute([
                $dataBaixa,
                $valor,
                $cbcontador,
                $tipoes,
                $usuarioId ?: null,
                $empresaId,
                (int)$titulo['CPCONTADOR'],
            ]);

            $baixados[] = (int)$titulo['CPCONTADOR'];
        }

        $pdo->commit();
        return $baixados;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function cpbAgruparTitulos(PDO $pdo, $empresaId, $usuarioId, array $dados)
{
    $cpcontadores = array_values(array_unique(array_filter(array_map('intval', (array)($dados['cpcontadores'] ?? [])))));
    if (count($cpcontadores) < 2) {
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
    if ($tipoes <= 0 || !cpbBuscarTipoes($pdo, $empresaId, $tipoes)) {
        throw new RuntimeException('Informe um TIPOES valido para o novo titulo.');
    }

    $titulos = cpbCarregarTitulosParaBaixa($pdo, $empresaId, $cpcontadores);
    if (count($titulos) !== count($cpcontadores)) {
        throw new RuntimeException('Um ou mais titulos selecionados nao estao abertos para agrupamento.');
    }

    $fornecedores = array_values(array_unique(array_map(static function ($titulo) {
        return (int)($titulo['FCONTADOR'] ?? 0);
    }, $titulos)));
    if (count($fornecedores) !== 1 || (int)$fornecedores[0] <= 0) {
        throw new RuntimeException('Para agrupar, todos os titulos devem ser do mesmo fornecedor.');
    }

    $total = 0.0;
    $origens = [];
    foreach ($titulos as $titulo) {
        if (($titulo['CONTROLE'] ?? '') === 'AGRUPADO_MOV_BAIXA') {
            throw new RuntimeException('O CP #' . (int)$titulo['CPCONTADOR'] . ' ja esta agrupado.');
        }
        $valor = (float)($titulo['VLRRESTANTE'] ?? 0);
        if ($valor <= 0) {
            $valor = (float)($titulo['VLRPARCELA'] ?? 0);
        }
        if ($valor <= 0) {
            throw new RuntimeException('O CP #' . (int)$titulo['CPCONTADOR'] . ' nao possui valor para agrupamento.');
        }
        $total += $valor;
        $origens[] = (int)$titulo['CPCONTADOR'];
    }
    $total = round($total, 2);

    $pdo->beginTransaction();
    try {
        $novoCpcontador = cpbProximoCpcontador($pdo, $empresaId);
        $chave = 'MOVBAIXA-CP-AGRUP-' . $empresaId . '-' . $novoCpcontador;
        $obsNovo = trim(($observacao !== '' ? $observacao . ' | ' : '') . 'Agrupa CP ' . implode(', CP ', $origens));

        $stmtNovo = $pdo->prepare("
            INSERT INTO armazem_cp001 (
                EMPRESA, CPCONTADOR, DTCOMPRA, NUMPARCELA, TITULO, VALORCOMPRA,
                FCONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
                VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
                TIPOCP, TIPOES, REGSTAMP, REGIMPORT, USERLANC, DTLANC, USERALT, DTALT,
                CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
            ) VALUES (
                ?, ?, CURDATE(), 1, ?, ?, ?, ?, CURDATE(), ?, '1/1', ?,
                ?, 0, 'AB', 'SUPERDUNGA', ?, 'AGRUPAMENTO_MOV_BAIXA',
                'CP', ?, NOW(), 'S', ?, NOW(), ?, NOW(), ?, 'N', 'N'
            )
        ");
        $stmtNovo->execute([
            $empresaId,
            $novoCpcontador,
            $novoTitulo,
            $total,
            (int)$fornecedores[0],
            $obsNovo,
            $total,
            $novoVencimento,
            $total,
            $novoCpcontador,
            $tipoes,
            $usuarioId ?: null,
            $usuarioId ?: null,
            $chave,
        ]);

        $stmtOrigem = $pdo->prepare("
            UPDATE armazem_cp001
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
              AND CPCONTADOR = ?
        ");
        foreach ($titulos as $titulo) {
            $stmtOrigem->execute([
                $novoCpcontador,
                'Agrupado no CP #' . $novoCpcontador,
                $usuarioId ?: null,
                $empresaId,
                (int)$titulo['CPCONTADOR'],
            ]);
        }

        $pdo->commit();
        return $novoCpcontador;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function cpbTituloVinculadoAcerto(PDO $pdo, $empresaId, $cpcontador)
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
          AND b.TIPODOCORIGEM = 'CP001'
          AND b.NUMDOCORIGEM = ?
          AND COALESCE(b.deletado, 'N') <> 'S'
    ");
    $stmt->execute([$empresaId, (string)(int)$cpcontador]);
    return (int)$stmt->fetchColumn() > 0;
}

function cpbMovimentosBaixaPorTitulo(PDO $pdo, $empresaId, array $cpcontadores)
{
    $cpcontadores = array_values(array_unique(array_filter(array_map('intval', $cpcontadores))));
    if (!$cpcontadores) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($cpcontadores), '?'));
    $stmt = $pdo->prepare("
        SELECT
            b.NUMDOCORIGEM AS cpcontador,
            b.MOVCONTADOR,
            b.DTMOV,
            b.CBCONTADOR,
            COALESCE(c.DESCABREV, c.TITULAR, CONCAT('Conta ', b.CBCONTADOR)) AS conta_nome,
            b.TIPOES,
            t.DESCES AS tipoes_desc,
            b.TIPOMOV,
            b.ORIGEMCPART,
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
          AND b.TIPODOCORIGEM = 'CP001'
          AND b.NUMDOCORIGEM IN ($placeholders)
          AND COALESCE(b.deletado, 'N') <> 'S'
        ORDER BY b.NUMDOCORIGEM, b.DTMOV, b.MOVCONTADOR
    ");
    $stmt->execute(array_merge([$empresaId], array_map('strval', $cpcontadores)));

    $porTitulo = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $movimento) {
        $porTitulo[(int)$movimento['cpcontador']][] = $movimento;
    }

    return $porTitulo;
}

function cpbExcluirTitulo(PDO $pdo, $empresaId, $usuarioId, $cpcontador)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_cp001
        WHERE EMPRESA = ?
          AND CPCONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $cpcontador]);
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

    if (cpbTituloVinculadoAcerto($pdo, $empresaId, $cpcontador)) {
        throw new RuntimeException('Titulo vinculado a acerto ativo nao pode ser excluido.');
    }

    $stmt = $pdo->prepare("
        UPDATE armazem_cp001
        SET excluido_firebird = 'S',
            data_exclusao_firebird = NOW(),
            motivo_sync = 'EXCLUIDO_MOVIMENTACAO_BAIXA',
            USERALT = ?,
            DTALT = NOW(),
            REGSTAMP = NOW()
        WHERE EMPRESA = ?
          AND CPCONTADOR = ?
    ");
    $stmt->execute([$usuarioId ?: null, $empresaId, $cpcontador]);
}

function cpbExcluirBaixaTitulo(PDO $pdo, $empresaId, $usuarioId, $movcontador)
{
    $stmt = $pdo->prepare("
        SELECT b.*, cp.CPCONTADOR, cp.VLRPARCELA
        FROM armazem_bnc001 b
        INNER JOIN armazem_cp001 cp
            ON cp.EMPRESA = b.EMPRESA
           AND cp.CPCONTADOR = CAST(b.NUMDOCORIGEM AS UNSIGNED)
        WHERE b.EMPRESA = ?
          AND b.MOVCONTADOR = ?
          AND b.TIPODOCORIGEM = 'CP001'
          AND COALESCE(b.deletado, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $movcontador]);
    $baixa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$baixa) {
        throw new RuntimeException('Baixa nao encontrada para exclusao.');
    }

    if ((int)($baixa['ORIGEMCPART'] ?? 0) > 0) {
        throw new RuntimeException('Exclua a baixa principal para desfazer tambem a contrapartida.');
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

    $stmtContrapAcerto = $pdo->prepare("
        SELECT COUNT(*)
        FROM armazem_bnc001 b
        INNER JOIN financeiro_acertos_extrato_itens ai
            ON ai.empresa_id = b.EMPRESA
           AND ai.movcontador = b.MOVCONTADOR
        INNER JOIN financeiro_acertos_extrato a
            ON a.id = ai.acerto_id
           AND a.status = 'ATIVO'
        WHERE b.EMPRESA = ?
          AND b.ORIGEMCPART = ?
          AND COALESCE(b.deletado, 'N') <> 'S'
    ");
    $stmtContrapAcerto->execute([$empresaId, $movcontador]);
    if ((int)$stmtContrapAcerto->fetchColumn() > 0) {
        throw new RuntimeException('Contrapartida vinculada a acerto ativo nao pode ser excluida.');
    }

    $cpcontador = (int)$baixa['CPCONTADOR'];
    $valorParcela = (float)($baixa['VLRPARCELA'] ?? 0);

    $pdo->beginTransaction();
    try {
        $stmtDelete = $pdo->prepare("
            UPDATE armazem_bnc001
            SET deletado = 'S',
                data_delecao_firebird = NOW(),
                motivo_sync = 'BAIXA_CP_EXCLUIDA_MOVIMENTACAO_BAIXA',
                USERBNCALT = ?,
                DTALT = NOW(),
                REGSTAMP = NOW()
            WHERE EMPRESA = ?
              AND MOVCONTADOR = ?
        ");
        $stmtDelete->execute([$usuarioId ?: null, $empresaId, $movcontador]);
        $pdo->prepare("
            UPDATE armazem_bnc001
            SET deletado = 'S',
                data_delecao_firebird = NOW(),
                motivo_sync = 'BAIXA_CP_CONTRAPARTIDA_EXCLUIDA_MOVIMENTACAO_BAIXA',
                USERBNCALT = ?,
                DTALT = NOW(),
                REGSTAMP = NOW()
            WHERE EMPRESA = ?
              AND ORIGEMCPART = ?
              AND COALESCE(deletado, 'N') <> 'S'
        ")->execute([$usuarioId ?: null, $empresaId, $movcontador]);

        $stmtCp = $pdo->prepare("
            UPDATE armazem_cp001
            SET STATUS = 'AB',
                DTPAGTO = NULL,
                VLRPAGO = 0,
                VLRRESTANTE = ?,
                CBCONTADOR = NULL,
                USERALT = ?,
                DTALT = NOW(),
                REGSTAMP = NOW()
            WHERE EMPRESA = ?
              AND CPCONTADOR = ?
        ");
        $stmtCp->execute([$valorParcela, $usuarioId ?: null, $empresaId, $cpcontador]);

        $pdo->commit();
        return [$cpcontador, $movcontador];
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
        $idsBaixaSelecionados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['cpcontadores'] ?? [])))));
        $titulosBaixa = cpbCarregarTitulosParaBaixa($pdo, $empresaId, $idsBaixaSelecionados);
        if (!$titulosBaixa) {
            $erro = 'Selecione ao menos um titulo aberto para baixa.';
        }
    } elseif ($acao === 'preparar_agrupamento') {
        $idsAgrupamentoSelecionados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['cpcontadores'] ?? [])))));
        $titulosAgrupamento = cpbCarregarTitulosParaBaixa($pdo, $empresaId, $idsAgrupamentoSelecionados);
        if (count($titulosAgrupamento) < 2) {
            $erro = 'Selecione ao menos dois titulos abertos para agrupar.';
        }
    } elseif ($acao === 'confirmar_baixa') {
        try {
            $baixados = cpbBaixarTitulos($pdo, $empresaId, $usuarioId, $_POST);
            $mensagem = count($baixados) . ' titulo(s) baixado(s): #' . implode(', #', $baixados) . '.';
        } catch (Throwable $e) {
            $erro = $e->getMessage();
            $idsBaixaSelecionados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['cpcontadores'] ?? [])))));
            $titulosBaixa = cpbCarregarTitulosParaBaixa($pdo, $empresaId, $idsBaixaSelecionados);
        }
    } elseif ($acao === 'confirmar_agrupamento') {
        try {
            $novoCp = cpbAgruparTitulos($pdo, $empresaId, $usuarioId, $_POST);
            $mensagem = 'Titulos agrupados no novo CP #' . (int)$novoCp . '.';
        } catch (Throwable $e) {
            $erro = $e->getMessage();
            $idsAgrupamentoSelecionados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['cpcontadores'] ?? [])))));
            $titulosAgrupamento = cpbCarregarTitulosParaBaixa($pdo, $empresaId, $idsAgrupamentoSelecionados);
        }
    } elseif ($acao === 'excluir_titulo') {
        try {
            $cpcontadorExcluir = (int)($_POST['cpcontador'] ?? 0);
            cpbExcluirTitulo($pdo, $empresaId, $usuarioId, $cpcontadorExcluir);
            $mensagem = 'Titulo CP #' . $cpcontadorExcluir . ' excluido com sucesso.';
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    } elseif ($acao === 'excluir_baixa') {
        try {
            $movcontadorExcluir = (int)($_POST['movcontador'] ?? 0);
            list($cpcontadorReaberto, $movcontadorExcluido) = cpbExcluirBaixaTitulo($pdo, $empresaId, $usuarioId, $movcontadorExcluir);
            $mensagem = 'Baixa MOV #' . $movcontadorExcluido . ' excluida e titulo CP #' . $cpcontadorReaberto . ' reaberto.';
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    } else {
        try {
            $cpcontadorEdicao = !empty($_POST['cpcontador_edicao']) ? (int)$_POST['cpcontador_edicao'] : null;
            $resultado = cpbSalvar($pdo, $empresaId, $usuarioId, $_POST, $cpcontadorEdicao);
            $mensagem = $cpcontadorEdicao
                ? 'Titulo CP #' . (int)$resultado . ' atualizado com sucesso.'
                : count($resultado) . ' titulo(s) gravado(s) com sucesso: #' . implode(', #', array_map('intval', $resultado)) . '.';
            $_GET['editar'] = null;
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    }
}

$stmtFornecedores = $pdo->prepare("
    SELECT FCONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Fornecedor ', FCONTADOR)) AS nome
    FROM armazem_cp003
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
      AND COALESCE(INATIVO, 'N') <> 'S'
    ORDER BY nome, FCONTADOR
");
$stmtFornecedores->execute([$empresaId]);
$fornecedores = $stmtFornecedores->fetchAll(PDO::FETCH_ASSOC);

$stmtTipoes = $pdo->prepare("
    SELECT ESCONTADOR, DESCES, TIPOMOV
    FROM armazem_bnc005
    WHERE EMPRESA = ?
      AND TIPOMOV = 'D'
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

$editarCpcontador = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$tituloEdicao = null;

if ($editarCpcontador > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_cp001
        WHERE EMPRESA = ?
          AND CPCONTADOR = ?
          AND TIPODOCORIGEM = 'SUPERDUNGA'
          AND CONTROLE = 'MOVIMENTACAO_BAIXA'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $editarCpcontador]);
    $tituloEdicao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tituloEdicao) {
        $erro = $erro ?: 'Titulo nao encontrado para edicao.';
    }
}

$form = [
    'cpcontador_edicao' => $tituloEdicao['CPCONTADOR'] ?? '',
    'dtcompra' => $tituloEdicao ? date('Y-m-d', strtotime($tituloEdicao['DTCOMPRA'])) : date('Y-m-d'),
    'dtvenc' => $tituloEdicao && !empty($tituloEdicao['DTVENC']) ? date('Y-m-d', strtotime($tituloEdicao['DTVENC'])) : date('Y-m-d'),
    'fcontador' => $tituloEdicao['FCONTADOR'] ?? '',
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

$fFornecedor = trim((string)($_GET['fornecedor'] ?? ''));
$fDocumento = trim((string)($_GET['documento'] ?? ''));
$fTipoes = trim((string)($_GET['tipoes'] ?? ''));
$fValorMin = trim((string)($_GET['valor_min'] ?? ''));
$fValorMax = trim((string)($_GET['valor_max'] ?? ''));
$fUsarData = ($_GET['usar_data'] ?? '') === 'S';
$fCompraIni = $_GET['compra_ini'] ?? '';
$fCompraFim = $_GET['compra_fim'] ?? '';
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
} elseif ($fOrigem === 'cartao') {
    $where[] = "cp.TIPODOCORIGEM = 'CARTAO'";
} elseif ($fOrigem === 'firebird') {
    $where[] = "(cp.TIPODOCORIGEM IS NULL OR cp.TIPODOCORIGEM NOT IN ('SUPERDUNGA', 'CARTAO'))";
}

if ($fFornecedor !== '') {
    $where[] = "(f.NOME LIKE ? OR f.APELIDO LIKE ? OR cp.FCONTADOR LIKE ?)";
    $like = '%' . $fFornecedor . '%';
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
    $params[] = cpbFloat($fValorMin);
}
if ($fValorMax !== '') {
    $where[] = "cp.VLRPARCELA <= ?";
    $params[] = cpbFloat($fValorMax);
}
if ($fUsarData && $fCompraIni !== '') {
    $where[] = "DATE(cp.DTCOMPRA) >= ?";
    $params[] = $fCompraIni;
}
if ($fUsarData && $fCompraFim !== '') {
    $where[] = "DATE(cp.DTCOMPRA) <= ?";
    $params[] = $fCompraFim;
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
           COALESCE(NULLIF(f.APELIDO, ''), f.NOME, CONCAT('Fornecedor ', cp.FCONTADOR)) AS fornecedor_nome,
           t.DESCES AS tipoes_desc,
           CASE
               WHEN cp.TIPODOCORIGEM = 'SUPERDUNGA' AND cp.CONTROLE = 'MOVIMENTACAO_BAIXA' THEN 'Mov/Baixa'
               WHEN cp.TIPODOCORIGEM = 'SUPERDUNGA' THEN 'SuperDunga'
               WHEN cp.TIPODOCORIGEM = 'CARTAO' THEN 'Cartao'
               ELSE 'Firebird'
           END AS origem_titulo
    FROM armazem_cp001 cp
    LEFT JOIN armazem_cp003 f
      ON f.EMPRESA = cp.EMPRESA
     AND f.FCONTADOR = cp.FCONTADOR
    LEFT JOIN armazem_bnc005 t
      ON t.EMPRESA = cp.EMPRESA
     AND t.ESCONTADOR = cp.TIPOES
    WHERE {$whereSql}
    ORDER BY cp.DTVENC DESC, cp.CPCONTADOR DESC
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
    return (int)$titulo['CPCONTADOR'];
}, $titulos);
$baixasPorTitulo = cpbMovimentosBaixaPorTitulo($pdo, $empresaId, $idsTitulosLista);
$agrupadosPorTitulo = [];
if ($idsTitulosLista) {
    $placeholdersAgrupados = implode(',', array_fill(0, count($idsTitulosLista), '?'));
    $stmtAgrupados = $pdo->prepare("
        SELECT *
        FROM armazem_cp001
        WHERE EMPRESA = ?
          AND CONTROLE = 'AGRUPADO_MOV_BAIXA'
          AND NUMDOCORIGEM IN ($placeholdersAgrupados)
        ORDER BY NUMDOCORIGEM, DTVENC, CPCONTADOR
    ");
    $stmtAgrupados->execute(array_merge([$empresaId], $idsTitulosLista));
    foreach ($stmtAgrupados as $agrupado) {
        $agrupadosPorTitulo[(int)$agrupado['NUMDOCORIGEM']][] = $agrupado;
    }
}

require '../../layout/header.php';
?>

<style>
    .cpb-wrap { max-width: 1180px; margin: 0 auto; padding: 18px; }
    .cpb-hero { background: #143b63; color: #fff; border-radius: 6px; padding: 22px; margin-bottom: 18px; display:flex; justify-content:space-between; gap:16px; align-items:center; }
    .cpb-hero h1 { margin:0 0 6px; font-size:1.55rem; }
    .cpb-card { background:#fff; border:1px solid #d8dee8; border-radius:6px; padding:16px; margin-bottom:16px; box-shadow:0 2px 10px rgba(15,23,42,.04); }
    .cpb-title { margin:0 0 14px; font-size:1.05rem; color:#1f2937; }
    .cpb-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:12px; }
    .cpb-field { grid-column:span 3; min-width:0; }
    .cpb-field.w2 { grid-column:span 2; }
    .cpb-field.w4 { grid-column:span 4; }
    .cpb-field.w6 { grid-column:span 6; }
    .cpb-field.w12 { grid-column:span 12; }
    .cpb-field label { display:block; margin-bottom:5px; font-weight:600; color:#334155; font-size:.88rem; }
    .cpb-field input, .cpb-field select, .cpb-field textarea { width:100%; border:1px solid #cbd5e1; border-radius:5px; padding:8px 9px; font-size:.95rem; background:#fff; }
    .cpb-field textarea { min-height:76px; resize:vertical; }
    .cpb-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:14px; }
    .cpb-btn { border:0; border-radius:5px; padding:9px 13px; background:#173b73; color:#fff; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; }
    .cpb-btn.secondary { background:#64748b; }
    .cpb-btn.light { background:#e2e8f0; color:#0f172a; }
    .cpb-alert { border-radius:5px; padding:11px 13px; margin-bottom:14px; }
    .cpb-alert.ok { background:#dcfce7; color:#166534; border:1px solid #86efac; }
    .cpb-alert.err { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
    .cpb-summary { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:10px; margin-bottom:12px; }
    .cpb-summary-item { border:1px solid #dbe3ef; background:#f8fafc; border-radius:6px; padding:10px 12px; }
    .cpb-summary-label { color:#64748b; font-size:.82rem; font-weight:700; text-transform:uppercase; }
    .cpb-summary-value { color:#0f172a; font-size:1.05rem; font-weight:800; margin-top:3px; }
    .cpb-table-wrap { overflow-x:auto; }
    .cpb-table { width:100%; border-collapse:collapse; min-width:1000px; }
    .cpb-table th, .cpb-table td { border-bottom:1px solid #e2e8f0; padding:9px 8px; text-align:left; vertical-align:top; font-size:.9rem; }
    .cpb-table th { background:#12336b; color:#fff; font-size:.82rem; text-transform:uppercase; }
    .cpb-badge { display:inline-block; border-radius:999px; padding:3px 8px; font-size:.78rem; font-weight:700; background:#e2e8f0; color:#0f172a; }
    .cpb-badge.open { background:#fff7ed; color:#9a3412; }
    .cpb-badge.paid { background:#dcfce7; color:#166534; }
    @media (max-width: 820px) {
        .cpb-wrap { padding:12px; }
        .cpb-hero { display:block; padding:18px; }
        .cpb-grid { grid-template-columns:1fr; }
        .cpb-summary { grid-template-columns:1fr; }
        .cpb-field, .cpb-field.w2, .cpb-field.w4, .cpb-field.w6, .cpb-field.w12 { grid-column:span 1; }
        .cpb-actions .cpb-btn { width:100%; }
    }
</style>

<div class="cpb-wrap">
    <div class="cpb-hero">
        <div>
            <h1>Contas a Pagar</h1>
            <div>Lancamento direto de titulos em CP001 para baixa posterior.</div>
        </div>
        <a class="cpb-btn light" href="menu_movimentacao_baixa.php">Voltar</a>
    </div>

    <?php if ($mensagem): ?>
        <div class="cpb-alert ok"><?= cpbH($mensagem) ?></div>
    <?php endif; ?>
    <?php if ($erro): ?>
        <div class="cpb-alert err"><?= cpbH($erro) ?></div>
    <?php endif; ?>

    <?php if ($titulosBaixa): ?>
        <div class="cpb-card" id="baixa">
            <h2 class="cpb-title">Confirmar baixa dos titulos selecionados</h2>
            <form method="post" autocomplete="off" onsubmit="return confirm('Confirmar baixa dos titulos selecionados?');">
                <input type="hidden" name="acao" value="confirmar_baixa">
                <?php foreach ($titulosBaixa as $tituloBaixa): ?>
                    <input type="hidden" name="cpcontadores[]" value="<?= (int)$tituloBaixa['CPCONTADOR'] ?>">
                <?php endforeach; ?>

                <div class="cpb-grid">
                    <div class="cpb-field w3">
                        <label for="data_baixa">Data da baixa</label>
                        <input type="date" id="data_baixa" name="data_baixa" value="<?= cpbH($_POST['data_baixa'] ?? date('Y-m-d')) ?>" required>
                    </div>
                    <div class="cpb-field w5">
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
                                    <?= cpbH($nomeConta) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cpb-field w4">
                        <label for="tipoes_baixa">TIPOES da baixa</label>
                        <select id="tipoes_baixa" name="tipoes_baixa">
                            <option value="">Manter TIPOES de cada titulo</option>
                            <?php foreach ($tipos as $tipo): ?>
                                <option value="<?= (int)$tipo['ESCONTADOR'] ?>" <?= (string)($_POST['tipoes_baixa'] ?? '') === (string)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                                    <?= cpbH(($tipo['DESCES'] ?? '') . ' (' . $tipo['ESCONTADOR'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="cpb-table-wrap" style="margin-top:14px;">
                    <table class="cpb-table">
                        <thead>
                            <tr>
                                <th>CP</th>
                                <th>Vencimento</th>
                                <th>Fornecedor</th>
                                <th>Documento</th>
                                <th>TIPOES atual</th>
                                <th>Valor restante</th>
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
                                    $totalBaixa += $valorBaixa;
                                ?>
                                <tr>
                                    <td><?= (int)$tituloBaixa['CPCONTADOR'] ?></td>
                                    <td><?= cpbH(cpbData($tituloBaixa['DTVENC'])) ?></td>
                                    <td><?= cpbH(($tituloBaixa['FCONTADOR'] ?? '') . ' - ' . ($tituloBaixa['fornecedor_nome'] ?? '')) ?></td>
                                    <td><?= cpbH($tituloBaixa['TITULO'] ?? '') ?></td>
                                    <td><?= cpbH($tituloBaixa['TIPOES'] ?? '') ?></td>
                                    <td><?= cpbH(cpbMoeda($valorBaixa)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="5" style="text-align:right;font-weight:700;">Total</td>
                                <td style="font-weight:700;"><?= cpbH(cpbMoeda($totalBaixa)) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="cpb-actions">
                    <button type="submit" class="cpb-btn">Confirmar baixa</button>
                    <a href="contas_pagar.php" class="cpb-btn light">Cancelar</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($titulosAgrupamento): ?>
        <?php
            $totalAgrupamento = 0.0;
            $fornecedoresAgrupamento = [];
            $vencimentosAgrupamento = [];
            foreach ($titulosAgrupamento as $tituloAgrupar) {
                $valorAgrupar = (float)($tituloAgrupar['VLRRESTANTE'] ?? 0);
                if ($valorAgrupar <= 0) {
                    $valorAgrupar = (float)($tituloAgrupar['VLRPARCELA'] ?? 0);
                }
                $totalAgrupamento += $valorAgrupar;
                $fornecedoresAgrupamento[(int)($tituloAgrupar['FCONTADOR'] ?? 0)] = true;
                if (!empty($tituloAgrupar['DTVENC'])) {
                    $vencimentosAgrupamento[] = date('Y-m-d', strtotime($tituloAgrupar['DTVENC']));
                }
            }
            sort($vencimentosAgrupamento);
            $novoVencimentoPadrao = $_POST['novo_vencimento'] ?? ($vencimentosAgrupamento[0] ?? date('Y-m-d'));
        ?>
        <div class="cpb-card" id="agrupamento">
            <h2 class="cpb-title">Agrupar titulos selecionados</h2>
            <?php if (count($fornecedoresAgrupamento) !== 1): ?>
                <div class="cpb-alert err">Para agrupar, todos os titulos devem ser do mesmo fornecedor.</div>
            <?php endif; ?>
            <form method="post" autocomplete="off" onsubmit="return confirm('Confirmar agrupamento dos titulos selecionados?');">
                <input type="hidden" name="acao" value="confirmar_agrupamento">
                <?php foreach ($titulosAgrupamento as $tituloAgrupar): ?>
                    <input type="hidden" name="cpcontadores[]" value="<?= (int)$tituloAgrupar['CPCONTADOR'] ?>">
                <?php endforeach; ?>

                <div class="cpb-grid">
                    <div class="cpb-field w3">
                        <label for="novo_vencimento">Vencimento do novo titulo</label>
                        <input type="date" id="novo_vencimento" name="novo_vencimento" value="<?= cpbH($novoVencimentoPadrao) ?>" required>
                    </div>
                    <div class="cpb-field w4">
                        <label for="novo_titulo">Documento/Titulo do agrupamento</label>
                        <input type="text" id="novo_titulo" name="novo_titulo" value="<?= cpbH($_POST['novo_titulo'] ?? ('AGRUPAMENTO ' . date('d/m/Y'))) ?>" required>
                    </div>
                    <div class="cpb-field w5">
                        <label for="tipoes_agrupamento">TIPOES do novo titulo</label>
                        <select id="tipoes_agrupamento" name="tipoes_agrupamento" required>
                            <option value="">Selecione</option>
                            <?php foreach ($tipos as $tipo): ?>
                                <option value="<?= (int)$tipo['ESCONTADOR'] ?>" <?= (string)($_POST['tipoes_agrupamento'] ?? ($titulosAgrupamento[0]['TIPOES'] ?? '')) === (string)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                                    <?= cpbH(($tipo['DESCES'] ?? '') . ' (' . $tipo['ESCONTADOR'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cpb-field w12">
                        <label for="observacao_agrupamento">Observacao</label>
                        <input type="text" id="observacao_agrupamento" name="observacao_agrupamento" value="<?= cpbH($_POST['observacao_agrupamento'] ?? '') ?>">
                    </div>
                </div>

                <div class="cpb-table-wrap" style="margin-top:14px;">
                    <table class="cpb-table">
                        <thead>
                            <tr>
                                <th>CP origem</th>
                                <th>Vencimento</th>
                                <th>Fornecedor</th>
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
                                    <td><?= (int)$tituloAgrupar['CPCONTADOR'] ?></td>
                                    <td><?= cpbH(cpbData($tituloAgrupar['DTVENC'])) ?></td>
                                    <td><?= cpbH(($tituloAgrupar['FCONTADOR'] ?? '') . ' - ' . ($tituloAgrupar['fornecedor_nome'] ?? '')) ?></td>
                                    <td><?= cpbH($tituloAgrupar['TITULO'] ?? '') ?></td>
                                    <td><?= cpbH(cpbMoeda($valorAgrupar)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="4" style="text-align:right;font-weight:700;">Novo titulo</td>
                                <td style="font-weight:700;"><?= cpbH(cpbMoeda($totalAgrupamento)) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="cpb-actions">
                    <button type="submit" class="cpb-btn" <?= count($fornecedoresAgrupamento) !== 1 ? 'disabled' : '' ?>>Confirmar agrupamento</button>
                    <a href="contas_pagar.php" class="cpb-btn light">Cancelar</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="cpb-card">
        <h2 class="cpb-title"><?= $tituloEdicao ? 'Editar titulo CP #' . (int)$tituloEdicao['CPCONTADOR'] : 'Novo titulo a pagar' ?></h2>
        <form method="post" autocomplete="off">
            <input type="hidden" name="acao" value="salvar_titulo">
            <input type="hidden" name="cpcontador_edicao" value="<?= cpbH($form['cpcontador_edicao']) ?>">

            <div class="cpb-grid">
                <div class="cpb-field w2">
                    <label for="dtcompra">Data compra</label>
                    <input type="date" id="dtcompra" name="dtcompra" value="<?= cpbH($form['dtcompra']) ?>" required>
                </div>
                <div class="cpb-field w2">
                    <label for="dtvenc">Vencimento</label>
                    <input type="date" id="dtvenc" name="dtvenc" value="<?= cpbH($form['dtvenc']) ?>" required>
                </div>
                <div class="cpb-field w4">
                    <label for="fcontador">
                        Fornecedor
                        <?php if ($empresaId === 2): ?>
                            <a href="fornecedores.php" class="cpb-btn light" style="padding:3px 8px;font-size:12px;margin-left:8px;">Cadastrar</a>
                        <?php endif; ?>
                    </label>
                    <select id="fcontador" name="fcontador" required>
                        <option value="">Selecione</option>
                        <?php foreach ($fornecedores as $fornecedor): ?>
                            <option value="<?= (int)$fornecedor['FCONTADOR'] ?>" <?= (string)$form['fcontador'] === (string)$fornecedor['FCONTADOR'] ? 'selected' : '' ?>>
                                <?= cpbH($fornecedor['nome'] . ' (' . $fornecedor['FCONTADOR'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($empresaId === 2 && empty($fornecedores)): ?>
                        <small class="text-muted">Nenhum fornecedor cadastrado. Cadastre em Mov/Baixa &gt; Fornecedores.</small>
                    <?php endif; ?>
                </div>
                <div class="cpb-field w4">
                    <label for="tipoes">TIPOES</label>
                    <select id="tipoes" name="tipoes" required>
                        <option value="">Selecione</option>
                        <?php foreach ($tipos as $tipo): ?>
                            <option value="<?= (int)$tipo['ESCONTADOR'] ?>" <?= (string)$form['tipoes'] === (string)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                                <?= cpbH(($tipo['DESCES'] ?? '') . ' (' . $tipo['ESCONTADOR'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="cpb-field w4">
                    <label for="titulo">Documento/Titulo</label>
                    <input type="text" id="titulo" name="titulo" value="<?= cpbH($form['titulo']) ?>" required>
                </div>
                <div class="cpb-field w3">
                    <label for="notafiscal">Nota fiscal</label>
                    <input type="text" id="notafiscal" name="notafiscal" value="<?= cpbH($form['notafiscal']) ?>">
                </div>
                <?php if ($tituloEdicao): ?>
                    <div class="cpb-field w2">
                        <label for="numparcela">N. parcela</label>
                        <input type="number" id="numparcela" name="numparcela" min="1" value="<?= cpbH($form['numparcela']) ?>" readonly>
                    </div>
                    <div class="cpb-field w2">
                        <label for="parcela">Parcela</label>
                        <input type="text" id="parcela" name="parcela" value="<?= cpbH($form['parcela']) ?>" readonly>
                    </div>
                    <input type="hidden" name="qtd_parcelas" value="1">
                    <input type="hidden" name="vencimento_modo" value="30dias">
                <?php else: ?>
                    <div class="cpb-field w2">
                        <label for="qtd_parcelas">N. parcelas</label>
                        <input type="number" id="qtd_parcelas" name="qtd_parcelas" min="1" max="120" value="<?= cpbH($form['qtd_parcelas']) ?>" required>
                    </div>
                    <div class="cpb-field w3" id="box_modo_vencimento">
                        <label for="vencimento_modo">Proximos vencimentos</label>
                        <select id="vencimento_modo" name="vencimento_modo">
                            <option value="fixo" <?= $form['vencimento_modo'] === 'fixo' ? 'selected' : '' ?>>Dia fixo de vencimento</option>
                            <option value="7dias" <?= $form['vencimento_modo'] === '7dias' ? 'selected' : '' ?>>Acrescentar 7 dias</option>
                            <option value="10dias" <?= $form['vencimento_modo'] === '10dias' ? 'selected' : '' ?>>Acrescentar 10 dias</option>
                            <option value="15dias" <?= $form['vencimento_modo'] === '15dias' ? 'selected' : '' ?>>Acrescentar 15 dias</option>
                            <option value="30dias" <?= $form['vencimento_modo'] === '30dias' ? 'selected' : '' ?>>Acrescentar 30 dias</option>
                        </select>
                    </div>
                    <div class="cpb-field w2" id="box_dia_fixo">
                        <label for="dia_fixo">Dia fixo</label>
                        <input type="number" id="dia_fixo" name="dia_fixo" min="1" max="31" value="<?= cpbH($form['dia_fixo']) ?>">
                    </div>
                <?php endif; ?>
                <div class="cpb-field w3">
                    <label for="valor"><?= $tituloEdicao ? 'Valor da parcela' : 'Valor da compra' ?></label>
                    <input type="text" id="valor" name="valor" inputmode="decimal" value="<?= cpbH($form['valor']) ?>" required>
                </div>
                <div class="cpb-field w12">
                    <label for="observacao">Observacao</label>
                    <textarea id="observacao" name="observacao"><?= cpbH($form['observacao']) ?></textarea>
                </div>
            </div>

            <div class="cpb-actions">
                <button type="submit" class="cpb-btn"><?= $tituloEdicao ? 'Salvar edicao' : 'Salvar titulo' ?></button>
                <?php if ($tituloEdicao): ?>
                    <a href="contas_pagar.php" class="cpb-btn secondary">Novo titulo</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="cpb-card">
        <h2 class="cpb-title">Filtros</h2>
        <form method="get" class="cpb-grid" autocomplete="off">
            <div class="cpb-field w3">
                <label for="fornecedor">Fornecedor</label>
                <input type="text" id="fornecedor" name="fornecedor" value="<?= cpbH($fFornecedor) ?>">
            </div>
            <div class="cpb-field w3">
                <label for="documento">Documento</label>
                <input type="text" id="documento" name="documento" value="<?= cpbH($fDocumento) ?>">
            </div>
            <div class="cpb-field w2">
                <label for="valor_min">Valor inicial</label>
                <input type="text" id="valor_min" name="valor_min" inputmode="decimal" value="<?= cpbH($fValorMin) ?>" placeholder="0,00">
            </div>
            <div class="cpb-field w2">
                <label for="valor_max">Valor final</label>
                <input type="text" id="valor_max" name="valor_max" inputmode="decimal" value="<?= cpbH($fValorMax) ?>" placeholder="0,00">
            </div>
            <div class="cpb-field w2">
                <label for="usar_data">Filtro de data</label>
                <select id="usar_data" name="usar_data">
                    <option value="" <?= !$fUsarData ? 'selected' : '' ?>>Nao usar datas</option>
                    <option value="S" <?= $fUsarData ? 'selected' : '' ?>>Usar datas</option>
                </select>
            </div>
            <div class="cpb-field w2">
                <label for="compra_ini">Compra inicial</label>
                <input type="date" id="compra_ini" name="compra_ini" value="<?= cpbH($fCompraIni) ?>">
            </div>
            <div class="cpb-field w2">
                <label for="compra_fim">Compra final</label>
                <input type="date" id="compra_fim" name="compra_fim" value="<?= cpbH($fCompraFim) ?>">
            </div>
            <div class="cpb-field w2">
                <label for="venc_ini">Venc. inicial</label>
                <input type="date" id="venc_ini" name="venc_ini" value="<?= cpbH($fVencIni) ?>">
            </div>
            <div class="cpb-field w2">
                <label for="venc_fim">Venc. final</label>
                <input type="date" id="venc_fim" name="venc_fim" value="<?= cpbH($fVencFim) ?>">
            </div>
            <div class="cpb-field w2">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">Todos</option>
                    <option value="AB" <?= $fStatus === 'AB' ? 'selected' : '' ?>>Aberto</option>
                    <option value="QT" <?= $fStatus === 'QT' ? 'selected' : '' ?>>Quitado</option>
                </select>
            </div>
            <div class="cpb-field w3">
                <label for="origem">Origem</label>
                <select id="origem" name="origem">
                    <option value="todos" <?= $fOrigem === 'todos' ? 'selected' : '' ?>>Todos CP001</option>
                    <option value="movimentacao_baixa" <?= $fOrigem === 'movimentacao_baixa' ? 'selected' : '' ?>>Mov/Baixa</option>
                    <option value="superdunga" <?= $fOrigem === 'superdunga' ? 'selected' : '' ?>>SuperDunga</option>
                    <option value="cartao" <?= $fOrigem === 'cartao' ? 'selected' : '' ?>>Cartao</option>
                    <option value="firebird" <?= $fOrigem === 'firebird' ? 'selected' : '' ?>>Firebird</option>
                </select>
            </div>
            <div class="cpb-field w3">
                <label for="tipoes_filtro">TIPOES</label>
                <select id="tipoes_filtro" name="tipoes">
                    <option value="">Todos</option>
                    <?php foreach ($tipos as $tipo): ?>
                        <option value="<?= (int)$tipo['ESCONTADOR'] ?>" <?= (string)$fTipoes === (string)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                            <?= cpbH(($tipo['DESCES'] ?? '') . ' (' . $tipo['ESCONTADOR'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cpb-field w12">
                <div class="cpb-actions">
                    <button class="cpb-btn" type="submit">Filtrar</button>
                    <a class="cpb-btn light" href="contas_pagar.php">Limpar</a>
                </div>
            </div>
        </form>
    </div>

    <div class="cpb-card">
        <h2 class="cpb-title">Titulos lancados</h2>
        <form method="post" autocomplete="off" id="form-titulos-selecionados"></form>
        <div class="cpb-summary">
                <div class="cpb-summary-item">
                    <div class="cpb-summary-label">Total valor</div>
                    <div class="cpb-summary-value"><?= cpbH(cpbMoeda($totalValorLista)) ?></div>
                </div>
                <div class="cpb-summary-item">
                    <div class="cpb-summary-label">Total restante</div>
                    <div class="cpb-summary-value"><?= cpbH(cpbMoeda($totalRestanteLista)) ?></div>
                </div>
                <div class="cpb-summary-item">
                    <div class="cpb-summary-label">Total selecionado</div>
                    <div class="cpb-summary-value" id="totalSelecionado">R$ 0,00</div>
                </div>
            </div>
            <div class="cpb-actions" style="margin-top:0;margin-bottom:12px;">
                <button type="submit" name="acao" value="preparar_baixa" form="form-titulos-selecionados" class="cpb-btn">Baixar titulos selecionados</button>
                <button type="submit" name="acao" value="preparar_agrupamento" form="form-titulos-selecionados" class="cpb-btn secondary">Agrupar selecionados</button>
            </div>
            <div class="cpb-table-wrap">
                <table class="cpb-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>CP</th>
                            <th>Compra</th>
                            <th>Vencimento</th>
                            <th>Fornecedor</th>
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
                                $cpIdLinha = (int)$titulo['CPCONTADOR'];
                                $baixasLinha = $baixasPorTitulo[$cpIdLinha] ?? [];
                                $agrupadosLinha = $agrupadosPorTitulo[$cpIdLinha] ?? [];
                            ?>
                            <tr>
                                <td>
                                    <?php if ($statusLinha !== 'QT'): ?>
                                        <input
                                            type="checkbox"
                                            name="cpcontadores[]"
                                            value="<?= (int)$titulo['CPCONTADOR'] ?>"
                                            data-restante="<?= cpbH((float)($titulo['VLRRESTANTE'] ?? 0)) ?>"
                                            form="form-titulos-selecionados"
                                        >
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)$titulo['CPCONTADOR'] ?></td>
                                <td><?= cpbH(cpbData($titulo['DTCOMPRA'])) ?></td>
                                <td><?= cpbH(cpbData($titulo['DTVENC'])) ?></td>
                                <td><?= cpbH(($titulo['FCONTADOR'] ?? '') . ' - ' . ($titulo['fornecedor_nome'] ?? '')) ?></td>
                                <td>
                                    <strong><?= cpbH($titulo['TITULO'] ?? '') ?></strong>
                                    <?php if (!empty($titulo['NOTAFISCAL'])): ?>
                                        <div class="text-muted small">NF <?= cpbH($titulo['NOTAFISCAL']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= cpbH(($titulo['TIPOES'] ?? '') . ' - ' . ($titulo['tipoes_desc'] ?? '')) ?></td>
                                <td><?= cpbH($titulo['origem_titulo'] ?? '') ?></td>
                                <td><?= cpbH(cpbMoeda($titulo['VLRPARCELA'])) ?></td>
                                <td><?= cpbH(cpbMoeda($titulo['VLRRESTANTE'])) ?></td>
                                <td>
                                    <span class="cpb-badge <?= $statusLinha === 'QT' ? 'paid' : 'open' ?>">
                                        <?= cpbH($statusLinha ?: 'SEM STATUS') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($statusLinha === 'QT'): ?>
                                        <button type="button" class="cpb-btn light cpb-toggle-baixa" data-target="detalhe-baixa-<?= $cpIdLinha ?>">
                                            Baixa
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($agrupadosLinha): ?>
                                        <button type="button" class="cpb-btn light cpb-toggle-baixa" data-target="detalhe-agrupamento-<?= $cpIdLinha ?>">
                                            Desdobramento
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($statusLinha !== 'QT' && ($titulo['TIPODOCORIGEM'] ?? '') === 'SUPERDUNGA' && ($titulo['CONTROLE'] ?? '') === 'MOVIMENTACAO_BAIXA'): ?>
                                        <a class="cpb-btn light" href="contas_pagar.php?editar=<?= (int)$titulo['CPCONTADOR'] ?>">Editar</a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Excluir este titulo aberto? Esta acao nao sera permitida se ele estiver vinculado a acerto.');">
                                            <input type="hidden" name="acao" value="excluir_titulo">
                                            <input type="hidden" name="cpcontador" value="<?= (int)$titulo['CPCONTADOR'] ?>">
                                            <button type="submit" class="cpb-btn secondary">Excluir</button>
                                        </form>
                                    <?php elseif ($statusLinha === 'QT'): ?>
                                        <span class="text-muted small">Quitado</span>
                                    <?php else: ?>
                                        <span class="text-muted small">Somente consulta</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($agrupadosLinha): ?>
                                <tr id="detalhe-agrupamento-<?= $cpIdLinha ?>" class="cpb-detalhe-baixa" style="display:none;">
                                    <td colspan="12">
                                        <div class="cpb-table-wrap">
                                            <table class="cpb-table" style="min-width:760px;">
                                                <thead>
                                                    <tr>
                                                        <th>CP origem</th>
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
                                                            <td><?= (int)$agrupado['CPCONTADOR'] ?></td>
                                                            <td><?= cpbH(cpbData($agrupado['DTVENC'])) ?></td>
                                                            <td><?= cpbH($agrupado['TITULO'] ?? '') ?></td>
                                                            <td><?= cpbH(cpbMoeda($valorAgrupadoLinha)) ?></td>
                                                            <td><?= cpbH($agrupado['STATUS'] ?? '') ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <tr>
                                                        <td colspan="3" style="text-align:right;font-weight:700;">Total agrupado</td>
                                                        <td style="font-weight:700;"><?= cpbH(cpbMoeda($totalAgrupadoLinha)) ?></td>
                                                        <td></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr id="detalhe-baixa-<?= $cpIdLinha ?>" class="cpb-detalhe-baixa" style="display:none;">
                                <td colspan="12">
                                    <?php if ($baixasLinha): ?>
                                        <div class="cpb-table-wrap">
                                            <table class="cpb-table" style="min-width:820px;">
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
                                                            <td><?= cpbH(cpbData($baixa['DTMOV'])) ?></td>
                                                            <td><?= cpbH(($baixa['CBCONTADOR'] ?? '') . ' - ' . ($baixa['conta_nome'] ?? '')) ?></td>
                                                            <td><?= cpbH(($baixa['TIPOES'] ?? '') . ' - ' . ($baixa['tipoes_desc'] ?? '')) ?></td>
                                                            <td><?= cpbH($baixa['TIPOMOV'] ?? '') ?></td>
                                                            <td><?= cpbH($baixa['HISTMOV'] ?? '') ?></td>
                                                            <td><?= cpbH(cpbMoeda($baixa['VALORMOV'])) ?></td>
                                                            <td>
                                                                <?php if (!empty($baixa['acerto_id'])): ?>
                                                                    Acerto #<?= (int)$baixa['acerto_id'] ?>
                                                                <?php else: ?>
                                                                    -
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if (!empty($baixa['ORIGEMCPART'])): ?>
                                                                    <span class="text-muted small">Contrapartida</span>
                                                                <?php elseif (empty($baixa['acerto_id'])): ?>
                                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Excluir esta baixa e reabrir o titulo?');">
                                                                        <input type="hidden" name="acao" value="excluir_baixa">
                                                                        <input type="hidden" name="movcontador" value="<?= (int)$baixa['MOVCONTADOR'] ?>">
                                                                        <button type="submit" class="cpb-btn secondary">Excluir baixa</button>
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
    const checks = Array.from(document.querySelectorAll('input[type="checkbox"][name="cpcontadores[]"]'));

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
    const camposData = ['compra_ini', 'compra_fim', 'venc_ini', 'venc_fim']
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
    document.querySelectorAll('.cpb-toggle-baixa').forEach(function (botao) {
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
