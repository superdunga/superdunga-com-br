<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';
require '../../layout/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$empresa_id = (int)$_SESSION['empresa_id'];
$regraImportacao = buscarRegraImportacao($pdo_master, $empresa_id, 'granito_pix_comercial', []);

if (!$regraImportacao) {
    echo "<div class='alert alert-warning'>Nenhuma regra de importacao Granito PIX Comercial cadastrada para esta empresa.</div>";
    require '../../layout/footer.php';
    exit;
}

function granitoPixValor($valor) {
    $valor = trim((string)$valor);
    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }
    return (float)$valor;
}

function granitoPixDataHora($valor) {
    $dt = DateTime::createFromFormat('d/m/Y - H:i:s', trim((string)$valor));
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

function granitoPixData($valor) {
    $dt = DateTime::createFromFormat('d/m/Y', trim((string)$valor));
    return $dt ? $dt->format('Y-m-d') : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo'])) {
    $arquivo = $_FILES['arquivo']['tmp_name'];
    $nomeArquivo = $_FILES['arquivo']['name'];

    if (!file_exists($arquivo)) {
        echo "<div class='alert alert-danger'>Arquivo nao encontrado.</div>";
    } else {
        $handle = fopen($arquivo, 'r');

        if (!$handle) {
            echo "<div class='alert alert-danger'>Erro ao abrir arquivo.</div>";
        } else {
            $linha = 0;
            $importados = 0;

            while (($dados = fgetcsv($handle, 0, ';')) !== false) {
                $linha++;
                if ($linha === 1) continue;
                if (count($dados) < 11) continue;

                $idTransacao = trim($dados[0] ?? '');
                $dataVenda = granitoPixDataHora($dados[1] ?? '');
                $tipo = trim($dados[2] ?? '');
                $status = trim($dados[3] ?? '');
                $parcelaTexto = trim($dados[4] ?? '1/1');
                $bandeira = trim($dados[5] ?? '');
                $valorBruto = granitoPixValor($dados[6] ?? 0);
                $valorTaxa = granitoPixValor($dados[7] ?? 0);
                $valorAntecipacao = granitoPixValor($dados[8] ?? 0);
                $valorLiquido = granitoPixValor($dados[9] ?? 0);
                $dataPagamento = granitoPixData($dados[10] ?? '');

                if ($idTransacao === '' || $dataVenda === null || strcasecmp($tipo, 'Pagamento Instantâneo') !== 0 || strcasecmp($status, 'Pago') !== 0 || $valorBruto <= 0) {
                    continue;
                }

                [$parcela, $totalParcelas] = array_pad(array_map('intval', explode('/', $parcelaTexto)), 2, 1);
                $identificador = 'GRANITO_PIX_' . $idTransacao;

                $check = $pdo_master->prepare("SELECT id FROM armazem_conciliacao_recebimentos WHERE empresa_id = ? AND identificador = ?");
                $check->execute([$empresa_id, $identificador]);
                if ($check->fetch()) continue;

                $stmt = $pdo_master->prepare("
                    INSERT INTO armazem_conciliacao_recebimentos (
                        empresa_id, origem, data_venda, data_prevista, data_recebimento,
                        valor_bruto, valor_desconto, valor_liquido, identificador, descricao,
                        pagador, parcela, total_parcelas, status, arquivo_origem, CMCONTADOR,
                        tipo_operacao, bandeira, nsu_transacao, numero_estabelecimento
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PIX - GRANITO', 'GRANITO PIX',
                        ?, ?, ?, ?, ?, 'P', ?, ?, ''
                    )
                ");
                $stmt->execute([
                    $empresa_id,
                    $regraImportacao['origem'],
                    $dataVenda,
                    $dataPagamento,
                    $dataPagamento,
                    $valorBruto,
                    $valorTaxa + $valorAntecipacao,
                    $valorLiquido,
                    $identificador,
                    $parcela ?: 1,
                    $totalParcelas ?: 1,
                    $status,
                    $nomeArquivo,
                    (int)$regraImportacao['cm_pix'],
                    $bandeira,
                    $idTransacao,
                ]);

                $importados++;
            }

            fclose($handle);
            echo "<div class='alert alert-success'>Importacao concluida! Registros importados: <strong>{$importados}</strong></div>";
        }
    }
}
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5>Importar <?= htmlspecialchars($regraImportacao['nome']) ?></h5>
        <a href="importar_recebimentos.php" class="btn btn-secondary btn-sm">Voltar</a>
    </div>

    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="regra_id" value="<?= (int)$regraImportacao['id'] ?>">
            <div class="mb-3">
                <label class="form-label">Selecione o arquivo CSV de agenda Granito</label>
                <input type="file" name="arquivo" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Importar Arquivo</button>
        </form>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
