param(
    [Parameter(Mandatory = $true)]
    [string[]]$Files
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$settingsPath = 'C:\Projetos\download_ftp.ps1'
if (-not (Test-Path -LiteralPath $settingsPath -PathType Leaf)) {
    throw "Configuracao local de FTP nao encontrada: $settingsPath"
}

$tokens = $null
$parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile(
    $settingsPath, [ref]$tokens, [ref]$parseErrors
)
if ($parseErrors.Count) {
    throw 'Configuracao local de FTP invalida.'
}

$settings = @{}
$assignments = $ast.FindAll({
    param($node)
    $node -is [System.Management.Automation.Language.AssignmentStatementAst]
}, $true)
foreach ($assignment in $assignments) {
    if ($assignment.Left -isnot [System.Management.Automation.Language.VariableExpressionAst] -or
        $assignment.Right -isnot [System.Management.Automation.Language.CommandExpressionAst] -or
        $assignment.Right.Expression -isnot [System.Management.Automation.Language.StringConstantExpressionAst]) {
        continue
    }
    $settings[$assignment.Left.VariablePath.UserPath] = $assignment.Right.Expression.Value
}
if (-not $settings.ftpServer -or -not $settings.username -or -not $settings.password) {
    throw 'Host, usuario ou senha ausente na configuracao local de FTP.'
}

$relativeFiles = foreach ($file in $Files) {
    $absolute = [System.IO.Path]::GetFullPath((Join-Path $root $file))
    if (-not $absolute.StartsWith($root + [System.IO.Path]::DirectorySeparatorChar,
        [System.StringComparison]::OrdinalIgnoreCase) -or -not (Test-Path -LiteralPath $absolute -PathType Leaf)) {
        throw "Arquivo fora do projeto ou inexistente: $file"
    }
    [System.IO.Path]::GetRelativePath($root, $absolute).Replace('\', '/')
}

Push-Location $root
try {
    & (Join-Path $root 'upload_ftp.ps1') `
        -Server $settings.ftpServer -Port 21 `
        -Username $settings.username -Password $settings.password `
        -RemoteBase '/superdunga.com.br' -Files $relativeFiles

    $credential = [System.Net.NetworkCredential]::new($settings.username, $settings.password)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        foreach ($file in $relativeFiles) {
            $request = [System.Net.FtpWebRequest]::Create(
                "ftp://$($settings.ftpServer)/superdunga.com.br/$file"
            )
            $request.Credentials = $credential
            $request.Method = [System.Net.WebRequestMethods+Ftp]::DownloadFile
            $request.UsePassive = $true
            $response = $request.GetResponse()
            $stream = $response.GetResponseStream()
            $memory = [System.IO.MemoryStream]::new()
            try {
                $stream.CopyTo($memory)
                $remoteHash = [Convert]::ToHexString($sha.ComputeHash($memory.ToArray()))
            } finally {
                $memory.Dispose()
                $stream.Dispose()
                $response.Dispose()
            }
            $localHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $file).Hash
            if ($remoteHash -ne $localHash) {
                throw "Verificacao FTP falhou: $file"
            }
            Write-Host "Verificado no FTP: $file"
        }
    } finally {
        $sha.Dispose()
    }
} finally {
    Pop-Location
}
