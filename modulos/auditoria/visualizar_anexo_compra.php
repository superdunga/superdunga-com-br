<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once __DIR__ . '/_anexos_compras.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$anexoId = (int)($_GET['id'] ?? 0);

$stmt = $pdo_master->prepare("
    SELECT arquivo, nome_original, mime_type, tamanho
    FROM auditoria_compra_anexos
    WHERE id = ?
      AND empresa_id = ?
      AND excluido_em IS NULL
    LIMIT 1
");
$stmt->execute([$anexoId, $empresaId]);
$anexo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$anexo) {
    http_response_code(404);
    exit('Foto nao encontrada.');
}

$raizAplicacao = realpath(__DIR__ . '/../..');
$arquivo = $raizAplicacao !== false
    ? realpath($raizAplicacao . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $anexo['arquivo']))
    : false;
$pastaPermitida = realpath(__DIR__ . '/../../uploads/auditoria_compras');

if (
    $arquivo === false
    || $pastaPermitida === false
    || strpos($arquivo, $pastaPermitida . DIRECTORY_SEPARATOR) !== 0
    || !is_file($arquivo)
) {
    http_response_code(404);
    exit('Arquivo da foto nao encontrado.');
}

$nome = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$anexo['nome_original']);
header('Content-Type: ' . $anexo['mime_type']);
header('Content-Length: ' . filesize($arquivo));
header('Content-Disposition: inline; filename="' . ($nome !== '' ? $nome : 'nota') . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($arquivo);
exit;
