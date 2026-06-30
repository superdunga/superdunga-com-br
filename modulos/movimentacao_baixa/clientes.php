<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$perfil = strtoupper((string)($_SESSION['nivel'] ?? ''));
$permitido = moduloPermitido($pdo, $empresaId, 'movimentacao_baixa_clientes', $perfil);

function mbcH($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function mbcFloat($valor)
{
    if ($valor === null || $valor === '') {
        return 0.0;
    }
    $valor = str_replace(['R$', ' '], '', trim((string)$valor));
    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }
    return (float)$valor;
}

function mbcMoeda($valor)
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function mbcProximoCliente(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CLICONTADOR), 0) + 1 FROM armazem_cr002 WHERE EMPRESA = 2");
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function mbcCliente(PDO $pdo, int $clicontador): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_cr002
        WHERE EMPRESA = 2
          AND CLICONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$clicontador]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    return $cliente ?: null;
}

function mbcDocumentoDuplicado(PDO $pdo, string $campo, string $valor, int $clicontadorAtual = 0): bool
{
    if ($valor === '' || !in_array($campo, ['CPF', 'CGC'], true)) {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM armazem_cr002
        WHERE EMPRESA = 2
          AND {$campo} = ?
          AND CLICONTADOR <> ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
    ");
    $stmt->execute([$valor, $clicontadorAtual]);
    return (int)$stmt->fetchColumn() > 0;
}

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $permitido && $empresaId === 2) {
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'salvar') {
            $clicontador = (int)($_POST['clicontador'] ?? 0);
            $nome = trim((string)($_POST['nome'] ?? ''));
            $apelido = trim((string)($_POST['apelido'] ?? ''));
            $tipoCliente = ($_POST['tipo_cliente'] ?? 'F') === 'J' ? 'J' : 'F';
            $cpf = trim((string)($_POST['cpf'] ?? ''));
            $cgc = trim((string)($_POST['cgc'] ?? ''));
            $celular = trim((string)($_POST['celular'] ?? ''));
            $limite = mbcFloat($_POST['limite_credito'] ?? '0');
            $bloqueado = ($_POST['bloqueado'] ?? 'N') === 'S' ? 'S' : 'N';
            $inativo = ($_POST['inativo'] ?? 'N') === 'S' ? 'S' : 'N';

            if ($nome === '') {
                throw new RuntimeException('Informe o nome do cliente.');
            }
            if (mbcDocumentoDuplicado($pdo, 'CPF', $cpf, $clicontador)) {
                throw new RuntimeException('Ja existe cliente com este CPF.');
            }
            if (mbcDocumentoDuplicado($pdo, 'CGC', $cgc, $clicontador)) {
                throw new RuntimeException('Ja existe cliente com este CNPJ.');
            }

            if ($clicontador > 0 && mbcCliente($pdo, $clicontador)) {
                $stmt = $pdo->prepare("
                    UPDATE armazem_cr002
                    SET NOME = ?, APELIDO = ?, TIPOCLIENTE = ?, CPF = ?, CGC = ?, CELULAR = ?,
                        LIMITECREDITO = ?, BLOQUEADO = ?, INATIVO = ?, DTALTERACAO = NOW(),
                        USERALTERA = ?, REGSTAMP = NOW(), REGIMPORT = 'N'
                    WHERE EMPRESA = 2
                      AND CLICONTADOR = ?
                ");
                $stmt->execute([$nome, $apelido, $tipoCliente, $cpf, $cgc, $celular, $limite, $bloqueado, $inativo, $usuarioId ?: null, $clicontador]);
                $mensagem = 'Cliente atualizado com sucesso.';
            } else {
                $clicontador = mbcProximoCliente($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO armazem_cr002
                        (EMPRESA, CLICONTADOR, NOME, APELIDO, TIPOCLIENTE, CPF, CGC, CELULAR,
                         LIMITECREDITO, BLOQUEADO, INATIVO, DTCADASTRO, DTALTERACAO, USERALTERA,
                         REGSTAMP, REGIMPORT, excluido_firebird)
                    VALUES
                        (2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, NOW(), 'N', 'N')
                ");
                $stmt->execute([$clicontador, $nome, $apelido, $tipoCliente, $cpf, $cgc, $celular, $limite, $bloqueado, $inativo, $usuarioId ?: null]);
                $mensagem = 'Cliente cadastrado com sucesso.';
            }
        } elseif ($acao === 'inativar') {
            $clicontador = (int)($_POST['clicontador'] ?? 0);
            $stmt = $pdo->prepare("
                UPDATE armazem_cr002
                SET INATIVO = CASE WHEN COALESCE(INATIVO, 'N') = 'S' THEN 'N' ELSE 'S' END,
                    DTALTERACAO = NOW(), USERALTERA = ?, REGSTAMP = NOW()
                WHERE EMPRESA = 2
                  AND CLICONTADOR = ?
            ");
            $stmt->execute([$usuarioId ?: null, $clicontador]);
            $mensagem = 'Situacao do cliente alterada.';
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$editarId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$editar = ($permitido && $empresaId === 2 && $editarId > 0) ? mbcCliente($pdo, $editarId) : null;

$fNome = trim((string)($_GET['nome'] ?? ''));
$fDoc = trim((string)($_GET['documento'] ?? ''));
$fSituacao = $_GET['situacao'] ?? 'ativos';
$clientes = [];

if ($permitido && $empresaId === 2) {
    $where = ["EMPRESA = 2", "COALESCE(excluido_firebird, 'N') <> 'S'"];
    $params = [];
    if ($fNome !== '') {
        $where[] = "(NOME LIKE ? OR APELIDO LIKE ? OR CLICONTADOR LIKE ?)";
        $like = '%' . $fNome . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($fDoc !== '') {
        $where[] = "(CPF LIKE ? OR CGC LIKE ?)";
        $like = '%' . $fDoc . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if ($fSituacao === 'ativos') {
        $where[] = "COALESCE(INATIVO, 'N') <> 'S'";
    } elseif ($fSituacao === 'inativos') {
        $where[] = "COALESCE(INATIVO, 'N') = 'S'";
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_cr002
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(NULLIF(APELIDO, ''), NOME), CLICONTADOR
        LIMIT 300
    ");
    $stmt->execute($params);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$form = [
    'clicontador' => $editar['CLICONTADOR'] ?? '',
    'nome' => $editar['NOME'] ?? '',
    'apelido' => $editar['APELIDO'] ?? '',
    'tipo_cliente' => $editar['TIPOCLIENTE'] ?? 'F',
    'cpf' => $editar['CPF'] ?? '',
    'cgc' => $editar['CGC'] ?? '',
    'celular' => $editar['CELULAR'] ?? '',
    'limite_credito' => isset($editar['LIMITECREDITO']) ? number_format((float)$editar['LIMITECREDITO'], 2, ',', '.') : '',
    'bloqueado' => $editar['BLOQUEADO'] ?? 'N',
    'inativo' => $editar['INATIVO'] ?? 'N',
];

require '../../layout/header.php';
?>

<style>
.mbc-wrap { max-width: 1180px; margin: 0 auto; }
.mbc-hero { background: #123c69; color: #fff; border-radius: 8px; padding: 24px; display:flex; justify-content:space-between; gap:16px; align-items:center; }
.mbc-card { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:18px; margin-top:16px; }
.mbc-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
.mbc-grid-3 { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
.mbc-field label { font-size:12px; font-weight:700; color:#495057; margin-bottom:4px; display:block; }
.mbc-field input, .mbc-field select { width:100%; border:1px solid #ced4da; border-radius:6px; padding:9px 10px; }
.mbc-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.mbc-table { width:100%; border-collapse:collapse; font-size:13px; }
.mbc-table th, .mbc-table td { border-bottom:1px solid #e9ecef; padding:9px; vertical-align:middle; }
.mbc-table th { background:#f1f5f9; font-size:12px; text-transform:uppercase; color:#334155; }
@media (max-width: 800px) { .mbc-hero { display:block; } .mbc-grid, .mbc-grid-3 { grid-template-columns:1fr; } .mbc-table { min-width:760px; } .mbc-scroll { overflow-x:auto; } }
</style>

<div class="mbc-wrap">
    <section class="mbc-hero">
        <div>
            <span class="badge text-bg-light mb-2">Mov/Baixa</span>
            <h1 class="h4 fw-bold mb-1">Clientes</h1>
            <p class="mb-0 opacity-75">Cadastro direto em CR002 para a empresa 2.</p>
        </div>
        <a href="menu_movimentacao_baixa.php" class="btn btn-outline-light">Voltar</a>
    </section>

    <?php if (!$permitido): ?>
        <div class="alert alert-warning mt-3">Voce nao tem permissao para acessar este cadastro.</div>
    <?php elseif ($empresaId !== 2): ?>
        <div class="alert alert-info mt-3">Cadastro disponivel somente para a empresa 2.</div>
    <?php else: ?>
        <?php if ($mensagem): ?><div class="alert alert-success mt-3"><?= mbcH($mensagem) ?></div><?php endif; ?>
        <?php if ($erro): ?><div class="alert alert-danger mt-3"><?= mbcH($erro) ?></div><?php endif; ?>

        <section class="mbc-card">
            <h2 class="h6 fw-bold mb-3"><?= $editar ? 'Editar cliente #' . (int)$editar['CLICONTADOR'] : 'Novo cliente' ?></h2>
            <form method="post">
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="clicontador" value="<?= mbcH($form['clicontador']) ?>">
                <div class="mbc-grid">
                    <div class="mbc-field" style="grid-column: span 2;">
                        <label>Nome</label>
                        <input type="text" name="nome" value="<?= mbcH($form['nome']) ?>" required>
                    </div>
                    <div class="mbc-field">
                        <label>Apelido/Fantasia</label>
                        <input type="text" name="apelido" value="<?= mbcH($form['apelido']) ?>">
                    </div>
                    <div class="mbc-field">
                        <label>Tipo</label>
                        <select name="tipo_cliente">
                            <option value="F" <?= $form['tipo_cliente'] !== 'J' ? 'selected' : '' ?>>Pessoa fisica</option>
                            <option value="J" <?= $form['tipo_cliente'] === 'J' ? 'selected' : '' ?>>Pessoa juridica</option>
                        </select>
                    </div>
                    <div class="mbc-field">
                        <label>CPF</label>
                        <input type="text" name="cpf" value="<?= mbcH($form['cpf']) ?>">
                    </div>
                    <div class="mbc-field">
                        <label>CNPJ</label>
                        <input type="text" name="cgc" value="<?= mbcH($form['cgc']) ?>">
                    </div>
                    <div class="mbc-field">
                        <label>Celular</label>
                        <input type="tel" name="celular" value="<?= mbcH($form['celular']) ?>" inputmode="numeric">
                    </div>
                    <div class="mbc-field">
                        <label>Limite de credito</label>
                        <input type="text" name="limite_credito" value="<?= mbcH($form['limite_credito']) ?>" inputmode="decimal">
                    </div>
                    <div class="mbc-field">
                        <label>Bloqueado</label>
                        <select name="bloqueado">
                            <option value="N" <?= $form['bloqueado'] !== 'S' ? 'selected' : '' ?>>Nao</option>
                            <option value="S" <?= $form['bloqueado'] === 'S' ? 'selected' : '' ?>>Sim</option>
                        </select>
                    </div>
                    <div class="mbc-field">
                        <label>Inativo</label>
                        <select name="inativo">
                            <option value="N" <?= $form['inativo'] !== 'S' ? 'selected' : '' ?>>Nao</option>
                            <option value="S" <?= $form['inativo'] === 'S' ? 'selected' : '' ?>>Sim</option>
                        </select>
                    </div>
                </div>
                <div class="mbc-actions mt-3">
                    <button class="btn btn-primary">Salvar cliente</button>
                    <a href="clientes.php" class="btn btn-outline-secondary">Novo/Limpar</a>
                </div>
            </form>
        </section>

        <section class="mbc-card">
            <h2 class="h6 fw-bold mb-3">Clientes cadastrados</h2>
            <form method="get" class="mbc-grid-3 mb-3">
                <div class="mbc-field">
                    <label>Nome ou codigo</label>
                    <input type="text" name="nome" value="<?= mbcH($fNome) ?>">
                </div>
                <div class="mbc-field">
                    <label>CPF/CNPJ</label>
                    <input type="text" name="documento" value="<?= mbcH($fDoc) ?>">
                </div>
                <div class="mbc-field">
                    <label>Situacao</label>
                    <select name="situacao">
                        <option value="ativos" <?= $fSituacao === 'ativos' ? 'selected' : '' ?>>Ativos</option>
                        <option value="inativos" <?= $fSituacao === 'inativos' ? 'selected' : '' ?>>Inativos</option>
                        <option value="todos" <?= $fSituacao === 'todos' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>
                <div class="mbc-actions">
                    <button class="btn btn-outline-primary">Filtrar</button>
                    <a href="clientes.php" class="btn btn-outline-secondary">Limpar</a>
                </div>
            </form>
            <div class="mbc-scroll">
                <table class="mbc-table">
                    <thead>
                        <tr>
                            <th>Codigo</th>
                            <th>Nome</th>
                            <th>Documento</th>
                            <th>Celular</th>
                            <th>Limite</th>
                            <th>Situacao</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes as $cliente): ?>
                            <tr>
                                <td><?= (int)$cliente['CLICONTADOR'] ?></td>
                                <td>
                                    <strong><?= mbcH($cliente['NOME']) ?></strong>
                                    <?php if (!empty($cliente['APELIDO'])): ?><br><small class="text-muted"><?= mbcH($cliente['APELIDO']) ?></small><?php endif; ?>
                                </td>
                                <td><?= mbcH(trim(($cliente['CPF'] ?? '') . ' ' . ($cliente['CGC'] ?? ''))) ?></td>
                                <td><?= mbcH($cliente['CELULAR'] ?? '') ?></td>
                                <td><?= mbcMoeda($cliente['LIMITECREDITO'] ?? 0) ?></td>
                                <td><?= (($cliente['INATIVO'] ?? 'N') === 'S') ? '<span class="badge text-bg-secondary">Inativo</span>' : '<span class="badge text-bg-success">Ativo</span>' ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="clientes.php?editar=<?= (int)$cliente['CLICONTADOR'] ?>">Editar</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Alterar situacao deste cliente?');">
                                        <input type="hidden" name="acao" value="inativar">
                                        <input type="hidden" name="clicontador" value="<?= (int)$cliente['CLICONTADOR'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Ativar/Inativar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($clientes)): ?>
                            <tr><td colspan="7" class="text-center text-muted">Nenhum cliente encontrado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require '../../layout/footer.php'; ?>
