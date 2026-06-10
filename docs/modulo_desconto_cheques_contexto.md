# Modulo Desconto de Cheques - Contexto do Projeto

## Inicio da conversa

Solicitacao inicial:

- criar um novo modulo no caminho `Index > Desconto de Cheques`;
- seguir o padrao visual e estrutural do sistema SuperDunga;
- manter a regra geral definida para novos modulos: novas funcionalidades nao devem ser atribuidas automaticamente a usuarios cadastrados que nao sejam perfil `MASTER`.

## Regra de acesso

O modulo deve nascer restrito ao perfil `MASTER`.

Arquivos esperados para a primeira estrutura:

- `config/modulos.php`
- `config/auth.php`
- `layout/header.php`
- `index.php`
- `modulos/desconto_cheques/menu_desconto_cheques.php`
- `modulos/desconto_cheques/clientes.php`
- `modulos/desconto_cheques/operacoes.php`
- `modulos/desconto_cheques/feriados.php`
- `modulos/desconto_cheques/_lib.php`

## Escopo inicial

Criar apenas a entrada inicial do modulo:

- card no `index.php`;
- item na topbar, se permitido ao usuario;
- configuracao no controle de modulos/permissoes;
- pagina inicial do modulo em `modulos/desconto_cheques/menu_desconto_cheques.php`.

As regras de negocio, tabelas e telas operacionais ainda serao definidas antes da implementacao.

## Primeira implementacao

Implementada a primeira etapa do modulo, restrita a perfil `MASTER`.

Telas criadas:

- `modulos/desconto_cheques/menu_desconto_cheques.php`
- `modulos/desconto_cheques/clientes.php`
- `modulos/desconto_cheques/operacoes.php`
- `modulos/desconto_cheques/feriados.php`

Funcionalidades iniciais:

- card no `index.php`;
- item curto `Cheques` na topbar;
- cadastro de clientes com nome, celular, taxa percentual, adicional de prazo, limite de credito e ativo/inativo;
- tabela padrao de adicional de prazo criada automaticamente por empresa;
- tela de feriados recorrentes por dia/mes, sem depender do ano;
- feriados nacionais fixos carregados automaticamente na tabela de feriados;
- feriados variaveis carregados automaticamente por ano em tabela propria;
- lancamento manual de operacao de desconto;
- anexar foto/arquivo por documento;
- informar tipo, numero, valor e vencimento por documento;
- calculo automatico de data de compensacao, prazo final, desconto, adicional e valor liquido;
- campos de fechamento da operacao para taxas/tarifas e valores a descontar, cada um com valor e historico;
- valor liquido final da operacao calculado por `liquido dos titulos - taxas/tarifas - valores a descontar`;
- relatorio da operacao para impressao/PDF;
- edicao de operacoes ja cadastradas, permitindo alterar cliente, data, observacao, taxas/tarifas, valores a descontar e os documentos do grid;
- na edicao de documentos, se nenhum novo arquivo for enviado, o anexo existente permanece vinculado;
- documentos removidos do grid durante a edicao sao removidos da operacao;
- bloqueio de operacao acima do limite de credito disponivel do cliente;
- listagem das operacoes com documentos logo abaixo.

Validacao inicial:

- arquivos PHP validados com `php -l`;
- telas de menu, clientes e operacoes abertas localmente;
- checagem responsiva em largura de 390px sem overflow horizontal de pagina.

## Regra de negocio definida

O modulo de desconto de cheques deve controlar as operacoes de desconto por cliente, acompanhando o limite de credito e o credito utilizado.

### Cadastro de cliente

Campos iniciais:

- Nome
- Celular
- Taxa de desconto percentual
- Adicional da tabela de prazo: sim/nao
- Limite de credito

Objetivo do cadastro: permitir controlar quanto cada cliente possui de credito disponivel para operacoes de desconto.

### Tabela de adicional de prazo

Quando o cliente estiver marcado para usar adicional de prazo, aplicar a regra abaixo:

- De 1 a 15 dias: adicional de 1% sobre a taxa cadastrada do cliente ou minimo de R$ 10,00.
- De 16 a 29 dias: adicional de 0,5% sobre a taxa cadastrada do cliente.
- Acima de 30 dias: usar apenas a taxa cadastrada do cliente.

### Operacoes de desconto

Fluxo previsto:

1. Operador informa a data de referencia, com padrao na data atual.
2. Operador informa o cliente.
3. Operador insere documento no grid via foto ou arquivo.
4. Sistema tenta ler o arquivo/imagem e extrair os dados do cheque ou boleto.
5. Operador confere/informa valor e data de vencimento.
6. Documento entra no grid e o sistema solicita o proximo documento.
7. Sistema calcula o valor descontado aplicando:
   - taxa cadastrada do cliente;
   - adicional por prazo, quando aplicavel;
   - 2 dias de compensacao.
