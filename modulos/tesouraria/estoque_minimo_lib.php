<?php

function garantirTabelaEstoqueMinimo(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tesouraria_estoque_minimos (
            empresa_id INT NOT NULL,
            tipo_dinheiro_id INT NOT NULL,
            quantidade_minima INT NOT NULL DEFAULT 0,
            atualizado_por INT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (empresa_id, tipo_dinheiro_id),
            KEY idx_estoque_minimo_tipo (tipo_dinheiro_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function listarEstoqueMinimo(PDO $pdo, int $empresaId): array
{
    $sql = "
        SELECT
            t.id,
            t.tipo,
            t.valor,
            t.descricao,
            COALESCE(s.quantidade_atual, 0) AS quantidade_atual,
            COALESCE(em.quantidade_minima, 0) AS quantidade_minima
        FROM tesouraria_tipos_dinheiro t
        LEFT JOIN (
            SELECT
                d.tipo_dinheiro_id,
                SUM(CASE
                    WHEN d.tipo = 'entrada' THEN d.quantidade
                    WHEN d.tipo = 'saida' THEN -d.quantidade
                    ELSE 0
                END) AS quantidade_atual
            FROM tesouraria_movimentacoes_detalhes d
            INNER JOIN tesouraria_movimentacoes m
                ON m.id = d.movimentacao_id
            WHERE m.empresa_id = ?
            GROUP BY d.tipo_dinheiro_id
        ) s ON s.tipo_dinheiro_id = t.id
        LEFT JOIN tesouraria_estoque_minimos em
            ON em.empresa_id = ?
           AND em.tipo_dinheiro_id = t.id
        ORDER BY FIELD(t.tipo, 'CEDULA', 'MOEDA'), t.valor DESC, t.id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$empresaId, $empresaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function resumirEstoqueMinimo(array $itens): array
{
    $resumo = [
        'configurados' => 0,
        'alertas' => 0,
        'quantidade_repor' => 0,
        'valor_reposicao' => 0.0,
    ];

    foreach ($itens as $item) {
        $minimo = max(0, (int)$item['quantidade_minima']);
        $atual = (int)$item['quantidade_atual'];
        if ($minimo > 0) {
            $resumo['configurados']++;
        }

        $repor = $minimo > $atual ? $minimo - $atual : 0;
        if ($minimo > 0 && $repor > 0) {
            $resumo['alertas']++;
            $resumo['quantidade_repor'] += $repor;
            $resumo['valor_reposicao'] += $repor * (float)$item['valor'];
        }
    }

    return $resumo;
}
