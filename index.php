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
            Você está logado como 
            <strong><?= htmlspecialchars($_SESSION['nivel']) ?></strong>.
        </p>

        <div class="mt-3">

            <!-- 🔷 CONTROLE DO SISTEMA (JÁ EXISTENTE) -->

            <?php if ($_SESSION['nivel'] === 'MASTER'): ?>
                <a href="modulos/empresas/listar.php" class="btn btn-primary me-2 mb-2">
                    Gerenciar Empresas
                </a>
            <?php endif; ?>

            <?php if ($_SESSION['nivel'] === 'MASTER' || $_SESSION['nivel'] === 'ADMIN'): ?>
                <a href="modulos/usuarios/listar.php" class="btn btn-secondary me-2 mb-2">
                    Gerenciar Usuários
                </a>
            <?php endif; ?>

            <hr>

            <!-- 🔥 NOVOS MÓDULOS DO SISTEMA -->

            <a href="modulos/tesouraria/menu_tesouraria.php" class="btn btn-success me-2 mb-2">
                Tesouraria
            </a>

            <a href="modulos/fechamentodecaixa/menu_fechamento.php" class="btn btn-warning me-2 mb-2">
                Fechamento e Conciliação de Caixas
            </a>

            <a href="modulos/auditoria/listar.php" class="btn btn-info me-2 mb-2">
                Auditoria das Compras
            </a>

            <!-- 🔄 NOVO BOTÃO (ADICIONADO) -->
            <a href="modulos/tesouraria/sincronizacao.php" class="btn btn-dark me-2 mb-2">
                Sincronizar Dados
            </a>

            <hr>

            <!-- 🔴 SAIR -->

            <a href="logout.php" class="btn btn-danger">
                Sair
            </a>

        </div>

    </div>
</div>

<?php require 'layout/footer.php'; ?>