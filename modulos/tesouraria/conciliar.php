<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

exigirNivel('OPERADOR');

$empresa_id = $_SESSION['empresa_id'];
$linhas_afetadas = 0;
$erro_conciliacao = '';

// EXECUTA A CONCILIAÇÃO AUTOMÁTICA AO ABRIR A PÁGINA
try {

    $sql = "
    UPDATE tesouraria_movimentacoes t
    INNER JOIN (
        SELECT 
            t.id AS tesouraria_id,
            MAX(f.MOVCONTADOR) AS firebird_id
        FROM tesouraria_movimentacoes t
        INNER JOIN armazem_bnc001 f
            ON ABS(CAST(t.valor_operacao AS DECIMAL(15,2))) = CAST(f.VALORMOV AS DECIMAL(15,2))
           AND DATE(t.data_mov) = DATE(f.DTMOV)
        WHERE t.conciliado = 'N'
          AND t.tipo_operacao <> 'T'    
          AND f.CBCONTADOR = 8
          AND CAST(f.VALORMOV AS CHAR) REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'
        GROUP BY t.id
        HAVING COUNT(f.MOVCONTADOR) = 1
    ) x
        ON x.tesouraria_id = t.id
    SET
        t.firebird_id = x.firebird_id,
        t.conciliado = 'S'
    ";

    $stmt = $pdo_master->prepare($sql);
    $stmt->execute();
    $linhas_afetadas = $stmt->rowCount();

} catch (Exception $e) {
    $erro_conciliacao = $e->getMessage();
}

// PENDENTES
$pendentes = $pdo_master->query("
    SELECT 
        t.id, 
        t.data_mov, 
        t.valor_operacao, 
        t.observacao,
        (
            SELECT COUNT(*)
            FROM armazem_bnc001 f
            WHERE ABS(CAST(t.valor_operacao AS DECIMAL(15,2))) = CAST(f.VALORMOV AS DECIMAL(15,2))
              AND DATE(f.DTMOV) = DATE(t.data_mov)
              AND f.CBCONTADOR = 8
              AND CAST(f.VALORMOV AS CHAR) REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'
        ) AS qtd_matches
    FROM tesouraria_movimentacoes t
    WHERE t.conciliado = 'N'
    AND t.tipo_operacao <> 'T'
    ORDER BY t.data_mov DESC
")->fetchAll(PDO::FETCH_ASSOC);

// CONCILIADOS
$conciliados = $pdo_master->query("
    SELECT 
        id, 
        data_mov, 
        valor_operacao, 
        firebird_id, 
        observacao
    FROM tesouraria_movimentacoes
    WHERE conciliado = 'S'  
    AND tipo_operacao <> 'T'
    ORDER BY data_mov DESC
")->fetchAll(PDO::FETCH_ASSOC);

// FIREBIRD NÃO CONCILIADOS
$firebird_nao = $pdo_master->query("
    SELECT 
        f.MOVCONTADOR,
        f.DTMOV,
        f.HISTMOV,
        f.VALORMOV,
        f.deletado
    FROM armazem_bnc001 f
    WHERE f.CBCONTADOR = 8
      AND CAST(f.VALORMOV AS CHAR) REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'
      AND f.MOVCONTADOR NOT IN (
          SELECT firebird_id
          FROM tesouraria_movimentacoes
          WHERE firebird_id IS NOT NULL
      )
    ORDER BY f.DTMOV DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h4>🔄 Conciliação da Tesouraria</h4>
        <hr>

        <?php if ($erro_conciliacao): ?>
            <div class="alert alert-danger">
                <strong>Erro ao executar a conciliação:</strong><br>
                <?= htmlspecialchars($erro_conciliacao) ?>
            </div>
        <?php else: ?>
            <div class="alert alert-success">
                <strong>Conciliação executada com sucesso.</strong><br>
                Linhas conciliadas nesta execução: <?= (int)$linhas_afetadas ?>
            </div>
        <?php endif; ?>

        <div class="mt-3">
            <a href="conciliar.php" class="btn btn-primary">🔄 Atualizar tela</a>
            <a href="menu_tesouraria.php" class="btn btn-secondary">← Voltar</a>
        </div>
    </div>
</div>

<!-- 1️⃣ PENDENTES -->
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h5>⚠️ Pendentes de Conciliação (<?= count($pendentes) ?>)</h5>

        <table class="table table-sm table-bordered">
            <tr>
                <th>ID</th>
                <th>Data</th>
                <th>Valor</th>
                <th>Obs</th>
                <th>Matches</th>
                <?php if ($_SESSION['nivel'] === 'MASTER'): ?>
                    <th>Ações</th>
                <?php endif; ?>
            </tr>

            <?php foreach ($pendentes as $p): ?>
                <tr>
                    <td><?= $p['id'] ?></td>
                    <td><?= $p['data_mov'] ?></td>
                    <td>R$ <?= number_format($p['valor_operacao'], 2, ',', '.') ?></td>
                    <td><?= htmlspecialchars($p['observacao']) ?></td>
                    <td>
                        <?php if ($p['qtd_matches'] == 1): ?>
                            <span class="badge bg-success">1 (OK)</span>
                        <?php elseif ($p['qtd_matches'] > 1): ?>
                            <span class="badge bg-warning"><?= $p['qtd_matches'] ?> (Duplicado)</span>
                        <?php else: ?>
                            <span class="badge bg-danger">0 (Sem match)</span>
                        <?php endif; ?>
                    </td>

                    <?php if ($_SESSION['nivel'] === 'MASTER'): ?>
                    <td>
                        <a href="movimentar.php?id=<?= $p['id'] ?>" 
                           class="btn btn-sm btn-outline-warning">
                            ✏️
                        </a>
                    </td>
                    <?php endif; ?>

                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<!-- 2️⃣ CONCILIADOS -->
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h5>✅ Conciliados (<?= count($conciliados) ?>)</h5>

        <table class="table table-sm table-bordered">
            <tr>
                <th>ID</th>
                <th>Data</th>
                <th>Valor</th>
                <th>Obs</th>
                <th>Firebird</th>
            </tr>

            <?php foreach ($conciliados as $c): ?>
                <tr>
                    <td><?= $c['id'] ?></td>
                    <td><?= $c['data_mov'] ?></td>
                    <td>R$ <?= number_format($c['valor_operacao'], 2, ',', '.') ?></td>
                    <td><?= htmlspecialchars($c['observacao']) ?></td>
                    <td><?= $c['firebird_id'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<!-- 3️⃣ FIREBIRD NÃO CONCILIADOS -->
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h5>📌 Firebird NÃO conciliados (<?= count($firebird_nao) ?>)</h5>

        <table class="table table-sm table-bordered">
            <tr>
                <th>MovContador</th>
                <th>Data</th>
                <th>HISTMOV</th>
                <th>Valor</th>
                <th>Status</th>
            </tr>

            <?php foreach ($firebird_nao as $f): ?>
                <tr>
                    <td><?= $f['MOVCONTADOR'] ?></td>
                    <td><?= $f['DTMOV'] ?></td>
                    <td><?= htmlspecialchars($f['HISTMOV']) ?></td>
                    <td>R$ <?= number_format($f['VALORMOV'], 2, ',', '.') ?></td>
                    <td>
                        <?php if ($f['deletado'] === 'S'): ?>
                            <span class="badge bg-danger">Deletado</span>
                        <?php else: ?>
                            <span class="badge bg-success">Ativo</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>