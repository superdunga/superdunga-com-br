<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require '../../layout/header.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);

$opcoes = [
    [
        'titulo' => 'Abertura de Caixa',
        'descricao' => 'Registra a retirada de dinheiro da tesouraria para abertura do caixa.',
        'href' => 'movimentar.php?fluxo=abertura',
        'modulo' => 'tesouraria_movimentacao_abertura',
        'icone' => 'AC',
        'botao' => 'btn-danger',
    ],
    [
        'titulo' => 'Fechamento de Caixa',
        'descricao' => 'Registra a entrada de dinheiro do fechamento do caixa na tesouraria.',
        'href' => 'movimentar.php?fluxo=fechamento',
        'modulo' => 'tesouraria_movimentacao_fechamento',
        'icone' => 'FC',
        'botao' => 'btn-success',
    ],
    [
        'titulo' => 'Movimentacao',
        'descricao' => 'Tela completa para credito, debito, troca e anexos.',
        'href' => 'movimentar.php',
        'modulo' => 'tesouraria_movimentacao_completa',
        'icone' => '$',
        'botao' => 'btn-primary',
    ],
];

$opcoes = filtrarOpcoesPorModulo($pdo_master, $empresaId, $opcoes);
?>

<section class="mb-4">
    <div class="p-4 bg-white border rounded-2 shadow-sm">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
            <div>
                <span class="badge text-bg-success mb-3">Tesouraria</span>
                <h1 class="h4 fw-bold mb-1">Movimentacao</h1>
                <p class="text-muted mb-0">Escolha a rotina de caixa ou abra a movimentacao completa.</p>
            </div>
            <div>
                <a href="menu_tesouraria.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<section>
    <div class="row g-3">
        <?php foreach ($opcoes as $opcao): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card module-card h-100 shadow-sm">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-start gap-3 mb-3">
                            <div class="module-icon"><?= htmlspecialchars($opcao['icone']) ?></div>
                            <div>
                                <h2 class="h6 fw-bold mb-1"><?= htmlspecialchars($opcao['titulo']) ?></h2>
                                <p class="text-muted small mb-0"><?= htmlspecialchars($opcao['descricao']) ?></p>
                            </div>
                        </div>
                        <a href="<?= htmlspecialchars($opcao['href']) ?>" class="btn <?= htmlspecialchars($opcao['botao']) ?> mt-auto w-100">Acessar</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($opcoes)): ?>
            <div class="col-12">
                <div class="alert alert-info mb-0">Nenhuma rotina de movimentacao liberada para este usuario.</div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
