<?php

if (defined('IMPORTACAO_TON_CARREGADA')) {
    return;
}

define('IMPORTACAO_TON_CARREGADA', true);

function tonIndiceColuna(string $referencia): int
{
    if (!preg_match('/^([A-Z]+)/i', $referencia, $matches)) {
        return 0;
    }

    $indice = 0;
    $letras = strtoupper($matches[1]);
    for ($i = 0; $i < strlen($letras); $i++) {
        $indice = ($indice * 26) + (ord($letras[$i]) - 64);
    }

    return max(0, $indice - 1);
}

function tonLerXlsx(string $arquivo): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('A extensao ZipArchive nao esta habilitada no servidor.');
    }

    $zip = new ZipArchive();
    if ($zip->open($arquivo) !== true) {
        throw new RuntimeException('O arquivo enviado nao e um XLSX valido.');
    }

    try {
        $compartilhadas = [];
        $xmlCompartilhadas = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlCompartilhadas !== false) {
            $domCompartilhadas = new DOMDocument();
            if (!$domCompartilhadas->loadXML($xmlCompartilhadas, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('Nao foi possivel ler os textos do arquivo XLSX.');
            }
            $xpathCompartilhadas = new DOMXPath($domCompartilhadas);
            $xpathCompartilhadas->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($xpathCompartilhadas->query('//x:si') as $item) {
                $texto = '';
                foreach ($xpathCompartilhadas->query('.//x:t', $item) as $parte) {
                    $texto .= $parte->nodeValue;
                }
                $compartilhadas[] = $texto;
            }
        }

        $planilha = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet[0-9]+\.xml$#', $nome)) {
                $planilha = $nome;
                break;
            }
        }
        if ($planilha === null) {
            throw new RuntimeException('O XLSX nao possui uma planilha de dados.');
        }

        $xmlPlanilha = $zip->getFromName($planilha);
        $domPlanilha = new DOMDocument();
        if ($xmlPlanilha === false || !$domPlanilha->loadXML($xmlPlanilha, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Nao foi possivel ler a planilha do arquivo XLSX.');
        }

        $xpath = new DOMXPath($domPlanilha);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $linhas = [];

        foreach ($xpath->query('//x:sheetData/x:row') as $noLinha) {
            $linha = [];
            $indiceSequencial = 0;
            foreach ($xpath->query('./x:c', $noLinha) as $celula) {
                $referencia = $celula->getAttribute('r');
                $indice = $referencia !== ''
                    ? tonIndiceColuna($referencia)
                    : $indiceSequencial;
                $tipo = $celula->getAttribute('t');
                $valor = '';

                if ($tipo === 'inlineStr') {
                    foreach ($xpath->query('.//x:t', $celula) as $parte) {
                        $valor .= $parte->nodeValue;
                    }
                } else {
                    $nosValor = $xpath->query('./x:v', $celula);
                    if ($nosValor->length > 0) {
                        $valor = $nosValor->item(0)->nodeValue;
                    }
                    if ($tipo === 's') {
                        $valor = $compartilhadas[(int)$valor] ?? '';
                    }
                }

                $linha[$indice] = trim((string)$valor);
                $indiceSequencial = $indice + 1;
            }

            if ($linha) {
                $maiorIndice = max(array_keys($linha));
                $linhas[] = array_replace(array_fill(0, $maiorIndice + 1, ''), $linha);
            }
        }

        return $linhas;
    } finally {
        $zip->close();
    }
}

function tonValor(string $valor): float
{
    $normalizado = str_replace(['R$', ' ', '.'], '', trim($valor));
    $normalizado = str_replace(',', '.', $normalizado);
    if ($normalizado === '' || !is_numeric($normalizado)) {
        throw new RuntimeException('Valor monetario invalido no arquivo TON: ' . $valor);
    }
    return round((float)$normalizado, 2);
}

function tonDataHora(string $valor): ?string
{
    $data = DateTime::createFromFormat('d/m/Y H:i', trim($valor));
    $erros = DateTime::getLastErrors();
    if (!$data || (is_array($erros) && ($erros['warning_count'] > 0 || $erros['error_count'] > 0))) {
        return null;
    }
    return $data->format('Y-m-d H:i:s');
}

