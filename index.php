<?php
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

$usuarioNome = $_SESSION['usuario_nome'] ?? 'Usuario';
$nivelUsuario = $_SESSION['nivel'] ?? '';

$atalhos = [
    [
        'titulo' => 'Tesouraria',
        'descricao' => 'Movimentacoes, extratos, inventario e conciliacao financeira.',
        'href' => 'modulos/tesouraria/menu_tesouraria.php',
        'classe' => 'border-success',
        'botao' => 'btn-success',
        'icone' => '$',
        'visivel' => true,
    ],
    [
        'titulo' => 'Fechamento de Caixas',
        'descricao' => 'Importacao, conferencia e conciliacao dos fechamentos.',
        'href' => 'modulos/fechamentodecaixa/menu_fechamento.php',
        'classe' => 'border-warning',
        'botao' => 'btn-warning',
        'icone' => 'FC',
        'visivel' => true,
    ],
    [
        'titulo' => 'Auditoria das Compras',
        'descricao' => 'Acompanhamento e auditoria dos processos de compras.',
        'href' => 'modulos/auditoria/menu_auditoria.php',
        'classe' => 'border-info',
        'botao' => 'btn-info',
        'icone' => 'A',
        'visivel' => true,
    ],
    [
        'titulo' => 'Sincronizar Dados',
        'descricao' => 'Atualize dados integrados antes das rotinas operacionais.',
        'href' => 'modulos/tesouraria/sincronizacao.php',
        'classe' => 'border-dark',
        'botao' => 'btn-dark',
        'icone' => 'Sync',
        'visivel' => true,
    ],
    [
        'titulo' => 'Mensagens WhatsApp',
        'descricao' => 'Configure destinatarios, mensagens e acompanhe os envios.',
        'href' => 'modulos/whatsapp/index.php',
        'classe' => 'border-success',
        'botao' => 'btn-success',
        'icone' => 'WA',
        'visivel' => $nivelUsuario === 'MASTER',
    ],
    [
        'titulo' => 'Gerenciar Usuarios',
        'descricao' => 'Cadastre, edite e revise acessos da equipe.',
        'href' => 'modulos/usuarios/listar.php',
        'classe' => 'border-primary',
        'botao' => 'btn-primary',
        'icone' => 'U',
        'visivel' => $nivelUsuario === 'MASTER' || $nivelUsuario === 'ADMIN',
    ],
    [
        'titulo' => 'Gerenciar Empresas',
        'descricao' => 'Controle empresas liberadas para uso do sistema.',
        'href' => 'modulos/empresas/listar.php',
        'classe' => 'border-secondary',
        'botao' => 'btn-secondary',
        'icone' => 'E',
        'visivel' => $nivelUsuario === 'MASTER',
    ],
];

require 'layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <span class="badge text-bg-warning mb-3">Painel inicial</span>
                <h1 class="h3 fw-bold mb-2">Bem-vindo, <?= htmlspecialchars($usuarioNome) ?>.</h1>
                <p class="text-muted mb-0">
                    Voce esta logado como <strong><?= htmlspecialchars($nivelUsuario) ?></strong>. Escolha um modulo abaixo para continuar.
                </p>
            </div>
            <div class="col-lg-4">
                <div class="row g-2">
                    <div class="col-6">
                        <div class="bg-light border rounded-2 p-3 h-100">
                            <div class="small text-muted">Perfil</div>
                            <div class="fw-semibold"><?= htmlspecialchars($nivelUsuario) ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="bg-light border rounded-2 p-3 h-100">
                            <div class="small text-muted">Sessao</div>
                            <div class="fw-semibold">Ativa</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h2 class="h5 fw-bold mb-1">Modulos do sistema</h2>
            <p class="text-muted mb-0">Acesso rapido as principais rotinas.</p>
        </div>
    </div>

    <div class="row g-3">
        <?php foreach ($atalhos as $atalho): ?>
            <?php if (!$atalho['visivel']) { continue; } ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 shadow-sm <?= htmlspecialchars($atalho['classe']) ?>">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-start gap-3 mb-3">
                            <div class="d-inline-flex align-items-center justify-content-center rounded-1 bg-light border fw-bold text-secondary" style="width:44px;height:44px;">
                                <?= htmlspecialchars($atalho['icone']) ?>
                            </div>
                            <div>
                                <h3 class="h6 fw-bold mb-1"><?= htmlspecialchars($atalho['titulo']) ?></h3>
                                <p class="text-muted small mb-0"><?= htmlspecialchars($atalho['descricao']) ?></p>
                            </div>
                        </div>
                        <div class="mt-auto">
                            <a href="<?= htmlspecialchars($atalho['href']) ?>" class="btn <?= htmlspecialchars($atalho['botao']) ?> w-100">
                                Acessar
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php require 'layout/footer.php'; ?>
