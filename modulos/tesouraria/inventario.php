<?php
require '../../config/auth.php';
require '../../layout/header.php';
require '../../config/conexao.php';

$empresa_id = (int)$_SESSION['empresa_id'];

/* =========================
   INVENTÁRIO DO SISTEMA (CÉDULAS)
========================= */
$sqlSistema = "
    SELECT 
        t.id,
        t.valor,
        t.descricao,
        COALESCE(SUM(
            CASE 
                WHEN m.id IS NOT NULL AND d.tipo = 'entrada' THEN d.quantidade
                WHEN m.id IS NOT NULL AND d.tipo = 'saida' THEN -d.quantidade
                ELSE 0
            END
        ), 0) AS quantidade
    FROM tesouraria_tipos_dinheiro t
    LEFT JOIN tesouraria_movimentacoes_detalhes d 
        ON d.tipo_dinheiro_id = t.id
    LEFT JOIN tesouraria_movimentacoes m
        ON m.id = d.movimentacao_id
       AND m.empresa_id = ?
    GROUP BY t.id, t.valor, t.descricao
    ORDER BY t.valor DESC
";

$stmtSistema = $pdo_master->prepare($sqlSistema);
$stmtSistema->execute([$empresa_id]);
$dadosSistema = $stmtSistema->fetchAll(PDO::FETCH_ASSOC);

$totalSistema = 0;
foreach ($dadosSistema as $d) {
    $totalSistema += $d['quantidade'] * $d['valor'];
}

if ($_SESSION['nivel'] === 'MASTER' && ($_GET['exportar_status'] ?? '') === 'excel') {
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=status_tesouraria_" . date('Ymd_His') . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "\xEF\xBB\xBF";
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>Denominacao</th>";
    echo "<th>Valor Unitario</th>";
    echo "<th>Quantidade Sistema</th>";
    echo "<th>Valor Total</th>";
    echo "<th>Status</th>";
    echo "</tr>";

    foreach ($dadosSistema as $d) {
        $quantidade = (int)$d['quantidade'];
        $valor = (float)$d['valor'];
        $total = $quantidade * $valor;
        $status = $quantidade < 0 ? 'NEGATIVO' : 'OK';

        echo "<tr>";
        echo "<td>" . htmlspecialchars((string)$d['descricao']) . "</td>";
        echo "<td>" . number_format($valor, 2, ',', '.') . "</td>";
        echo "<td>" . $quantidade . "</td>";
        echo "<td>" . number_format($total, 2, ',', '.') . "</td>";
        echo "<td>" . $status . "</td>";
        echo "</tr>";
    }

    echo "<tr>";
    echo "<td colspan='3'><strong>Total</strong></td>";
    echo "<td><strong>" . number_format($totalSistema, 2, ',', '.') . "</strong></td>";
    echo "<td></td>";
    echo "</tr>";
    echo "</table>";
    exit;
}

/* =========================
   DETALHE MASTER (LUPA)
========================= */
$movDetalhe = [];

