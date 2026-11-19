<?php
require 'config/conexao.php';
session_start();

if (isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

$empresas = $pdo_master
    ->query("SELECT id, nome_fantasia FROM empresas WHERE status='ATIVA' ORDER BY nome_fantasia")
    ->fetchAll(PDO::FETCH_ASSOC);

$empresasPorId = [];
foreach ($empresas as $empresa) {
    $empresasPorId[(int)$empresa['id']] = $empresa;
}

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(24));
}

$pendenciaMaster = $_SESSION['login_master_pendente'] ?? null;
if (is_array($pendenciaMaster) && (time() - (int)($pendenciaMaster['criado_em'] ?? 0)) > 600) {
    unset($_SESSION['login_master_pendente']);
    $pendenciaMaster = null;
    $erro = 'A selecao da empresa expirou. Entre novamente.';
}

$empresa_id = (int)($_POST['empresa_id'] ?? 0);
$ultimaEmpresaId = (int)($_COOKIE['sd_master_ultima_empresa'] ?? 0);
if ($empresa_id <= 0 && isset($empresasPorId[$ultimaEmpresaId])) {
    $empresa_id = $ultimaEmpresaId;
}
$login = trim($_POST['login'] ?? (is_array($pendenciaMaster) ? (string)($pendenciaMaster['login'] ?? '') : ''));
$etapaMaster = is_array($pendenciaMaster);
$campoFoco = $etapaMaster ? 'empresa_busca' : 'login';

