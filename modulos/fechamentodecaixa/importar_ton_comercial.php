<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';
require_once '../../config/importacao_ton.php';
require '../../layout/header.php';

$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$regraImportacao = buscarRegraImportacao($pdo_master, $empresa_id, 'ton_comercial', []);

if (!$regraImportacao) {
    echo "<div class='alert alert-warning'>Nenhuma regra de importacao TON Comercial cadastrada para esta empresa.</div>";
    require '../../layout/footer.php';
    exit;
}

$mensagem = null;
$mensagemTipo = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo'])) {
    $arquivo = $_FILES['arquivo']['tmp_name'] ?? '';
    $nomeArquivo = basename((string)($_FILES['arquivo']['name'] ?? ''));
    $extensao = strtolower(pathinfo($nomeArquivo, PATHINFO_EXTENSION));

    try {
        if (($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Falha no envio do arquivo TON.');
        }
        if ($extensao !== 'xlsx') {
            throw new RuntimeException('Importacao bloqueada: selecione o arquivo de vendas TON no formato XLSX.');
        }
        if (!is_uploaded_file($arquivo) && PHP_SAPI !== 'cli') {
            throw new RuntimeException('O arquivo enviado nao e valido.');
        }

        $linhas = tonLerXlsx($arquivo);
        $preparacao = tonPrepararVendas($linhas, $regraImportacao);
        $registros = $preparacao['registros'];
        $ignoradosStatus = (int)$preparacao['ignorados_status'];

        $pdo_master->beginTransaction();
        $check = $pdo_master->prepare("
            SELECT id
            FROM armazem_conciliacao_recebimentos
            WHERE empresa_id = ? AND identificador = ?
            LIMIT 1
        ");
        $insert = $pdo_master->prepare("
            INSERT INTO armazem_conciliacao_recebimentos (
                empresa_id, origem, data_venda, data_prevista, valor_bruto, valor_desconto, valor_liquido,
                identificador, descricao, pagador, parcela, total_parcelas, status,
                arquivo_origem, CMCONTADOR, tipo_operacao, bandeira, nsu_transacao,
                numero_estabelecimento, id_transacao
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");

        $importados = 0;
        $duplicados = 0;
        foreach ($registros as $registro) {
            $check->execute([$empresa_id, $registro['identificador']]);
            if ($check->fetchColumn()) {
                $duplicados++;
                continue;
            }

            $insert->execute([
                $empresa_id,
                $regraImportacao['origem'],
                $registro['data_venda'],
                $registro['data_prevista'],
                $registro['valor_bruto'],
                $registro['valor_desconto'],
                $registro['valor_liquido'],
                $registro['identificador'],
                $registro['descricao'],
                $registro['pagador'],
                $registro['total_parcelas'],
                $registro['status'],
                $nomeArquivo,
                $registro['cm'],
                $registro['tipo_operacao'],
                $registro['bandeira'],
                $registro['nsu_transacao'],
                $registro['terminal'],
                $registro['id_transacao'],
            ]);
            $importados++;
        }
        $pdo_master->commit();

        $mensagem = "Importacao TON concluida. Registros importados: {$importados}. Duplicados ignorados: {$duplicados}. Status nao aprovados/pagos ignorados: {$ignoradosStatus}.";
    } catch (Throwable $e) {
        if ($pdo_master->inTransaction()) {
            $pdo_master->rollBack();
        }
        $mensagemTipo = 'danger';
        $mensagem = $e->getMessage();
    }
}
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5>Importar <?= htmlspecialchars($regraImportacao['nome']) ?></h5>
        <a href="importar_recebimentos.php" class="btn btn-secondary btn-sm">Voltar</a>
    </div>
    <div class="card-body">
        <?php if ($mensagem !== null): ?>
            <div class="alert alert-<?= htmlspecialchars($mensagemTipo) ?>"><?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="regra_id" value="<?= (int)$regraImportacao['id'] ?>">
            <div class="mb-3">
                <label class="form-label">Arquivo de vendas TON (.xlsx)</label>
                <input type="file" name="arquivo" class="form-control" accept=".xlsx" required>
            </div>
            <button type="submit" class="btn btn-primary">Importar Arquivo</button>
        </form>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
