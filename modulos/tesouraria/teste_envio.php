<?php
require '../../config/conexao.php';
require '../../config/auth.php';
require '../whatsapp/whatsapp_lib.php';

exigirNivel('MASTER');
whatsappEnsureTables($pdo_master);

$config = whatsappConfig($pdo_master);
if (!$config || $config['ativo'] !== 'S') {
    exit('Configuracao do WhatsApp inativa.');
}

$stmt = $pdo_master->query("
    SELECT *
    FROM whatsapp_destinatarios
    WHERE ativo = 'S'
    ORDER BY tipo, nome
    LIMIT 1
");
$destinatario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$destinatario) {
    exit('Nenhum destinatario ativo cadastrado.');
}

$resultado = whatsappSend(
    $pdo_master,
    $config,
    $destinatario,
    'Teste de envio WhatsApp pelo SuperDunga.',
    null,
    $_SESSION['usuario_id'] ?? null
);

header('Content-Type: application/json');
echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
