<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../../login.php");
    exit;
}

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