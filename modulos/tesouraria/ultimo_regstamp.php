<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/conexao.php';

header('Content-Type: application/json');

/* =========================
   PARÂMETRO
========================= */
$tabela = $_GET['tabela'] ?? '';
$empresa = isset($_GET['empresa']) ? (int)$_GET['empresa'] : null;

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
    'est005',
    'est006',
    'est008'
];

if (!in_array($tabela, $tabelas_permitidas)) {
    echo json_encode(["erro" => "Tabela inválida"]);
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

    $params = [];
    $where = '';

    if ($empresa !== null && $empresa > 0) {
        $stmtColunaEmpresa = $pdo_master->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = 'EMPRESA'
        ");
        $stmtColunaEmpresa->execute([$nome_tabela]);

        if ((int)$stmtColunaEmpresa->fetchColumn() > 0) {
            $where = " WHERE EMPRESA = ?";
            $params[] = $empresa;
        }
    }

    $sql = "SELECT MAX(REGSTAMP) AS ultima_regstamp FROM $nome_tabela$where";
    $stmt = $pdo_master->prepare($sql);
    $stmt->execute($params);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ultima_regstamp' => $row['ultima_regstamp'] ?: '1900-01-01 00:00:00'
    ]);

} catch (Exception $e) {

    echo json_encode([
        'erro' => $e->getMessage()
    ]);
}
