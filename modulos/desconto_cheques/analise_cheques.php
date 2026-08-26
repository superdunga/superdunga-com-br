<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
if ($empresaId !== 2 || !moduloPermitido($pdo, $empresaId, 'desconto_cheques_analise')) {
    http_response_code(403);
    exit('Acesso permitido somente para a empresa 2.');
}

garantirTabelasDescontoCheques($pdo);

$fCliente = (int)($_GET['cliente_id'] ?? 0);
$fVencIni = trim((string)($_GET['venc_ini'] ?? ''));
$fVencFim = trim((string)($_GET['venc_fim'] ?? ''));
$fOperIni = trim((string)($_GET['oper_ini'] ?? ''));
$fOperFim = trim((string)($_GET['oper_fim'] ?? ''));
$fCnpj = preg_replace('/\D+/', '', (string)($_GET['cnpj'] ?? ''));
$fEmissor = trim((string)($_GET['emissor'] ?? ''));
$fNumero = trim((string)($_GET['numero'] ?? ''));
$fValorMin = trim((string)($_GET['valor_min'] ?? ''));
$fValorMax = trim((string)($_GET['valor_max'] ?? ''));
$fStatus = trim((string)($_GET['status'] ?? ''));
$fImagem = trim((string)($_GET['imagem'] ?? 'todos'));

function acqH($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function acqFloat(string $valor): ?float
{
    $valor = trim($valor);
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

function acqImagemUrl(?string $caminho): string
{
    $caminho = ltrim(trim((string)$caminho), '/\\');
    if ($caminho === '' || strpos($caminho, '..') !== false) {
        return '';
    }
    return '../../' . str_replace('\\', '/', $caminho);
}

$where = ["o.empresa_id = ?", "d.tipo_documento = 'CHEQUE'"];
$params = [$empresaId];
if ($fCliente > 0) {
    $where[] = 'o.cliente_id = ?';
    $params[] = $fCliente;
}
foreach ([['d.data_vencimento', '>=', $fVencIni], ['d.data_vencimento', '<=', $fVencFim], ['o.data_referencia', '>=', $fOperIni], ['o.data_referencia', '<=', $fOperFim]] as [$campo, $operador, $valor]) {
    if ($valor !== '') {
        $where[] = "$campo $operador ?";
        $params[] = $valor;
    }
}
if ($fCnpj !== '') {
    $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(d.cnpj_cpf_emissor, '.', ''), '/', ''), '-', ''), ' ', '') LIKE ?";
    $params[] = '%' . $fCnpj . '%';
}
if ($fEmissor !== '') {
    $where[] = 'd.nome_emissor LIKE ?';
    $params[] = '%' . $fEmissor . '%';
}
if ($fNumero !== '') {
    $where[] = 'd.numero_documento LIKE ?';
    $params[] = '%' . $fNumero . '%';
}
$valorMin = acqFloat($fValorMin);
if ($valorMin !== null) {
    $where[] = 'd.valor >= ?';
    $params[] = $valorMin;
}
$valorMax = acqFloat($fValorMax);
if ($valorMax !== null) {
    $where[] = 'd.valor <= ?';
    $params[] = $valorMax;
}
if ($fStatus !== '') {
    $where[] = 'o.status = ?';
    $params[] = $fStatus;
}
$exprImagem = "COALESCE(NULLIF(d.arquivo_frente_caminho, ''), NULLIF(d.arquivo_caminho, ''), NULLIF(d.arquivo_verso_caminho, ''))";
if ($fImagem === 'com') {
    $where[] = "$exprImagem IS NOT NULL";
} elseif ($fImagem === 'sem') {
    $where[] = "$exprImagem IS NULL";
}

$whereSql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT d.*, o.data_referencia, o.status AS operacao_status, c.nome AS cliente_nome
    FROM desconto_cheques_documentos d
    INNER JOIN desconto_cheques_operacoes o ON o.id = d.operacao_id
    INNER JOIN desconto_cheques_clientes c ON c.id = o.cliente_id AND c.empresa_id = o.empresa_id
    WHERE $whereSql
    ORDER BY d.data_vencimento, o.data_referencia, d.id
");
$stmt->execute($params);
$cheques = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = array_sum(array_map(static function (array $cheque): float {
    return (float)$cheque['valor'];
}, $cheques));
$stmtClientes = $pdo->prepare('SELECT id, nome FROM desconto_cheques_clientes WHERE empresa_id = ? ORDER BY nome');
$stmtClientes->execute([$empresaId]);
$clientes = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);
$statusOperacoes = $pdo->prepare('SELECT DISTINCT status FROM desconto_cheques_operacoes WHERE empresa_id = ? ORDER BY status');
$statusOperacoes->execute([$empresaId]);
$statusOperacoes = $statusOperacoes->fetchAll(PDO::FETCH_COLUMN);

require '../../layout/header.php';
?>

