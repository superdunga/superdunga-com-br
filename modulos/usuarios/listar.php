<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/modulos.php';

/* Somente ADMIN ou MASTER podem acessar */
exigirNivel('ADMIN');

require __DIR__ . '/../../layout/header.php';

/* MASTER vê todos usuários */
if ($_SESSION['nivel'] === 'MASTER') {

    $stmt = $pdo_master->query("
        SELECT u.*, e.nome_fantasia
        FROM usuarios u
        JOIN empresas e ON e.id = u.empresa_id
        ORDER BY u.id DESC
    ");

} else {

    /* ADMIN vê apenas usuários da própria empresa */
    $stmt = $pdo_master->prepare("
        SELECT u.*, e.nome_fantasia
        FROM usuarios u
        JOIN empresas e ON e.id = u.empresa_id
        WHERE u.empresa_id = ?
        ORDER BY u.id DESC
    ");

    $stmt->execute([$_SESSION['empresa_id']]);
}

$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Usuários</h5>

        <div>
            <a href="../../index.php" class="btn btn-secondary btn-sm">
                ← Voltar ao Painel
            </a>

            <?php if ($_SESSION['nivel'] === 'MASTER'): ?>
                <a href="permissoes.php" class="btn btn-outline-primary btn-sm">
                    Permissoes por Perfil
                </a>
                <a href="permissoes_usuario.php" class="btn btn-outline-primary btn-sm">
                    Permissoes por Usuario
                </a>
            <?php endif; ?>

            <a href="cadastrar.php" class="btn btn-primary btn-sm">
                Novo Usuário
            </a>
        </div>
    </div>

    <div class="card-body">

        <?php if (count($usuarios) > 0): ?>

            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Empresa</th>
                            <th>Nome</th>
                            <th>Login</th>
                            <th>Nível</th>
                            <th>Status</th>
                            <th class="text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $u): ?>
                            <tr>
                                <td><?= $u['id'] ?></td>
                                <td><?= htmlspecialchars($u['nome_fantasia']) ?></td>
                                <td><?= htmlspecialchars($u['nome']) ?></td>
                                <td><?= htmlspecialchars($u['login']) ?></td>
                                <td><?= $u['nivel'] ?></td>
                                <td>
                                    <span class="badge bg-<?= $u['status'] === 'ATIVO' ? 'success' : 'danger' ?>">
                                        <?= $u['status'] ?>
                                    </span>
                                </td>

                                <td class="text-center">
                                    
                                    <?php
                                    // 🔒 Segurança: ADMIN não pode editar MASTER
                                    $podeEditar = (
                                        $_SESSION['nivel'] === 'MASTER' ||
                                        $u['nivel'] !== 'MASTER'
                                    );
                                    ?>

                                    <?php if ($podeEditar): ?>
                                        <a href="editar.php?id=<?= $u['id'] ?>"
                                           class="btn btn-sm btn-outline-dark"
                                           title="Editar usuário">
                                            ✏️
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">🔒</span>
                                    <?php endif; ?>

                                </td>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <div class="alert alert-info">
                Nenhum usuário cadastrado.
            </div>

        <?php endif; ?>

    </div>
</div>

<?php require __DIR__ . '/../../layout/footer.php'; ?>
