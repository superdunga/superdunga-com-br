<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresa_id = (int)$_SESSION['empresa_id'];
$usuario_id = (int)($_SESSION['usuario_id'] ?? 0);

function garantirTabelaCaixasFinalizados(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fechamento_caixas_finalizados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            data_operacional DATE NOT NULL,
            cbcontador INT NOT NULL,
            usuario_id INT NULL,
            finalizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_caixa_finalizado (empresa_id, data_operacional, cbcontador),
            INDEX idx_caixa_finalizado_data (empresa_id, data_operacional)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

garantirTabelaCaixasFinalizados($pdo_master);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $dataPost = $_POST['data_operacional'] ?? '';
    $caixaPost = (int)($_POST['cbcontador'] ?? 0);

    if ($dataPost !== '' && $caixaPost > 0) {
        if ($acao === 'finalizar_caixa') {
            $stmtFinalizar = $pdo_master->prepare("
                INSERT INTO fechamento_caixas_finalizados
                    (empresa_id, data_operacional, cbcontador, usuario_id, finalizado_em)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    usuario_id = VALUES(usuario_id),
                    finalizado_em = NOW()
            ");
            $stmtFinalizar->execute([$empresa_id, $dataPost, $caixaPost, $usuario_id ?: null]);
        } elseif ($acao === 'reabrir_caixa') {
            $stmtReabrir = $pdo_master->prepare("
                DELETE FROM fechamento_caixas_finalizados
                WHERE empresa_id = ?
                  AND data_operacional = ?
                  AND cbcontador = ?
            ");
            $stmtReabrir->execute([$empresa_id, $dataPost, $caixaPost]);
        }
    }

    $queryRedirect = http_build_query([
        'mes' => $_POST['mes'] ?? date('Y-m'),
        'finalizado' => $_POST['finalizado'] ?? 'todos',
    ]);
    header('Location: conciliacao_dinheiro.php?' . $queryRedirect);
    exit;
}

$mes = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $mes = date('Y-m');
}

$filtroFinalizado = $_GET['finalizado'] ?? 'todos';
if (!in_array($filtroFinalizado, ['todos', 'finalizado', 'nao_finalizado'], true)) {
    $filtroFinalizado = 'todos';
}

$inicio = $mes . '-01 07:00:00';
$fim = date('Y-m-d 03:00:00', strtotime($mes . '-01 +1 month'));
if ($mes === date('Y-m')) {
    $fim = date('Y-m-d H:i:s');
}

$whereFinalizado = '';
if ($filtroFinalizado === 'finalizado') {
    $whereFinalizado = ' AND f.id IS NOT NULL';
} elseif ($filtroFinalizado === 'nao_finalizado') {
    $whereFinalizado = ' AND f.id IS NULL';
}

$sql = "
SELECT
    DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)) AS data_operacional,
    b.CBCONTADOR,
    SUM(
        CASE
            WHEN b.TIPOMOV = 'C' THEN b.VALORMOV
            WHEN b.TIPOMOV = 'D' THEN -b.VALORMOV
            ELSE 0
        END
    ) AS saldo_final,
    f.finalizado_em
FROM armazem_bnc001 b
INNER JOIN (
    SELECT DISTINCT CODCX
    FROM armazem_zconfig005
    WHERE CODCX IS NOT NULL
      AND EMPRESA = ?
) z ON z.CODCX = b.CBCONTADOR
LEFT JOIN fechamento_caixas_finalizados f
    ON f.empresa_id = b.EMPRESA
   AND f.data_operacional = DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR))
   AND f.cbcontador = b.CBCONTADOR
WHERE b.DTLANC BETWEEN ? AND ?
  AND b.EMPRESA = ?
  AND COALESCE(b.deletado, 'N') <> 'S'
  $whereFinalizado
GROUP BY
    DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)),
    b.CBCONTADOR,
    f.finalizado_em
ORDER BY
    data_operacional DESC,
    b.CBCONTADOR
";

