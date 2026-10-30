<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/conexao.php';
require_once dirname(__DIR__) . '/config/importacao_recebimentos.php';
require_once dirname(__DIR__) . '/config/cielo_extrato.php';

$empresaId = 1;
$dataFinal = isset($argv[2]) ? trim((string)$argv[2]) : date('Y-m-d');
$dataInicial = isset($argv[1]) ? trim((string)$argv[1]) : date('Y-m-d', strtotime('-7 days'));

try {
    $regra = buscarRegraImportacao(
        $pdo_master,
        $empresaId,
        'cielo_api_comercial',
        regraImportacaoCieloComercialArmazem()
    );
    if (!$regra) {
        throw new RuntimeException('Regra Cielo Comercial nao cadastrada para a empresa 1.');
    }

    $resultado = cieloSincronizarRecebimentos(
        $pdo_master,
        $empresaId,
        $regra,
        cieloConfiguracaoVrs(),
        $dataInicial,
        $dataFinal
    );

    echo date('Y-m-d H:i:s') . ' - Cielo Comercial concluida', PHP_EOL;
    echo 'Arquivos: ' . (int)$resultado['arquivos_recebidos'], PHP_EOL;
    echo 'Arquivos duplicados: ' . (int)$resultado['arquivos_duplicados'], PHP_EOL;
    echo 'Recebiveis novos: ' . (int)$resultado['registros_importados'], PHP_EOL;
    echo 'Recebiveis atualizados: ' . (int)$resultado['registros_atualizados'], PHP_EOL;
    echo 'Registros ignorados: ' . (int)$resultado['registros_ignorados'], PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' - ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

