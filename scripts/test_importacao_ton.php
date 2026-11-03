<?php

require dirname(__DIR__) . '/config/importacao_ton.php';

function tonTeste(bool $condicao, string $mensagem): void
{
    if (!$condicao) {
        throw new RuntimeException($mensagem);
    }
}

$regra = [
    'cm_debito' => 3,
    'cm_credito' => 2,
    'cm_pix' => 12,
    'estabelecimento' => 'PB0921C678651,6C229257',
];

$cabecalhoAntigo = [
    'Id da transação', 'Data da transação', 'Valor', 'Taxa', 'Desconto',
    'Valor recebido', 'Status', 'Nome do titular', 'Bandeira',
    'Método de pagamento', 'Parcelas', 'Cartão(últimos 4 dígitos)',
    'Método de captura', 'Tipo de cartão', 'Nº de série',
];
$antigo = tonPrepararVendas([
    $cabecalhoAntigo,
    ['tx-antiga', '02/11/2026 10:30', '10,00', '0,20', '0,20', '9,80', 'Paga', 'CLIENTE', 'Visa', 'Cartão de Crédito', '1', '1234', 'POS', 'Crédito', 'PB0921C678651'],
], $regra);
$registroAntigo = reset($antigo['registros']);
tonTeste($antigo['layout'] === 'antigo', 'Layout antigo nao reconhecido.');
tonTeste($registroAntigo['cm'] === 2, 'Credito antigo nao classificado no CM 2.');
tonTeste($registroAntigo['data_prevista'] === '2026-11-03', 'D+1 do layout antigo incorreto.');
tonTeste(abs($registroAntigo['valor_liquido'] - 9.80) < 0.001, 'Liquido antigo incorreto.');

$cabecalhoNovo = [
    'IDENTIFICADOR DA COBRANÇA', 'IDENTIFICADOR DA TRANSAÇÃO', 'DATA DA AUTORIZAÇÃO',
    'CÓDIGO DE AUTORIZAÇÃO', 'STATUS DO ANTIFRAUDE', 'DATA DE CAPTURA', 'MEIO DE CAPTURA',
    'NÚMERO DE SÉRIE', 'NÚMERO DO CARTÃO', 'FORMA DE PAGAMENTO', 'BANDEIRA',
    'NÚMERO DE PARCELAS', 'VALOR BRUTO', 'VALOR ESTORNADO', 'VALOR BRUTO ATUALIZADO',
    'DESCONTO DE VENDA', 'DESCONTO DE ANTECIPAÇÃO', 'DESCONTO UNIFICADO',
    'VALOR LÍQUIDO', 'ADQUIRENTE', 'ÚLTIMO STATUS', 'DATA DO ÚLTIMO STATUS',
];
$novo = tonPrepararVendas([
    $cabecalhoNovo,
    ['cobranca-pix', 'nsu-pix', '02/11/2026 11:00', '', '', '02/11/2026 11:00', 'POS', 'PB0921C678651', '', 'Pix QRcode', '', '', '20,00', '0,00', '20,00', '0,00', '0,00', '0,00', '20,00', '', 'Aprovada', '02/11/2026 11:00'],
    ['cobranca-cartao', '', '02/11/2026 12:00', '123456', '', '02/11/2026 12:00', 'POS', 'PB0921C678651', '411111******9876', 'Debito Pre-pago', 'MasterCard', '1', '10,00', '0,00', '10,00', '0,00', '0,00', '-0,10', '9,90', '', 'Aprovada', '02/11/2026 12:00'],
], $regra);
tonTeste($novo['layout'] === 'novo', 'Layout novo nao reconhecido.');
tonTeste(count($novo['registros']) === 2, 'Quantidade do layout novo incorreta.');
tonTeste($novo['registros']['TON-cobranca-pix']['cm'] === 12, 'PIX novo nao classificado no CM 12.');
tonTeste($novo['registros']['TON-cobranca-pix']['data_prevista'] === '2026-11-02', 'Data prevista do PIX incorreta.');
tonTeste($novo['registros']['TON-cobranca-cartao']['cm'] === 3, 'Debito pre-pago nao classificado no CM 3.');
tonTeste($novo['registros']['TON-cobranca-cartao']['data_prevista'] === '2026-11-03', 'D+1 do debito incorreto.');
tonTeste(abs($novo['registros']['TON-cobranca-cartao']['valor_desconto'] - 0.10) < 0.001, 'Desconto novo incorreto.');
tonTeste($novo['registros']['TON-cobranca-cartao']['nsu_transacao'] === 'cobranca-cartao', 'Fallback do NSU incorreto.');
tonTeste(strpos($novo['registros']['TON-cobranca-cartao']['descricao'], 'Final 9876') !== false, 'Final do cartao nao extraido.');

$terminalBloqueado = false;
try {
    $linhaInvalida = $novo;
    $dados = [
        $cabecalhoNovo,
        ['outra-cobranca', '', '02/11/2026 13:00', '', '', '02/11/2026 13:00', 'POS', 'TERMINAL-INVALIDO', '', 'Pix QRcode', '', '', '5,00', '0,00', '5,00', '0,00', '0,00', '0,00', '5,00', '', 'Aprovada', '02/11/2026 13:00'],
    ];
    tonPrepararVendas($dados, $regra);
} catch (RuntimeException $e) {
    $terminalBloqueado = strpos($e->getMessage(), 'nao pertence') !== false;
}
tonTeste($terminalBloqueado, 'Terminal de outra empresa nao foi bloqueado.');

echo "Testes da importacao TON concluidos com sucesso.\n";
