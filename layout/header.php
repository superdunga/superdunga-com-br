<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$modulosPos = strpos($scriptDir, '/modulos');
$appBaseUrl = $modulosPos !== false ? substr($scriptDir, 0, $modulosPos) : $scriptDir;
$appBaseUrl = rtrim($appBaseUrl, '/');
$homeUrl = ($appBaseUrl ?: '') . '/index.php';
$logoutUrl = ($appBaseUrl ?: '') . '/logout.php';
$tesourariaUrl = ($appBaseUrl ?: '') . '/modulos/tesouraria/menu_tesouraria.php';
$fechamentoUrl = ($appBaseUrl ?: '') . '/modulos/fechamentodecaixa/menu_fechamento.php';
$usuariosUrl = ($appBaseUrl ?: '') . '/modulos/usuarios/listar.php';
$empresasUrl = ($appBaseUrl ?: '') . '/modulos/empresas/listar.php';
$usuarioNome = $_SESSION['usuario_nome'] ?? 'Usuario';
$nivelUsuario = $_SESSION['nivel'] ?? '';
$homeUrlJson = htmlspecialchars(json_encode($homeUrl), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>SuperDunga - Sistema Financeiro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --sd-primary: #164194;
            --sd-primary-dark: #0f2d68;
            --sd-accent: #f0b429;
            --sd-page: #f3f6fb;
            --sd-border: #d9e2ef;
        }

        body {
            background: var(--sd-page);
            color: #1f2937;
        }

        .navbar-brand {
            font-weight: 700;
            letter-spacing: 0;
        }

        .app-topbar {
            background: linear-gradient(90deg, var(--sd-primary-dark), var(--sd-primary));
            box-shadow: 0 10px 30px rgba(15, 45, 104, .18);
        }

        .app-topbar .nav-link {
            color: rgba(255, 255, 255, .82);
            font-weight: 500;
        }

        .app-topbar .nav-link:hover,
        .app-topbar .nav-link:focus {
            color: #fff;
        }

        .btn-back-top {
            border-color: rgba(255, 255, 255, .35);
            color: #fff;
        }

        .btn-back-top:hover,
        .btn-back-top:focus {
            background: #fff;
            border-color: #fff;
            color: var(--sd-primary-dark);
        }

        .user-chip {
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .2);
            color: #fff;
            border-radius: 999px;
            padding: .35rem .75rem;
            font-size: .875rem;
        }

        .card {
            border: 1px solid var(--sd-border);
            border-radius: .5rem;
        }

        .page-shell {
            padding-top: 1.5rem;
            padding-bottom: 2rem;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark app-topbar">
    <div class="container-fluid px-3 px-lg-4">
        <button
            type="button"
            class="btn btn-sm btn-outline-light btn-back-top me-2"
            onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href=<?= $homeUrlJson ?>; }"
        >
            &larr; Voltar
        </button>

        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= htmlspecialchars($homeUrl) ?>">
            <span class="d-inline-flex align-items-center justify-content-center bg-warning text-dark rounded-1 fw-bold" style="width:32px;height:32px;">SD</span>
            <span>SuperDunga</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topbarNav" aria-controls="topbarNav" aria-expanded="false" aria-label="Abrir menu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="topbarNav">
            <ul class="navbar-nav me-auto ms-lg-4 mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars($homeUrl) ?>">Painel</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars($tesourariaUrl) ?>">Tesouraria</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= htmlspecialchars($fechamentoUrl) ?>">Fechamento</a>
                </li>
                <?php if ($nivelUsuario === 'MASTER' || $nivelUsuario === 'ADMIN'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($usuariosUrl) ?>">Usuarios</a>
                    </li>
                <?php endif; ?>
                <?php if ($nivelUsuario === 'MASTER'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($empresasUrl) ?>">Empresas</a>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-2">
                <span class="user-chip">
                    <?= htmlspecialchars($usuarioNome) ?>
                    <?php if ($nivelUsuario !== ''): ?>
                        <span class="opacity-75">/ <?= htmlspecialchars($nivelUsuario) ?></span>
                    <?php endif; ?>
                </span>
                <a href="<?= htmlspecialchars($logoutUrl) ?>" class="btn btn-warning btn-sm fw-semibold">Sair</a>
            </div>
        </div>
    </div>
</nav>

<main class="container page-shell">
