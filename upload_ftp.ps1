param(
    [Parameter(Mandatory = $true)]
    [string]$Server,

    [Parameter(Mandatory = $true)]
    [int]$Port,

    [Parameter(Mandatory = $true)]
    [string]$Username,

    [Parameter(Mandatory = $true)]
    [string]$Password,

    [Parameter(Mandatory = $true)]
    [string]$RemoteBase,

    [Parameter(Mandatory = $true)]
    [string[]]$Files,

    [switch]$IgnoreInvalidCertificate
)

$ErrorActionPreference = "Stop"
$localBase = (Get-Location).Path
$curl = (Get-Command curl.exe -ErrorAction Stop).Source

function Send-FtpFile {
    param(
        [string]$RelativePath
    )

    $localPath = Join-Path $localBase $RelativePath
    if (!(Test-Path -LiteralPath $localPath -PathType Leaf)) {
        throw "Arquivo local nao encontrado: $RelativePath"
    }

    $remotePath = ($RelativePath -replace '\\', '/')
    $remoteUri = "ftp://$Server`:$Port$RemoteBase/$remotePath"

    $curlArgs = @(
        '--silent', '--show-error', '--fail', '--ssl-reqd', '--ftp-pasv', '--ftp-create-dirs',
        '--user', "$Username`:$Password",
        '--upload-file', $localPath,
        $remoteUri
    )
    if ($IgnoreInvalidCertificate) {
        $curlArgs = @('--insecure') + $curlArgs
    }

    & $curl @curlArgs
    if ($LASTEXITCODE -ne 0) {
        throw "Falha ao enviar por FTPS: $RelativePath (curl $LASTEXITCODE)."
    }

    Write-Host "Enviado: $RelativePath"
}

foreach ($file in $Files) {
    Send-FtpFile -RelativePath $file
}

Write-Host "Upload concluido."
