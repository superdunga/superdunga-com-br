# Arquitetura das integracoes Firebird

Este documento e a referencia para identificar qual servidor executa cada
integracao do SuperDunga. Os servidores auxiliares e os servidores principais
sao ambientes diferentes e nao podem ser tratados como equivalentes.

## Matriz de responsabilidades

| Ambiente | Empresas | Fluxos executados |
| --- | --- | --- |
| Auxiliar Armazem | Origem Firebird 1 -> SuperDunga 1 | Somente `EST007` e `EST026`, nas cargas completa e incremental |
| Auxiliar Emporio | Origem Firebird 1 -> SuperDunga 4 | Somente `EST007` e `EST026`, nas cargas completa e incremental |
| Principal Armazem | Firebird 1 / SuperDunga 1 | Integracao geral Firebird -> SuperDunga e retorno das conciliacoes ao Firebird |
| Principal Emporio | Firebird 1 -> SuperDunga 4 e Firebird 6 -> SuperDunga 5 | Integracao geral Firebird -> SuperDunga e retorno das conciliacoes ao Firebird |

## Servidores auxiliares

O pacote `docs/integracao_firebird/auxiliar` e exclusivo da rotina de cupons e
notas fiscais.

O `sincronizar_auxiliar.ps1` processa somente:

- `est007_auxiliar`, com origem em `EST007`;
- `est026_auxiliar`, com origem em `EST026`.

Esses servidores nao executam:

- sincronizacao de `CR001` ou das demais tabelas financeiras;
- sincronizacao geral de estoque, produtos ou cadastros;
- envio de conciliacoes de recebiveis para o Firebird;
- atualizacao de `CHAVEINTEGRACAO`, `CMCONTADOR` ou `DTVENC` em `CR001`.

## Servidores principais

### Armazem

O servidor principal do Armazem usa os arquivos documentados em
`docs/integracao_firebird/armazem` e atende a empresa 1 do SuperDunga.

Responsabilidades:

- `enviar_dados.py`: integracao geral Firebird -> SuperDunga;
- rotinas rapida e completa;
- produtos, embalagens e estoque calculado;
- `enviar_conciliados.py`: retorno das conciliacoes SuperDunga -> Firebird.

### Emporio

O servidor principal do Emporio usa os arquivos documentados em
`docs/integracao_firebird/emporio`.

Mapeamentos:

- Firebird empresa 1 -> SuperDunga empresa 4;
- Firebird empresa 6 -> SuperDunga empresa 5.

Responsabilidades:

- `enviar_dados.py`: integracao geral Firebird -> SuperDunga;
- rotinas rapida e completa;
- produtos, embalagens e estoque calculado;
- `enviar_conciliados.py`: retorno das conciliacoes SuperDunga -> Firebird.

## Fluxo da conciliacao de recebiveis

1. O match e gravado no site SuperDunga.
2. O site deixa a conciliacao pendente para envio ao Firebird.
3. O `enviar_conciliados.py` do servidor principal correspondente consulta a
   fila.
4. O servidor principal atualiza o `CR001` no Firebird.
5. O processamento e confirmado no SuperDunga.

O servidor auxiliar de cupons fiscais nao participa desse fluxo.

## Regra de verificacao

Antes de informar qual servidor executou uma rotina, confirmar:

1. o caminho completo do script executado;
2. o nome da tarefa agendada ou do processo;
3. a empresa de origem no Firebird;
4. a empresa de destino no SuperDunga;
5. se o arquivo pertence ao pacote `auxiliar`, `armazem` ou `emporio`.

Nunca atribuir uma atualizacao ao servidor auxiliar apenas porque ele pertence
a mesma loja. Sem a identificacao do script e da maquina, a conclusao deve ser
apresentada como nao confirmada.
