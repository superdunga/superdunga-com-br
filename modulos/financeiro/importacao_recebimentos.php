<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$ehMaster = strtoupper((string)($_SESSION['nivel'] ?? '')) === 'MASTER';
$mensagem = null;
$erro = null;

if (!$ehMaster) {
    renderizarAcessoNegadoModulo('Somente usuario master pode acessar a importacao de recebimentos do financeiro.');
    exit;
}

garantirTabelaTaxasAdquirentes($pdo_master);
garantirTabelaAgendaAdquirentes($pdo_master);

function origemAdquirenteSql(): string
{
    return "
        CASE
            WHEN origem LIKE 'GRANITO%' THEN 'GRANITO'
            WHEN origem LIKE 'SIPAG%' THEN 'SIPAG'
            WHEN origem LIKE 'PAGSEGURO%' THEN 'PAGSEGURO'
            ELSE origem
        END
    ";
}

function origemGrupoSql(): string
{
    return "
        CASE
            WHEN origem LIKE '%COMERCIAL%' THEN 'COMERCIAL'
            WHEN origem LIKE '%OUTROS%' THEN 'OUTROS'
            ELSE 'GERAL'
        END
    ";
}

function rotuloTipoOperacao(string $tipo): string
{
    return match (strtoupper($tipo)) {
        'D' => 'DEBITO',
        'C' => 'CREDITO',
        'P' => 'PIX',
        default => strtoupper($tipo),
    };
}

function numeroTransacaoRecebimento(array $transacao): string
{
    $identificador = (string)($transacao['identificador'] ?? '');
    $identificador = preg_replace('/^(GRANITO_POS_|GRANITO_PIX_|SIPAG_POS_|SIPAG_PIX_|PAGSEGURO_PIX_)/', '', $identificador);
    return $identificador !== '' ? $identificador : ('#' . (int)$transacao['id']);
}