function tonClassificarPagamento(string $metodo, array $regra): array
{
    $metodo = trim($metodo);
    if (in_array($metodo, ['Cartao de Debito', 'Cartão de Débito', 'Debito', 'Débito', 'Debito Pre-pago', 'Débito Pré-pago'], true)) {
        return ['cm' => (int)$regra['cm_debito'], 'tipo' => 'D'];
    }
    if (in_array($metodo, ['Cartao de Credito', 'Cartão de Crédito', 'Credito', 'Crédito'], true)) {
        return ['cm' => (int)$regra['cm_credito'], 'tipo' => 'C'];
    }
    if (in_array(mb_strtolower($metodo), ['pix', 'pix qrcode', 'pix qr code'], true)) {
        return ['cm' => (int)$regra['cm_pix'], 'tipo' => 'P'];
    }

    throw new RuntimeException('Metodo de pagamento TON nao reconhecido: ' . $metodo);
}

function tonNormalizarCabecalho(string $cabecalho): string
{
    $cabecalho = mb_strtoupper(trim($cabecalho));
    $cabecalho = strtr($cabecalho, [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N', 'º' => '', 'ª' => '',
    ]);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cabecalho);
    if ($ascii !== false) {
        $cabecalho = $ascii;
    }

    return trim((string)preg_replace('/[^A-Z0-9]+/', '_', $cabecalho), '_');
}

function tonMapearCabecalhos(array $cabecalhos): array
{
    $mapa = [];
    foreach ($cabecalhos as $indice => $cabecalho) {
        $chave = tonNormalizarCabecalho((string)$cabecalho);
        if ($chave !== '') {
            $mapa[$chave] = $indice;
        }
    }
    return $mapa;
}

function tonValorColuna(array $linha, array $mapa, string $coluna): string
{
    if (!isset($mapa[$coluna])) {
        return '';
    }
    return trim((string)($linha[$mapa[$coluna]] ?? ''));
}

function tonFinalCartao(string $numeroCartao): string
{
    $digitos = preg_replace('/\D/', '', $numeroCartao);
    return strlen($digitos) >= 4 ? substr($digitos, -4) : '';
}

