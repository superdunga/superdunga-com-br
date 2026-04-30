<?php
require 'config/conexao.php';
session_start();

/* Carrega empresas ativas para o select */
$empresas = $pdo_master
    ->query("SELECT id, nome_fantasia FROM empresas WHERE status='ATIVA' ORDER BY nome_fantasia")
    ->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $empresa_id = (int)($_POST['empresa_id'] ?? 0);
    $login = trim($_POST['login'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if ($empresa_id > 0 && $login !== '' && $senha !== '') {

        // 🔥 ALTERAÇÃO: remove filtro por empresa
        $stmt = $pdo_master->prepare("
            SELECT * FROM usuarios 
            WHERE login = ? 
              AND status = 'ATIVO'
            LIMIT 1
        ");

        $stmt->execute([$login]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario && password_verify($senha, $usuario['senha'])) {

            // 🔒 VALIDA EMPRESA PARA USUÁRIOS NÃO MASTER
            if ($usuario['nivel'] !== 'MASTER' && $usuario['empresa_id'] != $empresa_id) {

                $erro = "Usuário não pertence a esta empresa.";

            } else {

                // 🔐 Sessão (mantido padrão)
                $_SESSION['usuario_id']   = $usuario['id'];
                $_SESSION['usuario_nome'] = $usuario['nome'];
                $_SESSION['nivel']        = $usuario['nivel'];

                // 👑 MASTER usa empresa selecionada
                if ($usuario['nivel'] === 'MASTER') {
                    $_SESSION['empresa_id'] = $empresa_id;
                } else {
                    $_SESSION['empresa_id'] = $usuario['empresa_id'];
                }

                // 🕒 Atualiza último login
                $pdo_master->prepare("
                    UPDATE usuarios 
                    SET ultimo_login = NOW() 
                    WHERE id = ?
                ")->execute([$usuario['id']]);

                header("Location: index.php");
                exit;
            }

        } else {
            $erro = "Empresa, login ou senha inválidos.";
        }

    } else {
        $erro = "Preencha empresa, login e senha.";
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Login - SuperDunga</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container d-flex justify-content-center align-items-center" style="height:100vh;">
    <div class="card shadow p-4" style="width:350px;">
        <h4 class="text-center mb-3">SuperDunga</h4>

        <?php if (!empty($erro)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <form method="POST">

            <div class="mb-3">
                <select name="empresa_id" class="form-select" required>
                    <option value="">Selecione a empresa</option>
                    <?php foreach ($empresas as $e): ?>
                        <option value="<?= $e['id'] ?>">
                            <?= htmlspecialchars($e['nome_fantasia']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <input type="text" name="login" class="form-control" placeholder="Login" required>
            </div>

            <div class="mb-3">
                <input type="password" name="senha" class="form-control" placeholder="Senha" required>
            </div>

            <button type="submit" class="btn btn-dark w-100">Entrar</button>
        </form>
    </div>
</div>

</body>
</html>