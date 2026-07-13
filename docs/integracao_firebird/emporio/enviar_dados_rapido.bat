@echo off

cd /d C:\Integracao_Emporio

echo. >> C:\Integracao_Emporio\logs\sincronizacao_rapida.log
echo Iniciando sincronizacao rapida EMPORIO... >> C:\Integracao_Emporio\logs\sincronizacao_rapida.log
echo Data/hora: %date% %time% >> C:\Integracao_Emporio\logs\sincronizacao_rapida.log

"C:\Users\Emporio01\AppData\Local\Programs\Python\Python313\python.exe" -u "C:\Integracao_Emporio\enviar_dados.py" rapido >> C:\Integracao_Emporio\logs\sincronizacao_rapida.log 2>&1

echo Atualizando embalagens Firebird... >> C:\Integracao_Emporio\logs\sincronizacao_rapida.log
"C:\Users\Emporio01\AppData\Local\Programs\Python\Python313\python.exe" -u "C:\Integracao_Emporio\atualizar_embalagens_firebird.py" --empresa 4 --firebird-empresa 1 --fdb "C:\Adm_EmporioDunga\Data\ESTOQUE.FDB" >> C:\Integracao_Emporio\logs\sincronizacao_rapida.log 2>&1

echo Atualizando produtos/compras Firebird... >> C:\Integracao_Emporio\logs\sincronizacao_rapida.log
"C:\Users\Emporio01\AppData\Local\Programs\Python\Python313\python.exe" -u "C:\Integracao_Emporio\atualizar_produtos_firebird.py" --empresa 4 --firebird-empresa 1 >> C:\Integracao_Emporio\logs\sincronizacao_rapida.log 2>&1

echo Finalizado: %date% %time% >> C:\Integracao_Emporio\logs\sincronizacao_rapida.log

exit /b 0
