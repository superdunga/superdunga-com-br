<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$ehMaster = strtoupper((string)($_SESSION['nivel'] ?? '')) === 'MASTER';

if (!$ehMaster) {
    renderizarAcessoNegadoModulo('Somente usuario master pode acessar o cadastro de taxas das adquirentes.');
    exit;
}

garantirTabelaTaxasAdquirentes($pdo_master);

$mensagensGet = [
    'taxa_salva' => 'Taxa salva com sucesso.',
    'taxa_desativada' => 'Taxa desativada.',
];

$mensagem = $mensagensGet[$_GET['msg'] ?? ''] ?? null;
$erro = null;

function taxaAdquirentePostDecimal(string $campo): float
{
    $valor = str_replace(',', '.', trim((string)($_POST[$campo] ?? '0')));
    return (float)$valor;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'salvar_taxa') {
            $id = (int)($_POST['id'] ?? 0);
            $parcelasDe = max(1, (int)($_POST['parcelas_de'] ?? 1));
            $parcelasAte = max($parcelasDe, (int)($_POST['parcelas_ate'] ?? $parcelasDe));
            $dados = [
                'adquirente' => strtoupper(trim((string)($_POST['adquirente'] ?? ''))),
                'grupo' => strtoupper(trim((string)($_POST['grupo'] ?? ''))),
                'tipo_operacao' => strtoupper(trim((string)($_POST['tipo_operacao'] ?? ''))),
                'bandeira' => strtoupper(trim((string)($_POST['bandeira'] ?? 'TODAS'))) ?: 'TODAS',
                'parcelas_de' => $parcelasDe,
                'parcelas_ate' => $parcelasAte,
                'taxa_percentual' => taxaAdquirentePostDecimal('taxa_percentual'),
                'tolerancia_percentual' => taxaAdquirentePostDecimal('tolerancia_percentual'),
                'ativo' => ($_POST['ativo'] ?? 'S') === 'S' ? 'S' : 'N',
            ];

            if ($dados['adquirente'] === '' || $dados['grupo'] === '' || $dados['tipo_operacao'] === '') {
                throw new RuntimeException('Informe adquirente, grupo e tipo.');
            }

            if ($id > 0) {
                $stmt = $pdo_master->prepare("
                    UPDATE fechamento_adquirente_taxas
                    SET adquirente = ?, grupo = ?, tipo_operacao = ?, bandeira = ?,
                        parcelas_de = ?, parcelas_ate = ?, taxa_percentual = ?,
                        tolerancia_percentual = ?, ativo = ?
                    WHERE id = ? AND empresa_id = ?
                ");
                $stmt->execute([
                    $dados['adquirente'],
                    $dados['grupo'],
                    $dados['tipo_operacao'],
                    $dados['bandeira'],
                    $dados['parcelas_de'],
                    $dados['parcelas_ate'],
                    $dados['taxa_percentual'],
                    $dados['tolerancia_percentual'],
                    $dados['ativo'],
                    $id,
                    $empresaId,
                ]);
            } else {
                $stmt = $pdo_master->prepare("
                    INSERT INTO fechamento_adquirente_taxas (
                        empresa_id, adquirente, grupo, tipo_operacao, bandeira,
                        parcelas_de, parcelas_ate, taxa_percentual, tolerancia_percentual, ativo
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $empresaId,
                    $dados['adquirente'],
                    $dados['grupo'],
                    $dados['tipo_operacao'],
                    $dados['bandeira'],
                    $dados['parcelas_de'],
                    $dados['parcelas_ate'],
                    $dados['taxa_percentual'],
                    $dados['tolerancia_percentual'],
                    $dados['ativo'],
                ]);
            }

            header('Location: cadastro_taxas_adquirentes.php?msg=taxa_salva');
            exit;
        }

        if ($acao === 'desativar_taxa') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo_master->prepare("UPDATE fechamento_adquirente_taxas SET ativo = 'N' WHERE id = ? AND empresa_id = ?");
            $stmt->execute([$id, $empresaId]);
            header('Location: cadastro_taxas_adquirentes.php?msg=taxa_desativada');
            exit;
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$taxaEditar = null;
if (isset($_GET['editar'])) {
    $stmtEditar = $pdo_master->prepare("SELECT * FROM fechamento_adquirente_taxas WHERE id = ? AND empresa_id = ?");
    $stmtEditar->execute([(int)$_GET['editar'], $empresaId]);
    $taxaEditar = $stmtEditar->fetch(PDO::FETCH_ASSOC) ?: null;
}

$stmtTaxas = $pdo_master->prepare("
    SELECT *
    FROM fechamento_adquirente_taxas
    WHERE empresa_id = ?
    ORDER BY ativo DESC, adquirente, grupo, tipo_operacao, bandeira, parcelas_de
");
$stmtTaxas->execute([$empresaId]);
$taxas = $stmtTaxas->fetchAll(PDO::FETCH_ASSOC);

$adquirentes = ['GRANITO', 'SIPAG', 'PAGSEGURO'];
$grupos = ['COMERCIAL', 'OUTROS', 'GERAL'];
$tipos = ['DEBITO', 'CREDITO', 'PIX'];

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-warning mb-3">Financeiro</span>
                <h1 class="h3 fw-bold mb-2">Cadastro de Taxas de Adquirentes</h1>
                <p class="text-muted mb-0">Cadastre as taxas acordadas por adquirente, grupo, tipo, bandeira e faixa de parcelas.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_financeiro.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagem): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensagem) ?></div>
