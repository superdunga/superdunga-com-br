<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/conexao.php';

header('Content-Type: application/json');

/* =========================
   PARÂMETRO
========================= */
$tabela = $_GET['tabela'] ?? '';

/* =========================
   SEGURANÇA
========================= */
$tabelas_permitidas = [
    'bnc001',
    'cr001',
    'cr002',
    'cp001',
    'cp003',
    'cp004',
    'est007',
    'est004',
    'est008'
];

if (!in_array($tabela, $tabelas_permitidas)) {
    echo json_encode(["erro" => "Tabela inválida"]);
    exit;
}

if ($tabela === 'cp004') {
    echo json_encode([
        'ultima_regstamp' => '1900-01-01 00:00:00'
    ]);
    exit;
}

/* =========================
   NOME REAL DA TABELA
========================= */
$nome_tabela = "armazem_" . $tabela;

/* =========================
   CONSULTA
========================= */
try {

    $sql = "SELECT MAX(REGSTAMP) AS ultima_regstamp FROM $nome_tabela";
    $stmt = $pdo_master->query($sql);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ultima_regstamp' => $row['ultima_regstamp'] ?: '1900-01-01 00:00:00'
    ]);

} catch (Exception $e) {

    echo json_encode([
        'erro' => $e->getMessage()
    ]);
}
