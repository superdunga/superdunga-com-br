<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

garantirTabelasDescontoCheques($pdo_master);

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$clienteId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$mensagemErro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim((string)($_POST['nome'] ?? ''));
    $clicontador = (int)($_POST['clicontador'] ?? 0);
    $celular = preg_replace('/[^\d()+\-\s]/', '', (string)($_POST['celular'] ?? ''));
    $taxaDesconto = decimalDC($_POST['taxa_desconto'] ?? '0');
    $usaAdicionalPrazo = ($_POST['usa_adicional_prazo'] ?? 'S') === 'N' ? 'N' : 'S';
    $limiteCredito = decimalDC($_POST['limite_credito'] ?? '0');
    $ativo = ($_POST['ativo'] ?? 'S') === 'N' ? 'N' : 'S';

    $clienteOficialValido = false;
    if ($clicontador > 0) {
        $stmtClienteOficial = $pdo_master->prepare("
            SELECT COUNT(*)
            FROM armazem_cr002
            WHERE EMPRESA = ?
              AND CLICONTADOR = ?
              AND COALESCE(excluido_firebird, 'N') <> 'S'
        ");
        $stmtClienteOficial->execute([$empresaId, $clicontador]);
        $clienteOficialValido = (int)$stmtClienteOficial->fetchColumn() > 0;
    }

    if ($nome === '') {
        $mensagemErro = 'Informe o nome do cliente.';
    } elseif (!$clienteOficialValido) {
        $mensagemErro = 'Selecione o cliente oficial vinculado.';
    } elseif ($taxaDesconto < 0) {
        $mensagemErro = 'A taxa de desconto nao pode ser negativa.';
    } elseif ($limiteCredito < 0) {
        $mensagemErro = 'O limite de credito nao pode ser negativo.';
    } else {
        if ($clienteId > 0) {
            $stmt = $pdo_master->prepare("
                UPDATE desconto_cheques_clientes
                SET nome = ?,
                    clicontador = ?,
                    celular = NULLIF(?, ''),
                    taxa_desconto = ?,
                    usa_adicional_prazo = ?,
                    limite_credito = ?,
                    ativo = ?
                WHERE id = ?
                  AND empresa_id = ?
            ");
            $stmt->execute([$nome, $clicontador, $celular, $taxaDesconto, $usaAdicionalPrazo, $limiteCredito, $ativo, $clienteId, $empresaId]);
        } else {
            $stmt = $pdo_master->prepare("
                INSERT INTO desconto_cheques_clientes
                    (empresa_id, nome, clicontador, celular, taxa_desconto, usa_adicional_prazo, limite_credito, ativo)
                VALUES (?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?)
            ");
            $stmt->execute([$empresaId, $nome, $clicontador, $celular, $taxaDesconto, $usaAdicionalPrazo, $limiteCredito, $ativo]);
        }

        header('Location: clientes.php?ok=1');
        exit;
    }
}

$clienteEditar = null;
if ($clienteId > 0) {
    $stmtEditar = $pdo_master->prepare("
        SELECT *
        FROM desconto_cheques_clientes
        WHERE id = ?
          AND empresa_id = ?
        LIMIT 1
    ");
    $stmtEditar->execute([$clienteId, $empresaId]);
    $clienteEditar = $stmtEditar->fetch(PDO::FETCH_ASSOC) ?: null;
}

$stmtClientesOficiais = $pdo_master->prepare("
    SELECT CLICONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Cliente ', CLICONTADOR)) AS nome
    FROM armazem_cr002
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY nome, CLICONTADOR
");
$stmtClientesOficiais->execute([$empresaId]);
$clientesOficiais = $stmtClientesOficiais->fetchAll(PDO::FETCH_ASSOC);

$filtroNome = trim((string)($_GET['nome'] ?? ''));
$filtroAtivo = $_GET['ativo'] ?? 'S';
$where = ['dc.empresa_id = ?'];
$params = [$empresaId];

if ($filtroNome !== '') {
    $where[] = 'dc.nome LIKE ?';
    $params[] = '%' . $filtroNome . '%';
}

if (in_array($filtroAtivo, ['S', 'N'], true)) {
    $where[] = 'dc.ativo = ?';
    $params[] = $filtroAtivo;
}

$stmtClientes = $pdo_master->prepare("
    SELECT
        dc.*,
        COALESCE(NULLIF(cr.APELIDO, ''), cr.NOME) AS cliente_oficial_nome,
        COALESCE((
            SELECT SUM(d.valor)
            FROM desconto_cheques_documentos d
            INNER JOIN desconto_cheques_operacoes o
                    ON o.id = d.operacao_id
                   AND o.empresa_id = dc.empresa_id
            LEFT JOIN armazem_cr001 titulo
                   ON titulo.EMPRESA = o.empresa_id
                  AND titulo.CRCONTADOR = d.crcontador
                  AND COALESCE(titulo.excluido_firebird, 'N') = 'N'
            WHERE o.cliente_id = dc.id
              AND d.data_vencimento >= CURRENT_DATE()
              AND (
                  o.status IN ('ABERTA', 'CONFIRMADA')
                  OR (
                      o.status = 'LANCADA'
                      AND titulo.CRCONTADOR IS NOT NULL
                      AND COALESCE(titulo.STATUS, '') <> 'QT'
                      AND COALESCE(titulo.VLRRESTANTE, titulo.VLRPARCELA, 0) > 0
                  )
              )
        ), 0) AS valor_a_vencer
    FROM desconto_cheques_clientes dc
    LEFT JOIN armazem_cr002 cr
      ON cr.EMPRESA = dc.empresa_id
     AND cr.CLICONTADOR = dc.clicontador
    WHERE " . implode(' AND ', $where) . "
    ORDER BY dc.nome
");
$stmtClientes->execute($params);
$clientes = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);
$totalAVencer = array_sum(array_map(static function (array $cliente): float {
    return (float)$cliente['valor_a_vencer'];
}, $clientes));

require '../../layout/header.php';
?>

<style>
    .dc-page-actions {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
    }

    @media (max-width: 575.98px) {
        .dc-page-actions .btn,
        .dc-form-actions .btn {
            width: 100%;
        }

        .dc-client-table {
            min-width: 720px;
        }
    }
</style>

<section class="mb-3">
    <div class="bg-white border rounded-2 shadow-sm p-3 p-lg-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div>
                <span class="badge text-bg-warning mb-2">Desconto de Cheques</span>
                <h1 class="h4 fw-bold mb-1">Clientes</h1>
                <p class="text-muted mb-0">Taxa, limite e regra de prazo por cliente.</p>
            </div>
            <div class="dc-page-actions align-self-lg-center">
                <a href="menu_desconto_cheques.php" class="btn btn-outline-secondary">Voltar</a>
                <a href="clientes.php" class="btn btn-warning">Novo cliente</a>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">Cliente salvo com sucesso.</div>
<?php endif; ?>
<?php if ($mensagemErro !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div>
<?php endif; ?>

<section class="mb-3">
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold"><?= $clienteEditar ? 'Editar cliente' : 'Novo cliente' ?></div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="id" value="<?= (int)($clienteEditar['id'] ?? 0) ?>">
                <div class="col-12 col-lg-4">
                    <label class="form-label">Nome</label>
                    <input type="text" name="nome" class="form-control" required value="<?= htmlspecialchars((string)($clienteEditar['nome'] ?? '')) ?>">
                </div>
                <div class="col-12 col-lg-4">
                    <label class="form-label">Cliente oficial</label>
                    <select name="clicontador" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($clientesOficiais as $clienteOficial): ?>
                            <option value="<?= (int)$clienteOficial['CLICONTADOR'] ?>" <?= (int)($clienteEditar['clicontador'] ?? 0) === (int)$clienteOficial['CLICONTADOR'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($clienteOficial['nome'] . ' (' . $clienteOficial['CLICONTADOR'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label class="form-label">Celular</label>
                    <input type="text" name="celular" class="form-control" value="<?= htmlspecialchars((string)($clienteEditar['celular'] ?? '')) ?>">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label">Taxa %</label>
                    <input type="text" name="taxa_desconto" class="form-control" inputmode="decimal" value="<?= htmlspecialchars(number_format((float)($clienteEditar['taxa_desconto'] ?? 0), 2, ',', '.')) ?>">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label">Limite</label>
                    <input type="text" name="limite_credito" class="form-control" inputmode="decimal" value="<?= htmlspecialchars(number_format((float)($clienteEditar['limite_credito'] ?? 0), 2, ',', '.')) ?>">
                </div>
                <div class="col-6 col-lg-1">
                    <label class="form-label">Prazo</label>
                    <select name="usa_adicional_prazo" class="form-select">
                        <option value="S" <?= ($clienteEditar['usa_adicional_prazo'] ?? 'S') === 'S' ? 'selected' : '' ?>>Sim</option>
                        <option value="N" <?= ($clienteEditar['usa_adicional_prazo'] ?? 'S') === 'N' ? 'selected' : '' ?>>Nao</option>
                    </select>
                </div>
                <div class="col-6 col-lg-1">
                    <label class="form-label">Ativo</label>
                    <select name="ativo" class="form-select">
                        <option value="S" <?= ($clienteEditar['ativo'] ?? 'S') === 'S' ? 'selected' : '' ?>>Sim</option>
                        <option value="N" <?= ($clienteEditar['ativo'] ?? 'S') === 'N' ? 'selected' : '' ?>>Nao</option>
                    </select>
                </div>
                <div class="col-12 dc-form-actions">
                    <button type="submit" class="btn btn-primary">Salvar cliente</button>
                </div>
            </form>
        </div>
    </div>
</section>

<section>
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label small">Buscar cliente</label>
                    <input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($filtroNome) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">Status</label>
                    <select name="ativo" class="form-select">
                        <option value="S" <?= $filtroAtivo === 'S' ? 'selected' : '' ?>>Ativos</option>
                        <option value="N" <?= $filtroAtivo === 'N' ? 'selected' : '' ?>>Inativos</option>
                        <option value="todos" <?= $filtroAtivo === 'todos' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small">Total a vencer</label>
                    <div class="form-control bg-light fw-semibold text-end"><?= moedaDC($totalAVencer) ?></div>
                </div>
                <div class="col-6 col-md-3 d-grid">
                    <button type="submit" class="btn btn-outline-primary">Filtrar</button>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 dc-client-table">
                <thead class="table-light">
                    <tr>
                        <th>Cliente</th>
                        <th>Cliente oficial</th>
                        <th>Celular</th>
                        <th class="text-end">Taxa</th>
                        <th class="text-center">Adicional</th>
                        <th class="text-end">Limite</th>
                        <th class="text-end">A vencer</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Acao</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $cliente): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($cliente['nome']) ?></td>
                            <td><?= !empty($cliente['clicontador']) ? htmlspecialchars($cliente['cliente_oficial_nome'] . ' (' . $cliente['clicontador'] . ')') : '<span class="text-danger">Nao vinculado</span>' ?></td>
                            <td><?= htmlspecialchars((string)($cliente['celular'] ?? '-')) ?></td>
                            <td class="text-end"><?= percentualDC($cliente['taxa_desconto']) ?></td>
                            <td class="text-center"><?= $cliente['usa_adicional_prazo'] === 'S' ? 'Sim' : 'Nao' ?></td>
                            <td class="text-end"><?= moedaDC($cliente['limite_credito']) ?></td>
                            <td class="text-end fw-semibold"><?= moedaDC($cliente['valor_a_vencer']) ?></td>
                            <td class="text-center">
                                <span class="badge <?= $cliente['ativo'] === 'S' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                    <?= $cliente['ativo'] === 'S' ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                            <td class="text-end"><a href="clientes.php?id=<?= (int)$cliente['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($clientes)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Nenhum cliente encontrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
