<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once 'estoque_minimo_lib.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$podeEditar = in_array($_SESSION['nivel'] ?? '', ['MASTER', 'ADMIN'], true);
$erro = '';

garantirTabelaEstoqueMinimo($pdo_master);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$podeEditar) {
        http_response_code(403);
        $erro = 'Seu perfil pode consultar os alertas, mas nao pode alterar os limites.';
    } else {
        $minimos = is_array($_POST['minimos'] ?? null) ? $_POST['minimos'] : [];

        try {
            $tipos = $pdo_master->query('SELECT id FROM tesouraria_tipos_dinheiro')->fetchAll(PDO::FETCH_COLUMN);
            $stmt = $pdo_master->prepare("
                INSERT INTO tesouraria_estoque_minimos
                    (empresa_id, tipo_dinheiro_id, quantidade_minima, atualizado_por)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    quantidade_minima = VALUES(quantidade_minima),
                    atualizado_por = VALUES(atualizado_por),
                    atualizado_em = CURRENT_TIMESTAMP
            ");

            $pdo_master->beginTransaction();
            foreach ($tipos as $tipoId) {
                $valorInformado = trim((string)($minimos[$tipoId] ?? '0'));
                if ($valorInformado === '' || !ctype_digit($valorInformado)) {
                    throw new RuntimeException('Informe quantidades minimas inteiras e maiores ou iguais a zero.');
                }
                $stmt->execute([$empresaId, (int)$tipoId, (int)$valorInformado, $usuarioId ?: null]);
            }
            $pdo_master->commit();
            header('Location: estoque_minimo.php?salvo=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo_master->inTransaction()) {
                $pdo_master->rollBack();
            }
            $erro = $e->getMessage();
        }
    }
}

$itens = listarEstoqueMinimo($pdo_master, $empresaId);
$resumo = resumirEstoqueMinimo($itens);

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <div>
            <div class="text-primary small mb-1">Tesouraria / Inventario</div>
            <h1 class="h3 fw-bold mb-1">Estoque minimo de cedulas e moedas</h1>
            <p class="text-muted mb-0">Defina o nivel desejado para a empresa atual e acompanhe a necessidade de reposicao.</p>
        </div>
        <a href="menu_tesouraria.php" class="btn btn-outline-secondary">Voltar para tesouraria</a>
    </div>
</section>

<?php if (isset($_GET['salvo'])): ?>
    <div class="alert alert-success">Quantidades minimas atualizadas com sucesso.</div>
<?php endif; ?>
<?php if ($erro !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<section class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">Denominacoes configuradas</div>
                <div class="h3 mb-0"><?= (int)$resumo['configurados'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 shadow-sm <?= $resumo['alertas'] > 0 ? 'border-danger' : 'border-success' ?>">
            <div class="card-body">
                <div class="text-muted small mb-1">Precisam de reposicao</div>
                <div class="h3 mb-0 <?= $resumo['alertas'] > 0 ? 'text-danger' : 'text-success' ?>"><?= (int)$resumo['alertas'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <div class="text-muted small mb-1">Valor estimado para reposicao</div>
                <div class="h3 mb-0">R$ <?= number_format((float)$resumo['valor_reposicao'], 2, ',', '.') ?></div>
            </div>
        </div>
    </div>
</section>

<section class="card shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h5 fw-bold mb-0">Limites por denominacao</h2>
    </div>
    <div class="card-body p-0">
        <form method="post">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tipo</th>
                            <th>Denominacao</th>
                            <th class="text-end">Quantidade atual</th>
                            <th style="width: 190px;">Minimo desejado</th>
                            <th class="text-end">Quantidade a repor</th>
                            <th>Situacao</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($itens as $item): ?>
                        <?php
                        $atual = (int)$item['quantidade_atual'];
                        $minimo = max(0, (int)$item['quantidade_minima']);
                        $repor = max(0, $minimo - $atual);
                        $configurado = $minimo > 0;
                        ?>
                        <tr class="<?= $configurado && $repor > 0 ? 'table-danger' : '' ?>">
                            <td><span class="badge text-bg-secondary"><?= htmlspecialchars((string)$item['tipo']) ?></span></td>
                            <td>
                                <strong><?= htmlspecialchars((string)$item['descricao']) ?></strong>
                                <div class="text-muted small">R$ <?= number_format((float)$item['valor'], 2, ',', '.') ?></div>
                            </td>
                            <td class="text-end fw-semibold"><?= $atual ?></td>
                            <td>
                                <?php if ($podeEditar): ?>
                                    <input type="number" min="0" step="1" inputmode="numeric"
                                           name="minimos[<?= (int)$item['id'] ?>]"
                                           value="<?= $minimo ?>"
                                           class="form-control text-end"
                                           aria-label="Minimo para <?= htmlspecialchars((string)$item['descricao']) ?>">
                                <?php else: ?>
                                    <span class="d-block text-end"><?= $minimo ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold"><?= $repor ?></td>
                            <td>
                                <?php if (!$configurado): ?>
                                    <span class="badge text-bg-secondary">Nao configurado</span>
                                <?php elseif ($repor > 0): ?>
                                    <span class="badge text-bg-danger">Repor</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">Adequado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($podeEditar): ?>
                <div class="d-flex justify-content-end border-top p-3">
                    <button type="submit" class="btn btn-primary">Salvar quantidades minimas</button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
