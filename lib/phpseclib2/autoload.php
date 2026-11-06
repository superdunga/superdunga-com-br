<?php

spl_autoload_register(function ($classe) {
    $prefixo = 'phpseclib\\';
    if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0) {
        return;
    }

    $relativo = substr($classe, strlen($prefixo));
    $arquivo = __DIR__ . DIRECTORY_SEPARATOR . 'phpseclib' . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, $relativo) . '.php';

    if (is_file($arquivo)) {
        require $arquivo;
    }
});
