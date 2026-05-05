<?php
require '../../config/auth.php';
require '../../layout/header.php';

$opcoes = [
    [
        'titulo' => 'Compras',
        'descricao' => 'Audite compras, itens, custos, precos de venda e margens.',
        'href' => 'listar.php',
        'icone' => 'CP',
        'botao' => 'btn-info',
    ],
];
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-info mb-3">Modulo</span>
                <h1 class="h3 fw-bold mb-2">Auditoria das Compras</h1>
                <p class="text-muted mb-0">Acesse as rotinas de auditoria e conferencia de compras.</p>
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
                        <a href="<?= htmlspecialchars($opcao['href']) ?>" class="btn <?= htmlspecialchars($opcao['botao']) ?> mt-auto w-100">Acessar</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
