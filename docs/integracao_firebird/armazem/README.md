# Integracao Firebird - Armazem

Arquivos copiados de:

```text
C:\Users\user\Downloads\integracao_armazem
```

Estrutura original copiada:

```text
integracao_superdunga
htdocs
```

Destino no servidor Armazem:

```text
C:\integracao_superdunga
```

API local:

```text
htdocs\firebird_teste.py
```

## Rotina rapida

Arquivo chamado:

```text
C:\integracao_superdunga\iniciar_firebird_api.bat
```

Etapas:

1. `enviar_dados.py` em modo padrao rapido.
2. `atualizar_embalagens_firebird.py --empresa 1 --firebird-empresa 1`.
3. `atualizar_produtos_firebird.py --empresa 1 --firebird-empresa 1`.

## Rotina completa

Arquivo chamado:

```text
C:\integracao_superdunga\enviar_dados_completo.bat
```

Comando principal:

```bat
"C:\Users\armuser03\AppData\Local\Programs\Python\Python314\python.exe" -u "C:\integracao_superdunga\enviar_dados.py" completo
```

## Testes uteis

Executar rotina rapida:

```powershell
Set-Location "C:\integracao_superdunga"
.\iniciar_firebird_api.bat
Get-Content C:\integracao_superdunga\logs\atualizar_produtos.log -Tail 100
Get-Content C:\integracao_superdunga\logs\atualizar_embalagens.log -Tail 50
```

Executar produtos/compras isolado:

```powershell
Set-Location "C:\integracao_superdunga"
& "C:\Users\armuser03\AppData\Local\Programs\Python\Python314\python.exe" -u "C:\integracao_superdunga\atualizar_produtos_firebird.py" --empresa 1 --firebird-empresa 1
```

Executar rotina completa:

```powershell
Set-Location "C:\integracao_superdunga"
.\enviar_dados_completo.bat
Get-Content C:\integracao_superdunga\logs\sincronizacao_completa.log -Tail 200
```

## Observacoes

- A rapida do `enviar_dados.py` pula `EST004`, `EST005` e `EST006`.
- Por isso, `atualizar_produtos_firebird.py` foi adicionada ao final da rapida para atualizar produtos, compras e itens alterados sem esperar a completa.
- Os logs grandes da pasta `logs` nao foram copiados para o projeto.
