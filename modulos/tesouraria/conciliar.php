<?php
require '../../config/auth.php';
require '../../config/conexao.php';

exigirNivel('OPERADOR');

$empresa_id = (int)$_SESSION['empresa_id'];
$linhas_afetadas = 0;
$erro_conciliacao = '';
$erro_manual = $_GET['erro_manual'] ?? '';
$sucesso_manual = isset($_GET['manual']) && $_GET['manual'] === '1';
$sucesso_conferido = isset($_GET['conferido']) && $_GET['conferido'] === '1';

function garantirTabelaFirebirdConferidos(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tesouraria_firebird_conferidos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL DEFAULT 1,
            movcontador INT NOT NULL,
            usuario_id INT NOT NULL,
            usuario_nome VARCHAR(150) NULL,
            conferido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_firebird_conferido (empresa_id, movcontador),
            INDEX idx_firebird_conferido_em (conferido_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $colunaEmpresa = $pdo->query("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tesouraria_firebird_conferidos'
          AND COLUMN_NAME = 'empresa_id'
    ")->fetchColumn();

    if ((int)$colunaEmpresa === 0) {
        $pdo->exec("ALTER TABLE tesouraria_firebird_conferidos ADD COLUMN empresa_id INT NOT NULL DEFAULT 1 AFTER id");
        $pdo->exec("ALTER TABLE tesouraria_firebird_conferidos DROP INDEX uniq_firebird_conferido");
        $pdo->exec("ALTER TABLE tesouraria_firebird_conferidos ADD UNIQUE KEY uniq_firebird_conferido (empresa_id, movcontador)");
    }
}

garantirTabelaFirebirdConferidos($pdo_master);

if (empty($_SESSION['csrf_conciliar_tesouraria'])) {
    $_SESSION['csrf_conciliar_tesouraria'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_conciliar_tesouraria'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'conciliar_manual') {
    $tesourariaId = (int)($_POST['tesouraria_id'] ?? 0);
    $firebirdId = (int)($_POST['firebird_id'] ?? 0);
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $token)) {
        header('Location: conciliar.php?erro_manual=token');
        exit;
    }

    try {
        $pdo_master->beginTransaction();

        $stmt = $pdo_master->prepare("
            SELECT
                t.id AS tesouraria_id,
                f.MOVCONTADOR AS firebird_id
            FROM tesouraria_movimentacoes t
            INNER JOIN armazem_bnc001 f
                ON ABS(CAST(t.valor_operacao AS DECIMAL(15,2))) = CAST(f.VALORMOV AS DECIMAL(15,2))
               AND DATE(t.data_mov) = DATE(f.DTMOV)
            WHERE t.id = ?
              AND f.MOVCONTADOR = ?
              AND t.empresa_id = ?
              AND f.EMPRESA = ?
              AND t.conciliado = 'N'
              AND t.tipo_operacao <> 'T'
              AND f.CBCONTADOR = 8
              AND COALESCE(f.deletado, 'N') <> 'S'
              AND CAST(f.VALORMOV AS CHAR) REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'
              AND NOT EXISTS (
                  SELECT 1
                  FROM tesouraria_movimentacoes tx
                  WHERE tx.firebird_id = f.MOVCONTADOR
                    AND tx.empresa_id = t.empresa_id
              )
            LIMIT 1
        ");
        $stmt->execute([$tesourariaId, $firebirdId, $empresa_id, $empresa_id]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$match) {
            throw new RuntimeException('match_invalido');
        }

        $stmt = $pdo_master->prepare("
            UPDATE tesouraria_movimentacoes
            SET firebird_id = ?, firebird_tabela = 'armazem_bnc001', conciliado = 'S'
            WHERE id = ? AND empresa_id = ? AND conciliado = 'N'
        ");
        $stmt->execute([$firebirdId, $tesourariaId, $empresa_id]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('nao_atualizado');
        }

        $pdo_master->commit();
        header('Location: conciliar.php?manual=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo_master->inTransaction()) {
            $pdo_master->rollBack();
        }

        header('Location: conciliar.php?erro_manual=' . urlencode($e->getMessage()));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'marcar_firebird_conferido') {
    if ($_SESSION['nivel'] !== 'MASTER') {
        header('Location: conciliar.php?erro_manual=acesso_negado');
        exit;
    }

    $firebirdId = (int)($_POST['firebird_id'] ?? 0);
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $token)) {
        header('Location: conciliar.php?erro_manual=token');
        exit;
    }

    if ($firebirdId > 0) {
        $stmtCheckDeletado = $pdo_master->prepare("
            SELECT COALESCE(deletado, 'N')
            FROM armazem_bnc001
            WHERE MOVCONTADOR = ?
              AND EMPRESA = ?
            LIMIT 1
        ");
        $stmtCheckDeletado->execute([$firebirdId, $empresa_id]);
        $deletado = $stmtCheckDeletado->fetchColumn();

        if ($deletado !== 'S') {
            header('Location: conciliar.php?erro_manual=conferido_apenas_deletado');
            exit;
        }

        $stmt = $pdo_master->prepare("
            INSERT INTO tesouraria_firebird_conferidos
                (empresa_id, movcontador, usuario_id, usuario_nome)
            VALUES
                (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                usuario_id = VALUES(usuario_id),
                usuario_nome = VALUES(usuario_nome),
                conferido_em = NOW()
        ");
        $stmt->execute([
            $empresa_id,
            $firebirdId,
            (int)$_SESSION['usuario_id'],
            $_SESSION['usuario_nome'] ?? null
        ]);
    }

    header('Location: conciliar.php?conferido=1');
    exit;
}

// EXECUTA A CONCILIACAO AUTOMATICA AO ABRIR A PAGINA
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
          AND t.empresa_id = ?
          AND t.tipo_operacao <> 'T'
          AND f.EMPRESA = ?
          AND f.CBCONTADOR = 8
          AND COALESCE(f.deletado, 'N') <> 'S'
          AND CAST(f.VALORMOV AS CHAR) REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'
          AND NOT EXISTS (
              SELECT 1
              FROM tesouraria_movimentacoes tx
              WHERE tx.firebird_id = f.MOVCONTADOR
                AND tx.empresa_id = t.empresa_id
          )
        GROUP BY t.id
        HAVING COUNT(f.MOVCONTADOR) = 1
    ) x
        ON x.tesouraria_id = t.id
    SET
        t.firebird_id = x.firebird_id,
        t.firebird_tabela = 'armazem_bnc001',
        t.conciliado = 'S'
    ";

    $stmt = $pdo_master->prepare($sql);
    $stmt->execute([$empresa_id, $empresa_id]);
    $linhas_afetadas = $stmt->rowCount();

} catch (Exception $e) {
    $erro_conciliacao = $e->getMessage();
}

// PENDENTES
$stmtPendentes = $pdo_master->prepare("
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
              AND f.EMPRESA = ?
              AND f.CBCONTADOR = 8
              AND COALESCE(f.deletado, 'N') <> 'S'
              AND CAST(f.VALORMOV AS CHAR) REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'
              AND NOT EXISTS (
                  SELECT 1
                  FROM tesouraria_movimentacoes tx
                  WHERE tx.firebird_id = f.MOVCONTADOR
                    AND tx.empresa_id = t.empresa_id
              )
        ) AS qtd_matches
    FROM tesouraria_movimentacoes t
    WHERE t.conciliado = 'N'
    AND t.empresa_id = ?
    AND t.tipo_operacao <> 'T'
    ORDER BY t.data_mov DESC
");
$stmtPendentes->execute([$empresa_id, $empresa_id]);
$pendentes = $stmtPendentes->fetchAll(PDO::FETCH_ASSOC);

$candidatosPorTesouraria = [];

if (!empty($pendentes)) {
    $stmtCandidatos = $pdo_master->prepare("
        SELECT
            f.MOVCONTADOR,
            f.DTMOV,
            f.HISTMOV,
            f.VALORMOV,
            f.TIPODOCORIGEM,
            f.NUMDOCORIGEM,
            f.FCONTADOR
        FROM armazem_bnc001 f
        WHERE ABS(CAST(? AS DECIMAL(15,2))) = CAST(f.VALORMOV AS DECIMAL(15,2))
          AND DATE(f.DTMOV) = DATE(?)
          AND f.EMPRESA = ?
          AND f.CBCONTADOR = 8
          AND COALESCE(f.deletado, 'N') <> 'S'
          AND CAST(f.VALORMOV AS CHAR) REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$'
          AND NOT EXISTS (
              SELECT 1
              FROM tesouraria_movimentacoes tx
              WHERE tx.firebird_id = f.MOVCONTADOR
                AND tx.empresa_id = ?
          )
        ORDER BY f.DTLANC ASC, f.MOVCONTADOR ASC
    ");

    foreach ($pendentes as $p) {
        if ((int)$p['qtd_matches'] > 1) {
            $stmtCandidatos->execute([$p['valor_operacao'], $p['data_mov'], $empresa_id, $empresa_id]);
            $candidatosPorTesouraria[(int)$p['id']] = $stmtCandidatos->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// CONCILIADOS
$stmtConciliados = $pdo_master->prepare("
    SELECT
        id,
        data_mov,
        valor_operacao,
        firebird_id,
        observacao
    FROM tesouraria_movimentacoes
    WHERE conciliado = 'S'
    AND empresa_id = ?
    AND tipo_operacao <> 'T'
    ORDER BY data_mov DESC
");
$stmtConciliados->execute([$empresa_id]);
$conciliados = $stmtConciliados->fetchAll(PDO::FETCH_ASSOC);

// FIREBIRD NAO CONCILIADOS
$stmtFirebirdNao = $pdo_master->prepare("
    SELECT
        f.MOVCONTADOR,
        f.DTMOV,
        f.HISTMOV,
        f.VALORMOV,
        f.deletado
    FROM armazem_bnc001 f
    WHERE f.EMPRESA = ?
      AND f.CBCONTADOR = 8
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
            AND tx.empresa_id = ?
      )
      AND NOT EXISTS (
          SELECT 1
          FROM tesouraria_firebird_conferidos fc
          WHERE fc.movcontador = f.MOVCONTADOR
            AND fc.empresa_id = ?
      )
    ORDER BY f.DTMOV DESC
");
$stmtFirebirdNao->execute([$empresa_id, $empresa_id, $empresa_id]);
$firebird_nao = $stmtFirebirdNao->fetchAll(PDO::FETCH_ASSOC);

require '../../layout/header.php';
?>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h4>Conciliacao da Tesouraria</h4>
        <hr>

        <?php if ($sucesso_manual): ?>
            <div class="alert alert-success">
                Conciliacao manual realizada com sucesso.
            </div>
        <?php endif; ?>

        <?php if ($sucesso_conferido): ?>
            <div class="alert alert-success">
                Lancamento Firebird marcado como conferido.
            </div>
        <?php endif; ?>

        <?php if ($erro_manual): ?>
            <div class="alert alert-danger">
                Nao foi possivel realizar a conciliacao manual: <?= htmlspecialchars($erro_manual) ?>
            </div>
        <?php endif; ?>

        <?php if ($erro_conciliacao): ?>
            <div class="alert alert-danger">
                <strong>Erro ao executar a conciliacao:</strong><br>
                <?= htmlspecialchars($erro_conciliacao) ?>
            </div>
        <?php else: ?>
            <div class="alert alert-success">
                <strong>Conciliacao automatica executada com sucesso.</strong><br>
                Linhas conciliadas nesta execucao: <?= (int)$linhas_afetadas ?>
            </div>
        <?php endif; ?>

        <div class="mt-3">
            <a href="conciliar.php" class="btn btn-primary">Atualizar tela</a>
            <a href="menu_tesouraria.php" class="btn btn-secondary">Voltar</a>
        </div>
    </div>
</div>

<!-- PENDENTES -->
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h5>Pendentes de Conciliacao (<?= count($pendentes) ?>)</h5>

        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <tr>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Valor</th>
                    <th>Obs</th>
                    <th>Matches</th>
                    <?php if ($_SESSION['nivel'] === 'MASTER'): ?>
                        <th>Acoes</th>
                    <?php endif; ?>
                </tr>

                <?php foreach ($pendentes as $p): ?>
                    <tr>
                        <td><?= $p['id'] ?></td>
                        <td><?= $p['data_mov'] ?></td>
                        <td>R$ <?= number_format((float)$p['valor_operacao'], 2, ',', '.') ?></td>
                        <td><?= htmlspecialchars($p['observacao']) ?></td>
                        <td>
                            <?php if ($p['qtd_matches'] == 1): ?>
                                <span class="badge bg-success">1 (OK)</span>
                            <?php elseif ($p['qtd_matches'] > 1): ?>
                                <span class="badge bg-warning"><?= (int)$p['qtd_matches'] ?> (Duplicado)</span>
                            <?php else: ?>
                                <span class="badge bg-danger">0 (Sem match)</span>
                            <?php endif; ?>
                        </td>

                        <?php if ($_SESSION['nivel'] === 'MASTER'): ?>
                        <td>
                            <a href="movimentar.php?id=<?= $p['id'] ?>"
                               class="btn btn-sm btn-outline-warning">
                                Editar
                            </a>
                        </td>
                        <?php endif; ?>
                    </tr>

                    <?php if ((int)$p['qtd_matches'] > 1): ?>
                        <tr>
                            <td colspan="<?= $_SESSION['nivel'] === 'MASTER' ? 6 : 5 ?>" class="bg-light">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                                    <div>
                                        <strong>Escolha o Firebird para o lancamento pendente #<?= (int)$p['id'] ?></strong>
                                        <div class="text-muted small">
                                            Sistema: <?= htmlspecialchars($p['data_mov']) ?> |
                                            R$ <?= number_format((float)$p['valor_operacao'], 2, ',', '.') ?> |
                                            <?= htmlspecialchars($p['observacao']) ?>
                                        </div>
                                    </div>
                                    <span class="badge bg-warning align-self-start">
                                        <?= (int)$p['qtd_matches'] ?> candidatos
                                    </span>
                                </div>

                                <div class="table-responsive mt-2">
                                    <table class="table table-sm table-bordered mb-0">
                                        <tr>
                                            <th>MovContador</th>
                                            <th>Data</th>
                                            <th>Valor</th>
                                            <th>Historico</th>
                                            <th>Origem</th>
                                            <th>Fornecedor</th>
                                            <th>Acao</th>
                                        </tr>

                                        <?php foreach ($candidatosPorTesouraria[(int)$p['id']] ?? [] as $candidato): ?>
                                            <tr>
                                                <td><?= (int)$candidato['MOVCONTADOR'] ?></td>
                                                <td><?= htmlspecialchars($candidato['DTMOV']) ?></td>
                                                <td>R$ <?= number_format((float)$candidato['VALORMOV'], 2, ',', '.') ?></td>
                                                <td><?= htmlspecialchars($candidato['HISTMOV']) ?></td>
                                                <td><?= htmlspecialchars($candidato['TIPODOCORIGEM']) ?> <?= htmlspecialchars($candidato['NUMDOCORIGEM']) ?></td>
                                                <td><?= htmlspecialchars($candidato['FCONTADOR']) ?></td>
                                                <td>
                                                    <form method="POST" class="m-0">
                                                        <input type="hidden" name="acao" value="conciliar_manual">
                                                        <input type="hidden" name="tesouraria_id" value="<?= (int)$p['id'] ?>">
                                                        <input type="hidden" name="firebird_id" value="<?= (int)$candidato['MOVCONTADOR'] ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                        <button type="submit" class="btn btn-sm btn-success">
                                                            Conciliar #<?= (int)$p['id'] ?> com Firebird <?= (int)$candidato['MOVCONTADOR'] ?>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>

<!-- FIREBIRD NAO CONCILIADOS -->
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h5>Firebird nao conciliados apos 15/04/2026 (<?= count($firebird_nao) ?>)</h5>
        <p class="text-muted small mb-3">
            Esta lista inclui lancamentos ativos e deletados do Firebird. Deletados aparecem apenas para conferencia e nao entram na conciliacao automatica/manual.
        </p>

        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <tr>
                    <th>MovContador</th>
                    <th>Data</th>
                    <th>HISTMOV</th>
                    <th>Valor</th>
                    <th>Status</th>
                    <?php if ($_SESSION['nivel'] === 'MASTER'): ?>
                        <th>Conferido</th>
                    <?php endif; ?>
                </tr>

                <?php foreach ($firebird_nao as $f): ?>
                    <tr>
                        <td><?= $f['MOVCONTADOR'] ?></td>
                        <td><?= $f['DTMOV'] ?></td>
                        <td><?= htmlspecialchars($f['HISTMOV']) ?></td>
                        <td>R$ <?= number_format((float)$f['VALORMOV'], 2, ',', '.') ?></td>
                        <td>
                            <?php if ($f['deletado'] === 'S'): ?>
                                <span class="badge bg-danger">Deletado</span>
                            <?php else: ?>
                                <span class="badge bg-success">Ativo</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($_SESSION['nivel'] === 'MASTER' && $f['deletado'] === 'S'): ?>
                            <td>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="acao" value="marcar_firebird_conferido">
                                    <input type="hidden" name="firebird_id" value="<?= (int)$f['MOVCONTADOR'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        Conferido
                                    </button>
                                </form>
                            </td>
                        <?php elseif ($_SESSION['nivel'] === 'MASTER'): ?>
                            <td class="text-muted small">
                                Conciliacao obrigatoria
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>

<!-- CONCILIADOS -->
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <h5>Conciliados (<?= count($conciliados) ?>)</h5>

        <div class="table-responsive">
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
                        <td>R$ <?= number_format((float)$c['valor_operacao'], 2, ',', '.') ?></td>
                        <td><?= htmlspecialchars($c['observacao']) ?></td>
                        <td><?= $c['firebird_id'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
