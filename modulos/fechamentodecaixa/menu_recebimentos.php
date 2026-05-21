<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require '../../layout/header.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);

$opcoes = [
    [
        'titulo' => 'Importar Arquivos',
        'descricao' => 'Upload e validacao dos arquivos de operadoras e extratos.',
        'href' => 'importar_recebimentos.php',
        'modulo' => 'fechamento_importar_recebimentos',
        'icone' => 'UP',
        'botao' => 'btn-warning',
    ],
    [
        'titulo' => 'Conciliar Recebimentos',
        'descricao' => 'Concilie recebiveis importados com os lancamentos do CR001.',
        'href' => 'conciliar_recebimentos.php',
        'modulo' => 'fechamento_importar_recebimentos',
        'icone' => 'CR',
        'botao' => 'btn-dark',
    ],
    [
        'titulo' => 'Validar Clientes',
        'descricao' => 'Confira clientes e CM antes de finalizar a rotina de recebimentos.',
        'href' => 'validar_cm.php',
        'modulo' => 'fechamento_importar_recebimentos',
        'icone' => 'VC',
        'botao' => 'btn-primary',
    ],
];

if (($_SESSION['nivel'] ?? '') === 'MASTER') {
    $opcoes[] = [
        'titulo' => 'Desvincular Matches',
        'descricao' => 'Desfaca conciliacoes de recebiveis feitas por engano.',
        'href' => 'desvincular_recebimentos.php',
        'modulo' => 'fechamento_importar_recebimentos',
        'icone' => 'DM',
        'botao' => 'btn-danger',
    ];
}

$opcoes = filtrarOpcoesPorModulo($pdo_master, $empresaId, $opcoes);
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-warning mb-3">Recebimentos</span>
                <h1 class="h3 fw-bold mb-2">Recebimentos</h1>
                <p class="text-muted mb-0">Central de importacao, conciliacao e validacao dos recebiveis.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_fechamento.php" class="btn btn-outline-secondary">Voltar ao fechamento</a>
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
                <div class="alert alert-info mb-0">Nenhuma rotina de recebimentos liberada para este usuario.</div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
