<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require __DIR__ . '/_empresa2_guard.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$perfil = strtoupper((string)($_SESSION['nivel'] ?? ''));
$permitido = moduloPermitido($pdo, $empresaId, 'movimentacao_baixa_fornecedores', $perfil);

function mbfH($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function mbfProximoFornecedor(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(FCONTADOR), 0) + 1 FROM armazem_cp003 WHERE EMPRESA = 2");
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function mbfFornecedor(PDO $pdo, int $fcontador): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_cp003
        WHERE EMPRESA = 2
          AND FCONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$fcontador]);
    $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fornecedor ?: null;
}

function mbfDocumentoDuplicado(PDO $pdo, string $cgc, int $fcontadorAtual = 0): bool
{
    if ($cgc === '') {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM armazem_cp003
        WHERE EMPRESA = 2
          AND CGC = ?
          AND FCONTADOR <> ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
    ");
    $stmt->execute([$cgc, $fcontadorAtual]);
    return (int)$stmt->fetchColumn() > 0;
}

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $permitido && $empresaId === 2) {
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'salvar') {
            $fcontador = (int)($_POST['fcontador'] ?? 0);
            $nome = trim((string)($_POST['nome'] ?? ''));
            $apelido = trim((string)($_POST['apelido'] ?? ''));
            $tipoFornecedor = trim((string)($_POST['tipo_forn'] ?? ''));
            $cgc = trim((string)($_POST['cgc'] ?? ''));
            $tipoes = (int)($_POST['tipoes'] ?? 0);
            $observacao = trim((string)($_POST['observacao'] ?? ''));
            $inativo = ($_POST['inativo'] ?? 'N') === 'S' ? 'S' : 'N';

            if ($nome === '') {
                throw new RuntimeException('Informe o nome do fornecedor.');
            }
            if (mbfDocumentoDuplicado($pdo, $cgc, $fcontador)) {
                throw new RuntimeException('Ja existe fornecedor com este CPF/CNPJ.');
            }

            if ($fcontador > 0 && mbfFornecedor($pdo, $fcontador)) {
                $stmt = $pdo->prepare("
                    UPDATE armazem_cp003
                    SET NOME = ?, APELIDO = ?, TIPOFORN = ?, CGC = ?, TIPOES = ?, OBSERVACAO = ?,
                        INATIVO = ?, REGDISAB = ?, USERALT = ?, DTALT = NOW(), REGSTAMP = NOW(),
                        REGIMPORT = 'N'
                    WHERE EMPRESA = 2
                      AND FCONTADOR = ?
                ");
                $stmt->execute([$nome, $apelido, $tipoFornecedor, $cgc, $tipoes ?: null, $observacao, $inativo, $inativo, $usuarioId ?: null, $fcontador]);
                $mensagem = 'Fornecedor atualizado com sucesso.';
            } else {
                $fcontador = mbfProximoFornecedor($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO armazem_cp003
                        (EMPRESA, FCONTADOR, NOME, APELIDO, TIPOFORN, CGC, TIPOES, OBSERVACAO,
                         INATIVO, REGDISAB, USERLANC, USERALT, DTCADASTRO, DTLANC, DTALT,
                         REGSTAMP, REGIMPORT, excluido_firebird)
                    VALUES
                        (2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), NOW(), 'N', 'N')
                ");
                $stmt->execute([$fcontador, $nome, $apelido, $tipoFornecedor, $cgc, $tipoes ?: null, $observacao, $inativo, $inativo, $usuarioId ?: null, $usuarioId ?: null]);
                $mensagem = 'Fornecedor cadastrado com sucesso.';
            }
        } elseif ($acao === 'inativar') {
            $fcontador = (int)($_POST['fcontador'] ?? 0);
            $atual = mbfFornecedor($pdo, $fcontador);
            if (!$atual) {
                throw new RuntimeException('Fornecedor nao encontrado.');
            }
            $novaSituacao = (($atual['INATIVO'] ?? 'N') === 'S' || ($atual['REGDISAB'] ?? 'N') === 'S') ? 'N' : 'S';
            $stmt = $pdo->prepare("
                UPDATE armazem_cp003
                SET INATIVO = ?,
                    REGDISAB = ?,
                    USERALT = ?, DTALT = NOW(), REGSTAMP = NOW()
                WHERE EMPRESA = 2
                  AND FCONTADOR = ?
            ");
            $stmt->execute([$novaSituacao, $novaSituacao, $usuarioId ?: null, $fcontador]);
            $mensagem = 'Situacao do fornecedor alterada.';
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$editarId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$editar = ($permitido && $empresaId === 2 && $editarId > 0) ? mbfFornecedor($pdo, $editarId) : null;

$fNome = trim((string)($_GET['nome'] ?? ''));
$fDoc = trim((string)($_GET['documento'] ?? ''));
$fSituacao = $_GET['situacao'] ?? 'ativos';
$fTipoesParam = trim((string)($_GET['tipoes'] ?? ''));
$fTipoes = ctype_digit($fTipoesParam) ? (int)$fTipoesParam : 0;
$fornecedores = [];
$tipos = [];

if ($permitido && $empresaId === 2) {
    $stmt = $pdo->prepare("
        SELECT ESCONTADOR, DESCES, TIPOMOV
        FROM armazem_bnc005
        WHERE EMPRESA = ?
          AND COALESCE(REGDISAB, 'N') <> 'S'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        ORDER BY GRUPOBNC, ESCONTADOR
    ");
    $stmt->execute([$empresaId]);
    $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $where = ["f.EMPRESA = 2", "COALESCE(f.excluido_firebird, 'N') <> 'S'"];
    $params = [];
    if ($fNome !== '') {
        $where[] = "(f.NOME LIKE ? OR f.APELIDO LIKE ? OR f.FCONTADOR LIKE ?)";
        $like = '%' . $fNome . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($fDoc !== '') {
        $where[] = "f.CGC LIKE ?";
        $params[] = '%' . $fDoc . '%';
    }
    if ($fSituacao === 'ativos') {
        $where[] = "COALESCE(f.INATIVO, 'N') <> 'S'";
        $where[] = "COALESCE(f.REGDISAB, 'N') <> 'S'";
    } elseif ($fSituacao === 'inativos') {
        $where[] = "(COALESCE(f.INATIVO, 'N') = 'S' OR COALESCE(f.REGDISAB, 'N') = 'S')";
    }
    if ($fTipoesParam === 'sem_padrao') {
        $where[] = "(f.TIPOES IS NULL OR f.TIPOES = 0)";
    } elseif ($fTipoes > 0) {
        $where[] = "f.TIPOES = ?";
        $params[] = $fTipoes;
    }

    $stmt = $pdo->prepare("
        SELECT f.*, t.DESCES AS tipoes_desc
        FROM armazem_cp003 f
        LEFT JOIN armazem_bnc005 t
          ON t.EMPRESA = f.EMPRESA
         AND t.ESCONTADOR = f.TIPOES
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(NULLIF(f.APELIDO, ''), f.NOME), f.FCONTADOR
        LIMIT 300
    ");
    $stmt->execute($params);
    $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$form = [
    'fcontador' => $editar['FCONTADOR'] ?? '',
    'nome' => $editar['NOME'] ?? '',
    'apelido' => $editar['APELIDO'] ?? '',
    'tipo_forn' => $editar['TIPOFORN'] ?? '',
    'cgc' => $editar['CGC'] ?? '',
    'tipoes' => $editar['TIPOES'] ?? '',
    'observacao' => $editar['OBSERVACAO'] ?? '',
    'inativo' => $editar['INATIVO'] ?? 'N',
];

require '../../layout/header.php';
?>

<style>
.mbf-wrap { max-width: 1180px; margin: 0 auto; }
.mbf-hero { background: #123c69; color: #fff; border-radius: 8px; padding: 24px; display:flex; justify-content:space-between; gap:16px; align-items:center; }
.mbf-card { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:18px; margin-top:16px; }
.mbf-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
.mbf-grid-3 { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
.mbf-field label { font-size:12px; font-weight:700; color:#495057; margin-bottom:4px; display:block; }
.mbf-field input, .mbf-field select, .mbf-field textarea { width:100%; border:1px solid #ced4da; border-radius:6px; padding:9px 10px; }
.mbf-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.mbf-table { width:100%; border-collapse:collapse; font-size:13px; }
.mbf-table th, .mbf-table td { border-bottom:1px solid #e9ecef; padding:9px; vertical-align:middle; }
.mbf-table th { background:#f1f5f9; font-size:12px; text-transform:uppercase; color:#334155; }
@media (max-width: 800px) { .mbf-hero { display:block; } .mbf-grid, .mbf-grid-3 { grid-template-columns:1fr; } .mbf-table { min-width:820px; } .mbf-scroll { overflow-x:auto; } }
</style>

<div class="mbf-wrap">
    <section class="mbf-hero">
        <div>
            <span class="badge text-bg-light mb-2">Mov/Baixa</span>
            <h1 class="h4 fw-bold mb-1">Fornecedores</h1>
            <p class="mb-0 opacity-75">Cadastro direto em CP003 para a empresa 2.</p>
        </div>
        <a href="menu_movimentacao_baixa.php" class="btn btn-outline-light">Voltar</a>
    </section>

    <?php if (!$permitido): ?>
        <div class="alert alert-warning mt-3">Voce nao tem permissao para acessar este cadastro.</div>
    <?php elseif ($empresaId !== 2): ?>
        <div class="alert alert-info mt-3">Cadastro disponivel somente para a empresa 2.</div>
    <?php else: ?>
        <?php if ($mensagem): ?><div class="alert alert-success mt-3"><?= mbfH($mensagem) ?></div><?php endif; ?>
        <?php if ($erro): ?><div class="alert alert-danger mt-3"><?= mbfH($erro) ?></div><?php endif; ?>

        <section class="mbf-card">
            <h2 class="h6 fw-bold mb-3"><?= $editar ? 'Editar fornecedor #' . (int)$editar['FCONTADOR'] : 'Novo fornecedor' ?></h2>
            <form method="post">
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="fcontador" value="<?= mbfH($form['fcontador']) ?>">
                <div class="mbf-grid">
                    <div class="mbf-field" style="grid-column: span 2;">
                        <label>Nome</label>
                        <input type="text" name="nome" value="<?= mbfH($form['nome']) ?>" required>
                    </div>
                    <div class="mbf-field">
                        <label>Apelido/Fantasia</label>
                        <input type="text" name="apelido" value="<?= mbfH($form['apelido']) ?>">
                    </div>
                    <div class="mbf-field">
                        <label>CPF/CNPJ</label>
                        <input type="text" name="cgc" value="<?= mbfH($form['cgc']) ?>">
                    </div>
                    <div class="mbf-field">
                        <label>Tipo fornecedor</label>
                        <input type="text" name="tipo_forn" value="<?= mbfH($form['tipo_forn']) ?>">
                    </div>
                    <div class="mbf-field" style="grid-column: span 2;">
                        <label>TIPOES padrao</label>
                        <select name="tipoes">
                            <option value="">Sem tipo padrao</option>
                            <?php foreach ($tipos as $tipo): ?>
                                <option value="<?= (int)$tipo['ESCONTADOR'] ?>" <?= (string)$form['tipoes'] === (string)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                                    <?= mbfH($tipo['DESCES'] . ' (' . $tipo['ESCONTADOR'] . ' - ' . $tipo['TIPOMOV'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mbf-field">
                        <label>Inativo</label>
                        <select name="inativo">
                            <option value="N" <?= $form['inativo'] !== 'S' ? 'selected' : '' ?>>Nao</option>
                            <option value="S" <?= $form['inativo'] === 'S' ? 'selected' : '' ?>>Sim</option>
                        </select>
                    </div>
                    <div class="mbf-field" style="grid-column: 1 / -1;">
                        <label>Observacao</label>
                        <textarea name="observacao" rows="2"><?= mbfH($form['observacao']) ?></textarea>
                    </div>
                </div>
                <div class="mbf-actions mt-3">
                    <button class="btn btn-primary">Salvar fornecedor</button>
                    <a href="fornecedores.php" class="btn btn-outline-secondary">Novo/Limpar</a>
                </div>
            </form>
        </section>

        <section class="mbf-card">
            <h2 class="h6 fw-bold mb-3">Fornecedores cadastrados</h2>
            <form method="get" class="mbf-grid-3 mb-3">
                <div class="mbf-field">
                    <label>Nome ou codigo</label>
                    <input type="text" name="nome" value="<?= mbfH($fNome) ?>">
                </div>
                <div class="mbf-field">
                    <label>CPF/CNPJ</label>
                    <input type="text" name="documento" value="<?= mbfH($fDoc) ?>">
                </div>
                <div class="mbf-field">
                    <label>Situacao</label>
                    <select name="situacao">
                        <option value="ativos" <?= $fSituacao === 'ativos' ? 'selected' : '' ?>>Ativos</option>
                        <option value="inativos" <?= $fSituacao === 'inativos' ? 'selected' : '' ?>>Inativos</option>
                        <option value="todos" <?= $fSituacao === 'todos' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>
                <div class="mbf-field">
                    <label>TIPOES</label>
                    <select name="tipoes">
                        <option value="">Todos</option>
                        <option value="sem_padrao" <?= $fTipoesParam === 'sem_padrao' ? 'selected' : '' ?>>Sem padrao</option>
                        <?php foreach ($tipos as $tipo): ?>
                            <option value="<?= (int)$tipo['ESCONTADOR'] ?>" <?= $fTipoes === (int)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                                <?= mbfH($tipo['DESCES'] . ' (' . $tipo['ESCONTADOR'] . ' - ' . $tipo['TIPOMOV'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mbf-actions">
                    <button class="btn btn-outline-primary">Filtrar</button>
                    <a href="fornecedores.php" class="btn btn-outline-secondary">Limpar</a>
                </div>
            </form>
            <div class="mbf-scroll">
                <table class="mbf-table">
                    <thead>
                        <tr>
                            <th>Codigo</th>
                            <th>Fornecedor</th>
                            <th>CPF/CNPJ</th>
                            <th>TIPOES</th>
                            <th>Situacao</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fornecedores as $fornecedor): ?>
                            <tr>
                                <td><?= (int)$fornecedor['FCONTADOR'] ?></td>
                                <td>
                                    <strong><?= mbfH($fornecedor['NOME']) ?></strong>
                                    <?php if (!empty($fornecedor['APELIDO'])): ?><br><small class="text-muted"><?= mbfH($fornecedor['APELIDO']) ?></small><?php endif; ?>
                                </td>
                                <td><?= mbfH($fornecedor['CGC'] ?? '') ?></td>
                                <td><?= !empty($fornecedor['TIPOES']) ? mbfH($fornecedor['TIPOES'] . ' - ' . ($fornecedor['tipoes_desc'] ?? '')) : '<span class="text-muted">Sem padrao</span>' ?></td>
                                <td><?= (($fornecedor['INATIVO'] ?? 'N') === 'S' || ($fornecedor['REGDISAB'] ?? 'N') === 'S') ? '<span class="badge text-bg-secondary">Inativo</span>' : '<span class="badge text-bg-success">Ativo</span>' ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="fornecedores.php?editar=<?= (int)$fornecedor['FCONTADOR'] ?>">Editar</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Alterar situacao deste fornecedor?');">
                                        <input type="hidden" name="acao" value="inativar">
                                        <input type="hidden" name="fcontador" value="<?= (int)$fornecedor['FCONTADOR'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Ativar/Inativar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($fornecedores)): ?>
                            <tr><td colspan="6" class="text-center text-muted">Nenhum fornecedor encontrado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_select_busca.php'; ?>
<?php require '../../layout/footer.php'; ?>
