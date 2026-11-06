# Integracao ALELO EDI

## Escopo

A integracao pertence a empresa 1 e aparece no grupo **Comercial** da tela de importacao de recebimentos.
Ela consulta a caixa postal SFTP da ALELO sem upload manual e processa os arquivos de layout fixo com 500 posicoes:

- Tipo `01`: transacoes. Somente movimentos `01 - Venda` geram recebiveis.
- Tipo `02`: pagamentos. Os dados sao armazenados em `fechamento_alelo_pagamentos` para auditoria.

O CM das vendas da empresa 1 e `15`, definido em `fechamento_importacao_regras` e alteravel sem mudar o parser.
A data prevista informada no EDI e sempre preservada.

## Credenciais

As credenciais nunca ficam no repositorio. O arquivo `credentials.txt` deve conter host, porta, usuario e senha
e ser instalado no diretorio externo `alelo_empresa1`.

O diretorio pode ser indicado por `SUPERDUNGA_ALELO_EMPRESA1_DIR`. Sem essa variavel, a aplicacao procura:

1. `%APPDATA%/SuperDunga/secrets/alelo_empresa1`;
2. `$HOME/.superdunga/secrets/alelo_empresa1`;
3. `.superdunga/secrets/alelo_empresa1` acima da raiz publicada.

A conexao valida a chave publica conhecida do servidor ALELO antes de baixar arquivos.

## Execucao manual

Na tela `modulos/fechamentodecaixa/importar_recebimentos.php`, abra o card **ALELO** e informe um periodo de ate 32 dias.
Arquivos ja processados sao reconhecidos pelo SHA-256 e nao sao importados novamente. Recebiveis repetidos tambem sao
protegidos pelo identificador unico da transacao.

## Execucao automatica

O comando ja usado pelo CRON da Cielo tambem executa a ALELO:

```bash
php scripts/sincronizar-cielo-recebimentos.php
```

Assim, o agendamento existente das 06:00 e 12:00 consulta Cielo e ALELO. Tambem existe um comando isolado para suporte:

```bash
php scripts/sincronizar-alelo-recebimentos.php
php scripts/sincronizar-alelo-recebimentos.php 2026-11-01 2026-11-06
```

## Tabelas

- `fechamento_importacao_regras`: card, grupo e CM.
- `armazem_conciliacao_recebimentos`: vendas importadas para conciliacao.
- `fechamento_alelo_sincronizacoes`: historico das execucoes.
- `fechamento_alelo_arquivos`: arquivos processados e controle de duplicidade.
- `fechamento_alelo_pagamentos`: informacoes dos arquivos tipo 02.
