<?php
require '../../config/auth.php';
require '../../config/conexao.php';

if (!temNivel('MASTER')) {
    renderizarAcessoNegadoModulo('Apenas usuarios MASTER podem desfazer validacoes de clientes.');
}

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$mensagemErro = '';
$crFiltro = trim((string)($_GET['crcontador'] ?? ''));
$clienteFiltro = trim((string)($_GET['cliente'] ?? ''));

$pdo_master->exec("
    CREATE TABLE IF NOT EXISTS conciliacao_cm9_desvalidacoes_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        empresa_id INT NOT NULL,
        crcontador INT NOT NULL,
        clicontador INT NOT NULL,
        usuario_validacao_anterior INT NULL,
        data_validacao_anterior DATETIME NULL,
        usuario_desvalidacao INT NOT NULL,
        motivo VARCHAR(255) NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cm9_desvalidacoes_titulo (empresa_id, crcontador),
        INDEX idx_cm9_desvalidacoes_data (empresa_id, criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if (empty($_SESSION['cm9_desvalidar_token'])) {
    $_SESSION['cm9_desvalidar_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'desvalidar_cm9') {
    try {
        $crcontador = (int)($_POST['crcontador'] ?? 0);
        $motivo = trim((string)($_POST['motivo'] ?? ''));
        if (!hash_equals($_SESSION['cm9_desvalidar_token'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Sessao expirada. Recarregue a pagina e tente novamente.');
        }
        if ($crcontador <= 0 || $motivo === '' || mb_strlen($motivo) > 255) {
            throw new RuntimeException('Informe um titulo CM 9 e um motivo com ate 255 caracteres.');
        }

        $pdo_master->beginTransaction();
        $stmt = $pdo_master->prepare("
            SELECT CRCONTADOR, CLICONTADOR, usuario_validacao, data_validacao
            FROM armazem_cr001
            WHERE EMPRESA = ? AND CRCONTADOR = ? AND CMCONTADOR = 9
              AND validado = 'S' AND COALESCE(excluido_firebird, 'N') = 'N'
            FOR UPDATE
        ");
        $stmt->execute([$empresaId, $crcontador]);
        $titulo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$titulo) {
            throw new RuntimeException('Titulo CM 9 validado nao encontrado nesta empresa.');
        }

        $stmt = $pdo_master->prepare("
            UPDATE armazem_cr001
            SET validado = 'N', data_validacao = NULL, usuario_validacao = NULL
            WHERE EMPRESA = ? AND CRCONTADOR = ? AND CMCONTADOR = 9 AND validado = 'S'
        ");
        $stmt->execute([$empresaId, $crcontador]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Nao foi possivel desfazer a validacao do titulo.');
        }

        $stmt = $pdo_master->prepare("
            INSERT INTO conciliacao_cm9_desvalidacoes_log
                (empresa_id, crcontador, clicontador, usuario_validacao_anterior,
                 data_validacao_anterior, usuario_desvalidacao, motivo)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $empresaId, $crcontador, (int)$titulo['CLICONTADOR'],
            $titulo['usuario_validacao'], $titulo['data_validacao'], $usuarioId, $motivo,
        ]);
        $pdo_master->commit();

        $query = $_GET;
        $query['ok'] = '1';
        header('Location: desvincular_clientes.php?' . http_build_query($query));
        exit;
    } catch (Throwable $e) {
        if ($pdo_master->inTransaction()) {
            $pdo_master->rollBack();
        }
        $mensagemErro = $e->getMessage();
    }
}

$where = ["c.EMPRESA = ?", "c.CMCONTADOR = 9", "c.validado = 'S'", "COALESCE(c.excluido_firebird, 'N') = 'N'"];
$params = [$empresaId];
if ($crFiltro !== '') {
    $where[] = 'c.CRCONTADOR = ?';
    $params[] = ctype_digit($crFiltro) ? (int)$crFiltro : 0;
}
if ($clienteFiltro !== '') {
    $where[] = '(cli.NOME LIKE ? OR cli.APELIDO LIKE ? OR c.CLICONTADOR = ?)';
    $params[] = '%' . $clienteFiltro . '%';
    $params[] = '%' . $clienteFiltro . '%';
    $params[] = ctype_digit($clienteFiltro) ? (int)$clienteFiltro : 0;
}
$stmt = $pdo_master->prepare("
    SELECT c.CRCONTADOR, c.CLICONTADOR, c.DTLANC, c.VLRPARCELA,
           c.data_validacao, c.usuario_validacao,
           COALESCE(NULLIF(cli.NOME, ''), NULLIF(cli.APELIDO, ''), '-') AS cliente_nome,
           u.nome AS usuario_nome
    FROM armazem_cr001 c
    LEFT JOIN armazem_cr002 cli
      ON cli.EMPRESA = c.EMPRESA AND cli.CLICONTADOR = c.CLICONTADOR
    LEFT JOIN usuarios u ON u.id = c.usuario_validacao
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.data_validacao DESC, c.CRCONTADOR DESC
    LIMIT 100
");
$stmt->execute($params);
$titulos = $stmt->fetchAll(PDO::FETCH_ASSOC);

require '../../layout/header.php';
?>

<section class="mb-3 d-flex justify-content-between align-items-center gap-3">
    <div>
        <h1 class="h4 mb-1">Desfazer validacao de clientes</h1>
        <p class="text-muted mb-0">Titulos CM 9 conferidos. Esta acao nao altera vinculos de cartoes ou dados no Firebird.</p>
    </div>
    <a href="menu_recebimentos.php" class="btn btn-outline-secondary">Voltar</a>
</section>

<?php if ($mensagemErro): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div>
<?php elseif (($_GET['ok'] ?? '') === '1'): ?>
    <div class="alert alert-success">Validacao desfeita. O titulo voltou para a lista de pendentes.</div>
<?php endif; ?>

<section class="bg-white border rounded-2 shadow-sm overflow-hidden">
    <form method="GET" class="p-3 border-bottom">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="crFiltro">CRCONTADOR</label>
                <input type="number" min="1" id="crFiltro" name="crcontador" class="form-control" value="<?= htmlspecialchars($crFiltro) ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label" for="clienteFiltro">Cliente ou codigo</label>
                <input type="text" id="clienteFiltro" name="cliente" class="form-control" value="<?= htmlspecialchars($clienteFiltro) ?>">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Buscar</button>
                <a href="desvincular_clientes.php" class="btn btn-outline-secondary">Limpar</a>
            </div>
        </div>
    </form>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>CRCONTADOR</th><th>Cliente</th><th>Data do titulo</th>
                    <th class="text-end">Valor</th><th>Validado em</th><th>Por</th><th>Acao</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($titulos as $titulo): ?>
                    <tr>
                        <td class="fw-semibold"><?= (int)$titulo['CRCONTADOR'] ?></td>
                        <td><?= htmlspecialchars($titulo['cliente_nome']) ?> (<?= (int)$titulo['CLICONTADOR'] ?>)</td>
                        <td><?= $titulo['DTLANC'] ? date('d/m/Y H:i', strtotime($titulo['DTLANC'])) : '-' ?></td>
                        <td class="text-end">R$ <?= number_format((float)$titulo['VLRPARCELA'], 2, ',', '.') ?></td>
                        <td><?= $titulo['data_validacao'] ? date('d/m/Y H:i', strtotime($titulo['data_validacao'])) : '-' ?></td>
                        <td><?= htmlspecialchars($titulo['usuario_nome'] ?: ('Usuario #' . (int)$titulo['usuario_validacao'])) ?></td>
                        <td>
                            <form method="POST" class="d-flex gap-2 align-items-center js-form-desvalidar">
                                <input type="hidden" name="acao" value="desvalidar_cm9">
                                <input type="hidden" name="crcontador" value="<?= (int)$titulo['CRCONTADOR'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['cm9_desvalidar_token']) ?>">
                                <input type="text" name="motivo" class="form-control form-control-sm" maxlength="255" required placeholder="Motivo" aria-label="Motivo para desfazer validacao" style="min-width: 150px;">
                                <button type="submit" class="btn btn-sm btn-outline-danger text-nowrap">Desfazer validacao</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$titulos): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">Nenhum titulo CM 9 validado encontrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($titulos) === 100): ?>
        <div class="small text-muted px-3 py-2 border-top">Exibindo os 100 mais recentes. Use a busca para localizar outros titulos.</div>
    <?php endif; ?>
</section>

<script>
document.querySelectorAll('.js-form-desvalidar').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!confirm('Desfazer a validacao CM 9 deste titulo? A acao sera registrada em log.')) {
            event.preventDefault();
        }
    });
});
</script>

<?php require '../../layout/footer.php'; ?>
