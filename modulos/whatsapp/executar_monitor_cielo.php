<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/whatsapp_lib.php';
require __DIR__ . '/../../config/cielo_caixa_whatsapp.php';

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
        echo json_encode(['status' => 'erro', 'mensagem' => 'Token invalido.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $inicioCron = microtime(true);
    $pdo_master->prepare("INSERT INTO whatsapp_cron_execucoes (processo) VALUES ('MONITOR_CIELO_CAIXA')")->execute();
    $execucaoId = (int)$pdo_master->lastInsertId();

    try {
        $resultado = cieloCaixaExecutarMonitor($pdo_master);
        $duracaoMs = (int)round((microtime(true) - $inicioCron) * 1000);
        $stmtFim = $pdo_master->prepare("UPDATE whatsapp_cron_execucoes SET status='OK',finalizado_em=NOW(),duracao_ms=?,detalhes=? WHERE id=?");
        $stmtFim->execute([$duracaoMs, json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $execucaoId]);
        echo json_encode(['status' => 'ok', 'resultado' => $resultado], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        $duracaoMs = (int)round((microtime(true) - $inicioCron) * 1000);
        $stmtFim = $pdo_master->prepare("UPDATE whatsapp_cron_execucoes SET status='ERRO',finalizado_em=NOW(),duracao_ms=?,detalhes=? WHERE id=?");
        $stmtFim->execute([$duracaoMs, $e->getMessage(), $execucaoId]);
        throw $e;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'erro', 'mensagem' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
