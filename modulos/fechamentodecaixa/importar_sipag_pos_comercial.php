<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// =========================
// FUNÇÃO
// =========================
function tratarValor($valor) {
    $valor = str_replace(['R$', ' ', '.'], '', $valor);
    $valor = str_replace(',', '.', $valor);
    return (float)$valor;
}

// =========================
// PROCESSAMENTO
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo'])) {

    $arquivo = $_FILES['arquivo']['tmp_name'];
    $nomeArquivo = $_FILES['arquivo']['name'];

    if (!file_exists($arquivo)) {
        echo "<div class='alert alert-danger'>Arquivo não encontrado.</div>";
    } else {

        $handle = fopen($arquivo, 'r');

        if (!$handle) {
            echo "<div class='alert alert-danger'>Erro ao abrir arquivo.</div>";
        } else {

            $linha = 0;
            $importados = 0;

            while (($dados = fgetcsv($handle, 0, ';')) !== false) {

                $linha++;

                if ($linha == 2) {
                    if (!isset($dados[1]) || trim($dados[1]) !== 'CB-111737460001') {
                        fclose($handle);
                        echo "<div class='alert alert-danger'>
                                Arquivo inválido – estabelecimento não corresponde ao Sipag Comercial.
                              </div>";
                        require '../../layout/footer.php';
                        exit;
                    }
                    continue;
                }

                if ($linha <= 3) continue;
                if (isset($dados[0]) && stripos($dados[0], 'Total') !== false) continue;
                if (count(array_filter($dados)) == 0) continue;

                $numero_estabelecimento = trim($dados[0] ?? '');
                $data_transacao         = trim($dados[1] ?? '');
                $nsu_transacao          = trim($dados[2] ?? '');
                $bandeira               = trim($dados[4] ?? '');
                $forma_pagamento        = trim($dados[5] ?? '');
                $parcela                = (int)($dados[7] ?? 0);
                $total_parcelas         = (int)($dados[8] ?? 0);
                $autorizacao            = trim($dados[9] ?? '');
                $tipo_operacao          = trim($dados[14] ?? '');
                $status                 = trim($dados[20] ?? '');
                $data_prevista          = trim($dados[17] ?? '');
                $valor_bruto            = trim($dados[21] ?? '');
                $valor_desconto         = trim($dados[22] ?? '');
                $valor_liquido          = trim($dados[23] ?? '');

                if (stripos($status, 'Processada') === false) continue;

                if (stripos($forma_pagamento, 'Débito') !== false) {
                    $CMCONTADOR = 3;
                } elseif (stripos($forma_pagamento, 'Crédito') !== false) {
                    $CMCONTADOR = 2;
                } else {
                    continue;
                }

                $valor_bruto    = tratarValor($valor_bruto);
                $valor_desconto = tratarValor($valor_desconto);
                $valor_liquido  = tratarValor($valor_liquido);

                $data_venda = date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $data_transacao)));
                $data_prev  = date('Y-m-d', strtotime(str_replace('/', '-', $data_prevista)));

                $identificador = $nsu_transacao . '-' . $parcela;

                $check = $pdo_master->prepare("SELECT id FROM armazem_conciliacao_recebimentos WHERE identificador = ?");
                $check->execute([$identificador]);

                if ($check->fetch()) continue;

                $descricao = $bandeira . ' - ' . $forma_pagamento;

                $stmt = $pdo_master->prepare("
                    INSERT INTO armazem_conciliacao_recebimentos (
                        origem,
                        data_venda,
                        data_prevista,
                        valor_bruto,
                        valor_desconto,
                        valor_liquido,
                        identificador,
                        descricao,
                        pagador,
                        parcela,
                        total_parcelas,
                        status,
                        arquivo_origem,
                        CMCONTADOR,
                        tipo_operacao,
                        bandeira,
                        nsu_transacao,
                        autorizacao,
                        numero_estabelecimento
                    ) VALUES (
                        'SIPAG_POS_COMERCIAL',
                        ?, ?, ?, ?, ?,
                        ?, ?, 'SIPAG',
                        ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?, ?
                    )
                ");

                $stmt->execute([
                    $data_venda,
                    $data_prev,
                    $valor_bruto,
                    $valor_desconto,
                    $valor_liquido,
                    $identificador,
                    $descricao,
                    $parcela,
                    $total_parcelas,
                    $status,
                    $nomeArquivo,
                    $CMCONTADOR,
                    $tipo_operacao,
                    $bandeira,
                    $nsu_transacao,
                    $autorizacao,
                    $numero_estabelecimento
                ]);

                $importados++;
            }

            fclose($handle);

            echo "<div class='alert alert-success'>
                    Importação concluída! Registros importados: <strong>{$importados}</strong>
                  </div>";
        }
    }
}
?>

<div class="card shadow-sm">

    <div class="card-header d-flex justify-content-between align-items-center">
        <h5>Importar SIPAG POS Comercial</h5>

        <a href="importar_recebimentos.php" class="btn btn-secondary btn-sm">
            ← Voltar
        </a>
    </div>

    <div class="card-body">

        <form method="POST" enctype="multipart/form-data">
            <div class="mb-3">
                <label class="form-label">Selecione o arquivo CSV</label>
                <input type="file" name="arquivo" class="form-control" required>
            </div>

            <button type="submit" class="btn btn-primary">
                Importar Arquivo
            </button>
        </form>

    </div>
</div>

<?php require '../../layout/footer.php'; ?>