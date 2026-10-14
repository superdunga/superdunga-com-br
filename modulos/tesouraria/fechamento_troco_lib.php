<?php

function garantirTabelasFechamentoTroco(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tesouraria_fechamento_trocos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            movimentacao_id INT NOT NULL,
            empresa_id INT NOT NULL,
            usuario_id INT NOT NULL,
            valor_total DECIMAL(15,2) NOT NULL DEFAULT 0,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tft_movimentacao (movimentacao_id),
            INDEX idx_tft_empresa_data (empresa_id, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tesouraria_fechamento_trocos_detalhes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fechamento_troco_id INT NOT NULL,
            tipo_dinheiro_id INT NOT NULL,
            quantidade INT NOT NULL DEFAULT 0,
            valor_unitario DECIMAL(15,2) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_tftd_denominacao (fechamento_troco_id, tipo_dinheiro_id),
            INDEX idx_tftd_tipo_dinheiro (tipo_dinheiro_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function carregarTrocoFechamento(PDO $pdo, int $movimentacaoId): array
{
    $stmt = $pdo->prepare("
        SELECT d.tipo_dinheiro_id, d.quantidade
        FROM tesouraria_fechamento_trocos t
        INNER JOIN tesouraria_fechamento_trocos_detalhes d
            ON d.fechamento_troco_id = t.id
        WHERE t.movimentacao_id = ?
    ");
    $stmt->execute([$movimentacaoId]);

    $quantidades = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $quantidades[(int)$row['tipo_dinheiro_id']] = (int)$row['quantidade'];
    }

    return $quantidades;
}

function salvarTrocoFechamento(
    PDO $pdo,
    int $movimentacaoId,
    int $empresaId,
    int $usuarioId,
    array $quantidades,
    array $tiposMap
): void {
    $valorTotal = 0.0;
    foreach ($quantidades as $tipoDinheiroId => $quantidade) {
        $tipoDinheiroId = (int)$tipoDinheiroId;
        $quantidade = max(0, (int)$quantidade);
        if ($quantidade > 0 && isset($tiposMap[$tipoDinheiroId])) {
            $valorTotal += (float)$tiposMap[$tipoDinheiroId] * $quantidade;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO tesouraria_fechamento_trocos
            (movimentacao_id, empresa_id, usuario_id, valor_total)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            empresa_id = VALUES(empresa_id),
            usuario_id = VALUES(usuario_id),
            valor_total = VALUES(valor_total),
            atualizado_em = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        $movimentacaoId,
        $empresaId,
        $usuarioId,
        round($valorTotal, 2),
    ]);

    $stmtId = $pdo->prepare("
        SELECT id
        FROM tesouraria_fechamento_trocos
        WHERE movimentacao_id = ?
        LIMIT 1
    ");
    $stmtId->execute([$movimentacaoId]);
    $fechamentoTrocoId = (int)$stmtId->fetchColumn();

    $pdo->prepare("
        DELETE FROM tesouraria_fechamento_trocos_detalhes
        WHERE fechamento_troco_id = ?
    ")->execute([$fechamentoTrocoId]);

    $stmtDetalhe = $pdo->prepare("
        INSERT INTO tesouraria_fechamento_trocos_detalhes
            (fechamento_troco_id, tipo_dinheiro_id, quantidade, valor_unitario)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($quantidades as $tipoDinheiroId => $quantidade) {
        $tipoDinheiroId = (int)$tipoDinheiroId;
        $quantidade = max(0, (int)$quantidade);
        if ($quantidade > 0 && isset($tiposMap[$tipoDinheiroId])) {
            $stmtDetalhe->execute([
                $fechamentoTrocoId,
                $tipoDinheiroId,
                $quantidade,
                $tiposMap[$tipoDinheiroId],
            ]);
        }
    }
}
