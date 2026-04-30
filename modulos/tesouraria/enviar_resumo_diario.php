<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/conexao.php';

date_default_timezone_set('America/Sao_Paulo');

// 🔹 CONFIGURAÇÕES
$empresa_id = 1;
$token = "1777428395264-c69341c8ecdd4cf5bab225027a812a79";
$numero = "120363161715233488";

// 🔹 EVITAR ENVIO DUPLICADO
$stmt = $pdo_master->prepare("
    SELECT COUNT(*) 
    FROM controle_envio 
    WHERE data = CURDATE()
");
$stmt->execute();

if ($stmt->fetchColumn() > 0) {
    exit("Já enviado hoje");
}

// 🔹 PERÍODO OPERACIONAL (07:00 → 03:00)
$dataBase = date('Y-m-d');
$inicio = $dataBase . ' 07:00:00';
$fim = date('Y-m-d 03:00:00', strtotime($dataBase . ' +1 day'));

// 🔹 VENDAS DO DIA
$stmt = $pdo_master->prepare("
    SELECT SUM(TOTGERAL)
    FROM armazem_est007
    WHERE DTLANC BETWEEN ? AND ?
    AND CANCELADO = 'N'
");
$stmt->execute([$inicio, $fim]);
$vendas = $stmt->fetchColumn() ?: 0;

// 🔹 CAIXA SISTEMA (BNC001)
// 🔹 CAIXA SISTEMA (MESMA LÓGICA DA CONCILIACAO)
$stmt = $pdo_master->prepare("
    SELECT SUM(saldo_final) FROM (
        SELECT 
            DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)) AS data_operacional,
            b.CBCONTADOR,
            SUM(
                CASE 
                    WHEN b.TIPOMOV = 'C' THEN b.VALORMOV
                    WHEN b.TIPOMOV = 'D' THEN -b.VALORMOV
                    ELSE 0
                END
            ) AS saldo_final
        FROM armazem_bnc001 b

        INNER JOIN (
            SELECT DISTINCT CODCX
            FROM armazem_zconfig005
            WHERE CODCX IS NOT NULL
        ) z ON z.CODCX = b.CBCONTADOR

        LEFT JOIN armazem_bnc001_ids_temp t
            ON t.MOVCONTADOR = b.MOVCONTADOR

        WHERE b.DTLANC BETWEEN ? AND ?

        GROUP BY 
            DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)),
            b.CBCONTADOR
    ) x
");

$stmt->execute([$inicio, $fim]);
$sistema = $stmt->fetchColumn() ?: 0;
// 🔹 TESOURARIA (CORRETO)
$stmt = $pdo_master->prepare("
    SELECT SUM(
        CASE 
            WHEN d.tipo = 'entrada' THEN d.quantidade * d.valor_unitario
            WHEN d.tipo = 'saida' THEN - (d.quantidade * d.valor_unitario)
            ELSE 0
        END
    )
    FROM tesouraria_movimentacoes_detalhes d
    INNER JOIN tesouraria_movimentacoes m 
        ON m.id = d.movimentacao_id
    WHERE m.data_mov >= ?
      AND m.data_mov < ?
      AND m.empresa_id = ?
");
$stmt->execute([$inicio, $fim, $empresa_id]);
$tesouraria = $stmt->fetchColumn() ?: 0;

// 🔹 DIFERENÇA
$diferenca = $sistema - $tesouraria;

// 🔹 MENSAGEM
$msg = "📊 *Resumo do Dia*\n\n";
$msg .= "📅 " . date('d/m/Y') . "\n\n";
$msg .= "🛒 Vendas: R$ " . number_format($vendas, 2, ',', '.') . "\n";
$msg .= "💵 Caixa Sistema: R$ " . number_format($sistema, 2, ',', '.') . "\n";
$msg .= "🧾 Tesouraria: R$ " . number_format($tesouraria, 2, ',', '.') . "\n";
$msg .= "⚖️ Diferença: R$ " . number_format($diferenca, 2, ',', '.') . "\n\n";

if ($diferenca == 0) {
    $msg .= "✅ Caixa conferido";
} else {
    $msg .= "🚨 Divergência no caixa";
}

// 🔹 ENVIO WHATSAPP
$url = "https://api-whatsapp.wascript.com.br/api/enviar-texto/$token";

$payload = [
    "phone" => $numero,
    "message" => $msg
];

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json"
]);

$response = curl_exec($ch);

if ($response === false) {
    $erro = curl_error($ch);
    file_put_contents("log_whatsapp.txt", date('Y-m-d H:i:s') . " - ERRO: $erro" . PHP_EOL, FILE_APPEND);
} else {
    file_put_contents("log_whatsapp.txt", date('Y-m-d H:i:s') . " - OK: $response" . PHP_EOL, FILE_APPEND);

    // 🔹 REGISTRA ENVIO
    $pdo_master->exec("INSERT INTO controle_envio (data) VALUES (CURDATE())");
}

curl_close($ch);