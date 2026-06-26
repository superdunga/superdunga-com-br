<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresa_id = $_SESSION['empresa_id'];
$nivelUsuario = $_SESSION['nivel'] ?? '';
$isMaster = $nivelUsuario === 'MASTER';
$podeVerDetalhes = in_array($nivelUsuario, ['MASTER', 'OPERADOR'], true);

function saldoComSinalTesouraria(float $valor): string
{
    if (abs($valor) < 0.01) {
        return '<span class="fw-bold">R$ 0,00</span>';
    }

    $sinal = $valor < 0 ? 'D' : 'C';
    $classe = $valor < 0 ? 'text-danger border-danger' : 'text-success border-success';

    return 'R$ ' . number_format(abs($valor), 2, ',', '.') .
        ' <span class="badge bg-white border ' . $classe . '">' . $sinal . '</span>';
}

/* =========================
   EXCLUIR MOVIMENTACAO (MASTER)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_movimentacao') {
    if (!$isMaster) {
        die('Acesso negado.');
    }

    $movId = (int)($_POST['movimentacao_id'] ?? 0);
    $redirectQuery = preg_replace('/[^a-zA-Z0-9_%=&.+\-]/', '', $_POST['redirect_query'] ?? '');
    $redirectUrl = 'extrato.php' . ($redirectQuery !== '' ? '?' . $redirectQuery : '');

    if ($movId <= 0) {
        header("Location: {$redirectUrl}");
        exit;
    }

    try {
        $pdo_master->beginTransaction();

        $stmtMov = $pdo_master->prepare("
            SELECT id
            FROM tesouraria_movimentacoes
            WHERE id = ?
              AND empresa_id = ?
            FOR UPDATE
        ");
        $stmtMov->execute([$movId, $empresa_id]);
        $mov = $stmtMov->fetch(PDO::FETCH_ASSOC);

        if (!$mov) {
            throw new Exception('Movimentacao nao encontrada.');
        }

        $stmtDetalhes = $pdo_master->prepare("
            SELECT tipo, tipo_dinheiro_id, quantidade
            FROM tesouraria_movimentacoes_detalhes
            WHERE movimentacao_id = ?
        ");
        $stmtDetalhes->execute([$movId]);
        $detalhes = $stmtDetalhes->fetchAll(PDO::FETCH_ASSOC);

        $stmtReverteEntrada = $pdo_master->prepare("
            UPDATE tesouraria_estoque
            SET quantidade = quantidade - ?
            WHERE tipo_dinheiro_id = ?
        ");

        $stmtReverteSaida = $pdo_master->prepare("
            UPDATE tesouraria_estoque
            SET quantidade = quantidade + ?
            WHERE tipo_dinheiro_id = ?
        ");
        $stmtInsereEstoque = $pdo_master->prepare("
            INSERT INTO tesouraria_estoque (tipo_dinheiro_id, quantidade)
            VALUES (?, ?)
        ");

        foreach ($detalhes as $det) {
            if ($det['tipo'] === 'entrada') {
                $stmtReverteEntrada->execute([
                    (int)$det['quantidade'],
                    (int)$det['tipo_dinheiro_id']
                ]);
            } else {
                $stmtReverteSaida->execute([
                    (int)$det['quantidade'],
                    (int)$det['tipo_dinheiro_id']
                ]);

                if ($stmtReverteSaida->rowCount() === 0) {
                    $stmtInsereEstoque->execute([
                        (int)$det['tipo_dinheiro_id'],
                        (int)$det['quantidade']
                    ]);
                }
            }
        }

        $stmtArquivos = $pdo_master->prepare("
            SELECT caminho_arquivo
            FROM tesouraria_comprovantes
            WHERE movimentacao_id = ?
        ");
        $stmtArquivos->execute([$movId]);
        $arquivosRemover = $stmtArquivos->fetchAll(PDO::FETCH_COLUMN);

        $pdo_master->prepare("DELETE FROM tesouraria_movimentacoes_detalhes WHERE movimentacao_id = ?")->execute([$movId]);
        $pdo_master->prepare("DELETE FROM tesouraria_comprovantes WHERE movimentacao_id = ?")->execute([$movId]);
        $pdo_master->prepare("DELETE FROM tesouraria_movimentacoes WHERE id = ? AND empresa_id = ?")->execute([$movId, $empresa_id]);

        $pdo_master->commit();

        $baseUploads = realpath(__DIR__ . '/../../uploads/comprovantes');
        if ($baseUploads) {
            foreach ($arquivosRemover as $arquivo) {
                $arquivoRelativo = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string)$arquivo);
                $arquivoRelativo = preg_replace('#^uploads[\\\\/]comprovantes[\\\\/]#', '', $arquivoRelativo);
                $caminho = realpath($baseUploads . DIRECTORY_SEPARATOR . $arquivoRelativo);

                if ($caminho && strpos($caminho, $baseUploads) === 0 && is_file($caminho)) {
                    @unlink($caminho);
                }
            }
        }

        $separador = strpos($redirectUrl, '?') === false ? '?' : '&';
        header("Location: {$redirectUrl}{$separador}excluido=1");
        exit;
    } catch (Throwable $e) {
        if ($pdo_master->inTransaction()) {
            $pdo_master->rollBack();
        }

        die('Erro ao excluir movimentacao: ' . $e->getMessage());
    }
}

/* =========================
   DESFAZER MATCH FIREBIRD (MASTER)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'desfazer_match_firebird') {
    if (!$isMaster) {
        die('Acesso negado.');
    }

    $movId = (int)($_POST['movimentacao_id'] ?? 0);
    $redirectQuery = preg_replace('/[^a-zA-Z0-9_%=&.+\-]/', '', $_POST['redirect_query'] ?? '');
    $redirectUrl = 'extrato.php' . ($redirectQuery !== '' ? '?' . $redirectQuery : '');

    if ($movId <= 0) {
        header("Location: {$redirectUrl}");
        exit;
    }

    try {
        $stmtDesfazer = $pdo_master->prepare("
            UPDATE tesouraria_movimentacoes
            SET firebird_id = NULL,
                firebird_tabela = NULL,
                conciliado = 'N'
            WHERE id = ?
              AND empresa_id = ?
              AND conciliado = 'S'
              AND firebird_id IS NOT NULL
        ");
        $stmtDesfazer->execute([$movId, $empresa_id]);

        $separador = strpos($redirectUrl, '?') === false ? '?' : '&';
        header("Location: {$redirectUrl}{$separador}match_desfeito=" . ($stmtDesfazer->rowCount() === 1 ? '1' : '0'));
        exit;
    } catch (Throwable $e) {
        die('Erro ao desfazer match: ' . $e->getMessage());
    }
}

/* =========================
   FILTROS
========================= */

