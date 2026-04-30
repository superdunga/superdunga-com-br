<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// ==========================================
// CONFIGURAÇÃO
// ==========================================

// IP da máquina onde roda o Python
$url = "http://192.168.2.241:5000/dados";

// ==========================================
// REQUISIÇÃO
// ==========================================

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);

// ==========================================
// TRATAMENTO DE ERRO
// ==========================================

if (curl_errno($ch)) {
    echo json_encode([
        "erro" => "Falha ao conectar na API Python",
        "detalhe" => curl_error($ch)
    ]);
    curl_close($ch);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($httpCode != 200) {
    echo json_encode([
        "erro" => "API retornou erro HTTP",
        "codigo" => $httpCode
    ]);
    exit;
}

// ==========================================
// RETORNO FINAL
// ==========================================

echo $response;