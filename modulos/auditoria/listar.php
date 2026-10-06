<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once __DIR__ . '/_anexos_compras.php';

$empresa_id = (int)$_SESSION['empresa_id'];
$usuario_id = (int)$_SESSION['usuario_id'];

auditoriaGarantirTabelaAnexosCompras($pdo_master);

if (empty($_SESSION['csrf_auditoria_compras'])) {
    $_SESSION['csrf_auditoria_compras'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_auditoria_compras'];

function auditoriaUrlListagem(array $parametros = []): string
{
    $permitidos = ['data_ini', 'data_fim', 'fornecedor', 'produto', 'documento'];
    $query = [];
    foreach ($permitidos as $campo) {
        if (isset($_GET[$campo]) && !is_array($_GET[$campo]) && $_GET[$campo] !== '') {
            $query[$campo] = (string)$_GET[$campo];
        }
    }
    foreach ($parametros as $campo => $valor) {
        $query[$campo] = $valor;
    }

    return 'listar.php' . (!empty($query) ? '?' . http_build_query($query) : '');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('A sessao do formulario expirou. Atualize a pagina e tente novamente.');
        }

        $acao = (string)($_POST['acao'] ?? '');
        $compraContador = (int)($_POST['compra_contador'] ?? 0);
        if ($acao === 'anexar_fotos') {
            $quantidade = auditoriaSalvarFotosCompra(
                $pdo_master,
                $empresa_id,
                $compraContador,
                $usuario_id,
                isset($_FILES['fotos']) && is_array($_FILES['fotos']) ? $_FILES['fotos'] : []
            );
            $_SESSION['auditoria_compras_flash'] = [
                'tipo' => 'success',
                'mensagem' => $quantidade . ($quantidade === 1 ? ' foto anexada.' : ' fotos anexadas.'),
            ];
        } elseif ($acao === 'excluir_foto') {
            $anexoId = (int)($_POST['anexo_id'] ?? 0);
            if (!auditoriaExcluirFotoCompra($pdo_master, $empresa_id, $anexoId, $usuario_id)) {
                throw new RuntimeException('A foto nao foi encontrada ou ja havia sido removida.');
            }
            $_SESSION['auditoria_compras_flash'] = [
                'tipo' => 'success',
                'mensagem' => 'Foto removida do registro.',
            ];
        } else {
            throw new RuntimeException('Acao invalida.');
        }
    } catch (Throwable $e) {
        $_SESSION['auditoria_compras_flash'] = [
            'tipo' => 'danger',
            'mensagem' => $e->getMessage(),
        ];
    }

    $destino = auditoriaUrlListagem();
    if ($compraContador > 0) {
        $destino .= (strpos($destino, '?') === false ? '?' : '&') . 'abrir=' . $compraContador;
        $destino .= '#compra-' . $compraContador;
    }
    header('Location: ' . $destino);
    exit;
}

$flash = isset($_SESSION['auditoria_compras_flash']) && is_array($_SESSION['auditoria_compras_flash'])
    ? $_SESSION['auditoria_compras_flash']
    : null;
unset($_SESSION['auditoria_compras_flash']);

require '../../layout/header.php';
$dataIni = $_GET['data_ini'] ?? date('Y-m-01');
$dataFim = $_GET['data_fim'] ?? date('Y-m-d');
$fornecedor = trim($_GET['fornecedor'] ?? '');
$produto = trim($_GET['produto'] ?? '');
$documento = trim($_GET['documento'] ?? '');

$where = [
    "c.EMPRESA = ?",
    "COALESCE(c.excluido_firebird, 'N') <> 'S'",
    "COALESCE(c.CANCELADO, 'N') <> 'S'",
    "DATE(c.DTEMISSAO) BETWEEN ? AND ?"
];
$params = [$empresa_id, $dataIni, $dataFim];

