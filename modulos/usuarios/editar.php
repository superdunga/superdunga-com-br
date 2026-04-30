<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';

/* Apenas ADMIN ou superior acessa */
exigirNivel('ADMIN');

$erro = '';
$sucesso = '';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die("Usuário não informado.");
}

/* Buscar usuário + empresa */
$stmt = $pdo_master->prepare("
    SELECT u.*, e.nome_fantasia
    FROM usuarios u
    JOIN empresas e ON e.id = u.empresa_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    die("Usuário não encontrado.");
}

/* Regras de permissão */
if ($_SESSION['nivel'] !== 'MASTER') {

    /* ADMIN só pode editar usuários da própria empresa */
    if ((int)$usuario['empresa_id'] !== (int)$_SESSION['empresa_id']) {
        die("Acesso negado.");
    }

    /* ADMIN não pode editar outro ADMIN */
    if ($usuario['nivel'] === 'ADMIN') {
        die("Acesso negado.");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nome   = trim($_POST['nome'] ?? '');
    $login  = trim($_POST['login'] ?? '');
    $status = $_POST['status'] ?? '';
    $senha  = $_POST['senha'] ?? '';

    if ($nome === '' || $login === '' || ($status !== 'ATIVO' && $status !== 'INATIVO')) {
        $erro = "Preencha nome, login e status corretamente.";
    } else {

        /* Impedir login duplicado na mesma empresa */
        $chk = $pdo_master->prepare("
            SELECT id 
            FROM usuarios 
            WHERE empresa_id = ? AND login = ? AND id <> ?
            LIMIT 1
        ");
        $chk->execute([$usuario['empresa_id'], $login, $usuario['id']]);

        if ($chk->fetch()) {
            $erro = "Já existe um usuário com esse login nessa empresa.";
        } else {

            if ($senha !== '') {

                $senha_hash = password_hash($senha, PASSWORD_DEFAULT);

                $upd = $pdo_master->prepare("
                    UPDATE usuarios
                    SET nome = ?, 
                        login = ?, 
                        status = ?, 
                        senha = ?, 
                        updated_by = ?, 
                        updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");

                $upd->execute([
                    $nome,
                    $login,
                    $status,
                    $senha_hash,
                    $_SESSION['usuario_id'],
                    $usuario['id']
                ]);

            } else {

                $upd = $pdo_master->prepare("
                    UPDATE usuarios
                    SET nome = ?, 
                        login = ?, 
                        status = ?, 
                        updated_by = ?, 
                        updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ");

                $upd->execute([
                    $nome,
                    $login,
                    $status,
                    $_SESSION['usuario_id'],
                    $usuario['id']
                ]);
            }

            $sucesso = "Usuário atualizado com sucesso.";

            /* Recarrega dados atualizados */
            $stmt->execute([$id]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}

require __DIR__ . '/../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Editar Usuário</h5>
        <a href="listar.php" class="btn btn-secondary btn-sm">← Voltar</a>
    </div>

    <div class="card-body">

        <?php if ($erro): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="alert alert-success"><?= htmlspecialchars($sucesso) ?></div>
        <?php endif; ?>

        <div class="mb-3">
            <span class="badge bg-dark">Empresa:</span>
            <strong><?= htmlspecialchars($usuario['nome_fantasia']) ?></strong>
        </div>

        <form method="POST">

            <div class="mb-3">
                <label class="form-label">Nome</label>
                <input type="text" name="nome" class="form-control"
                       value="<?= htmlspecialchars($usuario['nome']) ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Login</label>
                <input type="text" name="login" class="form-control"
                       value="<?= htmlspecialchars($usuario['login']) ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" required>
                    <option value="ATIVO" <?= $usuario['status'] === 'ATIVO' ? 'selected' : '' ?>>ATIVO</option>
                    <option value="INATIVO" <?= $usuario['status'] === 'INATIVO' ? 'selected' : '' ?>>INATIVO</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Nova Senha (opcional)</label>
                <input type="password" name="senha" class="form-control" placeholder="Deixe em branco para não alterar">
            </div>

            <div class="mb-3">
                <label class="form-label">Nível (somente leitura)</label>
                <input type="text" class="form-control"
                       value="<?= htmlspecialchars($usuario['nivel']) ?>" disabled>
            </div>

            <button type="submit" class="btn btn-success">Salvar Alterações</button>
            <a href="listar.php" class="btn btn-secondary">Cancelar</a>

        </form>

    </div>
</div>

<?php require __DIR__ . '/../../layout/footer.php'; ?>