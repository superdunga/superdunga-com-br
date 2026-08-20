<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$contaId = (int)($_GET['id'] ?? 0);

if ($contaId <= 0) {
    http_response_code(404);
    exit('Conta de energia nao localizada.');
}

$stmt = $pdo_master->prepare("
    SELECT arquivo_nome, arquivo_caminho
    FROM energia_contas
    WHERE id = ?
      AND empresa_id = ?
    LIMIT 1
");
$stmt->execute([$contaId, $empresaId]);
$conta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$conta || empty($conta['arquivo_caminho'])) {
    http_response_code(404);
    exit('PDF da conta de energia nao localizado.');
}

$baseUploads = realpath(__DIR__ . '/../../uploads/energia');
$arquivo = realpath(__DIR__ . '/../../' . ltrim((string)$conta['arquivo_caminho'], '/\\'));
$prefixoPermitido = $baseUploads !== false ? rtrim($baseUploads, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';

if (
    $baseUploads === false
    || $arquivo === false
    || strpos($arquivo, $prefixoPermitido) !== 0
    || !is_file($arquivo)
    || strtolower(pathinfo($arquivo, PATHINFO_EXTENSION)) !== 'pdf'
) {
    http_response_code(404);
    exit('PDF da conta de energia nao localizado.');
}

$nomeDownload = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($conta['arquivo_nome'] ?: basename($arquivo)));
if (substr(strtolower($nomeDownload), -4) !== '.pdf') {
    $nomeDownload .= '.pdf';
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nomeDownload . '"');
header('Content-Length: ' . filesize($arquivo));
header('X-Content-Type-Options: nosniff');
readfile($arquivo);
exit;