if ($fornecedor !== '') {
    $where[] = "(f.NOME LIKE ? OR f.APELIDO LIKE ? OR c.FORNECEDOR = ?)";
    $params[] = "%$fornecedor%";
    $params[] = "%$fornecedor%";
    $params[] = ctype_digit($fornecedor) ? (int)$fornecedor : 0;
}

if ($produto !== '') {
    $where[] = "EXISTS (
        SELECT 1
        FROM armazem_est006 item_filtro
        LEFT JOIN armazem_est004 produto_filtro
            ON produto_filtro.CONTAPRODUTO = item_filtro.PRODUTO
           AND produto_filtro.EMPRESA = item_filtro.EMPRESA
        WHERE item_filtro.EMPRESA = c.EMPRESA
          AND item_filtro.ITEMCOMPRACONTADOR = c.COMPRACONTADOR
          AND COALESCE(item_filtro.excluido_firebird, 'N') <> 'S'
          AND COALESCE(item_filtro.CANCELADO, 'N') <> 'S'
          AND (
              produto_filtro.DESCPRODUTO LIKE ?
              OR produto_filtro.CODPRODUTO LIKE ?
              OR item_filtro.PRODUTO = ?
          )
    )";
    $params[] = "%$produto%";
    $params[] = "%$produto%";
    $params[] = ctype_digit($produto) ? (int)$produto : 0;
}

