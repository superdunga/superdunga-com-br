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

Add-Type -AssemblyName System.Net

$ErrorActionPreference = "Stop"
$credential = New-Object System.Net.NetworkCredential($Username, $Password)
$localBase = (Get-Location).Path

if ($IgnoreInvalidCertificate) {
    [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
}

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

    $request = [System.Net.FtpWebRequest]::Create($remoteUri)
    $request.Credentials = $credential
    $request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $request.UsePassive = $true
    $request.UseBinary = $true
    $request.EnableSsl = $true

    $bytes = [System.IO.File]::ReadAllBytes($localPath)
    $request.ContentLength = $bytes.Length

    $stream = $request.GetRequestStream()
    try {
        $stream.Write($bytes, 0, $bytes.Length)
    } finally {
        $stream.Close()
    }

    $response = $request.GetResponse()
    try {
        Write-Host "Enviado: $RelativePath - $($response.StatusDescription.Trim())"
    } finally {
        $response.Close()
    }
}

foreach ($file in $Files) {
    Send-FtpFile -RelativePath $file
}

Write-Host "Upload concluido."
