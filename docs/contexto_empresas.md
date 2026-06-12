# Contexto das Empresas

## Empresa 2

- A empresa 2 nao possui banco Firebird proprio.
- Ela utiliza a estrutura das tabelas espelhadas do SuperDunga, como `armazem_cp001`, `armazem_cp003`, `armazem_bnc001` e demais tabelas `armazem_*`.
- Rotinas criadas para a empresa 2 nao devem presumir sincronizacao direta com Firebird local.
- Cadastros e lancamentos gerados pelo proprio SuperDunga para a empresa 2 devem gravar diretamente nas tabelas espelhadas, mantendo os campos esperados pelo padrao do sistema.

## Impacto no modulo Cartao de Credito

- A importacao de fatura de cartao da empresa 2 grava os lancamentos diretamente nas tabelas espelhadas.
- Fornecedores criados ou amarrados pelo modulo usam `armazem_cp003` da propria empresa 2.
- Contas a pagar geradas pela fatura usam `armazem_cp001` da propria empresa 2.
- Como nao ha Firebird da empresa 2 para corrigir ou recriar esses dados depois, a rotina deve evitar duplicidades e preservar os vinculos no proprio SuperDunga.
