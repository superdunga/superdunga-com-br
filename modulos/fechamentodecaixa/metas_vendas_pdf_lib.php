<?php

function metasVendasFiltrosPdfPadrao(array $filtros = []): array
{
    $mes = date('Y-m');
    $inicioMes = $mes . '-01';
    $ontem = date('Y-m-d', strtotime('-1 day'));

    return [
        'data_ini' => (string)($filtros['data_ini'] ?? $inicioMes),
        'data_fim' => (string)($filtros['data_fim'] ?? max($inicioMes, $ontem)),
        'dias' => is_array($filtros['dias'] ?? null) ? $filtros['dias'] : range(0, 6),
        'produto_mes_comparacao' => (string)($filtros['produto_mes_comparacao'] ?? date('Y-m', strtotime($inicioMes . ' -1 month'))),
    ];
}

function metasVendasHtmlPdf(int $empresaId, array $filtros = []): string
{
    $getAnterior = $_GET;
    $postAnterior = $_POST;
    $metodoAnterior = $_SERVER['REQUEST_METHOD'] ?? null;
    $empresaAnterior = $GLOBALS['METAS_VENDAS_EMPRESA_ID'] ?? null;

    $_GET = metasVendasFiltrosPdfPadrao($filtros);
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $GLOBALS['METAS_VENDAS_EMPRESA_ID'] = $empresaId;

    if (!defined('METAS_VENDAS_MODO_PDF')) {
        define('METAS_VENDAS_MODO_PDF', true);
    }
    if (!defined('METAS_VENDAS_EXECUCAO_INTERNA')) {
        define('METAS_VENDAS_EXECUCAO_INTERNA', true);
    }

    ob_start();
    try {
        include __DIR__ . '/metas_vendas.php';
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    } finally {
        $_GET = $getAnterior;
        $_POST = $postAnterior;
        if ($metodoAnterior === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $metodoAnterior;
        }
        if ($empresaAnterior === null) {
            unset($GLOBALS['METAS_VENDAS_EMPRESA_ID']);
        } else {
            $GLOBALS['METAS_VENDAS_EMPRESA_ID'] = $empresaAnterior;
        }
    }
}

function metasVendasGerarPdf(int $empresaId, array $filtros, string $arquivo): string
{
    $limiteMemoria = trim((string)ini_get('memory_limit'));
    $unidadeMemoria = strtolower(substr($limiteMemoria, -1));
    $limiteMemoriaMb = (int)$limiteMemoria;
    if ($unidadeMemoria === 'g') {
        $limiteMemoriaMb *= 1024;
    } elseif ($unidadeMemoria === 'k') {
        $limiteMemoriaMb = (int)ceil($limiteMemoriaMb / 1024);
    }
    if ($limiteMemoria !== '-1' && $limiteMemoriaMb < 512) {
        ini_set('memory_limit', '512M');
    }
    if (function_exists('set_time_limit')) {
        set_time_limit(300);
    }

    $autoload = __DIR__ . '/../../lib/dompdf/autoload.inc.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Biblioteca de PDF nao encontrada.');
    }
    require_once $autoload;

    $html = metasVendasHtmlPdf($empresaId, $filtros);
    $opcoes = new Dompdf\Options();
    $opcoes->set('defaultFont', 'DejaVu Sans');
    $opcoes->set('isRemoteEnabled', false);
    $opcoes->set('isHtml5ParserEnabled', true);
    $opcoes->set('chroot', dirname(__DIR__, 2));

    $dompdf = new Dompdf\Dompdf($opcoes);
    $dompdf->setPaper('A3', 'landscape');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    $diretorio = dirname($arquivo);
    if (!is_dir($diretorio) && !mkdir($diretorio, 0775, true) && !is_dir($diretorio)) {
        throw new RuntimeException('Nao foi possivel criar a pasta do PDF.');
    }
    if (file_put_contents($arquivo, $dompdf->output()) === false) {
        throw new RuntimeException('Nao foi possivel gravar o PDF.');
    }

    return $arquivo;
}
