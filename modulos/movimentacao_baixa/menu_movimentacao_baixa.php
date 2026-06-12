<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require '../../layout/header.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);

$opcoes = [
    [
        'titulo' => 'Caixa/Banco',
        'descricao' => 'Lance movimentacoes simples diretamente no extrato BNC001.',
        'href' => 'caixa_banco.php',
        'modulo' => 'movimentacao_baixa_caixa_banco',
        'icone' => 'CB',
        'botao' => 'btn-primary',
    ],
    [
        'titulo' => 'Contas a Pagar',
        'descricao' => 'Cadastre titulos a pagar diretamente em CP001 para baixa posterior.',
        'href' => 'contas_pagar.php',
        'modulo' => 'movimentacao_baixa_contas_pagar',
        'icone' => 'CP',
        'botao' => 'btn-success',
    ],
    [
        'titulo' => 'Contas a Receber',
        'descricao' => 'Cadastre titulos a receber diretamente em CR001 para baixa posterior.',
        'href' => 'contas_receber.php',
        'modulo' => 'movimentacao_baixa_contas_receber',
        'icone' => 'CR',
        'botao' => 'btn-info',
    ],
];

$opcoes = filtrarOpcoesPorModulo($pdo_master, $empresaId, $opcoes);
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Modulo</span>
                <h1 class="h3 fw-bold mb-2">Movimentacao/Baixa</h1>
                <p class="text-muted mb-0">Central para lancamentos diretos em caixa/banco e futuras rotinas de baixa.</p>
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
        <?php if (empty($opcoes)): ?>
            <div class="col-12">
                <div class="alert alert-info mb-0">Nenhuma rotina de movimentacao/baixa liberada para esta empresa.</div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