$stmtTaxas = $pdo_master->prepare("
    SELECT *
    FROM fechamento_adquirente_taxas
    WHERE empresa_id = ? AND ativo = 'S'
    ORDER BY adquirente, grupo, tipo_operacao, bandeira, parcelas_de
");
$stmtTaxas->execute([$empresaId]);
$taxasAtivas = $stmtTaxas->fetchAll(PDO::FETCH_ASSOC);

$filtroDataIni = $_GET['data_ini'] ?? '';
$filtroDataFim = $_GET['data_fim'] ?? '';
$filtroVencIni = $_GET['venc_ini'] ?? date('Y-m-01');
$filtroVencFim = $_GET['venc_fim'] ?? date('Y-m-t');
$filtroAdquirente = strtoupper(trim((string)($_GET['adquirente'] ?? '')));
$filtroGrupo = strtoupper(trim((string)($_GET['grupo'] ?? '')));
$filtroTipo = strtoupper(trim((string)($_GET['tipo'] ?? '')));
$filtroBandeira = strtoupper(trim((string)($_GET['bandeira'] ?? '')));
$filtroSituacao = $_GET['situacao'] ?? 'todos';

$where = ["empresa_id = ?"];
$params = [$empresaId];

if ($filtroDataIni !== '') {
    $where[] = "DATE(data_venda) >= ?";
    $params[] = $filtroDataIni;
}

if ($filtroDataFim !== '') {
    $where[] = "DATE(data_venda) <= ?";
    $params[] = $filtroDataFim;
}

if ($filtroVencIni !== '') {
    $where[] = "DATE(COALESCE(data_recebimento, data_prevista)) >= ?";
    $params[] = $filtroVencIni;
}

if ($filtroVencFim !== '') {
    $where[] = "DATE(COALESCE(data_recebimento, data_prevista)) <= ?";
    $params[] = $filtroVencFim;
}

if ($filtroAdquirente !== '') {
    $where[] = origemAdquirenteSql() . " = ?";
    $params[] = $filtroAdquirente;
}

if ($filtroGrupo !== '') {
    $where[] = origemGrupoSql() . " = ?";
    $params[] = $filtroGrupo;
}

if ($filtroTipo !== '') {
    $where[] = "tipo_operacao = ?";
    $params[] = $filtroTipo;
}

if ($filtroBandeira !== '') {
    $where[] = "UPPER(COALESCE(NULLIF(bandeira, ''), 'TODAS')) = ?";
    $params[] = $filtroBandeira;
}

$whereAgenda = ["empresa_id = ?"];
$paramsAgenda = [$empresaId];

if ($filtroDataIni !== '') {
    $whereAgenda[] = "DATE(data_transacao) >= ?";
    $paramsAgenda[] = $filtroDataIni;
}

if ($filtroDataFim !== '') {
    $whereAgenda[] = "DATE(data_transacao) <= ?";
    $paramsAgenda[] = $filtroDataFim;
}

if ($filtroVencIni !== '') {
    $whereAgenda[] = "DATE(data_pagamento) >= ?";
    $paramsAgenda[] = $filtroVencIni;
}

if ($filtroVencFim !== '') {
    $whereAgenda[] = "DATE(data_pagamento) <= ?";
    $paramsAgenda[] = $filtroVencFim;
}

if ($filtroAdquirente !== '') {
    $whereAgenda[] = "adquirente = ?";
    $paramsAgenda[] = $filtroAdquirente;
}

if ($filtroGrupo !== '') {
    $whereAgenda[] = "grupo = ?";
    $paramsAgenda[] = $filtroGrupo;
}

if ($filtroTipo !== '') {
    $whereAgenda[] = "tipo_operacao = ?";
    $paramsAgenda[] = $filtroTipo;
}

if ($filtroBandeira !== '') {
    $whereAgenda[] = "UPPER(COALESCE(NULLIF(bandeira, ''), 'TODAS')) = ?";
    $paramsAgenda[] = $filtroBandeira;
}

$stmtBandeiras = $pdo_master->prepare("
    SELECT DISTINCT bandeira
    FROM (
        SELECT UPPER(COALESCE(NULLIF(bandeira, ''), 'TODAS')) AS bandeira
        FROM armazem_conciliacao_recebimentos
        WHERE empresa_id = ?
        UNION
        SELECT UPPER(COALESCE(NULLIF(bandeira, ''), 'TODAS')) AS bandeira
        FROM fechamento_adquirente_agenda
        WHERE empresa_id = ?
    ) base
    ORDER BY bandeira
");
$stmtBandeiras->execute([$empresaId, $empresaId]);
$bandeiras = array_values(array_filter(array_column($stmtBandeiras->fetchAll(PDO::FETCH_ASSOC), 'bandeira')));

$sqlTransacoes = "
    SELECT
        id,
        data_venda,
        data_prevista,
        data_recebimento,
        arquivo_origem,
        origem,
        " . origemAdquirenteSql() . " AS adquirente,
        " . origemGrupoSql() . " AS grupo,
        tipo_operacao,
        COALESCE(NULLIF(bandeira, ''), 'TODAS') AS bandeira,
        COALESCE(NULLIF(total_parcelas, 0), 1) AS parcelas,
        COALESCE(valor_bruto, 0) AS total_bruto,
        COALESCE(valor_desconto, 0) AS total_taxa,
        COALESCE(valor_liquido, 0) AS total_liquido,
        identificador,
        descricao,
        pagador,
        criado_em AS importado_em,
        (
            SELECT COUNT(*)
            FROM fechamento_granito_agenda_taxas ag
            WHERE ag.empresa_id = armazem_conciliacao_recebimentos.empresa_id
              AND ag.identificador = armazem_conciliacao_recebimentos.identificador
        ) AS agenda_qtd
    FROM armazem_conciliacao_recebimentos
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        COALESCE(data_recebimento, data_prevista) IS NULL,
        COALESCE(data_recebimento, data_prevista) ASC,
        data_venda ASC,
        id ASC
";

$stmtTransacoes = $pdo_master->prepare($sqlTransacoes);
$stmtTransacoes->execute($params);
$transacoes = $stmtTransacoes->fetchAll(PDO::FETCH_ASSOC);

$sqlAgenda = "
    SELECT
        id,
        adquirente,
        grupo,
        arquivo_origem,
        id_transacao,
        identificador_recebivel,
        data_transacao,
        data_pagamento,
        tipo_operacao,
        categoria,
        descricao_original,
        status,
        parcela,
        total_parcelas,
        COALESCE(NULLIF(bandeira, ''), 'TODAS') AS bandeira,
        valor_bruto,
        valor_taxa,
        valor_antecipacao,
        valor_liquido
    FROM fechamento_adquirente_agenda
    WHERE " . implode(' AND ', $whereAgenda) . "
    ORDER BY
        data_pagamento IS NULL,
        data_pagamento ASC,
        data_transacao ASC,
        id ASC
";
$stmtAgenda = $pdo_master->prepare($sqlAgenda);
$stmtAgenda->execute($paramsAgenda);
$agendaAdquirente = $stmtAgenda->fetchAll(PDO::FETCH_ASSOC);

function buscarTaxaEsperada(array $taxasAtivas, array $relatorio): ?array
{
    $candidatas = [];
    foreach ($taxasAtivas as $taxa) {
        if ($taxa['adquirente'] !== $relatorio['adquirente']) {
            continue;
        }
        if ($taxa['grupo'] !== $relatorio['grupo']) {
            continue;
        }
        if ($taxa['tipo_operacao'] !== rotuloTipoOperacao((string)$relatorio['tipo_operacao'])) {
            continue;
        }
        if ((int)$relatorio['parcelas'] < (int)$taxa['parcelas_de'] || (int)$relatorio['parcelas'] > (int)$taxa['parcelas_ate']) {
            continue;
        }
        if ($taxa['bandeira'] !== 'TODAS' && strtoupper((string)$taxa['bandeira']) !== strtoupper((string)$relatorio['bandeira'])) {
            continue;
        }
        $candidatas[] = $taxa;
    }

    usort($candidatas, static function ($a, $b) {
        $aEspecifica = $a['bandeira'] === 'TODAS' ? 0 : 1;
        $bEspecifica = $b['bandeira'] === 'TODAS' ? 0 : 1;
        return $bEspecifica <=> $aEspecifica;
    });

    return $candidatas[0] ?? null;
}

function relatorioDemonstraTaxa(array $relatorio): bool
{
    if (strtoupper((string)($relatorio['tipo_operacao'] ?? '')) === 'P') {
        return true;
    }

    return ((int)($relatorio['agenda_qtd'] ?? 0) > 0) || abs((float)$relatorio['total_taxa']) > 0.0001;
}

function situacaoTaxaRelatorio(array $relatorio, array $taxasAtivas): array
{
    $totalBruto = (float)$relatorio['total_bruto'];
    $totalTaxa = (float)$relatorio['total_taxa'];
    $taxaMedia = $totalBruto > 0 ? ($totalTaxa / $totalBruto) * 100 : 0;
    $taxaEsperada = buscarTaxaEsperada($taxasAtivas, $relatorio);

    if (!relatorioDemonstraTaxa($relatorio)) {
        return ['classe' => 'secondary', 'texto' => 'Sem taxa demonstrada', 'taxa_media' => $taxaMedia, 'esperada' => $taxaEsperada];
    }

    if (!$taxaEsperada) {
        return ['classe' => 'secondary', 'texto' => 'Sem taxa cadastrada', 'taxa_media' => $taxaMedia, 'esperada' => null];
    }

    $esperada = (float)$taxaEsperada['taxa_percentual'];
    $tolerancia = (float)$taxaEsperada['tolerancia_percentual'];
    $divergente = abs($taxaMedia - $esperada) > $tolerancia;

    return [
        'classe' => $divergente ? 'danger' : 'success',
        'texto' => $divergente ? 'Divergente' : 'OK',
        'taxa_media' => $taxaMedia,
        'esperada' => $taxaEsperada,
    ];
}

$transacoes = array_values(array_filter($transacoes, function ($transacao) use ($taxasAtivas, $filtroSituacao) {
    if ($filtroSituacao === 'todos') {
        return true;
    }

    $situacao = situacaoTaxaRelatorio($transacao, $taxasAtivas);
    return match ($filtroSituacao) {
        'divergentes' => $situacao['texto'] === 'Divergente',
        'sem_taxa' => $situacao['texto'] === 'Sem taxa cadastrada',
        'sem_taxa_demonstrada' => $situacao['texto'] === 'Sem taxa demonstrada',
        'ok' => $situacao['texto'] === 'OK',
        default => true,
    };
}));

$totaisTransacoes = [
    'qtd' => count($transacoes),
    'bruto' => 0.0,
    'taxa' => 0.0,
    'taxa_esperada' => 0.0,
    'liquido' => 0.0,
    'diferenca_valor' => 0.0,
];

$totaisAgenda = [
    'qtd' => count($agendaAdquirente),
    'bruto' => 0.0,
    'taxa' => 0.0,
    'antecipacao' => 0.0,
    'liquido' => 0.0,
];

foreach ($agendaAdquirente as $agendaResumo) {
    $totaisAgenda['bruto'] += (float)$agendaResumo['valor_bruto'];
    $totaisAgenda['taxa'] += (float)$agendaResumo['valor_taxa'];
    $totaisAgenda['antecipacao'] += (float)$agendaResumo['valor_antecipacao'];
    $totaisAgenda['liquido'] += (float)$agendaResumo['valor_liquido'];
}

foreach ($transacoes as $transacaoResumo) {
    $situacaoResumo = situacaoTaxaRelatorio($transacaoResumo, $taxasAtivas);
    $totaisTransacoes['bruto'] += (float)$transacaoResumo['total_bruto'];
    $totaisTransacoes['taxa'] += (float)$transacaoResumo['total_taxa'];
    $totaisTransacoes['liquido'] += (float)$transacaoResumo['total_liquido'];

    if ($situacaoResumo['esperada']) {
        $taxaEsperadaResumo = (float)$situacaoResumo['esperada']['taxa_percentual'];
        $valorEsperadoResumo = ((float)$transacaoResumo['total_bruto']) * ($taxaEsperadaResumo / 100);
        $totaisTransacoes['taxa_esperada'] += $valorEsperadoResumo;

        if (relatorioDemonstraTaxa($transacaoResumo)) {
            $taxaAplicadaResumo = (float)$situacaoResumo['taxa_media'];
            $totaisTransacoes['diferenca_valor'] += ((float)$transacaoResumo['total_bruto']) * (($taxaAplicadaResumo - $taxaEsperadaResumo) / 100);
        }
    }
}

$adquirentes = ['GRANITO', 'SIPAG', 'PAGSEGURO'];
$grupos = ['COMERCIAL', 'OUTROS', 'GERAL'];
$tipos = ['DEBITO', 'CREDITO', 'PIX'];

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-warning mb-3">Recebimentos</span>
                <h1 class="h3 fw-bold mb-2">Relatorio dos Recebimentos</h1>
                <p class="text-muted mb-0">Acompanhe as transacoes importadas e a conferencia das taxas aplicadas pelas adquirentes.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_financeiro.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagem): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensagem) ?></div>
