<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../config/firebird.php';

$empresa_id = $_SESSION['empresa_id'];

// tenta conectar
$pdo_fb = conectarFirebird($empresa_id, $pdo_master);

echo "<h3>Conexão com Firebird OK ✅</h3>";

// teste simples
try {

    $stmt = $pdo_fb->query("
        SELECT FIRST 5 DTLANC, TOTGERAL
        FROM EST007
    ");

    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<pre>";
    print_r($dados);
    echo "</pre>";

} catch (Exception $e) {
    echo "Erro ao consultar Firebird: " . $e->getMessage();
}