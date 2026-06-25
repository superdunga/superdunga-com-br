<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';
require '../../layout/header.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$ehMaster = strtoupper((string)($_SESSION['nivel'] ?? '')) === 'MASTER';

if (!$ehMaster) {
    renderizarAcessoNegadoModulo('Somente usuario master pode acessar a importacao de recebimentos do financeiro.');
    exit;
}

garantirTabelaTaxasAdquirentes($pdo_master);

$mensagensGet = [
    'taxa_salva' => 'Taxa salva com sucesso.',
    'taxa_desativada' => 'Taxa desativada.',
];

$mensagem = $mensagensGet[$_GET['msg'] ?? ''] ?? null;
$erro = null;

function taxaPostDecimal(string $campo): float
{
    $valor = str_replace(',', '.', trim((string)($_POST[$campo] ?? '0')));
    return (float)$valor;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'salvar_taxa') {
            $id = (int)($_POST['id'] ?? 0);
            $parcelasDe = max(1, (int)($_POST['parcelas_de'] ?? 1));
            $parcelasAte = max($parcelasDe, (int)($_POST['parcelas_ate'] ?? $parcelasDe));
            $dados = [
                'adquirente' => strtoupper(trim((string)($_POST['adquirente'] ?? ''))),
                'grupo' => strtoupper(trim((string)($_POST['grupo'] ?? ''))),
                'tipo_operacao' => strtoupper(trim((string)($_POST['tipo_operacao'] ?? ''))),
                'bandeira' => strtoupper(trim((string)($_POST['bandeira'] ?? 'TODAS'))) ?: 'TODAS',
                'parcelas_de' => $parcelasDe,
                'parcelas_ate' => $parcelasAte,
                'taxa_percentual' => taxaPostDecimal('taxa_percentual'),
                'tolerancia_percentual' => taxaPostDecimal('tolerancia_percentual'),
                'ativo' => ($_POST['ativo'] ?? 'S') === 'S' ? 'S' : 'N',
            ];

            if ($dados['adquirente'] === '' || $dados['grupo'] === '' || $dados['tipo_operacao'] === '') {
                throw new RuntimeException('Informe adquirente, grupo e tipo.');
            }

            if ($id > 0) {
                $stmt = $pdo_master->prepare("
                    UPDATE fechamento_adquirente_taxas
                    SET adquirente = ?, grupo = ?, tipo_operacao = ?, bandeira = ?,
                        parcelas_de = ?, parcelas_ate = ?, taxa_percentual = ?,
                        tolerancia_percentual = ?, ativo = ?
                    WHERE id = ? AND empresa_id = ?
                ");
                $stmt->execute([
                    $dados['adquirente'], $dados['grupo'], $dados['tipo_operacao'], $dados['bandeira'],
                    $dados['parcelas_de'], $dados['parcelas_ate'], $dados['taxa_percentual'],
                    $dados['tolerancia_percentual'], $dados['ativo'], $id, $empresaId,
                ]);
            } else {
                $stmt = $pdo_master->prepare("
                    INSERT INTO fechamento_adquirente_taxas (
                        empresa_id, adquirente, grupo, tipo_operacao, bandeira,
                        parcelas_de, parcelas_ate, taxa_percentual, tolerancia_percentual, ativo
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $empresaId, $dados['adquirente'], $dados['grupo'], $dados['tipo_operacao'], $dados['bandeira'],
                    $dados['parcelas_de'], $dados['parcelas_ate'], $dados['taxa_percentual'],
                    $dados['tolerancia_percentual'], $dados['ativo'],
                ]);
            }

            header('Location: importacao_recebimentos.php?msg=taxa_salva');
            exit;
        } elseif ($acao === 'desativar_taxa') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo_master->prepare("UPDATE fechamento_adquirente_taxas SET ativo = 'N' WHERE id = ? AND empresa_id = ?");
            $stmt->execute([$id, $empresaId]);
            header('Location: importacao_recebimentos.php?msg=taxa_desativada');
            exit;
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$taxaEditar = null;
if (isset($_GET['editar'])) {
    $stmtEditar = $pdo_master->prepare("SELECT * FROM fechamento_adquirente_taxas WHERE id = ? AND empresa_id = ?");
    $stmtEditar->execute([(int)$_GET['editar'], $empresaId]);
    $taxaEditar = $stmtEditar->fetch(PDO::FETCH_ASSOC) ?: null;
}

$stmtTaxas = $pdo_master->prepare("
    SELECT *
    FROM fechamento_adquirente_taxas
    WHERE empresa_id = ?
    ORDER BY ativo DESC, adquirente, grupo, tipo_operacao, bandeira, parcelas_de
");
$stmtTaxas->execute([$empresaId]);
$taxas = $stmtTaxas->fetchAll(PDO::FETCH_ASSOC);

$taxasAtivas = array_values(array_filter($taxas, static fn($taxa) => ($taxa['ativo'] ?? 'N') === 'S'));

$filtroDataIni = $_GET['data_ini'] ?? date('Y-m-01');
$filtroDataFim = $_GET['data_fim'] ?? date('Y-m-d');
$filtroAdquirente = strtoupper(trim((string)($_GET['adquirente'] ?? '')));
$filtroGrupo = strtoupper(trim((string)($_GET['grupo'] ?? '')));
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

if ($filtroAdquirente !== '') {
    $where[] = origemAdquirenteSql() . " = ?";
    $params[] = $filtroAdquirente;
}

if ($filtroGrupo !== '') {
    $where[] = origemGrupoSql() . " = ?";
    $params[] = $filtroGrupo;
}

$sqlRelatorios = "
    SELECT
        arquivo_origem,
        origem,
        " . origemAdquirenteSql() . " AS adquirente,
        " . origemGrupoSql() . " AS grupo,
        tipo_operacao,
        COALESCE(NULLIF(bandeira, ''), 'TODAS') AS bandeira,
        COALESCE(NULLIF(total_parcelas, 0), 1) AS parcelas,
        COUNT(*) AS qtd,
        COALESCE(SUM(valor_bruto), 0) AS total_bruto,
        COALESCE(SUM(valor_desconto), 0) AS total_taxa,
        COALESCE(SUM(valor_liquido), 0) AS total_liquido,
        MIN(data_venda) AS data_ini,
        MAX(data_venda) AS data_fim,
        MIN(criado_em) AS importado_em
    FROM armazem_conciliacao_recebimentos
    WHERE " . implode(' AND ', $where) . "
    GROUP BY arquivo_origem, origem, adquirente, grupo, tipo_operacao, bandeira, parcelas
    ORDER BY importado_em DESC, arquivo_origem, tipo_operacao, bandeira
    LIMIT 500
";

$stmtRelatorios = $pdo_master->prepare($sqlRelatorios);
$stmtRelatorios->execute($params);
$relatorios = $stmtRelatorios->fetchAll(PDO::FETCH_ASSOC);

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
    return abs((float)$relatorio['total_taxa']) > 0.0001;
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

$relatorios = array_values(array_filter($relatorios, function ($relatorio) use ($taxasAtivas, $filtroSituacao) {
    if ($filtroSituacao === 'todos') {
        return true;
    }

    $situacao = situacaoTaxaRelatorio($relatorio, $taxasAtivas);
    return match ($filtroSituacao) {
        'divergentes' => $situacao['texto'] === 'Divergente',
        'sem_taxa' => $situacao['texto'] === 'Sem taxa cadastrada',
        'sem_taxa_demonstrada' => $situacao['texto'] === 'Sem taxa demonstrada',
        'ok' => $situacao['texto'] === 'OK',
        default => true,
    };
}));

$adquirentes = ['GRANITO', 'SIPAG', 'PAGSEGURO'];
$grupos = ['COMERCIAL', 'OUTROS', 'GERAL'];
$tipos = ['DEBITO', 'CREDITO', 'PIX'];
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-warning mb-3">Recebimentos</span>
                <h1 class="h3 fw-bold mb-2">Importacao de Recebimentos</h1>
                <p class="text-muted mb-0">Cadastre taxas e condicoes das adquirentes e acompanhe os relatorios importados.</p>
                <p class="text-muted small mt-2 mb-0">Na Granito, o arquivo de transacoes fica como base operacional; a conferencia de taxa usa o arquivo de agenda, quando ele traz a taxa/desconto demonstrado.</p>
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

<section class="mb-4">
    <div class="card shadow-sm">
        <div class="card-header">
            <h2 class="h5 mb-0"><?= $taxaEditar ? 'Editar taxa' : 'Cadastrar taxa' ?></h2>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="acao" value="salvar_taxa">
                <input type="hidden" name="id" value="<?= (int)($taxaEditar['id'] ?? 0) ?>">

                <div class="col-md-3">
                    <label class="form-label">Adquirente</label>
                    <select name="adquirente" class="form-select" required>
                        <?php foreach ($adquirentes as $adquirente): ?>
                            <option value="<?= $adquirente ?>" <?= (($taxaEditar['adquirente'] ?? '') === $adquirente) ? 'selected' : '' ?>><?= $adquirente ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Grupo</label>
                    <select name="grupo" class="form-select" required>
                        <?php foreach ($grupos as $grupo): ?>
                            <option value="<?= $grupo ?>" <?= (($taxaEditar['grupo'] ?? '') === $grupo) ? 'selected' : '' ?>><?= $grupo ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Tipo</label>
                    <select name="tipo_operacao" class="form-select" required>
                        <?php foreach ($tipos as $tipo): ?>
                            <option value="<?= $tipo ?>" <?= (($taxaEditar['tipo_operacao'] ?? '') === $tipo) ? 'selected' : '' ?>><?= $tipo ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Bandeira</label>
                    <input type="text" name="bandeira" class="form-control" value="<?= htmlspecialchars((string)($taxaEditar['bandeira'] ?? 'TODAS')) ?>" placeholder="TODAS, VISA, MASTERCARD">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Parcelas de</label>
                    <input type="number" name="parcelas_de" min="1" class="form-control" value="<?= (int)($taxaEditar['parcelas_de'] ?? 1) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Parcelas ate</label>
                    <input type="number" name="parcelas_ate" min="1" class="form-control" value="<?= (int)($taxaEditar['parcelas_ate'] ?? 1) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Taxa acordada %</label>
                    <input type="number" step="0.0001" name="taxa_percentual" min="0" class="form-control" value="<?= htmlspecialchars((string)($taxaEditar['taxa_percentual'] ?? '0.0000')) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Tolerancia %</label>
                    <input type="number" step="0.0001" name="tolerancia_percentual" min="0" class="form-control" value="<?= htmlspecialchars((string)($taxaEditar['tolerancia_percentual'] ?? '0.0500')) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Ativo</label>
                    <select name="ativo" class="form-select">
                        <option value="S" <?= (($taxaEditar['ativo'] ?? 'S') === 'S') ? 'selected' : '' ?>>Sim</option>
                        <option value="N" <?= (($taxaEditar['ativo'] ?? 'S') === 'N') ? 'selected' : '' ?>>Nao</option>
                    </select>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary"><?= $taxaEditar ? 'Salvar alteracao' : 'Cadastrar taxa' ?></button>
                    <?php if ($taxaEditar): ?>
                        <a href="importacao_recebimentos.php" class="btn btn-outline-secondary">Cancelar edicao</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</section>

<section class="mb-4">
    <div class="card shadow-sm">
        <div class="card-header">
            <h2 class="h5 mb-0">Taxas cadastradas</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Adquirente</th>
                        <th>Grupo</th>
                        <th>Tipo</th>
                        <th>Bandeira</th>
                        <th>Parcelas</th>
                        <th class="text-end">Taxa %</th>
                        <th class="text-end">Tol. %</th>
                        <th>Status</th>
                        <th class="text-end">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($taxas as $taxa): ?>
                        <tr>
                            <td><?= htmlspecialchars($taxa['adquirente']) ?></td>
                            <td><?= htmlspecialchars($taxa['grupo']) ?></td>
                            <td><?= htmlspecialchars($taxa['tipo_operacao']) ?></td>
                            <td><?= htmlspecialchars($taxa['bandeira']) ?></td>
                            <td><?= (int)$taxa['parcelas_de'] ?> a <?= (int)$taxa['parcelas_ate'] ?></td>
                            <td class="text-end"><?= number_format((float)$taxa['taxa_percentual'], 4, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$taxa['tolerancia_percentual'], 4, ',', '.') ?></td>
                            <td><span class="badge text-bg-<?= $taxa['ativo'] === 'S' ? 'success' : 'secondary' ?>"><?= $taxa['ativo'] === 'S' ? 'Ativa' : 'Inativa' ?></span></td>
                            <td class="text-end">
                                <a href="?editar=<?= (int)$taxa['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                <?php if ($taxa['ativo'] === 'S'): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Desativar esta taxa?')">
                                        <input type="hidden" name="acao" value="desativar_taxa">
                                        <input type="hidden" name="id" value="<?= (int)$taxa['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Desativar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($taxas)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Nenhuma taxa cadastrada.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section>
    <div class="card shadow-sm">
        <div class="card-header">
            <h2 class="h5 mb-0">Relatorios importados</h2>
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
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Arquivo</th>
                        <th>Periodo</th>
                        <th>Adq.</th>
                        <th>Grupo</th>
                        <th>Tipo</th>
                        <th>Bandeira</th>
                        <th>Parc.</th>
                        <th class="text-end">Qtd</th>
                        <th class="text-end">Bruto</th>
                        <th class="text-end">Taxa</th>
                        <th class="text-end">Taxa media</th>
                        <th class="text-end">Acordada</th>
                        <th>Sit.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($relatorios as $relatorio): ?>
                        <?php $situacao = situacaoTaxaRelatorio($relatorio, $taxasAtivas); ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars($relatorio['arquivo_origem'] ?: 'Sem arquivo') ?></td>
                            <td class="small">
                                <?= date('d/m/Y', strtotime($relatorio['data_ini'])) ?>
                                a <?= date('d/m/Y', strtotime($relatorio['data_fim'])) ?>
                            </td>
                            <td><?= htmlspecialchars($relatorio['adquirente']) ?></td>
                            <td><?= htmlspecialchars($relatorio['grupo']) ?></td>
                            <td><?= htmlspecialchars(rotuloTipoOperacao((string)$relatorio['tipo_operacao'])) ?></td>
                            <td><?= htmlspecialchars($relatorio['bandeira']) ?></td>
                            <td><?= (int)$relatorio['parcelas'] ?></td>
                            <td class="text-end"><?= (int)$relatorio['qtd'] ?></td>
                            <td class="text-end"><?= number_format((float)$relatorio['total_bruto'], 2, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$relatorio['total_taxa'], 2, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$situacao['taxa_media'], 4, ',', '.') ?>%</td>
                            <td class="text-end">
                                <?= $situacao['esperada'] ? number_format((float)$situacao['esperada']['taxa_percentual'], 4, ',', '.') . '%' : '-' ?>
                            </td>
                            <td><span class="badge text-bg-<?= htmlspecialchars($situacao['classe']) ?>"><?= htmlspecialchars($situacao['texto']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($relatorios)): ?>
                        <tr><td colspan="13" class="text-center text-muted py-4">Nenhum relatorio encontrado para os filtros.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
