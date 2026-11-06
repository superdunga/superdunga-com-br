<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/conexao.php';
require_once dirname(__DIR__) . '/config/importacao_recebimentos.php';
require_once dirname(__DIR__) . '/config/cielo_extrato.php';
require_once dirname(__DIR__) . '/config/alelo_edi.php';

$dataFinal = isset($argv[2]) ? trim((string)$argv[2]) : date('Y-m-d');
$dataInicial = isset($argv[1]) ? trim((string)$argv[1]) : date('Y-m-d', strtotime('-7 days'));
$integracoes = [
    [
        'nome' => 'Cielo Comercial',
        'tipo' => 'cielo_api_comercial',
        'regra' => regraImportacaoCieloComercialArmazem(),
        'config' => cieloConfiguracaoVrs(),
    ],
    [
        'nome' => 'Cielo Outros',
        'tipo' => 'cielo_api_outros',
        'regra' => regraImportacaoCieloOutrosArmazem(),
        'config' => cieloConfiguracaoEthicos(),
    ],
];
$houveErro = false;

foreach ($integracoes as $integracao) {
    try {
        $regra = buscarRegraImportacao(
            $pdo_master,
            1,
            $integracao['tipo'],
            $integracao['regra']
        );
        if (!$regra) {
            throw new RuntimeException('Regra nao cadastrada para a empresa 1.');
        }

        $resultado = cieloSincronizarRecebimentos(
            $pdo_master,
            1,
            $regra,
            $integracao['config'],
            $dataInicial,
            $dataFinal
        );

        echo date('Y-m-d H:i:s') . ' - ' . $integracao['nome'] . ' concluida', PHP_EOL;
        echo 'Arquivos: ' . (int)$resultado['arquivos_recebidos'], PHP_EOL;
        echo 'Arquivos duplicados: ' . (int)$resultado['arquivos_duplicados'], PHP_EOL;
        echo 'Recebiveis novos: ' . (int)$resultado['registros_importados'], PHP_EOL;
        echo 'Recebiveis atualizados: ' . (int)$resultado['registros_atualizados'], PHP_EOL;
        echo 'Registros ignorados: ' . (int)$resultado['registros_ignorados'], PHP_EOL;
    } catch (Throwable $e) {
        $houveErro = true;
        fwrite(
            STDERR,
            date('Y-m-d H:i:s') . ' - ERRO ' . $integracao['nome'] . ': ' . $e->getMessage() . PHP_EOL
        );
    }
}

try {
    $regraAlelo = buscarRegraImportacao(
        $pdo_master,
        1,
        'alelo_edi_comercial',
        regraImportacaoAleloComercialArmazem()
    );
    if (!$regraAlelo) {
        throw new RuntimeException('Regra ALELO nao cadastrada para a empresa 1.');
    }
    $resultadoAlelo = aleloSincronizarRecebimentos(
        $pdo_master,
        1,
        $regraAlelo,
        aleloConfiguracaoEmpresa1(),
        $dataInicial,
        $dataFinal
    );
    echo date('Y-m-d H:i:s') . ' - ALELO Comercial concluida', PHP_EOL;
    echo 'Arquivos: ' . (int)$resultadoAlelo['arquivos_recebidos'], PHP_EOL;
    echo 'Arquivos duplicados: ' . (int)$resultadoAlelo['arquivos_duplicados'], PHP_EOL;
    echo 'Recebiveis novos: ' . (int)$resultadoAlelo['registros_importados'], PHP_EOL;
    echo 'Recebiveis atualizados: ' . (int)$resultadoAlelo['registros_atualizados'], PHP_EOL;
    echo 'Pagamentos processados: ' . (int)$resultadoAlelo['pagamentos_processados'], PHP_EOL;
    echo 'Registros ignorados: ' . (int)$resultadoAlelo['registros_ignorados'], PHP_EOL;
} catch (Throwable $e) {
    $houveErro = true;
    fwrite(STDERR, date('Y-m-d H:i:s') . ' - ERRO ALELO Comercial: ' . $e->getMessage() . PHP_EOL);
}

exit($houveErro ? 1 : 0);

