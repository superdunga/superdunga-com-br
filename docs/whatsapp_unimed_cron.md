# WhatsApp gerencial - UNIMED

## Regra

- A rotina nao usa agendamento do WhatsApp gerencial.
- Um cron independente verifica diariamente a competencia atual.
- O envio so ocorre quando a fatura mensal e a utilizacao da competencia estiverem importadas.
- Cada responsavel recebe uma mensagem e seu PDF individual.
- Um envio concluido nao e repetido para a mesma fatura e responsavel.
- O telefone e enviado a Evolution API como `55 + DDD + celular`, somente digitos.
- O pagamento vence no dia 11. Se for sabado, domingo ou feriado cadastrado, antecipa para o dia util anterior.

## PIX

- Chave CNPJ: `26732639000155`
- Favorecido: `Ethicos Servicos`

## Cron independente

```cron
0 12 * * * /usr/local/bin/php -q /home/etelema/public_html/superdunga.com.br/scripts/enviar-unimed-whatsapp.php >/dev/null 2>&1
```

Este cron nao deve ser unificado com o cron geral do WhatsApp nem com o monitor da Cielo.

## Conferencia sem envio

```bash
php scripts/enviar-unimed-whatsapp.php --dry-run
```
