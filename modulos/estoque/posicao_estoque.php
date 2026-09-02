<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$codigo = trim($_GET['codigo'] ?? '');
$descricao = trim($_GET['descricao'] ?? '');
$saldoFiltro = $_GET['saldo'] ?? 'com_saldo';
$exportar = $_GET['exportar'] ?? '';

$colunasEstoque = [
    'ESTOQUE_GERAL' => "ALTER TABLE armazem_est004 ADD ESTOQUE_GERAL DECIMAL(18,4) NULL",
    'ESTOQUE_RESERVADO' => "ALTER TABLE armazem_est004 ADD ESTOQUE_RESERVADO DECIMAL(18,4) NULL",
    'ESTOQUE_DISPONIVEL' => "ALTER TABLE armazem_est004 ADD ESTOQUE_DISPONIVEL DECIMAL(18,4) NULL",
    'ESTOQUE_CALCULADO_EM' => "ALTER TABLE armazem_est004 ADD ESTOQUE_CALCULADO_EM DATETIME NULL",
];
$verificarColuna = $pdo_master->prepare("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'armazem_est004' AND COLUMN_NAME = ?
");
foreach ($colunasEstoque as $coluna => $ddl) {
    $verificarColuna->execute([$coluna]);
    if ((int)$verificarColuna->fetchColumn() === 0) {
        $pdo_master->exec($ddl);
    }
}

if (!in_array($saldoFiltro, ['todos', 'com_saldo', 'positivo', 'negativo'], true)) {
    $saldoFiltro = 'com_saldo';
}

function moedaEstoque($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function qtdEstoque($valor): string
{
    return number_format((float)$valor, 3, ',', '.');
}

$where = [
    'p.EMPRESA = ?',
    "COALESCE(p.excluido_firebird, 'N') <> 'S'",
    "COALESCE(p.INATIVO, 'N') <> 'S'",
];
$params = [$empresaId];

if ($codigo !== '') {
    $where[] = 'p.CODPRODUTO LIKE ?';
    $params[] = '%' . $codigo . '%';
}

if ($descricao !== '') {
    $where[] = 'p.DESCPRODUTO LIKE ?';
    $params[] = '%' . $descricao . '%';
}

$whereSql = implode("\n      AND ", $where);

$having = '';
if ($saldoFiltro === 'com_saldo') {
    $having = 'HAVING qtd_saldo <> 0';
} elseif ($saldoFiltro === 'positivo') {
    $having = 'HAVING qtd_saldo > 0';
} elseif ($saldoFiltro === 'negativo') {
    $having = 'HAVING qtd_saldo < 0';
}

$stmt = $pdo_master->prepare("
    SELECT
        p.CODPRODUTO,
        p.CONTAPRODUTO,
        p.DESCPRODUTO,
        CASE
            WHEN p.ESTOQUE_CALCULADO_EM IS NOT NULL THEN COALESCE(p.ESTOQUE_GERAL, 0)
            ELSE COALESCE(p.ESTINICIAL, 0) + COALESCE(e.qtd_entrada, 0) - COALESCE(s.qtd_saida, 0)
        END AS estoque_geral,
        CASE
            WHEN p.ESTOQUE_CALCULADO_EM IS NOT NULL THEN COALESCE(p.ESTOQUE_RESERVADO, 0)
            ELSE 0
        END AS estoque_reservado,
        CASE
            WHEN p.ESTOQUE_CALCULADO_EM IS NOT NULL THEN COALESCE(p.ESTOQUE_DISPONIVEL, 0)
            ELSE COALESCE(p.ESTINICIAL, 0) + COALESCE(e.qtd_entrada, 0) - COALESCE(s.qtd_saida, 0)
        END AS qtd_saldo,
        COALESCE(p.PRECOFINAL, 0) AS precofinal,
        (CASE
            WHEN p.ESTOQUE_CALCULADO_EM IS NOT NULL THEN COALESCE(p.ESTOQUE_DISPONIVEL, 0)
            ELSE COALESCE(p.ESTINICIAL, 0) + COALESCE(e.qtd_entrada, 0) - COALESCE(s.qtd_saida, 0)
        END * COALESCE(p.PRECOFINAL, 0)) AS valor_estoque,
        p.ESTOQUE_CALCULADO_EM
    FROM armazem_est004 p
    LEFT JOIN (
        SELECT i.EMPRESA, i.PRODUTO, SUM(COALESCE(i.QTDE, 0)) AS qtd_entrada
        FROM armazem_est006 i
        LEFT JOIN armazem_est005 c
            ON c.EMPRESA = i.EMPRESA AND c.COMPRACONTADOR = i.COMPRACONTA
        WHERE i.EMPRESA = ?
          AND COALESCE(i.excluido_firebird, 'N') <> 'S'
          AND COALESCE(i.CANCELADO, 'N') <> 'S'
          AND COALESCE(i.MOVESTOQUE, 'S') <> 'N'
          AND COALESCE(c.excluido_firebird, 'N') <> 'S'
          AND COALESCE(c.CANCELADO, 'N') <> 'S'
          AND COALESCE(c.BAIXAESTOQUE, 'S') <> 'N'
        GROUP BY i.EMPRESA, i.PRODUTO
    ) e ON e.EMPRESA = p.EMPRESA AND e.PRODUTO = p.CONTAPRODUTO
    LEFT JOIN (
        SELECT i.EMPRESA, i.PRODUTO, SUM(COALESCE(i.QTDE, 0)) AS qtd_saida
        FROM armazem_est008 i
        WHERE i.EMPRESA = ?
          AND COALESCE(i.CANCELADO, 'N') <> 'S'
          AND i.MOVESTOQUE = 'S'
        GROUP BY i.EMPRESA, i.PRODUTO
    ) s ON s.EMPRESA = p.EMPRESA AND s.PRODUTO = p.CONTAPRODUTO
    WHERE {$whereSql}
    {$having}
    ORDER BY qtd_saida DESC, p.DESCPRODUTO ASC, p.CODPRODUTO ASC
");

$stmt->execute(array_merge([$empresaId, $empresaId], $params));
$produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalProdutos = count($produtos);
$totalGeral = 0.0;
$totalReservado = 0.0;
$totalSaldo = 0.0;
$totalValor = 0.0;
foreach ($produtos as $produto) {
    $totalGeral += (float)$produto['estoque_geral'];
    $totalReservado += (float)$produto['estoque_reservado'];
    $totalSaldo += (float)$produto['qtd_saldo'];
    $totalValor += (float)$produto['valor_estoque'];
}

function queryEstoque(array $extra = []): string
{
    $params = $_GET;
    unset($params['exportar']);
    foreach ($extra as $chave => $valor) {
        if ($valor === null) {
            unset($params[$chave]);
        } else {
            $params[$chave] = $valor;
        }
    }
    return http_build_query($params);
}

if ($exportar === 'excel') {
    $nomeArquivo = 'posicao_estoque_' . date('Ymd_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
        <meta charset="utf-8">
        <title>Posicao de Estoque</title>
        <style>
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #999; padding: 6px; }
            th { background: #d9ead3; text-align: left; }
            .num { text-align: right; }
        </style>
    </head>
    <body>
        <h1>Posicao de Estoque</h1>
        <p>
            Codigo: <?= htmlspecialchars($codigo ?: 'Todos') ?> |
            Descricao: <?= htmlspecialchars($descricao ?: 'Todos') ?> |
            Saldo: <?= htmlspecialchars($saldoFiltro) ?> |
            Saida: MOVESTOQUE = S |
            Produtos: <?= (int)$totalProdutos ?> |
            Estoque geral: <?= htmlspecialchars(qtdEstoque($totalGeral)) ?> |
            Reservado: <?= htmlspecialchars(qtdEstoque($totalReservado)) ?> |
            Disponivel: <?= htmlspecialchars(qtdEstoque($totalSaldo)) ?> |
            Valor estoque: <?= htmlspecialchars(moedaEstoque($totalValor)) ?>
        </p>
        <table>
            <thead>
                <tr>
                    <th>CODPRODUTO</th>
                    <th>DESCPRODUTO</th>
                    <th class="num">ESTOQUE GERAL</th>
                    <th class="num">RESERVADO</th>
                    <th class="num">DISPONIVEL</th>
                    <th class="num">PRECOFINAL</th>
                    <th class="num">VALOR ESTOQUE</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($produtos as $produto): ?>
                    <tr>
                        <td><?= htmlspecialchars($produto['CODPRODUTO']) ?></td>
                        <td><?= htmlspecialchars($produto['DESCPRODUTO']) ?></td>
                        <td class="num"><?= number_format((float)$produto['estoque_geral'], 3, ',', '.') ?></td>
                        <td class="num"><?= number_format((float)$produto['estoque_reservado'], 3, ',', '.') ?></td>
                        <td class="num"><?= number_format((float)$produto['qtd_saldo'], 3, ',', '.') ?></td>
                        <td class="num"><?= number_format((float)$produto['precofinal'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format((float)$produto['valor_estoque'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

require '../../layout/header.php';
?>

<style>
    .estoque-grid { font-size: .88rem; }
    .estoque-grid th {
        white-space: nowrap;
        font-size: .75rem;
        text-transform: uppercase;
        vertical-align: middle;
    }
    .estoque-grid td { vertical-align: middle; }
    .estoque-grid .col-code { width: 96px; white-space: nowrap; }
    .estoque-grid .col-qtd { width: 112px; white-space: nowrap; }
    .estoque-grid .col-money { width: 128px; white-space: nowrap; }
    .produto-desc { min-width: 260px; }

    @media (max-width: 575.98px) {
        .estoque-grid { border-collapse: separate; border-spacing: 0 .75rem; }
        .estoque-grid thead { display: none; }
        .estoque-grid,
        .estoque-grid tbody,
        .estoque-grid tr,
        .estoque-grid td {
            display: block;
            width: 100%;
        }
        .estoque-grid tr {
            border: 1px solid #d7dee8;
            border-radius: .5rem;
            background: #fff;
            overflow: hidden;
        }
        .estoque-grid td {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: .55rem .75rem;
            border: 0;
            border-bottom: 1px solid #edf1f5;
            text-align: right !important;
        }
        .estoque-grid td:last-child { border-bottom: 0; }
        .estoque-grid td::before {
            content: attr(data-label);
            flex: 0 0 38%;
            color: #64748b;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            font-size: .72rem;
            line-height: 1.2;
        }
        .estoque-grid td[data-label="Produto"] {
            display: block;
            text-align: left !important;
        }
        .estoque-grid td[data-label="Produto"]::before {
            display: block;
            margin-bottom: .25rem;
        }
    }
</style>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-success mb-3">Estoque</span>
                <h1 class="h3 fw-bold mb-2">Posicao de Estoque</h1>
                <p class="text-muted mb-0">Estoque geral, reservado e disponivel calculados pelas procedures oficiais do Firebird.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_estoque.php" class="btn btn-outline-secondary">Voltar ao estoque</a>
            </div>
        </div>
    </div>
</section>

<section class="mb-3">
    <form method="GET" class="bg-white border rounded-2 shadow-sm p-3">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-2">
                <label class="form-label">Codigo</label>
                <input type="text" name="codigo" class="form-control" value="<?= htmlspecialchars($codigo) ?>">
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label">Descricao</label>
                <input type="text" name="descricao" class="form-control" value="<?= htmlspecialchars($descricao) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Saldo</label>
                <select name="saldo" class="form-select">
                    <option value="com_saldo" <?= $saldoFiltro === 'com_saldo' ? 'selected' : '' ?>>Somente com saldo</option>
                    <option value="positivo" <?= $saldoFiltro === 'positivo' ? 'selected' : '' ?>>Somente positivo</option>
                    <option value="negativo" <?= $saldoFiltro === 'negativo' ? 'selected' : '' ?>>Somente negativo</option>
                    <option value="todos" <?= $saldoFiltro === 'todos' ? 'selected' : '' ?>>Todos</option>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex flex-wrap gap-2">
                <a href="posicao_estoque.php" class="btn btn-outline-secondary flex-fill">Limpar</a>
                <button class="btn btn-success flex-fill">Filtrar</button>
                <a href="posicao_estoque.php?<?= htmlspecialchars(queryEstoque(['exportar' => 'excel'])) ?>" class="btn btn-outline-success w-100">Exportar Excel</a>
            </div>
        </div>
    </form>
</section>

<section class="mb-3">
    <div class="row g-3">
        <div class="col-md">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Produtos</div>
                <div class="h5 fw-bold mb-0"><?= (int)$totalProdutos ?></div>
            </div>
        </div>
        <div class="col-md">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Estoque geral</div>
                <div class="h5 fw-bold mb-0"><?= qtdEstoque($totalGeral) ?></div>
            </div>
        </div>
        <div class="col-md">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Reservado</div>
                <div class="h5 fw-bold mb-0"><?= qtdEstoque($totalReservado) ?></div>
            </div>
        </div>
        <div class="col-md">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Disponivel</div>
                <div class="h5 fw-bold mb-0"><?= qtdEstoque($totalSaldo) ?></div>
            </div>
        </div>
        <div class="col-md">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Valor estoque</div>
                <div class="h5 fw-bold mb-0"><?= moedaEstoque($totalValor) ?></div>
            </div>
        </div>
    </div>
    <div class="small text-muted mt-2">
        Valores sincronizados das procedures SP_CALCESTOQUE_LOJA e SP_CALCESTOQUE_RESERVA do Firebird.
    </div>
</section>

<section>
    <div class="bg-white border rounded-2 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 estoque-grid">
                <thead class="table-primary">
                    <tr>
                        <th class="col-code">Codproduto</th>
                        <th>Descproduto</th>
                        <th class="text-end col-qtd">Estoque geral</th>
                        <th class="text-end col-qtd">Reservado</th>
                        <th class="text-end col-qtd">Disponivel</th>
                        <th class="text-end col-money">Precofinal</th>
                        <th class="text-end col-money">Valor estoque</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($produtos as $produto): ?>
                        <tr>
                            <td data-label="Codigo" class="col-code"><?= htmlspecialchars($produto['CODPRODUTO']) ?></td>
                            <td data-label="Produto" class="produto-desc"><?= htmlspecialchars($produto['DESCPRODUTO']) ?></td>
                            <td data-label="Estoque geral" class="text-end col-qtd"><?= qtdEstoque($produto['estoque_geral']) ?></td>
                            <td data-label="Reservado" class="text-end col-qtd"><?= qtdEstoque($produto['estoque_reservado']) ?></td>
                            <td data-label="Disponivel" class="text-end fw-semibold col-qtd"><?= qtdEstoque($produto['qtd_saldo']) ?></td>
                            <td data-label="Preco final" class="text-end col-money"><?= moedaEstoque($produto['precofinal']) ?></td>
                            <td data-label="Valor estoque" class="text-end fw-semibold col-money"><?= moedaEstoque($produto['valor_estoque']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($produtos)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Nenhum produto encontrado com os filtros informados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
