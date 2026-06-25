<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';
require '../../layout/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$empresa_id = (int)$_SESSION['empresa_id'];
$regraImportacao = buscarRegraImportacao($pdo_master, $empresa_id, 'granito_pos_comercial', []);
garantirCamposGranitoRecebimentos($pdo_master);
garantirTabelaGranitoAgendaTaxas($pdo_master);

if (!$regraImportacao) {
    echo "<div class='alert alert-warning'>Nenhuma regra de importacao Granito POS Comercial cadastrada para esta empresa.</div>";
    require '../../layout/footer.php';
    exit;
}

function granitoValor($valor) {
    $valor = trim((string)$valor);
    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }
    return (float)$valor;
}

function granitoDataHora($valor) {
    $dt = DateTime::createFromFormat('d/m/Y - H:i:s', trim((string)$valor));
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo'])) {
    $arquivo = $_FILES['arquivo']['tmp_name'];
    $nomeArquivo = $_FILES['arquivo']['name'];

    if (stripos($nomeArquivo, 'transacoes') === false) {
        echo "<div class='alert alert-danger'>Importacao bloqueada: este botao aceita somente o arquivo de transacoes Granito POS.</div>";
        require '../../layout/footer.php';
        exit;
    }

    if (!file_exists($arquivo)) {
        echo "<div class='alert alert-danger'>Arquivo nao encontrado.</div>";
    } else {
        $handle = fopen($arquivo, 'r');

        if (!$handle) {
            echo "<div class='alert alert-danger'>Erro ao abrir arquivo.</div>";
        } else {
            $linha = 0;
            $importados = 0;
            $duplicados = 0;
            $atualizadosComAgenda = 0;

            while (($dados = fgetcsv($handle, 0, ';')) !== false) {
                $linha++;
                if ($linha === 1) continue;
                if (count($dados) < 13) continue;

                $idGranito = trim($dados[0] ?? '');
                $dataVenda = granitoDataHora($dados[1] ?? '');
                $pdv = trim($dados[2] ?? '');
                $tipo = trim($dados[3] ?? '');
                $bandeira = trim($dados[4] ?? '');
                $cartao = trim($dados[5] ?? '');
                $autorizacao = trim($dados[8] ?? '');
                $codTransacao = trim($dados[9] ?? '');
                $valor = granitoValor($dados[11] ?? 0);
                $status = trim($dados[12] ?? '');

                if ($idGranito === '' || $dataVenda === null || strcasecmp($status, 'Aprovada') !== 0 || $valor <= 0) {
                    continue;
                }

                $ehDebito = stripos($tipo, 'debito') !== false || stripos($tipo, 'débito') !== false;
                $cmcontador = $ehDebito ? (int)$regraImportacao['cm_debito'] : (int)$regraImportacao['cm_credito'];
                $tipoOperacao = $ehDebito ? 'D' : 'C';
                $origem = $regraImportacao['origem'];
                $identificador = identificadorGranitoPos($idGranito);

                $check = $pdo_master->prepare("SELECT id FROM armazem_conciliacao_recebimentos WHERE empresa_id = ? AND identificador = ?");
                $check->execute([$empresa_id, $identificador]);
                if ($check->fetch()) {
                    $duplicados++;
                    $atualizadosComAgenda += aplicarGranitoAgendaTaxaNoRecebimento($pdo_master, $empresa_id, $identificador) > 0 ? 1 : 0;
                    continue;
                }

                $stmt = $pdo_master->prepare("
                    INSERT INTO armazem_conciliacao_recebimentos (
                        empresa_id, origem, data_venda, valor_bruto, valor_desconto, valor_liquido,
                        identificador, descricao, pagador, parcela, total_parcelas, status,
                        arquivo_origem, CMCONTADOR, tipo_operacao, bandeira, nsu_transacao,
                        autorizacao, numero_estabelecimento, id_granito, id_transacao
                    ) VALUES (
                        ?, ?, ?, ?, 0, ?, ?, ?, 'GRANITO', 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ");
                $stmt->execute([
                    $empresa_id,
                    $origem,
                    $dataVenda,
                    $valor,
                    $valor,
                    $identificador,
                    trim($bandeira . ' - ' . $tipo . ' - ' . $cartao),
                    $status,
                    $nomeArquivo,
                    $cmcontador,
                    $tipoOperacao,
                    $bandeira,
                    $codTransacao,
                    $autorizacao,
                    $pdv,
                    $idGranito,
                    $idGranito,
                ]);

                $importados++;
                $atualizadosComAgenda += aplicarGranitoAgendaTaxaNoRecebimento($pdo_master, $empresa_id, $identificador) > 0 ? 1 : 0;
            }

            fclose($handle);
            echo "<div class='alert alert-success'>Importacao concluida! Registros importados: <strong>{$importados}</strong>. Duplicados: <strong>{$duplicados}</strong>. Atualizados com agenda: <strong>{$atualizadosComAgenda}</strong>.</div>";
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
                <label class="form-label">Selecione o arquivo CSV de transacoes Granito</label>
                <input type="file" name="arquivo" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Importar Arquivo</button>
        </form>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
