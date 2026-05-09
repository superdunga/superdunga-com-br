<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

/* =========================
   DATA
========================= */
$data = $_GET['data'] ?? date('Y-m-d');
$todos = ($_GET['todos'] ?? '') === '1';
$empresa_id = (int)$_SESSION['empresa_id'];

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
                  AND EMPRESA = ?
                  AND COALESCE(excluido_firebird, 'N') = 'N'
            ";

            $stmt = $pdo_master->prepare($sql);

            $params = array_merge([$usuario], $lote, [$empresa_id]);

            $stmt->execute($params);
        }
    }

    $redirect = $todos ? "validar_cm.php?todos=1" : "validar_cm.php?data=" . urlencode($data);
    header("Location: " . $redirect);
    exit;
}

/* =========================
   BUSCAR REGISTROS (CM = 9)
========================= */
$whereData = $todos ? "" : "AND c.DTLANC BETWEEN ? AND ?";
$stmt = $pdo_master->prepare("
    SELECT 
        c.CRCONTADOR,
        c.DTLANC,
        c.VLRPARCELA,
        cli.NOME
    FROM armazem_cr001 c
    LEFT JOIN armazem_cr002 cli
        ON cli.CLICONTADOR = c.CLICONTADOR
       AND cli.EMPRESA = c.EMPRESA
    WHERE c.CMCONTADOR = 9
      AND c.EMPRESA = ?
      $whereData
      AND (c.validado IS NULL OR c.validado = 'N')
      AND COALESCE(c.excluido_firebird, 'N') = 'N'
    ORDER BY c.DTLANC DESC, c.VLRPARCELA ASC
");

$stmt->execute($todos ? [$empresa_id] : [$empresa_id, $inicio, $fim]);
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

            <div class="col-md-3">
                <a href="validar_cm.php?todos=1" class="btn btn-outline-primary w-100">
                    Todos nao validados
                </a>
            </div>

            <?php if ($todos): ?>
                <div class="col-md-4">
                    <div class="alert alert-warning py-2 mb-0">
                        Exibindo todos os clientes sem validacao.
                    </div>
                </div>
            <?php endif; ?>
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
