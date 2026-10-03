# SuperDunga deployment

## PHP runtime

The production domain `superdunga.com.br` uses cPanel handler `ea-php72` (PHP 7.2), as configured in `/superdunga.com.br/.htaccess`. There is no overriding `.htaccess` in `modulos/` or `modulos/desconto_cheques/`. Keep project PHP code compatible with PHP 7.2. In particular, do not use arrow functions (`fn`), typed properties, nullsafe operators, `match`, or PHP 8-only helpers such as `str_contains`. Local XAMPP uses PHP 8.2, so `php -l` locally is not sufficient to establish production compatibility. Check new syntax and functions against PHP 7.2 before FTP deployment.

For this local project, FTP connection settings already exist in `C:\Projetos\download_ftp.ps1`. Do not copy credentials into this repository, chat output, or a new configuration file.

After committing and pushing only the intended files, publish them with `scripts/publish-ftp.ps1 -Files @('relative/path.php')` from the repository root. The helper reads the existing local settings, uploads to `/superdunga.com.br`, and verifies each remote file against the local SHA-256 hash. Do not edit project files directly in cPanel.

## Repository and production parity

The deployable source in `origin/master` must always match the files published on the production site. Treat commit, push, FTP upload, and remote hash verification as one deployment operation; do not leave a production fix only on FTP or a deployable fix only in the local working tree. Server-side integration scripts that are installed manually must also be committed and pushed in the matching project path after validation.

Before reporting a deployment as complete, compare the intended files with `origin/master` and confirm the production copy. If any previously deployed or locally completed change is not represented in both places, tell the user immediately which files differ, where each version currently exists, and the exact commit, push, upload, or verification still required. Generated backups, uploads, caches, logs, temporary files, and local credential helpers are operational artifacts and are excluded from this parity rule.
