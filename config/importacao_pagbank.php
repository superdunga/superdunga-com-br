<?php

if (defined('IMPORTACAO_PAGBANK_CARREGADA')) {
    return;
}

define('IMPORTACAO_PAGBANK_CARREGADA', true);

require_once __DIR__ . '/importacao_ton.php';

function pagbankNormalizarTexto(string $valor): string
{
    $valor = strtr(trim($valor), [
        'á'=>'a', 'à'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a',
        'Á'=>'A', 'À'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A',
        'é'=>'e', 'è'=>'e', 'ê'=>'e', 'ë'=>'e',
        'É'=>'E', 'È'=>'E', 'Ê'=>'E', 'Ë'=>'E',
        'í'=>'i', 'ì'=>'i', 'î'=>'i', 'ï'=>'i',
        'Í'=>'I', 'Ì'=>'I', 'Î'=>'I', 'Ï'=>'I',
        'ó'=>'o', 'ò'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o',
        'Ó'=>'O', 'Ò'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O',
        'ú'=>'u', 'ù'=>'u', 'û'=>'u', 'ü'=>'u',
        'Ú'=>'U', 'Ù'=>'U', 'Û'=>'U', 'Ü'=>'U',
        'ç'=>'c', 'Ç'=>'C',
    ]);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
    $texto = strtoupper($ascii !== false ? $ascii : trim($valor));
    $texto = preg_replace('/[^A-Z0-9]+/', ' ', $texto);
    return trim((string)$texto);
}

function pagbankValor(string $valor): float
{
    $valor = trim($valor);
    if ($valor === '') {
        return 0.0;
    }

    if (strpos($valor, ',') !== false) {
        $valor = str_replace(['R$', ' ', '.'], '', $valor);
        $valor = str_replace(',', '.', $valor);
    } else {
        $valor = str_replace(['R$', ' '], '', $valor);
    }

    if (!is_numeric($valor)) {
        throw new RuntimeException('Valor monetario invalido no arquivo PagBank: ' . $valor);
    }

    return round((float)$valor, 2);
}

function pagbankDataHora(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }

    if (is_numeric($valor)) {
        $serial = (float)$valor;
        $dias = (int)floor($serial);
        $segundos = (int)round(($serial - $dias) * 86400);
        $data = new DateTime('1899-12-30 00:00:00');
        $data->modify('+' . $dias . ' days');
        $data->modify('+' . $segundos . ' seconds');
        return $data->format('Y-m-d H:i:s');
    }

    foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y'] as $formato) {
        $data = DateTime::createFromFormat($formato, $valor);
        $erros = DateTime::getLastErrors();
        if ($data && (!is_array($erros) || ($erros['warning_count'] === 0 && $erros['error_count'] === 0))) {
            return $data->format('Y-m-d H:i:s');
        }
    }

    return null;
}

function pagbankTotalParcelas(string $valor): int
{
    $normalizado = pagbankNormalizarTexto($valor);
    if ($normalizado === '' || strpos($normalizado, 'VISTA') !== false) {
        return 1;
    }

    preg_match_all('/\d+/', $normalizado, $numeros);
    if (empty($numeros[0])) {
        return 1;
    }

    return max(1, (int)end($numeros[0]));
}

function pagbankClassificarPagamento(string $forma, array $regra): array
{
    $normalizado = pagbankNormalizarTexto($forma);
    if (strpos($normalizado, 'DEBITO') !== false) {
        return ['cm' => (int)$regra['cm_debito'], 'tipo' => 'D'];
    }
    if (strpos($normalizado, 'CREDITO') !== false) {
        return ['cm' => (int)$regra['cm_credito'], 'tipo' => 'C'];
    }
    if (strpos($normalizado, 'PIX') !== false) {
        return ['cm' => (int)$regra['cm_pix'], 'tipo' => 'P'];
    }

    throw new RuntimeException('Forma de pagamento PagBank nao reconhecida: ' . $forma);
}

