# Ambiente local

Use estes passos para testar o site na sua maquina antes de enviar por FTP.

## Iniciar o site

```powershell
.\scripts\start-local.ps1
```

Se o Windows bloquear scripts PowerShell, use:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\start-local.ps1
```

Acesse:

```text
http://127.0.0.1:8080/login.php
```

Para usar outra porta:

```powershell
.\scripts\start-local.ps1 -Port 8081
```

## Testar se o servidor respondeu

```powershell
.\scripts\test-local.ps1
```

## Parar o servidor

```powershell
.\scripts\stop-local.ps1
```

Ou, se o Windows bloquear scripts:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\stop-local.ps1
```

## Protecoes locais

O servidor local usa `dev/router.php` para bloquear acesso direto a `.git`, `config`, `dev`, `scripts` e execucao de PHP dentro de `uploads`.
