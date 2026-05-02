<?php
require '../../config/conexao.php';

header('Content-Type: application/json');

/*
    BUSCAR REGISTROS PRONTOS PARA ENVIO AO FIREBIRD
*/

$sql = "
SELECT 
    CRCONTADOR,
    recebimento_id,
    CMCONTADOR
FROM armazem_cr001
WHERE recebimento_id IS NOT NULL
  AND CRCONTADOR IS NOT NULL
  AND CMCONTADOR IS NOT NULL
  AND COALESCE(excluido_firebird, 'N') = 'N'
  AND (enviado_firebird IS NULL OR enviado_firebird IN ('N','E'))
LIMIT 500
";

$stmt = $pdo_master->query($sql);

$dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($dados);
