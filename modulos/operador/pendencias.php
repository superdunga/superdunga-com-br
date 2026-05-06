<?php
require '../../config/auth.php';
require '../../config/conexao.php';

function garantirTabelaPendenciasOperador(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS operador_pendencias_checks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            empresa_id INT NOT NULL,
            data_referencia DATE NOT NULL,
            chave VARCHAR(80) NOT NULL,
            quantidade INT NOT NULL DEFAULT 0,
            usuario_nome VARCHAR(150) NULL,
            verificado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_operador_pendencia_dia (usuario_id, empresa_id, data_referencia, chave),
            INDEX idx_operador_pendencias_data (data_referencia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function moedaOperador($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function contarTesourariaPendencias(PDO $pdo): array
{
    $pendentes = (int)$pdo->query("
        SELECT COUNT(*)
        FROM tesouraria_movimentacoes t
        WHERE t.conciliado = 'N'
          AND t.tipo_operacao <> 'T'
    ")->fetchColumn();

    $firebird = (int)$pdo->query("
        SELECT COUNT(*)
        FROM armazem_bnc001 f
        WHERE f.CBCONTADOR = 8
          AND f.DTMOV > '2026-04-15'
          AND (
              COALESCE(f.deletado, 'N') <> 'S'
              OR f.DTMOV >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          )
          AND CAST(f.VALORMOV AS CHAR) REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'
          AND NOT EXISTS (
              SELECT 1
              FROM tesouraria_movimentacoes tx
              WHERE tx.firebird_id = f.MOVCONTADOR
          )
    ")->fetchColumn();

    return [
        'quantidade' => $pendentes + $firebird,
        'detalhes' => [
            'Pendentes de conciliacao' => $pendentes,
            'Firebird nao conciliados' => $firebird,
        ],
    ];
}

function contarDinheiroDivergente(PDO $pdo, string $mes): array
{
    $inicio = $mes . '-01 07:00:00';
    $fim = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS qtd, COALESCE(SUM(ABS(x.saldo_final)), 0) AS total
        FROM (
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
            WHERE b.DTLANC BETWEEN ? AND ?
              AND COALESCE(b.deletado, 'N') <> 'S'
            GROUP BY DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)), b.CBCONTADOR
        ) x
        WHERE x.data_operacional <> CURDATE()
          AND ABS(x.saldo_final) >= 0.01
    ");
    $stmt->execute([$inicio, $fim]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total' => 0];

    return [
        'quantidade' => (int)$row['qtd'],
        'detalhes' => [
            'Caixas divergentes' => (int)$row['qtd'],
            'Diferenca acumulada' => moedaOperador($row['total']),
        ],
    ];
}

function contarPrazoPendente(PDO $pdo, string $mes): array
{
    $stmtDatas = $pdo->prepare("
        SELECT DISTINCT DATE(data_venda) AS data_base
        FROM armazem_conciliacao_recebimentos
        WHERE DATE(data_venda) LIKE ?
        ORDER BY data_base DESC
    ");
    $stmtDatas->execute([$mes . '%']);
    $datas = $stmtDatas->fetchAll(PDO::FETCH_COLUMN);

    $diasPendentes = 0;
    $pendentesSistemaTotal = 0;
    $pendentesCR001Total = 0;

    $stmtPendRec = $pdo->prepare("
        SELECT COUNT(*)
        FROM armazem_conciliacao_recebimentos r
        WHERE r.data_venda BETWEEN ? AND ?
          AND NOT EXISTS (
              SELECT 1
              FROM armazem_cr001 c
              WHERE c.recebimento_id = r.id
                AND COALESCE(c.excluido_firebird, 'N') = 'N'
          )
    ");

    $stmtPendCr = $pdo->prepare("
        SELECT COUNT(*)
        FROM armazem_cr001 c
        WHERE c.DTLANC BETWEEN ? AND ?
          AND c.CMCONTADOR <> 9
          AND c.recebimento_id IS NULL
          AND NOT (c.CMCONTADOR = 1 AND c.STATUS = 'QT')
          AND COALESCE(c.excluido_firebird, 'N') = 'N'
    ");

    foreach ($datas as $data) {
        $inicio = date('Y-m-d 07:00:00', strtotime($data));
        $fim = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));

        $stmtPendRec->execute([$inicio, $fim]);
        $pendentesSistema = (int)$stmtPendRec->fetchColumn();

        $stmtPendCr->execute([$inicio, $fim]);
        $pendentesCR001 = (int)$stmtPendCr->fetchColumn();

        if ($pendentesSistema > 0 || $pendentesCR001 > 0) {
            $diasPendentes++;
            $pendentesSistemaTotal += $pendentesSistema;
            $pendentesCR001Total += $pendentesCR001;
        }
    }

    return [
        'quantidade' => $diasPendentes,
        'detalhes' => [
            'Dias pendentes' => $diasPendentes,
            'Recebiveis nao conciliados' => $pendentesSistemaTotal,
            'CR001 nao conciliados' => $pendentesCR001Total,
        ],
    ];
}

function contarItensForaPadrao(PDO $pdo, string $dataIni, string $dataFim): array
{
    $dataIniSql = date('Y-m-d 00:00:00', strtotime($dataIni));
    $dataFimSql = date('Y-m-d 23:59:59', strtotime($dataFim));

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM armazem_est006 i
        INNER JOIN armazem_est005 c
            ON c.COMPRACONTADOR = i.ITEMCOMPRACONTADOR
        INNER JOIN armazem_est004 p
            ON p.CONTAPRODUTO = i.PRODUTO
        LEFT JOIN auditoria_compras_itens_verificados v
            ON v.itemcomprador = i.ITEMCOMPRACONTADOR
           AND v.compraconta = i.COMPRACONTA
           AND v.produto = i.PRODUTO
        WHERE COALESCE(c.excluido_firebird, 'N') <> 'S'
          AND COALESCE(c.CANCELADO, 'N') <> 'S'
          AND COALESCE(i.excluido_firebird, 'N') <> 'S'
          AND COALESCE(i.CANCELADO, 'N') <> 'S'
          AND COALESCE(p.excluido_firebird, 'N') <> 'S'
          AND p.PRECOFINAL > 0
          AND ABS(((p.PVENDA1ANT / p.PRECOFINAL) - 1) * 100) >= 60
          AND v.id IS NULL
          AND c.DTEMISSAO BETWEEN ? AND ?
    ");
    $stmt->execute([$dataIniSql, $dataFimSql]);
    $quantidade = (int)$stmt->fetchColumn();

    return [
        'quantidade' => $quantidade,
        'detalhes' => [
            'Itens pendentes' => $quantidade,
        ],
    ];
}

function buscarChecks(PDO $pdo, int $usuarioId, int $empresaId, string $data): array
{
    $stmt = $pdo->prepare("
        SELECT chave
        FROM operador_pendencias_checks
        WHERE usuario_id = ?
          AND empresa_id = ?
          AND data_referencia = ?
    ");
    $stmt->execute([$usuarioId, $empresaId, $data]);
    return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}

garantirTabelaPendenciasOperador($pdo_master);

$usuarioId = (int)$_SESSION['usuario_id'];
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$hoje = date('Y-m-d');
$mes = date('Y-m');
$dataIniMes = date('Y-m-01');
$dataFimMes = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'verificar') {
    $chave = preg_replace('/[^a-z0-9_]/', '', $_POST['chave'] ?? '');
    $quantidade = max(0, (int)($_POST['quantidade'] ?? 0));

    if ($chave !== '') {
        $stmt = $pdo_master->prepare("
            INSERT INTO operador_pendencias_checks
                (usuario_id, empresa_id, data_referencia, chave, quantidade, usuario_nome)
            VALUES
                (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                quantidade = VALUES(quantidade),
                usuario_nome = VALUES(usuario_nome),
                verificado_em = NOW()
        ");
        $stmt->execute([
            $usuarioId,
            $empresaId,
            $hoje,
            $chave,
            $quantidade,
            $_SESSION['usuario_nome'] ?? null,
        ]);
    }

    header('Location: pendencias.php');
    exit;
}

$checks = buscarChecks($pdo_master, $usuarioId, $empresaId, $hoje);

$tarefas = [
    'tesouraria' => array_merge([
        'titulo' => 'Itens nao conciliados na tesouraria',
        'descricao' => 'Pendentes de conciliacao e lancamentos Firebird nao conciliados.',
        'link' => '../tesouraria/conciliar.php',
    ], contarTesourariaPendencias($pdo_master)),
    'dinheiro' => array_merge([
        'titulo' => 'Conciliacao de dinheiro',
        'descricao' => 'Caixas do mes corrente com saldo divergente.',
        'link' => '../fechamentodecaixa/conciliacao_dinheiro_divergentes.php?mes=' . urlencode($mes),
    ], contarDinheiroDivergente($pdo_master, $mes)),
    'vendas_prazo' => array_merge([
        'titulo' => 'Resumo de vendas a prazo',
        'descricao' => 'Dias do mes corrente com status pendente.',
        'link' => '../fechamentodecaixa/resumo_prazo.php?mes=' . urlencode($mes),
    ], contarPrazoPendente($pdo_master, $mes)),
    'itens_fora_padrao' => array_merge([
        'titulo' => 'Itens fora do padrao',
        'descricao' => 'Compras do mes corrente com margem acima ou abaixo de 60%.',
        'link' => '../auditoria/itens_fora_padrao.php?data_ini=' . urlencode($dataIniMes) . '&data_fim=' . urlencode($dataFimMes),
    ], contarItensForaPadrao($pdo_master, $dataIniMes, $dataFimMes)),
];

$bloqueiosPendentes = 0;
foreach ($tarefas as $chave => $tarefa) {
    if ((int)$tarefa['quantidade'] > 0 && !isset($checks[$chave])) {
        $bloqueiosPendentes++;
    }
}

if ($bloqueiosPendentes === 0) {
    $_SESSION['operador_pendencias_liberado_data'] = $hoje;
}

require '../../layout/header.php';
?>

<div class="card shadow-sm mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1 class="h5 mb-1">Pendencias do operador</h1>
            <small class="text-muted">Confira as pendencias operacionais de hoje antes de acessar o restante do sistema.</small>
        </div>
        <?php if ($bloqueiosPendentes === 0): ?>
            <a href="../../index.php" class="btn btn-primary">Continuar</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($bloqueiosPendentes > 0): ?>
            <div class="alert alert-warning mb-3">
                Existem <?= (int)$bloqueiosPendentes ?> bloco(s) pendente(s) de verificacao. Marque cada bloco pendente como verificado para liberar a navegacao.
            </div>
        <?php else: ?>
            <div class="alert alert-success mb-3">
                Todas as pendencias do dia foram verificadas. A navegacao esta liberada.
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <?php foreach ($tarefas as $chave => $tarefa): ?>
                <?php
                    $quantidade = (int)$tarefa['quantidade'];
                    $verificado = isset($checks[$chave]) || $quantidade === 0;
                    $badgeClasse = $quantidade === 0 ? 'success' : ($verificado ? 'primary' : 'warning text-dark');
                    $badgeTexto = $quantidade === 0 ? 'Sem pendencia' : ($verificado ? 'Verificado' : 'Pendente');
                ?>
                <div class="col-lg-6">
                    <div class="border rounded-2 p-3 h-100 bg-white">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <h2 class="h6 fw-bold mb-1"><?= htmlspecialchars($tarefa['titulo']) ?></h2>
                                <p class="text-muted small mb-0"><?= htmlspecialchars($tarefa['descricao']) ?></p>
                            </div>
                            <span class="badge bg-<?= htmlspecialchars($badgeClasse) ?>"><?= htmlspecialchars($badgeTexto) ?></span>
                        </div>

                        <div class="display-6 fw-bold mb-2"><?= $quantidade ?></div>

                        <div class="small text-muted mb-3">
                            <?php foreach ($tarefa['detalhes'] as $rotulo => $valor): ?>
                                <div class="d-flex justify-content-between gap-3 border-bottom py-1">
                                    <span><?= htmlspecialchars($rotulo) ?></span>
                                    <strong class="text-dark"><?= htmlspecialchars((string)$valor) ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <a href="<?= htmlspecialchars($tarefa['link']) ?>" class="btn btn-outline-primary">Abrir detalhe</a>

                            <?php if ($quantidade > 0 && !$verificado): ?>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="acao" value="verificar">
                                    <input type="hidden" name="chave" value="<?= htmlspecialchars($chave) ?>">
                                    <input type="hidden" name="quantidade" value="<?= $quantidade ?>">
                                    <button class="btn btn-success">Marcar verificado</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
