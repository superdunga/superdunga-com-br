<?php

require_once __DIR__ . '/fpdf/fpdf.php';

spl_autoload_register(function ($classe) {
    $prefixo = 'setasign\\Fpdi\\';
    if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0) {
        return;
    }

    $relativo = substr($classe, strlen($prefixo));
    $arquivo = __DIR__ . '/fpdi/src/' . str_replace('\\', '/', $relativo) . '.php';
    if (is_file($arquivo)) {
        require_once $arquivo;
    }
});
