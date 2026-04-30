<?php

header('Content-Type: application/json');

try {

    $url = "http://SEU_IP_FIREBIRD:5000/executar/envio?token=123456";

    $resposta = file_get_contents($url);

    if (!$resposta) {
        throw new Exception("Sem resposta da API");
    }

    echo json_encode([
        "status" => "ok",
        "log" => $resposta
    ]);

} catch (Exception $e) {

    echo json_encode([
        "erro" => $e->getMessage()
    ]);
}