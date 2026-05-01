<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/conexao.php';
require '../whatsapp/whatsapp_lib.php';

date_default_timezone_set('America/Sao_Paulo');

whatsappEnsureTables($pdo_master);

$rotina = whatsappRotinaPorCodigo($pdo_master, 'resumo_diario');
if (!$rotina) {
    exit("Rotina resumo_diario nao cadastrada");
}

if (($rotina['evitar_duplicidade_diaria'] ?? 'S') === 'S') {
    $stmt = $pdo_master->prepare("
        SELECT COUNT(*)
        FROM controle_envio
        WHERE data = CURDATE()
    ");
    $stmt->execute();

    if ($stmt->fetchColumn() > 0) {
        exit("Ja enviado hoje");
    }
}

try {
    $msg = whatsappMensagemResumoDiario($pdo_master, 1);
    $resultado = whatsappEnviarRotina($pdo_master, $rotina, $msg, null, null);

    if ($resultado['ok'] > 0 && ($rotina['evitar_duplicidade_diaria'] ?? 'S') === 'S') {
        $pdo_master->exec("INSERT IGNORE INTO controle_envio (data) VALUES (CURDATE())");
    }

    echo "Envio concluido: {$resultado['ok']} OK, {$resultado['falha']} erro(s)";
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
}
