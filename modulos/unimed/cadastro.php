<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

garantirTabelasUnimed($pdo_master);

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);

$nome = trim((string)($_GET['nome'] ?? ''));
$familia = trim((string)($_GET['familia'] ?? ''));
$situacao = $_GET['situacao'] ?? 'ativos';
$telefoneStatus = $_GET['telefone'] ?? 'todos';

$where = ['empresa_id = ?'];
$params = [$empresaId];

if ($nome !== '') {
    $where[] = 'nome LIKE ?';
    $params[] = '%' . $nome . '%';
}

if ($familia !== '') {
    $where[] = 'familia = ?';
    $params[] = $familia;
}

if ($situacao === 'ativos') {
    $where[] = "ativo = 'S'";
} elseif ($situacao === 'inativos') {
    $where[] = "ativo = 'N'";
}

if ($telefoneStatus === 'com_telefone') {
    $where[] = "telefone_whatsapp IS NOT NULL AND telefone_whatsapp <> ''";
} elseif ($telefoneStatus === 'sem_telefone') {
    $where[] = "(telefone_whatsapp IS NULL OR telefone_whatsapp = '')";
}

$sqlWhere = implode(' AND ', $where);

$stmtResumo = $pdo_master->prepare("
    SELECT
        COUNT(*) AS qtd,
        COUNT(DISTINCT familia) AS familias,
        SUM(CASE WHEN tipo = 'TITULAR' THEN 1 ELSE 0 END) AS titulares,
        SUM(CASE WHEN tipo = 'DEPENDENTE' THEN 1 ELSE 0 END) AS dependentes
    FROM unimed_beneficiarios
    WHERE {$sqlWhere}
");
$stmtResumo->execute($params);
$resumo = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'familias' => 0, 'titulares' => 0, 'dependentes' => 0];

$stmt = $pdo_master->prepare("
    SELECT *
    FROM unimed_beneficiarios b
    WHERE {$sqlWhere}
    ORDER BY familia, dependente, nome
");
$stmt->execute($params);
$beneficiarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

$responsaveisPorId = [];
if (!empty($beneficiarios)) {
    $idsResponsaveis = array_values(array_unique(array_filter(array_map(static function (array $beneficiario): int {
        return (int)($beneficiario['responsavel_pagamento_id'] ?? 0);
    }, $beneficiarios))));

    if (!empty($idsResponsaveis)) {
        $placeholdersResponsaveis = implode(',', array_fill(0, count($idsResponsaveis), '?'));
        $stmtResponsaveis = $pdo_master->prepare("
            SELECT id, nome, codigo_completo
            FROM unimed_beneficiarios
            WHERE empresa_id = ?
              AND id IN ({$placeholdersResponsaveis})
        ");
        $stmtResponsaveis->execute(array_merge([$empresaId], $idsResponsaveis));
        foreach ($stmtResponsaveis->fetchAll(PDO::FETCH_ASSOC) as $responsavel) {
            $responsaveisPorId[(int)$responsavel['id']] = $responsavel;
        }
    }
}

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-success mb-3">Unimed</span>
                <h1 class="h3 fw-bold mb-2">Cadastro de Usuarios</h1>
                <p class="text-muted mb-0">Titulares, dependentes e familias cadastradas a partir dos analiticos da Unimed.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_unimed.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<section class="mb-3">
    <form method="get" class="card shadow-sm">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">Nome</label>
                    <input type="text" name="nome" value="<?= htmlspecialchars($nome) ?>" class="form-control" placeholder="Buscar beneficiario">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Familia</label>
                    <input type="text" name="familia" value="<?= htmlspecialchars($familia) ?>" class="form-control" placeholder="000001">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Situacao</label>
                    <select name="situacao" class="form-select">
                        <option value="ativos" <?= $situacao === 'ativos' ? 'selected' : '' ?>>Ativos</option>
                        <option value="inativos" <?= $situacao === 'inativos' ? 'selected' : '' ?>>Inativos</option>
                        <option value="todos" <?= $situacao === 'todos' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Telefone</label>
                    <select name="telefone" class="form-select">
                        <option value="todos" <?= $telefoneStatus === 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="com_telefone" <?= $telefoneStatus === 'com_telefone' ? 'selected' : '' ?>>Com telefone</option>
                        <option value="sem_telefone" <?= $telefoneStatus === 'sem_telefone' ? 'selected' : '' ?>>Sem telefone</option>
                    </select>
                </div>
                <div class="col-md-12 col-lg-1">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </div>
        </div>
    </form>
</section>

<section class="mb-3">
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Usuarios</div><div class="h4 mb-0"><?= (int)$resumo['qtd'] ?></div></div></div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Familias</div><div class="h4 mb-0"><?= (int)$resumo['familias'] ?></div></div></div>
        </div>
        <div class="col-md-2">
            <div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Titulares</div><div class="h4 mb-0"><?= (int)$resumo['titulares'] ?></div></div></div>
        </div>
        <div class="col-md-2">
            <div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Dependentes</div><div class="h4 mb-0"><?= (int)$resumo['dependentes'] ?></div></div></div>
        </div>
    </div>
</section>

<section class="card shadow-sm">
    <div class="card-header">
        <h2 class="h6 mb-0">Beneficiarios</h2>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>Codigo</th>
                        <th>Familia</th>
                        <th>Tipo</th>
                        <th>Nome</th>
                        <th>Responsavel</th>
                        <th>Telefone WhatsApp</th>
                        <th>Plano</th>
                        <th>Status</th>
                        <th class="text-end">Acao</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($beneficiarios)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Nenhum beneficiario encontrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($beneficiarios as $beneficiario): ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($beneficiario['codigo_completo']) ?></td>
                            <td><?= htmlspecialchars($beneficiario['familia']) ?></td>
                            <td><?= htmlspecialchars($beneficiario['tipo']) ?></td>
                            <td><?= htmlspecialchars($beneficiario['nome']) ?></td>
                            <td>
                                <?php
                                $responsavelId = (int)($beneficiario['responsavel_pagamento_id'] ?? 0);
                                $responsavel = $responsavelId > 0 ? ($responsaveisPorId[$responsavelId] ?? null) : null;
                                ?>
                                <?php if ($responsavel): ?>
                                    <?= htmlspecialchars($responsavel['nome']) ?>
                                    <small class="d-block text-muted"><?= htmlspecialchars($responsavel['codigo_completo']) ?></small>
                                <?php else: ?>
                                    <span class="text-muted">Nao informado</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string)($beneficiario['telefone_whatsapp'] ?? '')) ?: '<span class="text-muted">Nao informado</span>' ?></td>
                            <td><?= htmlspecialchars((string)$beneficiario['plano']) ?></td>
                            <td><?= $beneficiario['ativo'] === 'S' ? '<span class="badge text-bg-success">Ativo</span>' : '<span class="badge text-bg-secondary">Inativo</span>' ?></td>
                            <td class="text-end">
                                <a href="beneficiario.php?id=<?= (int)$beneficiario['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
