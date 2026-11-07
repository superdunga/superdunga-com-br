<?php

require dirname(__DIR__) . '/config/pagseguro_ofx.php';

function conferirPagseguroOfx($condicao, string $mensagem): void
{
    if (!$condicao) {
        throw new RuntimeException($mensagem);
    }
}

$arquivo = tempnam(sys_get_temp_dir(), 'pagseguro_ofx_');
$conteudo = <<<'OFX'
OFXHEADER:100
DATA:OFXSGML
VERSION:100
<OFX>
<STATUS><CODE>0</CODE></STATUS>
<CURDEF>BRL</CURDEF>
<BANKACCTFROM><BANKID>290</BANKID><ACCTID>51679053-2</ACCTID></BANKACCTFROM>
<BANKTRANLIST>
<STMTTRN>
<TRNTYPE>IN</TRNTYPE>
<DTPOSTED>20261104123607[-3:BRT]</DTPOSTED>
<TRNAMT>90.96</TRNAMT>
<FITID>ca1a099f-0d78-44fa-b5fa-61a342f46669</FITID>
<MEMO>Pix recebido - Cliente Teste</MEMO>
</STMTTRN>
<STMTTRN>
<TRNTYPE>IN</TRNTYPE>
<DTPOSTED>20261107063421[-3:BRT]</DTPOSTED>
<TRNAMT>0.02</TRNAMT>
<FITID>b792f3c6-27da-0dac-bf3a-a9027801b075</FITID>
<MEMO>Rendimento da conta - Rendimento líquido</MEMO>
</STMTTRN>
</BANKTRANLIST>
</OFX>
OFX;
file_put_contents($arquivo, $conteudo);

[$registros, $erros] = pagseguroLerOfx($arquivo);
unlink($arquivo);

conferirPagseguroOfx($erros === [], 'O OFX válido retornou erros.');
conferirPagseguroOfx(count($registros) === 1, 'O filtro deve importar apenas Pix recebido.');
conferirPagseguroOfx($registros[0]['codigo'] === 'ca1a099f-0d78-44fa-b5fa-61a342f46669', 'FITID incorreto.');
conferirPagseguroOfx($registros[0]['data_hora'] === '2026-11-04 12:36:07', 'Data e hora incorretas.');
conferirPagseguroOfx(abs($registros[0]['valor'] - 90.96) < 0.001, 'Valor incorreto.');
conferirPagseguroOfx($registros[0]['descricao'] === 'Cliente Teste', 'Pagador incorreto.');

echo "PagSeguro OFX: testes concluídos com sucesso.\n";
