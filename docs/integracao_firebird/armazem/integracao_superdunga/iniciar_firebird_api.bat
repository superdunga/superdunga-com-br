@echo off

echo Enviando dados do Firebird...

"C:\Users\armuser03\AppData\Local\Programs\Python\Python314\python.exe" "C:\integracao_superdunga\enviar_dados.py"

echo Atualizando embalagens Firebird...

"C:\Users\armuser03\AppData\Local\Programs\Python\Python314\python.exe" -u "C:\integracao_superdunga\atualizar_embalagens_firebird.py" --empresa 1 --firebird-empresa 1 --fdb "C:\Adm_EmporioDunga\Data\ESTOQUE.FDB" >> "C:\integracao_superdunga\logs\atualizar_embalagens.log" 2>&1

echo Atualizando produtos/compras Firebird...

"C:\Users\armuser03\AppData\Local\Programs\Python\Python314\python.exe" -u "C:\integracao_superdunga\atualizar_produtos_firebird.py" --empresa 1 --firebird-empresa 1 >> "C:\integracao_superdunga\logs\atualizar_produtos.log" 2>&1

echo Finalizado.
