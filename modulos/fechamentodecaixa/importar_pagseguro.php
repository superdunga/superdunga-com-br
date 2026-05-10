<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';
require '../../layout/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$empresa_id = (int)$_SESSION['empresa_id'];
$fallback = regrasImportacaoFallbackArmazem()[4];
$regraImportacao = buscarRegraImportacao($pdo_master, $empresa_id, 'pagseguro_pix', $fallback);

if (!$regraImportacao) {
    echo "<div class='alert alert-warning'>Nenhuma regra de importacao PagSeguro PIX cadastrada para esta empresa.</div>";
    require '../../layout/footer.php';
    exit;
}

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

    // VALIDAR NOME DO ARQUIVO
    if (stripos($nomeArquivo, 'pagseguro') === false) {
        echo "<div class='alert alert-danger'>
                Arquivo inválido - nome deve conter 'pagseguro'
              </div>";
        require '../../layout/footer.php';
        exit;
    }

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

                if ($linha == 1) continue;
                if (count(array_filter($dados)) == 0) continue;
                if (count($dados) < 5) continue;

                $codigo    = trim($dados[0] ?? '');
                $data      = trim($dados[1] ?? '');
                $tipo      = trim($dados[2] ?? '');
                $descricao = trim($dados[3] ?? '');
                $valor     = trim($dados[4] ?? '');

                // FILTRO
                if (strtolower($tipo) !== 'pix recebido') continue;

                $valor_float = tratarValor($valor);
                if ($valor_float <= 0) continue;

                $data_formatada = date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $data)));

                $identificador = $codigo;

                // DUPLICIDADE
                $check = $pdo_master->prepare("SELECT id FROM armazem_conciliacao_recebimentos WHERE empresa_id = ? AND identificador = ?");
                $check->execute([$empresa_id, $identificador]);
                if ($check->fetch()) continue;

                // INSERT 100% ALINHADO
                $stmt = $pdo_master->prepare("
                    INSERT INTO armazem_conciliacao_recebimentos (
                        empresa_id,
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
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ");

                $stmt->execute([
                    $empresa_id,
                    $regraImportacao['origem'], // origem
                    $data_formatada,     // data_venda
                    $valor_float,        // valor_bruto
                    0,                   // valor_desconto
                    $valor_float,        // valor_liquido
                    $identificador,      // identificador
                    'PAGSEGURO PIX',     // descricao
                    $descricao,          // pagador
                    1,                   // parcela
                    1,                   // total_parcelas
                    'LIQUIDADA',         // status
                    $nomeArquivo,        // arquivo_origem
                    (int)$regraImportacao['cm_pix'], // CMCONTADOR
                    'P',                 // tipo_operacao
                    $codigo,             // nsu_transacao
                    ''                   // numero_estabelecimento
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
        <h5>Importar <?= htmlspecialchars($regraImportacao['nome']) ?></h5>
        <a href="importar_recebimentos.php" class="btn btn-secondary btn-sm">← Voltar</a>
    </div>

    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?php if ((int)($regraImportacao['id'] ?? 0) > 0): ?>
                <input type="hidden" name="regra_id" value="<?= (int)$regraImportacao['id'] ?>">
            <?php endif; ?>
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
