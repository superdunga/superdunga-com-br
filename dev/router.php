<?php

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = realpath(__DIR__ . '/..' . $uri);
$root = realpath(__DIR__ . '/..');

$bloqueados = [
    '/.git',
    '/.env',
    '/config/',
    '/dev/',
    '/scripts/',
];

foreach ($bloqueados as $prefixo) {
    if (str_starts_with($uri, $prefixo)) {
        http_response_code(404);
        echo 'Arquivo nao encontrado.';
        return true;
    }
}

if ($path && $root && str_starts_with($path, $root)) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if (str_starts_with(str_replace('\\', '/', $uri), '/uploads/') && in_array($ext, ['php', 'phtml', 'phar'], true)) {
        http_response_code(403);
        echo 'Execucao bloqueada em uploads.';
        return true;
    }
}

return false;
