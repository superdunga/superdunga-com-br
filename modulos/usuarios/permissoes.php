<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/modulos.php';

if ($_SESSION['nivel'] !== 'MASTER') {
    header("Location: ../../index.php");
    exit;
}

garantirTabelasModulos($pdo_master);

$perfis = perfisSistema();
$perfilSelecionado = strtoupper(trim($_GET['perfil'] ?? $_POST['perfil'] ?? 'ADMIN'));

if (!in_array($perfilSelecionado, $perfis, true)) {
    $perfilSelecionado = 'ADMIN';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $perfilSelecionado !== 'MASTER') {
    $selecionados = $_POST['modulos'] ?? [];
    $selecionados = array_map('strval', $selecionados);

    $stmtModulos = $pdo_master->query("
        SELECT id, codigo
        FROM sistema_modulos
        WHERE ativo = 'S'
    ");
    $modulosSalvar = $stmtModulos->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo_master->prepare("
        INSERT INTO perfil_modulos
            (perfil, modulo_id, ativo, atualizado_por)
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
            $perfilSelecionado,
            (int)$modulo['id'],
            $ativo,
            (int)$_SESSION['usuario_id'],
        ]);
    }

    header('Location: permissoes.php?perfil=' . urlencode($perfilSelecionado) . '&ok=1');
    exit;
}

$perfilConfigurado = perfilTemConfiguracaoModulos($pdo_master, $perfilSelecionado);

$stmtModulos = $pdo_master->prepare("
    SELECT
        sm.id,
        sm.codigo,
        sm.grupo,
        sm.nome,
        sm.url,
        COALESCE(pm.ativo, 'S') AS liberado
    FROM sistema_modulos sm
    LEFT JOIN perfil_modulos pm
        ON pm.modulo_id = sm.id
       AND pm.perfil = ?
    WHERE sm.ativo = 'S'
    ORDER BY sm.grupo, sm.ordem, sm.nome
");
$stmtModulos->execute([$perfilSelecionado]);
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
            <h5 class="mb-1">Permissoes por Perfil</h5>
            <small class="text-muted">Controle quais modulos cada nivel de usuario enxerga nos menus.</small>
        </div>
        <a href="listar.php" class="btn btn-outline-secondary btn-sm">Voltar</a>
    </div>

    <div class="card-body">
        <?php if (($_GET['ok'] ?? '') === '1'): ?>
            <div class="alert alert-success">Permissoes do perfil atualizadas.</div>
        <?php endif; ?>

        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-6 col-lg-4">
                <label class="form-label">Perfil</label>
                <select name="perfil" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($perfis as $perfil): ?>
                        <option value="<?= htmlspecialchars($perfil) ?>" <?= $perfil === $perfilSelecionado ? 'selected' : '' ?>>
                            <?= htmlspecialchars($perfil) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if ($perfilSelecionado === 'MASTER'): ?>
            <div class="alert alert-info">
                O perfil MASTER sempre possui acesso total. Esta regra evita bloqueio acidental das telas administrativas.
            </div>
        <?php else: ?>
            <div class="alert <?= $perfilConfigurado ? 'alert-info' : 'alert-warning' ?>">
                <?php if ($perfilConfigurado): ?>
                    Este perfil possui configuracao salva. Os menus vao mostrar somente os modulos marcados.
                <?php else: ?>
                    Este perfil ainda nao possui configuracao salva. Por seguranca, todos os modulos continuam visiveis ate voce salvar.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="perfil" value="<?= htmlspecialchars($perfilSelecionado) ?>">

            <div class="row g-3">
                <?php $indiceGrupo = 0; ?>
                <?php foreach ($modulosPorGrupo as $grupo => $lista): ?>
                    <?php $grupoId = 'grupo-modulos-' . (++$indiceGrupo); ?>
                    <div class="col-lg-6">
                        <div class="card h-100 modulo-grupo-card" data-grupo="<?= htmlspecialchars($grupoId) ?>">
                            <div class="card-header bg-light d-flex align-items-center gap-2">
                                <input
                                    class="form-check-input modulo-grupo-check"
                                    type="checkbox"
                                    id="<?= htmlspecialchars($grupoId) ?>"
                                    data-grupo="<?= htmlspecialchars($grupoId) ?>"
                                    <?= $perfilSelecionado === 'MASTER' ? 'disabled' : '' ?>
                                >
                                <label class="form-check-label fw-semibold flex-grow-1" for="<?= htmlspecialchars($grupoId) ?>">
                                    <?= htmlspecialchars($grupo) ?>
                                </label>
                            </div>
                            <div class="card-body">
                                <?php foreach ($lista as $modulo): ?>
                                    <div class="form-check border-bottom py-2">
                                        <input
                                            class="form-check-input modulo-item-check"
                                            type="checkbox"
                                            name="modulos[]"
                                            value="<?= htmlspecialchars($modulo['codigo']) ?>"
                                            id="modulo-perfil-<?= (int)$modulo['id'] ?>"
                                            data-grupo="<?= htmlspecialchars($grupoId) ?>"
                                            <?= $modulo['liberado'] === 'S' ? 'checked' : '' ?>
                                            <?= $perfilSelecionado === 'MASTER' ? 'disabled' : '' ?>
                                        >
                                        <label class="form-check-label w-100" for="modulo-perfil-<?= (int)$modulo['id'] ?>">
                                            <span class="fw-semibold"><?= htmlspecialchars($modulo['nome']) ?></span>
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
                <button type="submit" class="btn btn-primary" <?= $perfilSelecionado === 'MASTER' ? 'disabled' : '' ?>>
                    Salvar permissoes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const grupoChecks = document.querySelectorAll('.modulo-grupo-check');

    function itensDoGrupo(grupoId) {
        return Array.from(document.querySelectorAll('.modulo-item-check[data-grupo="' + grupoId + '"]'))
            .filter(function (item) {
                return !item.disabled;
            });
    }

    function atualizarGrupo(grupoId) {
        const grupoCheck = document.querySelector('.modulo-grupo-check[data-grupo="' + grupoId + '"]');
        if (!grupoCheck || grupoCheck.disabled) {
            return;
        }

        const itens = itensDoGrupo(grupoId);
        const marcados = itens.filter(function (item) {
            return item.checked;
        }).length;

        grupoCheck.checked = itens.length > 0 && marcados === itens.length;
        grupoCheck.indeterminate = marcados > 0 && marcados < itens.length;
    }

    grupoChecks.forEach(function (grupoCheck) {
        const grupoId = grupoCheck.getAttribute('data-grupo');
        atualizarGrupo(grupoId);

        grupoCheck.addEventListener('change', function () {
            itensDoGrupo(grupoId).forEach(function (item) {
                item.checked = grupoCheck.checked;
            });
            atualizarGrupo(grupoId);
        });
    });

    document.querySelectorAll('.modulo-item-check').forEach(function (item) {
        item.addEventListener('change', function () {
            atualizarGrupo(item.getAttribute('data-grupo'));
        });
    });
});
</script>

<?php require __DIR__ . '/../../layout/footer.php'; ?>
