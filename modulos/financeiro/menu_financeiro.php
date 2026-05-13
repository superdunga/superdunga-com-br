<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require '../../layout/header.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);

$opcoes = [
    [
        'titulo' => 'Contas a Receber',
        'descricao' => 'Acompanhe carteiras e titulos em aberto por tipo de recebimento.',
        'href' => 'contas_receber.php',
        'modulo' => 'financeiro',
        'icone' => 'CR',
        'botao' => 'btn-primary',
    ],
    [
        'titulo' => 'Contas a Pagar',
        'descricao' => 'Acompanhe compras e parcelas em aberto por fornecedor, documento e vencimento.',
        'href' => 'contas_pagar.php',
        'modulo' => 'financeiro',
        'icone' => 'CP',
        'botao' => 'btn-success',
    ],
];

$opcoes = filtrarOpcoesPorModulo($pdo_master, $empresaId, $opcoes);
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Modulo</span>
                <h1 class="h3 fw-bold mb-2">Financeiro</h1>
                <p class="text-muted mb-0">Central para organizar contas, pagamentos, recebimentos e controles financeiros.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="../../index.php" class="btn btn-outline-secondary">Voltar ao painel</a>
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
                        <?php if (!empty($opcao['desabilitado'])): ?>
                            <button type="button" class="btn btn-outline-secondary mt-auto w-100" disabled>Em preparacao</button>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($opcao['href']) ?>" class="btn <?= htmlspecialchars($opcao['botao']) ?> mt-auto w-100">Acessar</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($opcoes)): ?>
            <div class="col-12">
                <div class="alert alert-info mb-0">Nenhum modulo financeiro liberado para esta empresa.</div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
