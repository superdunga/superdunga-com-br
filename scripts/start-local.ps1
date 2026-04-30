param(
    [int]$Port = 8080
)

$ErrorActionPreference = "Stop"

$root = Resolve-Path (Join-Path $PSScriptRoot "..")
$php = (Get-Command php -ErrorAction Stop).Source
$localDir = Join-Path $root ".local"
$pidFile = Join-Path $localDir "php-server.pid"
$outLog = Join-Path $localDir "php-server.out.log"
$errLog = Join-Path $localDir "php-server.err.log"

if (!(Test-Path $localDir)) {
    New-Item -ItemType Directory -Path $localDir | Out-Null
}

if (Test-Path $pidFile) {
    $oldPid = Get-Content $pidFile -ErrorAction SilentlyContinue
    if ($oldPid -and (Get-Process -Id $oldPid -ErrorAction SilentlyContinue)) {
        Write-Host "Servidor local ja esta rodando em http://127.0.0.1:$Port"
        exit 0
    }
}

$args = @(
    "-S", "127.0.0.1:$Port",
    "-t", $root,
    (Join-Path $root "dev/router.php")
)

$process = Start-Process -FilePath $php -ArgumentList $args -WorkingDirectory $root -WindowStyle Hidden -PassThru -RedirectStandardOutput $outLog -RedirectStandardError $errLog
$process.Id | Set-Content $pidFile

Write-Host "Servidor local iniciado: http://127.0.0.1:$Port"
Write-Host "Logs: $outLog"
