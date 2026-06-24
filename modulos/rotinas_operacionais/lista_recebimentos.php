<?php
require '../../config/auth.php';

$query = $_SERVER['QUERY_STRING'] ?? '';
$destino = 'recebimento_mercadorias.php' . ($query !== '' ? '?' . $query : '');

header('Location: ' . $destino);
exit;
