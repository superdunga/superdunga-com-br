<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../../config/modulos.php';

if ($_SESSION['nivel'] !== 'MASTER') {
    header("Location: ../../index.php");
    exit;
}

garantirTabelasModulos($pdo_master);

$empresaId = (int)($_GET['empresa_id'] ?? $_POST['empresa_id'] ?? 0);

$stmtEmpresas = $pdo_master->query("
    SELECT id, nome_fantasia, status
    FROM empresas
    ORDER BY nome_fantasia
");
$empresas = $stmtEmpresas->fetchAll(PDO::FETCH_ASSOC);

if ($empresaId <= 0 && !empty($empresas)) {
    $empresaId = (int)$empresas[0]['id'];
}

$stmtEmpresa = $pdo_master->prepare("SELECT * FROM empresas WHERE id = ?");
$stmtEmpresa->execute([$empresaId]);
$empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

if (!$empresa) {
    die('Empresa nao encontrada.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selecionados = $_POST['modulos'] ?? [];
    $selecionados = array_map('strval', $selecionados);
    $selecionados[] = 'empresas';
    $selecionados[] = 'empresas_modulos';

    $stmtModulos = $pdo_master->query("
        SELECT id, codigo
        FROM sistema_modulos
        WHERE ativo = 'S'
    ");
    $modulosSalvar = $stmtModulos->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo_master->prepare("
        INSERT INTO empresa_modulos
            (empresa_id, modulo_id, ativo, atualizado_por)
        VALUES
            (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            ativo = VALUES(ativo),
            atualizado_por = VALUES(atualizado_por),
            atualizado_em = NOW()
    ");

    foreach ($modulosSalvar as $modulo) {
        $ativo = in_array($modulo['codigo'], $selecionados, true) ? 'S' : 'N';
        $stmt->execute([
            $empresaId,
            (int)$modulo['id'],
            $ativo,
            (int)$_SESSION['usuario_id'],
        ]);
    }

    header('Location: modulos.php?empresa_id=' . $empresaId . '&ok=1');
    exit;
}

$empresaConfigurada = empresaTemConfiguracaoModulos($pdo_master, $empresaId);

$stmtModulos = $pdo_master->prepare("
    SELECT
        sm.id,
        sm.codigo,
        sm.grupo,
        sm.nome,
        sm.url,
        COALESCE(em.ativo, 'S') AS liberado
    FROM sistema_modulos sm
    LEFT JOIN empresa_modulos em
        ON em.modulo_id = sm.id
       AND em.empresa_id = ?
    WHERE sm.ativo = 'S'
    ORDER BY sm.grupo, sm.ordem, sm.nome
");
$stmtModulos->execute([$empresaId]);
$modulos = $stmtModulos->fetchAll(PDO::FETCH_ASSOC);

$modulosPorGrupo = [];
foreach ($modulos as $modulo) {
    $modulosPorGrupo[$modulo['grupo']][] = $modulo;
}

require __DIR__ . '/../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h5 class="mb-1">Modulos da Empresa</h5>
            <small class="text-muted">Libere ou bloqueie os modulos que aparecem nos menus da empresa.</small>
        </div>
        <a href="listar.php" class="btn btn-outline-secondary btn-sm">Voltar</a>
    </div>

    <div class="card-body">
        <?php if (($_GET['ok'] ?? '') === '1'): ?>
            <div class="alert alert-success">Permissoes de modulos atualizadas.</div>
        <?php endif; ?>

        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-6 col-lg-4">
                <label class="form-label">Empresa</label>
                <select name="empresa_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($empresas as $e): ?>
                        <option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === $empresaId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['nome_fantasia']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <div class="alert <?= $empresaConfigurada ? 'alert-info' : 'alert-warning' ?>">
            <?php if ($empresaConfigurada): ?>
                Esta empresa possui configuracao salva. Os menus vao mostrar somente os modulos marcados.
            <?php else: ?>
                Esta empresa ainda nao possui configuracao salva. Por seguranca, todos os modulos continuam visiveis ate voce salvar.
            <?php endif; ?>
        </div>

        <form method="POST">
            <input type="hidden" name="empresa_id" value="<?= $empresaId ?>">

            <div class="row g-3">
                <?php foreach ($modulosPorGrupo as $grupo => $lista): ?>
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header bg-light">
                                <strong><?= htmlspecialchars($grupo) ?></strong>
                            </div>
                            <div class="card-body">
                                <?php foreach ($lista as $modulo): ?>
                                    <div class="form-check border-bottom py-2">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            name="modulos[]"
                                            value="<?= htmlspecialchars($modulo['codigo']) ?>"
                                            id="modulo-<?= (int)$modulo['id'] ?>"
                                            <?= $modulo['liberado'] === 'S' ? 'checked' : '' ?>
                                            <?= in_array($modulo['codigo'], ['empresas', 'empresas_modulos'], true) ? 'disabled' : '' ?>
                                        >
                                        <label class="form-check-label w-100" for="modulo-<?= (int)$modulo['id'] ?>">
                                            <span class="fw-semibold"><?= htmlspecialchars($modulo['nome']) ?></span>
                                            <?php if (in_array($modulo['codigo'], ['empresas', 'empresas_modulos'], true)): ?>
                                                <span class="badge bg-secondary ms-1">Sempre liberado para MASTER</span>
                                            <?php endif; ?>
                                            <?php if (!empty($modulo['url'])): ?>
                                                <span class="d-block small text-muted"><?= htmlspecialchars($modulo['url']) ?></span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a href="listar.php" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">Salvar permissoes</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../layout/footer.php'; ?>
