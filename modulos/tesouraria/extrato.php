<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresa_id = $_SESSION['empresa_id'];

/* =========================
   FILTROS
========================= */

// DATA (mês atual automático)
$data_ini = $_GET['data_ini'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');

// TIPO
$tipo = $_GET['tipo'] ?? '';

// HISTÓRICO
$historico = $_GET['historico'] ?? '';

$where = "WHERE m.empresa_id = ?";
$params = [$empresa_id];

// filtro data
if (!empty($data_ini)) {
    $where .= " AND DATE(m.data_mov) >= ?";
    $params[] = $data_ini;
}

if (!empty($data_fim)) {
    $where .= " AND DATE(m.data_mov) <= ?";
    $params[] = $data_fim;
}

// filtro tipo
if ($tipo === 'C' || $tipo === 'D') {
    $where .= " AND m.tipo_operacao = ?";
    $params[] = $tipo;
}

// filtro histórico
if (!empty($historico)) {
    $where .= " AND m.observacao LIKE ?";
    $params[] = "%$historico%";
}

/* =========================
   SALDO INICIAL
========================= */

$saldoInicial = 0;

if (!empty($data_ini)) {

    $sqlSaldo = "
        SELECT SUM(valor_operacao) as saldo
        FROM tesouraria_movimentacoes
        WHERE empresa_id = ?
          AND DATE(data_mov) < ?
    ";

    $stmtSaldo = $pdo_master->prepare($sqlSaldo);
    $stmtSaldo->execute([$empresa_id, $data_ini]);

    $saldoInicial = (float) ($stmtSaldo->fetchColumn() ?? 0);
}

/* =========================
   BUSCAR MOVIMENTAÇÕES
========================= */
$stmt = $pdo_master->prepare("
    SELECT 
        m.*,
        GROUP_CONCAT(c.caminho_arquivo ORDER BY c.id SEPARATOR '|') AS arquivos,
        GROUP_CONCAT(c.nome_original ORDER BY c.id SEPARATOR '|') AS nomes
    FROM tesouraria_movimentacoes m
    LEFT JOIN tesouraria_comprovantes c 
        ON c.movimentacao_id = m.id
    $where
    GROUP BY 
        m.id, m.empresa_id, m.data_mov, m.tipo_operacao, 
        m.valor_operacao, m.valor_entregue, m.valor_troco, 
        m.usuario_id, m.observacao, m.caminho_foto, 
        m.firebird_tabela, m.firebird_id, m.conciliado
    ORDER BY m.id ASC
");

$stmt->execute($params);
$movimentacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   DETALHAMENTO (MASTER)
========================= */
$detalhesMov = [];

if ($_SESSION['nivel'] === 'MASTER') {

    $sql = "
        SELECT 
            d.movimentacao_id,
            t.descricao,
            t.valor,
            d.quantidade,
            d.tipo
        FROM tesouraria_movimentacoes_detalhes d
        JOIN tesouraria_tipos_dinheiro t 
            ON t.id = d.tipo_dinheiro_id
    ";

    $dados = $pdo_master->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dados as $d) {
        $detalhesMov[$d['movimentacao_id']][] = $d;
    }
}

require '../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-body">

        <h4 class="mb-3">Extrato da Tesouraria</h4>

        <!-- FILTROS -->
        <form method="GET" class="row g-2 mb-3">

            <div class="col-md-2">
                <label>Data Inicial</label>
                <input type="date" name="data_ini" class="form-control"
                       value="<?= $data_ini ?>">
            </div>

            <div class="col-md-2">
                <label>Data Final</label>
                <input type="date" name="data_fim" class="form-control"
                       value="<?= $data_fim ?>">
            </div>

            <div class="col-md-2">
                <label>Tipo</label>
                <select name="tipo" class="form-control">
                    <option value="">Todos</option>
                    <option value="C" <?= $tipo == 'C' ? 'selected' : '' ?>>Crédito</option>
                    <option value="D" <?= $tipo == 'D' ? 'selected' : '' ?>>Débito</option>
                </select>
            </div>

            <div class="col-md-4">
                <label>Histórico</label>
                <input type="text" name="historico" class="form-control"
                       value="<?= htmlspecialchars($historico) ?>">
            </div>

            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-primary w-100">Filtrar</button>
            </div>

        </form>

        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Data</th>
                        <th>ID</th>
                        <th>Histórico</th>
                        <th>Tipo</th>
                        <th>Comprovante / Detalhe</th>
                        <th>Valor</th>
                        <th>Saldo</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (!empty($movimentacoes)): ?>
                    <?php $saldo = $saldoInicial; ?>

                    <?php foreach ($movimentacoes as $m): ?>
                        <?php
                        $valor = (float)$m['valor_operacao'];
                        $saldo += $valor;
                        ?>

                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($m['data_mov'])) ?></td>
                            <td><?= $m['id'] ?></td>
                            <td><?= htmlspecialchars($m['observacao']) ?></td>

                            <td>
                                <?= $m['tipo_operacao'] === 'D'
                                    ? '<span class="text-danger">Débito</span>'
                                    : ($m['tipo_operacao'] === 'C'
                                        ? '<span class="text-success">Crédito</span>'
                                        : '<span class="text-primary">Troca</span>') ?>
                            </td>

                            <!-- ✅ ALTERAÇÃO CIRÚRGICA AQUI -->
                            <td>
                                <div class="d-flex align-items-center gap-1">

                                    <?php if ($_SESSION['nivel'] === 'MASTER'): ?>

                                        <button 
                                            class="btn btn-sm btn-outline-dark"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalMov<?= $m['id'] ?>"
                                            title="Detalhes">
                                            🔍
                                        </button>

                                        <a href="movimentar.php?id=<?= $m['id'] ?>" 
                                           class="btn btn-sm btn-outline-dark"
                                           title="Editar">
                                            ✏️
                                        </a>

                                    <?php endif; ?>

                                    <?php
                                    if (!empty($m['arquivos'])) {

                                        $arquivos = explode('|', $m['arquivos']);

                                        foreach ($arquivos as $arq) {

                                            echo '
                                            <a href="download.php?file=' . urlencode($arq) . '" 
                                               class="btn btn-sm btn-outline-dark"
                                               title="Baixar comprovante">
                                                📄
                                            </a>';
                                        }

                                    }
                                    ?>

                                </div>
                            </td>

                            <td class="<?= $valor < 0 ? 'text-danger' : 'text-success' ?>">
                                R$ <?= number_format($valor, 2, ',', '.') ?>
                            </td>

                            <td>
                                <strong>R$ <?= number_format($saldo, 2, ',', '.') ?></strong>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </div>
