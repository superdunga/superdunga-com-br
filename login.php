<?php
require 'config/conexao.php';
session_start();

if (isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

/* Carrega empresas ativas para o select */
$empresas = $pdo_master
    ->query("SELECT id, nome_fantasia FROM empresas WHERE status='ATIVA' ORDER BY nome_fantasia")
    ->fetchAll(PDO::FETCH_ASSOC);

$empresa_id = (int)($_POST['empresa_id'] ?? 0);
$login = trim($_POST['login'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $senha = $_POST['senha'] ?? '';

    if ($empresa_id > 0 && $login !== '' && $senha !== '') {

        // Alteracao: remove filtro por empresa
        $stmt = $pdo_master->prepare("
            SELECT * FROM usuarios
            WHERE login = ?
              AND status = 'ATIVO'
            LIMIT 1
        ");

        $stmt->execute([$login]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario && password_verify($senha, $usuario['senha'])) {

            // Valida empresa para usuarios nao MASTER
            if ($usuario['nivel'] !== 'MASTER' && $usuario['empresa_id'] != $empresa_id) {

                $erro = "Usuario nao pertence a esta empresa.";

            } else {

                // Sessao
                $_SESSION['usuario_id']   = $usuario['id'];
                $_SESSION['usuario_nome'] = $usuario['nome'];
                $_SESSION['nivel']        = $usuario['nivel'];

                // MASTER usa empresa selecionada
                if ($usuario['nivel'] === 'MASTER') {
                    $_SESSION['empresa_id'] = $empresa_id;
                } else {
                    $_SESSION['empresa_id'] = $usuario['empresa_id'];
                }

                // Atualiza ultimo login
                $pdo_master->prepare("
                    UPDATE usuarios
                    SET ultimo_login = NOW()
                    WHERE id = ?
                ")->execute([$usuario['id']]);

                header("Location: index.php");
                exit;
            }

        } else {
            $erro = "Empresa, login ou senha invalidos.";
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
    <style>
        :root {
            --sd-primary: #164194;
            --sd-primary-dark: #0f2d68;
            --sd-accent: #f0b429;
            --sd-page: #eef3f9;
            --sd-border: #d9e2ef;
        }

        body {
            min-height: 100vh;
            background:
                linear-gradient(135deg, rgba(15, 45, 104, .94), rgba(22, 65, 148, .82)),
                var(--sd-page);
        }

        .login-shell {
            min-height: 100vh;
        }

        .brand-panel {
            color: #fff;
        }

        .brand-mark {
            width: 54px;
            height: 54px;
            border-radius: .5rem;
            background: var(--sd-accent);
            color: #172033;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 18px 45px rgba(0, 0, 0, .18);
        }

        .login-card {
            border: 1px solid var(--sd-border);
            border-radius: .5rem;
        }

        .form-control,
        .form-select,
        .btn {
            border-radius: .5rem;
        }

        .login-back {
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 10;
            border-color: rgba(255, 255, 255, .45);
            color: #fff;
        }

        .login-back:hover,
        .login-back:focus {
            background: #fff;
            border-color: #fff;
            color: var(--sd-primary-dark);
        }
    </style>
</head>
<body>

<button
    type="button"
    class="btn btn-sm btn-outline-light login-back"
    onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href='index.php'; }"
>
    &larr; Voltar
</button>

<main class="container login-shell d-flex align-items-center py-4">
    <div class="row justify-content-center align-items-center g-4 g-lg-5 w-100">
        <div class="col-lg-6 brand-panel">
            <div class="brand-mark mb-4">SD</div>
            <h1 class="display-6 fw-bold mb-3">SuperDunga</h1>
            <p class="lead mb-4">
                Acesse o sistema financeiro com a empresa correta para continuar suas rotinas.
            </p>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge rounded-pill text-bg-light px-3 py-2">Tesouraria</span>
                <span class="badge rounded-pill text-bg-light px-3 py-2">Fechamento</span>
                <span class="badge rounded-pill text-bg-light px-3 py-2">Auditoria</span>
            </div>
        </div>

        <div class="col-sm-10 col-md-8 col-lg-5">
            <div class="card login-card shadow-lg">
                <div class="card-body p-4 p-lg-5">
                    <div class="mb-4">
                        <span class="badge text-bg-warning mb-3">Acesso restrito</span>
                        <h2 class="h4 fw-bold mb-1">Entrar no sistema</h2>
                        <p class="text-muted mb-0">Informe empresa, login e senha.</p>
                    </div>

                    <?php if (!empty($erro)): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($erro) ?></div>
                    <?php endif; ?>

                    <form method="POST" class="needs-validation" novalidate>
                        <div class="mb-3">
                            <label for="empresa_id" class="form-label">Empresa</label>
                            <select id="empresa_id" name="empresa_id" class="form-select" required>
                                <option value="">Selecione a empresa</option>
                                <?php foreach ($empresas as $e): ?>
                                    <option value="<?= $e['id'] ?>" <?= $empresa_id === (int)$e['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($e['nome_fantasia']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="login" class="form-label">Login</label>
                            <input
                                type="text"
                                id="login"
                                name="login"
                                class="form-control"
                                value="<?= htmlspecialchars($login) ?>"
                                autocomplete="username"
                                required
                            >
                        </div>

                        <div class="mb-4">
                            <label for="senha" class="form-label">Senha</label>
                            <input
                                type="password"
                                id="senha"
                                name="senha"
                                class="form-control"
                                autocomplete="current-password"
                                required
                            >
                        </div>

                        <button type="submit" class="btn btn-warning fw-semibold w-100 py-2">Entrar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
