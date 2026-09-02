# Integracao Firebird - Emporio

Arquivos copiados de:

```text
C:\Users\user\Downloads\integracao_Emporio
```

Destino no servidor Emporio:

```text
C:\Integracao_Emporio
```

## Mapeamento de empresas

Este servidor Firebird atende duas empresas distintas:

| Empresa no Firebird | Empresa no SuperDunga | Nome |
| --- | --- | --- |
| 1 | 4 | EMPORIO DUNGA |
| 6 | 5 | CMX |

O mapeamento sempre deve ser informado como um par. Nunca execute a origem 6
com o destino 4, nem a origem 1 com o destino 5.

## Arquivos armazenados

- `firebird_teste.py`: API local Firebird na porta 5000.
- `enviar_dados.py`: sincronizacao Firebird -> SuperDunga.
- `enviar_dados_rapido.bat`: rotina rapida do Agendador.
- `enviar_dados_completo.bat`: rotina completa do Agendador.
- `enviar_conciliados.py`: envio de conciliados para Firebird.
- `enviar_conciliados.bat`: chamada do envio de conciliados.
- `atualizar_embalagens_firebird.py`: atualiza `EST004.EMB_QTDE` no Firebird.
- `atualizar_produtos_firebird.py`: atualiza `EST004`, `EST005` e `EST006` no SuperDunga pela API local.
- `sincronizar_estoque_calculado.py`: envia estoque geral, reservado e disponivel calculados pelas procedures do Firebird.
- `SuperDunga - Sincronizacao Completa EMPORIO.xml`: exportacao da tarefa agendada completa.

## Tabelas sincronizadas na rotina rapida

Alem das tabelas financeiras e de vendas, a rotina rapida tambem envia:

- `REP001`: cadastro de funcionarios, gravado no SuperDunga como `armazem_REP001`.
- `FUNC001`: vales dos funcionarios, gravado no SuperDunga como `armazem_FUNC001`.

## Tarefa completa

Arquivo chamado:

```text
C:\Integracao_Emporio\enviar_dados_completo.bat
```

Comandos principais:

```bat
"C:\Users\Emporio01\AppData\Local\Programs\Python\Python313\python.exe" -u "C:\Integracao_Emporio\enviar_dados.py" completo 1 4
"C:\Users\Emporio01\AppData\Local\Programs\Python\Python313\python.exe" -u "C:\Integracao_Emporio\enviar_dados.py" completo 6 5
```

## Rotina rapida

Arquivo chamado:

```text
C:\Integracao_Emporio\enviar_dados_rapido.bat
```

Etapas para cada um dos dois mapeamentos:

1. `enviar_dados.py rapido FIREBIRD_EMPRESA EMPRESA_SUPERDUNGA`
2. `atualizar_embalagens_firebird.py --empresa EMPRESA_SUPERDUNGA --firebird-empresa FIREBIRD_EMPRESA`
3. `atualizar_produtos_firebird.py --empresa EMPRESA_SUPERDUNGA --firebird-empresa FIREBIRD_EMPRESA`
4. `sincronizar_estoque_calculado.py --empresa EMPRESA_SUPERDUNGA --firebird-empresa FIREBIRD_EMPRESA`

Os saldos oficiais usam `SP_CALCESTOQUE_LOJA` e `SP_CALCESTOQUE_RESERVA`.
O disponivel e calculado como estoque geral menos estoque reservado.

O `enviar_conciliados.py` percorre os dois mapeamentos e envia cada conciliacao
de volta para a respectiva `EMPRESA` no Firebird.

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
