@echo off

echo ================================
echo INICIO ENVIO RESUMO DIARIO
echo %date% %time%
echo ================================

curl https://www.superdunga.com.br/modulos/tesouraria/enviar_resumo_diario.php

echo ================================
echo FIM
echo ================================

