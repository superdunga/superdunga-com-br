<?php
require '../../config/conexao.php';

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? '';
if ($token !== '123456') {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso negado']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['erro' => 'JSON invalido']);
    exit;
}

$registros = isset($input['registros']) && is_array($input['registros'])
    ? $input['registros']
    : $input;

if (empty($registros)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Nenhum registro recebido']);
    exit;
}

try {
    $stmtOk = $pdo_master->prepare("
        UPDATE recebimento_mercadorias_itens
        SET status = 'atualizado_firebird',
            enviado_firebird = 'S',
            emb_qtde_atual = quantidade_por_caixa,
            data_envio_firebird = NOW(),
            tentativa_firebird = tentativa_firebird + 1,
            erro_firebird = NULL
        WHERE id = ?
    ");

    $stmtErro = $pdo_master->prepare("
        UPDATE recebimento_mercadorias_itens
        SET status = 'erro_firebird',
            enviado_firebird = 'E',
            data_envio_firebird = NOW(),
            tentativa_firebird = tentativa_firebird + 1,
            erro_firebird = ?
        WHERE id = ?
    ");

    $ok = 0;
    $erro = 0;

    foreach ($registros as $registro) {
        $id = (int)($registro['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $status = strtolower(trim((string)($registro['status'] ?? 'ok')));
        if (in_array($status, ['ok', 'sucesso', 'atualizado'], true)) {
            $stmtOk->execute([$id]);
            $ok += $stmtOk->rowCount();
            continue;
        }

        $mensagemErro = trim((string)($registro['erro'] ?? 'Erro ao atualizar no Firebird.'));
        $stmtErro->execute([mb_substr($mensagemErro, 0, 255), $id]);
        $erro += $stmtErro->rowCount();
    }

    echo json_encode([
        'status' => 'ok',
        'atualizados' => $ok,
        'erros' => $erro,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
