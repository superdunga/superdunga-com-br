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

                // 🔴 VALIDAR ESTABELECIMENTO (LINHA 2)
                if ($linha == 2) {
                    if (!isset($dados[1]) || trim($dados[1]) !== 'CB-110487250001') {
                        fclose($handle);
                        echo "<div class='alert alert-danger'>
                                Arquivo inválido - estabelecimento não corresponde ao Sipag PIX OUTROS.
                              </div>";
                        require '../../layout/footer.php';
                        exit;
                    }
                    continue;
                }

                // Ignorar cabeçalho
                if ($linha <= 3) continue;

                // Ignorar vazias
                if (count(array_filter($dados)) == 0) continue;

                // Garantir layout PIX (8 colunas)
                if (count($dados) < 8) continue;

                // =========================
                // CAMPOS
                // =========================
                $estabelecimento = trim($dados[0] ?? '');
                $data_venda      = trim($dados[1] ?? '');
                $status          = trim($dados[2] ?? '');
                $transacao       = trim($dados[3] ?? '');
                $terminal        = trim($dados[4] ?? '');
                $pagador         = trim($dados[5] ?? '');
                $valor_reembolso = trim($dados[6] ?? '');
                $valor_venda     = trim($dados[7] ?? '');

                // STATUS
                if (strtoupper($status) !== 'LIQUIDADA') continue;

                if ($pagador === '') {
                    $pagador = 'PIX';
                }

                // VALORES
                $valor_bruto    = tratarValor($valor_venda);
                $valor_liquido  = tratarValor($valor_venda);
                $valor_desconto = tratarValor($valor_reembolso);

                // DATA
                $data_formatada = date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $data_venda)));

                // IDENTIFICADOR
                $identificador = $transacao;

                // EVITAR DUPLICIDADE
                $check = $pdo_master->prepare("SELECT id FROM armazem_conciliacao_recebimentos WHERE identificador = ?");
                $check->execute([$identificador]);

                if ($check->fetch()) continue;

                // DESCRIÇÃO
                $descricao = 'PIX - SIPAG - Terminal ' . $terminal;

                // INSERT
                $stmt = $pdo_master->prepare("
                    INSERT INTO armazem_conciliacao_recebimentos (
                        origem,
                        data_venda,
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
                        nsu_transacao,
                        numero_estabelecimento
                    ) VALUES (
                        'SIPAG_PIX_OUTROS',
                        ?, ?, ?, ?, ?,
                        ?, ?,
                        1, 1,
                        ?, ?,
                        ?, 'P',
                        ?, ?
                    )
                ");

                $stmt->execute([
                    $data_formatada,
                    $valor_bruto,
                    $valor_desconto,
                    $valor_liquido,
                    $identificador,
                    $descricao,
                    $pagador,
                    $status,
                    $nomeArquivo,
                    7, // 🔴 CMCONTADOR OUTROS PIX
                    $transacao,
                    $estabelecimento
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
        <h5>Importar SIPAG PIX OUTROS</h5>

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