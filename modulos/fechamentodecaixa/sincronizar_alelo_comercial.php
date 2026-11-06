<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';
require_once '../../config/alelo_edi.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$regraImportacao = buscarRegraImportacao(
    $pdo_master,
    $empresaId,
    'alelo_edi_comercial',
    regraImportacaoAleloComercialArmazem()
);
$configAlelo = aleloConfiguracaoEmpresa1();
$statusCredenciais = aleloStatusCredenciais($configAlelo);
$mensagem = null;
$mensagemTipo = 'success';
$resultado = null;
$dataFinal = date('Y-m-d');
$dataInicial = date('Y-m-d', strtotime('-7 days'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dataInicial = trim((string)($_POST['data_inicial'] ?? ''));
    $dataFinal = trim((string)($_POST['data_final'] ?? ''));
}

if ($regraImportacao && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $tokenSessao = (string)($_SESSION['alelo_sync_token'] ?? '');
        $tokenPost = (string)($_POST['token'] ?? '');
        if ($tokenSessao === '' || !hash_equals($tokenSessao, $tokenPost)) {
            throw new RuntimeException('Sessao expirada. Atualize a pagina e tente novamente.');
        }
        $resultado = aleloSincronizarRecebimentos(
            $pdo_master,
            $empresaId,
            $regraImportacao,
            $configAlelo,
            $dataInicial,
            $dataFinal
        );
        $mensagem = $resultado['mensagem'];
    } catch (Throwable $e) {
        $mensagemTipo = 'danger';
        $mensagem = $e->getMessage();
    }
}

if (empty($_SESSION['alelo_sync_token'])) {
    $_SESSION['alelo_sync_token'] = bin2hex(random_bytes(24));
}

$historico = [];
if ($regraImportacao) {
    try {
        garantirTabelasAlelo($pdo_master);
        $stmtHistorico = $pdo_master->prepare("SELECT * FROM fechamento_alelo_sincronizacoes
            WHERE empresa_id = ? AND integracao = ? ORDER BY id DESC LIMIT 10");
        $stmtHistorico->execute([$empresaId, $configAlelo['chave']]);
        $historico = $stmtHistorico->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if ($mensagem === null) {
            $mensagemTipo = 'danger';
            $mensagem = 'Nao foi possivel carregar o historico da ALELO.';
        }
    }
}

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 bg-white border rounded-2 shadow-sm">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <span class="badge text-bg-primary mb-2">Comercial</span>
                <h1 class="h4 fw-bold mb-1">ALELO EDI</h1>
                <p class="text-muted mb-0">Captura automatica dos arquivos transacionais e de pagamento pela caixa postal SFTP.</p>
            </div>
            <a href="importar_recebimentos.php" class="btn btn-outline-secondary">Voltar</a>
        </div>
    </div>
</section>

<?php if (!$regraImportacao): ?>
    <div class="alert alert-warning shadow-sm">A integracao ALELO nao esta cadastrada para esta empresa.</div>
<?php else: ?>
    <?php if ($mensagem !== null): ?>
        <div class="alert alert-<?= htmlspecialchars($mensagemTipo) ?> shadow-sm"><?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h2 class="h6 mb-0">Sincronizar arquivos</h2></div>
                <div class="card-body">
                    <?php if (!$statusCredenciais['disponivel']): ?>
                        <div class="alert alert-warning mb-0">As credenciais ALELO ainda nao estao instaladas neste servidor.</div>
                    <?php else: ?>
                        <form method="POST" class="row g-3 align-items-end">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['alelo_sync_token']) ?>">
                            <div class="col-md-4">
                                <label for="data_inicial" class="form-label">Data inicial</label>
                                <input type="date" id="data_inicial" name="data_inicial" class="form-control"
                                       value="<?= htmlspecialchars($dataInicial) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label for="data_final" class="form-label">Data final</label>
                                <input type="date" id="data_final" name="data_final" class="form-control"
                                       value="<?= htmlspecialchars($dataFinal) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary w-100">Sincronizar agora</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h2 class="h6 mb-0">Configuracao</h2></div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5">Fonte</dt><dd class="col-7">ALELO EDI / SFTP</dd>
                        <dt class="col-5">Grupo</dt><dd class="col-7">Comercial</dd>
                        <dt class="col-5">Vendas</dt><dd class="col-7">CM <?= (int)$regraImportacao['cm_debito'] ?></dd>
                        <dt class="col-5">Data prevista</dt><dd class="col-7">Informada no EDI</dd>
                        <dt class="col-5">Situacao</dt>
                        <dd class="col-7 mb-0"><span class="badge text-bg-<?= $statusCredenciais['disponivel'] ? 'success' : 'warning' ?>">
                            <?= $statusCredenciais['disponivel'] ? 'Configurada' : 'Pendente' ?>
                        </span></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <?php if ($resultado !== null): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header"><h2 class="h6 mb-0">Resultado</h2></div>
            <div class="card-body"><div class="row g-3 text-center">
                <?php foreach ([
                    'Arquivos' => 'arquivos_recebidos', 'Novos' => 'registros_importados',
                    'Atualizados' => 'registros_atualizados', 'Pagamentos' => 'pagamentos_processados',
                    'Ignorados' => 'registros_ignorados',
                ] as $rotulo => $campo): ?>
                    <div class="col-6 col-lg"><div class="border rounded p-3 h-100">
                        <div class="text-muted small"><?= htmlspecialchars($rotulo) ?></div>
                        <div class="h5 mb-0"><?= (int)$resultado[$campo] ?></div>
                    </div></div>
                <?php endforeach; ?>
            </div></div>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header"><h2 class="h6 mb-0">Ultimas sincronizacoes</h2></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>Executada em</th><th>Periodo</th><th>Status</th>
                    <th class="text-end">Arquivos</th><th class="text-end">Novos</th>
                    <th class="text-end">Pagamentos</th><th class="text-end">Ignorados</th></tr></thead>
                <tbody>
                <?php if (!$historico): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma sincronizacao realizada.</td></tr>
                <?php else: ?>
                    <?php foreach ($historico as $item): ?>
                        <?php $classe = $item['status'] === 'CONCLUIDA' ? 'success' : ($item['status'] === 'ERRO' ? 'danger' : 'warning'); ?>
                        <tr>
                            <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($item['iniciado_em']))) ?></td>
                            <td><?= htmlspecialchars(date('d/m/Y', strtotime($item['data_inicial']))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($item['data_final']))) ?></td>
                            <td><span class="badge text-bg-<?= $classe ?>"><?= htmlspecialchars($item['status']) ?></span></td>
                            <td class="text-end"><?= (int)$item['arquivos_recebidos'] ?></td>
                            <td class="text-end"><?= (int)$item['registros_importados'] ?></td>
                            <td class="text-end"><?= (int)$item['pagamentos_processados'] ?></td>
                            <td class="text-end"><?= (int)$item['registros_ignorados'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require '../../layout/footer.php'; ?>
