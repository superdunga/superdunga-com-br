<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    garantirTabelasDescontoCheques($pdo_master);

    $empresaId = (int)($_SESSION['empresa_id'] ?? 0);
    $cnpjCpf = (string)($_GET['cnpj_cpf'] ?? '');
    $documentoId = (int)($_GET['documento_id'] ?? 0);

    echo json_encode(
        buscarResumoEmissorAVencerDC($pdo_master, $empresaId, $cnpjCpf, $documentoId),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
