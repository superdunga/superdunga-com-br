<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';

/* Apenas MASTER pode acessar */
exigirNivel('MASTER');

$erro = '';
$sucesso = '';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die("Empresa não informada.");
}

/* Buscar empresa */
$stmt = $pdo_master->prepare("
    SELECT * FROM empresas
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$empresa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$empresa) {
    die("Empresa não encontrada.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $razao_social  = trim($_POST['razao_social'] ?? '');
    $nome_fantasia = trim($_POST['nome_fantasia'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $telefone      = trim($_POST['telefone'] ?? '');
    $status        = $_POST['status'] ?? '';

    if ($razao_social === '' || $nome_fantasia === '' || 
        ($status !== 'ATIVA' && $status !== 'INATIVA')) {

        $erro = "Preencha os campos obrigatórios corretamente.";

    } else {

        /* UPDATE COM AUDITORIA */
        $upd = $pdo_master->prepare("
            UPDATE empresas
            SET razao_social = ?,
                nome_fantasia = ?,
                email = ?,
                telefone = ?,
                status = ?,
                updated_by = ?,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");

        $upd->execute([
            $razao_social,
            $nome_fantasia,
            $email,
            $telefone,
            $status,
            $_SESSION['usuario_id'],
            $empresa['id']
        ]);

        $sucesso = "Empresa atualizada com sucesso.";

        /* Recarrega dados */
        $stmt->execute([$id]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

require __DIR__ . '/../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Editar Empresa</h5>
        <a href="listar.php" class="btn btn-secondary btn-sm">← Voltar</a>
    </div>

    <div class="card-body">

        <?php if ($erro): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="alert alert-success"><?= htmlspecialchars($sucesso) ?></div>
        <?php endif; ?>

        <form method="POST">

            <div class="mb-3">
                <label class="form-label">Razão Social *</label>
                <input type="text" name="razao_social" class="form-control"
                       value="<?= htmlspecialchars($empresa['razao_social']) ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Nome Fantasia *</label>
                <input type="text" name="nome_fantasia" class="form-control"
                       value="<?= htmlspecialchars($empresa['nome_fantasia']) ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">CNPJ</label>
                <input type="text" class="form-control"
                       value="<?= htmlspecialchars($empresa['cnpj']) ?>" disabled>
            </div>

            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($empresa['email']) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Telefone</label>
                <input type="text" name="telefone" class="form-control"
                       value="<?= htmlspecialchars($empresa['telefone']) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" required>
                    <option value="ATIVA" <?= $empresa['status'] === 'ATIVA' ? 'selected' : '' ?>>ATIVA</option>
                    <option value="INATIVA" <?= $empresa['status'] === 'INATIVA' ? 'selected' : '' ?>>INATIVA</option>
                </select>
            </div>

            <button type="submit" class="btn btn-success">Salvar Alterações</button>
            <a href="listar.php" class="btn btn-secondary">Cancelar</a>

        </form>

    </div>
</div>

<?php require __DIR__ . '/../../layout/footer.php'; ?>