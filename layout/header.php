<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$modulosConfigPath = __DIR__ . '/../config/modulos.php';
if (file_exists($modulosConfigPath)) {
    require_once $modulosConfigPath;
}

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$modulosPos = strpos($scriptDir, '/modulos');
$appBaseUrl = $modulosPos !== false ? substr($scriptDir, 0, $modulosPos) : $scriptDir;
$appBaseUrl = rtrim($appBaseUrl, '/');
$homeUrl = ($appBaseUrl ?: '') . '/index.php';
$logoutUrl = ($appBaseUrl ?: '') . '/logout.php';
$tesourariaUrl = ($appBaseUrl ?: '') . '/modulos/tesouraria/menu_tesouraria.php';
$fechamentoUrl = ($appBaseUrl ?: '') . '/modulos/fechamentodecaixa/menu_fechamento.php';
$financeiroUrl = ($appBaseUrl ?: '') . '/modulos/financeiro/menu_financeiro.php';
$estoqueUrl = ($appBaseUrl ?: '') . '/modulos/estoque/menu_estoque.php';
$gestaoUrl = ($appBaseUrl ?: '') . '/modulos/gestao/menu_gestao.php';
$rotinasOperacionaisUrl = ($appBaseUrl ?: '') . '/modulos/rotinas_operacionais/menu_rotinas_operacionais.php';
$colaboradoresUrl = ($appBaseUrl ?: '') . '/modulos/colaboradores/menu_colaboradores.php';
$unimedUrl = ($appBaseUrl ?: '') . '/modulos/unimed/menu_unimed.php';
$descontoChequesUrl = ($appBaseUrl ?: '') . '/modulos/desconto_cheques/menu_desconto_cheques.php';
$movimentacaoBaixaUrl = ($appBaseUrl ?: '') . '/modulos/movimentacao_baixa/menu_movimentacao_baixa.php';
$whatsappUrl = ($appBaseUrl ?: '') . '/modulos/whatsapp/index.php';
$usuariosUrl = ($appBaseUrl ?: '') . '/modulos/usuarios/listar.php';
$empresasUrl = ($appBaseUrl ?: '') . '/modulos/empresas/listar.php';
$usuarioNome = $_SESSION['usuario_nome'] ?? 'Usuario';
$nivelUsuario = $_SESSION['nivel'] ?? '';
$empresaIdSessao = (int)($_SESSION['empresa_id'] ?? 0);
$empresaNome = 'Empresa nao definida';
$mostrarTesourariaTopbar = true;
$mostrarFechamentoTopbar = true;
$mostrarFinanceiroTopbar = true;
$mostrarEstoqueTopbar = true;
$mostrarGestaoTopbar = true;
$mostrarRotinasOperacionaisTopbar = true;
$mostrarColaboradoresTopbar = true;
$mostrarUnimedTopbar = true;
$mostrarDescontoChequesTopbar = true;
$mostrarMovimentacaoBaixaTopbar = $empresaIdSessao === 2;
$mostrarWhatsappTopbar = $nivelUsuario === 'MASTER';
$mostrarUsuariosTopbar = $nivelUsuario === 'MASTER' || $nivelUsuario === 'ADMIN';
$mostrarEmpresasTopbar = $nivelUsuario === 'MASTER';

if (!empty($_SESSION['empresa_nome'])) {
    $empresaNome = (string)$_SESSION['empresa_nome'];
} elseif ($empresaIdSessao > 0) {
    try {
        if (!isset($pdo_master)) {
            $conexaoPath = __DIR__ . '/../config/conexao.php';
            if (file_exists($conexaoPath)) {
                require $conexaoPath;
            }
        }

        if (isset($pdo_master) && $pdo_master instanceof PDO) {
            $stmtEmpresaTopbar = $pdo_master->prepare("SELECT nome_fantasia FROM empresas WHERE id = ? LIMIT 1");
            $stmtEmpresaTopbar->execute([$empresaIdSessao]);
            $empresaNomeBanco = $stmtEmpresaTopbar->fetchColumn();
            if ($empresaNomeBanco) {
                $empresaNome = $empresaNomeBanco;
                $_SESSION['empresa_nome'] = $empresaNomeBanco;
            }
        }
    } catch (Throwable $e) {
        $empresaNome = 'Empresa ' . $empresaIdSessao;
    }
}

