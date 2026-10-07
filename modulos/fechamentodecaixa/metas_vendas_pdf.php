<?php
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/metas_vendas_pdf_lib.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$arquivo = tempnam(sys_get_temp_dir(), 'analise_performance_');
if ($arquivo === false) {
    http_response_code(500);
    exit('Nao foi possivel preparar o PDF.');
}

try {
    metasVendasGerarPdf($empresaId, $_GET, $arquivo);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Analise_Performance.pdf"');
    header('Content-Length: ' . filesize($arquivo));
    readfile($arquivo);
} finally {
    if (is_file($arquivo)) {
        unlink($arquivo);
    }
}

