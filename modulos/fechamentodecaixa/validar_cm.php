<?php
require '../../config/auth.php';
require '../../config/conexao.php';

/* =========================
   DATA
========================= */
$data = $_GET['data'] ?? date('Y-m-d');
$todos = ($_GET['todos'] ?? '') === '1';
$empresa_id = (int)$_SESSION['empresa_id'];
$usuarioMaster = strtoupper((string)($_SESSION['nivel'] ?? '')) === 'MASTER';
$filtroValidacao = $usuarioMaster && in_array(($_GET['validacao'] ?? ''), ['validados', 'todos'], true)
    ? (string)$_GET['validacao']
    : 'nao_validados';
$filtroCliente = $usuarioMaster ? trim((string)($_GET['cliente'] ?? '')) : '';

$inicio = date('Y-m-d 07:00:00', strtotime($data));
$fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));

/* =========================
   SALVAR VALIDAÇÃO
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $estados = is_array($_POST['estado'] ?? null) ? $_POST['estado'] : [];
    $idsExibidos = [];
    $ids = [];
    foreach ($estados as $id => $estado) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }
        $idsExibidos[] = $id;
        if ($estado === 'S') {
            $ids[] = $id;
        }
    }
    $idsExibidos = array_values(array_unique($idsExibidos));
    $ids = array_values(array_unique($ids));
    $idsDesmarcados = array_values(array_diff($idsExibidos, $ids));
    $quantidadeValidada = 0;
    $quantidadeDesmarcada = 0;
    $usuario = (int)($_SESSION['usuario_id'] ?? 0);

    if (!empty($idsExibidos)) {
        $pdo_master->beginTransaction();
        try {
            foreach (array_chunk($ids, 100) as $lote) {
                $in = implode(',', array_fill(0, count($lote), '?'));
                $stmt = $pdo_master->prepare("
                    UPDATE armazem_cr001
                    SET validado = 'S',
                        data_validacao = NOW(),
                        usuario_validacao = ?
                    WHERE CRCONTADOR IN ($in)
                      AND EMPRESA = ?
                      AND COALESCE(validado, 'N') <> 'S'
                      AND COALESCE(excluido_firebird, 'N') = 'N'
                ");
                $stmt->execute(array_merge([$usuario], $lote, [$empresa_id]));
                $quantidadeValidada += $stmt->rowCount();
            }

            foreach (array_chunk($idsDesmarcados, 100) as $lote) {
                $in = implode(',', array_fill(0, count($lote), '?'));
                $stmt = $pdo_master->prepare("
                    UPDATE armazem_cr001
                    SET validado = 'N',
                        data_validacao = NULL,
                        usuario_validacao = NULL
                    WHERE CRCONTADOR IN ($in)
                      AND EMPRESA = ?
                      AND validado = 'S'
                      AND COALESCE(excluido_firebird, 'N') = 'N'
                ");
                $stmt->execute(array_merge($lote, [$empresa_id]));
                $quantidadeDesmarcada += $stmt->rowCount();
            }
            $pdo_master->commit();
        } catch (Throwable $e) {
            $pdo_master->rollBack();
            throw $e;
        }
    }

    $parametrosRedirect = $todos ? ['todos' => 1] : ['data' => $data];
    if ($usuarioMaster) {
        $parametrosRedirect['validacao'] = $filtroValidacao;
        if ($filtroCliente !== '') {
            $parametrosRedirect['cliente'] = $filtroCliente;
        }
    }
    $parametrosRedirect['resultado'] = empty($idsExibidos) ? 'sem_registros' : 'salvo';
    $parametrosRedirect['validados'] = $quantidadeValidada;
    $parametrosRedirect['desmarcados'] = $quantidadeDesmarcada;
    $redirect = 'validar_cm.php?' . http_build_query($parametrosRedirect);
    header("Location: " . $redirect);
    exit;
}

/* =========================
   BUSCAR REGISTROS (CM = 9)
========================= */
$whereData = $todos ? "" : "AND c.DTLANC BETWEEN ? AND ?";
$whereValidacao = $filtroValidacao === 'validados'
    ? "AND c.validado = 'S'"
    : ($filtroValidacao === 'todos' ? "" : "AND (c.validado IS NULL OR c.validado = 'N')");
$whereCliente = $filtroCliente !== ''
    ? "AND (CAST(c.CLICONTADOR AS CHAR) LIKE ? OR cli.NOME LIKE ? OR cli.APELIDO LIKE ?)"
    : "";
