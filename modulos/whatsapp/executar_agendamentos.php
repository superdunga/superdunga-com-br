<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/whatsapp_lib.php';
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

    $stmtLock = $pdo_master->query("SELECT GET_LOCK('superdunga_whatsapp_agendamentos', 0)");
    if ((int)$stmtLock->fetchColumn() !== 1) {
        echo json_encode([
            'status' => 'em_execucao',
            'mensagem' => 'Uma execucao anterior ainda esta ativa.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $inicioCron = microtime(true);
    $pdo_master->prepare("INSERT INTO whatsapp_cron_execucoes (processo) VALUES ('WHATSAPP_AGENDAMENTOS')")->execute();
    $execucaoId = (int)$pdo_master->lastInsertId();

    try {
        $pdo_master->prepare('UPDATE whatsapp_gerencial_config SET cron_ultimo_contato_em=NOW() WHERE agendamento_token=?')->execute([$tokenRecebido]);

        $resultado = whatsappExecutarAgendamentos($pdo_master);
        $resultadoOperacional = whatsappOperacionalExecutarAutomacao($pdo_master);
        $confirmacoesOperacionais = whatsappOperacionalConsultarConfirmacoes($pdo_master);
        $confirmacoesGerenciais = whatsappAtualizarConfirmacoesGerenciais($pdo_master);

        $resposta = [
            'status' => 'ok',
            'executadas' => count(array_filter($resultado, static function ($item) { return $item['status'] !== 'IGNORADO_ATRASO'; })),
            'ignoradas_atraso' => count(array_filter($resultado, static function ($item) { return $item['status'] === 'IGNORADO_ATRASO'; })),
            'resultado' => $resultado,
            'fechamentos_operacionais' => $resultadoOperacional,
            'confirmacoes_operacionais' => $confirmacoesOperacionais,
            'confirmacoes_gerenciais' => $confirmacoesGerenciais,
        ];
        $duracaoMs = (int)round((microtime(true) - $inicioCron) * 1000);
        $stmtFim = $pdo_master->prepare("UPDATE whatsapp_cron_execucoes SET status='OK',finalizado_em=NOW(),duracao_ms=?,detalhes=? WHERE id=?");
        $stmtFim->execute([$duracaoMs, json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $execucaoId]);
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        $duracaoMs = (int)round((microtime(true) - $inicioCron) * 1000);
        $stmtFim = $pdo_master->prepare("UPDATE whatsapp_cron_execucoes SET status='ERRO',finalizado_em=NOW(),duracao_ms=?,detalhes=? WHERE id=?");
        $stmtFim->execute([$duracaoMs, $e->getMessage(), $execucaoId]);
        throw $e;
    } finally {
        $pdo_master->query("SELECT RELEASE_LOCK('superdunga_whatsapp_agendamentos')");
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'erro',
        'mensagem' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