$stmt = $pdo_master->prepare($sql);
$stmt->execute([$empresa_id, $inicio, $fim, $empresa_id]);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmtPrazoCaixa = $pdo_master->prepare("
    SELECT
        DATE(DATE_SUB(c.DTLANC, INTERVAL 7 HOUR)) AS data_operacional,
        z.CODCX AS cbcontador,
        COUNT(*) AS qtd_cr_pendente,
        COALESCE(SUM(c.VLRPARCELA), 0) AS total_cr_pendente
    FROM armazem_cr001 c
    INNER JOIN armazem_zconfig005 z
        ON z.EMPRESA = c.EMPRESA
       AND z.CODUSER = c.USERLANC
       AND z.CODCX IS NOT NULL
    WHERE c.DTLANC BETWEEN ? AND ?
      AND c.EMPRESA = ?
      AND c.CMCONTADOR <> 9
      AND c.recebimento_id IS NULL
      AND COALESCE(c.STATUS, '') <> 'QT'
      AND COALESCE(c.excluido_firebird, 'N') = 'N'
    GROUP BY DATE(DATE_SUB(c.DTLANC, INTERVAL 7 HOUR)), z.CODCX
");
$stmtPrazoCaixa->execute([$inicio, $fim, $empresa_id]);
$prazoPorCaixa = [];
foreach ($stmtPrazoCaixa->fetchAll(PDO::FETCH_ASSOC) as $prazo) {
    $chavePrazo = $prazo['data_operacional'] . '|' . $prazo['cbcontador'];
    $prazoPorCaixa[$chavePrazo] = [
        'qtd' => (int)$prazo['qtd_cr_pendente'],
        'total' => (float)$prazo['total_cr_pendente'],
    ];
}

$stmtRecebiveisSemCaixa = $pdo_master->prepare("
    SELECT
        DATE(DATE_SUB(r.data_venda, INTERVAL 7 HOUR)) AS data_operacional,
        COUNT(*) AS qtd_recebivel_pendente,
        COALESCE(SUM(r.valor_bruto), 0) AS total_recebivel_pendente
    FROM armazem_conciliacao_recebimentos r
    WHERE r.data_venda BETWEEN ? AND ?
      AND r.empresa_id = ?
      AND r.CRCONTADOR IS NULL
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_cr001 c
          WHERE c.recebimento_id = r.id
            AND c.EMPRESA = ?
            AND COALESCE(c.STATUS, '') <> 'QT'
            AND COALESCE(c.excluido_firebird, 'N') = 'N'
      )
    GROUP BY DATE(DATE_SUB(r.data_venda, INTERVAL 7 HOUR))
");
$stmtRecebiveisSemCaixa->execute([$inicio, $fim, $empresa_id, $empresa_id]);
$recebiveisSemCaixaPorDia = [];
foreach ($stmtRecebiveisSemCaixa->fetchAll(PDO::FETCH_ASSOC) as $recebivelPendente) {
    $recebiveisSemCaixaPorDia[$recebivelPendente['data_operacional']] = [
        'qtd' => (int)$recebivelPendente['qtd_recebivel_pendente'],
        'total' => (float)$recebivelPendente['total_recebivel_pendente'],
    ];
}

require '../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Conciliacao de Dinheiro (Caixas validos)</h5>

        <div class="d-flex gap-2 flex-wrap">
            <a href="menu_fechamento.php" class="btn btn-secondary">Voltar</a>
            <a href="resumo_prazo.php?mes=<?= urlencode($mes) ?>" class="btn btn-outline-primary">Resumo a Prazo</a>

            <form method="GET" class="d-flex gap-2">
                <input type="month" name="mes" value="<?= htmlspecialchars($mes) ?>" class="form-control me-2">
                <select name="finalizado" class="form-select">
                    <option value="todos" <?= $filtroFinalizado === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="nao_finalizado" <?= $filtroFinalizado === 'nao_finalizado' ? 'selected' : '' ?>>Nao finalizados</option>
                    <option value="finalizado" <?= $filtroFinalizado === 'finalizado' ? 'selected' : '' ?>>Finalizados</option>
                </select>
                <button class="btn btn-primary">Filtrar</button>
            </form>
        </div>
    </div>

    <div class="card-body table-responsive">
        <table class="table table-sm table-bordered text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Data</th>
                    <th>Caixa</th>
                    <th>Dif. Dinheiro</th>
                    <th>CR001 pend.</th>
                    <th>Dif. Recebiveis</th>
                    <th>Dif. Final</th>
                    <th>Status</th>
                    <th>Acao</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($resultados) === 0): ?>
                    <tr>
                        <td colspan="8" class="text-muted">Nenhum registro encontrado</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($resultados as $r): ?>
                        <?php
                            $saldo = (float)$r['saldo_final'];
                            $dataOp = $r['data_operacional'];
                            $hoje = date('Y-m-d');
                            $finalizado = !empty($r['finalizado_em']);
                            $chavePrazo = $dataOp . '|' . $r['CBCONTADOR'];
                            $prazoCaixa = $prazoPorCaixa[$chavePrazo] ?? ['qtd' => 0, 'total' => 0.0];
                            $diferencaRecebiveis = -1 * (float)$prazoCaixa['total'];
                            $diferencaFinal = (abs($saldo) <= 0.01 ? 0.0 : $saldo) + $diferencaRecebiveis;

                            if ($finalizado) {
                                $status = 'FINALIZADO';
                                $classe = 'success';
                            } elseif ($dataOp === $hoje) {
                                $status = 'EM ABERTO';
                                $classe = 'warning';
                            } elseif (abs($saldo) <= 0.01 && abs($diferencaRecebiveis) <= 0.01) {
                                $status = 'OK';
                                $classe = 'success';
                            } else {
                                $status = 'DIVERGENTE';
                                $classe = 'danger';
                            }
                        ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($dataOp)) ?></td>
                            <td><?= htmlspecialchars((string)$r['CBCONTADOR']) ?></td>
                            <td class="fw-bold text-<?= $classe ?>">
                                R$ <?= number_format($saldo, 2, ',', '.') ?>
                            </td>
                            <td>
                                <?= (int)$prazoCaixa['qtd'] ?> |
                                R$ <?= number_format((float)$prazoCaixa['total'], 2, ',', '.') ?>
                            </td>
                            <td class="fw-bold <?= abs($diferencaRecebiveis) <= 0.01 ? 'text-success' : 'text-danger' ?>">
                                R$ <?= number_format($diferencaRecebiveis, 2, ',', '.') ?>
                            </td>
                            <td class="fw-bold <?= abs($diferencaFinal) <= 0.01 ? 'text-success' : 'text-danger' ?>">
                                R$ <?= number_format($diferencaFinal, 2, ',', '.') ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $classe ?>"><?= $status ?></span>
                            </td>
                            <td>
                                <div class="d-flex justify-content-center gap-1">
                                    <a href="extrato_caixa.php?data=<?= urlencode($dataOp) ?>&caixa=<?= urlencode((string)$r['CBCONTADOR']) ?>" class="btn btn-sm btn-outline-dark">
                                        Ver
                                    </a>

                                    <?php if ($finalizado): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="acao" value="reabrir_caixa">
                                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                                            <input type="hidden" name="finalizado" value="<?= htmlspecialchars($filtroFinalizado) ?>">
                                            <input type="hidden" name="data_operacional" value="<?= htmlspecialchars($dataOp) ?>">
                                            <input type="hidden" name="cbcontador" value="<?= (int)$r['CBCONTADOR'] ?>">
                                            <button class="btn btn-sm btn-outline-secondary" onclick="return confirm('Reabrir este caixa?')">Reabrir</button>
                                        </form>
                                    <?php elseif ($dataOp !== $hoje): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="acao" value="finalizar_caixa">
                                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                                            <input type="hidden" name="finalizado" value="<?= htmlspecialchars($filtroFinalizado) ?>">
                                            <input type="hidden" name="data_operacional" value="<?= htmlspecialchars($dataOp) ?>">
                                            <input type="hidden" name="cbcontador" value="<?= (int)$r['CBCONTADOR'] ?>">
                                            <button class="btn btn-sm btn-success" onclick="return confirm('Marcar este caixa como finalizado?')">Finalizar</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
            $diasComRecebiveisSemCaixa = array_filter($recebiveisSemCaixaPorDia, static function (array $item): bool {
                return (int)$item['qtd'] > 0;
            });
        ?>
        <?php if (!empty($diasComRecebiveisSemCaixa)): ?>
            <div class="alert alert-warning mt-3 mb-0">
                <strong>Recebiveis sem CR001 nao separados por caixa:</strong>
                <?php foreach ($diasComRecebiveisSemCaixa as $dataPendencia => $pendencia): ?>
                    <span class="d-inline-block me-3">
                        <?= date('d/m/Y', strtotime($dataPendencia)) ?>:
                        <?= (int)$pendencia['qtd'] ?> |
                        R$ <?= number_format((float)$pendencia['total'], 2, ',', '.') ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
