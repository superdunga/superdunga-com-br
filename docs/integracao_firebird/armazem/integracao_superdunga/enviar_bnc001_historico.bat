@echo off

echo Iniciando carga historica BNC001...
echo Periodo padrao: 1900-01-01 ate 2024-12-31
echo Data/hora: %date% %time%

"C:\Users\armuser03\AppData\Local\Programs\Python\Python314\python.exe" "C:\integracao_superdunga\enviar_dados.py" bnc001_historico 1900-01-01 2024-12-31 5000

echo Finalizado: %date% %time%