8. Se a data final cair em dia nao util, mover para o proximo dia util e aumentar o prazo da operacao.

### Calculo de prazo

O prazo da operacao deve considerar:

- data de referencia;
- data de vencimento do documento;
- 2 dias de compensacao;
- ajuste para proximo dia util quando a data final cair em sabado, domingo ou feriado cadastrado.

Esse prazo final ajustado deve ser usado para definir a faixa da tabela de adicional de prazo.

Exemplo:

- vencimento: 03/09/2026, quinta-feira;
- adiciona 2 dias de compensacao: 05/09/2026, sabado;
- 06/09/2026 e domingo;
- 07/09/2026 e feriado nacional;
- proximo dia util: 08/09/2026.

Feriados nacionais fixos carregados automaticamente na tela de feriados:

- 01/01
- 21/04
- 01/05
- 07/09
- 12/10
- 02/11
- 15/11
- 25/12

Os feriados sao recorrentes por dia e mes. O ano nao e relevante para feriados fixos. O operador pode cadastrar feriados estaduais, municipais ou regionais pela tela `Feriados`.

Feriados variaveis sao gravados em tabela separada por data especifica, pois mudam conforme o ano.

Feriados variaveis calculados automaticamente:

- Carnaval - segunda-feira;
- Carnaval - terca-feira;
- Sexta-feira Santa;
- Corpus Christi.

Ao acessar o modulo, o sistema carrega automaticamente os feriados variaveis do ano anterior ate os proximos anos configurados no codigo. O calculo de dia util consulta tanto os feriados fixos por dia/mes quanto os variaveis por data especifica.

### Calculo do desconto

A taxa cadastrada no cliente é mensal.

Formula:

- taxa proporcional do periodo = `taxa mensal / 30 * quantidade de dias calculada`;
- valor do desconto = `valor do cheque ou boleto * taxa proporcional do periodo`;
- valor liquido = `valor do cheque ou boleto - valor do desconto`.

Exemplo:

- taxa do cliente: 3%;
- prazo calculado: 113 dias;
- taxa total proporcional: `3 / 30 * 113 = 11,30%`;
- valor do documento: R$ 4.990,00;
- desconto: R$ 4.990,00 * 11,30% = R$ 563,87;
- liquido: R$ 4.426,13.

Quando houver adicional de prazo, o adicional também é tratado como percentual mensal proporcional aos dias calculados. Se a faixa possuir valor mínimo, aplicar o maior valor entre o adicional proporcional e o mínimo.

### Fechamento da operacao

Apos o grid dos cheques/boletos, a tela deve perguntar:

- Cobranca de taxas/tarifas: valor e historico.
- Valores a descontar: valor e historico.

Formula do valor final:

- valor liquido da operacao = `valor liquido dos titulos - taxas/tarifas - valores a descontar`.

No PDF da operacao, se taxas/tarifas ou valores a descontar forem zero, essas linhas/secoes nao devem aparecer.

### Leitura automatica de documentos

A tela de operacoes tenta ler o arquivo assim que o operador seleciona uma foto ou PDF.

O sistema retorna sugestoes para:

- numero do cheque/boleto;
- CNPJ ou CPF do emissor;
- nome do emissor;
- valor;
- data de vencimento.

As sugestoes preenchem somente campos vazios, mantendo o operador responsavel por conferir e corrigir antes de salvar.

Quando o CNPJ/CPF do emissor for preenchido, a tela consulta documentos ja cadastrados e ainda a vencer para o mesmo emissor, dentro da mesma empresa, em operacoes `ABERTA` ou `CONFIRMADA`. Na edicao, o proprio documento da linha e ignorado para nao inflar o alerta.

Dependencias tecnicas:

- PDF com texto: usa `pdftotext` quando disponivel ou Python com `pypdf`.
- Foto/imagem: usa `tesseract` quando disponivel no servidor.
- Foto/imagem no celular: quando o servidor nao tem OCR, a tela tenta OCR no proprio navegador usando `tesseract.js`.
- Sem essas ferramentas, o arquivo continua sendo anexado normalmente e o operador preenche os campos manualmente.

## Pendencias de definicao

Antes de codar as rotinas internas, definir:

- quais campos finais compoem um cheque ou boleto;
- se o documento pode ser cheque, boleto ou ambos na mesma operacao;
- como armazenar foto/arquivo original do documento;
- quais dados serao extraidos automaticamente da imagem/arquivo;
- se havera banco/conta vinculada;
- como registrar valor bruto, desconto, adicional, valor liquido e prazo final;
- como tratar cheques devolvidos, baixados, liquidados ou cancelados;
- quais relatorios e filtros serao necessarios;
- se havera conciliacao com `armazem_bnc001` ou outro movimento financeiro;
- quais feriados devem ser considerados como dias nao uteis alem de sabados e domingos.
