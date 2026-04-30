param(
    [int]$Port = 8080
)

$ErrorActionPreference = "Stop"
$url = "http://127.0.0.1:$Port/login.php"

try {
    $response = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 10
    Write-Host "OK $($response.StatusCode): $url"
} catch {
    Write-Host "Falha ao acessar $url"
    Write-Host $_.Exception.Message
    exit 1
}
