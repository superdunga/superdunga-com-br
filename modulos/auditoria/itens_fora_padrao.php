<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresa_id = (int)$_SESSION['empresa_id'];

function garantirTabelaVerificados(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auditoria_compras_itens_verificados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            itemcomprador INT NOT NULL,
            compraconta INT NOT NULL,
            produto INT NOT NULL,
            usuario_id INT NOT NULL,
            usuario_nome VARCHAR(150) NULL,
            verificado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            observacao VARCHAR(255) NULL,
            UNIQUE KEY uniq_auditoria_item_compra (itemcomprador, compraconta, produto),
            INDEX idx_auditoria_verificado_em (verificado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function moeda($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function numero($valor, int $casas = 2): string
{
    return number_format((float)$valor, $casas, ',', '.');
}

garantirTabelaVerificados($pdo_master);

$dataIni = $_GET['data_ini'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-d');
$dataIniSql = date('Y-m-d 00:00:00', strtotime($dataIni));
$dataFimSql = date('Y-m-d 23:59:59', strtotime($dataFim));
$fornecedor = trim($_GET['fornecedor'] ?? '');
$descricao = trim($_GET['descricao'] ?? '');
$margemMinima = 20;
$margemMaxima = 100;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'verificar') {
    $itemcomprador = (int)($_POST['itemcomprador'] ?? 0);
    $compraconta = (int)($_POST['compraconta'] ?? 0);
    $produto = (int)($_POST['produto'] ?? 0);

    if ($itemcomprador > 0 && $compraconta > 0 && $produto > 0) {
        $stmt = $pdo_master->prepare("
            INSERT INTO auditoria_compras_itens_verificados
                (itemcomprador, compraconta, produto, usuario_id, usuario_nome)
            VALUES
                (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                usuario_id = VALUES(usuario_id),
                usuario_nome = VALUES(usuario_nome),
                verificado_em = NOW()
        ");
        $stmt->execute([
            $itemcomprador,
            $compraconta,
            $produto,
            (int)$_SESSION['usuario_id'],
            $_SESSION['usuario_nome'] ?? null
        ]);
    }

    $query = $_GET;
    $query['ok'] = '1';
    header('Location: itens_fora_padrao.php?' . http_build_query($query));
    exit;
}

$where = [
    "c.EMPRESA = ?",
    "i.EMPRESA = ?",
    "p.EMPRESA = ?",
    "COALESCE(c.excluido_firebird, 'N') <> 'S'",
    "COALESCE(c.CANCELADO, 'N') <> 'S'",
    "COALESCE(i.excluido_firebird, 'N') <> 'S'",
    "COALESCE(i.CANCELADO, 'N') <> 'S'",
    "COALESCE(p.excluido_firebird, 'N') <> 'S'",
    "p.PRECOFINAL > 0",
    "p.PVENDA1 > 0",
    "(((p.PVENDA1 / p.PRECOFINAL) - 1) * 100 < ? OR ((p.PVENDA1 / p.PRECOFINAL) - 1) * 100 > ?)",
    "v.id IS NULL",
    "c.DTEMISSAO BETWEEN ? AND ?"
];
$params = [$empresa_id, $empresa_id, $empresa_id, $margemMinima, $margemMaxima, $dataIniSql, $dataFimSql];

if ($fornecedor !== '') {
    $where[] = "(f.NOME LIKE ? OR f.APELIDO LIKE ? OR c.FORNECEDOR = ?)";
    $params[] = "%$fornecedor%";
    $params[] = "%$fornecedor%";
    $params[] = ctype_digit($fornecedor) ? (int)$fornecedor : 0;
}

if ($descricao !== '') {
    $where[] = "p.DESCPRODUTO LIKE ?";
    $params[] = "%$descricao%";
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo_master->prepare("
    SELECT
        c.COMPRACONTADOR,
        c.DTEMISSAO,
        c.NUMDOC,
        COALESCE(f.NOME, f.APELIDO, CONCAT('Fornecedor ', c.FORNECEDOR)) AS fornecedor_nome,
        i.ITEMCOMPRACONTADOR,
        i.COMPRACONTA,
        i.PRODUTO,
        i.QTDE,
        i.TOTPRODCHEIO,
        p.CODPRODUTO,
        p.DESCPRODUTO,
        p.PRECOFINAL,
        p.PVENDA1,
        ((p.PVENDA1 / p.PRECOFINAL) - 1) * 100 AS margem
    FROM armazem_est006 i
    INNER JOIN armazem_est005 c
        ON c.COMPRACONTADOR = i.ITEMCOMPRACONTADOR
       AND c.EMPRESA = i.EMPRESA
    LEFT JOIN armazem_cp003 f
        ON f.FCONTADOR = c.FORNECEDOR
       AND f.EMPRESA = c.EMPRESA
    INNER JOIN armazem_est004 p
        ON p.CONTAPRODUTO = i.PRODUTO
       AND p.EMPRESA = i.EMPRESA
    LEFT JOIN auditoria_compras_itens_verificados v
        ON v.itemcomprador = i.ITEMCOMPRACONTADOR
       AND v.compraconta = i.COMPRACONTA
       AND v.produto = i.PRODUTO
    WHERE $whereSql
    ORDER BY ABS(((p.PVENDA1 / p.PRECOFINAL) - 1) * 100 - 20) DESC, c.DTEMISSAO DESC
    LIMIT 500
");
$stmt->execute($params);
$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

require '../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1 class="h5 mb-1">Itens fora do padrao</h1>
            <small class="text-muted">Itens com margem abaixo de <?= $margemMinima ?>% ou acima de <?= $margemMaxima ?>%.</small>
        </div>
        <a href="menu_auditoria.php" class="btn btn-outline-secondary">Voltar</a>
    </div>

    <div class="card-body">
        <?php if (($_GET['ok'] ?? '') === '1'): ?>
            <div class="alert alert-success">Item marcado como verificado.</div>
        <?php endif; ?>

        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-2">
                <label class="form-label small text-muted">Data inicial</label>
                <input type="date" name="data_ini" class="form-control" value="<?= htmlspecialchars($dataIni) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Data final</label>
                <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($dataFim) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted">Fornecedor</label>
                <input type="text" name="fornecedor" class="form-control" value="<?= htmlspecialchars($fornecedor) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Descricao</label>
                <input type="text" name="descricao" class="form-control" value="<?= htmlspecialchars($descricao) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-primary w-100">Filtrar</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Compra</th>
                        <th>Fornecedor</th>
                        <th>Documento</th>
                        <th>Codigo</th>
                        <th>Descricao</th>
                        <th class="text-end">Qtde</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Custo</th>
                        <th class="text-end">Venda Dia</th>
                        <th class="text-end">Margem</th>
                        <th class="text-center">Acao</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($itens)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted py-4">Nenhum item pendente encontrado.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($itens as $item): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($item['DTEMISSAO'])) ?></td>
                            <td><?= (int)$item['COMPRACONTADOR'] ?></td>
                            <td><?= htmlspecialchars($item['fornecedor_nome']) ?></td>
                            <td><?= htmlspecialchars($item['NUMDOC'] ?? '') ?></td>
                            <td><?= htmlspecialchars($item['CODPRODUTO'] ?? $item['PRODUTO']) ?></td>
                            <td><?= htmlspecialchars($item['DESCPRODUTO'] ?? '') ?></td>
                            <td class="text-end"><?= numero($item['QTDE'], 3) ?></td>
                            <td class="text-end"><?= moeda($item['TOTPRODCHEIO']) ?></td>
                            <td class="text-end"><?= moeda($item['PRECOFINAL']) ?></td>
                            <td class="text-end"><?= moeda($item['PVENDA1']) ?></td>
                            <td class="text-end fw-bold <?= (float)$item['margem'] < 0 ? 'text-danger' : 'text-success' ?>">
                                <?= numero($item['margem'], 2) ?>%
                            </td>
                            <td class="text-center">
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="acao" value="verificar">
                                    <input type="hidden" name="itemcomprador" value="<?= (int)$item['ITEMCOMPRACONTADOR'] ?>">
                                    <input type="hidden" name="compraconta" value="<?= (int)$item['COMPRACONTA'] ?>">
                                    <input type="hidden" name="produto" value="<?= (int)$item['PRODUTO'] ?>">
                                    <button class="btn btn-sm btn-success">Verificado</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (count($itens) >= 500): ?>
            <div class="alert alert-info mt-3 mb-0">Exibindo os 500 itens com maior divergencia no filtro atual.</div>
        <?php endif; ?>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
