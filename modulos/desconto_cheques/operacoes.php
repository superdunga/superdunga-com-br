<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

garantirTabelasDescontoCheques($pdo_master);

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
garantirPrazosPadraoDescontoCheques($pdo_master, $empresaId);
garantirFeriadosNacionaisFixosDC($pdo_master, $empresaId);
garantirFeriadosVariaveisDC($pdo_master, $empresaId, (int)date('Y') - 1, 6);

$mensagemErro = '';

function arquivoUploadLinhaDC(?array $arquivos, int $idx): array
{
    if (!is_array($arquivos) || !isset($arquivos['name'][$idx])) {
        return ['error' => UPLOAD_ERR_NO_FILE];
    }

    return [
        'name' => $arquivos['name'][$idx],
        'type' => $arquivos['type'][$idx] ?? '',
        'tmp_name' => $arquivos['tmp_name'][$idx] ?? '',
        'error' => $arquivos['error'][$idx] ?? UPLOAD_ERR_NO_FILE,
        'size' => $arquivos['size'][$idx] ?? 0,
    ];
}

function proximoCrcontadorDescontoCheques(PDO $pdo, int $empresaId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CRCONTADOR), 0) + 1 FROM armazem_cr001 WHERE EMPRESA = ?");
    $stmt->execute([$empresaId]);
    return (int)$stmt->fetchColumn();
}

function proximoMovcontadorDescontoCheques(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COALESCE(MAX(MOVCONTADOR), 0) + 1 FROM armazem_bnc001");
    return (int)$stmt->fetchColumn();
}

