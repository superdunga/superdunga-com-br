<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

/* =========================
   DATA
========================= */
$data = $_GET['data'] ?? date('Y-m-d');

$inicio = date('Y-m-d 07:00:00', strtotime($data));
$fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));

/* =========================
   SALVAR VALIDAÇÃO
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $ids = $_POST['validar'] ?? [];

    if (!empty($ids)) {

        // 🔥 GARANTE USUÁRIO
        $usuario = $_SESSION['usuario_id'] ?? 0;

        // 🔥 PROCESSA EM LOTES (EVITA QUEBRAR)
        $lotes = array_chunk($ids, 100);

        foreach ($lotes as $lote) {

            $in = implode(',', array_fill(0, count($lote), '?'));

            $sql = "
                UPDATE armazem_cr001
                SET validado = 'S',
                    data_validacao = NOW(),
                    usuario_validacao = ?
                WHERE CRCONTADOR IN ($in)
                  AND COALESCE(excluido_firebird, 'N') = 'N'
            ";

            $stmt = $pdo_master->prepare($sql);

            $params = array_merge([$usuario], $lote);

            $stmt->execute($params);
        }
    }

    header("Location: validar_cm.php?data=" . $data);
    exit;
}

/* =========================
   BUSCAR REGISTROS (CM = 9)
========================= */
$stmt = $pdo_master->prepare("
    SELECT 
        c.CRCONTADOR,
        c.DTLANC,
        c.VLRPARCELA,
        cli.NOME
    FROM armazem_cr001 c
    LEFT JOIN armazem_cr002 cli
        ON cli.CLICONTADOR = c.CLICONTADOR
    WHERE c.DTLANC BETWEEN ? AND ?
      AND c.CMCONTADOR = 9
      AND (c.validado IS NULL OR c.validado = 'N')
      AND COALESCE(c.excluido_firebird, 'N') = 'N'
    ORDER BY c.VLRPARCELA ASC
");

$stmt->execute([$inicio, $fim]);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .checkbox-lg {
        width: 22px;
        height: 22px;
        transform: scale(1.3);
        cursor: pointer;
    }
</style>

<div class="card shadow-sm">

    <div class="card-header d-flex justify-content-between align-items-center">
        <h5>🟣 Validação CMCONTADOR = 9</h5>

        <a href="menu_fechamento.php" class="btn btn-secondary btn-sm">
            ← Voltar
        </a>
    </div>

    <div class="card-body">

        <form method="GET" class="row mb-3">
            <div class="col-md-3">
                <input type="date" name="data" value="<?= htmlspecialchars($data) ?>" class="form-control">
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary w-100">Filtrar</button>
            </div>
        </form>

        <form method="POST">

            <table class="table table-bordered table-hover text-center">

                <thead class="table-dark">
                    <tr>
                        <th>✔</th>
                        <th>CRCONTADOR</th>
                        <th>Data</th>
                        <th>Valor</th>
                        <th>Cliente</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($registros)): ?>
                        <tr>
                            <td colspan="5" class="text-muted">
                                Nenhum registro pendente
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($registros as $r): ?>
                            <tr>
                                <td class="text-center">
                                    <input type="checkbox" class="checkbox-lg" name="validar[]" value="<?= $r['CRCONTADOR'] ?>">
                                </td>

                                <td><?= $r['CRCONTADOR'] ?></td>

                                <td><?= date('d/m/Y H:i', strtotime($r['DTLANC'])) ?></td>

                                <td>R$ <?= number_format($r['VLRPARCELA'], 2, ',', '.') ?></td>

                                <td><?= htmlspecialchars($r['NOME'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

            <?php if (!empty($registros)): ?>
                <button class="btn btn-success">
                    ✔ Validar selecionados
                </button>
            <?php endif; ?>

        </form>

    </div>

</div>

<?php require '../../layout/footer.php'; ?>
