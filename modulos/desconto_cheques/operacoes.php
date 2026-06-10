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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $valores = $_POST['valor'] ?? [];
    $vencimentos = $_POST['data_vencimento'] ?? [];
    $arquivos = $_FILES['arquivo_documento'] ?? null;
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

    if (!isset($clientesPorId[$clienteId])) {
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

            $arquivoLinha = ['error' => UPLOAD_ERR_NO_FILE];
            if (is_array($arquivos) && isset($arquivos['name'][$idx])) {
                $arquivoLinha = [
                    'name' => $arquivos['name'][$idx],
                    'type' => $arquivos['type'][$idx] ?? '',
                    'tmp_name' => $arquivos['tmp_name'][$idx] ?? '',
                    'error' => $arquivos['error'][$idx] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $arquivos['size'][$idx] ?? 0,
                ];
            }

            $documentos[] = [
                'id' => (int)($documentoIds[$idx] ?? 0),
                'tipo_documento' => in_array(($tipos[$idx] ?? 'CHEQUE'), ['CHEQUE', 'BOLETO'], true) ? $tipos[$idx] : 'CHEQUE',
                'numero_documento' => trim((string)($numeros[$idx] ?? '')),
                'valor' => $valor,
                'data_vencimento' => $vencimento,
                'arquivo' => $arquivoLinha,
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
                        (operacao_id, tipo_documento, numero_documento, arquivo_nome, arquivo_caminho, valor,
                         data_vencimento, data_compensacao, prazo_dias, taxa_cliente, adicional_percentual,
                         adicional_valor, desconto_valor, valor_liquido)
                    VALUES (?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtDocUpdate = $pdo_master->prepare("
                    UPDATE desconto_cheques_documentos
                    SET tipo_documento = ?,
                        numero_documento = NULLIF(?, ''),
                        arquivo_nome = ?,
                        arquivo_caminho = ?,
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
                    if ($docId > 0 && isset($docsAtuaisPorId[$docId]) && !$upload['caminho']) {
                        $upload['nome'] = $docsAtuaisPorId[$docId]['arquivo_nome'];
                        $upload['caminho'] = $docsAtuaisPorId[$docId]['arquivo_caminho'];
                    }
                    $calculo = $documento['calculo'];
                    if ($docId > 0 && isset($docsAtuaisPorId[$docId])) {
                        $stmtDocUpdate->execute([
                            $documento['tipo_documento'],
                            $documento['numero_documento'],
                            $upload['nome'],
                            $upload['caminho'],
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
                            $upload['nome'],
                            $upload['caminho'],
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
                header('Location: operacoes.php?ok=' . ($operacaoEditarId > 0 ? 'edit' : '1') . '&id=' . $operacaoId);
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
$where = ['o.empresa_id = ?', 'o.data_referencia BETWEEN ? AND ?'];
$params = [$empresaId, $dataIni, $dataFim];

if ($filtroCliente > 0) {
    $where[] = 'o.cliente_id = ?';
    $params[] = $filtroCliente;
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

$documentosPorOperacao = [];
if (!empty($operacoes)) {
    $ids = array_column($operacoes, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtDocs = $pdo_master->prepare("
        SELECT *
        FROM desconto_cheques_documentos
        WHERE operacao_id IN ($placeholders)
        ORDER BY operacao_id DESC, data_vencimento, id
    ");
    $stmtDocs->execute($ids);
    foreach ($stmtDocs->fetchAll(PDO::FETCH_ASSOC) as $doc) {
        $documentosPorOperacao[(int)$doc['operacao_id']][] = $doc;
    }
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
    'valor' => '',
    'data_vencimento' => '',
    'arquivo_nome' => '',
    'arquivo_caminho' => '',
]];

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

    .dc-doc-grid {
        display: grid;
        grid-template-columns: 130px 1fr 140px 150px minmax(180px, 1fr) auto;
        gap: .5rem;
        align-items: end;
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
    }

    @media (max-width: 575.98px) {
        .dc-doc-grid {
            grid-template-columns: 1fr;
        }

        .dc-actions .btn,
        .dc-main-actions .btn {
            width: 100%;
        }

        .dc-operation-table {
            min-width: 820px;
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
    <div class="alert alert-success">Operacao #<?= (int)($_GET['id'] ?? 0) ?> <?= $_GET['ok'] === 'edit' ? 'atualizada' : 'salva' ?> com sucesso.</div>
<?php endif; ?>
<?php if ($mensagemErro !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div>
<?php endif; ?>

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
                <form method="post" enctype="multipart/form-data" id="formOperacao" class="row g-3">
                    <input type="hidden" name="operacao_id" value="<?= (int)$formOperacao['id'] ?>">
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
                        <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-2">
                            <h2 class="h6 fw-bold mb-0">Documentos</h2>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddDocumento">Adicionar documento</button>
                        </div>
                        <div id="documentosContainer" class="d-flex flex-column gap-2">
                            <?php foreach ($formDocumentos as $docForm): ?>
                                <div class="dc-doc-card documento-row">
                                    <input type="hidden" name="documento_id[]" value="<?= (int)($docForm['id'] ?? 0) ?>">
                                    <div class="dc-doc-grid">
                                        <div>
                                            <label class="form-label small">Tipo</label>
                                            <select name="tipo_documento[]" class="form-select">
                                                <option value="CHEQUE" <?= ($docForm['tipo_documento'] ?? 'CHEQUE') === 'CHEQUE' ? 'selected' : '' ?>>Cheque</option>
                                                <option value="BOLETO" <?= ($docForm['tipo_documento'] ?? 'CHEQUE') === 'BOLETO' ? 'selected' : '' ?>>Boleto</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="form-label small">Numero</label>
                                            <input type="text" name="numero_documento[]" class="form-control" value="<?= htmlspecialchars((string)($docForm['numero_documento'] ?? '')) ?>">
                                        </div>
                                        <div>
                                            <label class="form-label small">Valor</label>
                                            <input type="text" name="valor[]" class="form-control" inputmode="decimal" required value="<?= ($docForm['valor'] ?? '') !== '' ? htmlspecialchars(number_format((float)$docForm['valor'], 2, ',', '.')) : '' ?>">
                                        </div>
                                        <div>
                                            <label class="form-label small">Vencimento</label>
                                            <input type="date" name="data_vencimento[]" class="form-control" required value="<?= htmlspecialchars((string)($docForm['data_vencimento'] ?? '')) ?>">
                                        </div>
                                        <div>
                                            <label class="form-label small">Foto/arquivo</label>
                                            <input type="file" name="arquivo_documento[]" class="form-control" accept="image/*,.pdf">
                                            <?php if (!empty($docForm['arquivo_caminho'])): ?>
                                                <div class="small mt-1">
                                                    <a target="_blank" href="../../<?= htmlspecialchars($docForm['arquivo_caminho']) ?>">Anexo atual</a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-outline-danger btn-remover-doc">Remover</button>
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
                        <button type="submit" class="btn btn-primary"><?= $operacaoEditar ? 'Atualizar operacao' : 'Salvar operacao' ?></button>
                        <?php if ($operacaoEditar): ?>
                            <a href="operacoes.php" class="btn btn-outline-secondary">Cancelar edicao</a>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<section>
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label small">Cliente</label>
                    <select name="cliente_id" class="form-select">
                        <option value="0">Todos</option>
                        <?php foreach ($clientes as $cliente): ?>
                            <option value="<?= (int)$cliente['id'] ?>" <?= $filtroCliente === (int)$cliente['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cliente['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small">Data inicial</label>
                    <input type="date" name="data_ini" class="form-control" value="<?= htmlspecialchars($dataIni) ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small">Data final</label>
                    <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($dataFim) ?>">
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-outline-primary">Filtrar</button>
                </div>
            </form>
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
                                <a href="operacoes.php?editar=<?= (int)$operacao['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                <a href="operacao_pdf.php?id=<?= (int)$operacao['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger">PDF</a>
                            </td>
                        </tr>
                        <?php foreach (($documentosPorOperacao[(int)$operacao['id']] ?? []) as $doc): ?>
                            <tr>
                                <td colspan="2">
                                    <?= htmlspecialchars($doc['tipo_documento']) ?>
                                    <?= $doc['numero_documento'] ? ' - ' . htmlspecialchars($doc['numero_documento']) : '' ?>
                                    <?php if ($doc['arquivo_caminho']): ?>
                                        <a class="ms-2 small" target="_blank" href="../../<?= htmlspecialchars($doc['arquivo_caminho']) ?>">Anexo</a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    Venc. <?= dataBRDC($doc['data_vencimento']) ?>
                                    <span class="text-muted">| comp. <?= dataBRDC($doc['data_compensacao']) ?></span>
                                </td>
                                <td class="text-end"><?= moedaDC($doc['valor']) ?></td>
                                <td class="text-end"><?= moedaDC($doc['desconto_valor']) ?></td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end"><?= moedaDC($doc['valor_liquido']) ?></td>
                                <td>
                                    <strong><?= (int)$doc['prazo_dias'] ?> dias</strong>
                                    <div class="small text-muted">Taxa total <?= percentualDC(taxaTotalDocumentoDC($doc)) ?></div>
                                </td>
                                <td></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <?php if (empty($operacoes)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">Nenhuma operacao encontrada.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('documentosContainer');
    const btnAdd = document.getElementById('btnAddDocumento');
    if (!container || !btnAdd) {
        return;
    }

    function atualizarBotoes() {
        const linhas = container.querySelectorAll('.documento-row');
        linhas.forEach(function (linha) {
            const botao = linha.querySelector('.btn-remover-doc');
            if (botao) {
                botao.disabled = linhas.length === 1;
            }
        });
    }

    btnAdd.addEventListener('click', function () {
        const primeira = container.querySelector('.documento-row');
        const clone = primeira.cloneNode(true);
        clone.querySelectorAll('input').forEach(function (input) {
            input.value = '';
        });
        clone.querySelectorAll('select').forEach(function (select) {
            select.selectedIndex = 0;
        });
        container.appendChild(clone);
        atualizarBotoes();
    });

    container.addEventListener('click', function (event) {
        if (!event.target.classList.contains('btn-remover-doc')) {
            return;
        }
        const linhas = container.querySelectorAll('.documento-row');
        if (linhas.length <= 1) {
            return;
        }
        event.target.closest('.documento-row').remove();
        atualizarBotoes();
    });

    atualizarBotoes();
});
</script>

<?php require '../../layout/footer.php'; ?>