<?php endif; ?>

<?php if ($erro): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<section>
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                <div>
                    <h2 class="h5 mb-1">Relatorio dos Recebimentos</h2>
                    <div class="text-muted small">Transacoes importadas com comparacao entre taxa acordada e taxa aplicada pela adquirente.</div>
                </div>
                <div class="text-muted small align-self-lg-center">Na Granito, a taxa de POS vem da agenda; o arquivo de transacoes fica como base operacional.</div>
            </div>
        </div>
        <div class="card-body border-bottom">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">Data inicial</label>
                    <input type="date" name="data_ini" value="<?= htmlspecialchars($filtroDataIni) ?>" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data final</label>
                    <input type="date" name="data_fim" value="<?= htmlspecialchars($filtroDataFim) ?>" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Venc./Pagto. inicial</label>
                    <input type="date" name="venc_ini" value="<?= htmlspecialchars($filtroVencIni) ?>" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Venc./Pagto. final</label>
                    <input type="date" name="venc_fim" value="<?= htmlspecialchars($filtroVencFim) ?>" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Adquirente</label>
                    <select name="adquirente" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($adquirentes as $adquirente): ?>
                            <option value="<?= $adquirente ?>" <?= $filtroAdquirente === $adquirente ? 'selected' : '' ?>><?= $adquirente ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Grupo</label>
                    <select name="grupo" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($grupos as $grupo): ?>
                            <option value="<?= $grupo ?>" <?= $filtroGrupo === $grupo ? 'selected' : '' ?>><?= $grupo ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select">
                        <option value="">Todos</option>
                        <option value="D" <?= $filtroTipo === 'D' ? 'selected' : '' ?>>Debito</option>
                        <option value="C" <?= $filtroTipo === 'C' ? 'selected' : '' ?>>Credito</option>
                        <option value="P" <?= $filtroTipo === 'P' ? 'selected' : '' ?>>Pix</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Bandeira</label>
                    <select name="bandeira" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($bandeiras as $bandeira): ?>
                            <option value="<?= htmlspecialchars($bandeira) ?>" <?= $filtroBandeira === $bandeira ? 'selected' : '' ?>><?= htmlspecialchars($bandeira) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Situacao taxa</label>
                    <select name="situacao" class="form-select">
                        <option value="todos" <?= $filtroSituacao === 'todos' ? 'selected' : '' ?>>Todas</option>
                        <option value="divergentes" <?= $filtroSituacao === 'divergentes' ? 'selected' : '' ?>>Divergentes</option>
                        <option value="ok" <?= $filtroSituacao === 'ok' ? 'selected' : '' ?>>OK</option>
                        <option value="sem_taxa" <?= $filtroSituacao === 'sem_taxa' ? 'selected' : '' ?>>Sem taxa cadastrada</option>
                        <option value="sem_taxa_demonstrada" <?= $filtroSituacao === 'sem_taxa_demonstrada' ? 'selected' : '' ?>>Sem taxa demonstrada</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        <div class="card-body border-bottom">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Transacoes filtradas</div>
                        <div class="h5 mb-0"><?= number_format($totaisTransacoes['qtd'], 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Valor bruto</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisTransacoes['bruto'], 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Taxa aplicada demonstrada</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisTransacoes['taxa'], 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Taxa acordada estimada</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisTransacoes['taxa_esperada'], 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Diferenca demonstrada</div>
                        <div class="h5 mb-0 <?= abs($totaisTransacoes['diferenca_valor']) > 0.009 ? 'text-danger' : 'text-success' ?>">
                            R$ <?= number_format($totaisTransacoes['diferenca_valor'], 2, ',', '.') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Venc./Pagto.</th>
                        <th>Data</th>
                        <th>Transacao</th>
                        <th>Adq.</th>
                        <th>Grupo</th>
                        <th>Tipo</th>
                        <th>Bandeira</th>
                        <th>Parc.</th>
                        <th class="text-end">Bruto</th>
                        <th class="text-end">Taxa aplicada R$</th>
                        <th class="text-end">Taxa acordada R$</th>
                        <th class="text-end">Aplicada</th>
                        <th class="text-end">Acordada</th>
                        <th class="text-end">Dif. %</th>
                        <th class="text-end">Dif. R$</th>
                        <th>Sit.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transacoes as $transacao): ?>
                        <?php
                            $situacao = situacaoTaxaRelatorio($transacao, $taxasAtivas);
                            $taxaAplicada = (float)$situacao['taxa_media'];
                            $taxaAcordada = $situacao['esperada'] ? (float)$situacao['esperada']['taxa_percentual'] : null;
                            $taxaDemonstrada = relatorioDemonstraTaxa($transacao);
                            $valorTaxaAcordada = $taxaAcordada !== null ? ((float)$transacao['total_bruto']) * ($taxaAcordada / 100) : null;
                            $difPercentual = ($taxaAcordada !== null && $taxaDemonstrada) ? $taxaAplicada - $taxaAcordada : null;
                            $difValor = $difPercentual !== null ? ((float)$transacao['total_bruto']) * ($difPercentual / 100) : null;
                            $dataPagamento = $transacao['data_recebimento'] ?: $transacao['data_prevista'];
                        ?>
                        <tr>
                            <td class="small"><?= $dataPagamento ? date('d/m/Y', strtotime($dataPagamento)) : '<span class="text-muted">Sem agenda</span>' ?></td>
                            <td class="small"><?= date('d/m/Y H:i', strtotime($transacao['data_venda'])) ?></td>
                            <td class="small"><?= htmlspecialchars(numeroTransacaoRecebimento($transacao)) ?></td>
                            <td><?= htmlspecialchars($transacao['adquirente']) ?></td>
                            <td><?= htmlspecialchars($transacao['grupo']) ?></td>
                            <td><?= htmlspecialchars(rotuloTipoOperacao((string)$transacao['tipo_operacao'])) ?></td>
                            <td><?= htmlspecialchars($transacao['bandeira']) ?></td>
                            <td><?= (int)$transacao['parcelas'] ?></td>
                            <td class="text-end"><?= number_format((float)$transacao['total_bruto'], 2, ',', '.') ?></td>
                            <td class="text-end"><?= $taxaDemonstrada ? number_format((float)$transacao['total_taxa'], 2, ',', '.') : '<span class="text-muted">Sem agenda</span>' ?></td>
                            <td class="text-end"><?= $valorTaxaAcordada !== null ? number_format($valorTaxaAcordada, 2, ',', '.') : '-' ?></td>
                            <td class="text-end"><?= $taxaDemonstrada ? number_format($taxaAplicada, 4, ',', '.') . '%' : '<span class="text-muted">Sem agenda</span>' ?></td>
                            <td class="text-end">
                                <?= $taxaAcordada !== null ? number_format($taxaAcordada, 4, ',', '.') . '%' : '-' ?>
                            </td>
                            <td class="text-end <?= $difPercentual !== null && abs($difPercentual) > 0.0001 ? 'text-danger' : '' ?>">
                                <?= $difPercentual !== null ? number_format($difPercentual, 4, ',', '.') . '%' : '-' ?>
                            </td>
                            <td class="text-end <?= $difValor !== null && abs($difValor) > 0.009 ? 'text-danger' : '' ?>">
                                <?= $difValor !== null ? number_format($difValor, 2, ',', '.') : '-' ?>
                            </td>
                            <td><span class="badge text-bg-<?= htmlspecialchars($situacao['classe']) ?>"><?= htmlspecialchars($situacao['texto']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($transacoes)): ?>
                        <tr><td colspan="16" class="text-center text-muted py-4">Nenhuma transacao encontrada para os filtros.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="mt-4">
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                <div>
                    <h2 class="h5 mb-1">Agenda da Adquirente</h2>
                    <div class="text-muted small">Linhas completas dos arquivos de agenda importados, incluindo vendas, Pix, taxas, aluguel POS e ajustes.</div>
                </div>
                <div class="text-muted small align-self-lg-center">Este bloco nao gera recebivel automaticamente para itens financeiros como aluguel de POS.</div>
            </div>
        </div>
        <div class="card-body border-bottom">
            <div class="row g-3">
                <div class="col-md-2">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Linhas da agenda</div>
                        <div class="h5 mb-0"><?= number_format($totaisAgenda['qtd'], 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Bruto</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisAgenda['bruto'], 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Taxa</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisAgenda['taxa'], 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Antecipacao</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisAgenda['antecipacao'], 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Liquido</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisAgenda['liquido'], 2, ',', '.') ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Venc./Pagto.</th>
                        <th>Data</th>
                        <th>Transacao</th>
                        <th>Adq.</th>
                        <th>Grupo</th>
                        <th>Tipo</th>
                        <th>Categoria</th>
                        <th>Bandeira</th>
                        <th>Parc.</th>
                        <th class="text-end">Bruto</th>
                        <th class="text-end">Taxa</th>
                        <th class="text-end">Antecip.</th>
                        <th class="text-end">Liquido</th>
                        <th>Status</th>
                        <th>Descricao</th>
                        <th>Arquivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agendaAdquirente as $agenda): ?>
                        <tr>
                            <td class="small"><?= $agenda['data_pagamento'] ? date('d/m/Y', strtotime($agenda['data_pagamento'])) : '-' ?></td>
                            <td class="small"><?= $agenda['data_transacao'] ? date('d/m/Y H:i', strtotime($agenda['data_transacao'])) : '-' ?></td>
                            <td class="small"><?= htmlspecialchars($agenda['id_transacao']) ?></td>
                            <td><?= htmlspecialchars($agenda['adquirente']) ?></td>
                            <td><?= htmlspecialchars($agenda['grupo']) ?></td>
                            <td><?= htmlspecialchars(rotuloTipoOperacao((string)$agenda['tipo_operacao'])) ?></td>
                            <td><span class="badge text-bg-secondary"><?= htmlspecialchars($agenda['categoria']) ?></span></td>
                            <td><?= htmlspecialchars($agenda['bandeira']) ?></td>
                            <td><?= (int)$agenda['parcela'] ?>/<?= (int)$agenda['total_parcelas'] ?></td>
                            <td class="text-end"><?= number_format((float)$agenda['valor_bruto'], 2, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$agenda['valor_taxa'], 2, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$agenda['valor_antecipacao'], 2, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$agenda['valor_liquido'], 2, ',', '.') ?></td>
                            <td><?= htmlspecialchars((string)$agenda['status']) ?></td>
                            <td class="small"><?= htmlspecialchars((string)$agenda['descricao_original']) ?></td>
                            <td class="small"><?= htmlspecialchars(basename((string)$agenda['arquivo_origem'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($agendaAdquirente)): ?>
                        <tr><td colspan="16" class="text-center text-muted py-4">Nenhuma linha de agenda encontrada para os filtros.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
