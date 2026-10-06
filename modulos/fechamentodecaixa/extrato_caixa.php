<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

$data  = $_GET['data'] ?? '';
$caixa = $_GET['caixa'] ?? '';
$empresa_id = (int)$_SESSION['empresa_id'];

function saldoComSinalCaixa(float $valor): string
{
    if (abs($valor) < 0.01) {
        return '<span class="fw-bold">R$ 0,00</span>';
    }

    $sinal = $valor < 0 ? 'D' : 'C';
    $classe = $valor < 0 ? 'text-danger border-danger' : 'text-success border-success';

    return 'R$ ' . number_format(abs($valor), 2, ',', '.') .
        ' <span class="badge bg-white border ' . $classe . '">' . $sinal . '</span>';
}

if (!$data || !$caixa) {
    echo "<div class='alert alert-danger'>Parâmetros inválidos</div>";
    exit;
}

$dataOperacionalBncSql = "CASE
    WHEN UPPER(TRIM(COALESCE(b.TIPODOCORIGEM, ''))) = 'VENDA'
         AND v.DTLANC IS NOT NULL
        THEN DATE(DATE_SUB(v.DTLANC, INTERVAL 7 HOUR))
    WHEN DATE(b.DTMOV) <> DATE(b.DTLANC) THEN DATE(b.DTMOV)
    ELSE DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR))
END";
$dataHoraMovimentoBncSql = "CASE
    WHEN UPPER(TRIM(COALESCE(b.TIPODOCORIGEM, ''))) = 'VENDA'
         AND v.DTLANC IS NOT NULL
        THEN v.DTLANC
    ELSE b.DTLANC
END";

$stmt = $pdo_master->prepare("
    SELECT 
        b.MOVCONTADOR,
        b.DTMOV,
        b.HISTMOV,
        b.NUMDOCORIGEM,
        b.DTLANC,
        $dataHoraMovimentoBncSql AS DTLANC_CAIXA,
        b.TIPOMOV,
        b.VALORMOV,

        CASE 
            WHEN b.TIPOMOV = 'C' THEN b.VALORMOV
            WHEN b.TIPOMOV = 'D' THEN -b.VALORMOV
            ELSE 0
        END AS valor_calculado,

        CASE 
            WHEN COALESCE(b.deletado, 'N') = 'S' THEN 'DELETADO'
            ELSE 'ATIVO'
        END AS status

    FROM armazem_bnc001 b
    LEFT JOIN armazem_est007 v
      ON v.EMPRESA = b.EMPRESA
     AND v.VENDACONTADOR = CAST(b.NUMDOCORIGEM AS UNSIGNED)
     AND UPPER(TRIM(COALESCE(b.TIPODOCORIGEM, ''))) = 'VENDA'

    WHERE b.CBCONTADOR = ?
      AND b.EMPRESA = ?
      AND $dataOperacionalBncSql = ?
      AND COALESCE(b.deletado, 'N') <> 'S'

    ORDER BY TIME($dataHoraMovimentoBncSql), b.MOVCONTADOR
");

$stmt->execute([$caixa, $empresa_id, $data]);

$lancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* SEPARAÇÃO */
$ativos = [];
$deletados = [];

foreach ($lancamentos as $l) {
    if ($l['status'] == 'DELETADO') {
        $deletados[] = $l;
    } else {
        $ativos[] = $l;
    }
}

$saldo = 0;
?>

<div class="card shadow-sm">

<div class="card-header d-flex justify-content-between align-items-center">
    <div>
        <h5 class="mb-0">📄 Extrato do Caixa</h5>
        <small>
            Data: <?= date('d/m/Y', strtotime($data)) ?> |
            Caixa: <?= $caixa ?>
        </small>
    </div>

    <a href="conciliacao_dinheiro.php" class="btn btn-secondary">← Voltar</a>
</div>

<div class="card-body table-responsive">

<!-- 🔵 EXTRATO PRINCIPAL -->
<h6 class="text-success">✔ Lançamentos válidos</h6>

<table class="table table-sm table-bordered text-center align-middle">
<thead class="table-dark">
<tr>
    <th>Hora</th>
    <th>Histórico</th>
    <th>Doc</th>
    <th>Tipo</th>
    <th>Valor</th>
    <th>Saldo</th>
</tr>
</thead>
<tbody>

<?php foreach ($ativos as $l):

    $saldo += (float)$l['valor_calculado'];
?>

<tr>
    <td><?= date('H:i:s', strtotime($l['DTLANC_CAIXA'])) ?></td>
    <td><?= htmlspecialchars($l['HISTMOV']) ?></td>
    <td><?= $l['NUMDOCORIGEM'] ?></td>
    <td><?= $l['TIPOMOV'] == 'C' ? 'Entrada' : 'Saída' ?></td>
    <td>R$ <?= number_format($l['valor_calculado'], 2, ',', '.') ?></td>
    <td class="fw-bold"><?= saldoComSinalCaixa((float)$saldo) ?></td>
</tr>

<?php endforeach; ?>

<tr class="table-dark fw-bold">
    <td colspan="5">Saldo Final</td>
    <td><?= saldoComSinalCaixa((float)$saldo) ?></td>
</tr>

</tbody>
</table>

<!-- 🔴 DELETADOS -->
<?php if (count($deletados) > 0): ?>

<h6 class="text-danger mt-4">❌ Lançamentos removidos no Firebird (não considerados)</h6>

<table class="table table-sm table-bordered text-center align-middle">
<thead class="table-danger">
<tr>
    <th>Hora</th>
    <th>Histórico</th>
    <th>Doc</th>
    <th>Tipo</th>
    <th>Valor</th>
</tr>
</thead>
<tbody>

<?php foreach ($deletados as $l): ?>

<tr class="table-danger">
    <td><?= date('H:i:s', strtotime($l['DTLANC_CAIXA'])) ?></td>
    <td><?= htmlspecialchars($l['HISTMOV']) ?></td>
    <td><?= $l['NUMDOCORIGEM'] ?></td>
    <td><?= $l['TIPOMOV'] == 'C' ? 'Entrada' : 'Saída' ?></td>
    <td>R$ <?= number_format($l['valor_calculado'], 2, ',', '.') ?></td>
</tr>

<?php endforeach; ?>

</tbody>
</table>

<?php endif; ?>

<!-- STATUS FINAL -->
<?php if (abs($saldo) < 0.01): ?>

<div class="alert alert-success">
    ✔ Caixa conferido
</div>

<?php else: ?>

<div class="alert alert-danger">
    ❌ Diferença: <?= saldoComSinalCaixa((float)$saldo) ?>
</div>

<?php endif; ?>

</div>
</div>

<?php require '../../layout/footer.php'; ?>