if ($documento !== '') {
    $where[] = "c.NUMDOC LIKE ?";
    $params[] = "%$documento%";
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo_master->prepare("
    SELECT
        c.COMPRACONTADOR,
        c.DTEMISSAO,
        c.NUMDOC,
        c.TOTGERAL,
        c.FORNECEDOR,
        c.CLASSIFICACAO,
        COALESCE(f.NOME, f.APELIDO, CONCAT('Fornecedor ', c.FORNECEDOR)) AS fornecedor_nome
    FROM armazem_est005 c
    LEFT JOIN armazem_cp003 f
        ON f.FCONTADOR = c.FORNECEDOR
       AND f.EMPRESA = c.EMPRESA
    WHERE $whereSql
    ORDER BY c.DTEMISSAO DESC, c.COMPRACONTADOR DESC
    LIMIT 200
");
$stmt->execute($params);
$compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

$itensPorCompra = [];
$anexosPorCompra = [];
$comprasIds = array_values(array_filter(array_map(function ($compra) {
    return (int)($compra['COMPRACONTADOR'] ?? 0);
}, $compras)));

if (!empty($comprasIds)) {
    $placeholders = implode(',', array_fill(0, count($comprasIds), '?'));
    $stmtItens = $pdo_master->prepare("
        SELECT
            i.ITEMCOMPRACONTADOR,
            i.COMPRACONTA,
            i.PRODUTO,
            i.QTDE,
            i.TOTPRODCHEIO,
            p.CODPRODUTO,
            p.DESCPRODUTO,
            p.PRECOFINAL,
            p.PVENDA1
        FROM armazem_est006 i
        LEFT JOIN armazem_est004 p
            ON p.CONTAPRODUTO = i.PRODUTO
           AND p.EMPRESA = i.EMPRESA
        WHERE i.ITEMCOMPRACONTADOR IN ($placeholders)
          AND i.EMPRESA = ?
          AND COALESCE(i.excluido_firebird, 'N') <> 'S'
          AND COALESCE(i.CANCELADO, 'N') <> 'S'
        ORDER BY i.ITEMCOMPRACONTADOR, i.COMPRACONTA
    ");
    $stmtItens->execute(array_merge($comprasIds, [$empresa_id]));

    while ($item = $stmtItens->fetch(PDO::FETCH_ASSOC)) {
        $itensPorCompra[(int)$item['ITEMCOMPRACONTADOR']][] = $item;
    }

    $stmtAnexos = $pdo_master->prepare("
        SELECT id, compra_contador, nome_original, mime_type, tamanho, criado_em
        FROM auditoria_compra_anexos
        WHERE empresa_id = ?
          AND compra_contador IN ($placeholders)
          AND excluido_em IS NULL
        ORDER BY compra_contador, criado_em, id
    ");
    $stmtAnexos->execute(array_merge([$empresa_id], $comprasIds));
    while ($anexo = $stmtAnexos->fetch(PDO::FETCH_ASSOC)) {
        $anexosPorCompra[(int)$anexo['compra_contador']][] = $anexo;
    }
}

function moeda($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function numero($valor, int $casas = 2): string
{
    return number_format((float)$valor, $casas, ',', '.');
}
?>

<div class="card shadow-sm">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1 class="h5 mb-1">Auditoria das Compras</h1>
            <small class="text-muted">Acompanhe compras e margens por item.</small>
        </div>
        <a href="../../index.php" class="btn btn-outline-secondary">Voltar</a>
    </div>

    <div class="card-body">
        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($flash['mensagem']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
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
            <div class="col-md-2">
                <label class="form-label small text-muted">Fornecedor</label>
                <input type="text" name="fornecedor" class="form-control" value="<?= htmlspecialchars($fornecedor) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Produto</label>
                <input type="text" name="produto" class="form-control" value="<?= htmlspecialchars($produto) ?>" placeholder="Descricao ou codigo">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Documento</label>
                <input type="text" name="documento" class="form-control" value="<?= htmlspecialchars($documento) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-primary w-100">Filtrar</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead>
                    <tr>
                        <th>Data da Compra</th>
                        <th>Fornecedor</th>
                        <th>Codigo</th>
                        <th>Documento</th>
                        <th>Classificacao</th>
                        <th class="text-end">Valor Total</th>
                        <th class="text-center">Fotos</th>
                        <th class="text-center">Detalhes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($compras)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Nenhuma compra encontrada.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($compras as $compra): ?>
                        <?php
                            $compraId = (int)$compra['COMPRACONTADOR'];
                            $collapseId = 'compra-itens-' . $compraId;
                            $itens = $itensPorCompra[$compraId] ?? [];
                            $anexos = $anexosPorCompra[$compraId] ?? [];
                            $abrirCompra = (int)($_GET['abrir'] ?? 0) === $compraId;
                        ?>
                        <tr id="compra-<?= $compraId ?>">
                            <td><?= date('d/m/Y', strtotime($compra['DTEMISSAO'])) ?></td>
                            <td><?= htmlspecialchars($compra['fornecedor_nome']) ?></td>
                            <td><?= $compraId ?></td>
                            <td><?= htmlspecialchars($compra['NUMDOC'] ?? '') ?></td>
                            <td><?= htmlspecialchars(trim((string)($compra['CLASSIFICACAO'] ?? '')) ?: '-') ?></td>
                            <td class="text-end"><?= moeda($compra['TOTGERAL']) ?></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-secondary"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= $collapseId ?>">
                                    Fotos (<?= count($anexos) ?>)
                                </button>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= $collapseId ?>">
                                    Detalhes
                                </button>
                            </td>
                        </tr>
                        <tr class="collapse<?= $abrirCompra ? ' show' : '' ?>" id="<?= $collapseId ?>">
                            <td colspan="8" class="bg-light">
                                <div class="border-bottom pb-3 mb-3">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                        <div>
                                            <h2 class="h6 mb-1">Fotos da nota</h2>
                                            <div class="small text-muted">Compra <?= $compraId ?> | Documento <?= htmlspecialchars($compra['NUMDOC'] ?? '') ?></div>
                                        </div>
                                        <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap align-items-end gap-2">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="acao" value="anexar_fotos">
                                            <input type="hidden" name="compra_contador" value="<?= $compraId ?>">
                                            <div>
                                                <label class="form-label small mb-1">Selecionar fotos</label>
                                                <input type="file" name="fotos[]" class="form-control form-control-sm"
                                                       accept="image/jpeg,image/png,image/webp" multiple required>
                                            </div>
                                            <button class="btn btn-sm btn-primary">Anexar</button>
                                        </form>
                                    </div>

                                    <?php if (empty($anexos)): ?>
                                        <div class="text-muted small">Nenhuma foto anexada a esta compra.</div>
                                    <?php else: ?>
                                        <div class="row g-2">
                                            <?php foreach ($anexos as $anexo): ?>
                                                <div class="col-6 col-md-3 col-xl-2">
                                                    <div class="card h-100">
                                                        <a href="visualizar_anexo_compra.php?id=<?= (int)$anexo['id'] ?>"
                                                           target="_blank" rel="noopener" class="d-block">
                                                            <img src="visualizar_anexo_compra.php?id=<?= (int)$anexo['id'] ?>"
                                                                 class="card-img-top auditoria-foto-nota"
                                                                 alt="Foto da nota <?= htmlspecialchars($anexo['nome_original']) ?>">
                                                        </a>
                                                        <div class="card-body p-2">
                                                            <div class="small text-truncate" title="<?= htmlspecialchars($anexo['nome_original']) ?>">
                                                                <?= htmlspecialchars($anexo['nome_original']) ?>
                                                            </div>
                                                            <div class="text-muted" style="font-size: .75rem;">
                                                                <?= date('d/m/Y H:i', strtotime($anexo['criado_em'])) ?>
                                                            </div>
                                                            <form method="post" class="mt-2" onsubmit="return confirm('Remover esta foto do registro?')">
                                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                                                <input type="hidden" name="acao" value="excluir_foto">
                                                                <input type="hidden" name="compra_contador" value="<?= $compraId ?>">
                                                                <input type="hidden" name="anexo_id" value="<?= (int)$anexo['id'] ?>">
                                                                <button class="btn btn-sm btn-outline-danger w-100">Remover</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (empty($itens)): ?>
                                    <div class="text-muted small">Nenhum item encontrado para a compra <?= $compraId ?>.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Codigo</th>
                                                    <th>Descricao</th>
                                                    <th class="text-end">Quantidade</th>
                                                    <th class="text-end">Valor Total</th>
                                                    <th class="text-end">Custo Unitario</th>
                                                    <th class="text-end">Preco Venda Dia</th>
                                                    <th class="text-end">Margem</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($itens as $item): ?>
                                                    <?php
                                                        $precoFinal = (float)($item['PRECOFINAL'] ?? 0);
                                                        $precoVenda = (float)($item['PVENDA1'] ?? 0);
                                                        $margem = $precoFinal > 0 ? (($precoVenda / $precoFinal) - 1) * 100 : null;
                                                    ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($item['CODPRODUTO'] ?? $item['PRODUTO']) ?></td>
                                                        <td><?= htmlspecialchars($item['DESCPRODUTO'] ?? '') ?></td>
                                                        <td class="text-end"><?= numero($item['QTDE'], 3) ?></td>
                                                        <td class="text-end"><?= moeda($item['TOTPRODCHEIO']) ?></td>
                                                        <td class="text-end"><?= moeda($precoFinal) ?></td>
                                                        <td class="text-end"><?= moeda($precoVenda) ?></td>
                                                        <td class="text-end <?= $margem !== null && $margem < 0 ? 'text-danger' : '' ?>">
                                                            <?= $margem === null ? '-' : numero($margem, 2) . '%' ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (count($compras) >= 200): ?>
            <div class="alert alert-info mt-3 mb-0">Exibindo os 200 registros mais recentes do filtro.</div>
        <?php endif; ?>
    </div>
</div>

<style>
.auditoria-foto-nota {
    width: 100%;
    aspect-ratio: 4 / 3;
    object-fit: cover;
    background: #f1f3f5;
}
</style>

<?php require '../../layout/footer.php'; ?>