</div>

<!-- MODAIS (INALTERADO) -->
<?php if ($_SESSION['nivel'] === 'MASTER'): ?>
<?php foreach ($movimentacoes as $m): ?>

<div class="modal fade" id="modalMov<?= $m['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5>Movimentação #<?= $m['id'] ?></h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <?php
                $itens = $detalhesMov[$m['id']] ?? [];
                $entrada = 0;
                $saida = 0;
                ?>

                <?php if ($itens): ?>

                    <?php foreach ($itens as $i): 
                        $valorLinha = $i['quantidade'] * $i['valor'];

                        if ($i['tipo'] === 'entrada') {
                            $entrada += $valorLinha;
                        } else {
                            $saida += $valorLinha;
                        }
                    ?>

                        <div class="d-flex justify-content-between border-bottom py-1">
                            <div>
                                <strong><?= $i['descricao'] ?></strong><br>
                                <small>Qtd: <?= $i['quantidade'] ?></small>
                            </div>

                            <div class="<?= $i['tipo'] === 'entrada' ? 'text-success' : 'text-danger' ?>">
                                <?= $i['tipo'] === 'entrada' ? '+' : '-' ?>
                                R$ <?= number_format($valorLinha, 2, ',', '.') ?>
                            </div>
                        </div>

                    <?php endforeach; ?>

                    <hr>

                    <div class="d-flex justify-content-between">
                        <strong>Entrada:</strong>
                        <span class="text-success">R$ <?= number_format($entrada, 2, ',', '.') ?></span>
                    </div>

                    <div class="d-flex justify-content-between">
                        <strong>Saída:</strong>
                        <span class="text-danger">R$ <?= number_format($saida, 2, ',', '.') ?></span>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between">
                        <strong>Total:</strong>
                        <strong>R$ <?= number_format($entrada - $saida, 2, ',', '.') ?></strong>
                    </div>

                <?php else: ?>

                    <div class="text-center text-danger">
                        ⚠️ Sem detalhamento
                    </div>

                <?php endif; ?>

            </div>

        </div>
    </div>
</div>

<?php endforeach; ?>
<?php endif; ?>

<style>
.btn-voltar-fixo {
    position: fixed;
    bottom: 15px;
    left: 15px;
}
</style>

<div class="btn-voltar-fixo">
    <button onclick="window.location.href='menu_tesouraria.php'" class="btn btn-secondary">
        ← Voltar
    </button>
</div>

<?php require '../../layout/footer.php'; ?>