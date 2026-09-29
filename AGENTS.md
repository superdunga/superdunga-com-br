# SuperDunga deployment

## PHP runtime

The production domain `superdunga.com.br` uses cPanel handler `ea-php72` (PHP 7.2), as configured in `/superdunga.com.br/.htaccess`. There is no overriding `.htaccess` in `modulos/` or `modulos/desconto_cheques/`. Keep project PHP code compatible with PHP 7.2. In particular, do not use arrow functions (`fn`), typed properties, nullsafe operators, `match`, or PHP 8-only helpers such as `str_contains`. Local XAMPP uses PHP 8.2, so `php -l` locally is not sufficient to establish production compatibility. Check new syntax and functions against PHP 7.2 before FTP deployment.

For this local project, FTP connection settings already exist in `C:\Projetos\download_ftp.ps1`. Do not copy credentials into this repository, chat output, or a new configuration file.

After committing and pushing only the intended files, publish them with `scripts/publish-ftp.ps1 -Files @('relative/path.php')` from the repository root. The helper reads the existing local settings, uploads to `/superdunga.com.br`, and verifies each remote file against the local SHA-256 hash. Do not edit project files directly in cPanel.
