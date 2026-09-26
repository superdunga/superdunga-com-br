param(
    [ValidateSet('armazem', 'emporio')]
    [string]$Source = 'armazem',
    [string]$Database = 'C:\Administrativo\Data\ESTOQUE.FDB',
    [string]$Client = 'C:\Program Files\Firebird\Firebird_4_0\fbclient.dll'
)

$ErrorActionPreference = 'Stop'
$mutex = [System.Threading.Mutex]::new($false, "Local\SuperDungaAuxiliarSync_$Source")
$acquired = $false
$logDir = Join-Path $env:APPDATA 'SuperDungaAuxiliar\logs'
$log = Join-Path $logDir ('sync-' + (Get-Date -Format 'yyyy-MM-dd') + '.log')

try {
    try {
        $acquired = $mutex.WaitOne(0)
    } catch [System.Threading.AbandonedMutexException] {
        $acquired = $true
    }
    if (-not $acquired) {
        throw 'Outra sincronizacao auxiliar esta em andamento.'
    }

    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
    $python = (Get-ItemProperty 'HKCU:\SOFTWARE\Python\PythonCore\3.13\InstallPath').ExecutablePath
    $script = Join-Path $PSScriptRoot 'est007_api.py'
    if (-not (Test-Path -LiteralPath $python) -or -not (Test-Path -LiteralPath $script)) {
        throw 'Python ou est007_api.py nao encontrado.'
    }

    $store = Join-Path $env:APPDATA 'SuperDungaAuxiliar'
    $firebirdCredential = Import-Clixml (Join-Path $store 'firebird-credencial.xml')
    $syncToken = Import-Clixml (Join-Path $store "$Source-sync-token.xml")
    $env:AUXILIAR_SOURCE = $Source
    $env:AUXILIAR_FIREBIRD_DSN = $Database
    $env:AUXILIAR_FIREBIRD_CLIENT = $Client
    $env:AUXILIAR_FIREBIRD_USER = $firebirdCredential.UserName
    $env:AUXILIAR_FIREBIRD_PASSWORD = $firebirdCredential.GetNetworkCredential().Password
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($syncToken)
    try {
        $env:AUXILIAR_SYNC_TOKEN = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
    }

    "$(Get-Date -Format s) Inicio: $Source" | Tee-Object -FilePath $log -Append
    foreach ($table in @('est026_auxiliar', 'est007_auxiliar')) {
        & $python -u $script --sync-table $table --page-size 50 2>&1 |
            Tee-Object -FilePath $log -Append
        if ($LASTEXITCODE -ne 0) {
            throw "Falha na sincronizacao de $table (codigo $LASTEXITCODE)."
        }
    }
    "$(Get-Date -Format s) Concluido: $Source" | Tee-Object -FilePath $log -Append
} catch {
    if (Test-Path -LiteralPath $logDir) {
        "$(Get-Date -Format s) ERRO: $($_.Exception.Message)" | Tee-Object -FilePath $log -Append
    }
    exit 1
} finally {
    Remove-Item Env:\AUXILIAR_SYNC_TOKEN, Env:\AUXILIAR_FIREBIRD_PASSWORD -ErrorAction SilentlyContinue
    if ($acquired) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
