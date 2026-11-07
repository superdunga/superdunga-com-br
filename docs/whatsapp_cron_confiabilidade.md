# Confiabilidade dos agendamentos WhatsApp

## Separacao obrigatoria

O cron de WhatsApp deve executar apenas:

- agendamentos gerenciais;
- automacoes operacionais ja existentes;
- consultas de confirmacao para auditoria.

Consultas Cielo nao podem ser executadas dentro do endpoint de WhatsApp. Elas fazem
chamadas externas longas e usam o endpoint dedicado
`modulos/whatsapp/executar_monitor_cielo.php`.

## Regras preservadas

- O cron geral roda a cada cinco minutos.
- O monitor Cielo roda separadamente a cada dez minutos.
- `GET_LOCK('superdunga_whatsapp_agendamentos', 0)` impede duas execucoes simultaneas
  do cron geral.
- Uma resposta HTTP de sucesso da Evolution significa mensagem aceita e permite
  avancar o agendamento.
- O status posterior da Evolution e informacao de auditoria. Ele nao bloqueia o
  agendamento porque grupos podem permanecer em `PENDING` mesmo apos a entrega.
- Falhas definitivas podem ser tentadas novamente, no maximo tres vezes. Uma
  tentativa aceita nunca e reenviada automaticamente.

## Auditoria

A tabela `whatsapp_cron_execucoes` registra processo, inicio, fim, duracao, status e
detalhes de cada ciclo. Os processos esperados sao:

- `WHATSAPP_AGENDAMENTOS`;
- `MONITOR_CIELO_CAIXA`.

O campo `cron_ultimo_contato_em` em `whatsapp_gerencial_config` continua sendo o
indicador simples de atividade do cron geral.