function tonPrepararVendas(array $linhas, array $regra): array
{
    if (count($linhas) < 2) {
        throw new RuntimeException('O arquivo TON nao possui vendas para importar.');
    }

    $cabecalhosAntigos = [
        'Id da transação', 'Data da transação', 'Valor', 'Taxa', 'Desconto',
        'Valor recebido', 'Status', 'Nome do titular', 'Bandeira',
        'Método de pagamento', 'Parcelas', 'Cartão(últimos 4 dígitos)',
        'Método de captura', 'Tipo de cartão', 'Nº de série',
    ];
    $cabecalhos = array_map('trim', $linhas[0]);
    $layoutAntigo = array_slice($cabecalhos, 0, count($cabecalhosAntigos)) === $cabecalhosAntigos;
    $mapa = tonMapearCabecalhos($cabecalhos);
    $layoutNovo = isset($mapa['IDENTIFICADOR_DA_COBRANCA'], $mapa['DATA_DE_CAPTURA'], $mapa['VALOR_BRUTO_ATUALIZADO'], $mapa['VALOR_LIQUIDO']);

    if (!$layoutAntigo && !$layoutNovo) {
        throw new RuntimeException('Importacao bloqueada: as colunas nao correspondem a um layout TON reconhecido.');
    }

    if ($layoutNovo) {
        $obrigatorias = [
            'IDENTIFICADOR_DA_COBRANCA', 'DATA_DE_CAPTURA', 'NUMERO_DE_SERIE',
            'NUMERO_DO_CARTAO', 'FORMA_DE_PAGAMENTO', 'BANDEIRA',
            'NUMERO_DE_PARCELAS', 'VALOR_BRUTO_ATUALIZADO', 'VALOR_LIQUIDO',
            'ULTIMO_STATUS',
        ];
        foreach ($obrigatorias as $coluna) {
            if (!isset($mapa[$coluna])) {
                throw new RuntimeException('Importacao bloqueada: coluna TON ausente: ' . $coluna . '.');
            }
        }
    }

    $terminaisPermitidos = preg_split('/\s*[,;]\s*/', (string)($regra['estabelecimento'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $registros = [];
    $ignoradosStatus = 0;

    foreach (array_slice($linhas, 1) as $indice => $linha) {
        $numeroLinha = $indice + 2;
        if (count(array_filter($linha, function ($valor) { return trim((string)$valor) !== ''; })) === 0) {
            continue;
        }

        if ($layoutNovo) {
            $idTransacao = tonValorColuna($linha, $mapa, 'IDENTIFICADOR_DA_COBRANCA');
            $nsuTransacao = tonValorColuna($linha, $mapa, 'IDENTIFICADOR_DA_TRANSACAO');
            $dataVenda = tonDataHora(tonValorColuna($linha, $mapa, 'DATA_DE_CAPTURA'));
            $valorBruto = tonValor(tonValorColuna($linha, $mapa, 'VALOR_BRUTO_ATUALIZADO'));
            $valorLiquido = tonValor(tonValorColuna($linha, $mapa, 'VALOR_LIQUIDO'));
            $valorDesconto = round($valorBruto - $valorLiquido, 2);
            $status = tonValorColuna($linha, $mapa, 'ULTIMO_STATUS');
            $pagador = 'TON';
            $bandeira = tonValorColuna($linha, $mapa, 'BANDEIRA');
            $metodo = tonValorColuna($linha, $mapa, 'FORMA_DE_PAGAMENTO');
            $totalParcelas = max(1, (int)tonValorColuna($linha, $mapa, 'NUMERO_DE_PARCELAS'));
            $finalCartao = tonFinalCartao(tonValorColuna($linha, $mapa, 'NUMERO_DO_CARTAO'));
            $tipoCartao = '';
            $terminal = tonValorColuna($linha, $mapa, 'NUMERO_DE_SERIE');
            $statusAceito = strcasecmp($status, 'Aprovada') === 0;
        } else {
            $linha = array_pad($linha, count($cabecalhosAntigos), '');
            $idTransacao = trim((string)$linha[0]);
            $nsuTransacao = $idTransacao;
            $dataVenda = tonDataHora((string)$linha[1]);
            $valorBruto = tonValor((string)$linha[2]);
            $valorDesconto = tonValor((string)$linha[4]);
            $valorLiquido = tonValor((string)$linha[5]);
            $status = trim((string)$linha[6]);
            $pagador = trim((string)$linha[7]);
            $bandeira = trim((string)$linha[8]);
            $metodo = trim((string)$linha[9]);
            $totalParcelas = max(1, (int)$linha[10]);
            $finalCartao = trim((string)$linha[11]);
            $tipoCartao = trim((string)$linha[13]);
            $terminal = trim((string)$linha[14]);
            $statusAceito = strcasecmp($status, 'Paga') === 0;
        }

        if (!$statusAceito) {
            $ignoradosStatus++;
            continue;
        }
        if ($idTransacao === '' || $dataVenda === null || $valorBruto <= 0 || $valorLiquido <= 0) {
            throw new RuntimeException("Linha {$numeroLinha}: identificador, data ou valor da venda invalido.");
        }
        if ($valorDesconto < -0.01 || abs(($valorBruto - $valorDesconto) - $valorLiquido) > 0.01) {
            throw new RuntimeException("Linha {$numeroLinha}: valor liquido nao confere com o valor bruto atualizado.");
        }
        if ($terminaisPermitidos && !in_array($terminal, $terminaisPermitidos, true)) {
            throw new RuntimeException("Linha {$numeroLinha}: terminal TON nao pertence a esta empresa.");
        }

        $classificacao = tonClassificarPagamento($metodo, $regra);
        if ($classificacao['cm'] <= 0) {
            throw new RuntimeException("Linha {$numeroLinha}: CM nao configurado para {$metodo}.");
        }
        $dataPrevista = substr($dataVenda, 0, 10);
        if (in_array($classificacao['cm'], [2, 3], true)) {
            $dataPrevista = date('Y-m-d', strtotime($dataPrevista . ' +1 day'));
        }

        $identificador = 'TON-' . $idTransacao;
        if (isset($registros[$identificador])) {
            throw new RuntimeException("Linha {$numeroLinha}: identificador da cobranca repetido dentro do arquivo.");
        }

        $descricaoPartes = array_filter(['TON', $metodo, $tipoCartao, $bandeira, $finalCartao !== '' ? 'Final ' . $finalCartao : '']);
        $registros[$identificador] = [
            'id_transacao' => $idTransacao,
            'nsu_transacao' => $nsuTransacao !== '' ? $nsuTransacao : $idTransacao,
            'data_venda' => $dataVenda,
            'data_prevista' => $dataPrevista,
            'valor_bruto' => $valorBruto,
            'valor_desconto' => $valorDesconto,
            'valor_liquido' => $valorLiquido,
            'identificador' => $identificador,
            'descricao' => implode(' - ', $descricaoPartes),
            'pagador' => $pagador !== '' ? $pagador : 'TON',
            'total_parcelas' => $totalParcelas,
            'status' => $status,
            'cm' => $classificacao['cm'],
            'tipo_operacao' => $classificacao['tipo'],
            'bandeira' => $bandeira,
            'terminal' => $terminal,
        ];
    }

    if (!$registros) {
        throw new RuntimeException('Nenhuma venda aprovada ou paga foi encontrada no arquivo TON.');
    }

    return [
        'layout' => $layoutNovo ? 'novo' : 'antigo',
        'registros' => $registros,
        'ignorados_status' => $ignoradosStatus,
    ];
}
