<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_cartao_credito_lib.php';

$empresaSessao = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$nivel = strtoupper((string)($_SESSION['nivel'] ?? ''));

if ($nivel !== 'MASTER') {
    renderizarAcessoNegadoModulo('A rotina de cartao de credito esta liberada somente para perfil MASTER.');
}

garantirTabelasCartaoCredito($pdo_master);

$mensagemOk = '';
$mensagemErro = '';
$faturaId = (int)($_GET['fatura_id'] ?? $_POST['fatura_id'] ?? 0);
$competenciaFiltro = trim((string)($_GET['competencia'] ?? date('Y-m')));
$empresaSelecionada = $empresaSessao;

function voltarCartaoCredito(array $extra = []): string
{
    $base = [
        'competencia' => $_POST['competencia'] ?? $_GET['competencia'] ?? date('Y-m'),
        'fatura_id' => $_POST['fatura_id'] ?? $_GET['fatura_id'] ?? '',
    ];
    foreach ($extra as $k => $v) {
        $base[$k] = $v;
    }
    $base = array_filter($base, function ($v) {
        return $v !== '' && $v !== null;
    });
    return 'cartao_credito.php' . ($base ? '?' . http_build_query($base) : '');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'importar') {
            $empresaImportacao = $empresaSessao;
            $competencia = trim((string)($_POST['competencia'] ?? ''));
            $dataVencimento = trim((string)($_POST['data_vencimento'] ?? ''));
            $cartaoNome = trim((string)($_POST['cartao_nome'] ?? ''));

            if ($empresaImportacao <= 0 || !preg_match('/^\d{4}-\d{2}$/', $competencia) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataVencimento) || $cartaoNome === '') {
                throw new RuntimeException('Informe empresa, competencia, vencimento e nome do cartao.');
            }
            if (empty($_FILES['arquivo_csv']['tmp_name']) || !is_uploaded_file($_FILES['arquivo_csv']['tmp_name'])) {
                throw new RuntimeException('Selecione o arquivo CSV da fatura.');
            }

            $linhas = lerCsvFaturaCartao($_FILES['arquivo_csv']['tmp_name']);
            if (empty($linhas)) {
                throw new RuntimeException('Nenhuma linha valida foi encontrada no CSV.');
            }

            $pdo_master->beginTransaction();

            $totalCompras = 0.0;
            $totalPagamentos = 0.0;
            foreach ($linhas as $linha) {
                if ($linha['natureza'] === 'D') {
                    $totalCompras += (float)$linha['valor'];
                } else {
                    $totalPagamentos += (float)$linha['valor'];
                }
            }

            $stmtFatura = $pdo_master->prepare("
                INSERT INTO financeiro_cartao_faturas (
                    empresa_id, competencia, data_vencimento, cartao_nome, nome_arquivo,
                    total_compras, total_pagamentos, total_liquido, total_linhas, usuario_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtFatura->execute([
                $empresaImportacao,
                $competencia,
                $dataVencimento,
                $cartaoNome,
                $_FILES['arquivo_csv']['name'] ?? null,
                $totalCompras,
                $totalPagamentos,
                $totalCompras - $totalPagamentos,
                count($linhas),
                $usuarioId ?: null,
            ]);
            $novaFaturaId = (int)$pdo_master->lastInsertId();

            $stmtLanc = $pdo_master->prepare("
                INSERT IGNORE INTO financeiro_cartao_lancamentos (
                    fatura_id, empresa_id, data_compra, descricao, categoria, tipo_lancamento,
                    valor, natureza, hash_linha
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($linhas as $linha) {
                $stmtLanc->execute([
                    $novaFaturaId,
                    $empresaImportacao,
                    $linha['data_compra'],
                    $linha['descricao'],
                    $linha['categoria'],
                    $linha['tipo_lancamento'],
                    $linha['valor'],
                    $linha['natureza'],
                    $linha['hash_linha'],
                ]);
            }

            aplicarMapeamentosCartaoCredito($pdo_master, $novaFaturaId);
            $pdo_master->commit();

            header('Location: cartao_credito.php?competencia=' . urlencode($competencia) . '&fatura_id=' . $novaFaturaId . '&ok=importado');
            exit;
        }

        if ($acao === 'mapear_fornecedor') {
            $lancamentoId = (int)($_POST['lancamento_id'] ?? 0);
            $fcontador = (int)($_POST['fcontador'] ?? 0);
            $novoFornecedor = trim((string)($_POST['novo_fornecedor'] ?? ''));

            $stmtLinha = $pdo_master->prepare("SELECT id, empresa_id, descricao FROM financeiro_cartao_lancamentos WHERE id = ? LIMIT 1");
            $stmtLinha->execute([$lancamentoId]);
            $linha = $stmtLinha->fetch(PDO::FETCH_ASSOC);
            if (!$linha) {
                throw new RuntimeException('Lancamento nao encontrado.');
            }

            $empresaLinha = (int)$linha['empresa_id'];
            if ($fcontador <= 0 && $novoFornecedor !== '') {
                $fcontador = criarFornecedorCartaoCredito($pdo_master, $empresaLinha, $novoFornecedor, $usuarioId);
            }
            if ($fcontador <= 0 || !fornecedorCartaoExiste($pdo_master, $empresaLinha, $fcontador)) {
                throw new RuntimeException('Fornecedor invalido.');
            }

            salvarMapeamentoFornecedorCartao($pdo_master, $empresaLinha, $linha['descricao'], $fcontador);
            $pdo_master->prepare("
                UPDATE financeiro_cartao_lancamentos
                SET fornecedor_fcontador = ?
                WHERE empresa_id = ?
                  AND descricao = ?
                  AND fornecedor_fcontador IS NULL
            ")->execute([$fcontador, $empresaLinha, $linha['descricao']]);

            header('Location: ' . voltarCartaoCredito(['ok' => 'fornecedor']));
            exit;
        }

        if ($acao === 'mapear_fornecedor_lote') {
            $faturaPost = (int)($_POST['fatura_id'] ?? 0);
            $novosFornecedores = $_POST['novo_fornecedor_lote'] ?? [];
            $novosFornecedores = is_array($novosFornecedores) ? $novosFornecedores : [];
            $fornecedoresExistentes = $_POST['fcontador_lote'] ?? [];
            $fornecedoresExistentes = is_array($fornecedoresExistentes) ? $fornecedoresExistentes : [];
            $criados = 0;
            $amarrados = 0;

            $stmtLinha = $pdo_master->prepare("
                SELECT id, empresa_id, descricao, fornecedor_fcontador, cpcontador, natureza
                FROM financeiro_cartao_lancamentos
                WHERE id = ?
                  AND fatura_id = ?
                LIMIT 1
            ");

            foreach ($novosFornecedores as $lancamentoId => $nomeFornecedor) {
                $lancamentoId = (int)$lancamentoId;
                if ($lancamentoId <= 0) {
                    continue;
                }

                $stmtLinha->execute([$lancamentoId, $faturaPost]);
                $linha = $stmtLinha->fetch(PDO::FETCH_ASSOC);
                if (!$linha || $linha['natureza'] !== 'D' || !empty($linha['fornecedor_fcontador']) || !empty($linha['cpcontador'])) {
                    continue;
                }

                $empresaLinha = (int)$linha['empresa_id'];
                $fcontador = (int)($fornecedoresExistentes[$lancamentoId] ?? 0);
                if ($fcontador > 0) {
                    if (!fornecedorCartaoExiste($pdo_master, $empresaLinha, $fcontador)) {
                        continue;
                    }
                    $amarrados++;
                } else {
                    $nomeFornecedor = trim((string)$nomeFornecedor);
                    if ($nomeFornecedor === '') {
                        continue;
                    }
                    $fcontador = criarFornecedorCartaoCredito($pdo_master, $empresaLinha, $nomeFornecedor, $usuarioId);
                    $criados++;
                }

                salvarMapeamentoFornecedorCartao($pdo_master, $empresaLinha, $linha['descricao'], $fcontador);
                $pdo_master->prepare("
                    UPDATE financeiro_cartao_lancamentos
                    SET fornecedor_fcontador = ?
                    WHERE id = ?
                ")->execute([$fcontador, $lancamentoId]);
            }

            header('Location: ' . voltarCartaoCredito(['ok' => 'fornecedor_lote', 'criados' => $criados, 'amarrados' => $amarrados]));
            exit;
        }

        if ($acao === 'gerar_cp001') {
            $lancamentoId = (int)($_POST['lancamento_id'] ?? 0);
            gerarCp001CartaoCredito($pdo_master, $lancamentoId, $usuarioId);
            header('Location: ' . voltarCartaoCredito(['ok' => 'cp001']));
            exit;
        }

        if ($acao === 'gerar_cp001_lote') {
            $stmtPendentes = $pdo_master->prepare("
                SELECT id
                FROM financeiro_cartao_lancamentos
                WHERE fatura_id = ?
                  AND natureza = 'D'
                  AND cpcontador IS NULL
                  AND fornecedor_fcontador IS NOT NULL
                ORDER BY data_compra, id
            ");
            $stmtPendentes->execute([$faturaId]);
            $gerados = 0;
            foreach ($stmtPendentes->fetchAll(PDO::FETCH_ASSOC) as $linha) {
                gerarCp001CartaoCredito($pdo_master, (int)$linha['id'], $usuarioId);
                $gerados++;
            }
            header('Location: ' . voltarCartaoCredito(['ok' => 'lote', 'gerados' => $gerados]));
            exit;
        }
    }
} catch (Throwable $e) {
    if ($pdo_master->inTransaction()) {
        $pdo_master->rollBack();
    }
    $mensagemErro = $e->getMessage();
}

if (($_GET['ok'] ?? '') === 'importado') {
    $mensagemOk = 'Fatura importada para conferencia.';
} elseif (($_GET['ok'] ?? '') === 'fornecedor') {
    $mensagemOk = 'Fornecedor vinculado ao lancamento.';
} elseif (($_GET['ok'] ?? '') === 'cp001') {
    $mensagemOk = 'Lancamento gerado em contas a pagar.';
} elseif (($_GET['ok'] ?? '') === 'lote') {
    $mensagemOk = 'Lancamentos gerados em contas a pagar: ' . (int)($_GET['gerados'] ?? 0);
} elseif (($_GET['ok'] ?? '') === 'fornecedor_lote') {
    $mensagemOk = 'Fornecedores amarrados: ' . (int)($_GET['amarrados'] ?? 0) . ' | Criados: ' . (int)($_GET['criados'] ?? 0);
}

$fornecedores = buscarFornecedoresCartaoCredito($pdo_master, $empresaSelecionada);

$stmtFaturas = $pdo_master->prepare("
    SELECT f.*,
           SUM(CASE WHEN l.natureza = 'D' THEN 1 ELSE 0 END) AS qtd_compras,
           SUM(CASE WHEN l.natureza = 'C' THEN 1 ELSE 0 END) AS qtd_creditos,
           SUM(CASE WHEN l.natureza = 'D' AND l.cpcontador IS NOT NULL THEN 1 ELSE 0 END) AS qtd_cp
    FROM financeiro_cartao_faturas f
    LEFT JOIN financeiro_cartao_lancamentos l ON l.fatura_id = f.id
    WHERE f.empresa_id = ?
      AND f.competencia = ?
    GROUP BY f.id
    ORDER BY f.criado_em DESC
");
$stmtFaturas->execute([$empresaSelecionada, $competenciaFiltro]);
$faturas = $stmtFaturas->fetchAll(PDO::FETCH_ASSOC);

$faturaAtual = null;
$lancamentos = [];
$resumoFaturaAtual = [
    'compras' => 0,
    'creditos' => 0,
    'pendentes' => 0,
    'prontos' => 0,
    'gerados' => 0,
];
if ($faturaId > 0) {
    $stmtFaturaAtual = $pdo_master->prepare("SELECT * FROM financeiro_cartao_faturas WHERE id = ? AND empresa_id = ? LIMIT 1");
    $stmtFaturaAtual->execute([$faturaId, $empresaSelecionada]);
    $faturaAtual = $stmtFaturaAtual->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($faturaAtual) {
        aplicarMapeamentosCartaoCredito($pdo_master, $faturaId);
        $stmtLancamentos = $pdo_master->prepare("
            SELECT l.*, f.NOME AS fornecedor_nome, f.APELIDO AS fornecedor_apelido
            FROM financeiro_cartao_lancamentos l
            LEFT JOIN armazem_cp003 f
                ON f.EMPRESA = l.empresa_id
               AND f.FCONTADOR = l.fornecedor_fcontador
            WHERE l.fatura_id = ?
            ORDER BY l.natureza DESC, l.data_compra, l.id
        ");
        $stmtLancamentos->execute([$faturaId]);
        $lancamentos = $stmtLancamentos->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lancamentos as $linhaResumo) {
            if ($linhaResumo['natureza'] === 'D') {
                $resumoFaturaAtual['compras']++;
                if (!empty($linhaResumo['cpcontador'])) {
                    $resumoFaturaAtual['gerados']++;
                } elseif (!empty($linhaResumo['fornecedor_fcontador'])) {
                    $resumoFaturaAtual['prontos']++;
                } else {
                    $resumoFaturaAtual['pendentes']++;
                }
            } else {
                $resumoFaturaAtual['creditos']++;
            }
        }
    }
}

require '../../layout/header.php';
?>

<style>
    .cartao-panel {
        border: 1px solid #dbe3ef;
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .06);
    }
    .cartao-panel .card-header {
        border-bottom-color: #e6edf5;
    }
    .cartao-kpi {
        border: 1px solid #dbe3ef;
        border-radius: 8px;
        padding: .85rem 1rem;
        background: #fff;
        height: 100%;
    }
    .cartao-kpi .small { color: #64748b; }
    .cartao-kpi .h5 { color: #1f2a37; }
    .cartao-table { min-width: 1040px; }
    .cartao-status { white-space: nowrap; }
    .cartao-desc { min-width: 260px; }
    .cartao-fornecedor-input { min-width: 280px; }
    .cartao-money { font-variant-numeric: tabular-nums; }
    .cartao-section-title {
        font-size: .82rem;
        letter-spacing: .02em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: .45rem;
    }
    @media (max-width: 768px) {
        .cartao-actions { display: grid; gap: .5rem; }
        .cartao-actions .btn { width: 100%; }
        .cartao-panel .card-body { padding: 1rem; }
        .cartao-table {
            min-width: 0;
            border-collapse: separate;
            border-spacing: 0 .75rem;
        }
        .cartao-table thead { display: none; }
        .cartao-table,
        .cartao-table tbody,
        .cartao-table tr,
        .cartao-table td {
            display: block;
            width: 100%;
        }
        .cartao-table tr {
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            background: #fff;
            padding: .75rem;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .05);
        }
        .cartao-table tr.table-secondary { background: #f8fafc; }
        .cartao-table td {
            border: 0;
            padding: .25rem 0;
        }
        .cartao-table td::before {
            content: attr(data-label);
            display: block;
            font-size: .72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: .1rem;
        }
        .cartao-table td.text-end { text-align: left !important; }
        .cartao-desc,
        .cartao-fornecedor-input { min-width: 0; }
    }
</style>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Financeiro</span>
                <h1 class="h3 fw-bold mb-2">Cartao de Credito</h1>
                <p class="text-muted mb-0">Importe a fatura, confira fornecedores e gere os lancamentos em contas a pagar.</p>
            </div>
            <div class="col-lg-4 text-lg-end cartao-actions">
                <a href="menu_financeiro.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagemOk): ?><div class="alert alert-success"><?= htmlspecialchars($mensagemOk) ?></div><?php endif; ?>
<?php if ($mensagemErro): ?><div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div><?php endif; ?>

<section class="mb-4">
    <div class="card cartao-panel">
        <div class="card-header bg-white fw-semibold">Importar fatura</div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <input type="hidden" name="acao" value="importar">
                <div class="col-md-2">
                    <label class="form-label">Competencia</label>
                    <input type="month" name="competencia" class="form-control" value="<?= htmlspecialchars($competenciaFiltro) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Vencimento</label>
                    <input type="date" name="data_vencimento" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cartao</label>
                    <input type="text" name="cartao_nome" class="form-control" value="INTER" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">CSV da fatura</label>
                    <input type="file" name="arquivo_csv" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100">Importar</button>
                </div>
            </form>
        </div>
    </div>
</section>

<section class="mb-4">
    <div class="card cartao-panel">
        <div class="card-header bg-white fw-semibold">Faturas importadas</div>
        <div class="card-body">
            <form method="get" class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label">Competencia</label>
                    <input type="month" name="competencia" class="form-control" value="<?= htmlspecialchars($competenciaFiltro) ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-outline-primary w-100">Filtrar</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Cartao</th>
                            <th>Competencia</th>
                            <th>Vencimento</th>
                            <th class="text-end">Compras</th>
                            <th class="text-end">Pagamentos</th>
                            <th class="text-end">Liquido</th>
                            <th class="text-center">CP gerados</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($faturas as $fatura): ?>
                            <tr>
                                <td><?= (int)$fatura['id'] ?></td>
                                <td><?= htmlspecialchars($fatura['cartao_nome']) ?></td>
                                <td><?= htmlspecialchars($fatura['competencia']) ?></td>
                                <td><?= dataCartaoCredito($fatura['data_vencimento']) ?></td>
                                <td class="text-end cartao-money"><?= moedaCartaoCredito($fatura['total_compras']) ?></td>
                                <td class="text-end text-muted cartao-money"><?= moedaCartaoCredito($fatura['total_pagamentos']) ?></td>
                                <td class="text-end fw-semibold cartao-money"><?= moedaCartaoCredito($fatura['total_liquido']) ?></td>
                                <td class="text-center"><?= (int)$fatura['qtd_cp'] ?> / <?= (int)$fatura['qtd_compras'] ?></td>
                                <td class="text-end">
                                    <a href="cartao_credito.php?competencia=<?= urlencode($competenciaFiltro) ?>&fatura_id=<?= (int)$fatura['id'] ?>" class="btn btn-sm btn-outline-primary">Abrir</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($faturas)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">Nenhuma fatura importada nesse filtro.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<?php if ($faturaAtual): ?>
<section>
    <div class="card cartao-panel">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Fatura #<?= (int)$faturaAtual['id'] ?></strong>
                <span class="text-muted">| <?= htmlspecialchars($faturaAtual['cartao_nome']) ?> | <?= htmlspecialchars($faturaAtual['competencia']) ?></span>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" form="form-fornecedores-lote" class="btn btn-outline-primary btn-sm">Salvar fornecedores</button>
                <form method="post" onsubmit="return confirm('Gerar CP001 para todos os lancamentos com fornecedor definido?');">
                    <input type="hidden" name="acao" value="gerar_cp001_lote">
                    <input type="hidden" name="fatura_id" value="<?= (int)$faturaAtual['id'] ?>">
                    <input type="hidden" name="competencia" value="<?= htmlspecialchars($competenciaFiltro) ?>">
                    <button class="btn btn-success btn-sm">Gerar CP001 em lote</button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <form method="post" id="form-fornecedores-lote">
                <input type="hidden" name="acao" value="mapear_fornecedor_lote">
                <input type="hidden" name="fatura_id" value="<?= (int)$faturaAtual['id'] ?>">
                <input type="hidden" name="competencia" value="<?= htmlspecialchars($competenciaFiltro) ?>">
            </form>
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3">
                    <div class="cartao-kpi">
                        <div class="small">Compras</div>
                        <div class="h5 fw-bold mb-0 cartao-money"><?= moedaCartaoCredito($faturaAtual['total_compras']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="cartao-kpi">
                        <div class="small">Pagamentos</div>
                        <div class="h5 fw-bold mb-0 text-muted cartao-money"><?= moedaCartaoCredito($faturaAtual['total_pagamentos']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="cartao-kpi">
                        <div class="small">Liquido</div>
                        <div class="h5 fw-bold mb-0 cartao-money"><?= moedaCartaoCredito($faturaAtual['total_liquido']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="cartao-kpi">
                        <div class="small">Status CP001</div>
                        <div class="h6 fw-bold mb-0">
                            <?= (int)$resumoFaturaAtual['gerados'] ?> gerados
                            <span class="text-muted">| <?= (int)$resumoFaturaAtual['pendentes'] ?> pend.</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="cartao-section-title">Lancamentos da fatura</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 cartao-table">
                    <thead class="table-light">
                        <tr>
                            <th>Data</th>
                            <th class="cartao-desc">Lancamento</th>
                            <th>Categoria</th>
                            <th>Tipo</th>
                            <th class="text-end">Valor</th>
                            <th>Fornecedor</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lancamentos as $linha): ?>
                            <?php $ehCompra = $linha['natureza'] === 'D'; ?>
                            <tr class="<?= $ehCompra ? '' : 'table-secondary' ?>">
                                <td data-label="Data"><?= dataCartaoCredito($linha['data_compra']) ?></td>
                                <td data-label="Lancamento" class="cartao-desc">
                                    <div class="fw-semibold"><?= htmlspecialchars($linha['descricao']) ?></div>
                                    <?php if (!$ehCompra): ?><small class="text-muted">Pagamento/credito da fatura. Nao gera CP001.</small><?php endif; ?>
                                </td>
                                <td data-label="Categoria"><?= htmlspecialchars($linha['categoria'] ?? '') ?></td>
                                <td data-label="Tipo"><?= htmlspecialchars($linha['tipo_lancamento'] ?? '') ?></td>
                                <td data-label="Valor" class="text-end cartao-money <?= $ehCompra ? '' : 'text-muted' ?>"><?= $ehCompra ? moedaCartaoCredito($linha['valor']) : '-' . moedaCartaoCredito($linha['valor']) ?></td>
                                <td data-label="Fornecedor">
                                    <?php if ($ehCompra): ?>
                                        <?php if (!empty($linha['fornecedor_fcontador'])): ?>
                                            <div class="small fw-semibold">
                                                <?= (int)$linha['fornecedor_fcontador'] ?> - <?= htmlspecialchars($linha['fornecedor_apelido'] ?: $linha['fornecedor_nome'] ?: 'Fornecedor') ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (empty($linha['cpcontador']) && empty($linha['fornecedor_fcontador'])): ?>
                                            <div class="cartao-fornecedor-input">
                                                <select name="fcontador_lote[<?= (int)$linha['id'] ?>]" form="form-fornecedores-lote" class="form-select form-select-sm mb-1">
                                                    <option value="">Fornecedor existente...</option>
                                                    <?php foreach ($fornecedores as $fornecedor): ?>
                                                        <option value="<?= (int)$fornecedor['FCONTADOR'] ?>">
                                                            <?= (int)$fornecedor['FCONTADOR'] ?> - <?= htmlspecialchars($fornecedor['nome']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="text" name="novo_fornecedor_lote[<?= (int)$linha['id'] ?>]" form="form-fornecedores-lote" class="form-control form-control-sm" value="<?= htmlspecialchars(sugerirNomeFornecedorCartao($linha['descricao'])) ?>" placeholder="Ou novo fornecedor">
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status" class="cartao-status">
                                    <?php if (!empty($linha['cpcontador'])): ?>
                                        <span class="badge text-bg-success">CP <?= (int)$linha['cpcontador'] ?></span>
                                    <?php elseif (!$ehCompra): ?>
                                        <span class="badge text-bg-secondary">Ignorado</span>
                                    <?php elseif (!empty($linha['fornecedor_fcontador'])): ?>
                                        <span class="badge text-bg-warning">Pronto</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-light text-dark border">Pendente</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Acao" class="text-end">
                                    <?php if ($ehCompra && empty($linha['cpcontador']) && !empty($linha['fornecedor_fcontador'])): ?>
                                        <form method="post" onsubmit="return confirm('Gerar este lancamento no CP001?');">
                                            <input type="hidden" name="acao" value="gerar_cp001">
                                            <input type="hidden" name="fatura_id" value="<?= (int)$faturaAtual['id'] ?>">
                                            <input type="hidden" name="competencia" value="<?= htmlspecialchars($competenciaFiltro) ?>">
                                            <input type="hidden" name="lancamento_id" value="<?= (int)$linha['id'] ?>">
                                            <button class="btn btn-sm btn-success">Gerar CP001</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($lancamentos)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">Nenhum lancamento nesta fatura.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php require '../../layout/footer.php'; ?>
