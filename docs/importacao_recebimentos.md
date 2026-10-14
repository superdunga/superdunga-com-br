# Importacao de recebimentos

## Prioridade da data prevista

A fonte de `data_prevista` segue esta ordem obrigatoria:

1. Se o arquivo da adquirente informar uma data prevista valida, usar exatamente
   essa data, sem hora e minuto.
2. Somente quando o layout nao possuir data prevista, aplicar a regra calculada
   para a origem e o `CMCONTADOR`.
3. Se o layout exigir a data e o valor estiver vazio ou invalido, a importacao
   deve ser bloqueada; nao substituir silenciosamente por uma data generica.

Uma data prevista fornecida pelo arquivo nunca deve ser sobrescrita por uma
regra geral D+1 ou D+30.

## Regras atuais

- SIPAG POS Comercial e Outros: usa a data prevista fornecida no arquivo.
- SIPAG PIX Comercial e Outros: o layout nao fornece previsao separada; usa a
  data da venda, sem hora e minuto.
- PagSeguro PIX: o layout nao fornece previsao separada; usa a data da venda,
  sem hora e minuto.
- TON Comercial: o layout nao fornece previsao separada; CM 2 e 3 usam D+1 e
  CM 12 usa a data da venda.
- Granito POS Comercial e Outros: o arquivo de transacoes nao fornece previsao;
  CM 2 e 14 usam D+30 e CM 3 e 6 usam D+1.
- Granito PIX: usa a data de pagamento informada na agenda.
