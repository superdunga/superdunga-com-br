<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$erro = '';
$sucesso = isset($_GET['alterada']);

if (empty($_SESSION['csrf_minha_senha'])) {
    $_SESSION['csrf_minha_senha'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $senhaAtual = (string)($_POST['senha_atual'] ?? '');
    $novaSenha = (string)($_POST['nova_senha'] ?? '');
    $confirmacao = (string)($_POST['confirmar_senha'] ?? '');

    if (!hash_equals((string)$_SESSION['csrf_minha_senha'], $token)) {
        $erro = 'A sessao do formulario expirou. Atualize a pagina e tente novamente.';
    } elseif ($senhaAtual === '' || $novaSenha === '' || $confirmacao === '') {
        $erro = 'Preencha todos os campos.';
    } elseif (strlen($novaSenha) < 8) {
        $erro = 'A nova senha deve ter no minimo 8 caracteres.';
    } elseif ($novaSenha !== $confirmacao) {
        $erro = 'A confirmacao nao corresponde a nova senha.';
    } else {
        $stmt = $pdo_master->prepare('SELECT senha FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$usuarioId]);
        $senhaHash = $stmt->fetchColumn();

        if (!$senhaHash || !password_verify($senhaAtual, $senhaHash)) {
            $erro = 'A senha atual esta incorreta.';
        } elseif (password_verify($novaSenha, $senhaHash)) {
            $erro = 'A nova senha deve ser diferente da senha atual.';
        } else {
            $stmt = $pdo_master->prepare("UPDATE usuarios
                SET senha = ?, updated_by = ?, updated_at = NOW()
                WHERE id = ?
                LIMIT 1");
            $stmt->execute([
                password_hash($novaSenha, PASSWORD_DEFAULT),
                $usuarioId,
                $usuarioId,
            ]);

            session_regenerate_id(true);
            $_SESSION['csrf_minha_senha'] = bin2hex(random_bytes(32));
            header('Location: minha_senha.php?alterada=1');
            exit;
        }
    }
}

require __DIR__ . '/../../layout/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header">
                <h1 class="h5 mb-0">Alterar minha senha</h1>
            </div>
            <div class="card-body">
                <?php if ($erro !== ''): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
                <?php endif; ?>

                <?php if ($sucesso): ?>
                    <div class="alert alert-success">Senha alterada com sucesso.</div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_minha_senha']) ?>">

                    <div class="mb-3">
                        <label for="senha_atual" class="form-label">Senha atual</label>
                        <input type="password" id="senha_atual" name="senha_atual" class="form-control" autocomplete="current-password" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label for="nova_senha" class="form-label">Nova senha</label>
                        <input type="password" id="nova_senha" name="nova_senha" class="form-control" minlength="8" autocomplete="new-password" required>
                        <div class="form-text">Use no minimo 8 caracteres.</div>
                    </div>

                    <div class="mb-4">
                        <label for="confirmar_senha" class="form-label">Confirmar nova senha</label>
                        <input type="password" id="confirmar_senha" name="confirmar_senha" class="form-control" minlength="8" autocomplete="new-password" required>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Alterar senha</button>
                        <a href="<?= htmlspecialchars(appBaseUrl() . '/index.php') ?>" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../layout/footer.php'; ?>