<?php endif; ?>

<?php if ($erro): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<section class="mb-4">
    <div class="card shadow-sm">
        <div class="card-header">
            <h2 class="h5 mb-0"><?= $taxaEditar ? 'Editar taxa' : 'Cadastrar taxa' ?></h2>
        </div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="acao" value="salvar_taxa">
                <input type="hidden" name="id" value="<?= (int)($taxaEditar['id'] ?? 0) ?>">

                <div class="col-md-3">
                    <label class="form-label">Adquirente</label>
                    <select name="adquirente" class="form-select" required>
                        <?php foreach ($adquirentes as $adquirente): ?>
                            <option value="<?= $adquirente ?>" <?= (($taxaEditar['adquirente'] ?? '') === $adquirente) ? 'selected' : '' ?>><?= $adquirente ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Grupo</label>
                    <select name="grupo" class="form-select" required>
                        <?php foreach ($grupos as $grupo): ?>
                            <option value="<?= $grupo ?>" <?= (($taxaEditar['grupo'] ?? '') === $grupo) ? 'selected' : '' ?>><?= $grupo ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Tipo</label>
                    <select name="tipo_operacao" class="form-select" required>
                        <?php foreach ($tipos as $tipo): ?>
                            <option value="<?= $tipo ?>" <?= (($taxaEditar['tipo_operacao'] ?? '') === $tipo) ? 'selected' : '' ?>><?= $tipo ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Bandeira</label>
                    <input type="text" name="bandeira" class="form-control" value="<?= htmlspecialchars((string)($taxaEditar['bandeira'] ?? 'TODAS')) ?>" placeholder="TODAS, VISA, MASTERCARD">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Parcelas de</label>
                    <input type="number" name="parcelas_de" min="1" class="form-control" value="<?= (int)($taxaEditar['parcelas_de'] ?? 1) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Parcelas ate</label>
                    <input type="number" name="parcelas_ate" min="1" class="form-control" value="<?= (int)($taxaEditar['parcelas_ate'] ?? 1) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Taxa acordada %</label>
                    <input type="number" step="0.0001" name="taxa_percentual" min="0" class="form-control" value="<?= htmlspecialchars((string)($taxaEditar['taxa_percentual'] ?? '0.0000')) ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Tolerancia %</label>
                    <input type="number" step="0.0001" name="tolerancia_percentual" min="0" class="form-control" value="<?= htmlspecialchars((string)($taxaEditar['tolerancia_percentual'] ?? '0.0500')) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Ativo</label>
                    <select name="ativo" class="form-select">
                        <option value="S" <?= (($taxaEditar['ativo'] ?? 'S') === 'S') ? 'selected' : '' ?>>Sim</option>
                        <option value="N" <?= (($taxaEditar['ativo'] ?? 'S') === 'N') ? 'selected' : '' ?>>Nao</option>
                    </select>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary"><?= $taxaEditar ? 'Salvar alteracao' : 'Cadastrar taxa' ?></button>
                    <?php if ($taxaEditar): ?>
                        <a href="cadastro_taxas_adquirentes.php" class="btn btn-outline-secondary">Cancelar edicao</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</section>

<section>
    <div class="card shadow-sm">
        <div class="card-header">
            <h2 class="h5 mb-0">Taxas cadastradas</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Adquirente</th>
                        <th>Grupo</th>
                        <th>Tipo</th>
                        <th>Bandeira</th>
                        <th>Parcelas</th>
                        <th class="text-end">Taxa %</th>
                        <th class="text-end">Tol. %</th>
                        <th>Status</th>
                        <th class="text-end">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($taxas as $taxa): ?>
                        <tr>
                            <td><?= htmlspecialchars($taxa['adquirente']) ?></td>
                            <td><?= htmlspecialchars($taxa['grupo']) ?></td>
                            <td><?= htmlspecialchars($taxa['tipo_operacao']) ?></td>
                            <td><?= htmlspecialchars($taxa['bandeira']) ?></td>
                            <td><?= (int)$taxa['parcelas_de'] ?> a <?= (int)$taxa['parcelas_ate'] ?></td>
                            <td class="text-end"><?= number_format((float)$taxa['taxa_percentual'], 4, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$taxa['tolerancia_percentual'], 4, ',', '.') ?></td>
                            <td><span class="badge text-bg-<?= $taxa['ativo'] === 'S' ? 'success' : 'secondary' ?>"><?= $taxa['ativo'] === 'S' ? 'Ativa' : 'Inativa' ?></span></td>
                            <td class="text-end">
                                <a href="?editar=<?= (int)$taxa['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                <?php if ($taxa['ativo'] === 'S'): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Desativar esta taxa?')">
                                        <input type="hidden" name="acao" value="desativar_taxa">
                                        <input type="hidden" name="id" value="<?= (int)$taxa['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Desativar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($taxas)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Nenhuma taxa cadastrada.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
