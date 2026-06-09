<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

garantirTabelasUnimed($pdo_master);

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$mensagemSucesso = '';
$mensagemErro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    try {
        if ($acao === 'upload_mensalidade') {
            $upload = salvarUploadUnimed($_FILES['arquivo_mensalidade'] ?? [], 'mensalidade');
            $resultado = importarFaturaMensalidadeUnimed($pdo_master, $empresaId, $upload['absoluto'], $upload['original']);
            $query = http_build_query([
                'fatura_id' => $resultado['fatura_id'],
                'ok' => 'mensalidade',
                'itens' => $resultado['itens'],
                'total' => number_format((float)$resultado['total'], 2, '.', ''),
            ]);
            header('Location: faturas.php?' . $query);
            exit;
        }

        if ($acao === 'upload_utilizacao') {
            $faturaDestinoId = (int)($_POST['fatura_destino_id'] ?? 0);
            $upload = salvarUploadUnimed($_FILES['arquivo_utilizacao'] ?? [], 'utilizacao');
            $resultado = importarFaturaUtilizacaoUnimed($pdo_master, $empresaId, $faturaDestinoId, $upload['absoluto'], $upload['original']);
            $query = http_build_query([
                'fatura_id' => $resultado['fatura_id'],
                'ok' => 'utilizacao',
                'itens' => $resultado['itens'],
                'total' => number_format((float)$resultado['total'], 2, '.', ''),
            ]);
            header('Location: faturas.php?' . $query);
            exit;
        }
    } catch (Throwable $e) {
        $mensagemErro = $e->getMessage();
    }
}

if (($_GET['ok'] ?? '') === 'mensalidade') {
    $mensagemSucesso = 'Fatura de mensalidade importada: ' . (int)($_GET['itens'] ?? 0) . ' item(ns), total ' . moedaUnimed((float)($_GET['total'] ?? 0)) . '.';
} elseif (($_GET['ok'] ?? '') === 'utilizacao') {
    $mensagemSucesso = 'Fatura de utilizacao importada: ' . (int)($_GET['itens'] ?? 0) . ' item(ns), total ' . moedaUnimed((float)($_GET['total'] ?? 0)) . '.';
}

$competencia = trim((string)($_GET['competencia'] ?? ''));
$faturaId = (int)($_GET['fatura_id'] ?? 0);

$where = ['empresa_id = ?'];
$params = [$empresaId];

if ($competencia !== '') {
    $where[] = 'competencia = ?';
    $params[] = preg_replace('/\D/', '', $competencia);
}