function pagbankLerRegistros(string $arquivo, array $regra): array
{
    $linhas = tonLerXlsx($arquivo);
    if (count($linhas) < 2) {
        throw new RuntimeException('O arquivo PagBank nao possui vendas para importar.');
    }

    $indices = [];
    foreach ($linhas[0] as $indice => $cabecalho) {
        $indices[pagbankNormalizarTexto((string)$cabecalho)] = $indice;
    }

    $obrigatorios = [
        'NOME CLIENTE', 'CODIGO DA TRANSACAO', 'DATA DA TRANSACAO',
        'DATA PREVISTA DE LIBERACAO', 'BANDEIRA', 'FORMA DE PAGAMENTO',
        'PARCELA', 'VALOR BRUTO', 'VALOR TAXA', 'VALOR LIQUIDO', 'STATUS',
        'CODIGO NSU', 'IDENTIFICACAO DA MAQUININHA',
    ];
    foreach ($obrigatorios as $cabecalho) {
        if (!array_key_exists($cabecalho, $indices)) {
            throw new RuntimeException('Importacao bloqueada: coluna obrigatoria ausente no arquivo PagBank: ' . $cabecalho . '.');
        }
    }

    $valorColuna = function (array $linha, string $cabecalho) use ($indices): string {
        if (!isset($indices[$cabecalho])) {
            return '';
        }
        return trim((string)($linha[$indices[$cabecalho]] ?? ''));
    };

    $clienteEsperado = pagbankNormalizarTexto((string)($regra['estabelecimento'] ?? ''));
    $registros = [];
    $ignoradosStatus = 0;

    foreach (array_slice($linhas, 1) as $indiceLinha => $linha) {
        $numeroLinha = $indiceLinha + 2;
        if (count(array_filter($linha, function ($valor) { return trim((string)$valor) !== ''; })) === 0) {
            continue;
        }

        $status = $valorColuna($linha, 'STATUS');
        if (pagbankNormalizarTexto($status) !== 'APROVADA') {
            $ignoradosStatus++;
            continue;
        }

        $cliente = $valorColuna($linha, 'NOME CLIENTE');
        if ($clienteEsperado !== '' && pagbankNormalizarTexto($cliente) !== $clienteEsperado) {
            throw new RuntimeException("Linha {$numeroLinha}: cliente PagBank nao pertence a esta empresa.");
        }

        $idTransacao = $valorColuna($linha, 'CODIGO DA TRANSACAO');
        $dataVenda = pagbankDataHora($valorColuna($linha, 'DATA DA TRANSACAO'));
        $dataPrevistaArquivo = pagbankDataHora($valorColuna($linha, 'DATA PREVISTA DE LIBERACAO'));
        $formaPagamento = $valorColuna($linha, 'FORMA DE PAGAMENTO');
        $valorBruto = pagbankValor($valorColuna($linha, 'VALOR BRUTO'));
        $valorTaxa = pagbankValor($valorColuna($linha, 'VALOR TAXA'));
        $valorLiquido = pagbankValor($valorColuna($linha, 'VALOR LIQUIDO'));

        if ($idTransacao === '' || $dataVenda === null || $valorBruto <= 0) {
            throw new RuntimeException("Linha {$numeroLinha}: identificador, data ou valor da venda invalido.");
        }
        if (abs(($valorBruto - $valorTaxa) - $valorLiquido) > 0.01) {
            throw new RuntimeException("Linha {$numeroLinha}: valor liquido difere do valor bruto menos a taxa.");
        }

        $classificacao = pagbankClassificarPagamento($formaPagamento, $regra);
        if ($classificacao['cm'] <= 0) {
            throw new RuntimeException("Linha {$numeroLinha}: CM nao configurado para {$formaPagamento}.");
        }

        if ($dataPrevistaArquivo !== null) {
            $dataPrevista = substr($dataPrevistaArquivo, 0, 10);
        } else {
            $dataPrevista = substr($dataVenda, 0, 10);
            if ($classificacao['tipo'] !== 'P') {
                $dataPrevista = date('Y-m-d', strtotime($dataPrevista . ' +1 day'));
            }
        }

        $identificador = 'PAGBANK-' . $idTransacao;
        if (isset($registros[$identificador])) {
            throw new RuntimeException("Linha {$numeroLinha}: codigo da transacao repetido dentro do arquivo.");
        }

        $bandeira = $valorColuna($linha, 'BANDEIRA');
        $numeroCartao = $valorColuna($linha, 'NUMERO DO CARTAO');
        $codigoVenda = $valorColuna($linha, 'CODIGO DA VENDA');
        $nomeComprador = $valorColuna($linha, 'NOME COMPRADOR');
        $txidPix = $valorColuna($linha, 'CODIGO TX ID PIX');
        $nsu = $valorColuna($linha, 'CODIGO NSU');
        $terminal = $valorColuna($linha, 'IDENTIFICACAO DA MAQUININHA');
        $descricaoPartes = array_filter([
            'PagBank', $formaPagamento, $bandeira,
            $numeroCartao !== '' ? 'Cartao ' . $numeroCartao : '',
            $codigoVenda !== '' ? 'Venda ' . $codigoVenda : '',
        ]);

        $registros[$identificador] = [
            'id_transacao' => $idTransacao,
            'data_venda' => $dataVenda,
            'data_prevista' => $dataPrevista,
            'valor_bruto' => $valorBruto,
            'valor_desconto' => $valorTaxa,
            'valor_liquido' => $valorLiquido,
            'identificador' => $identificador,
            'descricao' => implode(' - ', $descricaoPartes),
            'pagador' => $nomeComprador !== '' ? $nomeComprador : 'PAGBANK',
            'total_parcelas' => pagbankTotalParcelas($valorColuna($linha, 'PARCELA')),
            'status' => $status,
            'cm' => $classificacao['cm'],
            'tipo_operacao' => $classificacao['tipo'],
            'bandeira' => $bandeira,
            'nsu' => $nsu !== '' ? $nsu : ($txidPix !== '' ? $txidPix : $idTransacao),
            'terminal' => $terminal,
            'txid_pix' => $txidPix,
        ];
    }

    if (!$registros) {
        throw new RuntimeException('Nenhuma venda aprovada foi encontrada no arquivo PagBank.');
    }

    return ['registros' => $registros, 'ignorados_status' => $ignoradosStatus];
}