// DATA (mês atual automático)
$data_ini = $_GET['data_ini'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');

// TIPO
$tipo = $_GET['tipo'] ?? '';

// HISTÓRICO
$historico = $_GET['historico'] ?? '';
$queryAtual = http_build_query([
    'data_ini' => $data_ini,
    'data_fim' => $data_fim,
    'tipo' => $tipo,
    'historico' => $historico
]);

$where = "WHERE m.empresa_id = ?";
$params = [$empresa_id];

// filtro data
if (!empty($data_ini)) {
    $where .= " AND DATE(m.data_mov) >= ?";
    $params[] = $data_ini;
}

if (!empty($data_fim)) {
    $where .= " AND DATE(m.data_mov) <= ?";
    $params[] = $data_fim;
}

// filtro tipo
if ($tipo === 'C' || $tipo === 'D') {
    $where .= " AND m.tipo_operacao = ?";
    $params[] = $tipo;
}

// filtro histórico
if (!empty($historico)) {
    $where .= " AND m.observacao LIKE ?";
    $params[] = "%$historico%";
}

/* =========================
   SALDO INICIAL
========================= */

$saldoInicial = 0;

if (!empty($data_ini)) {

    $sqlSaldo = "
        SELECT SUM(valor_operacao) as saldo
        FROM tesouraria_movimentacoes
        WHERE empresa_id = ?
          AND DATE(data_mov) < ?
    ";

    $stmtSaldo = $pdo_master->prepare($sqlSaldo);
    $stmtSaldo->execute([$empresa_id, $data_ini]);

    $saldoInicial = (float) ($stmtSaldo->fetchColumn() ?? 0);
}

/* =========================
   BUSCAR MOVIMENTAÇÕES
========================= */
$stmt = $pdo_master->prepare("
    SELECT 
        m.*,
        GROUP_CONCAT(c.caminho_arquivo ORDER BY c.id SEPARATOR '|') AS arquivos,
        GROUP_CONCAT(c.nome_original ORDER BY c.id SEPARATOR '|') AS nomes
    FROM tesouraria_movimentacoes m
    LEFT JOIN tesouraria_comprovantes c 
        ON c.movimentacao_id = m.id
    $where
    GROUP BY 
        m.id, m.empresa_id, m.data_mov, m.tipo_operacao, 
        m.valor_operacao, m.valor_entregue, m.valor_troco, 
        m.usuario_id, m.observacao, m.caminho_foto, 
        m.firebird_tabela, m.firebird_id, m.conciliado
    ORDER BY m.id ASC
");

$stmt->execute($params);
$movimentacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   DETALHAMENTO (MASTER / OPERADOR)
========================= */
$detalhesMov = [];

if ($podeVerDetalhes) {

    $sql = "
        SELECT 
            d.movimentacao_id,
            t.descricao,
            t.valor,
            d.quantidade,
            d.tipo
        FROM tesouraria_movimentacoes_detalhes d
        JOIN tesouraria_tipos_dinheiro t 
            ON t.id = d.tipo_dinheiro_id
        ORDER BY d.movimentacao_id, CAST(t.valor AS DECIMAL(10,2)) ASC, d.tipo ASC, t.descricao ASC
    ";

    $dados = $pdo_master->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dados as $d) {
        $detalhesMov[$d['movimentacao_id']][] = $d;
    }
}

require '../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-body">

        <h4 class="mb-3">Extrato da Tesouraria</h4>

        <?php if (!empty($_GET['excluido'])): ?>
            <div class="alert alert-success">
                Movimentacao excluida com sucesso.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['match_desfeito'])): ?>
            <?php if ($_GET['match_desfeito'] === '1'): ?>
                <div class="alert alert-success">
                    Match Firebird desfeito com sucesso.
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    Nenhum match Firebird ativo foi encontrado para desfazer.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- FILTROS -->
        <form method="GET" class="row g-2 mb-3">

            <div class="col-md-2">
                <label>Data Inicial</label>
                <input type="date" name="data_ini" class="form-control"
                       value="<?= $data_ini ?>">
            </div>

            <div class="col-md-2">
                <label>Data Final</label>
                <input type="date" name="data_fim" class="form-control"
                       value="<?= $data_fim ?>">
            </div>

            <div class="col-md-2">
                <label>Tipo</label>
                <select name="tipo" class="form-control">
                    <option value="">Todos</option>
                    <option value="C" <?= $tipo == 'C' ? 'selected' : '' ?>>Crédito</option>
                    <option value="D" <?= $tipo == 'D' ? 'selected' : '' ?>>Débito</option>
                </select>
            </div>

            <div class="col-md-4">
                <label>Histórico</label>
                <input type="text" name="historico" class="form-control"
                       value="<?= htmlspecialchars($historico) ?>">
            </div>

            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-primary w-100">Filtrar</button>
            </div>

        </form>

        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Data</th>
                        <th>ID</th>
                        <th>Histórico</th>
                        <th>Tipo</th>
                        <th>Comprovante / Detalhe</th>
                        <th>Valor</th>
                        <th>Saldo</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (!empty($movimentacoes)): ?>
                    <?php $saldo = $saldoInicial; ?>

                    <?php foreach ($movimentacoes as $m): ?>
                        <?php
                        $valor = (float)$m['valor_operacao'];
                        $saldo += $valor;
                        ?>

                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($m['data_mov'])) ?></td>
                            <td><?= $m['id'] ?></td>
                            <td><?= htmlspecialchars($m['observacao']) ?></td>

                            <td>
                                <?= $m['tipo_operacao'] === 'D'
                                    ? '<span class="text-danger">Débito</span>'
                                    : ($m['tipo_operacao'] === 'C'
                                        ? '<span class="text-success">Crédito</span>'
                                        : '<span class="text-primary">Troca</span>') ?>
                            </td>

                            <!-- ✅ ALTERAÇÃO CIRÚRGICA AQUI -->
                            <td>
                                <div class="d-flex align-items-center gap-1">

                                    <?php if ($podeVerDetalhes): ?>

                                        <button 
                                            class="btn btn-sm btn-outline-dark"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalMov<?= $m['id'] ?>"
                                            title="Detalhes">
                                            &#128269;
                                        </button>

                                    <?php endif; ?>

                                    <?php if ($isMaster): ?>

                                        <a href="movimentar.php?id=<?= $m['id'] ?>" 
                                           class="btn btn-sm btn-outline-dark"
                                           title="Editar">
                                            ✏️
                                        </a>

                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Excluir definitivamente a movimentacao #<?= (int)$m['id'] ?>? Esta acao tambem reverte o estoque da tesouraria.');">
                                            <input type="hidden" name="acao" value="excluir_movimentacao">
                                            <input type="hidden" name="movimentacao_id" value="<?= (int)$m['id'] ?>">
                                            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($queryAtual) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir">
                                                Excluir
                                            </button>
                                        </form>

                                    <?php endif; ?>

                                    <?php
                                    if (!empty($m['arquivos'])) {

                                        $arquivos = explode('|', $m['arquivos']);

                                        foreach ($arquivos as $arq) {

                                            echo '
                                            <a href="download.php?file=' . urlencode($arq) . '" 
                                               class="btn btn-sm btn-outline-dark"
                                               title="Baixar comprovante">
                                                📄
                                            </a>';
                                        }

                                    }
                                    ?>

                                    <?php if ($isMaster && ($m['conciliado'] ?? '') === 'S' && !empty($m['firebird_id'])): ?>
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Desfazer o match Firebird da movimentacao #<?= (int)$m['id'] ?>?');">
                                            <input type="hidden" name="acao" value="desfazer_match_firebird">
                                            <input type="hidden" name="movimentacao_id" value="<?= (int)$m['id'] ?>">
                                            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars($queryAtual) ?>">
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-warning"
                                                    title="Desfazer match Firebird <?= (int)$m['firebird_id'] ?>">
                                                &#8634;
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                </div>
                            </td>

                            <td class="<?= $valor < 0 ? 'text-danger' : 'text-success' ?>">
                                R$ <?= number_format($valor, 2, ',', '.') ?>
                            </td>

                            <td>
                                <strong><?= saldoComSinalTesouraria((float)$saldo) ?></strong>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                <?php endif; ?>

                </tbody>
            </table>
        </div>

    </div>