$stmtFaturas = $pdo_master->prepare("
    SELECT *
    FROM unimed_faturas
    WHERE " . implode(' AND ', $where) . "
    ORDER BY competencia DESC, numero_fatura DESC
");
$stmtFaturas->execute($params);
$faturas = $stmtFaturas->fetchAll(PDO::FETCH_ASSOC);

if ($faturaId <= 0 && !empty($faturas)) {
    $faturaId = (int)$faturas[0]['id'];
}

$faturaAtual = null;
$itens = [];
$familias = [];
$utilizacoes = [];

if ($faturaId > 0) {
    $stmtFatura = $pdo_master->prepare("SELECT * FROM unimed_faturas WHERE id = ? AND empresa_id = ?");
    $stmtFatura->execute([$faturaId, $empresaId]);
    $faturaAtual = $stmtFatura->fetch(PDO::FETCH_ASSOC);

    if ($faturaAtual) {
        $stmtItens = $pdo_master->prepare("
            SELECT *
            FROM unimed_fatura_itens
            WHERE fatura_id = ?
            ORDER BY familia, dependente, nome
        ");
        $stmtItens->execute([$faturaId]);
        $itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

        $stmtFamilias = $pdo_master->prepare("
            SELECT
                familia,
                SUM(qtd) AS qtd,
                SUM(mensalidade) AS mensalidade,
                SUM(utilizacao) AS utilizacao,
                SUM(total) AS total
            FROM (
                SELECT familia, COUNT(*) AS qtd, SUM(valor_mensalidade) AS mensalidade, 0 AS utilizacao, SUM(valor_mensalidade) AS total
                FROM unimed_fatura_itens
                WHERE fatura_id = ?
                GROUP BY familia
                UNION ALL
                SELECT familia, 0 AS qtd, 0 AS mensalidade, SUM(valor_total) AS utilizacao, SUM(valor_total) AS total
                FROM unimed_utilizacoes
                WHERE fatura_id = ?
                GROUP BY familia
            ) x
            GROUP BY familia
            ORDER BY familia
        ");
        $stmtFamilias->execute([$faturaId, $faturaId]);
        $familias = $stmtFamilias->fetchAll(PDO::FETCH_ASSOC);

        $stmtUtilizacoes = $pdo_master->prepare("
            SELECT *
            FROM unimed_utilizacoes
            WHERE fatura_id = ?
            ORDER BY familia, dependente, data_atendimento, prestador, id
        ");
        $stmtUtilizacoes->execute([$faturaId]);
        $utilizacoes = $stmtUtilizacoes->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (($_GET['relatorio_responsaveis'] ?? '') === 'pdf' && $faturaAtual) {
    $stmtMensalidadesResp = $pdo_master->prepare("
        SELECT
            i.*,
            COALESCE(resp.id, b.id, i.beneficiario_id, 0) AS responsavel_id,
            COALESCE(resp.nome, b.nome, 'Responsavel nao informado') AS responsavel_nome,
            COALESCE(resp.codigo_completo, b.codigo_completo, '') AS responsavel_codigo,
            COALESCE(resp.telefone_whatsapp, '') AS responsavel_telefone
        FROM unimed_fatura_itens i
        LEFT JOIN unimed_beneficiarios b
            ON b.id = i.beneficiario_id
        LEFT JOIN unimed_beneficiarios resp
            ON resp.id = COALESCE(b.responsavel_pagamento_id, b.id)
        WHERE i.fatura_id = ?
        ORDER BY responsavel_nome, i.familia, i.dependente, i.nome
    ");
    $stmtMensalidadesResp->execute([$faturaId]);
    $mensalidadesResp = $stmtMensalidadesResp->fetchAll(PDO::FETCH_ASSOC);

    $stmtUtilizacoesResp = $pdo_master->prepare("
        SELECT
            u.*,
            COALESCE(resp.id, b.id, u.beneficiario_id, 0) AS responsavel_id,
            COALESCE(resp.nome, b.nome, 'Responsavel nao informado') AS responsavel_nome,
            COALESCE(resp.codigo_completo, b.codigo_completo, '') AS responsavel_codigo,
            COALESCE(resp.telefone_whatsapp, '') AS responsavel_telefone
        FROM unimed_utilizacoes u
        LEFT JOIN unimed_beneficiarios b
            ON b.id = u.beneficiario_id
        LEFT JOIN unimed_beneficiarios resp
            ON resp.id = COALESCE(b.responsavel_pagamento_id, b.id)
        WHERE u.fatura_id = ?
        ORDER BY responsavel_nome, u.familia, u.dependente, u.nome, u.data_atendimento, u.id
    ");
    $stmtUtilizacoesResp->execute([$faturaId]);
    $utilizacoesResp = $stmtUtilizacoesResp->fetchAll(PDO::FETCH_ASSOC);

    $responsaveisRelatorio = [];
    $inicializarResponsavel = static function (array $linha) use (&$responsaveisRelatorio): int {
        $responsavelId = (int)($linha['responsavel_id'] ?? 0);
        if (!isset($responsaveisRelatorio[$responsavelId])) {
            $responsaveisRelatorio[$responsavelId] = [
                'id' => $responsavelId,
                'nome' => (string)($linha['responsavel_nome'] ?? 'Responsavel nao informado'),
                'codigo' => (string)($linha['responsavel_codigo'] ?? ''),
                'telefone' => (string)($linha['responsavel_telefone'] ?? ''),
                'mensalidade' => 0.0,
                'utilizacao' => 0.0,
                'beneficiarios' => [],
                'mensalidades' => [],
                'utilizacoes' => [],
            ];
        }

        return $responsavelId;
    };

    foreach ($mensalidadesResp as $linha) {
        $responsavelId = $inicializarResponsavel($linha);
        $codigoBeneficiario = (string)$linha['codigo_completo'];
        if (!isset($responsaveisRelatorio[$responsavelId]['beneficiarios'][$codigoBeneficiario])) {
            $responsaveisRelatorio[$responsavelId]['beneficiarios'][$codigoBeneficiario] = [
                'codigo' => $codigoBeneficiario,
                'nome' => (string)$linha['nome'],
                'familia' => (string)$linha['familia'],
                'mensalidade' => 0.0,
                'utilizacao' => 0.0,
            ];
        }

        $valor = (float)$linha['valor_mensalidade'];
        $responsaveisRelatorio[$responsavelId]['mensalidade'] += $valor;
        $responsaveisRelatorio[$responsavelId]['beneficiarios'][$codigoBeneficiario]['mensalidade'] += $valor;
        $responsaveisRelatorio[$responsavelId]['mensalidades'][] = $linha;
    }

    foreach ($utilizacoesResp as $linha) {
        $responsavelId = $inicializarResponsavel($linha);
        $codigoBeneficiario = (string)$linha['codigo_completo'];
        if (!isset($responsaveisRelatorio[$responsavelId]['beneficiarios'][$codigoBeneficiario])) {
            $responsaveisRelatorio[$responsavelId]['beneficiarios'][$codigoBeneficiario] = [
                'codigo' => $codigoBeneficiario,
                'nome' => (string)$linha['nome'],
                'familia' => (string)$linha['familia'],
                'mensalidade' => 0.0,
                'utilizacao' => 0.0,
            ];
        }

        $valor = (float)$linha['valor_total'];
        $responsaveisRelatorio[$responsavelId]['utilizacao'] += $valor;
        $responsaveisRelatorio[$responsavelId]['beneficiarios'][$codigoBeneficiario]['utilizacao'] += $valor;
        $responsaveisRelatorio[$responsavelId]['utilizacoes'][] = $linha;
    }

    uasort($responsaveisRelatorio, static function (array $a, array $b): int {
        return strcasecmp($a['nome'], $b['nome']);
    });

    require '../../layout/header.php';
?>
<style>
    .unimed-relatorio {
        max-width: 980px;
        margin: 0 auto;
        background: #fff;
        color: #182033;
        font-size: 12px;
    }

    .unimed-relatorio .no-print {
        margin: 0 0 14px;
    }

    .unimed-recibo {
        border: 1px solid #cbd5e1;
        margin-bottom: 18px;
        page-break-after: always;
    }

    .unimed-recibo:last-child {
        page-break-after: auto;
    }

    .unimed-topo {
        background: #123a78;
        color: #fff;
        padding: 16px 18px;
        border-bottom: 4px solid #f0b429;
    }

    .unimed-topo h1 {
        font-size: 19px;
        margin: 0 0 6px;
        font-weight: 800;
        letter-spacing: .02em;
    }

    .unimed-topo .sub {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px 18px;
    }

    .unimed-bloco {
        padding: 12px 18px;
        border-bottom: 1px solid #d7dee8;
    }

    .unimed-titulo {
        background: #e8eef7;
        color: #0f2d68;
        font-weight: 800;
        padding: 7px 9px;
        margin: 0 0 8px;
        text-transform: uppercase;
    }

    .unimed-resumo {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
    }

    .unimed-box {
        border: 1px solid #d7dee8;
        padding: 8px;
        background: #f8fafc;
    }

    .unimed-box .label {
        color: #64748b;
        font-size: 11px;
        text-transform: uppercase;
    }

    .unimed-box .valor {
        font-size: 16px;
        font-weight: 800;
    }

    .unimed-relatorio table {
        width: 100%;
        border-collapse: collapse;
    }

    .unimed-relatorio th,
    .unimed-relatorio td {
        border-bottom: 1px solid #e2e8f0;
        padding: 5px 6px;
        vertical-align: top;
    }

    .unimed-relatorio th {
        background: #f1f5f9;
        color: #0f2d68;
        font-weight: 800;
    }

    .unimed-relatorio .text-end {
        text-align: right;
    }

    .unimed-total-final {
        text-align: right;
        font-size: 18px;
        font-weight: 900;
        color: #0f2d68;
        padding: 14px 18px 18px;
    }

    @media print {
        header, nav, .navbar, .topbar, .no-print, .btn {
            display: none !important;
        }

        body {
            background: #fff !important;
        }

        .container, .container-fluid {
            max-width: none !important;
            width: 100% !important;
            padding: 0 !important;
        }

        .unimed-relatorio {
            max-width: none;
            font-size: 11px;
        }

        .unimed-recibo {
            border: 0;
            margin: 0;
        }
    }
</style>

<div class="unimed-relatorio">
    <div class="no-print d-flex gap-2 mb-3">
        <button type="button" class="btn btn-primary" onclick="window.print()">Salvar em PDF</button>
        <a href="faturas.php?fatura_id=<?= (int)$faturaId ?>" class="btn btn-outline-secondary">Voltar</a>
    </div>

    <?php if (empty($responsaveisRelatorio)): ?>
        <div class="alert alert-info">Nenhum responsavel encontrado para esta fatura.</div>
    <?php endif; ?>

    <?php foreach ($responsaveisRelatorio as $responsavel): ?>
        <?php
            $totalResponsavel = (float)$responsavel['mensalidade'] + (float)$responsavel['utilizacao'];
            $beneficiariosResp = $responsavel['beneficiarios'];
            uasort($beneficiariosResp, static function (array $a, array $b): int {
                return strcasecmp($a['nome'], $b['nome']);
            });
        ?>
        <section class="unimed-recibo">
            <div class="unimed-topo">
                <h1>DEMONSTRATIVO UNIMED POR RESPONSAVEL</h1>
                <div class="sub">
                    <div><strong>Responsavel:</strong> <?= htmlspecialchars($responsavel['nome']) ?></div>
                    <div><strong>Telefone:</strong> <?= htmlspecialchars($responsavel['telefone'] !== '' ? $responsavel['telefone'] : '-') ?></div>
                    <div><strong>Fatura mensal:</strong> <?= htmlspecialchars($faturaAtual['numero_fatura']) ?> - <?= htmlspecialchars(competenciaUnimed($faturaAtual['competencia'])) ?></div>
                    <div><strong>Fatura utilizacao:</strong> <?= htmlspecialchars((string)($faturaAtual['numero_fatura_utilizacao'] ?: '-')) ?><?= !empty($faturaAtual['competencia_utilizacao']) ? ' - ' . htmlspecialchars(competenciaUnimed($faturaAtual['competencia_utilizacao'])) : '' ?></div>
                </div>
            </div>

            <div class="unimed-bloco">
                <div class="unimed-resumo">
                    <div class="unimed-box"><div class="label">Beneficiarios</div><div class="valor"><?= count($beneficiariosResp) ?></div></div>
                    <div class="unimed-box"><div class="label">Mensalidade</div><div class="valor"><?= moedaUnimed($responsavel['mensalidade']) ?></div></div>
                    <div class="unimed-box"><div class="label">Utilizacao</div><div class="valor"><?= moedaUnimed($responsavel['utilizacao']) ?></div></div>
                    <div class="unimed-box"><div class="label">Total a pagar</div><div class="valor"><?= moedaUnimed($totalResponsavel) ?></div></div>
                </div>
            </div>

            <div class="unimed-bloco">
                <div class="unimed-titulo">Resumo por beneficiario</div>
                <table>
                    <thead>
                        <tr>
                            <th>Codigo</th>
                            <th>Beneficiario</th>
                            <th>Familia</th>
                            <th class="text-end">Mensalidade</th>
                            <th class="text-end">Utilizacao</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($beneficiariosResp as $beneficiarioResumo): ?>
                            <tr>
                                <td><?= htmlspecialchars($beneficiarioResumo['codigo']) ?></td>
                                <td><?= htmlspecialchars($beneficiarioResumo['nome']) ?></td>
                                <td><?= htmlspecialchars($beneficiarioResumo['familia']) ?></td>
                                <td class="text-end"><?= moedaUnimed($beneficiarioResumo['mensalidade']) ?></td>
                                <td class="text-end"><?= moedaUnimed($beneficiarioResumo['utilizacao']) ?></td>
                                <td class="text-end"><strong><?= moedaUnimed((float)$beneficiarioResumo['mensalidade'] + (float)$beneficiarioResumo['utilizacao']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="unimed-bloco">
                <div class="unimed-titulo">Mensalidades</div>
                <table>
                    <thead>
                        <tr>
                            <th>Codigo</th>
                            <th>Beneficiario</th>
                            <th>Lancamento</th>
                            <th class="text-end">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($responsavel['mensalidades'])): ?>
                            <tr><td colspan="4">Nenhuma mensalidade.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($responsavel['mensalidades'] as $mensalidade): ?>
                            <tr>
                                <td><?= htmlspecialchars($mensalidade['codigo_completo']) ?></td>
                                <td><?= htmlspecialchars($mensalidade['nome']) ?></td>
                                <td><?= htmlspecialchars($mensalidade['lancamento']) ?></td>
                                <td class="text-end"><?= moedaUnimed($mensalidade['valor_mensalidade']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="unimed-bloco">
                <div class="unimed-titulo">Utilizacoes do plano</div>
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Codigo</th>
                            <th>Beneficiario</th>
                            <th>Prestador</th>
                            <th>Doc.</th>
                            <th class="text-end">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($responsavel['utilizacoes'])): ?>
                            <tr><td colspan="6">Nenhuma utilizacao.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($responsavel['utilizacoes'] as $utilizacaoLinha): ?>
                            <tr>
                                <td><?= !empty($utilizacaoLinha['data_atendimento']) ? date('d/m/Y', strtotime($utilizacaoLinha['data_atendimento'])) : '-' ?></td>
                                <td><?= htmlspecialchars($utilizacaoLinha['codigo_completo']) ?></td>
                                <td><?= htmlspecialchars($utilizacaoLinha['nome']) ?></td>
                                <td><?= htmlspecialchars((string)$utilizacaoLinha['prestador']) ?></td>
                                <td><?= htmlspecialchars((string)$utilizacaoLinha['documento']) ?></td>
                                <td class="text-end"><?= moedaUnimed($utilizacaoLinha['valor_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="unimed-total-final">
                TOTAL A PAGAR: <?= moedaUnimed($totalResponsavel) ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 350);
    });
</script>
<?php
    require '../../layout/footer.php';
    exit;
}

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Unimed</span>
                <h1 class="h3 fw-bold mb-2">Faturas Mensais</h1>
                <p class="text-muted mb-0">Fechamento mensal por usuario e familia, separando mensalidade e utilizacao do plano.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_unimed.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagemSucesso !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensagemSucesso) ?></div>
<?php endif; ?>
<?php if ($mensagemErro !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div>
<?php endif; ?>

<section class="mb-3">
    <div class="row g-3">
        <div class="col-lg-6">
            <form method="post" enctype="multipart/form-data" class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Importar Analitico de Taxa</h2>
                </div>
                <div class="card-body">
                    <input type="hidden" name="acao" value="upload_mensalidade">
                    <label class="form-label">Arquivo PDF do Analitico de Taxa</label>
                    <input type="file" name="arquivo_mensalidade" accept="application/pdf,.pdf" class="form-control" required>
                    <div class="form-text">Use o arquivo que detalha as mensalidades por beneficiario. O recibo/boleto nao contem os usuarios.</div>
                </div>
                <div class="card-footer bg-white text-end">
                    <button type="submit" class="btn btn-primary">Enviar Analitico de Taxa</button>
                </div>
            </form>
        </div>
        <div class="col-lg-6">
            <form method="post" enctype="multipart/form-data" class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Importar utilizacoes do plano</h2>
                </div>
                <div class="card-body">
                    <input type="hidden" name="acao" value="upload_utilizacao">
                    <label class="form-label">Fatura mensal de destino</label>
                    <select name="fatura_destino_id" class="form-select mb-3" required>
                        <option value="">Selecione</option>
                        <?php foreach ($faturas as $fatura): ?>
                            <option value="<?= (int)$fatura['id'] ?>" <?= (int)$fatura['id'] === $faturaId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fatura['numero_fatura']) ?> - <?= htmlspecialchars(competenciaUnimed($fatura['competencia'])) ?> - <?= moedaUnimed($fatura['total_fatura']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label">Arquivo PDF de utilizacoes</label>
                    <input type="file" name="arquivo_utilizacao" accept="application/pdf,.pdf" class="form-control" required>
                    <div class="form-text">Substitui as utilizacoes vinculadas a fatura mensal selecionada, evitando duplicidade.</div>
                </div>
                <div class="card-footer bg-white text-end">
                    <button type="submit" class="btn btn-primary">Enviar utilizacoes</button>
                </div>
            </form>
        </div>
    </div>
</section>

<section class="mb-3">
    <form method="get" class="card shadow-sm">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Competencia</label>
                    <input type="text" name="competencia" value="<?= htmlspecialchars($competencia) ?>" class="form-control" placeholder="202606">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Fatura</label>
                    <select name="fatura_id" class="form-select">
                        <?php foreach ($faturas as $fatura): ?>
                            <option value="<?= (int)$fatura['id'] ?>" <?= (int)$fatura['id'] === $faturaId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fatura['numero_fatura']) ?> - <?= htmlspecialchars(competenciaUnimed($fatura['competencia'])) ?> - <?= moedaUnimed($fatura['total_fatura']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </div>
        </div>
    </form>
</section>

<?php if (!$faturaAtual): ?>
    <div class="alert alert-info">Nenhuma fatura Unimed cadastrada para esta empresa.</div>
<?php else: ?>
    <section class="mb-3">
        <div class="row g-3">
            <div class="col-md-6 col-xl"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Fatura</div><div class="h5 mb-0"><?= htmlspecialchars($faturaAtual['numero_fatura']) ?></div></div></div></div>
            <div class="col-md-6 col-xl"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Competencia</div><div class="h5 mb-0"><?= htmlspecialchars(competenciaUnimed($faturaAtual['competencia'])) ?></div></div></div></div>
            <div class="col-md-4 col-xl"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Mensalidade</div><div class="h5 mb-0"><?= moedaUnimed($faturaAtual['total_mensalidade']) ?></div></div></div></div>
            <div class="col-md-4 col-xl"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Utilizacao</div><div class="h5 mb-0"><?= moedaUnimed($faturaAtual['total_utilizacao']) ?></div></div></div></div>
            <div class="col-md-4 col-xl"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Total</div><div class="h5 mb-0"><?= moedaUnimed($faturaAtual['total_fatura']) ?></div></div></div></div>
        </div>
    </section>

    <?php if (!empty($faturaAtual['numero_fatura_utilizacao'])): ?>
        <section class="mb-3">
            <div class="alert alert-info mb-0">
                Utilizacao vinculada: fatura <?= htmlspecialchars($faturaAtual['numero_fatura_utilizacao']) ?>
                <?php if (!empty($faturaAtual['competencia_utilizacao'])): ?>
                    | competencia <?= htmlspecialchars(competenciaUnimed($faturaAtual['competencia_utilizacao'])) ?>
                <?php endif; ?>
                | total <?= moedaUnimed($faturaAtual['total_utilizacao']) ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="mb-3">
        <div class="d-flex justify-content-end">
            <a href="faturas.php?fatura_id=<?= (int)$faturaId ?>&relatorio_responsaveis=pdf" target="_blank" class="btn btn-danger">
                PDF por responsavel
            </a>
        </div>
    </section>

    <section class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Resumo por familia</h2></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead><tr><th>Familia</th><th class="text-end">Usuarios</th><th class="text-end">Mensalidade</th><th class="text-end">Utilizacao</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($familias as $familiaLinha): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($familiaLinha['familia']) ?></td>
                                <td class="text-end"><?= (int)$familiaLinha['qtd'] ?></td>
                                <td class="text-end"><?= moedaUnimed($familiaLinha['mensalidade']) ?></td>
                                <td class="text-end"><?= moedaUnimed($familiaLinha['utilizacao']) ?></td>
                                <td class="text-end fw-semibold"><?= moedaUnimed($familiaLinha['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card shadow-sm">
        <div class="card-header"><h2 class="h6 mb-0">Itens da fatura</h2></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead><tr><th>Codigo</th><th>Familia</th><th>Nome</th><th>Lancamento</th><th class="text-end">Mensalidade</th><th class="text-end">Utilizacao</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($itens as $item): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($item['codigo_completo']) ?></td>
                                <td><?= htmlspecialchars($item['familia']) ?></td>
                                <td><?= htmlspecialchars($item['nome']) ?></td>
                                <td><?= htmlspecialchars($item['lancamento']) ?></td>
                                <td class="text-end"><?= moedaUnimed($item['valor_mensalidade']) ?></td>
                                <td class="text-end"><?= moedaUnimed($item['valor_utilizacao']) ?></td>
                                <td class="text-end fw-semibold"><?= moedaUnimed($item['valor_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card shadow-sm mt-3">
        <div class="card-header"><h2 class="h6 mb-0">Utilizacoes do plano</h2></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead><tr><th>Codigo</th><th>Familia</th><th>Nome</th><th>Data</th><th>Prestador</th><th>Doc.</th><th class="text-end">Valor</th></tr></thead>
                    <tbody>
                        <?php if (empty($utilizacoes ?? [])): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma utilizacao vinculada a esta fatura.</td></tr>
                        <?php endif; ?>
                        <?php foreach (($utilizacoes ?? []) as $utilizacao): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($utilizacao['codigo_completo']) ?></td>
                                <td><?= htmlspecialchars($utilizacao['familia']) ?></td>
                                <td><?= htmlspecialchars($utilizacao['nome']) ?></td>
                                <td><?= !empty($utilizacao['data_atendimento']) ? date('d/m/Y', strtotime($utilizacao['data_atendimento'])) : '-' ?></td>
                                <td><?= htmlspecialchars((string)$utilizacao['prestador']) ?></td>
                                <td><?= htmlspecialchars((string)$utilizacao['documento']) ?></td>
                                <td class="text-end fw-semibold"><?= moedaUnimed($utilizacao['valor_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php require '../../layout/footer.php'; ?>
