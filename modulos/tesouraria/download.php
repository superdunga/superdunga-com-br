<?php
require '../../config/auth.php';

$arquivo = $_GET['file'] ?? '';

// segurança
$arquivo = str_replace(['..', '\\'], '', $arquivo);

// 🔥 REMOVE DOMÍNIO DUPLICADO
$arquivo = str_replace('superdunga.com.br/', '', $arquivo);

// monta caminho correto
$caminho = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($arquivo, '/');

if (!file_exists($caminho)) {
    die('Arquivo não encontrado: ' . $caminho);
}

// tipo do arquivo
$tipo = mime_content_type($caminho);

// headers download
header('Content-Description: File Transfer');
header('Content-Type: ' . $tipo);
header('Content-Disposition: attachment; filename="' . basename($caminho) . '"');
header('Content-Length: ' . filesize($caminho));

readfile($caminho);
exit;