</div>

<!-- MODAIS -->
<?php if ($podeVerDetalhes): ?>
<?php foreach ($movimentacoes as $m): ?>

<div class="modal fade" id="modalMov<?= $m['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5>Movimentação #<?= $m['id'] ?></h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <?php
                $itens = $detalhesMov[$m['id']] ?? [];
                usort($itens, function (array $a, array $b): int {
                    $valorA = (float)str_replace(',', '.', (string)($a['valor'] ?? 0));
                    $valorB = (float)str_replace(',', '.', (string)($b['valor'] ?? 0));

                    if ($valorA === $valorB) {
                        return strcmp((string)($a['descricao'] ?? ''), (string)($b['descricao'] ?? ''));
                    }

                    return $valorA <=> $valorB;
                });
                $entrada = 0;
                $saida = 0;
                ?>

                <?php if ($itens): ?>

                    <?php foreach ($itens as $i): 
                        $valorLinha = $i['quantidade'] * $i['valor'];

                        if ($i['tipo'] === 'entrada') {
                            $entrada += $valorLinha;
                        } else {
                            $saida += $valorLinha;
                        }
                    ?>

                        <div class="d-flex justify-content-between border-bottom py-1">
                            <div>
                                <strong><?= $i['descricao'] ?></strong><br>
                                <small>Qtd: <?= $i['quantidade'] ?></small>
                            </div>

                            <div class="<?= $i['tipo'] === 'entrada' ? 'text-success' : 'text-danger' ?>">
                                <?= $i['tipo'] === 'entrada' ? '+' : '-' ?>
                                R$ <?= number_format($valorLinha, 2, ',', '.') ?>
                            </div>
                        </div>

                    <?php endforeach; ?>

                    <hr>

                    <div class="d-flex justify-content-between">
                        <strong>Entrada:</strong>
                        <span class="text-success">R$ <?= number_format($entrada, 2, ',', '.') ?></span>
                    </div>

                    <div class="d-flex justify-content-between">
                        <strong>Saída:</strong>
                        <span class="text-danger">R$ <?= number_format($saida, 2, ',', '.') ?></span>
                    </div>

                    <hr>

                    <div class="d-flex justify-content-between">
                        <strong>Total:</strong>
                        <strong>R$ <?= number_format($entrada - $saida, 2, ',', '.') ?></strong>
                    </div>

                <?php else: ?>

                    <div class="text-center text-danger">
                        ⚠️ Sem detalhamento
                    </div>

                <?php endif; ?>

            </div>

        </div>
    </div>
</div>

<?php endforeach; ?>
<?php endif; ?>

<style>
.btn-voltar-fixo {
    position: fixed;
    bottom: 15px;
    left: 15px;
}
</style>

<div class="btn-voltar-fixo">
    <button onclick="window.location.href='menu_tesouraria.php'" class="btn btn-secondary">
        ← Voltar
    </button>
</div>

<?php require '../../layout/footer.php'; ?>
