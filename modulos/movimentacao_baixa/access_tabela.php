<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require __DIR__ . '/_empresa2_guard.php';
require __DIR__ . '/_access_legado_lib.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);

garantirTabelaMovBaixaLancamentosAccess($pdo);

function atH($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function atFloat($valor): ?float
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

function atMoeda($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function atData($valor): string
{
    return $valor ? date('d/m/Y', strtotime((string)$valor)) : '-';
}

function atTipoOrigem(array $linha): string
{
    $debito = (float)($linha['debito_origem'] ?? 0);
    $credito = (float)($linha['credito_origem'] ?? 0);
    if ($debito > 0 && $credito <= 0) {
        return 'D';
    }
    if ($credito > 0 && $debito <= 0) {
        return 'C';
    }
    return '';
}

function atValorCsv($valor): string
{
    return number_format((float)$valor, 2, ',', '');
}

function atDataCsv($valor): string
{
    return $valor ? date('d/m/Y', strtotime((string)$valor)) : '';
}

function atCsvLinha(array $linha): array
{
    return [
        $linha['codigo_origem'] ?? '',
        $linha['lancamento_origem'] ?? '',
        $linha['cod_empresa_origem'] ?? '',
        $linha['cod_conta_origem'] ?? '',
        $linha['cod_centro_custo_origem'] ?? '',
        $linha['cod_favorecido_origem'] ?? '',
        $linha['bandeira_origem'] ?? '',
        $linha['observacao_origem'] ?? '',
        atDataCsv($linha['data_pagamento_origem'] ?? ''),
        atDataCsv($linha['data_bom_para_origem'] ?? ''),
        atDataCsv($linha['data_emissao_origem'] ?? ''),
        $linha['cod_historico_origem'] ?? '',
        $linha['documento_origem'] ?? '',
        $linha['cheque_origem'] ?? '',
        $linha['nota_fiscal_origem'] ?? '',
        $linha['cheque_n_origem'] ?? '',
        atValorCsv($linha['valor_origem'] ?? 0),
        atValorCsv($linha['debito_origem'] ?? 0),
        atValorCsv($linha['credito_origem'] ?? 0),
        $linha['referencia_origem'] ?? '',
        $linha['conferido_origem'] ?? '',
        $linha['conferido2_origem'] ?? '',
        $linha['funcionario_origem'] ?? '',
        $linha['correntista_origem'] ?? '',
        $linha['enviado_superdunga'] ?? '',
        $linha['status_controle'] ?? '',
        $linha['resultado_comparacao'] ?? '',
        $linha['tabela_destino'] ?? '',
        $linha['id_destino'] ?? '',
        $linha['movcontador_destino'] ?? '',
        $linha['crcontador_destino'] ?? '',
        $linha['cpcontador_destino'] ?? '',
        atDataCsv($linha['enviado_em'] ?? ''),
        $linha['observacao_controle'] ?? '',
    ];
}

$colunasAccess = [
    'Código',
    'Lancamento',
    'CodEmpresa',
    'CodConta',
    'CodCentroCusto',
    'CodFavorecido',
    'Bandeira',
    'Observacao',
    'DataPagamento',
    'DataBomPara',
    'DataEmissao',
    'CodHistorico',
    'Documento',
    'Cheque',
    'NotaFiscal',
    'CHEQUE_N',
    'Vr',
    'Debito',
    'Credito',
    'Referencia',
    'Conferido',
    'Conferido2',
    'Funcionario',
    'Correntista',
    'EnviadoSuperDunga',
    'StatusControle',
    'ResultadoComparacao',
    'TabelaDestino',
    'IdDestino',
    'MovcontadorDestino',
    'CrcontadorDestino',
    'CpcontadorDestino',
    'EnviadoEm',
    'ObservacaoControle',
];

$fCodigo = trim((string)($_GET['codigo'] ?? ''));
$fLinha = trim((string)($_GET['linha'] ?? ''));
$fEmpresaOrigem = trim((string)($_GET['empresa_origem'] ?? ''));
$fConta = trim((string)($_GET['conta'] ?? ''));
$fCentro = trim((string)($_GET['centro'] ?? ''));
$fHistorico = trim((string)($_GET['historico'] ?? ''));
$fFavorecido = trim((string)($_GET['favorecido'] ?? ''));
$fObservacao = trim((string)($_GET['observacao'] ?? ''));
$fDocumento = trim((string)($_GET['documento'] ?? ''));
$fReferencia = trim((string)($_GET['referencia'] ?? ''));
$fFuncionario = trim((string)($_GET['funcionario'] ?? ''));
$fStatusOrigem = trim((string)($_GET['status_origem'] ?? ''));
$fControle = trim((string)($_GET['status_controle'] ?? ''));
$fEnviado = trim((string)($_GET['enviado'] ?? ''));
$fTipo = trim((string)($_GET['tipo'] ?? ''));
$fDataCampo = trim((string)($_GET['data_campo'] ?? 'pagamento'));
$fDataSituacao = trim((string)($_GET['data_situacao'] ?? 'todas'));
$fDataIni = trim((string)($_GET['data_ini'] ?? ''));
$fDataFim = trim((string)($_GET['data_fim'] ?? ''));
$fValorMin = trim((string)($_GET['valor_min'] ?? ''));
$fValorMax = trim((string)($_GET['valor_max'] ?? ''));

$where = ['empresa_id = ?'];
$params = [$empresaId];

if ($fEmpresaOrigem !== '') {
    $where[] = 'cod_empresa_origem LIKE ?';
    $params[] = '%' . $fEmpresaOrigem . '%';
}
if ($fConta !== '') {
    $where[] = 'cod_conta_origem LIKE ?';
    $params[] = '%' . $fConta . '%';
}
if ($fCentro !== '') {
    $where[] = 'cod_centro_custo_origem LIKE ?';
    $params[] = '%' . $fCentro . '%';
}
if ($fHistorico !== '') {
    $where[] = 'cod_historico_origem LIKE ?';
    $params[] = '%' . $fHistorico . '%';
}
if ($fFavorecido !== '') {
    $where[] = 'cod_favorecido_origem LIKE ?';
    $params[] = '%' . $fFavorecido . '%';
}
if ($fObservacao !== '') {
    $where[] = 'observacao_origem LIKE ?';
    $params[] = '%' . $fObservacao . '%';
}
if ($fDocumento !== '') {
    $where[] = '(documento_origem LIKE ? OR cheque_origem LIKE ? OR nota_fiscal_origem LIKE ? OR cheque_n_origem LIKE ?)';
    $params[] = '%' . $fDocumento . '%';
    $params[] = '%' . $fDocumento . '%';
    $params[] = '%' . $fDocumento . '%';
    $params[] = '%' . $fDocumento . '%';
}
if ($fReferencia !== '') {
    $where[] = 'referencia_origem LIKE ?';
    $params[] = '%' . $fReferencia . '%';
}
if ($fFuncionario !== '') {
    $where[] = 'funcionario_origem LIKE ?';
    $params[] = '%' . $fFuncionario . '%';
}
$campoDataSql = 'data_pagamento_origem';
if ($fDataCampo === 'emissao') {
    $campoDataSql = 'data_emissao_origem';
} elseif ($fDataCampo === 'bom_para') {
    $campoDataSql = 'data_bom_para_origem';
}

if ($fDataSituacao === 'sem_data') {
    $where[] = "{$campoDataSql} IS NULL";
} elseif ($fDataSituacao === 'com_data') {
    $where[] = "{$campoDataSql} IS NOT NULL";
}
if ($fDataIni !== '') {
    $where[] = "{$campoDataSql} >= ?";
    $params[] = $fDataIni;
}
if ($fDataFim !== '') {
    $where[] = "{$campoDataSql} <= ?";
    $params[] = $fDataFim;
}

$valorMin = atFloat($fValorMin);
if ($valorMin !== null) {
    $where[] = 'valor_origem >= ?';
    $params[] = $valorMin;
}
$valorMax = atFloat($fValorMax);
if ($valorMax !== null) {
    $where[] = 'valor_origem <= ?';
    $params[] = $valorMax;
}

$whereSql = implode(' AND ', array_map(static function ($item) {
    return '(' . $item . ')';
}, $where));

if (($_GET['exportar'] ?? '') === 'csv') {
    $stmtCsv = $pdo->prepare("
        SELECT *
        FROM mov_baixa_lancamentos_access
        WHERE {$whereSql}
        ORDER BY {$campoDataSql} DESC, linha_origem DESC
    ");
    $stmtCsv->execute($params);

    $nomeArquivo = 'tabela_access_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $colunasAccess, ';');
    while ($linhaCsv = $stmtCsv->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, atCsvLinha($linhaCsv), ';');
    }
    fclose($out);
    exit;
}

$stmtResumo = $pdo->prepare("
    SELECT
        COUNT(*) AS qtd,
        COALESCE(SUM(valor_origem), 0) AS total_valor,
        COALESCE(SUM(debito_origem), 0) AS total_debito,
        COALESCE(SUM(credito_origem), 0) AS total_credito,
        COALESCE(SUM(credito_origem - debito_origem), 0) AS saldo
    FROM mov_baixa_lancamentos_access
    WHERE {$whereSql}
");
$stmtResumo->execute($params);
$resumo = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: [];

$limit = 100;
$stmtLinhas = $pdo->prepare("
    SELECT *
    FROM mov_baixa_lancamentos_access
    WHERE {$whereSql}
    ORDER BY {$campoDataSql} DESC, linha_origem DESC
    LIMIT {$limit}
");
$stmtLinhas->execute($params);
$linhas = $stmtLinhas->fetchAll(PDO::FETCH_ASSOC);

require '../../layout/header.php';
?>

<style>
    .at-card { background:#fff; border:1px solid #dbe3ef; border-radius:8px; box-shadow:0 8px 24px rgba(15,23,42,.06); }
    .at-card .card-header { background:#f8fafc; border-bottom:1px solid #e2e8f0; font-weight:700; }
    .at-kpi { border:1px solid #e2e8f0; border-radius:8px; padding:14px; background:#fff; min-height:88px; }
    .at-kpi small { display:block; color:#64748b; font-size:12px; text-transform:uppercase; font-weight:700; }
    .at-kpi strong { display:block; color:#0f172a; font-size:19px; margin-top:6px; }
    .at-table { font-size:12px; }
    .at-table th { white-space:nowrap; color:#334155; background:#f1f5f9; }
    .at-table td { vertical-align:middle; }
    .at-code { font-family:Consolas, monospace; font-size:12px; }
    .at-truncate { max-width:260px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .at-filter-label { display:block; min-height:32px; line-height:1.15; margin-bottom:4px; overflow-wrap:anywhere; }
</style>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-secondary mb-3">Mov/Baixa</span>
                <h1 class="h3 fw-bold mb-2">Tabela Access</h1>
                <p class="text-muted mb-0">Consulta da tabela original importada do Access. Esta tela nao compara, nao vincula e nao faz lancamentos.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="access_legado.php" class="btn btn-outline-primary">Analise Access</a>
                <a href="menu_movimentacao_baixa.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<section class="mb-3">
    <div class="row g-3">
        <div class="col-md-2"><div class="at-kpi"><small>Registros filtrados</small><strong><?= number_format((int)($resumo['qtd'] ?? 0), 0, ',', '.') ?></strong></div></div>
        <div class="col-md-2"><div class="at-kpi"><small>Valor filtrado</small><strong><?= atH(atMoeda($resumo['total_valor'] ?? 0)) ?></strong></div></div>
        <div class="col-md-2"><div class="at-kpi"><small>Debito filtrado</small><strong><?= atH(atMoeda($resumo['total_debito'] ?? 0)) ?></strong></div></div>
        <div class="col-md-2"><div class="at-kpi"><small>Credito filtrado</small><strong><?= atH(atMoeda($resumo['total_credito'] ?? 0)) ?></strong></div></div>
        <div class="col-md-2"><div class="at-kpi"><small>Saldo credito - debito</small><strong><?= atH(atMoeda($resumo['saldo'] ?? 0)) ?></strong></div></div>
        <div class="col-md-2"><div class="at-kpi"><small>Registros carregados</small><strong><?= number_format($limit, 0, ',', '.') ?></strong></div></div>
    </div>
</section>

<section class="card at-card mb-3">
    <div class="card-header">Filtros da tabela Access</div>
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label at-filter-label">CodEmpresa</label>
                <input type="text" name="empresa_origem" class="form-control" value="<?= atH($fEmpresaOrigem) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">CodConta</label>
                <input type="text" name="conta" class="form-control" value="<?= atH($fConta) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">CodCentroCusto</label>
                <input type="text" name="centro" class="form-control" value="<?= atH($fCentro) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">CodFavorecido</label>
                <input type="text" name="favorecido" class="form-control" value="<?= atH($fFavorecido) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">Observacao</label>
                <input type="text" name="observacao" class="form-control" value="<?= atH($fObservacao) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">Data para filtrar</label>
                <select name="data_campo" class="form-select">
                    <option value="pagamento" <?= $fDataCampo === 'pagamento' ? 'selected' : '' ?>>DataPagamento</option>
                    <option value="bom_para" <?= $fDataCampo === 'bom_para' ? 'selected' : '' ?>>DataBomPara</option>
                    <option value="emissao" <?= $fDataCampo === 'emissao' ? 'selected' : '' ?>>DataEmissao</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">Situacao da data</label>
                <select name="data_situacao" class="form-select">
                    <option value="todas" <?= $fDataSituacao === 'todas' ? 'selected' : '' ?>>Todas</option>
                    <option value="com_data" <?= $fDataSituacao === 'com_data' ? 'selected' : '' ?>>Com data</option>
                    <option value="sem_data" <?= $fDataSituacao === 'sem_data' ? 'selected' : '' ?>>Sem data</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">Data inicial</label>
                <input type="date" name="data_ini" class="form-control" value="<?= atH($fDataIni) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">Data final</label>
                <input type="date" name="data_fim" class="form-control" value="<?= atH($fDataFim) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">CodHistorico</label>
                <input type="text" name="historico" class="form-control" value="<?= atH($fHistorico) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label at-filter-label">Documento / Cheque / NF / CHEQUE_N</label>
                <input type="text" name="documento" class="form-control" value="<?= atH($fDocumento) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">Vr inicial</label>
                <input type="text" name="valor_min" inputmode="decimal" class="form-control" value="<?= atH($fValorMin) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">Vr final</label>
                <input type="text" name="valor_max" inputmode="decimal" class="form-control" value="<?= atH($fValorMax) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">Referencia</label>
                <input type="text" name="referencia" class="form-control" value="<?= atH($fReferencia) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label at-filter-label">Funcionario</label>
                <input type="text" name="funcionario" class="form-control" value="<?= atH($fFuncionario) ?>">
            </div>
            <div class="col-md-12 d-flex gap-2">
                <button type="submit" class="btn btn-secondary">Filtrar</button>
                <button type="submit" name="exportar" value="csv" class="btn btn-outline-primary">Exportar CSV</button>
                <a href="access_tabela.php" class="btn btn-outline-secondary">Limpar</a>
            </div>
        </form>
    </div>
</section>

<section class="card at-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Registros da tabela Access</span>
        <small class="text-muted">Consulta direta da carga importada; mostrando ate <?= (int)$limit ?> linhas.</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm at-table mb-0">
                <thead>
                    <tr>
                        <?php foreach ($colunasAccess as $colunaAccess): ?>
                            <th><?= atH($colunaAccess) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linhas as $linha): ?>
                        <tr>
                            <td class="at-code"><?= atH($linha['codigo_origem']) ?></td>
                            <td><?= atH($linha['lancamento_origem']) ?></td>
                            <td><?= atH($linha['cod_empresa_origem']) ?></td>
                            <td><?= atH($linha['cod_conta_origem']) ?></td>
                            <td><?= atH($linha['cod_centro_custo_origem']) ?></td>
                            <td class="at-truncate" title="<?= atH($linha['cod_favorecido_origem']) ?>"><?= atH($linha['cod_favorecido_origem']) ?></td>
                            <td><?= atH($linha['bandeira_origem']) ?></td>
                            <td class="at-truncate" title="<?= atH($linha['observacao_origem']) ?>"><?= atH($linha['observacao_origem']) ?></td>
                            <td><?= atH(atData($linha['data_pagamento_origem'])) ?></td>
                            <td><?= atH(atData($linha['data_bom_para_origem'])) ?></td>
                            <td><?= atH(atData($linha['data_emissao_origem'])) ?></td>
                            <td class="at-truncate" title="<?= atH($linha['cod_historico_origem']) ?>"><?= atH($linha['cod_historico_origem']) ?></td>
                            <td><?= atH($linha['documento_origem']) ?></td>
                            <td><?= atH($linha['cheque_origem']) ?></td>
                            <td><?= atH($linha['nota_fiscal_origem']) ?></td>
                            <td><?= atH($linha['cheque_n_origem']) ?></td>
                            <td><?= atH(atMoeda($linha['valor_origem'])) ?></td>
                            <td><?= atH(atMoeda($linha['debito_origem'])) ?></td>
                            <td><?= atH(atMoeda($linha['credito_origem'])) ?></td>
                            <td><?= atH($linha['referencia_origem']) ?></td>
                            <td><?= atH($linha['conferido_origem']) ?></td>
                            <td><?= atH($linha['conferido2_origem']) ?></td>
                            <td class="at-truncate" title="<?= atH($linha['funcionario_origem']) ?>"><?= atH($linha['funcionario_origem']) ?></td>
                            <td class="at-truncate" title="<?= atH($linha['correntista_origem']) ?>"><?= atH($linha['correntista_origem']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$linhas): ?>
                        <tr><td colspan="<?= count($colunasAccess) ?>" class="text-center text-muted py-4">Nenhum registro encontrado para os filtros.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