function gerarMovimentoDescontoCheques(PDO $pdo, int $empresaId, int $usuarioId, int $operacaoId, string $data, string $tipomov, int $tipoes, float $valor, string $historico): ?int
{
    if ($valor <= 0) {
        return null;
    }

    $movcontador = proximoMovcontadorDescontoCheques($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO armazem_bnc001 (
            EMPRESA, MOVCONTADOR, DTMOV, NUMDOC, TIPOMOV, CBCONTADOR, TIPOES,
            HISTMOV, VALORMOV, TIPODOCORIGEM, NUMDOCORIGEM, REGSTAMP,
            USERBNCLANC, CONTRAPARTIDA, ORIGEMCPART, DTLANC, DTPROCESSADO, deletado
        ) VALUES (
            ?, ?, ?, ?, ?, 38, ?, ?, ?, 'DESCONTO_CHEQUES', ?, NOW(),
            ?, 'N', 0, NOW(), NOW(), 'N'
        )
    ");
    $stmt->execute([
        $empresaId,
        $movcontador,
        $data,
        'DESCCH-' . $operacaoId,
        $tipomov,
        $tipoes,
        $historico,
        $valor,
        $operacaoId,
        $usuarioId ?: null,
    ]);

    return $movcontador;
}

function gerarLancamentosFinanceirosDescontoCheques(PDO $pdo, int $empresaId, int $usuarioId, int $operacaoId): array
{
    $stmtOperacao = $pdo->prepare("
        SELECT o.*, c.nome AS cliente_nome
        FROM desconto_cheques_operacoes o
        INNER JOIN desconto_cheques_clientes c ON c.id = o.cliente_id
        WHERE o.id = ?
          AND o.empresa_id = ?
        LIMIT 1
    ");
    $stmtOperacao->execute([$operacaoId, $empresaId]);
    $operacao = $stmtOperacao->fetch(PDO::FETCH_ASSOC);
    if (!$operacao) {
        throw new RuntimeException('Operacao nao encontrada.');
    }
    if (($operacao['status'] ?? '') === 'LANCADA') {
        throw new RuntimeException('Esta operacao ja foi lancada no financeiro.');
    }

    $stmtDocs = $pdo->prepare("
        SELECT *
        FROM desconto_cheques_documentos
        WHERE operacao_id = ?
        ORDER BY data_vencimento, id
    ");
    $stmtDocs->execute([$operacaoId]);
    $documentos = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
    if (!$documentos) {
        throw new RuntimeException('Operacao sem documentos para lancar.');
    }

    $pdo->beginTransaction();
    try {
        $crGerados = 0;
        foreach ($documentos as $doc) {
            if (!empty($doc['crcontador'])) {
                continue;
            }
            $crcontador = proximoCrcontadorDescontoCheques($pdo, $empresaId);
            $titulo = trim(($doc['tipo_documento'] ?? 'CHEQUE') . ' DESC. CHEQUES OP #' . $operacaoId . ' DOC ' . ($doc['numero_documento'] ?: $doc['id']));
            $obs = trim('Operacao de desconto #' . $operacaoId . ' | Emissor: ' . ($doc['nome_emissor'] ?: '-') . ' | CPF/CNPJ: ' . ($doc['cnpj_cpf_emissor'] ?: '-'));
            $chave = 'DESCONTO-CHEQUES-DOC-' . $empresaId . '-' . (int)$doc['id'];

            $stmtCr = $pdo->prepare("
                INSERT INTO armazem_cr001 (
                    EMPRESA, CRCONTADOR, DTVENDA, NUMPARCELA, TITULO, VALORVENDA,
                    CLICONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
                    VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
                    TIPOCR, TIPOES, NOTAFISCAL, REGSTAMP, USERLANC, DTLANC,
                    USERALT, DTALT, CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
                ) VALUES (
                    ?, ?, ?, 1, ?, ?, NULL, ?, ?, ?, '1/1', ?, ?, 0, 'AB', 'DESCONTO_CHEQUES', ?, 'DESCONTO_CHEQUES',
                    'CR', 302, NULL, NOW(), ?, NOW(), ?, NOW(), ?, 'N', 'N'
                )
            ");
            $stmtCr->execute([
                $empresaId,
                $crcontador,
                $operacao['data_referencia'],
                $titulo,
                (float)$doc['valor'],
                $obs,
                $operacao['data_referencia'],
                (float)$doc['valor'],
                $doc['data_vencimento'],
                (float)$doc['valor'],
                (int)$doc['id'],
                $usuarioId ?: null,
                $usuarioId ?: null,
                $chave,
            ]);

            $pdo->prepare("UPDATE desconto_cheques_documentos SET crcontador = ? WHERE id = ? AND operacao_id = ?")
                ->execute([$crcontador, (int)$doc['id'], $operacaoId]);
            $crGerados++;
        }

        $movBruto = $operacao['mov_bruto'] ?: gerarMovimentoDescontoCheques($pdo, $empresaId, $usuarioId, $operacaoId, $operacao['data_referencia'], 'D', 302, (float)$operacao['valor_bruto'], 'DESC. CHEQUES OP #' . $operacaoId . ' - VALOR BRUTO - ' . $operacao['cliente_nome']);
        $movDesconto = $operacao['mov_desconto'] ?: gerarMovimentoDescontoCheques($pdo, $empresaId, $usuarioId, $operacaoId, $operacao['data_referencia'], 'C', 51, (float)$operacao['valor_desconto'], 'DESC. CHEQUES OP #' . $operacaoId . ' - DESCONTO');
        $movTaxas = $operacao['mov_taxas'] ?: gerarMovimentoDescontoCheques($pdo, $empresaId, $usuarioId, $operacaoId, $operacao['data_referencia'], 'C', 51, (float)$operacao['valor_taxas_tarifas'], 'DESC. CHEQUES OP #' . $operacaoId . ' - TAXAS/TARIFAS');
        $movOutros = $operacao['mov_outros'] ?: gerarMovimentoDescontoCheques($pdo, $empresaId, $usuarioId, $operacaoId, $operacao['data_referencia'], 'C', 51, (float)$operacao['valor_descontar'], 'DESC. CHEQUES OP #' . $operacaoId . ' - OUTROS DESCONTOS');

        $stmtUpdate = $pdo->prepare("
            UPDATE desconto_cheques_operacoes
            SET status = 'LANCADA',
                mov_bruto = COALESCE(mov_bruto, ?),
                mov_desconto = COALESCE(mov_desconto, ?),
                mov_taxas = COALESCE(mov_taxas, ?),
                mov_outros = COALESCE(mov_outros, ?)
            WHERE id = ?
              AND empresa_id = ?
        ");
        $stmtUpdate->execute([$movBruto, $movDesconto, $movTaxas, $movOutros, $operacaoId, $empresaId]);

        $pdo->commit();
        return ['cr' => $crGerados];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$stmtClientes = $pdo_master->prepare("
    SELECT *
    FROM desconto_cheques_clientes
    WHERE empresa_id = ?
      AND ativo = 'S'
    ORDER BY nome
");
$stmtClientes->execute([$empresaId]);
$clientes = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);
$clientesPorId = [];
foreach ($clientes as $clienteLinha) {
    $clientesPorId[(int)$clienteLinha['id']] = $clienteLinha;
}

$faixasPrazo = buscarPrazosDescontoCheques($pdo_master, $empresaId);
$feriadosRecorrentes = feriadosRecorrentesDC($pdo_master, $empresaId);
$feriadosEspecificos = feriadosEspecificosDC($pdo_master, $empresaId, (int)date('Y') - 1, (int)date('Y') + 6);
$operacaoEditarId = (int)($_GET['editar'] ?? $_POST['operacao_id'] ?? 0);
$operacaoEditar = null;
$documentosEditar = [];
$mostrarFormulario = isset($_GET['nova']) || $operacaoEditarId > 0 || $_SERVER['REQUEST_METHOD'] === 'POST';

if ($operacaoEditarId > 0) {
    $stmtEditar = $pdo_master->prepare("
        SELECT *
        FROM desconto_cheques_operacoes
        WHERE id = ?
          AND empresa_id = ?
        LIMIT 1
    ");
    $stmtEditar->execute([$operacaoEditarId, $empresaId]);
    $operacaoEditar = $stmtEditar->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($operacaoEditar) {
        $stmtDocsEditar = $pdo_master->prepare("
            SELECT *
            FROM desconto_cheques_documentos
            WHERE operacao_id = ?
            ORDER BY data_vencimento, id
        ");
        $stmtDocsEditar->execute([$operacaoEditarId]);
        $documentosEditar = $stmtDocsEditar->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $operacaoEditarId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'gerar_financeiro') {
    $operacaoGerarId = (int)($_POST['operacao_id'] ?? 0);
    try {
        $resultadoGeracao = gerarLancamentosFinanceirosDescontoCheques($pdo_master, $empresaId, $usuarioId, $operacaoGerarId);
        header('Location: operacoes.php?ok=financeiro&id=' . $operacaoGerarId . '&cr=' . (int)$resultadoGeracao['cr']);
        exit;
    } catch (Throwable $e) {
        $mensagemErro = 'Erro ao gerar lancamentos: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') !== 'gerar_financeiro') {
    $dataReferencia = $_POST['data_referencia'] ?: date('Y-m-d');
    $clienteId = (int)($_POST['cliente_id'] ?? 0);
    $observacao = trim((string)($_POST['observacao'] ?? ''));
    $valorTaxasTarifas = decimalDC($_POST['valor_taxas_tarifas'] ?? '0');
    $historicoTaxasTarifas = trim((string)($_POST['historico_taxas_tarifas'] ?? ''));
    $valorDescontar = decimalDC($_POST['valor_descontar'] ?? '0');
    $historicoDescontar = trim((string)($_POST['historico_descontar'] ?? ''));
    $tipos = $_POST['tipo_documento'] ?? [];
    $documentoIds = $_POST['documento_id'] ?? [];
    $numeros = $_POST['numero_documento'] ?? [];
    $cnpjsCpfsEmissores = $_POST['cnpj_cpf_emissor'] ?? [];
    $nomesEmissores = $_POST['nome_emissor'] ?? [];
    $valores = $_POST['valor'] ?? [];
    $vencimentos = $_POST['data_vencimento'] ?? [];
    $arquivos = $_FILES['arquivo_documento'] ?? null;
    $arquivosFrente = $_FILES['arquivo_frente'] ?? null;
    $arquivosVerso = $_FILES['arquivo_verso'] ?? null;
    $docsAtuaisPorId = [];

    if ($operacaoEditarId > 0) {
        $stmtDocsAtuais = $pdo_master->prepare("
            SELECT *
            FROM desconto_cheques_documentos
            WHERE operacao_id = ?
        ");
        $stmtDocsAtuais->execute([$operacaoEditarId]);
        foreach ($stmtDocsAtuais->fetchAll(PDO::FETCH_ASSOC) as $docAtual) {
            $docsAtuaisPorId[(int)$docAtual['id']] = $docAtual;
        }
    }

    if ($operacaoEditar && ($operacaoEditar['status'] ?? '') === 'LANCADA') {
        $mensagemErro = 'Operacao ja lancada no financeiro e nao pode ser editada.';
    } elseif (!isset($clientesPorId[$clienteId])) {
        $mensagemErro = 'Informe um cliente ativo.';
    } elseif ($valorTaxasTarifas < 0 || $valorDescontar < 0) {
        $mensagemErro = 'Taxas/tarifas e valores a descontar nao podem ser negativos.';
    } else {
        $documentos = [];
        foreach ($valores as $idx => $valorInformado) {
            $valor = decimalDC($valorInformado);
            $vencimento = trim((string)($vencimentos[$idx] ?? ''));
            if ($valor <= 0 && $vencimento === '') {
                continue;
            }

            if ($valor <= 0 || $vencimento === '') {
                $mensagemErro = 'Todos os documentos informados precisam de valor e vencimento.';
                break;
            }

            try {
                $calculo = calcularDocumentoDescontoCheques($valor, $dataReferencia, $vencimento, $clientesPorId[$clienteId], $faixasPrazo, $feriadosRecorrentes, $feriadosEspecificos);
            } catch (Throwable $e) {
                $mensagemErro = 'Data invalida em um dos documentos.';
                break;
            }

            $arquivoLinha = arquivoUploadLinhaDC($arquivos, $idx);
            $arquivoFrenteLinha = arquivoUploadLinhaDC($arquivosFrente, $idx);
            $arquivoVersoLinha = arquivoUploadLinhaDC($arquivosVerso, $idx);

            $documentos[] = [
                'id' => (int)($documentoIds[$idx] ?? 0),
                'tipo_documento' => in_array(($tipos[$idx] ?? 'CHEQUE'), ['CHEQUE', 'BOLETO'], true) ? $tipos[$idx] : 'CHEQUE',
                'numero_documento' => trim((string)($numeros[$idx] ?? '')),
                'cnpj_cpf_emissor' => preg_replace('/\D+/', '', (string)($cnpjsCpfsEmissores[$idx] ?? '')),
                'nome_emissor' => trim((string)($nomesEmissores[$idx] ?? '')),
                'valor' => $valor,
                'data_vencimento' => $vencimento,
                'arquivo' => $arquivoLinha,
                'arquivo_frente' => $arquivoFrenteLinha,
                'arquivo_verso' => $arquivoVersoLinha,
                'calculo' => $calculo,
            ];
        }

        if ($mensagemErro === '' && empty($documentos)) {
            $mensagemErro = 'Informe ao menos um documento.';
        }

        if ($mensagemErro === '') {
            $valorBruto = array_sum(array_column($documentos, 'valor'));
            $resumoCredito = buscarResumoCreditoClienteDC($pdo_master, $empresaId, $clienteId, $operacaoEditarId);
            if ((float)$resumoCredito['limite_credito'] > 0 && $valorBruto > (float)$resumoCredito['credito_disponivel']) {
                $mensagemErro = 'Operacao acima do limite disponivel do cliente. Disponivel: ' . moedaDC($resumoCredito['credito_disponivel']) . '.';
            }
        }

        if ($mensagemErro === '') {
            try {
                $pdo_master->beginTransaction();

                $valorBruto = 0.0;
                $valorDesconto = 0.0;
                $valorLiquidoTitulos = 0.0;
                foreach ($documentos as $documento) {
                    $valorBruto += $documento['valor'];
                    $valorDesconto += $documento['calculo']['desconto_valor'];
                    $valorLiquidoTitulos += $documento['calculo']['valor_liquido'];
                }
                $valorLiquidoFinal = round($valorLiquidoTitulos - $valorTaxasTarifas - $valorDescontar, 2);

                if ($operacaoEditarId > 0) {
                    $operacaoId = $operacaoEditarId;
                    $stmtOperacao = $pdo_master->prepare("
                        UPDATE desconto_cheques_operacoes
                        SET cliente_id = ?,
                            data_referencia = ?,
                            valor_bruto = ?,
                            valor_desconto = ?,
                            valor_taxas_tarifas = ?,
                            historico_taxas_tarifas = NULLIF(?, ''),
                            valor_descontar = ?,
                            historico_descontar = NULLIF(?, ''),
                            valor_liquido = ?,
                            observacao = NULLIF(?, '')
                        WHERE id = ?
                          AND empresa_id = ?
                    ");
                    $stmtOperacao->execute([
                        $clienteId,
                        $dataReferencia,
                        $valorBruto,
                        $valorDesconto,
                        $valorTaxasTarifas,
                        $historicoTaxasTarifas,
                        $valorDescontar,
                        $historicoDescontar,
                        $valorLiquidoFinal,
                        $observacao,
                        $operacaoId,
                        $empresaId,
                    ]);
                } else {
                    $stmtOperacao = $pdo_master->prepare("
                        INSERT INTO desconto_cheques_operacoes
                            (empresa_id, cliente_id, data_referencia, status, valor_bruto, valor_desconto,
                             valor_taxas_tarifas, historico_taxas_tarifas, valor_descontar, historico_descontar,
                             valor_liquido, observacao, criado_por)
                        VALUES (?, ?, ?, 'ABERTA', ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), ?, NULLIF(?, ''), ?)
                    ");
                    $stmtOperacao->execute([
                        $empresaId,
                        $clienteId,
                        $dataReferencia,
                        $valorBruto,
                        $valorDesconto,
                        $valorTaxasTarifas,
                        $historicoTaxasTarifas,
                        $valorDescontar,
                        $historicoDescontar,
                        $valorLiquidoFinal,
                        $observacao,
                        $usuarioId ?: null,
                    ]);
                    $operacaoId = (int)$pdo_master->lastInsertId();
                }

                $stmtDoc = $pdo_master->prepare("
                    INSERT INTO desconto_cheques_documentos
                        (operacao_id, tipo_documento, numero_documento, cnpj_cpf_emissor, nome_emissor,
                         arquivo_nome, arquivo_caminho, arquivo_frente_nome, arquivo_frente_caminho, arquivo_verso_nome, arquivo_verso_caminho, valor,
                         data_vencimento, data_compensacao, prazo_dias, taxa_cliente, adicional_percentual,
                         adicional_valor, desconto_valor, valor_liquido)
                    VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtDocUpdate = $pdo_master->prepare("
                    UPDATE desconto_cheques_documentos
                    SET tipo_documento = ?,
                        numero_documento = NULLIF(?, ''),
                        cnpj_cpf_emissor = NULLIF(?, ''),
                        nome_emissor = NULLIF(?, ''),
                        arquivo_nome = ?,
                        arquivo_caminho = ?,
                        arquivo_frente_nome = ?,
                        arquivo_frente_caminho = ?,
                        arquivo_verso_nome = ?,
                        arquivo_verso_caminho = ?,
                        valor = ?,
                        data_vencimento = ?,
                        data_compensacao = ?,
                        prazo_dias = ?,
                        taxa_cliente = ?,
                        adicional_percentual = ?,
                        adicional_valor = ?,
                        desconto_valor = ?,
                        valor_liquido = ?
                    WHERE id = ?
                      AND operacao_id = ?
                ");
                $docsMantidos = [];

                foreach ($documentos as $documento) {
                    $docId = (int)$documento['id'];
                    $upload = salvarUploadDocumentoDC($documento['arquivo']);
                    $uploadFrente = salvarUploadDocumentoDC($documento['arquivo_frente']);
                    $uploadVerso = salvarUploadDocumentoDC($documento['arquivo_verso']);
                    if ($docId > 0 && isset($docsAtuaisPorId[$docId]) && !$upload['caminho']) {
                        $upload['nome'] = $docsAtuaisPorId[$docId]['arquivo_nome'];
                        $upload['caminho'] = $docsAtuaisPorId[$docId]['arquivo_caminho'];
                    }
                    if ($docId > 0 && isset($docsAtuaisPorId[$docId]) && !$uploadFrente['caminho']) {
                        $uploadFrente['nome'] = $docsAtuaisPorId[$docId]['arquivo_frente_nome'] ?? $docsAtuaisPorId[$docId]['arquivo_nome'];
                        $uploadFrente['caminho'] = $docsAtuaisPorId[$docId]['arquivo_frente_caminho'] ?? $docsAtuaisPorId[$docId]['arquivo_caminho'];
                    }
                    if ($docId > 0 && isset($docsAtuaisPorId[$docId]) && !$uploadVerso['caminho']) {
                        $uploadVerso['nome'] = $docsAtuaisPorId[$docId]['arquivo_verso_nome'] ?? null;
                        $uploadVerso['caminho'] = $docsAtuaisPorId[$docId]['arquivo_verso_caminho'] ?? null;
                    }
                    if ($documento['tipo_documento'] === 'CHEQUE') {
                        if (!$uploadFrente['caminho'] && $upload['caminho']) {
                            $uploadFrente = $upload;
                        }
                        $upload = $uploadFrente;
                    } else {
                        $uploadFrente = ['nome' => null, 'caminho' => null];
                        $uploadVerso = ['nome' => null, 'caminho' => null];
                    }
                    $calculo = $documento['calculo'];
                    if ($docId > 0 && isset($docsAtuaisPorId[$docId])) {
                        $stmtDocUpdate->execute([
                            $documento['tipo_documento'],
                            $documento['numero_documento'],
                            $documento['cnpj_cpf_emissor'],
                            $documento['nome_emissor'],
                            $upload['nome'],
                            $upload['caminho'],
                            $uploadFrente['nome'],
                            $uploadFrente['caminho'],
                            $uploadVerso['nome'],
                            $uploadVerso['caminho'],
                            $documento['valor'],
                            $documento['data_vencimento'],
                            $calculo['data_compensacao'],
                            $calculo['prazo_dias'],
                            $calculo['taxa_cliente'],
                            $calculo['adicional_percentual'],
                            $calculo['adicional_valor'],
                            $calculo['desconto_valor'],
                            $calculo['valor_liquido'],
                            $docId,
                            $operacaoId,
                        ]);
                        $docsMantidos[] = $docId;
                    } else {
                        $stmtDoc->execute([
                            $operacaoId,
                            $documento['tipo_documento'],
                            $documento['numero_documento'],
                            $documento['cnpj_cpf_emissor'],
                            $documento['nome_emissor'],
                            $upload['nome'],
                            $upload['caminho'],
                            $uploadFrente['nome'],
                            $uploadFrente['caminho'],
                            $uploadVerso['nome'],
                            $uploadVerso['caminho'],
                            $documento['valor'],
                            $documento['data_vencimento'],
                            $calculo['data_compensacao'],
                            $calculo['prazo_dias'],
                            $calculo['taxa_cliente'],
                            $calculo['adicional_percentual'],
                            $calculo['adicional_valor'],
                            $calculo['desconto_valor'],
                            $calculo['valor_liquido'],
                        ]);
                        $docsMantidos[] = (int)$pdo_master->lastInsertId();
                    }
                }

                if ($operacaoEditarId > 0) {
                    $docsRemover = array_diff(array_keys($docsAtuaisPorId), $docsMantidos);
                    if (!empty($docsRemover)) {
                        $placeholdersRemover = implode(',', array_fill(0, count($docsRemover), '?'));
                        $stmtRemover = $pdo_master->prepare("
                            DELETE FROM desconto_cheques_documentos
                            WHERE operacao_id = ?
                              AND id IN ($placeholdersRemover)
                        ");
                        $stmtRemover->execute(array_merge([$operacaoId], array_values($docsRemover)));
                    }
                }

                $pdo_master->commit();
                if (!empty($_POST['continuar_editando'])) {
                    header('Location: operacoes.php?editar=' . $operacaoId . '&ok=' . ($operacaoEditarId > 0 ? 'edit' : '1') . '&id=' . $operacaoId);
                } else {
                    header('Location: operacoes.php?ok=' . ($operacaoEditarId > 0 ? 'edit' : '1') . '&id=' . $operacaoId);
                }
                exit;
            } catch (Throwable $e) {
                if ($pdo_master->inTransaction()) {
                    $pdo_master->rollBack();
                }
                $mensagemErro = 'Erro ao salvar operacao: ' . $e->getMessage();
            }
        }
    }
}

$filtroCliente = (int)($_GET['cliente_id'] ?? 0);
$dataIni = $_GET['data_ini'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-d');
$filtroStatus = strtoupper(trim((string)($_GET['status'] ?? '')));
$filtroBusca = trim((string)($_GET['busca'] ?? ''));
$valorMin = trim((string)($_GET['valor_min'] ?? ''));
$valorMax = trim((string)($_GET['valor_max'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataIni)) {
    $dataIni = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
    $dataFim = date('Y-m-d');
}
$where = ['o.empresa_id = ?', 'o.data_referencia BETWEEN ? AND ?'];
$params = [$empresaId, $dataIni, $dataFim];

if ($filtroCliente > 0) {
    $where[] = 'o.cliente_id = ?';
    $params[] = $filtroCliente;
}
if ($filtroStatus !== '') {
    $where[] = 'o.status = ?';
    $params[] = $filtroStatus;
}
if ($valorMin !== '') {
    $where[] = 'o.valor_liquido >= ?';
    $params[] = decimalDC($valorMin);
}
if ($valorMax !== '') {
    $where[] = 'o.valor_liquido <= ?';
    $params[] = decimalDC($valorMax);
}
if ($filtroBusca !== '') {
    $where[] = "(
        o.id = ?
        OR o.observacao LIKE ?
        OR c.nome LIKE ?
        OR EXISTS (
            SELECT 1
            FROM desconto_cheques_documentos d
            WHERE d.operacao_id = o.id
              AND (
                d.numero_documento LIKE ?
                OR d.cnpj_cpf_emissor LIKE ?
                OR d.nome_emissor LIKE ?
              )
        )
    )";
    $likeBusca = '%' . $filtroBusca . '%';
    $params[] = ctype_digit($filtroBusca) ? (int)$filtroBusca : 0;
    array_push($params, $likeBusca, $likeBusca, $likeBusca, $likeBusca, $likeBusca);
}

$stmtOperacoes = $pdo_master->prepare("
    SELECT
        o.*,
        c.nome AS cliente_nome,
        c.limite_credito
    FROM desconto_cheques_operacoes o
    INNER JOIN desconto_cheques_clientes c ON c.id = o.cliente_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY o.data_referencia DESC, o.id DESC
    LIMIT 100
");
$stmtOperacoes->execute($params);
$operacoes = $stmtOperacoes->fetchAll(PDO::FETCH_ASSOC);

$totaisOperacoes = [
    'bruto' => 0.0,
    'desconto' => 0.0,
    'outros' => 0.0,
    'liquido' => 0.0,
];
foreach ($operacoes as $operacaoTotal) {
    $totaisOperacoes['bruto'] += (float)($operacaoTotal['valor_bruto'] ?? 0);
    $totaisOperacoes['desconto'] += (float)($operacaoTotal['valor_desconto'] ?? 0);
    $totaisOperacoes['outros'] += (float)($operacaoTotal['valor_taxas_tarifas'] ?? 0) + (float)($operacaoTotal['valor_descontar'] ?? 0);
    $totaisOperacoes['liquido'] += (float)($operacaoTotal['valor_liquido'] ?? 0);
}

$formOperacao = $operacaoEditar ?: [
    'id' => 0,
    'cliente_id' => '',
    'data_referencia' => date('Y-m-d'),
    'observacao' => '',
    'valor_taxas_tarifas' => 0,
    'historico_taxas_tarifas' => '',
    'valor_descontar' => 0,
    'historico_descontar' => '',
];
$formDocumentos = $documentosEditar ?: [[
    'id' => 0,
    'tipo_documento' => 'CHEQUE',
    'numero_documento' => '',
    'cnpj_cpf_emissor' => '',
    'nome_emissor' => '',
    'valor' => '',
    'data_vencimento' => '',
    'arquivo_nome' => '',
    'arquivo_caminho' => '',
    'arquivo_frente_nome' => '',
    'arquivo_frente_caminho' => '',
    'arquivo_verso_nome' => '',
    'arquivo_verso_caminho' => '',
]];
$operacaoLancada = $operacaoEditar && ($operacaoEditar['status'] ?? '') === 'LANCADA';

require '../../layout/header.php';
?>

<style>
    .dc-summary-card {
        border: 1px solid #d9e2ef;
        border-radius: 8px;
        background: #fff;
        padding: 1rem;
        height: 100%;
    }

    .dc-doc-card {
        border: 1px solid #d9e2ef;
        border-radius: 8px;
        background: #f8fafc;
        padding: .75rem;
    }

    .dc-total-card {
        border: 1px solid #d9e2ef;
        border-radius: 8px;
        background: #fff;
        padding: .85rem 1rem;
        height: 100%;
    }

    .dc-total-label {
        color: #64748b;
        font-size: .78rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .dc-total-value {
        color: #0f172a;
        font-size: 1.15rem;
        font-weight: 800;
        line-height: 1.2;
    }

    .dc-total-value.negative {
        color: #b91c1c;
    }

    .dc-total-value.positive {
        color: #047857;
    }

    .dc-doc-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(150px, 1fr));
        gap: .5rem;
        align-items: end;
    }

    .dc-doc-grid .dc-doc-file {
        grid-column: span 2;
    }

    .dc-doc-grid .dc-doc-file-cheque {
        grid-column: span 2;
    }

    .dc-doc-grid .dc-doc-file-generico {
        grid-column: span 2;
    }

    .dc-doc-calculo-atual {
        background: #fff;
        border: 1px solid #d9e2ef;
        border-radius: 8px;
        display: grid;
        gap: .35rem;
        grid-column: 1 / -1;
        grid-template-columns: repeat(3, minmax(120px, 1fr));
        padding: .65rem .75rem;
    }

    .dc-doc-calculo-atual span {
        display: block;
    }

    .documento-row[data-tipo-documento="CHEQUE"] .dc-doc-file-generico,
    .documento-row[data-tipo-documento="BOLETO"] .dc-doc-file-cheque {
        display: none;
    }

    .dc-doc-grid .dc-doc-remove {
        justify-self: end;
    }

    .dc-doc-summary {
        display: none;
        gap: .75rem;
        align-items: center;
        justify-content: space-between;
    }

    .documento-row:not(.documento-ativo) {
        background: #fff;
    }

    .documento-row:not(.documento-ativo) .dc-doc-grid {
        display: none;
    }

    .documento-row:not(.documento-ativo) .dc-doc-summary {
        display: flex;
    }

    .dc-doc-summary-main {
        min-width: 0;
        flex: 1 1 220px;
    }

    .dc-doc-summary-title,
    .dc-doc-summary-subtitle {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .dc-doc-summary-metrics {
        display: grid;
        gap: .1rem;
        min-width: 150px;
    }

    .dc-doc-summary-thumb {
        align-items: center;
        display: flex;
        justify-content: center;
        min-width: 64px;
    }

    .dc-doc-summary-thumb img {
        border: 1px solid #d9e2ef;
        border-radius: 6px;
        height: 56px;
        object-fit: cover;
        width: 56px;
    }

    .dc-doc-summary-thumb .btn {
        white-space: nowrap;
    }

    .dc-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
    }

    @media (max-width: 991.98px) {
        .dc-doc-grid {
            grid-template-columns: 1fr 1fr;
        }

        .dc-doc-grid .dc-doc-file {
            grid-column: span 2;
        }

        .dc-doc-grid .dc-doc-file-cheque,
        .dc-doc-grid .dc-doc-file-generico {
            grid-column: span 2;
        }

        .dc-doc-calculo-atual {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 575.98px) {
        .dc-doc-grid {
            grid-template-columns: 1fr;
        }

        .dc-doc-grid .dc-doc-file {
            grid-column: auto;
        }

        .dc-doc-grid .dc-doc-file-cheque,
        .dc-doc-grid .dc-doc-file-generico {
            grid-column: auto;
        }

        .dc-doc-grid .dc-doc-remove {
            justify-self: stretch;
        }

        .dc-actions .btn,
        .dc-main-actions .btn {
            width: 100%;
        }

        .dc-operation-table {
            min-width: 820px;
        }

        .dc-doc-summary {
            align-items: stretch;
            flex-direction: column;
        }

        .dc-doc-summary-values {
            text-align: left !important;
        }

        .dc-doc-summary-thumb {
            justify-content: flex-start;
        }

        .dc-doc-summary-actions {
            display: grid;
            gap: .5rem;
            grid-template-columns: 1fr 1fr;
        }
    }
</style>

<section class="mb-3">
    <div class="bg-white border rounded-2 shadow-sm p-3 p-lg-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div>
                <span class="badge text-bg-warning mb-2">Desconto de Cheques</span>
                <h1 class="h4 fw-bold mb-1">Operacoes</h1>
                <p class="text-muted mb-0">Lancamento manual dos documentos com calculo automatico de desconto.</p>
            </div>
            <div class="dc-actions align-self-lg-center">
                <a href="menu_desconto_cheques.php" class="btn btn-outline-secondary">Voltar</a>
                <a href="clientes.php" class="btn btn-outline-primary">Clientes</a>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($_GET['ok'])): ?>
    <?php if ($_GET['ok'] === 'financeiro'): ?>
        <div class="alert alert-success">Lancamentos financeiros da operacao #<?= (int)($_GET['id'] ?? 0) ?> gerados com sucesso. Titulos CR001 criados: <?= (int)($_GET['cr'] ?? 0) ?>.</div>
    <?php else: ?>
        <div class="alert alert-success">Operacao #<?= (int)($_GET['id'] ?? 0) ?> <?= $_GET['ok'] === 'edit' ? 'atualizada' : 'salva' ?> com sucesso.</div>
    <?php endif; ?>
<?php endif; ?>
<?php if ($mensagemErro !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div>
<?php endif; ?>

<?php if ($mostrarFormulario): ?>
<section class="mb-3">
    <div class="row g-3">
        <?php foreach ($faixasPrazo as $faixa): ?>
            <div class="col-12 col-md-4">
                <div class="dc-summary-card">
                    <div class="fw-semibold"><?= htmlspecialchars($faixa['descricao']) ?></div>
                    <div class="small text-muted">
                        Adicional <?= percentualDC($faixa['adicional_percentual']) ?>
                        <?php if ((float)$faixa['minimo_valor'] > 0): ?>
                            | minimo <?= moedaDC($faixa['minimo_valor']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="mb-4">
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold"><?= $operacaoEditar ? 'Editar operacao #' . (int)$operacaoEditar['id'] : 'Nova operacao' ?></div>
        <div class="card-body">
            <?php if (empty($clientes)): ?>
                <div class="alert alert-warning mb-0">Cadastre um cliente ativo antes de lancar uma operacao.</div>
            <?php else: ?>
                <?php if ($operacaoLancada): ?>
                    <div class="alert alert-info">Operacao lancada no financeiro. Os dados ficam disponiveis somente para consulta.</div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" id="formOperacao" class="row g-3">
                    <input type="hidden" name="operacao_id" value="<?= (int)$formOperacao['id'] ?>">
                    <input type="hidden" name="continuar_editando" id="continuarEditando" value="0">
                    <fieldset class="row g-3 m-0 p-0 border-0" <?= $operacaoLancada ? 'disabled' : '' ?>>
                    <div class="col-12 col-md-4 col-lg-3">
                        <label class="form-label">Data de referencia</label>
                        <input type="date" name="data_referencia" class="form-control" value="<?= htmlspecialchars((string)$formOperacao['data_referencia']) ?>" required>
                    </div>
                    <div class="col-12 col-md-8 col-lg-5">
                        <label class="form-label">Cliente</label>
                        <select name="cliente_id" class="form-select" required>
                            <option value="">Selecione</option>
                            <?php foreach ($clientes as $cliente): ?>
                                <option value="<?= (int)$cliente['id'] ?>" <?= (int)$formOperacao['cliente_id'] === (int)$cliente['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cliente['nome']) ?> | taxa <?= percentualDC($cliente['taxa_desconto']) ?> | limite <?= moedaDC($cliente['limite_credito']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-lg-4">
                        <label class="form-label">Observacao</label>
                        <input type="text" name="observacao" class="form-control" maxlength="255" value="<?= htmlspecialchars((string)($formOperacao['observacao'] ?? '')) ?>">
                    </div>

                    <div class="col-12">
                        <div class="row g-2">
                            <div class="col-12 col-sm-6 col-xl-3">
                                <div class="dc-total-card">
                                    <div class="dc-total-label">Valor bruto</div>
                                    <div class="dc-total-value"><?= moedaDC($formOperacao['valor_bruto'] ?? 0) ?></div>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-xl-3">
                                <div class="dc-total-card">
                                    <div class="dc-total-label">Desconto dos documentos</div>
                                    <div class="dc-total-value negative"><?= moedaDC($formOperacao['valor_desconto'] ?? 0) ?></div>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-xl-3">
                                <div class="dc-total-card">
                                    <div class="dc-total-label">Taxas e outros descontos</div>
                                    <div class="dc-total-value negative"><?= moedaDC((float)($formOperacao['valor_taxas_tarifas'] ?? 0) + (float)($formOperacao['valor_descontar'] ?? 0)) ?></div>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-xl-3">
                                <div class="dc-total-card">
                                    <div class="dc-total-label">Valor liquido</div>
                                    <div class="dc-total-value positive"><?= moedaDC($formOperacao['valor_liquido'] ?? 0) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-2">
                            <h2 class="h6 fw-bold mb-0">Documentos</h2>
                            <button type="button" class="btn btn-sm btn-primary" id="btnAddDocumento">Adicionar documento</button>
                        </div>
                        <div id="documentosContainer" class="d-flex flex-column gap-2" data-iniciar-recolhido="<?= $operacaoEditar ? '1' : '0' ?>">
                            <?php foreach ($formDocumentos as $docForm): ?>
                                <?php
                                $docPrazoDias = isset($docForm['prazo_dias']) && $docForm['prazo_dias'] !== '' ? (int)$docForm['prazo_dias'] : '';
                                $docTaxaTotal = $docPrazoDias !== '' ? percentualDC(taxaTotalDocumentoDC($docForm)) : '';
                                $docValorLiquido = isset($docForm['valor_liquido']) && $docForm['valor_liquido'] !== '' ? moedaDC($docForm['valor_liquido']) : '';
                                $docArquivoCaminho = (string)($docForm['arquivo_caminho'] ?? '');
                                $docArquivoNome = (string)($docForm['arquivo_nome'] ?? '');
                                $docFrenteCaminho = (string)($docForm['arquivo_frente_caminho'] ?? $docArquivoCaminho);
                                $docFrenteNome = (string)($docForm['arquivo_frente_nome'] ?? $docArquivoNome);
                                $docVersoCaminho = (string)($docForm['arquivo_verso_caminho'] ?? '');
                                $docVersoNome = (string)($docForm['arquivo_verso_nome'] ?? '');
                                $docTipoAtual = ($docForm['tipo_documento'] ?? 'CHEQUE') === 'BOLETO' ? 'BOLETO' : 'CHEQUE';
                                ?>
                                <div class="dc-doc-card documento-row"
                                     data-tipo-documento="<?= htmlspecialchars($docTipoAtual) ?>"
                                     data-prazo-dias="<?= htmlspecialchars((string)$docPrazoDias) ?>"
                                     data-taxa-total="<?= htmlspecialchars($docTaxaTotal) ?>"
                                     data-valor-liquido="<?= htmlspecialchars($docValorLiquido) ?>"
                                     data-arquivo-caminho="<?= htmlspecialchars($docFrenteCaminho ?: $docArquivoCaminho) ?>"
                                     data-arquivo-nome="<?= htmlspecialchars($docFrenteNome ?: $docArquivoNome) ?>">
                                    <input type="hidden" name="documento_id[]" value="<?= (int)($docForm['id'] ?? 0) ?>">
                                    <div class="dc-doc-grid">
                                        <div>
                                            <label class="form-label small">Tipo</label>
                                            <select name="tipo_documento[]" class="form-select">
                                                <option value="CHEQUE" <?= $docTipoAtual === 'CHEQUE' ? 'selected' : '' ?>>Cheque</option>
                                                <option value="BOLETO" <?= $docTipoAtual === 'BOLETO' ? 'selected' : '' ?>>Boleto</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="form-label small">Numero</label>
                                            <input type="text" name="numero_documento[]" class="form-control" value="<?= htmlspecialchars((string)($docForm['numero_documento'] ?? '')) ?>">
                                        </div>
                                        <div>
                                            <label class="form-label small">CNPJ/CPF emissor</label>
                                            <input type="text" name="cnpj_cpf_emissor[]" class="form-control" inputmode="numeric" value="<?= htmlspecialchars((string)($docForm['cnpj_cpf_emissor'] ?? '')) ?>">
                                            <div class="small mt-1 dc-emissor-status text-muted"></div>
                                        </div>
                                        <div>
                                            <label class="form-label small">Nome do emissor</label>
                                            <input type="text" name="nome_emissor[]" class="form-control" value="<?= htmlspecialchars((string)($docForm['nome_emissor'] ?? '')) ?>">
                                        </div>
                                        <div>
                                            <label class="form-label small">Valor</label>
                                            <input type="text" name="valor[]" class="form-control" inputmode="decimal" required value="<?= ($docForm['valor'] ?? '') !== '' ? htmlspecialchars(number_format((float)$docForm['valor'], 2, ',', '.')) : '' ?>">
                                        </div>
                                        <div>
                                            <label class="form-label small">Vencimento</label>
                                            <input type="date" name="data_vencimento[]" class="form-control" required value="<?= htmlspecialchars((string)($docForm['data_vencimento'] ?? '')) ?>">
                                        </div>
                                        <div class="dc-doc-file-generico">
                                            <label class="form-label small">Arquivo do boleto</label>
                                            <input type="file" name="arquivo_documento[]" class="form-control" accept="image/*,.pdf">
                                            <div class="small mt-1 dc-leitura-status text-muted"></div>
                                            <?php if (!empty($docForm['arquivo_caminho'])): ?>
                                                <div class="small mt-1">
                                                    <a target="_blank" href="../../<?= htmlspecialchars($docForm['arquivo_caminho']) ?>">Anexo atual</a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="dc-doc-file-cheque">
                                            <label class="form-label small">Frente do cheque</label>
                                            <input type="file" name="arquivo_frente[]" class="form-control" accept="image/*,.pdf">
                                            <div class="small mt-1 dc-leitura-status text-muted"></div>
                                            <?php if (!empty($docFrenteCaminho)): ?>
                                                <div class="small mt-1">
                                                    <a target="_blank" href="../../<?= htmlspecialchars($docFrenteCaminho) ?>">Frente atual</a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="dc-doc-file-cheque">
                                            <label class="form-label small">Verso do cheque</label>
                                            <input type="file" name="arquivo_verso[]" class="form-control" accept="image/*,.pdf">
                                            <?php if (!empty($docVersoCaminho)): ?>
                                                <div class="small mt-1">
                                                    <a target="_blank" href="../../<?= htmlspecialchars($docVersoCaminho) ?>">Verso atual</a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="dc-doc-calculo-atual">
                                            <div>
                                                <span class="small text-muted">Dias</span>
                                                <span class="fw-semibold dc-doc-current-days">Calculado ao salvar</span>
                                            </div>
                                            <div>
                                                <span class="small text-muted">Taxa</span>
                                                <span class="fw-semibold dc-doc-current-tax">Calculada ao salvar</span>
                                            </div>
                                            <div>
                                                <span class="small text-muted">Valor liquido</span>
                                                <span class="fw-semibold text-success dc-doc-current-liquid">Calculado ao salvar</span>
                                            </div>
                                        </div>
                                        <div class="dc-doc-remove">
                                            <div class="d-grid gap-2">
                                                <button type="button" class="btn btn-primary btn-salvar-doc">Salvar documento</button>
                                                <button type="button" class="btn btn-outline-danger btn-remover-doc">Remover</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="dc-doc-summary">
                                        <div class="dc-doc-summary-main">
                                            <div class="fw-semibold dc-doc-summary-title">Documento pendente</div>
                                            <div class="small text-muted dc-doc-summary-subtitle">Preencha os dados do documento.</div>
                                            <div class="small text-warning dc-doc-summary-status"></div>
                                        </div>
                                        <div class="dc-doc-summary-values text-md-end">
                                            <div class="fw-semibold dc-doc-summary-value">R$ 0,00</div>
                                            <div class="small text-muted dc-doc-summary-date">Vencimento nao informado</div>
                                        </div>
                                        <div class="dc-doc-summary-metrics">
                                            <div class="small fw-semibold dc-doc-summary-days">Dias calculados ao salvar</div>
                                            <div class="small text-muted dc-doc-summary-tax">Taxa calculada ao salvar</div>
                                            <div class="small text-success dc-doc-summary-liquid">Liquido calculado ao salvar</div>
                                        </div>
                                        <div class="dc-doc-summary-thumb small text-muted">Sem anexo</div>
                                        <div class="dc-doc-summary-actions">
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-editar-doc">Editar</button>
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-remover-doc">Remover</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="row g-3">
                            <div class="col-12 col-lg-6">
                                <div class="dc-doc-card">
                                    <h3 class="h6 fw-bold mb-3">Cobranca de taxas/tarifas?</h3>
                                    <div class="row g-2">
                                        <div class="col-12 col-sm-4">
                                            <label class="form-label small">Valor</label>
                                            <input type="text" name="valor_taxas_tarifas" class="form-control" inputmode="decimal" value="<?= htmlspecialchars(number_format((float)($formOperacao['valor_taxas_tarifas'] ?? 0), 2, ',', '.')) ?>">
                                        </div>
                                        <div class="col-12 col-sm-8">
                                            <label class="form-label small">Historico</label>
                                            <input type="text" name="historico_taxas_tarifas" class="form-control" maxlength="255" value="<?= htmlspecialchars((string)($formOperacao['historico_taxas_tarifas'] ?? '')) ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-lg-6">
                                <div class="dc-doc-card">
                                    <h3 class="h6 fw-bold mb-3">Valores a descontar?</h3>
                                    <div class="row g-2">
                                        <div class="col-12 col-sm-4">
                                            <label class="form-label small">Valor</label>
                                            <input type="text" name="valor_descontar" class="form-control" inputmode="decimal" value="<?= htmlspecialchars(number_format((float)($formOperacao['valor_descontar'] ?? 0), 2, ',', '.')) ?>">
                                        </div>
                                        <div class="col-12 col-sm-8">
                                            <label class="form-label small">Historico</label>
                                            <input type="text" name="historico_descontar" class="form-control" maxlength="255" value="<?= htmlspecialchars((string)($formOperacao['historico_descontar'] ?? '')) ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 dc-main-actions">
                        <?php if (!$operacaoLancada): ?>
                            <button type="submit" class="btn btn-primary"><?= $operacaoEditar ? 'Atualizar operacao' : 'Salvar operacao' ?></button>
                        <?php endif; ?>
                        <a href="operacoes.php" class="btn btn-outline-secondary">Voltar para operacoes</a>
                    </div>
                    </fieldset>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!$mostrarFormulario): ?>
<section>
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
            <h2 class="h6 fw-bold mb-0">Operacoes ja salvas</h2>
            <a href="operacoes.php?nova=1" class="btn btn-primary btn-sm">Nova Operacao</a>
        </div>
        <div class="card-body border-bottom">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-6 col-lg-2">
                    <label class="form-label small fw-semibold">Data inicial</label>
                    <input type="date" name="data_ini" class="form-control form-control-sm" value="<?= htmlspecialchars($dataIni) ?>">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small fw-semibold">Data final</label>
                    <input type="date" name="data_fim" class="form-control form-control-sm" value="<?= htmlspecialchars($dataFim) ?>">
                </div>
                <div class="col-12 col-lg-3">
                    <label class="form-label small fw-semibold">Cliente</label>
                    <select name="cliente_id" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php foreach ($clientes as $clienteFiltro): ?>
                            <option value="<?= (int)$clienteFiltro['id'] ?>" <?= $filtroCliente === (int)$clienteFiltro['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($clienteFiltro['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="ABERTA" <?= $filtroStatus === 'ABERTA' ? 'selected' : '' ?>>Aberta</option>
                        <option value="LANCADA" <?= $filtroStatus === 'LANCADA' ? 'selected' : '' ?>>Lancada</option>
                        <option value="FECHADA" <?= $filtroStatus === 'FECHADA' ? 'selected' : '' ?>>Fechada</option>
                        <option value="CANCELADA" <?= $filtroStatus === 'CANCELADA' ? 'selected' : '' ?>>Cancelada</option>
                    </select>
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label small fw-semibold">Buscar</label>
                    <input type="text" name="busca" class="form-control form-control-sm" value="<?= htmlspecialchars($filtroBusca) ?>" placeholder="Operacao, emissor, CPF/CNPJ, documento">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small fw-semibold">Liquido minimo</label>
                    <input type="text" name="valor_min" inputmode="decimal" class="form-control form-control-sm" value="<?= htmlspecialchars($valorMin) ?>">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small fw-semibold">Liquido maximo</label>
                    <input type="text" name="valor_max" inputmode="decimal" class="form-control form-control-sm" value="<?= htmlspecialchars($valorMax) ?>">
                </div>
                <div class="col-12 col-lg-8 d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-primary btn-sm">Filtrar</button>
                    <a href="operacoes.php" class="btn btn-outline-secondary btn-sm">Limpar filtros</a>
                    <span class="text-muted small align-self-center"><?= count($operacoes) ?> operacao(oes)</span>
                </div>
            </form>
        </div>
        <div class="card-body border-bottom bg-light">
            <div class="row g-2">
                <div class="col-6 col-lg-3">
                    <div class="dc-total-card">
                        <div class="dc-total-label">Bruto</div>
                        <div class="dc-total-value"><?= moedaDC($totaisOperacoes['bruto']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="dc-total-card">
                        <div class="dc-total-label">Desconto</div>
                        <div class="dc-total-value negative"><?= moedaDC($totaisOperacoes['desconto']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="dc-total-card">
                        <div class="dc-total-label">Outros descontos</div>
                        <div class="dc-total-value negative"><?= moedaDC($totaisOperacoes['outros']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="dc-total-card">
                        <div class="dc-total-label">Liquido</div>
                        <div class="dc-total-value positive"><?= moedaDC($totaisOperacoes['liquido']) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 dc-operation-table">
                <thead class="table-light">
                    <tr>
                        <th>Operacao</th>
                        <th>Cliente</th>
                        <th>Data</th>
                        <th class="text-end">Bruto</th>
                        <th class="text-end">Desconto</th>
                        <th class="text-end">Taxas</th>
                        <th class="text-end">Outros desc.</th>
                        <th class="text-end">Liquido</th>
                        <th>Status</th>
                        <th class="text-end">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($operacoes as $operacao): ?>
                        <tr class="table-primary">
                            <td class="fw-semibold">#<?= (int)$operacao['id'] ?></td>
                            <td><?= htmlspecialchars($operacao['cliente_nome']) ?></td>
                            <td><?= dataBRDC($operacao['data_referencia']) ?></td>
                            <td class="text-end"><?= moedaDC($operacao['valor_bruto']) ?></td>
                            <td class="text-end text-danger"><?= moedaDC($operacao['valor_desconto']) ?></td>
                            <td class="text-end text-danger"><?= moedaDC($operacao['valor_taxas_tarifas'] ?? 0) ?></td>
                            <td class="text-end text-danger"><?= moedaDC($operacao['valor_descontar'] ?? 0) ?></td>
                            <td class="text-end text-success fw-semibold"><?= moedaDC($operacao['valor_liquido']) ?></td>
                            <td><span class="badge text-bg-info"><?= htmlspecialchars($operacao['status']) ?></span></td>
                            <td class="text-end">
                                <a href="operacoes.php?editar=<?= (int)$operacao['id'] ?>" class="btn btn-sm btn-outline-primary">Detalhes</a>
                                <?php if (($operacao['status'] ?? '') !== 'LANCADA'): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Gerar titulos no contas a receber e lancamentos na conta 38? A operacao nao podera mais ser editada.');">
                                        <input type="hidden" name="acao" value="gerar_financeiro">
                                        <input type="hidden" name="operacao_id" value="<?= (int)$operacao['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success">Gerar lancamentos</button>
                                    </form>
                                <?php endif; ?>
                                <a href="operacao_pdf.php?id=<?= (int)$operacao['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger">PDF</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($operacoes)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">Nenhuma operacao encontrada.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($mostrarFormulario): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('documentosContainer');
    const btnAdd = document.getElementById('btnAddDocumento');
    if (!container || !btnAdd) {
        return;
    }
    let linhaAtiva = container.dataset.iniciarRecolhido === '1' ? null : container.querySelector('.documento-row');

    function valorInput(linha, seletor) {
        const campo = linha.querySelector(seletor);
        return campo ? campo.value.trim() : '';
    }

    function textoSelect(linha, seletor) {
        const campo = linha.querySelector(seletor);
        return campo && campo.selectedOptions.length ? campo.selectedOptions[0].textContent.trim() : '';
    }

    function dataBR(dataIso) {
        if (!dataIso || !/^\d{4}-\d{2}-\d{2}$/.test(dataIso)) {
            return '';
        }
        const pedacos = dataIso.split('-');
        return pedacos[2] + '/' + pedacos[1] + '/' + pedacos[0];
    }

    function escapeHtml(texto) {
        const div = document.createElement('div');
        div.textContent = texto || '';
        return div.innerHTML;
    }

    function caminhoAnexo(caminho) {
        if (!caminho) {
            return '';
        }
        if (/^(https?:)?\/\//i.test(caminho)) {
            return caminho;
        }
        return '../../' + caminho.replace(/^\/+/, '');
    }

    function atualizarResumoLinha(linha, indice) {
        const tipo = textoSelect(linha, 'select[name="tipo_documento[]"]') || 'Documento';
        const numero = valorInput(linha, 'input[name="numero_documento[]"]');
        const emissor = valorInput(linha, 'input[name="nome_emissor[]"]');
        const cnpjCpf = valorInput(linha, 'input[name="cnpj_cpf_emissor[]"]');
        const valor = valorInput(linha, 'input[name="valor[]"]');
        const vencimento = dataBR(valorInput(linha, 'input[name="data_vencimento[]"]'));
        const prazoDias = linha.dataset.prazoDias || '';
        const taxaTotal = linha.dataset.taxaTotal || '';
        const valorLiquido = linha.dataset.valorLiquido || '';
        const arquivoCaminho = linha.dataset.arquivoCaminho || '';
        const arquivoNome = linha.dataset.arquivoNome || 'Anexo';

        const titulo = linha.querySelector('.dc-doc-summary-title');
        const subtitulo = linha.querySelector('.dc-doc-summary-subtitle');
        const statusResumo = linha.querySelector('.dc-doc-summary-status');
        const valorResumo = linha.querySelector('.dc-doc-summary-value');
        const dataResumo = linha.querySelector('.dc-doc-summary-date');
        const diasResumo = linha.querySelector('.dc-doc-summary-days');
        const taxaResumo = linha.querySelector('.dc-doc-summary-tax');
        const liquidoResumo = linha.querySelector('.dc-doc-summary-liquid');
        const anexoResumo = linha.querySelector('.dc-doc-summary-thumb');
        const diasAtual = linha.querySelector('.dc-doc-current-days');
        const taxaAtual = linha.querySelector('.dc-doc-current-tax');
        const liquidoAtual = linha.querySelector('.dc-doc-current-liquid');

        if (titulo) {
            titulo.textContent = '#' + indice + ' - ' + tipo + (numero ? ' ' + numero : '');
        }
        if (subtitulo) {
            const detalhes = [];
            if (emissor) {
                detalhes.push(emissor);
            }
            if (cnpjCpf) {
                detalhes.push(cnpjCpf);
            }
            subtitulo.textContent = detalhes.length ? detalhes.join(' | ') : 'Emissor nao informado';
        }
        if (statusResumo) {
            statusResumo.textContent = documentoIdLinha(linha) === '0' ? 'Novo documento, pendente de salvar na operacao.' : '';
        }
        if (valorResumo) {
            valorResumo.textContent = valor ? 'R$ ' + valor : 'Valor nao informado';
        }
        if (dataResumo) {
            dataResumo.textContent = vencimento ? 'Venc. ' + vencimento : 'Vencimento nao informado';
        }
        if (diasResumo) {
            diasResumo.textContent = prazoDias ? prazoDias + ' dias' : 'Dias calculados ao salvar';
        }
        if (diasAtual) {
            diasAtual.textContent = prazoDias ? prazoDias + ' dias' : 'Calculado ao salvar';
        }
        if (taxaResumo) {
            taxaResumo.textContent = taxaTotal ? 'Taxa ' + taxaTotal : 'Taxa calculada ao salvar';
        }
        if (taxaAtual) {
            taxaAtual.textContent = taxaTotal || 'Calculada ao salvar';
        }
        if (liquidoResumo) {
            liquidoResumo.textContent = valorLiquido ? 'Liquido ' + valorLiquido : 'Liquido calculado ao salvar';
        }
        if (liquidoAtual) {
            liquidoAtual.textContent = valorLiquido || 'Calculado ao salvar';
        }
        if (anexoResumo) {
            const href = caminhoAnexo(arquivoCaminho);
            if (!href) {
                anexoResumo.textContent = 'Sem anexo';
            } else if (/\.(png|jpe?g|gif|webp|bmp)$/i.test(arquivoCaminho)) {
                anexoResumo.innerHTML = '<a href="' + escapeHtml(href) + '" target="_blank" title="' + escapeHtml(arquivoNome) + '"><img src="' + escapeHtml(href) + '" alt="Anexo"></a>';
            } else {
                anexoResumo.innerHTML = '<a href="' + escapeHtml(href) + '" target="_blank" class="btn btn-sm btn-outline-secondary">Anexo</a>';
            }
        }
    }

    function atualizarEstadoDocumentos() {
        const linhas = Array.from(container.querySelectorAll('.documento-row'));
        linhas.forEach(function (linha, indice) {
            linha.classList.toggle('documento-ativo', linha === linhaAtiva);
            atualizarResumoLinha(linha, indice + 1);
        });
    }

    function atualizarBotoes() {
        const linhas = container.querySelectorAll('.documento-row');
        linhas.forEach(function (linha) {
            const botao = linha.querySelector('.btn-remover-doc');
            if (botao) {
                botao.disabled = linhas.length === 1;
            }
        });
        atualizarEstadoDocumentos();
    }

    function validarDocumentoAtivo() {
        if (linhaAtiva) {
            const valorAtual = linhaAtiva.querySelector('input[name="valor[]"]');
            const vencimentoAtual = linhaAtiva.querySelector('input[name="data_vencimento[]"]');
            if (valorAtual && !valorAtual.value.trim()) {
                valorAtual.reportValidity();
                valorAtual.focus();
                return false;
            }
            if (vencimentoAtual && !vencimentoAtual.value.trim()) {
                vencimentoAtual.reportValidity();
                vencimentoAtual.focus();
                return false;
            }
        }
        return true;
    }

    function limparLinhaDocumento(clone) {
        clone.querySelectorAll('input').forEach(function (input) {
            input.value = '';
        });
        const idDocumento = clone.querySelector('input[name="documento_id[]"]');
        if (idDocumento) {
            idDocumento.value = '0';
        }
        clone.querySelectorAll('.dc-doc-file-generico a, .dc-doc-file-cheque a').forEach(function (link) {
            const wrapper = link.closest('.small');
            if (wrapper) {
                wrapper.remove();
            } else {
                link.remove();
            }
        });
        clone.querySelectorAll('.dc-leitura-status').forEach(function (status) {
            status.textContent = '';
            status.className = 'small mt-1 dc-leitura-status text-muted';
        });
        clone.querySelectorAll('.dc-emissor-status').forEach(function (status) {
            status.textContent = '';
            status.className = 'small mt-1 dc-emissor-status text-muted';
        });
        clone.querySelectorAll('select').forEach(function (select) {
            select.selectedIndex = 0;
        });
        clone.dataset.prazoDias = '';
        clone.dataset.taxaTotal = '';
        clone.dataset.valorLiquido = '';
        clone.dataset.arquivoCaminho = '';
        clone.dataset.arquivoNome = '';
        clone.dataset.tipoDocumento = 'CHEQUE';
    }

    btnAdd.addEventListener('click', function () {
        if (linhaAtiva) {
            linhaAtiva.scrollIntoView({behavior: 'smooth', block: 'center'});
            const primeiroCampo = linhaAtiva.querySelector('input, select, textarea');
            if (primeiroCampo) {
                primeiroCampo.focus();
            }
            return;
        }

        const primeira = container.querySelector('.documento-row');
        if (!primeira) {
            return;
        }
        const clone = primeira.cloneNode(true);
        limparLinhaDocumento(clone);
        clone.classList.add('documento-ativo');
        linhaAtiva = clone;
        container.insertBefore(clone, primeira);
        atualizarBotoes();
    });

    container.addEventListener('click', function (event) {
        if (event.target.classList.contains('btn-editar-doc')) {
            const linha = event.target.closest('.documento-row');
            if (linha) {
                container.insertBefore(linha, container.firstElementChild);
                linhaAtiva = linha;
                atualizarBotoes();
            }
            return;
        }

        if (event.target.classList.contains('btn-salvar-doc')) {
            const linha = event.target.closest('.documento-row');
            if (linha) {
                linhaAtiva = linha;
                if (!validarDocumentoAtivo()) {
                    return;
                }
                const continuar = document.getElementById('continuarEditando');
                if (continuar) {
                    continuar.value = '1';
                }
                const form = document.getElementById('formOperacao');
                if (form && typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else if (form) {
                    form.submit();
                }
            }
            return;
        }

        if (!event.target.classList.contains('btn-remover-doc')) {
            return;
        }
        const linhas = container.querySelectorAll('.documento-row');
        if (linhas.length <= 1) {
            return;
        }
        const linhaRemovida = event.target.closest('.documento-row');
        if (linhaRemovida === linhaAtiva) {
            linhaAtiva = null;
        }
        linhaRemovida.remove();
        atualizarBotoes();
    });

    container.addEventListener('input', function (event) {
        const linha = event.target.closest('.documento-row');
        if (linha) {
            atualizarResumoLinha(linha, Array.from(container.querySelectorAll('.documento-row')).indexOf(linha) + 1);
        }
    });

    container.addEventListener('change', function (event) {
        const linha = event.target.closest('.documento-row');
        if (linha) {
            if (event.target.matches('select[name="tipo_documento[]"]')) {
                linha.dataset.tipoDocumento = event.target.value === 'BOLETO' ? 'BOLETO' : 'CHEQUE';
            }
            atualizarResumoLinha(linha, Array.from(container.querySelectorAll('.documento-row')).indexOf(linha) + 1);
        }
    });

    function aplicarSugestoes(linha, dados, sobrescrever) {
        const numero = linha.querySelector('input[name="numero_documento[]"]');
        const cnpjCpf = linha.querySelector('input[name="cnpj_cpf_emissor[]"]');
        const nomeEmissor = linha.querySelector('input[name="nome_emissor[]"]');
        const valor = linha.querySelector('input[name="valor[]"]');
        const vencimento = linha.querySelector('input[name="data_vencimento[]"]');

        if (dados.numero_documento && numero && (sobrescrever || !numero.value.trim())) {
            numero.value = dados.numero_documento;
        }
        if (dados.cnpj_cpf_emissor && cnpjCpf && (sobrescrever || !cnpjCpf.value.trim())) {
            cnpjCpf.value = dados.cnpj_cpf_emissor;
            consultarEmissor(linha);
        }
        if (dados.nome_emissor && nomeEmissor && (sobrescrever || !nomeEmissor.value.trim())) {
            nomeEmissor.value = dados.nome_emissor;
        }
        if (dados.valor_formatado && valor && (sobrescrever || !valor.value.trim())) {
            valor.value = dados.valor_formatado;
        }
        if (dados.data_vencimento && vencimento && (sobrescrever || !vencimento.value.trim())) {
            vencimento.value = dados.data_vencimento;
        }
    }

    function montarResumoLeitura(dados) {
        const partes = [];
        if (dados.numero_documento) {
            partes.push('numero ' + dados.numero_documento);
        }
        if (dados.cnpj_cpf_emissor) {
            partes.push('CNPJ/CPF ' + dados.cnpj_cpf_emissor);
        }
        if (dados.nome_emissor) {
            partes.push('emissor ' + dados.nome_emissor);
        }
        if (dados.valor_formatado) {
            partes.push('valor R$ ' + dados.valor_formatado);
        }
        if (dados.data_vencimento) {
            const pedacos = dados.data_vencimento.split('-');
            partes.push('venc. ' + pedacos[2] + '/' + pedacos[1] + '/' + pedacos[0]);
        }
        return partes.join(' | ');
    }

    function escaparHtml(texto) {
        return String(texto).replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    function arquivoEhImagem(arquivo) {
        return arquivo && (
            (arquivo.type && arquivo.type.indexOf('image/') === 0)
            || /\.(jpe?g|png|webp|bmp|tiff?)$/i.test(arquivo.name || '')
        );
    }

    let promessaTesseract = null;
    function carregarTesseract() {
        if (window.Tesseract && typeof window.Tesseract.recognize === 'function') {
            return Promise.resolve(window.Tesseract);
        }
        if (promessaTesseract) {
            return promessaTesseract;
        }
        promessaTesseract = new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
            script.async = true;
            script.onload = function () {
                if (window.Tesseract && typeof window.Tesseract.recognize === 'function') {
                    resolve(window.Tesseract);
                } else {
                    reject(new Error('OCR indisponivel no navegador. Confira manualmente.'));
                }
            };
            script.onerror = function () {
                reject(new Error('OCR indisponivel no navegador. Confira manualmente.'));
            };
            document.head.appendChild(script);
        });
        return promessaTesseract;
    }

    function decimalOCR(valor) {
        valor = String(valor || '').trim().replace(/[R$\s]/g, '');
        if (!valor) {
            return null;
        }
        valor = valor.replace(/\./g, '').replace(',', '.');
        const numero = Number(valor);
        return Number.isFinite(numero) && numero > 0 ? numero : null;
    }

    function valorBR(numero) {
        return Number(numero).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function interpretarTextoOCR(texto) {
        texto = String(texto || '').replace(/\r\n|\r/g, '\n').replace(/[ \t]+/g, ' ');
        const textoPlano = texto.split('\n').map(function (linha) {
            return linha.trim();
        }).filter(Boolean).join(' ');

        const datas = [];
        const dataRegex = /\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})\b/g;
        let dataMatch;
        while ((dataMatch = dataRegex.exec(textoPlano)) !== null) {
            let ano = Number(dataMatch[3]);
            ano = ano < 100 ? 2000 + ano : ano;
            const mes = Number(dataMatch[2]);
            const dia = Number(dataMatch[1]);
            if (mes >= 1 && mes <= 12 && dia >= 1 && dia <= 31) {
                datas.push(String(ano).padStart(4, '0') + '-' + String(mes).padStart(2, '0') + '-' + String(dia).padStart(2, '0'));
            }
        }
        datas.sort();

        const valores = [];
        const valorRegex = /(?:R\$\s*)?(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2})\b/g;
        let valorMatch;
        while ((valorMatch = valorRegex.exec(textoPlano)) !== null) {
            const numero = decimalOCR(valorMatch[1]);
            if (numero && numero < 100000000) {
                valores.push(numero);
            }
        }

        let numeroDocumento = null;
        const linhaDigitavel = textoPlano.match(/\b(\d[\d .-]{42,60}\d)\b/);
        if (linhaDigitavel) {
            numeroDocumento = linhaDigitavel[1].replace(/\D+/g, '');
        }
        if (!numeroDocumento) {
            const rotuloNumero = textoPlano.match(/(?:cheque|boleto|documento|doc\.?|numero|no\.?|n\.?)[^\d]{0,20}(\d{4,20})/i);
            if (rotuloNumero) {
                numeroDocumento = rotuloNumero[1];
            }
        }

        let cnpjCpf = null;
        const cpf = textoPlano.match(/\b(\d{3}\.?\d{3}\.?\d{3}-?\d{2})\b/);
        const cnpj = textoPlano.match(/\b(\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2})\b/);
        if (cpf) {
            cnpjCpf = cpf[1].replace(/\D+/g, '');
        } else if (cnpj) {
            cnpjCpf = cnpj[1].replace(/\D+/g, '');
        }

        let nomeEmissor = null;
        const nomeMatch = textoPlano.match(/(?:emissor|emitente|sacado|pagador|cliente|cedente|beneficiario)\s*[:\-]?\s*([A-Z0-9][A-Z0-9 .,&\-]{4,120})/i);
        if (nomeMatch) {
            nomeEmissor = nomeMatch[1].replace(/\s+/g, ' ').replace(/\s+(CPF|CNPJ|VALOR|VENCIMENTO|DATA|DOCUMENTO|NUMERO|N)\b.*$/i, '').trim();
        }

        const maiorValor = valores.length ? Math.max.apply(null, valores) : null;
        return {
            numero_documento: numeroDocumento,
            cnpj_cpf_emissor: cnpjCpf,
            nome_emissor: nomeEmissor || null,
            valor: maiorValor,
            valor_formatado: maiorValor ? valorBR(maiorValor) : null,
            data_vencimento: datas.length ? datas[datas.length - 1] : null,
            avisos: []
        };
    }

    function temSugestaoLeitura(dados) {
        return !!(dados && (dados.numero_documento || dados.cnpj_cpf_emissor || dados.nome_emissor || dados.valor_formatado || dados.data_vencimento));
    }

    function lerImagemNoCelular(linha, arquivo, status) {
        status.className = 'small mt-1 dc-leitura-status text-muted';
        status.textContent = 'Carregando OCR para tentar ler a foto...';

        return carregarTesseract().then(function (Tesseract) {
            status.textContent = 'Tentando OCR no celular. A primeira leitura pode demorar...';
            return Tesseract.recognize(arquivo, 'por+eng', {
            logger: function (mensagem) {
                if (mensagem && mensagem.status === 'recognizing text' && mensagem.progress) {
                    status.textContent = 'Lendo foto no celular... ' + Math.round(mensagem.progress * 100) + '%';
                }
            }
            });
        }).then(function (resultado) {
            const texto = resultado && resultado.data ? resultado.data.text : '';
            const dados = interpretarTextoOCR(texto);
            aplicarSugestoes(linha, dados, false);
            const resumo = montarResumoLeitura(dados);
            if (resumo) {
                status.className = 'small mt-1 dc-leitura-status text-success';
                status.innerHTML = 'Leitura pelo celular: ' + escaparHtml(resumo);
            } else {
                status.className = 'small mt-1 dc-leitura-status text-warning';
                status.textContent = 'OCR do celular nao identificou os dados. Confira manualmente.';
            }
        }).catch(function () {
            status.className = 'small mt-1 dc-leitura-status text-warning';
            status.textContent = 'Nao foi possivel ler a foto no celular. Confira manualmente.';
        });
    }

    function statusEmissor(linha) {
        return linha ? linha.querySelector('.dc-emissor-status') : null;
    }

    function documentoIdLinha(linha) {
        const input = linha ? linha.querySelector('input[name="documento_id[]"]') : null;
        return input ? input.value || '0' : '0';
    }

    function numeroParaMoedaBR(valor) {
        return 'R$ ' + Number(valor || 0).toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function valorDecimalBR(valor) {
        const texto = String(valor || '').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
        const numero = Number(texto);
        return Number.isFinite(numero) ? numero : 0;
    }

    function variantesCnpjCpf(digitos) {
        const variantes = [digitos];
        const match = String(digitos || '').match(/^(\d{8})\d(0001\d{2})$/);
        if (match) {
            variantes.push(match[1] + match[2]);
        }
        return Array.from(new Set(variantes));
    }

    function documentosGridMesmoEmissor(linhaAtual, digitos) {
        const documentos = [];
        let nomeEmissor = '';
        const variantes = variantesCnpjCpf(digitos);
        container.querySelectorAll('.documento-row').forEach(function (linha) {
            if (linha === linhaAtual) {
                return;
            }

            const cnpjCpf = valorInput(linha, 'input[name="cnpj_cpf_emissor[]"]').replace(/\D+/g, '');
            if (!variantes.includes(cnpjCpf)) {
                return;
            }

            if (!nomeEmissor) {
                nomeEmissor = valorInput(linha, 'input[name="nome_emissor[]"]');
            }

            const valor = valorDecimalBR(valorInput(linha, 'input[name="valor[]"]'));
            const vencimento = valorInput(linha, 'input[name="data_vencimento[]"]');
            if (valor <= 0 || !vencimento) {
                return;
            }

            documentos.push({
                operacao_id: 'Atual',
                numero_documento: valorInput(linha, 'input[name="numero_documento[]"]'),
                data_vencimento_br: dataBR(vencimento),
                valor_formatado: numeroParaMoedaBR(valor),
                valor: valor
            });
        });

        return {
            documentos: documentos,
            nome_emissor: nomeEmissor
        };
    }

    function consultarEmissor(linha) {
        const input = linha ? linha.querySelector('input[name="cnpj_cpf_emissor[]"]') : null;
        const status = statusEmissor(linha);
        if (!input || !status) {
            return;
        }

        const digitos = input.value.replace(/\D+/g, '');
        if (digitos.length < 11) {
            status.textContent = '';
            status.className = 'small mt-1 dc-emissor-status text-muted';
            return;
        }

        status.className = 'small mt-1 dc-emissor-status text-muted';
        status.textContent = 'Consultando titulos a vencer deste emissor...';
        const resumoGrid = documentosGridMesmoEmissor(linha, digitos);
        const documentosGrid = resumoGrid.documentos || [];

        const params = new URLSearchParams({
            cnpj_cpf: digitos,
            documento_id: documentoIdLinha(linha)
        });

        fetch('consultar_emissor.php?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json().then(function (json) {
                    if (!response.ok) {
                        throw new Error(json.erro || 'Falha na consulta do emissor.');
                    }
                    return json;
                });
            })
            .then(function (dados) {
                const documentosBanco = Array.isArray(dados.documentos) ? dados.documentos : [];
                const campoNome = linha.querySelector('input[name="nome_emissor[]"]');
                const nomeSugerido = dados.nome_emissor || resumoGrid.nome_emissor || '';
                if (campoNome && !campoNome.value.trim() && nomeSugerido) {
                    campoNome.value = nomeSugerido;
                    atualizarResumoLinha(linha, Array.from(container.querySelectorAll('.documento-row')).indexOf(linha) + 1);
                }

                const quantidadeBanco = Number(dados.quantidade || 0);
                const totalBanco = Number(dados.valor_total || 0);
                const quantidadeGrid = documentosGrid.length;
                const totalGrid = documentosGrid.reduce(function (total, doc) {
                    return total + Number(doc.valor || 0);
                }, 0);
                const quantidadeTotal = quantidadeBanco + quantidadeGrid;
                const valorTotal = totalBanco + totalGrid;

                if (!quantidadeTotal) {
                    status.className = 'small mt-1 dc-emissor-status text-success';
                    status.textContent = nomeSugerido
                        ? 'Emissor localizado: ' + nomeSugerido + '. Nenhum cheque/boleto a vencer ja cadastrado.'
                        : 'Nenhum cheque/boleto a vencer ja cadastrado para este emissor.';
                    return;
                }

                const detalhes = documentosBanco.concat(documentosGrid).slice(0, 6)
                    .map(function (doc) {
                        const numero = doc.numero_documento ? ' ' + doc.numero_documento : '';
                        return '#' + doc.operacao_id + numero + ' - ' + doc.data_vencimento_br + ' - ' + doc.valor_formatado;
                    }).join('; ');

                status.className = 'small mt-1 dc-emissor-status text-warning';
                status.textContent = 'Ja existem ' + quantidadeTotal + ' documento(s) a vencer deste emissor, total ' + numeroParaMoedaBR(valorTotal) + (detalhes ? '. ' + detalhes : '') + '.';
            })
            .catch(function (erro) {
                status.className = 'small mt-1 dc-emissor-status text-warning';
                status.textContent = erro.message || 'Nao foi possivel consultar titulos do emissor.';
            });
    }

    const timersEmissor = new WeakMap();
    container.addEventListener('input', function (event) {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.name !== 'cnpj_cpf_emissor[]') {
            return;
        }

        const linha = input.closest('.documento-row');
        if (!linha) {
            return;
        }

        const timerAtual = timersEmissor.get(input);
        if (timerAtual) {
            clearTimeout(timerAtual);
        }
        timersEmissor.set(input, setTimeout(function () {
            consultarEmissor(linha);
        }, 500));
    });

    container.addEventListener('change', function (event) {
        const input = event.target;
        if (input instanceof HTMLInputElement && input.name === 'cnpj_cpf_emissor[]') {
            consultarEmissor(input.closest('.documento-row'));
        }
    });

    container.addEventListener('change', function (event) {
        const input = event.target;
        if (
            !(input instanceof HTMLInputElement)
            || input.type !== 'file'
            || !['arquivo_documento[]', 'arquivo_frente[]'].includes(input.name)
        ) {
            return;
        }

        const arquivo = input.files && input.files[0] ? input.files[0] : null;
        const linha = input.closest('.documento-row');
        const blocoArquivo = input.closest('.dc-doc-file, .dc-doc-file-cheque, .dc-doc-file-generico');
        const status = blocoArquivo ? blocoArquivo.querySelector('.dc-leitura-status') : null;
        if (!arquivo || !linha || !status) {
            return;
        }

        status.className = 'small mt-1 dc-leitura-status text-muted';
        status.textContent = 'Lendo arquivo...';

        const formData = new FormData();
        formData.append('arquivo', arquivo);

        fetch('ler_documento.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json().then(function (json) {
                    if (!response.ok) {
                        throw new Error(json.erro || 'Falha na leitura do arquivo.');
                    }
                    return json;
                });
            })
            .then(function (dados) {
                aplicarSugestoes(linha, dados, false);
                const resumo = montarResumoLeitura(dados);
                const avisos = Array.isArray(dados.avisos) && dados.avisos.length ? ' ' + dados.avisos.join(' ') : '';
                if (resumo) {
                    status.className = 'small mt-1 dc-leitura-status text-success';
                    status.innerHTML = 'Leitura: ' + escaparHtml(resumo + avisos);
                } else if (arquivoEhImagem(arquivo)) {
                    return lerImagemNoCelular(linha, arquivo, status);
                } else {
                    status.className = 'small mt-1 dc-leitura-status text-warning';
                    status.textContent = 'Nao consegui identificar valor, vencimento ou numero. Confira manualmente.' + avisos;
                }
            })
            .catch(function (erro) {
                status.className = 'small mt-1 dc-leitura-status text-warning';
                status.textContent = erro.message || 'Nao foi possivel ler o arquivo. Preencha manualmente.';
            });
    });

    atualizarBotoes();
});
</script>
<?php endif; ?>

<?php require '../../layout/footer.php'; ?>
