<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

garantirTabelasEnergia($pdo_master);

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$mensagem = '';
$erro = '';

function moedaEnergia(float $valor): string
{
    return number_format($valor, 2, ',', '.');
}

function decimalPostEnergia(string $valor): float
{
    return valorPtEnergia($valor);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    try {
        if ($acao === 'importar_conta') {
            $arquivo = salvarArquivoContaEnergia($_FILES['arquivo_pdf'] ?? []);
            $texto = extrairTextoPdfEnergia($arquivo['absoluto']);
            $dados = parseContaEnergiaCemig($texto);

            $stmt = $pdo_master->prepare("
                INSERT INTO energia_contas (
                    empresa_id, arquivo_nome, arquivo_caminho, logradouro_complemento,
                    unidade_consumidora, referencia, vencimento, valor_total, data_emissao,
                    consumo_kwh, valor_unitario_kw, franquia_minima,
                    custo_disponibilidade, contribuicao_iluminacao,
                    texto_extraido, criado_por
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");
            $stmt->execute([
                $empresaId,
                $arquivo['nome'],
                $arquivo['caminho'],
                $dados['logradouro_complemento'],
                $dados['unidade_consumidora'],
                $dados['referencia'],
                $dados['vencimento'] ?: null,
                $dados['valor_total'],
                $dados['data_emissao'] ?: null,
                $dados['consumo_kwh'],
                0,
                0,
                $dados['custo_disponibilidade'],
                $dados['contribuicao_iluminacao'],
                $texto,
                $usuarioId ?: null,
            ]);

            header('Location: contas.php?ok=importado&id=' . (int)$pdo_master->lastInsertId());
            exit;
        }

        if ($acao === 'salvar_conta') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Conta de energia nao localizada.');
            }

            $custoDisponibilidade = decimalPostEnergia((string)($_POST['custo_disponibilidade'] ?? '0'));
            $franquiaMinima = decimalPostEnergia((string)($_POST['franquia_minima'] ?? '0'));
            $valorUnitarioKw = $franquiaMinima > 0 ? round($custoDisponibilidade / $franquiaMinima, 6) : 0.0;

            $stmt = $pdo_master->prepare("
                UPDATE energia_contas
                SET logradouro_complemento = ?,
                    unidade_consumidora = ?,
                    referencia = ?,
                    vencimento = ?,
                    valor_total = ?,
                    data_emissao = ?,
                    consumo_kwh = ?,
                    valor_unitario_kw = ?,
                    franquia_minima = ?,
                    custo_disponibilidade = ?,
                    contribuicao_iluminacao = ?
                WHERE id = ?
                  AND empresa_id = ?
            ");
            $stmt->execute([
                trim((string)($_POST['logradouro_complemento'] ?? '')),
                trim((string)($_POST['unidade_consumidora'] ?? '')),
                trim((string)($_POST['referencia'] ?? '')),
                $_POST['vencimento'] !== '' ? $_POST['vencimento'] : null,
                decimalPostEnergia((string)($_POST['valor_total'] ?? '0')),
                $_POST['data_emissao'] !== '' ? $_POST['data_emissao'] : null,
                decimalPostEnergia((string)($_POST['consumo_kwh'] ?? '0')),
                $valorUnitarioKw,
                $franquiaMinima,
                $custoDisponibilidade,
                decimalPostEnergia((string)($_POST['contribuicao_iluminacao'] ?? '0')),
                $id,
                $empresaId,
            ]);

            header('Location: contas.php?ok=salvo&id=' . $id);
            exit;
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$editarId = (int)($_GET['editar'] ?? $_GET['id'] ?? 0);
$contaEditar = null;
if ($editarId > 0) {
    $stmt = $pdo_master->prepare("SELECT * FROM energia_contas WHERE id = ? AND empresa_id = ?");
    $stmt->execute([$editarId, $empresaId]);
    $contaEditar = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (isset($_GET['ok'])) {
    $mensagem = $_GET['ok'] === 'importado'
        ? 'Conta de energia importada. Confira os campos extraidos.'
        : 'Conta de energia atualizada.';
}

$fReferencia = trim((string)($_GET['referencia'] ?? ''));
$fEndereco = trim((string)($_GET['endereco'] ?? ''));
$fVencIni = trim((string)($_GET['venc_ini'] ?? ''));
$fVencFim = trim((string)($_GET['venc_fim'] ?? ''));

$where = ['empresa_id = ?'];
$params = [$empresaId];
if ($fReferencia !== '') {
    $where[] = 'referencia LIKE ?';
    $params[] = '%' . $fReferencia . '%';
}
if ($fEndereco !== '') {
    $where[] = 'logradouro_complemento LIKE ?';
    $params[] = '%' . $fEndereco . '%';
}
if ($fVencIni !== '') {
    $where[] = 'vencimento >= ?';
    $params[] = $fVencIni;
}
if ($fVencFim !== '') {
    $where[] = 'vencimento <= ?';
    $params[] = $fVencFim;
}

$stmt = $pdo_master->prepare("
    SELECT *
    FROM energia_contas
    WHERE " . implode(' AND ', $where) . "
    ORDER BY COALESCE(vencimento, '9999-12-31') DESC, id DESC
    LIMIT 200
");
$stmt->execute($params);
$contas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalValor = 0.0;
$totalConsumo = 0.0;
foreach ($contas as $conta) {
    $totalValor += (float)$conta['valor_total'];
    $totalConsumo += (float)$conta['consumo_kwh'];
}

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-2">Energia</span>
                <h1 class="h4 fw-bold mb-1">Contas de Energia</h1>
                <p class="text-muted mb-0">Importe contas da CEMIG e confira os campos usados no controle de energia.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_energia.php" class="btn btn-outline-secondary">Voltar</a>
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

<section class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3">Importar conta</h2>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="acao" value="importar_conta">
                    <label class="form-label small fw-semibold">Arquivo PDF da conta</label>
                    <input type="file" name="arquivo_pdf" class="form-control" accept="application/pdf,.pdf" required>
                    <button class="btn btn-primary w-100 mt-3">Importar PDF</button>
                </form>
                <p class="text-muted small mt-3 mb-0">O sistema extrai os campos principais e permite ajuste manual antes do uso gerencial.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3"><?= $contaEditar ? 'Conferir conta importada' : 'Campos gravados da conta' ?></h2>
                <?php if ($contaEditar): ?>
                    <form method="post" class="row g-3">
                        <input type="hidden" name="acao" value="salvar_conta">
                        <input type="hidden" name="id" value="<?= (int)$contaEditar['id'] ?>">
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Logradouro/numero/complemento</label>
                            <input type="text" name="logradouro_complemento" class="form-control" value="<?= htmlspecialchars((string)$contaEditar['logradouro_complemento']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Unidade consumidora</label>
                            <input type="text" name="unidade_consumidora" class="form-control" value="<?= htmlspecialchars((string)$contaEditar['unidade_consumidora']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Referencia</label>
                            <input type="text" name="referencia" class="form-control" value="<?= htmlspecialchars((string)$contaEditar['referencia']) ?>" placeholder="JUN/2026">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Vencimento</label>
                            <input type="date" name="vencimento" class="form-control" value="<?= htmlspecialchars((string)$contaEditar['vencimento']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Valor total a pagar</label>
                            <input type="text" name="valor_total" inputmode="decimal" class="form-control" value="<?= moedaEnergia((float)$contaEditar['valor_total']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Data de emissao</label>
                            <input type="date" name="data_emissao" class="form-control" value="<?= htmlspecialchars((string)$contaEditar['data_emissao']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Consumo kWh</label>
                            <input type="text" name="consumo_kwh" inputmode="decimal" class="form-control" value="<?= number_format((float)$contaEditar['consumo_kwh'], 3, ',', '.') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Valor unitario do kW</label>
                            <input type="text" name="valor_unitario_kw" id="valor_unitario_kw" inputmode="decimal" class="form-control" value="<?= number_format((float)($contaEditar['valor_unitario_kw'] ?? 0), 6, ',', '.') ?>" readonly>
                            <div class="form-text">Calculado por custo de disponibilidade / franquia minima.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Franquia minima</label>
                            <input type="text" name="franquia_minima" id="franquia_minima" inputmode="decimal" class="form-control" value="<?= number_format((float)($contaEditar['franquia_minima'] ?? 0), 3, ',', '.') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Custo de Disponibilidade</label>
                            <input type="text" name="custo_disponibilidade" id="custo_disponibilidade" inputmode="decimal" class="form-control" value="<?= moedaEnergia((float)$contaEditar['custo_disponibilidade']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contrib Ilum Publica Municipal</label>
                            <input type="text" name="contribuicao_iluminacao" inputmode="decimal" class="form-control" value="<?= moedaEnergia((float)$contaEditar['contribuicao_iluminacao']) ?>">
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button class="btn btn-success">Salvar conferencia</button>
                            <?php if (!empty($contaEditar['arquivo_caminho'])): ?>
                                <a class="btn btn-outline-primary" target="_blank" href="../../<?= htmlspecialchars((string)$contaEditar['arquivo_caminho']) ?>">Abrir PDF</a>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info mb-0">Importe uma conta ou clique em conferir para revisar os campos extraidos.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<section class="mb-3">
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Referencia</label>
                    <input type="text" name="referencia" class="form-control" value="<?= htmlspecialchars($fReferencia) ?>" placeholder="JUN/2026">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Endereco</label>
                    <input type="text" name="endereco" class="form-control" value="<?= htmlspecialchars($fEndereco) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Venc. inicial</label>
                    <input type="date" name="venc_ini" class="form-control" value="<?= htmlspecialchars($fVencIni) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Venc. final</label>
                    <input type="date" name="venc_fim" class="form-control" value="<?= htmlspecialchars($fVencFim) ?>">
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary">Filtrar</button>
                </div>
            </form>
        </div>
    </div>
</section>

<section>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <div class="badge text-bg-light border p-2">Registros: <?= count($contas) ?></div>
        <div class="badge text-bg-light border p-2">Valor total: R$ <?= moedaEnergia($totalValor) ?></div>
        <div class="badge text-bg-light border p-2">Consumo: <?= number_format($totalConsumo, 3, ',', '.') ?> kWh</div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Referencia</th>
                        <th>Vencimento</th>
                        <th>Endereco</th>
                        <th>Unidade</th>
                        <th class="text-end">Consumo kWh</th>
                        <th class="text-end">Valor kW</th>
                        <th class="text-end">Franquia</th>
                        <th class="text-end">Valor</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contas as $conta): ?>
                        <tr>
                            <td><?= (int)$conta['id'] ?></td>
                            <td><?= htmlspecialchars((string)$conta['referencia']) ?></td>
                            <td><?= $conta['vencimento'] ? date('d/m/Y', strtotime((string)$conta['vencimento'])) : '-' ?></td>
                            <td><?= htmlspecialchars((string)$conta['logradouro_complemento']) ?></td>
                            <td><?= htmlspecialchars((string)$conta['unidade_consumidora']) ?></td>
                            <td class="text-end"><?= number_format((float)$conta['consumo_kwh'], 3, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)($conta['valor_unitario_kw'] ?? 0), 6, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)($conta['franquia_minima'] ?? 0), 3, ',', '.') ?></td>
                            <td class="text-end">R$ <?= moedaEnergia((float)$conta['valor_total']) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="contas.php?editar=<?= (int)$conta['id'] ?>">Conferir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($contas)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">Nenhuma conta de energia encontrada.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const custo = document.getElementById('custo_disponibilidade');
    const franquia = document.getElementById('franquia_minima');
    const valorKw = document.getElementById('valor_unitario_kw');
    const parsePt = (valor) => {
        valor = String(valor || '').replace(/[^\d,.-]/g, '');
        if (valor.includes(',')) {
            valor = valor.replace(/\./g, '').replace(',', '.');
        }
        return Number.parseFloat(valor) || 0;
    };
    const formatPt = (valor) => valor.toLocaleString('pt-BR', {
        minimumFractionDigits: 6,
        maximumFractionDigits: 6
    });
    const atualizarValorKw = () => {
        if (!custo || !franquia || !valorKw) {
            return;
        }
        const franquiaValor = parsePt(franquia.value);
        valorKw.value = franquiaValor > 0 ? formatPt(parsePt(custo.value) / franquiaValor) : formatPt(0);
    };
    if (custo && franquia && valorKw) {
        custo.addEventListener('input', atualizarValorKw);
        franquia.addEventListener('input', atualizarValorKw);
    }
});
</script>

<?php require '../../layout/footer.php'; ?>
