<?php

require dirname(__DIR__) . '/config/alelo_edi.php';

function linhaAlelo(array $campos): string
{
    $linha = str_repeat(' ', 500);
    foreach ($campos as $campo) {
        $inicio = (int)$campo[0];
        $tamanho = (int)$campo[1];
        $valor = substr(str_pad((string)$campo[2], $tamanho, ' ', STR_PAD_RIGHT), 0, $tamanho);
        $linha = substr_replace($linha, $valor, $inicio - 1, $tamanho);
    }
    return $linha;
}

function conferirAlelo($condicao, string $mensagem): void
{
    if (!$condicao) {
        throw new RuntimeException($mensagem);
    }
}

$headerVenda = linhaAlelo([
    [1, 2, '00'], [3, 15, '6000317874'], [18, 8, '20261106'],
    [26, 8, '20261105'], [34, 8, '20261105'], [43, 7, '0000002'],
    [50, 5, 'ALELO'], [55, 2, '01'], [107, 5, '0021'], [112, 14, '58742902000183'],
]);
$venda = linhaAlelo([
    [1, 2, '01'], [3, 30, 'CHAVE-PAGAMENTO-TESTE'], [48, 15, '123456789012345'],
    [71, 3, '01'], [111, 8, '20261105'], [119, 6, '134500'], [125, 8, '20261106'],
    [133, 1, '+'], [134, 13, '0000004990000'], [147, 1, '+'], [148, 13, '0000004840300'],
    [201, 4, '0048'], [209, 3, '007'], [234, 8, 'TERM0001'], [242, 8, 'AUT00001'],
    [250, 6, '123456'], [279, 18, 'MOVIMENTO-00000001'],
]);
$trailerVenda = linhaAlelo([[1, 2, '99']]);
$arquivoVenda = aleloLerArquivo(implode("\r\n", [$headerVenda, $venda, $trailerVenda]), [
    'origem' => 'ALELO_EDI_COMERCIAL', 'cm_debito' => 15,
]);

conferirAlelo(count($arquivoVenda['registros']) === 1, 'A venda ALELO nao foi interpretada.');
$registro = $arquivoVenda['registros'][0];
conferirAlelo($registro['data_venda'] === '2026-11-05 13:45:00', 'Data da venda incorreta.');
conferirAlelo($registro['data_prevista'] === '2026-11-06', 'Data prevista incorreta.');
conferirAlelo(abs($registro['valor_bruto'] - 4.99) < 0.001, 'Valor bruto incorreto.');
conferirAlelo(abs($registro['valor_liquido'] - 4.84) < 0.001, 'Valor liquido incorreto.');
conferirAlelo($registro['cm'] === 15, 'CM incorreto.');
conferirAlelo($registro['descricao'] === 'ALELO - ALIMENTACAO PAT', 'Descricao incorreta.');

$headerPagamento = linhaAlelo([
    [1, 2, '00'], [3, 15, '6000317874'], [18, 8, '20261106'],
    [26, 8, '20261105'], [34, 8, '20261105'], [43, 7, '0000002'],
    [50, 5, 'ALELO'], [55, 2, '02'], [107, 5, '0021'], [112, 14, '58742902000183'],
]);
$pagamento = linhaAlelo([
    [1, 2, '02'], [3, 15, '123456789012345'], [26, 15, '6000317874'],
    [41, 30, 'CHAVE-PAGAMENTO-TESTE'], [71, 1, 'R'], [72, 3, '003'],
    [81, 8, '20261106'], [89, 8, '20261106'], [97, 8, '20261106'],
    [105, 1, '+'], [106, 18, '000000000004990000'],
]);
$arquivoPagamento = aleloLerArquivo(implode("\n", [$headerPagamento, $pagamento, $trailerVenda]), [
    'origem' => 'ALELO_EDI_COMERCIAL', 'cm_debito' => 15,
]);
conferirAlelo(count($arquivoPagamento['pagamentos']) === 1, 'O pagamento ALELO nao foi interpretado.');
conferirAlelo(abs($arquivoPagamento['pagamentos'][0]['valor'] - 4.99) < 0.001, 'Valor do pagamento incorreto.');

echo "ALELO EDI: testes concluidos com sucesso.\n";
