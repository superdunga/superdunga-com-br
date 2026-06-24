<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/modulos.php';

if ($_SESSION['nivel'] !== 'MASTER') {
    header("Location: ../../index.php");
    exit;
}

garantirTabelasModulos($pdo_master);

$usuarioId = (int)($_GET['usuario_id'] ?? $_POST['usuario_id'] ?? 0);

$stmtUsuarios = $pdo_master->query("
    SELECT u.id, u.nome, u.login, u.nivel, u.status, e.nome_fantasia
    FROM usuarios u
    JOIN empresas e ON e.id = u.empresa_id
    ORDER BY e.nome_fantasia, u.nome
");
$usuarios = $stmtUsuarios->fetchAll(PDO::FETCH_ASSOC);

if ($usuarioId <= 0 && !empty($usuarios)) {
    $usuarioId = (int)$usuarios[0]['id'];
}

$usuarioSelecionado = null;
foreach ($usuarios as $usuario) {
    if ((int)$usuario['id'] === $usuarioId) {
        $usuarioSelecionado = $usuario;
        break;
    }
}

if (!$usuarioSelecionado) {
    die('Usuario nao encontrado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $usuarioSelecionado['nivel'] !== 'MASTER') {
    $selecionados = $_POST['modulos'] ?? [];
    $selecionados = array_map('strval', $selecionados);

    $stmtModulos = $pdo_master->query("
        SELECT id, codigo
        FROM sistema_modulos
        WHERE ativo = 'S'
    ");
    $modulosSalvar = $stmtModulos->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo_master->prepare("
        INSERT INTO usuario_modulos
            (usuario_id, modulo_id, ativo, atualizado_por)
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
            $usuarioId,
            (int)$modulo['id'],
            $ativo,
            (int)$_SESSION['usuario_id'],
        ]);
    }

    header('Location: permissoes_usuario.php?usuario_id=' . $usuarioId . '&ok=1');
    exit;
}

$usuarioConfigurado = usuarioTemConfiguracaoModulos($pdo_master, $usuarioId);

$stmtModulos = $pdo_master->prepare("
    SELECT
        sm.id,
        sm.codigo,
        sm.grupo,
        sm.nome,
        sm.url,
        COALESCE(um.ativo, 'S') AS liberado_usuario,
        COALESCE(pm.ativo, 'S') AS liberado_perfil
    FROM sistema_modulos sm
    LEFT JOIN usuario_modulos um
        ON um.modulo_id = sm.id
       AND um.usuario_id = ?
    LEFT JOIN perfil_modulos pm
        ON pm.modulo_id = sm.id
       AND pm.perfil = ?
    WHERE sm.ativo = 'S'
    ORDER BY sm.grupo, sm.ordem, sm.nome
");
$stmtModulos->execute([$usuarioId, $usuarioSelecionado['nivel']]);
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
            <h5 class="mb-1">Permissoes por Usuario</h5>
            <small class="text-muted">Use esta tela para criar excecoes individuais acima das permissoes do perfil.</small>
        </div>
        <a href="listar.php" class="btn btn-outline-secondary btn-sm">Voltar</a>
    </div>

    <div class="card-body">
        <?php if (($_GET['ok'] ?? '') === '1'): ?>
            <div class="alert alert-success">Permissoes do usuario atualizadas.</div>
        <?php endif; ?>

        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-8 col-lg-6">
                <label class="form-label">Usuario</label>
                <select name="usuario_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($usuarios as $usuario): ?>
                        <option value="<?= (int)$usuario['id'] ?>" <?= (int)$usuario['id'] === $usuarioId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($usuario['nome_fantasia'] . ' - ' . $usuario['nome'] . ' / ' . $usuario['nivel']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if ($usuarioSelecionado['nivel'] === 'MASTER'): ?>
            <div class="alert alert-info">
                Usuario MASTER sempre possui acesso total. Esta regra evita bloqueio acidental das telas administrativas.
            </div>
        <?php else: ?>
            <div class="alert <?= $usuarioConfigurado ? 'alert-info' : 'alert-warning' ?>">
                <?php if ($usuarioConfigurado): ?>
                    Este usuario possui configuracao individual salva. Ela tem prioridade sobre o perfil <?= htmlspecialchars($usuarioSelecionado['nivel']) ?>.
                <?php else: ?>
                    Este usuario ainda nao possui configuracao individual. Ele continua herdando as permissoes do perfil <?= htmlspecialchars($usuarioSelecionado['nivel']) ?>.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="usuario_id" value="<?= $usuarioId ?>">

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
                                    <?= $usuarioSelecionado['nivel'] === 'MASTER' ? 'disabled' : '' ?>
                                >
                                <label class="form-check-label fw-semibold flex-grow-1" for="<?= htmlspecialchars($grupoId) ?>">
                                    <?= htmlspecialchars($grupo) ?>
                                </label>
                            </div>
                            <div class="card-body">
                                <?php foreach ($lista as $modulo): ?>
                                    <?php $marcado = $usuarioConfigurado ? $modulo['liberado_usuario'] === 'S' : $modulo['liberado_perfil'] === 'S'; ?>
                                    <div class="form-check border-bottom py-2">
                                        <input
                                            class="form-check-input modulo-item-check"
                                            type="checkbox"
                                            name="modulos[]"
                                            value="<?= htmlspecialchars($modulo['codigo']) ?>"
                                            id="modulo-usuario-<?= (int)$modulo['id'] ?>"
                                            data-grupo="<?= htmlspecialchars($grupoId) ?>"
                                            <?= $marcado ? 'checked' : '' ?>
                                            <?= $usuarioSelecionado['nivel'] === 'MASTER' ? 'disabled' : '' ?>
                                        >
                                        <label class="form-check-label w-100" for="modulo-usuario-<?= (int)$modulo['id'] ?>">
                                            <span class="fw-semibold"><?= htmlspecialchars($modulo['nome']) ?></span>
                                            <?php if (!$usuarioConfigurado): ?>
                                                <span class="badge bg-secondary ms-1">herdado do perfil</span>
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
                <button type="submit" class="btn btn-primary" <?= $usuarioSelecionado['nivel'] === 'MASTER' ? 'disabled' : '' ?>>
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
