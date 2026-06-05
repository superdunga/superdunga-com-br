@echo off

cd /d C:\Integracao_Emporio

echo Enviando conciliados EMPORIO...

python -u enviar_conciliados.py >> C:\Integracao_Emporio\logs\enviar_conciliados.log 2>&1

echo Finalizado: %date% %time% >> C:\Integracao_Emporio\logs\enviar_conciliados.log
