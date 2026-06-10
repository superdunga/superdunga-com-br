<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

garantirTabelasDescontoCheques($pdo_master);

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
garantirFeriadosNacionaisFixosDC($pdo_master, $empresaId);
garantirFeriadosVariaveisDC($pdo_master, $empresaId, (int)date('Y') - 1, 6);

$feriadoId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$mensagemErro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dia = (int)($_POST['dia'] ?? 0);
    $mes = (int)($_POST['mes'] ?? 0);
    $descricao = trim((string)($_POST['descricao'] ?? ''));
    $tipo = strtoupper(trim((string)($_POST['tipo'] ?? 'REGIONAL')));
    $ativo = ($_POST['ativo'] ?? 'S') === 'N' ? 'N' : 'S';

    if ($dia < 1 || $dia > 31 || $mes < 1 || $mes > 12 || !checkdate($mes, $dia, 2024)) {
        $mensagemErro = 'Informe dia e mes validos.';
    } elseif ($descricao === '') {
        $mensagemErro = 'Informe a descricao do feriado.';
    } elseif (!in_array($tipo, ['NACIONAL', 'ESTADUAL', 'MUNICIPAL', 'REGIONAL'], true)) {
        $mensagemErro = 'Informe um tipo valido.';
    } else {
        if ($feriadoId > 0) {
            $stmt = $pdo_master->prepare("
                UPDATE desconto_cheques_feriados
                SET dia = ?,
                    mes = ?,
                    descricao = ?,
                    tipo = ?,
                    ativo = ?
                WHERE id = ?
                  AND empresa_id = ?
            ");
            $stmt->execute([$dia, $mes, $descricao, $tipo, $ativo, $feriadoId, $empresaId]);
        } else {
            $stmt = $pdo_master->prepare("
                INSERT INTO desconto_cheques_feriados
                    (empresa_id, dia, mes, descricao, tipo, ativo)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    descricao = VALUES(descricao),
                    tipo = VALUES(tipo),
                    ativo = VALUES(ativo)
            ");
            $stmt->execute([$empresaId, $dia, $mes, $descricao, $tipo, $ativo]);
        }

        header('Location: feriados.php?ok=1');
        exit;
    }
}

$feriadoEditar = null;
if ($feriadoId > 0) {
    $stmtEditar = $pdo_master->prepare("
        SELECT *
        FROM desconto_cheques_feriados
        WHERE id = ?
          AND empresa_id = ?
        LIMIT 1
    ");
    $stmtEditar->execute([$feriadoId, $empresaId]);
    $feriadoEditar = $stmtEditar->fetch(PDO::FETCH_ASSOC) ?: null;
}

$stmtFeriados = $pdo_master->prepare("
    SELECT *
    FROM desconto_cheques_feriados
    WHERE empresa_id = ?
    ORDER BY mes, dia, descricao
");
$stmtFeriados->execute([$empresaId]);
$feriados = $stmtFeriados->fetchAll(PDO::FETCH_ASSOC);

$stmtVariaveis = $pdo_master->prepare("
    SELECT *
    FROM desconto_cheques_feriados_variaveis
    WHERE empresa_id = ?
      AND data_feriado BETWEEN ? AND ?
    ORDER BY data_feriado, descricao
");
$stmtVariaveis->execute([
    $empresaId,
    ((int)date('Y') - 1) . '-01-01',
    ((int)date('Y') + 6) . '-12-31',
]);
$feriadosVariaveis = $stmtVariaveis->fetchAll(PDO::FETCH_ASSOC);

require '../../layout/header.php';
?>

<style>
    @media (max-width: 575.98px) {
        .dc-form-actions .btn {
            width: 100%;
        }

        .dc-feriados-table {
            min-width: 680px;
        }
    }
</style>

<section class="mb-3">
    <div class="bg-white border rounded-2 shadow-sm p-3 p-lg-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div>
                <span class="badge text-bg-warning mb-2">Desconto de Cheques</span>
                <h1 class="h4 fw-bold mb-1">Feriados</h1>
                <p class="text-muted mb-0">Feriados recorrentes usados no calculo do proximo dia util.</p>
            </div>
            <div class="d-flex flex-column flex-sm-row gap-2 align-self-lg-center">
                <a href="menu_desconto_cheques.php" class="btn btn-outline-secondary">Voltar</a>
                <a href="feriados.php" class="btn btn-warning">Novo feriado</a>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">Feriado salvo com sucesso.</div>
<?php endif; ?>
<?php if ($mensagemErro !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div>
<?php endif; ?>

<section class="mb-3">
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold"><?= $feriadoEditar ? 'Editar feriado' : 'Novo feriado' ?></div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="id" value="<?= (int)($feriadoEditar['id'] ?? 0) ?>">
                <div class="col-6 col-md-2">
                    <label class="form-label">Dia</label>
                    <input type="number" name="dia" min="1" max="31" class="form-control" required value="<?= htmlspecialchars((string)($feriadoEditar['dia'] ?? '')) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Mes</label>
                    <input type="number" name="mes" min="1" max="12" class="form-control" required value="<?= htmlspecialchars((string)($feriadoEditar['mes'] ?? '')) ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Descricao</label>
                    <input type="text" name="descricao" class="form-control" required value="<?= htmlspecialchars((string)($feriadoEditar['descricao'] ?? '')) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Tipo</label>
                    <?php $tipoAtual = $feriadoEditar['tipo'] ?? 'REGIONAL'; ?>
                    <select name="tipo" class="form-select">
                        <option value="NACIONAL" <?= $tipoAtual === 'NACIONAL' ? 'selected' : '' ?>>Nacional</option>
                        <option value="ESTADUAL" <?= $tipoAtual === 'ESTADUAL' ? 'selected' : '' ?>>Estadual</option>
                        <option value="MUNICIPAL" <?= $tipoAtual === 'MUNICIPAL' ? 'selected' : '' ?>>Municipal</option>
                        <option value="REGIONAL" <?= $tipoAtual === 'REGIONAL' ? 'selected' : '' ?>>Regional</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Ativo</label>
                    <select name="ativo" class="form-select">
                        <option value="S" <?= ($feriadoEditar['ativo'] ?? 'S') === 'S' ? 'selected' : '' ?>>Sim</option>
                        <option value="N" <?= ($feriadoEditar['ativo'] ?? 'S') === 'N' ? 'selected' : '' ?>>Nao</option>
                    </select>
                </div>
                <div class="col-12 dc-form-actions">
                    <button type="submit" class="btn btn-primary">Salvar feriado</button>
                </div>
            </form>
        </div>
    </div>
</section>

<section>
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold">Feriados fixos cadastrados</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 dc-feriados-table">
                <thead class="table-light">
                    <tr>
                        <th>Data</th>
                        <th>Descricao</th>
                        <th>Tipo</th>
                        <th>Status</th>
                        <th class="text-end">Acao</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feriados as $feriado): ?>
                        <tr>
                            <td class="fw-semibold"><?= str_pad((string)$feriado['dia'], 2, '0', STR_PAD_LEFT) ?>/<?= str_pad((string)$feriado['mes'], 2, '0', STR_PAD_LEFT) ?></td>
                            <td><?= htmlspecialchars($feriado['descricao']) ?></td>
                            <td><?= htmlspecialchars($feriado['tipo']) ?></td>
                            <td>
                                <span class="badge <?= $feriado['ativo'] === 'S' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                    <?= $feriado['ativo'] === 'S' ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                            <td class="text-end"><a href="feriados.php?id=<?= (int)$feriado['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($feriados)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Nenhum feriado cadastrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="mt-3">
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold">Feriados variaveis carregados automaticamente</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 dc-feriados-table">
                <thead class="table-light">
                    <tr>
                        <th>Data</th>
                        <th>Descricao</th>
                        <th>Tipo</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feriadosVariaveis as $feriado): ?>
                        <tr>
                            <td class="fw-semibold"><?= dataBRDC($feriado['data_feriado']) ?></td>
                            <td><?= htmlspecialchars($feriado['descricao']) ?></td>
                            <td><?= htmlspecialchars($feriado['tipo']) ?></td>
                            <td>
                                <span class="badge <?= $feriado['ativo'] === 'S' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                    <?= $feriado['ativo'] === 'S' ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($feriadosVariaveis)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Nenhum feriado variavel carregado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
