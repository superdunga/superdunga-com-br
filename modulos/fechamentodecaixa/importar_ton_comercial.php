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
        if (count($linhas) < 2) {
            throw new RuntimeException('O arquivo TON nao possui vendas para importar.');
        }

        $cabecalhosEsperados = [
            'Id da transação', 'Data da transação', 'Valor', 'Taxa', 'Desconto',
            'Valor recebido', 'Status', 'Nome do titular', 'Bandeira',
            'Método de pagamento', 'Parcelas', 'Cartão(últimos 4 dígitos)',
            'Método de captura', 'Tipo de cartão', 'Nº de série',
        ];
        $cabecalhos = array_map('trim', array_slice($linhas[0], 0, count($cabecalhosEsperados)));
        if ($cabecalhos !== $cabecalhosEsperados) {
            throw new RuntimeException('Importacao bloqueada: as colunas nao correspondem ao arquivo de vendas TON esperado.');
        }

        $terminaisPermitidos = preg_split('/\s*[,;]\s*/', (string)($regraImportacao['estabelecimento'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $registros = [];
        $ignoradosStatus = 0;

        foreach (array_slice($linhas, 1) as $indice => $linha) {
            $numeroLinha = $indice + 2;
            $linha = array_pad($linha, count($cabecalhosEsperados), '');
            if (count(array_filter($linha, function ($valor) { return trim((string)$valor) !== ''; })) === 0) {
                continue;
            }

            $idTransacao = trim($linha[0]);
            $dataVenda = tonDataHora($linha[1]);
            $valorBruto = tonValor($linha[2]);
            $valorDesconto = tonValor($linha[4]);
            $valorLiquido = tonValor($linha[5]);
            $status = trim($linha[6]);
            $pagador = trim($linha[7]);
            $bandeira = trim($linha[8]);
            $metodo = trim($linha[9]);
            $totalParcelas = max(1, (int)$linha[10]);
            $finalCartao = trim($linha[11]);
            $tipoCartao = trim($linha[13]);
            $terminal = trim($linha[14]);

            if (strcasecmp($status, 'Paga') !== 0) {
                $ignoradosStatus++;
                continue;
            }
            if ($idTransacao === '' || $dataVenda === null || $valorBruto <= 0) {
                throw new RuntimeException("Linha {$numeroLinha}: identificador, data ou valor da venda invalido.");
            }
            if (abs(($valorBruto - $valorDesconto) - $valorLiquido) > 0.01) {
                throw new RuntimeException("Linha {$numeroLinha}: valor recebido difere do valor menos o desconto.");
            }
            if ($terminaisPermitidos && !in_array($terminal, $terminaisPermitidos, true)) {
                throw new RuntimeException("Linha {$numeroLinha}: terminal TON nao pertence a esta empresa.");
            }

            $classificacao = tonClassificarPagamento($metodo, $regraImportacao);
            if ($classificacao['cm'] <= 0) {
                throw new RuntimeException("Linha {$numeroLinha}: CM nao configurado para {$metodo}.");
            }

            $identificador = 'TON-' . $idTransacao;
            if (isset($registros[$identificador])) {
                throw new RuntimeException("Linha {$numeroLinha}: ID da transacao repetido dentro do arquivo.");
            }

            $descricaoPartes = array_filter(['TON', $metodo, $tipoCartao, $bandeira, $finalCartao !== '' ? 'Final ' . $finalCartao : '']);
            $registros[$identificador] = [
                'id_transacao' => $idTransacao,
                'data_venda' => $dataVenda,
                'valor_bruto' => $valorBruto,
                'valor_desconto' => $valorDesconto,
                'valor_liquido' => $valorLiquido,
                'identificador' => $identificador,
                'descricao' => implode(' - ', $descricaoPartes),
                'pagador' => $pagador !== '' ? $pagador : 'TON',
                'total_parcelas' => $totalParcelas,
                'status' => $status,
                'cm' => $classificacao['cm'],
                'tipo_operacao' => $classificacao['tipo'],
                'bandeira' => $bandeira,
                'terminal' => $terminal,
            ];
        }

        if (!$registros) {
            throw new RuntimeException('Nenhuma venda paga foi encontrada no arquivo TON.');
        }

        $pdo_master->beginTransaction();
        $check = $pdo_master->prepare("
            SELECT id
            FROM armazem_conciliacao_recebimentos
            WHERE empresa_id = ? AND identificador = ?
            LIMIT 1
        ");
        $insert = $pdo_master->prepare("
            INSERT INTO armazem_conciliacao_recebimentos (
                empresa_id, origem, data_venda, valor_bruto, valor_desconto, valor_liquido,
                identificador, descricao, pagador, parcela, total_parcelas, status,
                arquivo_origem, CMCONTADOR, tipo_operacao, bandeira, nsu_transacao,
                numero_estabelecimento, id_transacao
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?
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
                $registro['id_transacao'],
                $registro['terminal'],
                $registro['id_transacao'],
            ]);
            $importados++;
        }
        $pdo_master->commit();

        $mensagem = "Importacao TON concluida. Registros importados: {$importados}. Duplicados ignorados: {$duplicados}. Status nao pagos ignorados: {$ignoradosStatus}.";
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
