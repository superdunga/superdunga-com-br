<?php
require '../../config/auth.php';
require '../../layout/header.php';
?>

<div class="card shadow-sm">

    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5>📥 Importação de Recebimentos</h5>
                <small>Selecione o tipo de arquivo para iniciar a importação</small>
            </div>

            <div class="d-flex gap-2">
                <!-- BOTÃO VOLTAR -->
                <a href="menu_fechamento.php" class="btn btn-secondary">
                    ← Voltar
                </a>

                <!-- 🟣 NOVO BOTÃO -->
                <a href="validar_cm.php" class="btn btn-purple">
                    🟣 Validar Clientes
                </a>

                <!-- BOTÃO DE CONCILIAÇÃO -->
                <a href="conciliar_recebimentos.php" class="btn btn-dark">
                    🔗 Conciliação
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">

        <div class="row">

            <!-- 🔵 COMERCIAL -->
            <div class="col-md-6">

                <h5 class="mb-3">📦 COMERCIAL</h5>

                <!-- SIPAG POS -->
                <div class="mb-3 border p-3 rounded">
                    <strong>📘 SIPAG POS (Débito/Crédito)</strong><br>
                    <small>D+1 → CMCONTADOR 3 | D+30 → CMCONTADOR 2</small>

                    <a href="importar_sipag_pos_comercial.php" 
                       class="btn btn-primary w-100 mt-2">
                       Abrir Importação
                    </a>
                </div>

                <!-- SIPAG PIX -->
                <div class="border p-3 rounded">
                    <strong>🟣 SIPAG PIX</strong><br>
                    <small>CMCONTADOR 12</small>

                    <a href="importar_sipag_pix_comercial.php" 
                       class="btn btn-primary w-100 mt-2">
                       Abrir Importação
                    </a>
                </div>

            </div>

            <!-- 🟢 OUTROS -->
            <div class="col-md-6">

                <h5 class="mb-3">📦 OUTROS</h5>

                <!-- SIPAG POS -->
                <div class="mb-3 border p-3 rounded">
                    <strong>🟢 SIPAG POS (Débito/Crédito)</strong><br>
                    <small>D+1 → CMCONTADOR 6 | D+30 → CMCONTADOR 14</small>

                    <a href="importar_sipag_pos_outros.php" 
                       class="btn btn-success w-100 mt-2">
                       Abrir Importação
                    </a>
                </div>

                <!-- SIPAG PIX -->
                <div class="mb-3 border p-3 rounded">
                    <strong>🟡 SIPAG PIX</strong><br>
                    <small>CMCONTADOR 7</small>

                    <a href="importar_sipag_pix_outros.php" 
                       class="btn btn-success w-100 mt-2">
                       Abrir Importação
                    </a>
                </div>

                <!-- PAGSEGURO -->
                <div class="border p-3 rounded">
                    <strong>🟠 PAGSEGURO PIX</strong><br>
                    <small>CMCONTADOR 7</small>

                    <a href="importar_pagseguro.php" 
                       class="btn btn-success w-100 mt-2">
                       Abrir Importação
                    </a>
                </div>

            </div>

        </div>

    </div>
</div>

<?php require '../../layout/footer.php'; ?>