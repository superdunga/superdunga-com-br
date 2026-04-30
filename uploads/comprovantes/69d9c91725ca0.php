<?php
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

require 'layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-body">
        <h4>Bem-vindo, <?= htmlspecialchars($_SESSION['usuario_nome']) ?>!</h4>
        <p class="mt-3">
            Você está logado como <strong><?= htmlspecialchars($_SESSION['nivel']) ?></strong>.
        </p>

        <div class="mt-3">

            <?php if ($_SESSION['nivel'] === 'MASTER'): ?>
                <a href="modulos/empresas/listar.php" class="btn btn-primary me-2">
                    Gerenciar Empresas
                </a>
            <?php endif; ?>

            <?php if ($_SESSION['nivel'] === 'MASTER' || $_SESSION['nivel'] === 'ADMIN'): ?>
                <a href="modulos/usuarios/listar.php" class="btn btn-secondary me-2">
                    Gerenciar Usuários
                </a>
            <?php endif; ?>

            <a href="logout.php" class="btn btn-danger">
                Sair
            </a>

        </div>
    </div>
</div>

<?php require 'layout/footer.php'; ?>