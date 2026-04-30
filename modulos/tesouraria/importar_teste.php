<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/auth.php';
require '../../config/conexao.php';
require '../../config/firebird.php';

$empresa_id = $_SESSION['empresa_id'];

// 🔥 conecta firebird
$pdo_fb = conectarFirebird($empresa_id, $pdo_master);

// 🔥 busca controlada
$stmt = $pdo_fb->query("
    SELECT FIRST 50 *
    FROM BNC001
    ORDER BY MOVCONTADOR
");

$dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = 0;

foreach ($dados as $d) {

    $sql = "
        INSERT INTO armazem_bnc001 (
            EMPRESA, MOVCONTADOR, DTMOV, NUMDOC, TIPOMOV,
            PAGTOEM, CBCONTADOR, TIPOES, CLICONTADOR,
            FCONTADOR, HISTMOV, VALORMOV
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt_mysql = $pdo_master->prepare($sql);

    $stmt_mysql->execute([
        $empresa_id,
        $d['MOVCONTADOR'],
        $d['DTMOV'],
        $d['NUMDOC'],
        $d['TIPOMOV'],
        $d['PAGTOEM'],
        $d['CBCONTADOR'],
        $d['TIPOES'],
        $d['CLICONTADOR'],
        $d['FCONTADOR'],
        $d['HISTMOV'],
        $d['VALORMOV']
    ]);

    $total++;
}

echo "Importados: $total registros";