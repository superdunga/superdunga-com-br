<?php

$host = "superdunga.com.br";
$db   = "etelema_superdunga";
$user = "etelema_superdunga_user";
$pass = "@superdunga2026";

try {

    $pdo_master = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

} catch (PDOException $e) {
    die("Erro na conexão com o banco MASTER: " . $e->getMessage());
}
