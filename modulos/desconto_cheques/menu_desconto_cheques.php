<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

garantirTabelasDescontoCheques($pdo_master);
garantirPrazosPadraoDescontoCheques($pdo_master, (int)($_SESSION['empresa_id'] ?? 0));
garantirFeriadosNacionaisFixosDC($pdo_master, (int)($_SESSION['empresa_id'] ?? 0));
garantirFeriadosVariaveisDC($pdo_master, (int)($_SESSION['empresa_id'] ?? 0), (int)date('Y') - 1, 6);

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);

$opcoes = [
    [
        'titulo' => 'Clientes',
        'descricao' => 'Cadastre taxa, limite e regra de prazo por cliente.',
        'href' => 'clientes.php',
        'modulo' => 'desconto_cheques_clientes',
        'icone' => 'CL',
        'botao' => 'btn-warning',
    ],
    [
        'titulo' => 'Operacoes',
        'descricao' => 'Lance documentos e calcule o valor liquido do desconto.',
        'href' => 'operacoes.php',
        'modulo' => 'desconto_cheques_operacoes',
        'icone' => 'OP',
        'botao' => 'btn-primary',
    ],
    [
        'titulo' => 'Feriados',
        'descricao' => 'Mantenha os feriados recorrentes usados no calculo de dias uteis.',
        'href' => 'feriados.php',
        'modulo' => 'desconto_cheques_feriados',
        'icone' => 'FE',
        'botao' => 'btn-secondary',
    ],
];

$opcoes = filtrarOpcoesPorModulo($pdo_master, $empresaId, $opcoes);

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-warning mb-3">Modulo</span>
                <h1 class="h3 fw-bold mb-2">Desconto de Cheques</h1>
                <p class="text-muted mb-0">Controle de clientes, limites e operacoes de desconto.</p>
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
                <div class="alert alert-info mb-0">Nenhuma rotina de desconto de cheques liberada para esta empresa.</div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
