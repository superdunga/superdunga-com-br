<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 🔐 TOKEN
$token = "1777428395264-c69341c8ecdd4cf5bab225027a812a79";

// 📱 Número
$numero = "553798710505";

// 💬 Mensagem
$msg = "🚀 Teste API Waseller funcionando";

// 🌐 URL
$url = "https://api-whatsapp.wascript.com.br/api/enviar-texto/$token";

// 📦 PAYLOAD JSON
$payload = [
    "phone" => $numero,
    "message" => $msg
];

// 🔄 CURL
$ch = curl_init($url);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);

// 🔥 ENVIO EM JSON
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

// 🔥 HEADER CORRETO
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json"
]);

$response = curl_exec($ch);

if ($response === false) {
    echo "ERRO CURL: " . curl_error($ch);
} else {
    echo "<pre>";
    echo $response;
}

curl_close($ch);