if (isset($pdo_master) && function_exists('grupoPermitido') && function_exists('moduloPermitido')) {
    $mostrarTesourariaTopbar = grupoPermitido($pdo_master, $empresaIdSessao, 'Tesouraria', $nivelUsuario);
    $mostrarFechamentoTopbar = grupoPermitido($pdo_master, $empresaIdSessao, 'Fechamento', $nivelUsuario);
    $mostrarFinanceiroTopbar = grupoPermitido($pdo_master, $empresaIdSessao, 'Financeiro', $nivelUsuario);
    $mostrarEstoqueTopbar = grupoPermitido($pdo_master, $empresaIdSessao, 'Estoque', $nivelUsuario);
    $mostrarGestaoTopbar = grupoPermitido($pdo_master, $empresaIdSessao, 'Gestao', $nivelUsuario);
    $mostrarRotinasOperacionaisTopbar = grupoPermitido($pdo_master, $empresaIdSessao, 'Rotinas Operacionais', $nivelUsuario);
    $mostrarColaboradoresTopbar = grupoPermitido($pdo_master, $empresaIdSessao, 'Colaboradores', $nivelUsuario);
    $mostrarUnimedTopbar = grupoPermitido($pdo_master, $empresaIdSessao, 'Unimed', $nivelUsuario);
    $mostrarDescontoChequesTopbar = grupoPermitido($pdo_master, $empresaIdSessao, 'Desconto de Cheques', $nivelUsuario);
    $mostrarMovimentacaoBaixaTopbar = $empresaIdSessao === 2 && grupoPermitido($pdo_master, $empresaIdSessao, 'Movimentacao/Baixa', $nivelUsuario);
    $mostrarWhatsappTopbar = $nivelUsuario === 'MASTER' && moduloPermitido($pdo_master, $empresaIdSessao, 'whatsapp', $nivelUsuario);
    $mostrarUsuariosTopbar = in_array($nivelUsuario, ['MASTER', 'ADMIN'], true) && moduloPermitido($pdo_master, $empresaIdSessao, 'usuarios', $nivelUsuario);
    $mostrarEmpresasTopbar = $nivelUsuario === 'MASTER' && moduloPermitido($pdo_master, $empresaIdSessao, 'empresas', $nivelUsuario);
}

