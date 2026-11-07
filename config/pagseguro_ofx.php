<?php

function pagseguroCapturarTagOfx(string $conteudo, string $tag): string
{
    if (preg_match('/<' . preg_quote($tag, '/') . '>([^<\r\n]+)/i', $conteudo, $match)) {
        return trim((string)$match[1]);
    }
    return '';
}

function pagseguroLerOfx(string $arquivo): array
{
    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        return [[], ['Não foi possível ler o arquivo OFX.']];
    }

    $conteudoUtf8 = @mb_convert_encoding($conteudo, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1');
    if (is_string($conteudoUtf8) && $conteudoUtf8 !== '') {
        $conteudo = $conteudoUtf8;
    }

    $erros = [];
    $bankId = pagseguroCapturarTagOfx($conteudo, 'BANKID');
    $conta = pagseguroCapturarTagOfx($conteudo, 'ACCTID');
    $moeda = strtoupper(pagseguroCapturarTagOfx($conteudo, 'CURDEF'));
    $status = pagseguroCapturarTagOfx($conteudo, 'CODE');

    if ($bankId !== '290') {
        $erros[] = 'O OFX não pertence ao PagSeguro (BANKID 290).';
    }
    if (preg_replace('/\D/', '', $conta) !== '516790532') {
        $erros[] = 'O OFX não pertence à conta PagSeguro 51679053-2.';
    }
    if ($moeda !== 'BRL') {
        $erros[] = 'A moeda do OFX deve ser BRL.';
    }
    if ($status !== '' && $status !== '0') {
        $erros[] = 'O OFX informa erro de processamento (código ' . $status . ').';
    }

    if ($erros) {
        return [[], $erros];
    }

    preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $conteudo, $matches);
    $registros = [];
    foreach ($matches[1] as $indice => $bloco) {
        $tipoOfx = strtoupper(pagseguroCapturarTagOfx($bloco, 'TRNTYPE'));
        $memo = pagseguroCapturarTagOfx($bloco, 'MEMO');
        $valorRaw = pagseguroCapturarTagOfx($bloco, 'TRNAMT');
        $codigo = pagseguroCapturarTagOfx($bloco, 'FITID');
        $dataRaw = pagseguroCapturarTagOfx($bloco, 'DTPOSTED');

        if ($tipoOfx !== 'IN' || stripos($memo, 'Pix recebido -') !== 0) {
            continue;
        }
        if (!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $codigo)) {
            $erros[] = 'Lançamento OFX ' . ($indice + 1) . ': FITID inválido.';
            continue;
        }
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $dataRaw, $dataPartes)) {
            $erros[] = 'Lançamento OFX ' . ($indice + 1) . ': data inválida.';
            continue;
        }
        if (!is_numeric($valorRaw) || (float)$valorRaw <= 0) {
            $erros[] = 'Lançamento OFX ' . ($indice + 1) . ': valor inválido.';
            continue;
        }

        $dataHora = sprintf(
            '%s-%s-%s %s:%s:%s',
            $dataPartes[1],
            $dataPartes[2],
            $dataPartes[3],
            $dataPartes[4],
            $dataPartes[5],
            $dataPartes[6]
        );
        $dataObj = DateTime::createFromFormat('!Y-m-d H:i:s', $dataHora);
        if ($dataObj === false || $dataObj->format('Y-m-d H:i:s') !== $dataHora) {
            $erros[] = 'Lançamento OFX ' . ($indice + 1) . ': data inexistente.';
            continue;
        }

        $pagador = trim((string)preg_replace('/^Pix recebido\s*-\s*/i', '', $memo));
        if ($pagador === '') {
            $erros[] = 'Lançamento OFX ' . ($indice + 1) . ': pagador não informado.';
            continue;
        }

        $registros[] = [
            'codigo' => $codigo,
            'data' => $dataObj->format('d/m/Y'),
            'data_hora' => $dataHora,
            'tipo' => 'pix recebido',
            'descricao' => $pagador,
            'valor' => (float)$valorRaw,
        ];
    }

    if (!$registros && !$erros) {
        $erros[] = 'O arquivo OFX não possui recebimentos com a descrição Pix recebido.';
    }

    return [$registros, $erros];
}
