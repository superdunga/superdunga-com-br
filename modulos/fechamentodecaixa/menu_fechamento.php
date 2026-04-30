<?php
require '../../config/auth.php';
require '../../layout/header.php';
?>

<div class="card shadow-sm">
    
    <!-- 🔥 HEADER COM BOTÃO VOLTAR -->
    <div class="card-header d-flex justify-content-between align-items-center">
        
        <h5 class="mb-0">📊 Fechamento e Conciliação de Caixa</h5>

        <!-- 🔙 BOTÃO VOLTAR -->
        <a href="../../index.php" class="btn btn-secondary">
            ← Voltar
        </a>

    </div>

    <div class="card-body">

        <div class="d-flex flex-wrap gap-2">

            <!-- 🔥 FECHAMENTO -->
            <a href="fechamento_caixa.php" class="btn btn-primary">
                📅 Fechamento de Caixa
            </a>

            <!-- 💰 CONCILIAÇÃO DE DINHEIRO -->
            <a href="conciliacao_dinheiro.php" class="btn btn-success">
                💰 Conciliação de Dinheiro
            </a>

            <!-- 📥 IMPORTAR / CONCILIAR PRAZO -->
            <a href="importar_recebimentos.php" class="btn btn-warning">
                📥 Importar / Conciliar Vendas a Prazo
            </a>

            <!-- 📊 RESUMO PRAZO (NOVO) -->
            <a href="resumo_prazo.php" class="btn btn-info">
                📊 Resumo Vendas a Prazo
            </a>

        </div>

    </div>
</div>

<?php require '../../layout/footer.php'; ?>