if ($_SESSION['nivel'] === 'MASTER') {

    $stmt = $pdo_master->prepare("
        SELECT 
            d.tipo_dinheiro_id,
            m.data_mov,
            d.tipo,
            d.quantidade,
            d.valor_unitario,
            m.observacao
        FROM tesouraria_movimentacoes_detalhes d
        INNER JOIN tesouraria_movimentacoes m
            ON m.id = d.movimentacao_id
        WHERE m.empresa_id = ?
        ORDER BY m.data_mov DESC, d.id DESC
    ");

    $stmt->execute([$empresa_id]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $movDetalhe[$row['tipo_dinheiro_id']][] = $row;
    }
}

/* =========================
   SALVAR INVENTÁRIO
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $usuario_id = $_SESSION['usuario_id'];
    $valores = $_POST['valor_digitado'] ?? [];

    try {
        $pdo_master->beginTransaction();

        $totalFisico = 0;

        foreach ($dadosSistema as $d) {

            $idTipo = $d['id'];
            $valor  = (float)$d['valor'];
            $sistema = (int)$d['quantidade'];

            $valorDigitado = isset($valores[$idTipo]) ? (float)$valores[$idTipo] : 0;

            $qtd = $valor > 0 ? $valorDigitado / $valor : 0;

            if ($valorDigitado > 0 && floor($qtd) != $qtd) {
                throw new Exception("Valor inválido para " . number_format($valor, 2, ',', '.'));
            }

            $totalFisico += $valorDigitado;
        }

        $diferenca = $totalFisico - $totalSistema;
        $status = ($diferenca == 0) ? 'OK' : 'DIVERGENTE';

        $stmt = $pdo_master->prepare("
            INSERT INTO tesouraria_inventarios
            (empresa_id, usuario_id, valor_sistema, valor_fisico, diferenca, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $empresa_id,
            $usuario_id,
            $totalSistema,
            $totalFisico,
            $diferenca,
            $status
        ]);

        $inventario_id = $pdo_master->lastInsertId();

        $stmtItem = $pdo_master->prepare("
            INSERT INTO tesouraria_inventarios_detalhe
            (inventario_id, tipo_dinheiro_id, quantidade_sistema, quantidade_fisica, diferenca, valor_unitario, valor_total_sistema, valor_total_fisico)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($dadosSistema as $d) {

            $idTipo = $d['id'];
            $valor  = (float)$d['valor'];
            $sistema = (int)$d['quantidade'];

            $valorDigitado = isset($valores[$idTipo]) ? (float)$valores[$idTipo] : 0;

            $qtd = $valor > 0 ? $valorDigitado / $valor : 0;
            $fis = (int)$qtd;

            $stmtItem->execute([
                $inventario_id,
                $idTipo,
                $sistema,
                $fis,
                ($fis - $sistema),
                $valor,
                $sistema * $valor,
                $valorDigitado
            ]);
        }

        $pdo_master->commit();

        header("Location: inventario_resultado.php?id=" . $inventario_id);
        exit;

    } catch (Exception $e) {
        $pdo_master->rollBack();
        echo "<div class='alert alert-danger'>Erro: " . $e->getMessage() . "</div>";
    }
}
?>

<div class="card shadow-sm">
<div class="card-body p-2">

<a href="menu_tesouraria.php" class="btn btn-outline-secondary mb-2 w-100">← Voltar</a>

<?php if ($_SESSION['nivel'] === 'MASTER'): ?>
<button class="btn btn-dark w-100 mb-2" data-bs-toggle="modal" data-bs-target="#modalSistema">
📊 Ver Inventário do Sistema
</button>

<a href="inventario.php?exportar_status=excel"
class="btn btn-success w-100 mb-2">
Baixar status atual em Excel
</a>

<a href="recalcular_estoque.php"
class="btn btn-danger w-100 mb-2"
onclick="return confirm('Confirma o recálculo completo do estoque?');">
🔄 Recalcular Estoque
</a>
<?php endif; ?>

<h5 class="text-center mb-2">Inventário (às cegas)</h5>

<form method="POST">

<div class="row g-2">

<?php foreach ($dadosSistema as $d): ?>
<div class="col-12">
<div class="card border-0 shadow-sm p-2">

<div class="d-flex justify-content-between align-items-center">

<div class="d-flex align-items-center gap-2">
<strong><?= $d['descricao'] ?></strong>

<?php if ($_SESSION['nivel'] === 'MASTER'): ?>
<button type="button" class="btn btn-sm btn-outline-primary"
onclick="abrirDetalhe(<?= $d['id'] ?>,'<?= $d['descricao'] ?>')">
🔍
</button>
<?php endif; ?>
</div>

<input type="number" step="0.01"
name="valor_digitado[<?= $d['id'] ?>]"
class="form-control valor text-center"
data-valor="<?= $d['valor'] ?>"
value="0"
style="width:110px;">
</div>

<small>Qtd: <span class="qtd">0</span></small>

</div>
</div>
<?php endforeach; ?>

</div>

<div class="mt-3 text-center">
<h4>Total Contado: R$ <span id="totalGeral">0,00</span></h4>
<button class="btn btn-success w-100">Salvar Inventário</button>
</div>

</form>

</div>
</div>

<!-- MODAL SISTEMA -->
<div class="modal fade" id="modalSistema">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header">
<h5>Inventário do Sistema</h5>
<button class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<?php foreach ($dadosSistema as $d): ?>
<div class="d-flex justify-content-between border-bottom py-1">
<div><?= $d['descricao'] ?> (<?= $d['quantidade'] ?>)</div>
<div>R$ <?= number_format($d['quantidade']*$d['valor'],2,',','.') ?></div>
</div>
<?php endforeach; ?>
<hr>
<strong>Total: R$ <?= number_format($totalSistema,2,',','.') ?></strong>
</div>
</div>
</div>
</div>

<!-- MODAL DETALHE -->
<div class="modal fade" id="modalDetalhe">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<div class="modal-header">
<h5 id="tituloDetalhe"></h5>
<button class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" id="conteudoDetalhe"></div>
</div>
</div>
</div>

<script>
const movDetalhe = <?= json_encode($movDetalhe, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

function calcular(){
let total=0;
document.querySelectorAll(".valor").forEach(input=>{
let v=parseFloat(input.value||0);
let unit=parseFloat(input.dataset.valor);
let qtd=v/unit;
input.closest('.card').querySelector('.qtd').innerText=Number.isInteger(qtd)?qtd:'-';
total+=v;
});
document.getElementById("totalGeral").innerText=total.toLocaleString('pt-BR',{minimumFractionDigits:2});
}

document.querySelectorAll(".valor").forEach(i=>i.addEventListener("input",calcular));
calcular();


function abrirDetalhe(id, desc){

    let dados = movDetalhe[id] || [];

    let html = '';

    if(dados.length === 0){
        html = "<div class='text-center text-muted'>Nenhuma movimentação encontrada</div>";
    } else {

        // ordem cronológica correta
        dados = dados.slice().reverse();

        let saldoQtd = 0;
        let saldoValor = 0;

        html += "<table class='table table-sm'>";
        html += "<thead><tr>";
        html += "<th>Data</th>";
        html += "<th>Tipo</th>";
        html += "<th>Qtd</th>";
        html += "<th>Valor</th>";
        html += "<th>Saldo Qtd</th>";
        html += "<th>Saldo R$</th>";
        html += "<th>Histórico</th>";
        html += "</tr></thead><tbody>";

        dados.forEach(d => {

            let qtd = parseInt(d.quantidade);
            let valorUnit = parseFloat(d.valor_unitario);
            let valorTotal = qtd * valorUnit;

            if(d.tipo === 'entrada'){
                saldoQtd += qtd;
                saldoValor += valorTotal;
            } else {
                saldoQtd -= qtd;
                saldoValor -= valorTotal;
            }

            let cor = d.tipo === 'saida' ? 'text-danger' : 'text-success';
            let corSaldo = saldoQtd < 0 ? 'text-danger' : '';

            html += `<tr>
                <td>${d.data_mov}</td>
                <td class="${cor}">${d.tipo}</td>
                <td>${qtd}</td>
                <td>R$ ${valorTotal.toLocaleString('pt-BR',{minimumFractionDigits:2})}</td>
                <td class="${corSaldo}"><strong>${saldoQtd}</strong></td>
                <td class="${corSaldo}"><strong>R$ ${saldoValor.toLocaleString('pt-BR',{minimumFractionDigits:2})}</strong></td>
                <td>${d.observacao ?? ''}</td>
            </tr>`;
        });

        html += "</tbody></table>";
    }

    document.getElementById('tituloDetalhe').innerText = "Movimentação - " + desc;
    document.getElementById('conteudoDetalhe').innerHTML = html;

    new bootstrap.Modal(document.getElementById('modalDetalhe')).show();
}
</script>

<?php require '../../layout/footer.php'; ?>