<style>
    .acq-thumb { width:96px; height:58px; object-fit:cover; border:1px solid #cbd5e1; border-radius:6px; background:#f8fafc; }
    .acq-images { display:flex; gap:6px; align-items:center; min-width:102px; }
    .acq-table th { white-space:nowrap; }
    .acq-table td { vertical-align:middle; }
    .acq-nowrap { white-space:nowrap; }
</style>

<section class="mb-4">
    <div class="p-4 bg-white border rounded-2 shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="badge text-bg-success mb-2">Desconto de Cheques</span>
            <h1 class="h4 fw-bold mb-1">Analise de Cheques</h1>
            <p class="text-muted mb-0">Consulta consolidada dos cheques cadastrados na empresa 2.</p>
        </div>
        <a href="menu_desconto_cheques.php" class="btn btn-outline-secondary">Voltar</a>
    </div>
</section>

<section class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Filtros</div>
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3"><label class="form-label small">Cliente</label><select name="cliente_id" class="form-select"><option value="">Todos</option><?php foreach ($clientes as $cliente): ?><option value="<?= (int)$cliente['id'] ?>" <?= $fCliente === (int)$cliente['id'] ? 'selected' : '' ?>><?= acqH($cliente['nome']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label small">Vencimento inicial</label><input type="date" name="venc_ini" value="<?= acqH($fVencIni) ?>" class="form-control"></div>
            <div class="col-md-2"><label class="form-label small">Vencimento final</label><input type="date" name="venc_fim" value="<?= acqH($fVencFim) ?>" class="form-control"></div>
            <div class="col-md-2"><label class="form-label small">Operacao inicial</label><input type="date" name="oper_ini" value="<?= acqH($fOperIni) ?>" class="form-control"></div>
            <div class="col-md-2"><label class="form-label small">Operacao final</label><input type="date" name="oper_fim" value="<?= acqH($fOperFim) ?>" class="form-control"></div>
            <div class="col-md-2"><label class="form-label small">CPF/CNPJ emissor</label><input type="text" name="cnpj" value="<?= acqH($fCnpj) ?>" class="form-control"></div>
            <div class="col-md-3"><label class="form-label small">Nome do emissor</label><input type="text" name="emissor" value="<?= acqH($fEmissor) ?>" class="form-control"></div>
            <div class="col-md-2"><label class="form-label small">Numero do cheque</label><input type="text" name="numero" value="<?= acqH($fNumero) ?>" class="form-control"></div>
            <div class="col-md-2"><label class="form-label small">Valor minimo</label><input type="text" name="valor_min" value="<?= acqH($fValorMin) ?>" class="form-control" inputmode="decimal"></div>
            <div class="col-md-2"><label class="form-label small">Valor maximo</label><input type="text" name="valor_max" value="<?= acqH($fValorMax) ?>" class="form-control" inputmode="decimal"></div>
            <div class="col-md-2"><label class="form-label small">Status da operacao</label><select name="status" class="form-select"><option value="">Todos</option><?php foreach ($statusOperacoes as $status): ?><option value="<?= acqH($status) ?>" <?= $fStatus === $status ? 'selected' : '' ?>><?= acqH($status) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label small">Imagem</label><select name="imagem" class="form-select"><option value="todos" <?= $fImagem === 'todos' ? 'selected' : '' ?>>Todas</option><option value="com" <?= $fImagem === 'com' ? 'selected' : '' ?>>Com imagem</option><option value="sem" <?= $fImagem === 'sem' ? 'selected' : '' ?>>Sem imagem</option></select></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-success" type="submit">Filtrar</button><a href="analise_cheques.php" class="btn btn-outline-secondary">Limpar</a></div>
        </form>
    </div>
</section>

<section class="row g-3 mb-3">
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><small class="text-muted fw-semibold">CHEQUES</small><div class="h4 mb-0 mt-1"><?= number_format(count($cheques), 0, ',', '.') ?></div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><small class="text-muted fw-semibold">VALOR TOTAL</small><div class="h4 mb-0 mt-1"><?= acqH(moedaDC($total)) ?></div></div></div></div>
</section>

<section class="card shadow-sm">
    <div class="card-header bg-white fw-semibold">Cheques encontrados</div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 acq-table">
            <thead class="table-light"><tr><th>Cliente</th><th>Vencimento</th><th>Data operacao</th><th>CPF/CNPJ</th><th>Emissor</th><th>Cheque</th><th class="text-end">Valor</th><th>Status</th><th>Imagem</th></tr></thead>
            <tbody>
            <?php foreach ($cheques as $cheque): ?>
                <?php
                    $frente = acqImagemUrl($cheque['arquivo_frente_caminho'] ?: $cheque['arquivo_caminho']);
                    $verso = acqImagemUrl($cheque['arquivo_verso_caminho']);
                ?>
                <tr>
                    <td><?= acqH($cheque['cliente_nome']) ?></td>
                    <td class="acq-nowrap"><?= acqH(dataBRDC($cheque['data_vencimento'])) ?></td>
                    <td class="acq-nowrap"><a href="operacoes.php?editar=<?= (int)$cheque['operacao_id'] ?>">#<?= (int)$cheque['operacao_id'] ?> - <?= acqH(dataBRDC($cheque['data_referencia'])) ?></a></td>
                    <td class="acq-nowrap"><?= acqH(formatarCpfCnpjDC($cheque['cnpj_cpf_emissor'])) ?></td>
                    <td><?= acqH($cheque['nome_emissor'] ?: '-') ?></td>
                    <td><?= acqH($cheque['numero_documento'] ?: '-') ?></td>
                    <td class="text-end fw-semibold acq-nowrap"><?= acqH(moedaDC($cheque['valor'])) ?></td>
                    <td><span class="badge text-bg-secondary"><?= acqH($cheque['operacao_status']) ?></span></td>
                    <td>
                        <div class="acq-images">
                            <?php if ($frente !== ''): ?><a href="<?= acqH($frente) ?>" target="_blank" title="Abrir frente"><img src="<?= acqH($frente) ?>" class="acq-thumb" alt="Frente do cheque"></a><?php endif; ?>
                            <?php if ($verso !== ''): ?><a href="<?= acqH($verso) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Verso</a><?php endif; ?>
                            <?php if ($frente === '' && $verso === ''): ?><span class="text-muted">Sem imagem</span><?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$cheques): ?><tr><td colspan="9" class="text-center text-muted py-4">Nenhum cheque encontrado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
