<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require __DIR__ . '/_empresa2_guard.php';
require_once __DIR__ . '/_acertos_lib.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$erro = '';

mbaGarantirEstrutura($pdo);

$clicontador = (int)($_GET['clicontador'] ?? $_POST['clicontador'] ?? 0);
$fcontador = (int)($_GET['fcontador'] ?? $_POST['fcontador'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'fechar_acerto') {
    try {
        $acertoId = mbaFecharAcerto($pdo, $empresaId, $usuarioId, $_POST);
        header('Location: acertos.php?ok=' . $acertoId);
        exit;
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$stmtClientes = $pdo->prepare("
    SELECT CLICONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Cliente ', CLICONTADOR)) AS nome
    FROM armazem_cr002
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
      AND COALESCE(INATIVO, 'N') <> 'S'
    ORDER BY nome, CLICONTADOR
");
$stmtClientes->execute([$empresaId]);
$clientes = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);

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

$stmtContas = $pdo->prepare("
    SELECT CBCONTADOR, COALESCE(NULLIF(TITULAR, ''), NULLIF(DESCABREV, ''), CONCAT('Conta ', CBCONTADOR)) AS nome
    FROM armazem_bnc002
    WHERE EMPRESA = ?
      AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY nome, CBCONTADOR
");
$stmtContas->execute([$empresaId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

$titulosReceber = [];
if ($clicontador > 0) {
    $stmt = $pdo->prepare("
        SELECT CRCONTADOR, DTVENDA, DTVENC, TITULO, NOTAFISCAL, OBSERVACAO, TIPOES,
               COALESCE(NULLIF(VLRRESTANTE, 0), VLRPARCELA, 0) AS saldo
        FROM armazem_cr001
        WHERE EMPRESA = ? AND CLICONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(STATUS, 'AB') <> 'QT'
          AND COALESCE(NULLIF(VLRRESTANTE, 0), VLRPARCELA, 0) > 0
        ORDER BY DTVENC, CRCONTADOR
    ");
    $stmt->execute([$empresaId, $clicontador]);
    $titulosReceber = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$titulosPagar = [];
if ($fcontador > 0) {
    $stmt = $pdo->prepare("
        SELECT CPCONTADOR, DTCOMPRA, DTVENC, TITULO, NOTAFISCAL, OBSERVACAO, TIPOES,
               COALESCE(NULLIF(VLRRESTANTE, 0), VLRPARCELA, 0) AS saldo
        FROM armazem_cp001
        WHERE EMPRESA = ? AND FCONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(STATUS, 'AB') <> 'QT'
          AND COALESCE(NULLIF(VLRRESTANTE, 0), VLRPARCELA, 0) > 0
        ORDER BY DTVENC, CPCONTADOR
    ");
    $stmt->execute([$empresaId, $fcontador]);
    $titulosPagar = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$stmtHistorico = $pdo->prepare("
    SELECT a.*, cli.nome AS cliente_nome, forn.nome AS fornecedor_nome, conta.nome AS conta_nome,
           (SELECT COUNT(*) FROM movimentacao_baixa_acerto_itens i WHERE i.acerto_id = a.id AND i.tipo_titulo = 'CR') AS qtd_cr,
           (SELECT COUNT(*) FROM movimentacao_baixa_acerto_itens i WHERE i.acerto_id = a.id AND i.tipo_titulo = 'CP') AS qtd_cp
    FROM movimentacao_baixa_acertos a
    LEFT JOIN (
        SELECT EMPRESA, CLICONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME) AS nome FROM armazem_cr002
    ) cli ON cli.EMPRESA = a.empresa_id AND cli.CLICONTADOR = a.clicontador
    LEFT JOIN (
        SELECT EMPRESA, FCONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME) AS nome FROM armazem_cp003
    ) forn ON forn.EMPRESA = a.empresa_id AND forn.FCONTADOR = a.fcontador
    LEFT JOIN (
        SELECT EMPRESA, CBCONTADOR, COALESCE(NULLIF(TITULAR, ''), NULLIF(DESCABREV, ''), CONCAT('Conta ', CBCONTADOR)) AS nome FROM armazem_bnc002
    ) conta ON conta.EMPRESA = a.empresa_id AND conta.CBCONTADOR = a.cbcontador
    WHERE a.empresa_id = ?
    ORDER BY a.id DESC
    LIMIT 50
");
$stmtHistorico->execute([$empresaId]);
$historico = $stmtHistorico->fetchAll(PDO::FETCH_ASSOC);

require '../../layout/header.php';
?>

<style>
    .mba-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1rem; }
    .mba-table-wrap { max-height: 520px; overflow-y: auto; overflow-x: hidden; }
    .mba-table { width: 100%; table-layout: fixed; font-size: .82rem; }
    .mba-table th, .mba-table td { padding: .35rem .3rem; }
    .mba-table th:nth-child(1), .mba-table td:nth-child(1) { width: 6%; }
    .mba-table th:nth-child(2), .mba-table td:nth-child(2) { width: 10%; }
    .mba-table th:nth-child(3), .mba-table td:nth-child(3),
    .mba-table th:nth-child(4), .mba-table td:nth-child(4) { width: 15%; white-space: nowrap; }
    .mba-table th:nth-child(5), .mba-table td:nth-child(5) { width: 25%; overflow-wrap: anywhere; }
    .mba-table th:nth-child(6), .mba-table td:nth-child(6) { width: 10%; }
    .mba-table th:nth-child(7), .mba-table td:nth-child(7) { width: 19%; white-space: nowrap; }
    .mba-table th { position: sticky; top: 0; z-index: 1; }
    .mba-summary-value { font-size: 1.05rem; font-weight: 700; }
    @media (max-width: 1199.98px) { .mba-grid { grid-template-columns: 1fr; } }
</style>

<section class="mb-3">
    <div class="bg-white border rounded-2 shadow-sm p-3 p-lg-4 d-flex flex-wrap justify-content-between gap-3 align-items-center">
        <div>
            <span class="badge text-bg-primary mb-2">Movimentacao/Baixa</span>
            <h1 class="h4 fw-bold mb-1">Acerto Cliente x Fornecedor</h1>
            <p class="text-muted mb-0">Selecione titulos abertos e quite os dois lados na mesma conta.</p>
        </div>
        <a href="menu_movimentacao_baixa.php" class="btn btn-outline-secondary">Voltar</a>
    </div>
</section>

<?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">Acerto #<?= (int)$_GET['ok'] ?> fechado com sucesso.</div>
<?php endif; ?>
<?php if ($erro !== ''): ?>
    <div class="alert alert-danger"><?= mbaH($erro) ?></div>
<?php endif; ?>

<section class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Selecionar as partes</div>
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-12 col-lg-5">
                <label class="form-label">Cliente</label>
                <select name="clicontador" class="form-select" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($clientes as $cliente): ?>
                        <option value="<?= (int)$cliente['CLICONTADOR'] ?>" <?= $clicontador === (int)$cliente['CLICONTADOR'] ? 'selected' : '' ?>>
                            <?= mbaH($cliente['nome'] . ' (' . $cliente['CLICONTADOR'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-lg-5">
                <label class="form-label">Fornecedor</label>
                <select name="fcontador" class="form-select" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($fornecedores as $fornecedor): ?>
                        <option value="<?= (int)$fornecedor['FCONTADOR'] ?>" <?= $fcontador === (int)$fornecedor['FCONTADOR'] ? 'selected' : '' ?>>
                            <?= mbaH($fornecedor['nome'] . ' (' . $fornecedor['FCONTADOR'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-lg-2 d-grid">
                <button class="btn btn-primary">Buscar titulos</button>
            </div>
        </form>
    </div>
</section>

<?php if ($clicontador > 0 && $fcontador > 0): ?>
<form method="post" id="form-acerto">
    <input type="hidden" name="acao" value="fechar_acerto">
    <input type="hidden" name="clicontador" value="<?= $clicontador ?>">
    <input type="hidden" name="fcontador" value="<?= $fcontador ?>">

    <section class="mba-grid mb-3">
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
                <strong>Contas a receber</strong>
                <span class="badge text-bg-success"><?= count($titulosReceber) ?> aberto(s)</span>
            </div>
            <div class="mba-table-wrap">
                <table class="table table-sm table-hover align-middle mb-0 mba-table">
                    <thead class="table-light">
                        <tr>
                            <th style="width:36px"><input type="checkbox" class="form-check-input" id="todos-cr" title="Selecionar todos"></th>
                            <th>CR</th><th>Emissao</th><th>Vencimento</th><th>Documento</th><th>TIPOES</th><th class="text-end">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($titulosReceber as $titulo): ?>
                            <tr>
                                <td><input type="checkbox" class="form-check-input titulo-check cr-check" name="crcontadores[]" value="<?= (int)$titulo['CRCONTADOR'] ?>" data-valor="<?= (float)$titulo['saldo'] ?>"></td>
                                <td><?= (int)$titulo['CRCONTADOR'] ?></td>
                                <td><?= mbaData($titulo['DTVENDA']) ?></td>
                                <td><?= mbaData($titulo['DTVENC']) ?></td>
                                <td><?= mbaH($titulo['TITULO'] ?: ($titulo['NOTAFISCAL'] ?: '-')) ?></td>
                                <td><?= (int)$titulo['TIPOES'] ?></td>
                                <td class="text-end fw-semibold"><?= mbaMoeda($titulo['saldo']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$titulosReceber): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum titulo aberto.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
                <strong>Contas a pagar</strong>
                <span class="badge text-bg-danger"><?= count($titulosPagar) ?> aberto(s)</span>
            </div>
            <div class="mba-table-wrap">
                <table class="table table-sm table-hover align-middle mb-0 mba-table">
                    <thead class="table-light">
                        <tr>
                            <th style="width:36px"><input type="checkbox" class="form-check-input" id="todos-cp" title="Selecionar todos"></th>
                            <th>CP</th><th>Emissao</th><th>Vencimento</th><th>Documento</th><th>TIPOES</th><th class="text-end">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($titulosPagar as $titulo): ?>
                            <tr>
                                <td><input type="checkbox" class="form-check-input titulo-check cp-check" name="cpcontadores[]" value="<?= (int)$titulo['CPCONTADOR'] ?>" data-valor="<?= (float)$titulo['saldo'] ?>"></td>
                                <td><?= (int)$titulo['CPCONTADOR'] ?></td>
                                <td><?= mbaData($titulo['DTCOMPRA']) ?></td>
                                <td><?= mbaData($titulo['DTVENC']) ?></td>
                                <td><?= mbaH($titulo['TITULO'] ?: ($titulo['NOTAFISCAL'] ?: '-')) ?></td>
                                <td><?= (int)$titulo['TIPOES'] ?></td>
                                <td class="text-end fw-semibold"><?= mbaMoeda($titulo['saldo']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$titulosPagar): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum titulo aberto.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">Fechar acerto</div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-6 col-lg-2"><div class="text-muted small">Selecionados CR</div><div class="mba-summary-value" id="qtd-cr">0</div></div>
                <div class="col-6 col-lg-2"><div class="text-muted small">Total a receber</div><div class="mba-summary-value text-success" id="total-cr">R$ 0,00</div></div>
                <div class="col-6 col-lg-2"><div class="text-muted small">Selecionados CP</div><div class="mba-summary-value" id="qtd-cp">0</div></div>
                <div class="col-6 col-lg-2"><div class="text-muted small">Total a pagar</div><div class="mba-summary-value text-danger" id="total-cp">R$ 0,00</div></div>
                <div class="col-12 col-lg-4"><div class="text-muted small">Saldo (creditos - debitos)</div><div class="mba-summary-value" id="saldo-acerto">R$ 0,00</div></div>
                <div class="col-12 col-lg-5">
                    <label class="form-label">Conta para quitacao</label>
                    <select name="cbcontador" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($contas as $conta): ?>
                            <option value="<?= (int)$conta['CBCONTADOR'] ?>"><?= mbaH($conta['nome'] . ' (' . $conta['CBCONTADOR'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label">Data do acerto</label>
                    <input type="date" name="data_acerto" class="form-control" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-12 col-sm-6 col-lg-4 d-grid">
                    <button type="submit" class="btn btn-success" id="btn-fechar" disabled>Fechar acerto</button>
                </div>
            </div>
        </div>
    </section>
</form>
<?php endif; ?>

<section class="card shadow-sm">
    <div class="card-header bg-white fw-semibold">Acertos realizados</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Data</th><th>Cliente</th><th>Fornecedor</th><th>Conta</th><th class="text-end">Receber</th><th class="text-end">Pagar</th><th class="text-end">Saldo</th><th>Itens</th></tr></thead>
            <tbody>
                <?php foreach ($historico as $acerto): ?>
                    <tr>
                        <td><?= (int)$acerto['id'] ?></td><td><?= mbaData($acerto['data_acerto']) ?></td>
                        <td><?= mbaH($acerto['cliente_nome'] ?: 'Cliente ' . $acerto['clicontador']) ?></td>
                        <td><?= mbaH($acerto['fornecedor_nome'] ?: 'Fornecedor ' . $acerto['fcontador']) ?></td>
                        <td><?= mbaH($acerto['conta_nome'] ?: 'Conta ' . $acerto['cbcontador']) ?></td>
                        <td class="text-end text-success"><?= mbaMoeda($acerto['total_receber']) ?></td>
                        <td class="text-end text-danger"><?= mbaMoeda($acerto['total_pagar']) ?></td>
                        <td class="text-end fw-semibold"><?= mbaMoeda($acerto['saldo']) ?></td>
                        <td><?= (int)$acerto['qtd_cr'] ?> CR / <?= (int)$acerto['qtd_cp'] ?> CP</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$historico): ?><tr><td colspan="9" class="text-center text-muted py-4">Nenhum acerto realizado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
(() => {
    const moeda = valor => new Intl.NumberFormat('pt-BR', {style: 'currency', currency: 'BRL'}).format(valor);
    const checks = seletor => Array.from(document.querySelectorAll(seletor));
    const atualizar = () => {
        const cr = checks('.cr-check:checked');
        const cp = checks('.cp-check:checked');
        const totalCr = cr.reduce((s, el) => s + Number(el.dataset.valor || 0), 0);
        const totalCp = cp.reduce((s, el) => s + Number(el.dataset.valor || 0), 0);
        document.getElementById('qtd-cr').textContent = cr.length;
        document.getElementById('qtd-cp').textContent = cp.length;
        document.getElementById('total-cr').textContent = moeda(totalCr);
        document.getElementById('total-cp').textContent = moeda(totalCp);
        const saldo = totalCr - totalCp;
        const saldoEl = document.getElementById('saldo-acerto');
        saldoEl.textContent = moeda(Math.abs(saldo)) + (Math.abs(saldo) < .005 ? '' : (saldo > 0 ? ' C' : ' D'));
        saldoEl.className = 'mba-summary-value ' + (saldo > 0 ? 'text-success' : (saldo < 0 ? 'text-danger' : ''));
        document.getElementById('btn-fechar').disabled = cr.length + cp.length === 0;
    };
    const ligarTodos = (id, classe) => {
        const geral = document.getElementById(id);
        if (!geral) return;
        geral.addEventListener('change', () => {
            checks(classe).forEach(el => { el.checked = geral.checked; });
            atualizar();
        });
    };
    checks('.titulo-check').forEach(el => el.addEventListener('change', atualizar));
    ligarTodos('todos-cr', '.cr-check');
    ligarTodos('todos-cp', '.cp-check');
    const form = document.getElementById('form-acerto');
    if (form) form.addEventListener('submit', () => {
        const botao = document.getElementById('btn-fechar');
        botao.disabled = true;
        botao.textContent = 'Fechando...';
    });
    atualizar();
})();
</script>

<?php require __DIR__ . '/_select_busca.php'; ?>
<?php require '../../layout/footer.php'; ?>