$stmt = $pdo_master->prepare("
    SELECT 
        c.CRCONTADOR,
        c.NUMDOCORIGEM AS VENDACONTADOR,
        c.TIPODOCORIGEM,
        c.DTLANC,
        c.VLRPARCELA,
        c.CLICONTADOR,
        c.STATUS,
        c.validado,
        cli.NOME
    FROM armazem_cr001 c
    LEFT JOIN armazem_cr002 cli
        ON cli.CLICONTADOR = c.CLICONTADOR
       AND cli.EMPRESA = c.EMPRESA
    WHERE c.CMCONTADOR = 9
      AND c.EMPRESA = ?
      $whereData
      $whereValidacao
      $whereCliente
      AND COALESCE(c.excluido_firebird, 'N') = 'N'
    ORDER BY c.DTLANC DESC, c.VLRPARCELA ASC
");

$parametrosConsulta = $todos ? [$empresa_id] : [$empresa_id, $inicio, $fim];
if ($filtroCliente !== '') {
    $termoCliente = '%' . $filtroCliente . '%';
    array_push($parametrosConsulta, $termoCliente, $termoCliente, $termoCliente);
}
$stmt->execute($parametrosConsulta);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
require '../../layout/header.php';
?>

<style>
    .checkbox-lg {
        width: 22px;
        height: 22px;
        transform: scale(1.3);
        cursor: pointer;
    }

    .cliente-destaque > * {
        --bs-table-bg: #fff3cd;
        --bs-table-accent-bg: #fff3cd;
        background-color: #fff3cd !important;
        box-shadow: inset 0 0 0 9999px #fff3cd !important;
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

        <?php if (($_GET['resultado'] ?? '') === 'sem_registros'): ?>
            <div class="alert alert-warning">Nenhum registro foi exibido para salvar.</div>
        <?php elseif (($_GET['resultado'] ?? '') === 'salvo'): ?>
            <div class="alert alert-success">
                Validacoes salvas: <?= (int)($_GET['validados'] ?? 0) ?> marcado(s) e
                <?= (int)($_GET['desmarcados'] ?? 0) ?> desmarcado(s).
            </div>
        <?php endif; ?>

        <form method="GET" class="row mb-3">
            <div class="col-md-3">
                <input type="date" name="data" value="<?= htmlspecialchars($data) ?>" class="form-control">
            </div>

            <?php if ($usuarioMaster): ?>
                <div class="col-md-3">
                    <select name="validacao" class="form-select">
                        <option value="nao_validados" <?= $filtroValidacao === 'nao_validados' ? 'selected' : '' ?>>Nao validados</option>
                        <option value="validados" <?= $filtroValidacao === 'validados' ? 'selected' : '' ?>>Validados</option>
                        <option value="todos" <?= $filtroValidacao === 'todos' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <input type="text" name="cliente" value="<?= htmlspecialchars($filtroCliente) ?>" class="form-control" placeholder="Codigo ou nome do cliente">
                </div>
            <?php endif; ?>

            <div class="col-md-2">
                <button class="btn btn-primary w-100">Filtrar</button>
            </div>

            <div class="col-md-3">
                <a href="validar_cm.php?<?= htmlspecialchars(http_build_query(array_filter(['todos' => 1, 'validacao' => $usuarioMaster ? $filtroValidacao : null, 'cliente' => $usuarioMaster && $filtroCliente !== '' ? $filtroCliente : null], static function ($valor) { return $valor !== null; }))) ?>" class="btn btn-outline-primary w-100">
                    <?= $usuarioMaster ? 'Todos os periodos' : 'Todos nao validados' ?>
                </a>
            </div>

            <?php if ($todos): ?>
                <div class="col-md-4">
                    <div class="alert alert-warning py-2 mb-0">
                        Exibindo todos os periodos com o filtro selecionado.
                    </div>
                </div>
            <?php endif; ?>
        </form>

        <form method="POST">

            <table class="table table-bordered table-hover text-center">

                <thead class="table-dark">
                    <tr>
                        <th>✔</th>
                        <th>CR</th>
                        <th>VENDA</th>
                        <th>DOC</th>
                        <th>Data</th>
                        <th>Valor</th>
                        <th>Cliente</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($registros)): ?>
                        <tr>
                            <td colspan="7" class="text-muted">
                                Nenhum registro pendente
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($registros as $r): ?>
                            <?php $crBaixado = ($r['STATUS'] ?? '') === 'QT'; ?>
                            <tr class="<?= (int)$r['CLICONTADOR'] === 1 ? 'cliente-destaque' : '' ?>">
                                <td class="text-center">
                                    <?php if ($crBaixado): ?>
                                        <input
                                            type="checkbox"
                                            class="checkbox-lg"
                                            <?= ($r['validado'] ?? 'N') === 'S' ? 'checked' : '' ?>
                                            disabled
                                            title="CR baixado"
                                        >
                                    <?php else: ?>
                                        <input type="hidden" name="estado[<?= $r['CRCONTADOR'] ?>]" value="N">
                                        <input
                                            type="checkbox"
                                            class="checkbox-lg"
                                            name="estado[<?= $r['CRCONTADOR'] ?>]"
                                            value="S"
                                            <?= ($r['validado'] ?? 'N') === 'S' ? 'checked' : '' ?>
                                        >
                                    <?php endif; ?>
                                </td>

                                <td><?= $r['CRCONTADOR'] ?></td>

                                <td><?= htmlspecialchars($r['VENDACONTADOR'] ?? '-') ?></td>

                                <td><?= htmlspecialchars($r['TIPODOCORIGEM'] ?? '-') ?></td>

                                <td><?= date('d/m/Y H:i', strtotime($r['DTLANC'])) ?></td>

                                <td>R$ <?= number_format($r['VLRPARCELA'], 2, ',', '.') ?></td>

                                <td><?= htmlspecialchars($r['NOME'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

            <?php if (!empty($registros)): ?>
                <button type="submit" class="btn btn-success">
                    ✔ Salvar validacoes
                </button>
            <?php endif; ?>

        </form>

    </div>

</div>

<?php require '../../layout/footer.php'; ?>
