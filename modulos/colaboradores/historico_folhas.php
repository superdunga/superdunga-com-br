<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);

function moedaHistoricoFolha($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function dataHistoricoFolha($valor): string
{
    if (!$valor || $valor === '0000-00-00' || $valor === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime((string)$valor);
    return $ts ? date('d/m/Y', $ts) : (string)$valor;
}

function dataHoraHistoricoFolha($valor): string
{
    if (!$valor || $valor === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime((string)$valor);
    return $ts ? date('d/m/Y H:i', $ts) : (string)$valor;
}

function tabelaExisteHistoricoFolha(PDO $pdo, string $tabela): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$tabela]);
    return (int)$stmt->fetchColumn() > 0;
}

$referencia = trim((string)($_GET['referencia'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'todos'));
$versao = trim((string)($_GET['versao'] ?? ''));

if ($referencia !== '' && !preg_match('/^\d{4}-\d{2}$/', $referencia)) {
    $referencia = '';
}

if (!in_array($status, ['todos', 'ATUAL', 'HISTORICO'], true)) {
    $status = 'todos';
}

$temTabelas = tabelaExisteHistoricoFolha($pdo_master, 'colaboradores_folha_versoes')
    && tabelaExisteHistoricoFolha($pdo_master, 'colaboradores_folha_itens');

$folhas = [];
if ($temTabelas) {
    $where = ['v.empresa_id = ?'];
    $params = [$empresaId];

    if ($referencia !== '') {
        $where[] = 'v.referencia = ?';
        $params[] = $referencia;
    }

    if ($status !== 'todos') {
        $where[] = 'v.status = ?';
        $params[] = $status;
    }

    if ($versao !== '' && ctype_digit($versao)) {
        $where[] = 'v.versao = ?';
        $params[] = (int)$versao;
    }

    $sql = "
        SELECT
            v.*,
            COUNT(i.id) AS itens_salvos
        FROM colaboradores_folha_versoes v
        LEFT JOIN colaboradores_folha_itens i ON i.folha_versao_id = v.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY v.id
        ORDER BY v.referencia DESC, v.versao DESC
        LIMIT 300
    ";
    $stmt = $pdo_master->prepare($sql);
    $stmt->execute($params);
    $folhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Colaboradores</span>
                <h1 class="h3 fw-bold mb-2">Historico de Folhas</h1>
                <p class="text-muted mb-0">Consulte as versoes atuais e historicas das folhas geradas no SuperDunga.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="d-flex flex-wrap justify-content-lg-end gap-2">
                    <a href="folha_pagamento.php" class="btn btn-outline-primary">Folha de Pagamento</a>
                    <a href="menu_colaboradores.php" class="btn btn-outline-secondary">Voltar</a>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Referencia</label>
                <input type="month" name="referencia" value="<?= htmlspecialchars($referencia) ?>" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="ATUAL" <?= $status === 'ATUAL' ? 'selected' : '' ?>>Atual</option>
                    <option value="HISTORICO" <?= $status === 'HISTORICO' ? 'selected' : '' ?>>Historico</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Versao</label>
                <input type="number" min="1" name="versao" value="<?= htmlspecialchars($versao) ?>" class="form-control">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Filtrar</button>
                <a href="historico_folhas.php" class="btn btn-outline-secondary">Limpar</a>
            </div>
        </form>
    </div>
</section>

<section class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center gap-3">
        <div>
            <h2 class="h6 fw-bold mb-0">Versoes geradas</h2>
            <small class="text-muted">A versao atual e a que fica ativa para consulta normal da folha.</small>
        </div>
        <span class="badge text-bg-light"><?= count($folhas) ?> registro(s)</span>
    </div>
    <div class="card-body p-0">
        <?php if (!$temTabelas): ?>
            <div class="alert alert-info m-3">Nenhuma folha foi gerada ainda.</div>
        <?php elseif (empty($folhas)): ?>
            <div class="alert alert-info m-3">Nenhuma versao encontrada para os filtros informados.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Referencia</th>
                            <th>Versao</th>
                            <th>Status</th>
                            <th>Pagamento</th>
                            <th class="text-end">Recibos</th>
                            <th class="text-end">Vencimentos</th>
                            <th class="text-end">Descontos</th>
                            <th class="text-end">Liquido</th>
                            <th>Criado em</th>
                            <th class="text-end">Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($folhas as $folha): ?>
                            <?php
                                $ref = (string)$folha['referencia'];
                                $versaoFolha = (int)$folha['versao'];
                                $linkVersao = 'folha_pagamento.php?referencia=' . urlencode($ref) . '&versao=' . $versaoFolha;
                                $linkAtual = 'folha_pagamento.php?referencia=' . urlencode($ref);
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($ref) ?></td>
                                <td><?= $versaoFolha ?></td>
                                <td>
                                    <span class="badge <?= $folha['status'] === 'ATUAL' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                        <?= htmlspecialchars((string)$folha['status']) ?>
                                    </span>
                                </td>
                                <td><?= dataHistoricoFolha($folha['data_pagamento'] ?? '') ?></td>
                                <td class="text-end"><?= (int)($folha['itens_salvos'] ?? $folha['total_recibos'] ?? 0) ?></td>
                                <td class="text-end"><?= moedaHistoricoFolha($folha['total_vencimentos'] ?? 0) ?></td>
                                <td class="text-end"><?= moedaHistoricoFolha($folha['total_descontos'] ?? 0) ?></td>
                                <td class="text-end fw-semibold"><?= moedaHistoricoFolha($folha['total_liquido'] ?? 0) ?></td>
                                <td><?= dataHoraHistoricoFolha($folha['criado_em'] ?? '') ?></td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= htmlspecialchars($linkVersao) ?>" class="btn btn-outline-primary">Abrir versao</a>
                                        <?php if ($folha['status'] !== 'ATUAL'): ?>
                                            <a href="<?= htmlspecialchars($linkAtual) ?>" class="btn btn-outline-secondary">Atual</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
