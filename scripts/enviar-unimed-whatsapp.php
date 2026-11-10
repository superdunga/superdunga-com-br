<?php

require __DIR__ . '/../config/conexao.php';
require __DIR__ . '/../modulos/whatsapp/whatsapp_lib.php';

date_default_timezone_set('America/Sao_Paulo');

$dryRun = in_array('--dry-run', $argv ?? [], true);
$competencia = date('Ym');
$inicio = microtime(true);
$execucaoId = 0;
$lockObtido = false;

try {
    if (!$dryRun) {
        whatsappEnsureTables($pdo_master);
    }

    $stmtLock = $pdo_master->query("SELECT GET_LOCK('superdunga_whatsapp_unimed', 0)");
    $lockObtido = (int)$stmtLock->fetchColumn() === 1;
    if (!$lockObtido) {
        throw new RuntimeException('Outra verificacao UNIMED esta em andamento.');
    }

    if (!$dryRun) {
        $pdo_master->prepare("INSERT INTO whatsapp_cron_execucoes (processo) VALUES ('WHATSAPP_UNIMED')")->execute();
        $execucaoId = (int)$pdo_master->lastInsertId();
    }

    $stmt = $pdo_master->prepare("
        SELECT *
        FROM whatsapp_rotinas
        WHERE gerador_sistema = 'unimed_responsaveis_pdf'
          AND ativo = 'S'
        ORDER BY empresa_id, id
    ");
    $stmt->execute();

    $resultados = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rotina) {
        $empresaId = (int)$rotina['empresa_id'];
        $faturas = whatsappFaturasUnimedProntas($pdo_master, $empresaId, $competencia);
        if (!$faturas) {
            $resultados[] = [
                'empresa_id' => $empresaId,
                'status' => 'AGUARDANDO_RELATORIOS',
                'competencia' => $competencia,
            ];
            continue;
        }

        if ($dryRun) {
            $resultados[] = [
                'empresa_id' => $empresaId,
                'status' => 'PRONTO',
                'competencia' => $competencia,
                'faturas' => count($faturas),
                'previa' => whatsappMensagemUnimedPreview($pdo_master, $empresaId, false),
            ];
            continue;
        }

        $config = whatsappConfig($pdo_master, $empresaId);
        if (!$config || ($config['ativo'] ?? 'N') !== 'S') {
            throw new RuntimeException('Configuracao gerencial do WhatsApp inativa para a empresa ' . $empresaId . '.');
        }

        $resultado = whatsappEnviarUnimedResponsaveisPdf(
            $pdo_master,
            $config,
            $rotina,
            null,
            $competencia
        );
        $pdo_master->prepare('UPDATE whatsapp_rotinas SET ultima_execucao = NOW() WHERE id = ?')
            ->execute([(int)$rotina['id']]);
        $resultados[] = [
            'empresa_id' => $empresaId,
            'status' => 'PROCESSADO',
            'competencia' => $competencia,
            'resultado' => $resultado,
        ];
    }

    $resposta = [
        'status' => 'ok',
        'dry_run' => $dryRun,
        'competencia' => $competencia,
        'resultados' => $resultados,
    ];

    if ($execucaoId > 0) {
        $duracaoMs = (int)round((microtime(true) - $inicio) * 1000);
        $pdo_master->prepare("UPDATE whatsapp_cron_execucoes SET status='OK', finalizado_em=NOW(), duracao_ms=?, detalhes=? WHERE id=?")
            ->execute([$duracaoMs, json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $execucaoId]);
    }

    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    if ($execucaoId > 0) {
        $duracaoMs = (int)round((microtime(true) - $inicio) * 1000);
        $pdo_master->prepare("UPDATE whatsapp_cron_execucoes SET status='ERRO', finalizado_em=NOW(), duracao_ms=?, detalhes=? WHERE id=?")
            ->execute([$duracaoMs, $e->getMessage(), $execucaoId]);
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($lockObtido) {
        $pdo_master->query("SELECT RELEASE_LOCK('superdunga_whatsapp_unimed')");
    }
}
