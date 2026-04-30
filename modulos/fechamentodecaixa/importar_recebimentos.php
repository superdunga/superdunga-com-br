<?php
require '../../config/auth.php';
require '../../layout/header.php';

$comercial = [
    [
        'titulo' => 'SIPAG POS',
        'descricao' => 'Debito/Credito - D+1 CMCONTADOR 3 | D+30 CMCONTADOR 2',
        'href' => 'importar_sipag_pos_comercial.php',
        'botao' => 'btn-primary',
    ],
    [
        'titulo' => 'SIPAG PIX',
        'descricao' => 'Recebimentos PIX comercial - CMCONTADOR 12',
        'href' => 'importar_sipag_pix_comercial.php',
        'botao' => 'btn-primary',
    ],
];

$outros = [
    [
        'titulo' => 'SIPAG POS',
        'descricao' => 'Debito/Credito - D+1 CMCONTADOR 6 | D+30 CMCONTADOR 14',
        'href' => 'importar_sipag_pos_outros.php',
        'botao' => 'btn-success',
    ],
    [
        'titulo' => 'SIPAG PIX',
        'descricao' => 'Recebimentos PIX outros - CMCONTADOR 7',
        'href' => 'importar_sipag_pix_outros.php',
        'botao' => 'btn-success',
    ],
    [
        'titulo' => 'PAGSEGURO PIX',
        'descricao' => 'Importacao PagSeguro PIX - CMCONTADOR 7',
        'href' => 'importar_pagseguro.php',
        'botao' => 'btn-success',
    ],
];
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-7">
                <span class="badge text-bg-warning mb-3">Recebimentos</span>
                <h1 class="h3 fw-bold mb-2">Importacao de Recebimentos</h1>
                <p class="text-muted mb-0">Selecione o tipo de arquivo para iniciar a importacao ou abrir a conciliacao.</p>
            </div>
            <div class="col-lg-5">
                <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                    <a href="menu_fechamento.php" class="btn btn-outline-secondary">Voltar ao modulo</a>
                    <a href="validar_cm.php" class="btn btn-purple">Validar Clientes</a>
                    <a href="conciliar_recebimentos.php" class="btn btn-dark">Conciliacao</a>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100 shadow-sm">
            <div class="card-header">
                <h2 class="h5 mb-0">Comercial</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($comercial as $item): ?>
                        <div class="col-md-6 col-lg-12">
                            <div class="border p-3 rounded h-100">
                                <h3 class="h6 fw-bold mb-1"><?= htmlspecialchars($item['titulo']) ?></h3>
                                <p class="text-muted small mb-3"><?= htmlspecialchars($item['descricao']) ?></p>
                                <a href="<?= htmlspecialchars($item['href']) ?>" class="btn <?= htmlspecialchars($item['botao']) ?> w-100">Abrir Importacao</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100 shadow-sm">
            <div class="card-header">
                <h2 class="h5 mb-0">Outros</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($outros as $item): ?>
                        <div class="col-md-6 col-lg-12">
                            <div class="border p-3 rounded h-100">
                                <h3 class="h6 fw-bold mb-1"><?= htmlspecialchars($item['titulo']) ?></h3>
                                <p class="text-muted small mb-3"><?= htmlspecialchars($item['descricao']) ?></p>
                                <a href="<?= htmlspecialchars($item['href']) ?>" class="btn <?= htmlspecialchars($item['botao']) ?> w-100">Abrir Importacao</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
