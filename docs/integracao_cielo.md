# Integracao Cielo - Extrato Eletronico

## Escopo atual

A integracao `cielo_api2_comercial_vrs` pertence exclusivamente ao card
**Comercial** da empresa 5. Ela usa o estabelecimento Cielo `3003947763` e as
mesmas classificacoes comerciais ja adotadas pelos importadores da empresa:

- debito: CM 3;
- credito: CM 2;
- Pix: CM 12.

Os recebiveis sao gravados em `armazem_conciliacao_recebimentos` e seguem o
fluxo existente de conciliacao do SuperDunga. A integracao nao executa nenhuma
alteracao direta no Firebird.

## Arquivos consumidos

A API solicita os arquivos CIELO03, CIELO04, CIELO09, CIELO15 e CIELO16. Nesta
etapa, os recebiveis sao gerados somente a partir dos registros transacionais:

- CIELO03: vendas de debito e credito, registros tipo `E`;
- CIELO16: vendas Pix, registros tipo `8`.

Os demais arquivos sao baixados, validados e registrados no historico para
permitir evolucao posterior da conciliacao financeira. Arquivos apenas com
header e trailer sao validos e nao geram recebiveis.

O parser segue o layout fixo v15 publicado pela Cielo. A data de vencimento
original do arquivo CIELO03 e a data de pagamento do CIELO16 alimentam
`data_prevista`. Registros sem uma data prevista valida nao sao importados.

## Seguranca e credenciais

As credenciais nunca ficam no repositorio ou dentro da raiz publica do site.
O diretorio deve ser indicado pela variavel de ambiente
`SUPERDUNGA_CIELO_VRS_DIR`. Como alternativas locais, o codigo procura:

- Windows: `%APPDATA%\SuperDunga\secrets\cielo_api2_comercial_vrs`;
- Linux: `$HOME/.superdunga/secrets/cielo_api2_comercial_vrs`.

Arquivos esperados no diretorio:

- `client_id.txt`;
- `access_token.txt` (client secret fornecido pelo portal Cielo);
- `hmac_x_signature.txt`;
- `comercial-vrs-ltda-extc-prd-mulesoft-mtls.cer`;
- `comercial-vrs-ltda-extc-prd-mulesoft-mtls.key`.

A autenticacao usa OAuth2 com mTLS. O header `X-Signature` e calculado em
HMAC-SHA256 sobre o JSON exato da solicitacao e convertido para Base64.

## Idempotencia e auditoria

Cada arquivo e identificado pelo SHA-256 em `fechamento_cielo_arquivos`. Um
arquivo ja processado nao e importado novamente. Cada recebivel recebe um
identificador estavel derivado da chave transacional da Cielo; arquivos
reprocessados atualizam o recebivel existente sem apagar `CRCONTADOR` ou o
vinculo de conciliacao.

As execucoes ficam em `fechamento_cielo_sincronizacoes`, com periodo, status,
quantidade de arquivos, novos registros, atualizacoes e registros ignorados.

## Execucao

O operador pode executar a sincronizacao em:

`Fechamento > Recebimentos > Importar > Comercial > Cielo API`

Para agendamento no servidor principal:

```bash
php scripts/sincronizar-cielo-recebimentos.php
```

Sem parametros, o comando consulta os ultimos oito dias, permitindo receber
arquivos liberados com atraso. Um periodo especifico pode ser informado:

```bash
php scripts/sincronizar-cielo-recebimentos.php 2026-10-01 2026-10-10
```

O job deve ser instalado apenas no servidor principal do SuperDunga. Os
servidores auxiliares Firebird continuam restritos ao espelho de documentos
fiscais.

