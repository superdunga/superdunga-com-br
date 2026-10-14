# SuperDunga deployment

## PHP runtime

The production domain `superdunga.com.br` uses cPanel handler `ea-php72` (PHP 7.2), as configured in `/superdunga.com.br/.htaccess`. There is no overriding `.htaccess` in `modulos/` or `modulos/desconto_cheques/`. Keep project PHP code compatible with PHP 7.2. In particular, do not use arrow functions (`fn`), typed properties, nullsafe operators, `match`, or PHP 8-only helpers such as `str_contains`. Local XAMPP uses PHP 8.2, so `php -l` locally is not sufficient to establish production compatibility. Check new syntax and functions against PHP 7.2 before FTP deployment.

For this local project, FTP connection settings already exist in `C:\Projetos\download_ftp.ps1`. Do not copy credentials into this repository, chat output, or a new configuration file.

After committing and pushing only the intended files, publish them with `scripts/publish-ftp.ps1 -Files @('relative/path.php')` from the repository root. The helper reads the existing local settings, uploads to `/superdunga.com.br`, and verifies each remote file against the local SHA-256 hash. Do not edit project files directly in cPanel.

## Repository and production parity

The deployable source in `origin/master` must always match the files published on the production site. Treat commit, push, FTP upload, and remote hash verification as one deployment operation; do not leave a production fix only on FTP or a deployable fix only in the local working tree. Server-side integration scripts that are installed manually must also be committed and pushed in the matching project path after validation.

Before reporting a deployment as complete, compare the intended files with `origin/master` and confirm the production copy. If any previously deployed or locally completed change is not represented in both places, tell the user immediately which files differ, where each version currently exists, and the exact commit, push, upload, or verification still required. Generated backups, uploads, caches, logs, temporary files, and local credential helpers are operational artifacts and are excluded from this parity rule.

## Firebird integration architecture

Never conflate the auxiliary servers with the main Armazem and Emporio servers.

- The Armazem auxiliary server and the Emporio auxiliary server run only the fiscal-document mirror in `docs/integracao_firebird/auxiliar`: `EST007` and `EST026`, in complete and incremental modes. They do not synchronize `CR001`, do not process general financial or stock tables, and do not send receipt reconciliations back to Firebird.
- The main Armazem server runs the general integration for SuperDunga company 1, including `enviar_dados.py` and the reverse reconciliation flow implemented by `enviar_conciliados.py`.
- The main Emporio server runs the general integration for the mappings Firebird 1 -> SuperDunga 4 and Firebird 6 -> SuperDunga 5, including `enviar_dados.py` and `enviar_conciliados.py`.
- A reconciliation created in SuperDunga is queued on the site and is applied to `CR001` by `enviar_conciliados.py` on the corresponding main server, never by an auxiliary fiscal server.

Before attributing an execution, update, log, scheduled task, or failure to a server, identify the exact script path and machine. Do not infer that an auxiliary server performed an operation merely because it belongs to the same store. Use `docs/integracao_firebird/README.md` as the architecture reference.

## Receipt import dates

When a receipt import file provides a valid expected payment date, that date is authoritative and must be stored in `data_prevista`. Rules that calculate `data_prevista` from `data_venda` and `CMCONTADOR` are fallbacks only for layouts that do not provide an expected date. Never replace a valid date supplied by an acquirer file with a generic D+1 or D+30 calculation.
