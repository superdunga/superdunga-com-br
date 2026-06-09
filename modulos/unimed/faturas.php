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
