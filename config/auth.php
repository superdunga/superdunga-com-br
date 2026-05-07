<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function appBaseUrl(): string
{
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $modulosPos = strpos($scriptDir, '/modulos');
    $base = $modulosPos !== false ? substr($scriptDir, 0, $modulosPos) : $scriptDir;
    return rtrim($base, '/');
}

if (!isset($_SESSION['usuario_id'])) {
    $base = appBaseUrl();
    header("Location: " . ($base ?: '') . "/login.php");
    exit;
}

function redirecionarPendenciasOperador(): void
{
    if (($_SESSION['nivel'] ?? '') !== 'OPERADOR') {
        return;
    }

    $hoje = date('Y-m-d');
    if (($_SESSION['operador_pendencias_liberado_data'] ?? '') === $hoje) {
        return;
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $permitidos = [
        '/modulos/operador/pendencias.php',
        '/modulos/tesouraria/conciliar.php',
        '/modulos/fechamentodecaixa/conciliacao_dinheiro_divergentes.php',
        '/modulos/fechamentodecaixa/extrato_caixa.php',
        '/modulos/fechamentodecaixa/validar_cm.php',
        '/modulos/fechamentodecaixa/resumo_prazo.php',
        '/modulos/fechamentodecaixa/diagnostico_divergencia.php',
        '/modulos/auditoria/itens_fora_padrao.php',
        '/logout.php',
        '/login.php',
    ];

    foreach ($permitidos as $permitido) {
        if (substr($script, -strlen($permitido)) === $permitido) {
            return;
        }
    }

    $base = appBaseUrl();
    header("Location: " . ($base ?: '') . "/modulos/operador/pendencias.php");
    exit;
}

redirecionarPendenciasOperador();

/* Hierarquia oficial do sistema */
function hierarquia() {
    return [
        'CONSULTA'   => 1,
        'OPERADOR'   => 2,
        'SUPERVISOR' => 3,
        'GERENTE'    => 4,
        'ADMIN'      => 5,
        'MASTER'     => 6
    ];
}

/* Exige nível mínimo para acessar a página */
function exigirNivel($nivelPermitido) {

    $hierarquia = hierarquia();

    if (
        !isset($_SESSION['nivel']) ||
        !isset($hierarquia[$_SESSION['nivel']]) ||
        !isset($hierarquia[$nivelPermitido]) ||
        $hierarquia[$_SESSION['nivel']] < $hierarquia[$nivelPermitido]
    ) {
        die("Acesso negado.");
    }
}

/* Apenas verifica se possui nível mínimo (não mata a página) */
function temNivel($nivelMinimo) {

    $hierarquia = hierarquia();

    if (
        !isset($_SESSION['nivel']) ||
        !isset($hierarquia[$_SESSION['nivel']]) ||
        !isset($hierarquia[$nivelMinimo])
    ) {
        return false;
    }

    return $hierarquia[$_SESSION['nivel']] >= $hierarquia[$nivelMinimo];
}

/* Impede criação de usuário com nível superior ao seu */
function podeCriarNivel($nivelCriado) {

    $hierarquia = hierarquia();

    if (
        !isset($_SESSION['nivel']) ||
        !isset($hierarquia[$_SESSION['nivel']]) ||
        !isset($hierarquia[$nivelCriado])
    ) {
        return false;
    }

    return $hierarquia[$_SESSION['nivel']] > $hierarquia[$nivelCriado];
}