function concluirLogin(PDO $pdo, array $usuario, int $empresaId, array $empresasPorId): void
{
    if (!isset($empresasPorId[$empresaId])) {
        throw new RuntimeException('A empresa selecionada nao esta disponivel para acesso.');
    }

    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int)$usuario['id'];
    $_SESSION['usuario_nome'] = $usuario['nome'];
    $_SESSION['nivel'] = $usuario['nivel'];
    $_SESSION['empresa_id'] = $empresaId;
    $_SESSION['empresa_nome'] = $empresasPorId[$empresaId]['nome_fantasia'];
    unset($_SESSION['login_master_pendente']);

    $pdo->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?")
        ->execute([(int)$usuario['id']]);

    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfRecebido = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)$_SESSION['login_csrf'], $csrfRecebido)) {
        $erro = 'A pagina expirou. Atualize e tente novamente.';
    } else {
        $acao = (string)($_POST['acao'] ?? 'autenticar');

        if ($acao === 'cancelar_master') {
            unset($_SESSION['login_master_pendente']);
            $pendenciaMaster = null;
            $etapaMaster = false;
            $empresa_id = 0;
            $login = '';
            $campoFoco = 'login';
        } elseif ($acao === 'selecionar_empresa') {
            $pendenciaMaster = $_SESSION['login_master_pendente'] ?? null;
            if (!is_array($pendenciaMaster)) {
                $erro = 'A selecao da empresa expirou. Entre novamente.';
                $etapaMaster = false;
                $campoFoco = 'login';
            } elseif (!isset($empresasPorId[$empresa_id])) {
                $erro = 'Selecione uma empresa ativa para continuar.';
                $etapaMaster = true;
                $campoFoco = 'empresa_busca';
            } else {
                $stmt = $pdo_master->prepare("SELECT * FROM usuarios WHERE id = ? AND nivel = 'MASTER' AND status = 'ATIVO' LIMIT 1");
                $stmt->execute([(int)$pendenciaMaster['usuario_id']]);
                $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$usuario) {
                    unset($_SESSION['login_master_pendente']);
                    $erro = 'Nao foi possivel concluir o acesso. Entre novamente.';
                    $etapaMaster = false;
                    $campoFoco = 'login';
                } else {
                    setcookie('sd_master_ultima_empresa', (string)$empresa_id, time() + 31536000, '/', '', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
                    concluirLogin($pdo_master, $usuario, $empresa_id, $empresasPorId);
                }
            }
        } else {
            $senha = (string)($_POST['senha'] ?? '');
            if ($login === '' || $senha === '') {
                $erro = 'Informe login e senha para continuar.';
                $campoFoco = $login === '' ? 'login' : 'senha';
            } else {
                $stmt = $pdo_master->prepare("SELECT * FROM usuarios WHERE login = ? AND status = 'ATIVO' LIMIT 1");
                $stmt->execute([$login]);
                $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$usuario || !password_verify($senha, $usuario['senha'])) {
                    $erro = 'Login ou senha invalidos.';
                    $campoFoco = 'login';
                } elseif ($usuario['nivel'] === 'MASTER') {
                    session_regenerate_id(true);
                    $_SESSION['login_master_pendente'] = [
                        'usuario_id' => (int)$usuario['id'],
                        'nome' => (string)$usuario['nome'],
                        'login' => (string)$usuario['login'],
                        'criado_em' => time(),
                    ];
                    $pendenciaMaster = $_SESSION['login_master_pendente'];
                    $etapaMaster = true;
                    $campoFoco = 'empresa_busca';
                    $login = (string)$usuario['login'];
                } else {
                    concluirLogin($pdo_master, $usuario, (int)$usuario['empresa_id'], $empresasPorId);
                }
            }
        }
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
            overflow-x: hidden;
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

        .master-identity {
            border: 1px solid var(--sd-border);
            background: var(--sd-page);
            border-radius: .5rem;
        }

        .last-company-note {
            color: var(--sd-primary-dark);
            font-weight: 600;
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

        @media (max-width: 991.98px) {
            .brand-panel {
                text-align: center;
            }

            .brand-panel .d-flex {
                justify-content: center;
            }
        }

        @media (max-width: 575.98px) {
            .login-shell {
                align-items: flex-start !important;
                padding-top: 4.25rem !important;
            }

            .login-back {
                right: 1rem;
                width: calc(100% - 2rem);
            }

            .brand-mark {
                width: 46px;
                height: 46px;
                margin-bottom: 1rem !important;
            }

            .brand-panel h1 {
                font-size: 1.9rem;
            }

            .brand-panel .lead {
                font-size: 1rem;
            }

            .login-card .card-body {
                padding: 1.25rem !important;
            }

            .form-control,
            .form-select,
            .btn {
                min-height: 44px;
            }
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
                        <?php if ($etapaMaster): ?>
                            <h2 class="h4 fw-bold mb-1">Escolher empresa</h2>
                            <p class="text-muted mb-0">Credenciais confirmadas. Selecione onde deseja trabalhar.</p>
                        <?php else: ?>
                            <h2 class="h4 fw-bold mb-1">Entrar no sistema</h2>
                            <p class="text-muted mb-0">Informe login e senha para continuar.</p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($erro)): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($erro) ?></div>
                    <?php endif; ?>

                    <?php if ($etapaMaster): ?>
                        <div class="master-identity p-3 mb-3">
                            <span class="text-muted small d-block">Usuario MASTER</span>
                            <strong><?= htmlspecialchars((string)($pendenciaMaster['nome'] ?? $login)) ?></strong>
                        </div>

                        <form method="POST" id="form-selecao-empresa">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf']) ?>">
                            <input type="hidden" name="acao" value="selecionar_empresa">

                            <div class="mb-3">
                                <label for="empresa_busca" class="form-label">Pesquisar empresa</label>
                                <input
                                    type="search"
                                    id="empresa_busca"
                                    class="form-control"
                                    placeholder="Digite o nome da empresa"
                                    autocomplete="off"
                                >
                            </div>

                            <div class="mb-4">
                                <label for="empresa_id" class="form-label">Empresa</label>
                                <select id="empresa_id" name="empresa_id" class="form-select" required>
                                    <option value="">Selecione a empresa</option>
                                    <?php foreach ($empresas as $e): ?>
                                        <?php $empresaEhUltima = (int)$e['id'] === $ultimaEmpresaId; ?>
                                        <option
                                            value="<?= (int)$e['id'] ?>"
                                            data-nome="<?= htmlspecialchars(mb_strtolower($e['nome_fantasia'], 'UTF-8')) ?>"
                                            <?= $empresa_id === (int)$e['id'] ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($e['nome_fantasia']) ?><?= $empresaEhUltima ? ' - ultimo acesso' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($empresasPorId[$ultimaEmpresaId])): ?>
                                    <div class="form-text last-company-note">
                                        Ultima empresa acessada: <?= htmlspecialchars($empresasPorId[$ultimaEmpresaId]['nome_fantasia']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="btn btn-warning fw-semibold w-100 py-2">Entrar nesta empresa</button>
                        </form>

                        <form method="POST" class="mt-2">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf']) ?>">
                            <input type="hidden" name="acao" value="cancelar_master">
                            <button type="submit" class="btn btn-link text-secondary w-100">Trocar usuario</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" id="form-login">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf']) ?>">
                            <input type="hidden" name="acao" value="autenticar">

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
                                <div class="input-group">
                                    <input
                                        type="password"
                                        id="senha"
                                        name="senha"
                                        class="form-control"
                                        autocomplete="current-password"
                                        required
                                    >
                                    <button type="button" class="btn btn-outline-secondary" id="alternar-senha" aria-controls="senha" aria-pressed="false">
                                        Mostrar
                                    </button>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-warning fw-semibold w-100 py-2">Entrar</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var campoFoco = document.getElementById(<?= json_encode($campoFoco) ?>);
    if (campoFoco) {
        campoFoco.focus();
        if (campoFoco.id === 'login' && campoFoco.value) {
            campoFoco.select();
        }
    }

    var senha = document.getElementById('senha');
    var alternarSenha = document.getElementById('alternar-senha');
    if (senha && alternarSenha) {
        alternarSenha.addEventListener('click', function () {
            var mostrar = senha.type === 'password';
            senha.type = mostrar ? 'text' : 'password';
            alternarSenha.textContent = mostrar ? 'Ocultar' : 'Mostrar';
            alternarSenha.setAttribute('aria-pressed', mostrar ? 'true' : 'false');
            senha.focus();
        });
    }

    var busca = document.getElementById('empresa_busca');
    var empresas = document.getElementById('empresa_id');
    if (busca && empresas) {
        busca.addEventListener('input', function () {
            var termo = busca.value.toLocaleLowerCase('pt-BR').trim();
            Array.prototype.forEach.call(empresas.options, function (opcao, indice) {
                if (indice === 0) {
                    return;
                }
                opcao.hidden = termo !== '' && opcao.getAttribute('data-nome').indexOf(termo) === -1;
            });
        });
    }
}());
</script>
</body>
</html>
