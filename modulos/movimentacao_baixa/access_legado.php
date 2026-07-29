<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require __DIR__ . '/_empresa2_guard.php';
require __DIR__ . '/_access_comparacao_lib.php';

acGarantir($pdo_master);

require '../../layout/header.php';
echo acCss();
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Mov/Baixa</span>
                <h1 class="h3 fw-bold mb-2">Analise Access</h1>
                <p class="text-muted mb-0">Comparacao partindo da base menor do SuperDunga para localizar os lancamentos correspondentes no Access.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="access_tabela.php" class="btn btn-outline-primary">Tabela Access</a>
                <a href="menu_movimentacao_baixa.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<section class="row g-3">
    <div class="col-md-3">
        <div class="card ac-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold">Caixa/Banco x Access</h2>
                <p class="text-muted">Procura lancamentos de BNC001 no Access por valor e data aproximada.</p>
                <a href="access_caixa_banco.php" class="btn btn-primary">Abrir comparacao</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card ac-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold">Contas a Receber x Access</h2>
                <p class="text-muted">Procura parcelas de CR001 no Access por valor e data aproximada.</p>
                <a href="access_contas_receber.php" class="btn btn-primary">Abrir comparacao</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card ac-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold">Contas a Pagar x Access</h2>
                <p class="text-muted">Procura parcelas de CP001 no Access por valor e data aproximada.</p>
                <a href="access_contas_pagar.php" class="btn btn-primary">Abrir comparacao</a>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card ac-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold">Lancamentos pendentes</h2>
                <p class="text-muted">Cria lancamentos no SuperDunga a partir dos registros Access ainda nao vinculados.</p>
                <a href="access_lancar_pendentes.php" class="btn btn-success">Abrir pendentes</a>
            </div>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
