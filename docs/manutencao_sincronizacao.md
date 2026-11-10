# Alteracao crucial: desempenho e estabilidade da sincronizacao

> **Ponto crucial do SuperDunga - 10/11/2026.** Esta alteracao deve ser preservada e publicada como uma unidade. Nao publique apenas parte dos arquivos envolvidos, pois o conjunto protege a sincronizacao Firebird, o registro de execucoes e a verificacao CR001 do WhatsApp Operacional.

## Escopo aprovado

- operadores deixam de ser obrigados a abrir a tela de pendencias ao entrar no sistema;
- snapshots Firebird passam a ser gravados em lotes;
- snapshots parciais sao removidos em erros, bloqueios e pre-validacoes rejeitadas;
- verificacoes de estrutura deixam de executar em toda gravacao de log;
- logs de sincronizacao passam a ter retencao de 30 dias;
- a consulta CR001 do WhatsApp usa indice composto e possui retorno seguro em caso de indisponibilidade;
- a manutencao pesada fica fora das requisicoes normais do sistema.

## Evidencias anteriores a publicacao

- lote generico validado com 1.200 chaves;
- lote EST008 validado com 600 chaves;
- testes de erro deixaram zero snapshots parciais;
- login de OPERADOR, painel MASTER, Tesouraria e WhatsApp responderam HTTP 200;
- o plano da consulta CR001 passou de 72 para 6 linhas estimadas;
- nenhum relacionamento por chave estrangeira depende dos logs eliminados;
- regras financeiras, conciliacoes e tabelas de negocio nao foram alteradas.

## Arquivos que formam a unidade de publicacao

- `config/auth.php`
- `login.php`
- `modulos/tesouraria/log_sync.php`
- `modulos/tesouraria/receber_firebird.php`
- `modulos/tesouraria/sincronizacao.php`
- `modulos/whatsapp_operacional/whatsapp_operacional_lib.php`
- `scripts/manter-sincronizacao.php`

## Manutencao diaria

O script `scripts/manter-sincronizacao.php` executa a manutencao fora das requisicoes do sistema:

- preserva 30 dias de `tesouraria_sincronizacao_log`;
- remove snapshots incompletos com mais de 1 dia;
- garante o indice composto usado pela verificacao CR001 do WhatsApp Operacional;
- usa uma trava MySQL para impedir duas execucoes simultaneas;
- exclui dados em lotes para reduzir o tempo de bloqueio.

Agende uma execucao diaria no cron do cPanel, em horario de baixo movimento:

```text
30 3 * * * /usr/local/bin/ea-php72 /home/USUARIO/superdunga.com.br/scripts/manter-sincronizacao.php
```

Substitua `USUARIO` pelo usuario real da hospedagem. Para conferir as quantidades sem excluir dados:

```text
/usr/local/bin/ea-php72 /home/USUARIO/superdunga.com.br/scripts/manter-sincronizacao.php --dry-run
```

## Reversao

Em uma reversao de codigo, nao remova o indice `idx_sync_log_empresa_tabela_status_data`: ele e compativel com a versao anterior e evita custo adicional. Os logs removidos pela retencao nao participam de saldos, conciliacoes ou regras financeiras.
