<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';
require_once '../../config/cielo_extrato.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$regraImportacao = buscarRegraImportacao($pdo_master, $empresaId, 'cielo_api_comercial', []);
$configCielo = cieloConfiguracaoVrs();
$statusCredenciais = cieloStatusCredenciais($configCielo);
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
        $tokenSessao = (string)($_SESSION['cielo_sync_token'] ?? '');
        $tokenPost = (string)($_POST['token'] ?? '');
        if ($tokenSessao === '' || !hash_equals($tokenSessao, $tokenPost)) {
            throw new RuntimeException('Sessao expirada. Atualize a pagina e tente novamente.');
        }
        $resultado = cieloSincronizarRecebimentos(
            $pdo_master,
            $empresaId,
            $regraImportacao,
            $configCielo,
            $dataInicial,
            $dataFinal
        );
        $mensagem = $resultado['mensagem'];
    } catch (Throwable $e) {
        $mensagemTipo = 'danger';
        $mensagem = $e->getMessage();
    }
}

if (empty($_SESSION['cielo_sync_token'])) {
    $_SESSION['cielo_sync_token'] = bin2hex(random_bytes(24));
}

$historico = [];
if ($regraImportacao) {
    try {
        garantirTabelasCielo($pdo_master);
        $stmtHistorico = $pdo_master->prepare("
            SELECT *
            FROM fechamento_cielo_sincronizacoes
            WHERE empresa_id = ? AND integracao = ?
            ORDER BY id DESC
            LIMIT 10
        ");
        $stmtHistorico->execute([$empresaId, $configCielo['chave']]);
        $historico = $stmtHistorico->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if ($mensagem === null) {
            $mensagemTipo = 'danger';
            $mensagem = 'Nao foi possivel carregar o historico da Cielo.';
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
                <h1 class="h4 fw-bold mb-1">Cielo API</h1>
                <p class="text-muted mb-0">Captura automatica dos extratos CIELO03 e CIELO16 para recebimentos.</p>
            </div>
            <a href="importar_recebimentos.php" class="btn btn-outline-secondary">Voltar</a>
        </div>
    </div>
</section>

<?php if (!$regraImportacao): ?>
    <div class="alert alert-warning shadow-sm">
        A integracao Cielo Comercial nao esta cadastrada para esta empresa.
    </div>
<?php else: ?>
    <?php if ($mensagem !== null): ?>
        <div class="alert alert-<?= htmlspecialchars($mensagemTipo) ?> shadow-sm">
            <?= htmlspecialchars($mensagem) ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h2 class="h6 mb-0">Sincronizar extratos</h2>
                </div>
                <div class="card-body">
                    <?php if (!$statusCredenciais['disponivel']): ?>
                        <div class="alert alert-warning mb-0">
                            As credenciais Cielo ainda nao estao instaladas neste servidor.
                        </div>
                    <?php else: ?>
                        <form method="POST" class="row g-3 align-items-end">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['cielo_sync_token']) ?>">
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
                <div class="card-header">
                    <h2 class="h6 mb-0">Configuracao</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5">Fonte</dt>
                        <dd class="col-7">Cielo API</dd>
                        <dt class="col-5">Grupo</dt>
                        <dd class="col-7">Comercial</dd>
                        <dt class="col-5">Debito</dt>
                        <dd class="col-7">CM <?= (int)$regraImportacao['cm_debito'] ?></dd>
                        <dt class="col-5">Credito</dt>
                        <dd class="col-7">CM <?= (int)$regraImportacao['cm_credito'] ?></dd>
                        <dt class="col-5">PIX</dt>
                        <dd class="col-7">CM <?= (int)$regraImportacao['cm_pix'] ?></dd>
                        <dt class="col-5">Situacao</dt>
                        <dd class="col-7 mb-0">
                            <span class="badge text-bg-<?= $statusCredenciais['disponivel'] ? 'success' : 'warning' ?>">
                                <?= $statusCredenciais['disponivel'] ? 'Configurada' : 'Pendente' ?>
                            </span>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <?php if ($resultado !== null): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h2 class="h6 mb-0">Resultado</h2>
            </div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    <div class="col-6 col-lg">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Arquivos</div>
                            <div class="h5 mb-0"><?= (int)$resultado['arquivos_recebidos'] ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-lg">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Novos</div>
                            <div class="h5 mb-0"><?= (int)$resultado['registros_importados'] ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-lg">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Atualizados</div>
                            <div class="h5 mb-0"><?= (int)$resultado['registros_atualizados'] ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-lg">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">Ignorados</div>
                            <div class="h5 mb-0"><?= (int)$resultado['registros_ignorados'] ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header">
            <h2 class="h6 mb-0">Ultimas sincronizacoes</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Executada em</th>
                        <th>Periodo</th>
                        <th>Status</th>
                        <th class="text-end">Arquivos</th>
                        <th class="text-end">Novos</th>
                        <th class="text-end">Atualizados</th>
                        <th class="text-end">Ignorados</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$historico): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma sincronizacao realizada.</td></tr>
                    <?php else: ?>
                        <?php foreach ($historico as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($item['iniciado_em']))) ?></td>
                                <td><?= htmlspecialchars(date('d/m/Y', strtotime($item['data_inicial']))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($item['data_final']))) ?></td>
                                <td>
                                    <?php
                                    $classeStatus = $item['status'] === 'CONCLUIDA' ? 'success' : ($item['status'] === 'ERRO' ? 'danger' : 'warning');
                                    ?>
                                    <span class="badge text-bg-<?= $classeStatus ?>"><?= htmlspecialchars($item['status']) ?></span>
                                </td>
                                <td class="text-end"><?= (int)$item['arquivos_recebidos'] ?></td>
                                <td class="text-end"><?= (int)$item['registros_importados'] ?></td>
                                <td class="text-end"><?= (int)$item['registros_atualizados'] ?></td>
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
