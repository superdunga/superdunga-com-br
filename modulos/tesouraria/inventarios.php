<?php
require '../../config/auth.php';
require '../../config/conexao.php';

/* 🔒 CONTROLE DE ACESSO (MASTER OU GERENTE) */
if (!temNivel('GERENTE')) {
    require '../../layout/header.php';
    ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <h1 class="h4 fw-bold mb-2">Acesso negado</h1>
            <p class="text-muted mb-4">Seu usuario nao possui permissao para acessar o historico de inventarios.</p>
            <a href="menu_tesouraria.php" class="btn btn-primary">Voltar para Tesouraria</a>
        </div>
    </div>
    <?php
    require '../../layout/footer.php';
    exit;
}

$empresa_id = $_SESSION['empresa_id'];

/* =========================
   FILTROS
========================= */
$data_ini = $_GET['data_ini'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';
$status   = $_GET['status'] ?? '';

$where = "WHERE i.empresa_id = ?";
$params = [$empresa_id];

if (!empty($data_ini)) {
    $where .= " AND DATE(i.data_inventario) >= ?";
    $params[] = $data_ini;
}

if (!empty($data_fim)) {
    $where .= " AND DATE(i.data_inventario) <= ?";
    $params[] = $data_fim;
}

if ($status === 'OK' || $status === 'DIVERGENTE') {
    $where .= " AND i.status = ?";
    $params[] = $status;
}

/* =========================
   BUSCAR INVENTÁRIOS
========================= */
$sql = "
    SELECT i.*, u.nome AS usuario
    FROM tesouraria_inventarios i
    LEFT JOIN usuarios u ON u.id = i.usuario_id
    $where
    ORDER BY i.id DESC
";

$stmt = $pdo_master->prepare($sql);
$stmt->execute($params);
$inventarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   RELATÓRIO POR OPERADOR
========================= */
$sqlOperador = "
    SELECT 
        u.nome,
        COUNT(*) AS total_inventarios,
        SUM(CASE WHEN i.status = 'DIVERGENTE' THEN 1 ELSE 0 END) AS divergencias,
        SUM(i.diferenca) AS total_diferenca
    FROM tesouraria_inventarios i
    LEFT JOIN usuarios u ON u.id = i.usuario_id
    $where
    GROUP BY u.nome
";

$stmt = $pdo_master->prepare($sqlOperador);
$stmt->execute($params);
$relatorio = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   EXPORTAR EXCEL
========================= */
if (isset($_GET['exportar'])) {

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=inventarios.xls");

    echo "ID\tData\tUsuario\tSistema\tFisico\tDiferenca\tStatus\n";

    foreach ($inventarios as $i) {
        echo "{$i['id']}\t{$i['data_inventario']}\t{$i['usuario']}\t{$i['valor_sistema']}\t{$i['valor_fisico']}\t{$i['diferenca']}\t{$i['status']}\n";
    }

    exit;
}

require '../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-body">

        <h4>Histórico de Inventários</h4>

        <!-- FILTROS -->
        <form method="GET" class="row g-2 mt-3">

            <div class="col-md-2">
                <input type="date" name="data_ini" class="form-control" value="<?= $data_ini ?>">
            </div>

            <div class="col-md-2">
                <input type="date" name="data_fim" class="form-control" value="<?= $data_fim ?>">
            </div>

            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">Todos</option>
                    <option value="OK" <?= $status=='OK'?'selected':'' ?>>OK</option>
                    <option value="DIVERGENTE" <?= $status=='DIVERGENTE'?'selected':'' ?>>Divergente</option>
                </select>
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary w-100">Filtrar</button>
            </div>

            <div class="col-md-2">
                <a href="?exportar=1" class="btn btn-success w-100">Excel</a>
            </div>

        </form>

        <!-- TABELA -->
        <table class="table table-bordered table-sm mt-3">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Usuário</th>
                    <th>Sistema</th>
                    <th>Físico</th>
                    <th>Diferença</th>
                    <th>Status</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>

            <?php foreach ($inventarios as $i): ?>
                <tr>
                    <td><?= $i['id'] ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($i['data_inventario'])) ?></td>
                    <td><?= htmlspecialchars($i['usuario']) ?></td>

                    <td>R$ <?= number_format($i['valor_sistema'],2,',','.') ?></td>
                    <td>R$ <?= number_format($i['valor_fisico'],2,',','.') ?></td>

                    <td class="<?= $i['diferenca']!=0?'text-danger':'text-success' ?>">
                        R$ <?= number_format($i['diferenca'],2,',','.') ?>
                    </td>

                    <td>
                        <span class="badge bg-<?= $i['status']=='OK'?'success':'danger' ?>">
                            <?= $i['status'] ?>
                        </span>
                    </td>

                    <td>
                        <a href="inventario_resultado.php?id=<?= $i['id'] ?>" class="btn btn-sm btn-primary">
                            Ver
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>

            </tbody>
        </table>

        <!-- RELATÓRIO POR OPERADOR -->
        <h5 class="mt-4">Relatório por Operador</h5>

        <table class="table table-bordered table-sm">
            <thead class="table-light">
                <tr>
                    <th>Operador</th>
                    <th>Total Inventários</th>
                    <th>Divergências</th>
                    <th>Diferença Total</th>
                </tr>
            </thead>
            <tbody>

            <?php foreach ($relatorio as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['nome']) ?></td>
                    <td><?= $r['total_inventarios'] ?></td>
                    <td class="text-danger"><?= $r['divergencias'] ?></td>
                    <td>R$ <?= number_format($r['total_diferenca'],2,',','.') ?></td>
                </tr>
            <?php endforeach; ?>

            </tbody>
        </table>

    </div>
</div>

<?php require '../../layout/footer.php'; ?>
