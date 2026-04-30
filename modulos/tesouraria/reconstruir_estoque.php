<?php
require '../../config/conexao.php';

echo "<h3>Reconstruindo estoque...</h3>";

/* ZERA ESTOQUE */
$pdo_master->exec("TRUNCATE tesouraria_estoque");

/* BUSCA TODOS OS DETALHES */
$sql = "
    SELECT 
        tipo_dinheiro_id,
        tipo,
        quantidade
    FROM tesouraria_movimentacoes_detalhes
";

$dados = $pdo_master->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* AGRUPAR */
$estoque = [];

foreach ($dados as $d) {

    $id = $d['tipo_dinheiro_id'];
    $qtd = (int)$d['quantidade'];

    if (!isset($estoque[$id])) {
        $estoque[$id] = 0;
    }

    if ($d['tipo'] === 'entrada') {
        $estoque[$id] += $qtd;
    } else {
        $estoque[$id] -= $qtd;
    }
}

/* INSERIR NO BANCO */
$stmt = $pdo_master->prepare("
    INSERT INTO tesouraria_estoque (tipo_dinheiro_id, quantidade)
    VALUES (?, ?)
");

foreach ($estoque as $id => $qtd) {
    $stmt->execute([$id, $qtd]);
}

echo "<h4>Estoque reconstruído com sucesso!</h4>";