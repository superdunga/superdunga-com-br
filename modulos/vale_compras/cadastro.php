<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require __DIR__ . '/../movimentacao_baixa/_empresa2_guard.php';
require_once __DIR__ . '/_lib.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$perfil = strtoupper((string)($_SESSION['nivel'] ?? ''));
$permitido = moduloPermitido($pdo, $empresaId, 'vale_compras_operacoes', $perfil);

garantirTabelasValeCompras($pdo);

$mensagem = '';
$erro = '';

function vcCadastroRedirect(array $params = []): void
{
    header('Location: cadastro.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $permitido && $empresaId === 2) {
    try {
        $acao = $_POST['acao'] ?? '';
        if ($acao === 'salvar_vale') {
            $valeId = (int)($_POST['vale_id'] ?? 0);
            $identificacao = trim((string)($_POST['identificacao'] ?? ''));
            $saldoInicial = vcFloat($_POST['saldo_inicial'] ?? '0');
            if ($identificacao === '') {
                throw new RuntimeException('Informe a identificacao do vale-compra.');
            }

            if ($valeId > 0) {
                $stmt = $pdo->prepare("UPDATE vale_compras_vales SET identificacao = ?, saldo_inicial = ? WHERE id = ? AND empresa_id = ?");
                $stmt->execute([$identificacao, $saldoInicial, $valeId, $empresaId]);
                vcCadastroRedirect(['editar' => $valeId, 'ok' => 'vale']);
            }

            $stmt = $pdo->prepare("INSERT INTO vale_compras_vales (empresa_id, identificacao, saldo_inicial, criado_por) VALUES (?, ?, ?, ?)");
            $stmt->execute([$empresaId, $identificacao, $saldoInicial, $usuarioId ?: null]);
            vcCadastroRedirect(['editar' => (int)$pdo->lastInsertId(), 'ok' => 'vale']);
        }

        if ($acao === 'encerrar_vale') {
            $valeId = (int)($_POST['vale_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE vale_compras_vales SET status = 'ENCERRADO' WHERE id = ? AND empresa_id = ?");
            $stmt->execute([$valeId, $empresaId]);
            vcCadastroRedirect(['ok' => 'encerrado']);
        }

        if ($acao === 'reabrir_vale') {
            $valeId = (int)($_POST['vale_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE vale_compras_vales SET status = 'ABERTO' WHERE id = ? AND empresa_id = ?");
            $stmt->execute([$valeId, $empresaId]);
            vcCadastroRedirect(['editar' => $valeId, 'ok' => 'reaberto']);
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$ok = $_GET['ok'] ?? '';
if ($ok === 'vale') {
    $mensagem = 'Vale-compra salvo com sucesso.';
} elseif ($ok === 'encerrado') {
    $mensagem = 'Vale-compra encerrado.';
} elseif ($ok === 'reaberto') {
    $mensagem = 'Vale-compra reaberto.';
}

$valeAtual = null;
$editarId = (int)($_GET['editar'] ?? 0);
if ($permitido && $editarId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM vale_compras_vales WHERE id = ? AND empresa_id = ?");
    $stmt->execute([$editarId, $empresaId]);
    $valeAtual = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$fStatus = $_GET['status'] ?? 'abertos';
$fBusca = trim((string)($_GET['busca'] ?? ''));
$fSemData = ($_GET['sem_data'] ?? 'S') === 'S';
$fDataIni = trim((string)($_GET['data_ini'] ?? ''));
$fDataFim = trim((string)($_GET['data_fim'] ?? ''));
$where = ["v.empresa_id = ?"];
$whereParams = [$empresaId];
$join = ["m.vale_id = v.id"];
$joinParams = [];
if ($fStatus === 'abertos') {
    $where[] = "v.status <> 'ENCERRADO'";
} elseif ($fStatus === 'encerrados') {
    $where[] = "v.status = 'ENCERRADO'";
}
if ($fBusca !== '') {
    $where[] = "(v.identificacao LIKE ? OR v.id = ?)";
    $whereParams[] = '%' . $fBusca . '%';
    $whereParams[] = ctype_digit($fBusca) ? (int)$fBusca : 0;
}
if (!$fSemData) {
    if ($fDataIni !== '') {
        $join[] = "m.data_movimento >= ?";
        $joinParams[] = $fDataIni;
    }
    if ($fDataFim !== '') {
        $join[] = "m.data_movimento <= ?";
        $joinParams[] = $fDataFim;
    }
    if ($fDataIni !== '' || $fDataFim !== '') {
        $where[] = "m.id IS NOT NULL";
    }
}

$vales = [];
$totaisVales = ['saldo_inicial' => 0.0, 'compras' => 0.0, 'vendas' => 0.0, 'desconto' => 0.0, 'saldo' => 0.0];
if ($permitido && $empresaId === 2) {
    $stmt = $pdo->prepare("
        SELECT v.*,
               COALESCE(SUM(CASE WHEN m.tipo = 'COMPRA' THEN m.valor_nominal ELSE 0 END), 0) AS total_compras,
               COALESCE(SUM(CASE WHEN m.tipo = 'COMPRA' THEN m.valor_desagio ELSE 0 END), 0) AS total_desconto,
               COALESCE(SUM(CASE WHEN m.tipo = 'VENDA' THEN m.valor ELSE 0 END), 0) AS total_vendas
        FROM vale_compras_vales v
        LEFT JOIN vale_compras_movimentos m ON " . implode(' AND ', $join) . "
        WHERE " . implode(' AND ', $where) . "
        GROUP BY v.id
        ORDER BY v.id DESC
        LIMIT 300
    ");
    $stmt->execute(array_merge($joinParams, $whereParams));
    $vales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vales as $valeTotal) {
        $totaisVales['saldo_inicial'] += (float)($valeTotal['saldo_inicial'] ?? 0);
        $totaisVales['compras'] += (float)$valeTotal['total_compras'];
        $totaisVales['vendas'] += (float)$valeTotal['total_vendas'];
        $totaisVales['desconto'] += (float)$valeTotal['total_desconto'];
    }
    $totaisVales['saldo'] = $totaisVales['saldo_inicial'] + $totaisVales['compras'] - $totaisVales['vendas'];
}

require '../../layout/header.php';
?>

<style>
.vc-wrap { max-width: 1180px; margin: 0 auto; }
.vc-hero { background:#123c69; color:#fff; border-radius:8px; padding:24px; display:flex; justify-content:space-between; gap:16px; align-items:center; }
.vc-card { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:18px; margin-top:16px; }
.vc-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
.vc-field label { font-size:12px; font-weight:700; color:#495057; margin-bottom:4px; display:block; }
.vc-field input, .vc-field select { width:100%; border:1px solid #ced4da; border-radius:6px; padding:9px 10px; }
.vc-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.vc-filter { display:grid; grid-template-columns:2fr 1fr 1fr 1fr auto auto; gap:10px; align-items:end; }
.vc-table { width:100%; border-collapse:collapse; font-size:13px; }
.vc-table th, .vc-table td { border-bottom:1px solid #e9ecef; padding:9px; vertical-align:middle; }
.vc-table th { background:#f1f5f9; font-size:12px; text-transform:uppercase; color:#334155; }
.vc-kpi { border:1px solid #e2e8f0; border-radius:8px; padding:14px; background:#f8fafc; }
.vc-kpi small { color:#64748b; display:block; font-weight:700; text-transform:uppercase; font-size:11px; }
.vc-kpi strong { font-size:20px; }
@media (max-width: 900px) { .vc-hero { display:block; } .vc-grid, .vc-filter { grid-template-columns:1fr; } .vc-table { min-width:760px; } .vc-scroll { overflow-x:auto; } }
</style>

<div class="vc-wrap">
    <section class="vc-hero">
        <div>
            <span class="badge text-bg-light mb-2">Vale-Compras</span>
            <h1 class="h4 fw-bold mb-1">Cadastro de Vales</h1>
            <p class="mb-0 opacity-75">Cadastre o cabeçalho do vale. Compras e vendas ficam na tela de lançamentos.</p>
        </div>
        <div class="vc-actions">
            <a href="../../index.php" class="btn btn-outline-light">Voltar</a>
            <a href="cadastro.php" class="btn btn-warning">Novo vale</a>
        </div>
    </section>

    <?php if (!$permitido): ?>
        <div class="alert alert-danger mt-3">Seu usuario nao possui permissao para acessar esta rotina.</div>
    <?php else: ?>
        <?php if ($mensagem): ?><div class="alert alert-success mt-3"><?= vcH($mensagem) ?></div><?php endif; ?>
        <?php if ($erro): ?><div class="alert alert-danger mt-3"><?= vcH($erro) ?></div><?php endif; ?>

        <section class="vc-card">
            <h2 class="h6 fw-bold mb-3"><?= $valeAtual ? 'Editar vale #' . (int)$valeAtual['id'] : 'Novo vale-compra' ?></h2>
            <form method="post">
                <input type="hidden" name="acao" value="salvar_vale">
                <input type="hidden" name="vale_id" value="<?= (int)($valeAtual['id'] ?? 0) ?>">
                <div class="vc-grid">
                    <div class="vc-field" style="grid-column:1 / span 2">
                        <label>Identificação do vale</label>
                        <input type="text" name="identificacao" required maxlength="120" value="<?= vcH($valeAtual['identificacao'] ?? '') ?>" placeholder="João Henrique / Vale #1">
                    </div>
                    <div class="vc-field">
                        <label>Saldo inicial</label>
                        <input type="text" name="saldo_inicial" inputmode="decimal" value="<?= vcH(number_format((float)($valeAtual['saldo_inicial'] ?? 0), 2, ',', '.')) ?>">
                    </div>
                    <?php if ($valeAtual): ?>
                        <div class="vc-field">
                            <label>Status</label>
                            <input type="text" value="<?= vcH($valeAtual['status']) ?>" readonly>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="vc-actions mt-3">
                    <button class="btn btn-primary">Salvar vale</button>
                    <?php if ($valeAtual): ?>
                        <a class="btn btn-outline-primary" href="lancamentos.php?vale=<?= (int)$valeAtual['id'] ?>">Ir para lançamentos</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="vc-card">
            <div class="mb-3">
                <h2 class="h6 fw-bold mb-3">Vales cadastrados</h2>
                <form method="get" class="vc-filter">
                    <div class="vc-field">
                        <label>Vale</label>
                        <input type="text" name="busca" value="<?= vcH($fBusca) ?>" placeholder="Numero ou identificacao">
                    </div>
                    <div class="vc-field">
                        <label>Status</label>
                        <select name="status">
                        <option value="abertos" <?= $fStatus === 'abertos' ? 'selected' : '' ?>>Abertos</option>
                        <option value="todos" <?= $fStatus === 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="encerrados" <?= $fStatus === 'encerrados' ? 'selected' : '' ?>>Encerrados</option>
                        </select>
                    </div>
                    <div class="vc-field">
                        <label>Data inicial operacao</label>
                        <input type="date" name="data_ini" value="<?= vcH($fDataIni) ?>" <?= $fSemData ? 'disabled' : '' ?>>
                    </div>
                    <div class="vc-field">
                        <label>Data final operacao</label>
                        <input type="date" name="data_fim" value="<?= vcH($fDataFim) ?>" <?= $fSemData ? 'disabled' : '' ?>>
                    </div>
                    <input type="hidden" name="sem_data" value="N">
                    <label class="form-check d-flex align-items-center gap-2 mb-2">
                        <input class="form-check-input" type="checkbox" name="sem_data" value="S" <?= $fSemData ? 'checked' : '' ?> onchange="this.form.querySelectorAll('input[type=date]').forEach(function(el){ el.disabled = this.checked; }, this);">
                        <span>Sem data</span>
                    </label>
                    <div class="vc-actions mb-2">
                        <button class="btn btn-sm btn-outline-secondary">Filtrar</button>
                        <a class="btn btn-sm btn-outline-light text-dark border" href="cadastro.php">Limpar</a>
                    </div>
                </form>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md"><div class="vc-kpi"><small>Saldo inicial</small><strong><?= vcMoeda($totaisVales['saldo_inicial']) ?></strong></div></div>
                <div class="col-md"><div class="vc-kpi"><small>Compras</small><strong><?= vcMoeda($totaisVales['compras']) ?></strong></div></div>
                <div class="col-md"><div class="vc-kpi"><small>Vendas</small><strong><?= vcMoeda($totaisVales['vendas']) ?></strong></div></div>
                <div class="col-md"><div class="vc-kpi"><small>Desconto</small><strong><?= vcMoeda($totaisVales['desconto']) ?></strong></div></div>
                <div class="col-md"><div class="vc-kpi"><small>Saldo</small><strong><?= vcMoeda($totaisVales['saldo']) ?></strong></div></div>
            </div>
            <div class="vc-scroll">
                <table class="vc-table">
                    <thead>
                        <tr>
                            <th>Vale</th>
                            <th>Identificação</th>
                            <th>Saldo inicial</th>
                            <th>Compras</th>
                            <th>Vendas</th>
                            <th>Desconto</th>
                            <th>Saldo</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vales as $vale): ?>
                            <?php
                            $saldoInicial = (float)($vale['saldo_inicial'] ?? 0);
                            $compras = (float)$vale['total_compras'];
                            $vendas = (float)$vale['total_vendas'];
                            $desconto = (float)$vale['total_desconto'];
                            $saldo = $saldoInicial + $compras - $vendas;
                            ?>
                            <tr>
                                <td>#<?= (int)$vale['id'] ?></td>
                                <td><?= vcH($vale['identificacao']) ?></td>
                                <td><?= vcMoeda($saldoInicial) ?></td>
                                <td><?= vcMoeda($compras) ?></td>
                                <td><?= vcMoeda($vendas) ?></td>
                                <td><?= vcMoeda($desconto) ?></td>
                                <td class="fw-semibold"><?= vcMoeda($saldo) ?></td>
                                <td><span class="badge text-bg-<?= ($vale['status'] ?? '') === 'ENCERRADO' ? 'secondary' : 'primary' ?>"><?= vcH($vale['status']) ?></span></td>
                                <td class="text-end">
                                    <div class="vc-actions justify-content-end">
                                        <a href="cadastro.php?editar=<?= (int)$vale['id'] ?>" class="btn btn-sm btn-outline-secondary">Editar</a>
                                        <a href="lancamentos.php?vale=<?= (int)$vale['id'] ?>" class="btn btn-sm btn-outline-primary">Lançamentos</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$vales): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">Nenhum vale-compra encontrado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require '../../layout/footer.php'; ?>
