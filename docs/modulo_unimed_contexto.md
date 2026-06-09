# Modulo Unimed - Contexto do Projeto

## Objetivo

Centralizar o controle da Unimed no SuperDunga para:

- manter cadastro de beneficiarios;
- agrupar beneficiarios por familia;
- definir responsavel por pagamento;
- importar mensalidades por beneficiario;
- importar utilizacoes do plano;
- fechar valores por usuario, familia e responsavel;
- futuramente enviar informacoes por WhatsApp.

## Regra de acesso

O modulo Unimed foi criado como funcionalidade restrita a perfil `MASTER`.

Arquivos envolvidos:

- `config/modulos.php`
- `config/auth.php`
- `layout/header.php`
- `index.php`
- `modulos/unimed/menu_unimed.php`
- `modulos/unimed/cadastro.php`
- `modulos/unimed/beneficiario.php`
- `modulos/unimed/faturas.php`
- `modulos/unimed/_lib.php`

## Tabelas criadas

### `unimed_beneficiarios`

Cadastro dos usuarios da Unimed.

Campos principais:

- `empresa_id`
- `codigo_completo`
- `unidade_unimed`
- `contrato_unimed`
- `familia`
- `dependente`
- `tipo`
- `nome`
- `responsavel_pagamento_id`
- `telefone_whatsapp`
- `contrato_venda`
- `plano`
- `status_operacao`
- `ativo`

Observacao: o responsavel por pagamento deve sempre ser outro beneficiario cadastrado na mesma empresa.

### `unimed_faturas`

Cabecalho da fatura mensal.

Campos importantes:

- `competencia`: competencia da mensalidade.
- `numero_fatura`: numero da fatura mensal.
- `competencia_utilizacao`: competencia do arquivo de utilizacao, que pode ser diferente da mensalidade.
- `numero_fatura_utilizacao`: numero da fatura/analitico de utilizacao.
- `total_mensalidade`
- `total_utilizacao`
- `total_fatura`
- `arquivo_nome`
- `arquivo_utilizacao_nome`

### `unimed_fatura_itens`

Itens da mensalidade por beneficiario.

### `unimed_utilizacoes`

Itens de utilizacao do plano por beneficiario.

Importante: esta tabela nao usa chave unica para bloquear linhas visualmente iguais, pois alguns PDFs da Unimed trazem linhas legitimas com mesmos campos aparentes. A reimportacao evita duplicidade apagando e recriando as utilizacoes da fatura selecionada.

## Modelos de arquivos

Existem dois modelos validos para importacao:

### Analitico de Taxa

Usado no botao:

`Importar Analitico de Taxa`

Este arquivo contem:

- beneficiarios;
- familias;
- mensalidade por beneficiario;
- total da fatura de mensalidade.

Exemplo de arquivo:

`ANALITICO_TAXA_0022200058-1.PDF`

### Analitico de Servico Empresarial

Usado no botao:

`Importar utilizacoes do plano`

Este arquivo contem:

- utilizacoes do plano;
- data de atendimento;
- prestador;
- documento;
- quantidade;
- valor.

Exemplo de arquivo:

`ANALITICO_SERVICO_EMPRESARIAL_0022199705-1.PDF`

## Arquivos que nao servem para importar beneficiarios

O boleto/recibo da fatura nao serve para alimentar mensalidade por usuario/familia, pois nao traz os beneficiarios linha por linha.

Quando esse arquivo e enviado, o sistema deve avisar que ele e recibo/boleto e pedir o `ANALITICO DE TAXA`.

## Regras importantes ja implementadas

- A competencia da utilizacao pode ser diferente da competencia da mensalidade.
- O operador escolhe a fatura mensal de destino antes de importar utilizacoes.
- Reimportar utilizacoes substitui as utilizacoes da fatura escolhida.
- Reimportar mensalidade atualiza a fatura e recria os itens de mensalidade.
- O parser trata variacoes do PDF:
  - valores com espaco interno, como `1 12,31`;
  - datas com espaco interno, como `1 1/12/2025`;
  - documentos com espaco interno;
  - linhas repetidas legitimas.

## Ajustes feitos manualmente no banco

### Transferencia de utilizacoes

As utilizacoes de `R$ 2.463,37` estavam vinculadas incorretamente a:

- fatura mensal `40809990`
- competencia `03/2026`

Foram transferidas para:

- fatura mensal `40862330`
- competencia `04/2026`

Resultado:

- `40809990`: utilizacao zerada, total voltou para mensalidade.
- `40862330`: utilizacao `R$ 2.463,37`, 113 itens.

### Correcao da fatura 7

Arquivo de utilizacao da fatura 7 inicialmente importou `R$ 311,99`, mas o PDF totalizava `R$ 448,07`.

Causa:

- uma linha de `R$ 136,08` tinha data extraida como `1 1/12/2025`.

Correcao:

- parser ajustado;
- banco reimportado;
- fatura 7 ficou com 15 itens e total `R$ 448,07`.

## Ultimo commit relacionado

Commit:

`0550a69 Adiciona modulo Unimed`

Esse commit foi enviado ao GitHub e por FTP.

## Observacao operacional

Sempre que for continuar este modulo em um chat separado, usar o titulo:

`MODULO UNIMED`

E informar que o contexto consolidado esta em:

`docs/modulo_unimed_contexto.md`
