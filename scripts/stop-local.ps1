$ErrorActionPreference = "Stop"

$root = Resolve-Path (Join-Path $PSScriptRoot "..")
$pidFile = Join-Path $root ".local/php-server.pid"

if (!(Test-Path $pidFile)) {
    Write-Host "Nenhum servidor local registrado."
    exit 0
}

$pidValue = Get-Content $pidFile -ErrorAction SilentlyContinue

if ($pidValue -and (Get-Process -Id $pidValue -ErrorAction SilentlyContinue)) {
    Stop-Process -Id $pidValue
    Write-Host "Servidor local parado."
} else {
    Write-Host "Processo local nao estava rodando."
}

Remove-Item $pidFile -Force
