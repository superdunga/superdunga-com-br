<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require '../../layout/header.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);

$opcoes = [
    [
        'titulo' => 'Movimentacao',
        'descricao' => 'Registre entradas, saidas e ajustes da tesouraria.',
        'href' => 'movimentar.php',
        'modulo' => 'tesouraria_movimentacao',
        'icone' => '$',
        'botao' => 'btn-success',
    ],
    [
        'titulo' => 'Extrato',
        'descricao' => 'Consulte saldos, filtros e historico de movimentacoes.',
        'href' => 'extrato.php',
        'modulo' => 'tesouraria_extrato',
        'icone' => 'EX',
        'botao' => 'btn-primary',
    ],
    [
        'titulo' => 'Inventario Fisico',
        'descricao' => 'Abra a rotina de contagem e conferencia fisica.',
        'href' => 'inventario.php',
        'modulo' => 'tesouraria_inventario',
        'icone' => 'IF',
        'botao' => 'btn-info',
    ],
    [
        'titulo' => 'Historico de Inventarios',
        'descricao' => 'Veja inventarios finalizados e resultados anteriores.',
        'href' => 'inventarios.php',
        'modulo' => 'tesouraria_inventarios',
        'icone' => 'HI',
        'botao' => 'btn-secondary',
    ],
    [
        'titulo' => 'Conciliar Tesouraria',
        'descricao' => 'Compare lancamentos e marque conciliacoes.',
        'href' => 'conciliar.php',
        'modulo' => 'tesouraria_conciliar',
        'icone' => 'CT',
        'botao' => 'btn-warning',
    ],
];

$opcoes = filtrarOpcoesPorModulo($pdo_master, $empresaId, $opcoes);
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-success mb-3">Modulo</span>
                <h1 class="h3 fw-bold mb-2">Tesouraria</h1>
                <p class="text-muted mb-0">Acesse as rotinas de movimentacao, extrato, inventario e conciliacao.</p>
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
                <div class="alert alert-info mb-0">Nenhum modulo de tesouraria liberado para esta empresa.</div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
