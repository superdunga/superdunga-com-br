<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/whatsapp_lib.php';
require __DIR__ . '/../../config/cielo_caixa_whatsapp.php';
require __DIR__ . '/../whatsapp_operacional/whatsapp_operacional_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('X-LiteSpeed-Cache-Control: no-cache');
date_default_timezone_set('America/Sao_Paulo');

try {
    whatsappEnsureTables($pdo_master);

    $tokenRecebido = trim((string)($_GET['token'] ?? ''));

    $stmtToken = $pdo_master->prepare("
        SELECT COUNT(*)
        FROM whatsapp_gerencial_config
        WHERE agendamento_token = ?
          AND agendamento_token IS NOT NULL
          AND agendamento_token <> ''
    ");
    $stmtToken->execute([$tokenRecebido]);

    if ($tokenRecebido === '' || (int)$stmtToken->fetchColumn() === 0) {
        http_response_code(403);
        echo json_encode([
            'status' => 'erro',
            'mensagem' => 'Token invalido.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pdo_master->prepare('UPDATE whatsapp_gerencial_config SET cron_ultimo_contato_em=NOW() WHERE agendamento_token=?')->execute([$tokenRecebido]);

    $resultado = whatsappExecutarAgendamentos($pdo_master);
    $resultadoCaixaCielo = cieloCaixaExecutarMonitor($pdo_master);
    $resultadoOperacional = whatsappOperacionalExecutarAutomacao($pdo_master);
    $confirmacoesOperacionais = whatsappOperacionalConsultarConfirmacoes($pdo_master);
    $confirmacoesGerenciais = whatsappAtualizarConfirmacoesGerenciais($pdo_master);

    echo json_encode([
        'status' => 'ok',
        'executadas' => count(array_filter($resultado, static function ($item) { return $item['status'] !== 'IGNORADO_ATRASO'; })),
        'ignoradas_atraso' => count(array_filter($resultado, static function ($item) { return $item['status'] === 'IGNORADO_ATRASO'; })),
        'resultado' => $resultado,
        'caixa_dia_anterior_cielo' => $resultadoCaixaCielo,
        'fechamentos_operacionais' => $resultadoOperacional,
        'confirmacoes_operacionais' => $confirmacoesOperacionais,
        'confirmacoes_gerenciais' => $confirmacoesGerenciais,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'erro',
        'mensagem' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
