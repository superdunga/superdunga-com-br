<?php
require '../../config/auth.php';
require '../../config/conexao.php';

if ($_SESSION['nivel'] !== 'MASTER') {
    die("Acesso negado.");
}

$empresa_id = $_SESSION['empresa_id'];

try {

    $pdo_master->beginTransaction();

    /*
      Zera estoque (mantemos geral, pois tabela não tem empresa_id)
    */
    $pdo_master->exec("UPDATE tesouraria_estoque SET quantidade = 0");

    /*
      Recalcula SOMENTE da empresa logada
    */
    $stmt = $pdo_master->prepare("
        SELECT 
            d.tipo_dinheiro_id,
            SUM(
                CASE 
                    WHEN d.tipo = 'entrada' THEN d.quantidade
                    WHEN d.tipo = 'saida' THEN -d.quantidade
                    ELSE 0
                END
            ) AS saldo
        FROM tesouraria_movimentacoes_detalhes d
        INNER JOIN tesouraria_movimentacoes m
            ON m.id = d.movimentacao_id
        WHERE m.empresa_id = ?
        GROUP BY d.tipo_dinheiro_id
    ");

    $stmt->execute([$empresa_id]);
    $saldos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($saldos as $row) {

        $stmtUp = $pdo_master->prepare("
            UPDATE tesouraria_estoque
            SET quantidade = ?
            WHERE tipo_dinheiro_id = ?
        ");

        $stmtUp->execute([
            (int)$row['saldo'],
            $row['tipo_dinheiro_id']
        ]);
    }

    $pdo_master->commit();

    header("Location: inventario.php?recalculo=ok");
    exit;

} catch (Exception $e) {

    if ($pdo_master->inTransaction()) {
        $pdo_master->rollBack();
    }

    die("Erro ao recalcular estoque: " . $e->getMessage());
}