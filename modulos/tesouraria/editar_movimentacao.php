<?php
require '../../config/auth.php';
require '../../config/conexao.php';

if ($_SESSION['nivel'] !== 'MASTER') {
    die("Acesso negado");
}

$id = $_GET['id'] ?? null;

if (!$id) {
    die("ID inválido");
}

/* =========================
   MOVIMENTAÇÃO
========================= */
$stmt = $pdo_master->prepare("
    SELECT 
        m.*,
        GROUP_CONCAT(c.caminho_arquivo SEPARATOR '|') AS arquivos,
        GROUP_CONCAT(c.nome_original SEPARATOR '|') AS nomes
    FROM tesouraria_movimentacoes m
    LEFT JOIN tesouraria_comprovantes c 
        ON c.movimentacao_id = m.id
    WHERE m.id = ?
    GROUP BY m.id
");
$stmt->execute([$id]);
$mov = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$mov) {
    die("Movimentação não encontrada");
}

/* =========================
   TIPOS DE DINHEIRO
========================= */
$tipos = $pdo_master->query("
    SELECT id, valor, descricao 
    FROM tesouraria_tipos_dinheiro
    ORDER BY valor DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   DETALHES
========================= */
$stmt = $pdo_master->prepare("
    SELECT * FROM tesouraria_movimentacoes_detalhes
    WHERE movimentacao_id = ?
");
$stmt->execute([$id]);
$detalhes = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* MAPEAR */
$entradaMap = [];
$saidaMap = [];

foreach ($detalhes as $d) {
    if ($d['tipo'] === 'entrada') {
        $entradaMap[$d['tipo_dinheiro_id']] = $d['quantidade'];
    } else {
        $saidaMap[$d['tipo_dinheiro_id']] = $d['quantidade'];
    }
}

require '../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-header">
        <h5>✏️ Editar Movimentação #<?= $mov['id'] ?></h5>
    </div>

    <div class="card-body">

        <form method="POST">

            <!-- HISTÓRICO -->
            <div class="mb-3">
                <label>Histórico</label>
                <input type="text" name="observacao" class="form-control"
                       value="<?= htmlspecialchars($mov['observacao']) ?>">
            </div>

            <!-- COMPROVANTES -->
            <div class="mb-3">
                <label>Comprovantes</label>

                <?php
                if (!empty($mov['arquivos'])) {

                    $arquivos = explode('|', $mov['arquivos']);
                    $nomes = explode('|', $mov['nomes']);

                    foreach ($arquivos as $i => $arq) {

                        $nome = $nomes[$i] ?? 'Arquivo';

                        echo '<div>
                            📄 ' . htmlspecialchars($nome) . '
                            <a href="download.php?file=' . urlencode($arq) . '" 
                               class="btn btn-sm btn-outline-primary ms-2">
                                Baixar
                            </a>
                        </div>';
                    }

                } else {
                    echo '<div class="text-muted">Sem comprovantes</div>';
                }
                ?>
            </div>

            <div class="row">

                <!-- ENTRADA -->
                <div class="col-md-6">
                    <h6 class="text-success">Entrada</h6>

                    <?php foreach ($tipos as $t): 
                        $qtd = $entradaMap[$t['id']] ?? 0;
                        $total = $qtd * $t['valor'];
                    ?>
                        <div class="d-flex justify-content-between mb-1">
                            <span><?= number_format($t['valor'], 2, ',', '.') ?></span>

                            <input type="number"
                                   name="entrada[<?= $t['id'] ?>]"
                                   value="<?= $qtd ?>"
                                   class="form-control form-control-sm"
                                   style="width:80px;">

                            <span class="text-success">
                                R$ <?= number_format($total, 2, ',', '.') ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- SAÍDA -->
                <div class="col-md-6">
                    <h6 class="text-danger">Saída</h6>

                    <?php foreach ($tipos as $t): 
                        $qtd = $saidaMap[$t['id']] ?? 0;
                        $total = $qtd * $t['valor'];
                    ?>
                        <div class="d-flex justify-content-between mb-1">
                            <span><?= number_format($t['valor'], 2, ',', '.') ?></span>

                            <input type="number"
                                   name="saida[<?= $t['id'] ?>]"
                                   value="<?= $qtd ?>"
                                   class="form-control form-control-sm"
                                   style="width:80px;">

                            <span class="text-danger">
                                R$ <?= number_format($total, 2, ',', '.') ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>

            <hr>

            <!-- TOTAIS -->
            <div class="d-flex justify-content-between">
                <strong>Total Entrada:</strong>
                <span class="text-success" id="totalEntrada">R$ 0,00</span>
            </div>

            <div class="d-flex justify-content-between">
                <strong>Total Saída:</strong>
                <span class="text-danger" id="totalSaida">R$ 0,00</span>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
                <strong>Resultado:</strong>
                <strong id="resultado">R$ 0,00</strong>
            </div>

            <button class="btn btn-success mt-3">
                💾 Salvar Alterações
            </button>

            <a href="extrato.php" class="btn btn-secondary mt-3">
                ← Voltar
            </a>

        </form>

    </div>
</div>

<script>
function calcularTotais() {
    let entrada = 0;
    let saida = 0;

    document.querySelectorAll('[name^="entrada"]').forEach(el => {
        let valor = parseFloat(el.name.match(/\[(.*?)\]/)[1]);
        entrada += valor * (parseInt(el.value) || 0);
    });

    document.querySelectorAll('[name^="saida"]').forEach(el => {
        let valor = parseFloat(el.name.match(/\[(.*?)\]/)[1]);
        saida += valor * (parseInt(el.value) || 0);
    });

    document.getElementById('totalEntrada').innerText = 'R$ ' + entrada.toFixed(2).replace('.', ',');
    document.getElementById('totalSaida').innerText = 'R$ ' + saida.toFixed(2).replace('.', ',');
    document.getElementById('resultado').innerText = 'R$ ' + (entrada - saida).toFixed(2).replace('.', ',');
}

document.querySelectorAll('input').forEach(el => {
    el.addEventListener('input', calcularTotais);
});

calcularTotais();
</script>

<?php require '../../layout/footer.php'; ?>