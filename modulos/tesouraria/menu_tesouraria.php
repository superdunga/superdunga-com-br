<?php
require '../../config/auth.php';
require '../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-body">

        <h4>Tesouraria</h4>
        <p class="text-muted">Selecione uma opção</p>

        <div class="row mt-3">

            <div class="col-md-4 mb-2">
                <a href="movimentar.php" class="btn btn-success w-100">
                    💰 Movimentação
                </a>
            </div>

            <div class="col-md-4 mb-2">
                <a href="extrato.php" class="btn btn-primary w-100">
                    📊 Extrato
                </a>
            </div>

            <div class="col-md-4 mb-2">
                <a href="inventario.php" class="btn btn-info w-100">
                    📦 Inventário Físico
                </a>
            </div>
      
            <div class="col-md-4 mb-2">
                <a href="inventarios.php" class="btn btn-secondary w-100">
                    📋 Histórico de Inventários
                </a>
            </div>

            <!-- NOVO BOTÃO DE CONCILIAÇÃO -->
            <div class="col-md-4 mb-2">
                <a href="conciliar.php" class="btn btn-warning w-100">
                    🔄 Conciliar Tesouraria
                </a>
            </div>

        </div>

    </div>
</div>

<style>
.btn-voltar-fixo {
    position: fixed;
    bottom: 15px;
    left: 15px;
    z-index: 9999;
}

@media (max-width: 768px) {
    .btn-voltar-fixo {
        position: static;
        width: 100%;
        margin-top: 15px;
    }
}
</style>

<div class="btn-voltar-fixo">
    <button onclick="window.location.href='../../index.php'" class="btn btn-secondary">
        ← Voltar
    </button>
</div>

<?php require '../../layout/footer.php'; ?>