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
            foreach ($xpath->query('./x:c', $noLinha) as $celula) {
                $indice = tonIndiceColuna($celula->getAttribute('r'));
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
    if ($metodo === 'Cartao de Debito' || $metodo === 'Cartão de Débito') {
        return ['cm' => (int)$regra['cm_debito'], 'tipo' => 'D'];
    }
    if ($metodo === 'Cartao de Credito' || $metodo === 'Cartão de Crédito') {
        return ['cm' => (int)$regra['cm_credito'], 'tipo' => 'C'];
    }
    if (strcasecmp($metodo, 'Pix') === 0) {
        return ['cm' => (int)$regra['cm_pix'], 'tipo' => 'P'];
    }

    throw new RuntimeException('Metodo de pagamento TON nao reconhecido: ' . $metodo);
}
