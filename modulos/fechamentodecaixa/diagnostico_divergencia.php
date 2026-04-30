<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

/* =========================
   DATA
========================= */
$data = $_GET['data'] ?? date('Y-m-d');

/* =========================
   JANELA OPERACIONAL
========================= */
$inicio = date('Y-m-d 07:00:00', strtotime($data));
$fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));

/* =========================
   FALTANDO NO CR001
   (tem no sistema e NÃO foi conciliado)
========================= */
$stmtSistema = $pdo_master->prepare("
    SELECT r.*
    FROM armazem_conciliacao_recebimentos r
    WHERE r.data_venda BETWEEN ? AND ?
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_cr001 c
          WHERE c.recebimento_id = r.id
      )
");
$stmtSistema->execute([$inicio, $fim]);
$faltandoCR = $stmtSistema->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   FALTANDO NO SISTEMA
   (tem no CR001 e NÃO foi conciliado)
========================= */
$stmtCR = $pdo_master->prepare("
    SELECT c.*
    FROM armazem_cr001 c
    WHERE c.DTLANC BETWEEN ? AND ?
      AND c.CMCONTADOR <> 9
      AND c.recebimento_id IS NULL
      AND NOT (c.CMCONTADOR = 1 AND c.STATUS = 'QT')
");
$stmtCR->execute([$inicio, $fim]);
$faltandoSistema = $stmtCR->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   SOMATÓRIOS
========================= */
$totalSistema = array_sum(array_column($faltandoCR, 'valor_bruto'));
$totalCR001   = array_sum(array_column($faltandoSistema, 'VLRPARCELA'));

$diferenca = $totalSistema - $totalCR001;
?>

<div class="card shadow-sm mb-3">

    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">🧠 Diagnóstico de Divergência</h5>

        <a href="resumo_prazo.php" class="btn btn-secondary btn-sm">
            ← Voltar
        </a>
    </div>

    <div class="card-body">

        <p><strong>Data:</strong> <?= date('d/m/Y', strtotime($data)) ?></p>

        <div class="row mb-3">

            <div class="col-md-4">
                <div class="alert alert-primary text-center">
                    <strong>Faltando no CR001</strong><br>
                    R$ <?= number_format($totalSistema, 2, ',', '.') ?>
                </div>
            </div>

            <div class="col-md-4">
                <div class="alert alert-warning text-center">
                    <strong>Faltando no Sistema</strong><br>
                    R$ <?= number_format($totalCR001, 2, ',', '.') ?>
                </div>
            </div>

            <div class="col-md-4">
                <div class="alert <?= (abs($diferenca) < 0.01 ? 'alert-success' : 'alert-danger') ?> text-center">
                    <strong>Diferença</strong><br>
                    R$ <?= number_format($diferenca, 2, ',', '.') ?>
                </div>
            </div>

        </div>

        <div class="row">

            <div class="col-md-6">
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        🟦 Faltando no CR001
                    </div>
                    <div class="card-body p-2" style="max-height:300px; overflow:auto;">
                        <table class="table table-sm table-bordered text-center">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Data</th>
                                    <th>Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($faltandoCR)): ?>
                                    <tr>
                                        <td colspan="3" class="text-muted">Nenhum registro</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($faltandoCR as $r): ?>
                                        <tr>
                                            <td><?= $r['id'] ?></td>
                                            <td><?= date('d/m/Y H:i', strtotime($r['data_venda'])) ?></td>
                                            <td>R$ <?= number_format($r['valor_bruto'], 2, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        🟥 Faltando no Sistema
                    </div>
                    <div class="card-body p-2" style="max-height:300px; overflow:auto;">
                        <table class="table table-sm table-bordered text-center">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Data</th>
                                    <th>Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($faltandoSistema)): ?>
                                    <tr>
                                        <td colspan="3" class="text-muted">Nenhum registro</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($faltandoSistema as $c): ?>
                                        <tr>
                                            <td><?= $c['CRCONTADOR'] ?></td>
                                            <td><?= date('d/m/Y H:i', strtotime($c['DTLANC'])) ?></td>
                                            <td>R$ <?= number_format($c['VLRPARCELA'], 2, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

    </div>

</div>

<?php require '../../layout/footer.php'; ?>