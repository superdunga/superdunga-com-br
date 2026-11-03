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
        -RemoteBase '/superdunga.com.br' -Files $relativeFiles `
        -IgnoreInvalidCertificate

    $curl = (Get-Command curl.exe -ErrorAction Stop).Source
    foreach ($file in $relativeFiles) {
        $temporario = [System.IO.Path]::GetTempFileName()
        try {
            & $curl `
                --silent --show-error --fail --ssl-reqd --insecure --ftp-pasv `
                --user "$($settings.username):$($settings.password)" `
                --output $temporario `
                "ftp://$($settings.ftpServer)/superdunga.com.br/$file"
            if ($LASTEXITCODE -ne 0) {
                throw "Falha ao baixar arquivo publicado para verificacao: $file (curl $LASTEXITCODE)."
            }

            $remoteHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $temporario).Hash
            $localHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $file).Hash
            if ($remoteHash -ne $localHash) {
                throw "Verificacao FTP falhou: $file"
            }
            Write-Host "Verificado no FTP: $file"
        } finally {
            [System.IO.File]::Delete($temporario)
        }
    }
} finally {
    Pop-Location
}
