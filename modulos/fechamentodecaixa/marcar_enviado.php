<?php
require '../../config/conexao.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(["erro" => "JSON vazio"]);
    exit;
}

$empresa = (int)($input['empresa'] ?? 0);
$resultados = $input['resultados'] ?? null;

// Compatibilidade temporaria com o formato antigo da empresa 1.
if (!is_array($resultados) && array_is_list($input)) {
    $resultados = [];
    foreach ($input as $item) {
        if (!empty($item['CRCONTADOR'])) {
            $resultados[] = [
                'CRCONTADOR' => (int)$item['CRCONTADOR'],
                'status' => 'SINCRONIZADO',
            ];
        }
    }
    $empresa = 1;
}

if ($empresa <= 0 || !is_array($resultados) || empty($resultados)) {
    http_response_code(400);
    echo json_encode(["erro" => "Empresa ou resultados nao informados"]);
    exit;
}

$marcarSucesso = $pdo_master->prepare("
    UPDATE armazem_cr001
    SET enviado_firebird = 'S',
        data_envio_firebird = NOW(),
        tentativa_envio = COALESCE(tentativa_envio, 0) + 1,
        erro_envio = NULL
    WHERE EMPRESA = ? AND CRCONTADOR = ?
");
$marcarErro = $pdo_master->prepare("
    UPDATE armazem_cr001
    SET enviado_firebird = 'E',
        data_envio_firebird = NOW(),
        tentativa_envio = COALESCE(tentativa_envio, 0) + 1,
        erro_envio = ?
    WHERE EMPRESA = ? AND CRCONTADOR = ?
");

$sucessos = 0;
$erros = 0;
$pdo_master->beginTransaction();

try {
    foreach ($resultados as $resultado) {
        $crcontador = (int)($resultado['CRCONTADOR'] ?? 0);
        if ($crcontador <= 0) {
            continue;
        }

        if (($resultado['status'] ?? '') === 'SINCRONIZADO') {
            $marcarSucesso->execute([$empresa, $crcontador]);
            $sucessos += $marcarSucesso->rowCount();
        } else {
            $erro = trim((string)($resultado['erro'] ?? 'Firebird nao confirmou os valores enviados'));
            $marcarErro->execute([$erro, $empresa, $crcontador]);
            $erros += $marcarErro->rowCount();
        }
    }
    $pdo_master->commit();
} catch (Throwable $e) {
    $pdo_master->rollBack();
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
    exit;
}

echo json_encode([
    "status" => "ok",
    "empresa" => $empresa,
    "sincronizados" => $sucessos,
    "erros" => $erros,
]);
