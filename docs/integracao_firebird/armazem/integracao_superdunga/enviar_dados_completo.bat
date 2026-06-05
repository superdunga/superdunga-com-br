@echo off

cd /d C:\integracao_superdunga

echo. >> "C:\integracao_superdunga\logs\sincronizacao_completa.log"
echo Enviando dados completos do Firebird... >> "C:\integracao_superdunga\logs\sincronizacao_completa.log"
echo Data/hora: %date% %time% >> "C:\integracao_superdunga\logs\sincronizacao_completa.log"

"C:\Users\armuser03\AppData\Local\Programs\Python\Python314\python.exe" -u "C:\integracao_superdunga\enviar_dados.py" completo >> "C:\integracao_superdunga\logs\sincronizacao_completa.log" 2>&1

echo Finalizado: %date% %time% >> "C:\integracao_superdunga\logs\sincronizacao_completa.log"
