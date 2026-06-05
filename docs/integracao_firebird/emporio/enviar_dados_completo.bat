@echo off

cd /d C:\Integracao_Emporio

echo. >> C:\Integracao_Emporio\logs\sincronizacao_completa.log
echo Iniciando sincronizacao completa EMPORIO... >> C:\Integracao_Emporio\logs\sincronizacao_completa.log
echo Data/hora: %date% %time% >> C:\Integracao_Emporio\logs\sincronizacao_completa.log

"C:\Users\Emporio01\AppData\Local\Programs\Python\Python313\python.exe" -u "C:\Integracao_Emporio\enviar_dados.py" completo >> C:\Integracao_Emporio\logs\sincronizacao_completa.log 2>&1

echo Finalizado: %date% %time% >> C:\Integracao_Emporio\logs\sincronizacao_completa.log