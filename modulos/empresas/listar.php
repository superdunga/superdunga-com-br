<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';

/* BLOQUEIO REAL DE ACESSO */
if ($_SESSION['nivel'] !== 'MASTER') {
    header("Location: ../../index.php");
    exit;
}

require __DIR__ . '/../../layout/header.php';

/* Filtro opcional por status */
$statusFiltro = $_GET['status'] ?? '';

$sql = "SELECT * FROM empresas";
$params = [];

if ($statusFiltro === 'ATIVA' || $statusFiltro === 'INATIVA') {
    $sql .= " WHERE status = ?";
    $params[] = $statusFiltro;
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo_master->prepare($sql);
$stmt->execute($params);
$empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Empresas Cadastradas</h5>

        <div>
            <a href="../../index.php" class="btn btn-secondary btn-sm">
                ← Voltar ao Painel
            </a>

            <?php if ($_SESSION['nivel'] === 'MASTER'): ?>
                <a href="cadastrar.php" class="btn btn-primary btn-sm">
                    Nova Empresa
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body">

        <!-- Filtro simples -->
        <form method="GET" class="mb-3">
            <select name="status" class="form-select form-select-sm w-auto d-inline">
                <option value="">Todas</option>
                <option value="ATIVA" <?= $statusFiltro === 'ATIVA' ? 'selected' : '' ?>>Ativas</option>
                <option value="INATIVA" <?= $statusFiltro === 'INATIVA' ? 'selected' : '' ?>>Inativas</option>
            </select>
            <button type="submit" class="btn btn-sm btn-secondary">Filtrar</button>
        </form>

        <?php if (count($empresas) > 0): ?>

            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Nome Fantasia</th>
                            <th>CNPJ</th>
                            <th>Status</th>
                            <th width="100">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($empresas as $empresa): ?>
                            <tr>
                                <td><?= $empresa['id'] ?></td>
                                <td><?= htmlspecialchars($empresa['nome_fantasia']) ?></td>
                                <td><?= htmlspecialchars($empresa['cnpj']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $empresa['status'] === 'ATIVA' ? 'success' : 'danger' ?>">
                                        <?= $empresa['status'] ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="editar.php?id=<?= $empresa['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        Editar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <div class="alert alert-info">
                Nenhuma empresa cadastrada.
            </div>

        <?php endif; ?>

    </div>
</div>

<?php require __DIR__ . '/../../layout/footer.php'; ?>