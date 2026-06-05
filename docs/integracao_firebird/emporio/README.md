# Integracao Firebird - Emporio

Arquivos copiados de:

```text
C:\Users\user\Downloads\integracao_Emporio
```

Destino no servidor Emporio:

```text
C:\Integracao_Emporio
```

## Arquivos armazenados

- `firebird_teste.py`: API local Firebird na porta 5000.
- `enviar_dados.py`: sincronizacao Firebird -> SuperDunga.
- `enviar_dados_rapido.bat`: rotina rapida do Agendador.
- `enviar_dados_completo.bat`: rotina completa do Agendador.
- `enviar_conciliados.py`: envio de conciliados para Firebird.
- `enviar_conciliados.bat`: chamada do envio de conciliados.
- `atualizar_embalagens_firebird.py`: atualiza `EST004.EMB_QTDE` no Firebird.
- `atualizar_produtos_firebird.py`: atualiza `EST004`, `EST005` e `EST006` no SuperDunga pela API local.
- `SuperDunga - Sincronizacao Completa EMPORIO.xml`: exportacao da tarefa agendada completa.

## Tarefa completa

Arquivo chamado:

```text
C:\Integracao_Emporio\enviar_dados_completo.bat
```

Comando principal:

```bat
"C:\Users\Emporio01\AppData\Local\Programs\Python\Python313\python.exe" -u "C:\Integracao_Emporio\enviar_dados.py" completo
```

## Rotina rapida

Arquivo chamado:

```text
C:\Integracao_Emporio\enviar_dados_rapido.bat
```

Etapas:

1. `enviar_dados.py rapido`
2. `atualizar_embalagens_firebird.py --empresa 4 --firebird-empresa 1`
3. `atualizar_produtos_firebird.py --empresa 4 --firebird-empresa 1`

Observacao: o `echo Finalizado` deve ficar depois da etapa de produtos/compras, para o log refletir o fim real da rotina.

## Testes uteis

Executar rotina rapida:

```powershell
Set-Location "C:\Integracao_Emporio"
.\enviar_dados_rapido.bat
Get-Content C:\Integracao_Emporio\logs\sincronizacao_rapida.log -Tail 150
```

Executar rotina completa:

```powershell
schtasks /run /tn "SuperDunga - Sincronizacao Completa EMPORIO"
Get-Content C:\Integracao_Emporio\logs\sincronizacao_completa.log -Tail 200
```

Testar produtos/compras isolado:

```powershell
Set-Location "C:\Integracao_Emporio"
& "C:\Users\Emporio01\AppData\Local\Programs\Python\Python313\python.exe" -u "C:\Integracao_Emporio\atualizar_produtos_firebird.py" --empresa 4 --firebird-empresa 1
```

Testar embalagens isolado:

```powershell
Set-Location "C:\Integracao_Emporio"
& "C:\Users\Emporio01\AppData\Local\Programs\Python\Python313\python.exe" -u "C:\Integracao_Emporio\atualizar_embalagens_firebird.py" --empresa 4 --firebird-empresa 1 --fdb "C:\Adm_EmporioDunga\Data\ESTOQUE.FDB"
```