$homeUrlJson = htmlspecialchars(json_encode($homeUrl), ENT_QUOTES, 'UTF-8');
$bootstrapCssUrl = ($appBaseUrl ?: '') . '/assets/bootstrap/bootstrap.min.css';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>SuperDunga - Sistema Financeiro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5 -->
    <link href="<?= htmlspecialchars($bootstrapCssUrl) ?>" rel="stylesheet">

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
            overflow-x: hidden;
        }

        .navbar-brand {
            font-weight: 700;
            letter-spacing: 0;
            min-width: 0;
        }

        .app-topbar {
            background: linear-gradient(90deg, var(--sd-primary-dark), var(--sd-primary));
            box-shadow: 0 10px 30px rgba(15, 45, 104, .18);
        }

        .app-topbar .nav-link {
            color: rgba(255, 255, 255, .82);
            font-weight: 500;
            white-space: nowrap;
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
            border-radius: .5rem;
            padding: .4rem .75rem;
            font-size: .875rem;
            line-height: 1.15;
            max-width: 240px;
            min-width: 0;
        }

        .user-chip .user-chip-label {
            color: rgba(255, 255, 255, .7);
            font-size: .72rem;
        }

        .user-chip .user-chip-value {
            color: #fff;
            font-weight: 700;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .card {
            border: 1px solid var(--sd-border);
            border-radius: .5rem;
        }

        .card.shadow-sm,
        .card.shadow-lg,
        .page-shell > .card {
            box-shadow: 0 .75rem 2rem rgba(15, 45, 104, .08) !important;
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid var(--sd-border);
            padding: 1rem 1.25rem;
        }

        .card-header h1,
        .card-header h2,
        .card-header h3,
        .card-header h4,
        .card-header h5,
        .card-body > h1:first-child,
        .card-body > h2:first-child,
        .card-body > h3:first-child,
        .card-body > h4:first-child,
        .card-body > h5:first-child {
            color: #172033;
            font-weight: 700;
            letter-spacing: 0;
        }

        .card-header small,
        .text-muted {
            color: #667085 !important;
        }

        .form-control,
        .form-select,
        .btn,
        .alert,
        .badge {
            border-radius: .5rem;
        }

        .btn {
            font-weight: 600;
        }

        .btn-purple {
            --bs-btn-color: #fff;
            --bs-btn-bg: #5b3cc4;
            --bs-btn-border-color: #5b3cc4;
            --bs-btn-hover-color: #fff;
            --bs-btn-hover-bg: #4d2fb2;
            --bs-btn-hover-border-color: #4d2fb2;
        }

        .table {
            margin-bottom: 0;
            vertical-align: middle;
        }

        .table-responsive {
            border: 1px solid var(--sd-border);
            border-radius: .5rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table thead th,
        .table-dark th {
            background: var(--sd-primary-dark) !important;
            border-color: rgba(255, 255, 255, .14) !important;
            color: #fff !important;
            font-size: .8rem;
            letter-spacing: .02em;
            text-transform: uppercase;
        }

        .table tbody tr:last-child td {
            border-bottom: 0;
        }

        .border.p-3.rounded {
            background: #fff;
            border-color: var(--sd-border) !important;
            border-radius: .5rem !important;
        }

        .module-card {
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }

        .module-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 1rem 2.5rem rgba(15, 45, 104, .12) !important;
            border-color: rgba(22, 65, 148, .35);
        }

        .module-icon {
            width: 44px;
            height: 44px;
            border-radius: .5rem;
            background: #eef4ff;
            border: 1px solid #d6e4ff;
            color: var(--sd-primary-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .btn-voltar-fixo {
            display: none !important;
        }

        .page-shell {
            padding-top: 1.5rem;
            padding-bottom: 2rem;
        }

        img,
        video,
        canvas,
        iframe {
            max-width: 100%;
        }

        input,
        select,
        textarea,
        button {
            max-width: 100%;
        }

        @media (min-width: 992px) {
            .app-topbar .navbar-collapse {
                min-width: 0;
            }

            .app-topbar .navbar-nav {
                flex-wrap: wrap;
                row-gap: .15rem;
            }

            .app-topbar .navbar-nav .nav-link {
                padding-right: .42rem;
                padding-left: .42rem;
                font-size: .92rem;
            }

            .app-topbar .navbar-brand {
                flex: 0 0 auto;
                margin-right: .25rem;
            }

            .app-topbar .user-chip {
                max-width: 220px;
            }
        }

        @media (min-width: 992px) and (max-width: 1250px) {
            .btn-back-top {
                padding-right: .45rem;
                padding-left: .45rem;
            }

            .app-topbar .navbar-brand span:last-child {
                display: none;
            }

            .app-topbar .navbar-nav {
                margin-left: .75rem !important;
            }

            .app-topbar .navbar-nav .nav-link {
                padding-right: .32rem;
                padding-left: .32rem;
                font-size: .86rem;
            }

            .app-topbar .user-chip {
                max-width: 185px;
                padding-right: .55rem;
                padding-left: .55rem;
            }
        }

        @media (max-width: 991.98px) {
            .app-topbar .container-fluid {
                gap: .5rem;
            }

            .app-topbar .navbar-brand {
                font-size: 1rem;
                flex: 1 1 auto;
                overflow: hidden;
            }

            .app-topbar .navbar-brand span:last-child {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .card-header .d-flex,
            .card-header.d-flex {
                align-items: flex-start !important;
                flex-direction: column;
                gap: .75rem;
                width: 100%;
            }

            .card-header .d-flex > div,
            .card-header.d-flex > div {
                width: 100%;
            }

            .card-header .btn,
            .card-header form,
            .card-header form .form-control,
            .card-header form .form-select {
                width: 100%;
            }

            .card-header form.d-flex,
            .card-body form.d-flex,
            .card-body > .d-flex,
            .page-shell form.d-flex {
                align-items: stretch !important;
                flex-direction: column;
                gap: .5rem !important;
            }

            .page-shell .d-flex.flex-wrap .btn {
                flex: 1 1 100%;
            }

            .user-chip {
                width: 100%;
                text-align: center;
            }
        }

        @media (max-width: 767.98px) {
            .page-shell {
                width: 100%;
                max-width: 100%;
                padding: 1rem .75rem 1.5rem;
            }

            .card {
                border-radius: .5rem;
            }

            .card-body,
            .card-header {
                padding: 1rem;
            }

            .table-responsive {
                margin-right: -1rem;
                margin-left: -1rem;
                border-right: 0;
                border-left: 0;
                border-radius: 0;
            }

            .table-responsive > .table,
            .card-body.table-responsive > .table {
                min-width: 680px;
            }

            .btn,
            .form-control,
            .form-select {
                min-height: 42px;
            }

            .page-shell .row {
                --bs-gutter-x: .75rem;
            }

            .module-icon {
                width: 40px;
                height: 40px;
                flex: 0 0 40px;
            }
        }

        @media (max-width: 575.98px) {
            .btn-back-top {
                padding-right: .5rem;
                padding-left: .5rem;
            }

            .app-topbar .navbar-brand span:first-child {
                width: 30px !important;
                height: 30px !important;
                flex: 0 0 30px;
            }

            .app-topbar .navbar-brand span:last-child {
                font-size: .95rem;
            }

            .navbar-toggler {
                padding: .25rem .5rem;
            }

            .card-header h1,
            .card-header h2,
            .card-header h3,
            .card-header h4,
            .card-header h5,
            .page-shell h1.h3,
            .page-shell .h3 {
                font-size: 1.25rem;
            }

            .page-shell .display-6 {
                font-size: 1.8rem;
            }

            .page-shell .btn {
                width: 100%;
            }
        }

        @media (max-width: 380px) {
            .app-topbar .navbar-brand span:last-child {
                display: none;
            }
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
                <?php if ($mostrarTesourariaTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($tesourariaUrl) ?>">Tesouraria</a>
                    </li>
                <?php endif; ?>
                <?php if ($mostrarFechamentoTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($fechamentoUrl) ?>">Fechamento</a>
                    </li>
                <?php endif; ?>
                <?php if ($mostrarFinanceiroTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($financeiroUrl) ?>">Financeiro</a>
                    </li>
                <?php endif; ?>
                <?php if ($mostrarEstoqueTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($estoqueUrl) ?>">Estoque</a>
                    </li>
                <?php endif; ?>
                <?php if ($mostrarGestaoTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($gestaoUrl) ?>">Gestão</a>
                    </li>
                <?php endif; ?>
                <?php if ($mostrarRotinasOperacionaisTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($rotinasOperacionaisUrl) ?>">Operacional</a>
                    </li>
                <?php endif; ?>
                <?php if ($mostrarColaboradoresTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($colaboradoresUrl) ?>">Colaboradores</a>
                    </li>
                <?php endif; ?>
                <?php if ($mostrarUnimedTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($unimedUrl) ?>">Unimed</a>
                    </li>
                <?php endif; ?>
                <?php if ($mostrarDescontoChequesTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($descontoChequesUrl) ?>">Cheques</a>
                    </li>
                <?php endif; ?>
                <?php if ($mostrarMovimentacaoBaixaTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($movimentacaoBaixaUrl) ?>">Mov/Baixa</a>
                    </li>
                <?php endif; ?>
                <?php if ($mostrarWhatsappTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($whatsappUrl) ?>">WhatsApp</a>
                    </li>
                <?php endif; ?>
                <?php if ($mostrarUsuariosTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($usuariosUrl) ?>">Usuarios</a>
                    </li>
                <?php endif; ?>
                <?php if ($mostrarEmpresasTopbar): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= htmlspecialchars($empresasUrl) ?>">Empresas</a>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-2">
                <span class="user-chip">
                    <span class="d-block user-chip-label">Usuario</span>
                    <span class="d-block user-chip-value">
                        <?= htmlspecialchars($usuarioNome) ?>
                        <?php if ($nivelUsuario !== ''): ?>
                            <span class="fw-normal opacity-75">/ <?= htmlspecialchars($nivelUsuario) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="d-block user-chip-label mt-1">Empresa</span>
                    <span class="d-block user-chip-value"><?= htmlspecialchars($empresaNome) ?></span>
                </span>
                <a href="<?= htmlspecialchars($logoutUrl) ?>" class="btn btn-warning btn-sm fw-semibold">Sair</a>
            </div>
        </div>
    </div>
</nav>

<main class="container page-shell">
