<?php
require '../../config/auth.php';
require '../../config/conexao.php';

if (!temNivel('MASTER')) {
    renderizarAcessoNegadoModulo('Apenas usuarios MASTER podem desvincular conciliacoes.');
}

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$mensagemOk = '';
$mensagemErro = '';

function garantirLogDesvinculoRecebimentos(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conciliacao_recebimentos_desvinculos_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            recebimento_id INT NOT NULL,
            crcontador INT NOT NULL,
            usuario_id INT NOT NULL,
            dados_recebivel LONGTEXT NULL,
            dados_cr001 LONGTEXT NULL,
            motivo VARCHAR(255) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_desvinculo_empresa_data (empresa_id, criado_em),
            INDEX idx_desvinculo_recebimento (recebimento_id),
            INDEX idx_desvinculo_crcontador (crcontador)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

garantirLogDesvinculoRecebimentos($pdo_master);

function dataHoraDesvinculo($valor): string
{
    return $valor ? date('d/m/Y H:i', strtotime($valor)) : '-';
}

function moedaDesvinculo($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function classificarTipoMatchDesvinculo(array $vinculo): string
{
    $valorRecebivel = abs((float)($vinculo['valor_bruto'] ?? 0));
    $valorCr = abs((float)($vinculo['VLRPARCELA'] ?? 0));

    if (abs($valorRecebivel - $valorCr) >= 0.01) {
        return 'manual';
    }

    $dataRecebivel = !empty($vinculo['data_venda']) ? strtotime($vinculo['data_venda']) : false;
    $dataLancamento = !empty($vinculo['DTLANC']) ? strtotime($vinculo['DTLANC']) : false;
    $dataMovimento = !empty($vinculo['DTEMISSAO']) ? strtotime($vinculo['DTEMISSAO']) : false;
    $cmRecebivel = (string)($vinculo['cm_recebivel'] ?? '');
    $cmCr = (string)($vinculo['cm_cr001'] ?? '');

    if ($dataRecebivel && $dataLancamento && date('Y-m-d H:i', $dataRecebivel) === date('Y-m-d H:i', $dataLancamento)) {
        return 'exato';
    }

    if ($dataRecebivel && $dataMovimento && $cmRecebivel === $cmCr && date('Y-m-d', $dataRecebivel) === date('Y-m-d', $dataMovimento)) {
        return 'movimento';
    }

    if ($dataRecebivel && $dataLancamento && abs(($dataLancamento - $dataRecebivel) / 60) <= 5) {
        return 'aproximado';
    }

    return 'manual';
}

function labelTipoMatchDesvinculo(string $tipo): string
{
    $labels = [
        'exato' => 'Seguro (exato)',
        'movimento' => 'Data movimento',
        'aproximado' => 'Aproximado',
        'manual' => 'Manual/indefinido',
    ];

    return $labels[$tipo] ?? $tipo;
}

function queryDesvinculo(array $extra = []): string
{
    $params = $_GET;
    foreach ($extra as $chave => $valor) {
        if ($valor === null) {
            unset($params[$chave]);
        } else {
            $params[$chave] = $valor;
        }
    }

    return http_build_query($params);
}

$dataIni = trim($_GET['data_ini'] ?? date('Y-m-d'));
$dataFim = trim($_GET['data_fim'] ?? date('Y-m-d'));
$recebimentoFiltro = trim($_GET['recebimento_id'] ?? '');
$crFiltro = trim($_GET['crcontador'] ?? '');
$tipoMatchFiltro = trim($_GET['tipo_match'] ?? '');
$tiposMatchValidos = ['exato', 'movimento', 'aproximado', 'manual'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'desvincular') {
    $recebimentoId = (int)($_POST['recebimento_id'] ?? 0);
    $crcontador = (int)($_POST['crcontador'] ?? 0);
    $motivo = trim($_POST['motivo'] ?? '');

    if ($recebimentoId <= 0 || $crcontador <= 0) {
        $mensagemErro = 'Informe um vinculo valido para desvincular.';
    } else {
        try {
            $pdo_master->beginTransaction();

            $stmtRecebivel = $pdo_master->prepare("
                SELECT id, empresa_id, data_venda, valor_bruto, CMCONTADOR, pagador, CRCONTADOR
                FROM armazem_conciliacao_recebimentos
                WHERE id = ?
                  AND empresa_id = ?
                FOR UPDATE
            ");
            $stmtRecebivel->execute([$recebimentoId, $empresaId]);
            $recebivel = $stmtRecebivel->fetch(PDO::FETCH_ASSOC);

            $stmtCr = $pdo_master->prepare("
                SELECT CRCONTADOR, EMPRESA, DTLANC, DTEMISSAO, VLRPARCELA, CMCONTADOR, STATUS, recebimento_id, excluido_firebird
                FROM armazem_cr001
                WHERE CRCONTADOR = ?
                  AND EMPRESA = ?
                FOR UPDATE
            ");
            $stmtCr->execute([$crcontador, $empresaId]);
            $cr = $stmtCr->fetch(PDO::FETCH_ASSOC);

            if (!$recebivel || !$cr) {
                throw new RuntimeException('Vinculo nao encontrado para esta empresa.');
            }

            $recebivelApontaCr = (int)($recebivel['CRCONTADOR'] ?? 0) === $crcontador;
            $crApontaRecebivel = (int)($cr['recebimento_id'] ?? 0) === $recebimentoId;

            if (!$recebivelApontaCr && !$crApontaRecebivel) {
                throw new RuntimeException('Recebivel e CR001 informados nao estao vinculados entre si.');
            }

            if ($recebivelApontaCr) {
                $stmt = $pdo_master->prepare("
                    UPDATE armazem_conciliacao_recebimentos
                    SET CRCONTADOR = NULL
                    WHERE id = ?
                      AND empresa_id = ?
                      AND CRCONTADOR = ?
                ");
                $stmt->execute([$recebimentoId, $empresaId, $crcontador]);
            }

            if ($crApontaRecebivel) {
                $stmt = $pdo_master->prepare("
                    UPDATE armazem_cr001
                    SET recebimento_id = NULL
                    WHERE CRCONTADOR = ?
                      AND EMPRESA = ?
                      AND recebimento_id = ?
                ");
                $stmt->execute([$crcontador, $empresaId, $recebimentoId]);
            }

            $stmtLog = $pdo_master->prepare("
                INSERT INTO conciliacao_recebimentos_desvinculos_log
                    (empresa_id, recebimento_id, crcontador, usuario_id, dados_recebivel, dados_cr001, motivo)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtLog->execute([
                $empresaId,
                $recebimentoId,
                $crcontador,
                $usuarioId,
                json_encode($recebivel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($cr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $motivo !== '' ? $motivo : null,
            ]);

            $pdo_master->commit();

            $query = queryDesvinculo(['ok' => '1']);
            header('Location: desvincular_recebimentos.php' . ($query ? '?' . $query : ''));
            exit;
        } catch (Throwable $e) {
            if ($pdo_master->inTransaction()) {
                $pdo_master->rollBack();
            }
            $mensagemErro = $e->getMessage();
        }
    }
}

if (($_GET['ok'] ?? '') === '1') {
    $mensagemOk = 'Vinculo desfeito com sucesso.';
}

$where = [
    'r.empresa_id = ?',
    '(r.CRCONTADOR IS NOT NULL OR c.recebimento_id IS NOT NULL)',
];
$params = [$empresaId];

if ($dataIni !== '') {
    $where[] = 'DATE(r.data_venda) >= ?';
    $params[] = $dataIni;
}

if ($dataFim !== '') {
    $where[] = 'DATE(r.data_venda) <= ?';
    $params[] = $dataFim;
}

if ($recebimentoFiltro !== '' && ctype_digit($recebimentoFiltro)) {
    $where[] = 'r.id = ?';
    $params[] = (int)$recebimentoFiltro;
}

if ($crFiltro !== '' && ctype_digit($crFiltro)) {
    $where[] = '(r.CRCONTADOR = ? OR c.CRCONTADOR = ?)';
    $params[] = (int)$crFiltro;
    $params[] = (int)$crFiltro;
}

$whereSql = implode("\n      AND ", $where);

$stmt = $pdo_master->prepare("
    SELECT
        r.id AS recebimento_id,
        r.data_venda,
        r.valor_bruto,
        r.CMCONTADOR AS cm_recebivel,
        r.pagador,
        r.CRCONTADOR AS cr_recebivel,
        c.CRCONTADOR,
        c.DTLANC,
        c.DTEMISSAO,
        c.VLRPARCELA,
        c.CMCONTADOR AS cm_cr001,
        c.STATUS,
        c.recebimento_id AS recebimento_id_cr001,
        c.excluido_firebird
    FROM armazem_conciliacao_recebimentos r
    LEFT JOIN armazem_cr001 c
        ON c.EMPRESA = r.empresa_id
       AND (
            c.CRCONTADOR = r.CRCONTADOR
            OR c.recebimento_id = r.id
       )
    WHERE {$whereSql}
    ORDER BY r.data_venda DESC, r.id DESC, c.CRCONTADOR DESC
    LIMIT 300
");
$stmt->execute($params);
$vinculos = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($vinculos as &$vinculo) {
    $vinculo['tipo_match'] = classificarTipoMatchDesvinculo($vinculo);
}
unset($vinculo);

$contagemTiposMatch = array_fill_keys($tiposMatchValidos, 0);
foreach ($vinculos as $vinculo) {
    $tipoContagem = $vinculo['tipo_match'] ?? 'manual';
    if (!isset($contagemTiposMatch[$tipoContagem])) {
        $contagemTiposMatch[$tipoContagem] = 0;
    }
    $contagemTiposMatch[$tipoContagem]++;
}

if (in_array($tipoMatchFiltro, $tiposMatchValidos, true)) {
    $vinculos = array_values(array_filter($vinculos, static function (array $vinculo) use ($tipoMatchFiltro): bool {
        return ($vinculo['tipo_match'] ?? '') === $tipoMatchFiltro;
    }));
}

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-danger mb-3">MASTER</span>
                <h1 class="h3 fw-bold mb-2">Desvincular Match de Recebimentos</h1>
                <p class="text-muted mb-0">Use esta rotina apenas para desfazer conciliacoes feitas por engano.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_recebimentos.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagemOk): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensagemOk) ?></div>
<?php endif; ?>

<?php if ($mensagemErro): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div>
<?php endif; ?>

<section class="mb-3">
    <form method="GET" class="bg-white border rounded-2 shadow-sm p-3">
        <div class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Data inicial</label>
                <input type="date" name="data_ini" class="form-control" value="<?= htmlspecialchars($dataIni) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Data final</label>
                <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($dataFim) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Recebivel ID</label>
                <input type="number" name="recebimento_id" class="form-control" value="<?= htmlspecialchars($recebimentoFiltro) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">CRCONTADOR</label>
                <input type="number" name="crcontador" class="form-control" value="<?= htmlspecialchars($crFiltro) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Tipo de match</label>
                <select name="tipo_match" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($tiposMatchValidos as $tipoMatchOpcao): ?>
                        <option value="<?= htmlspecialchars($tipoMatchOpcao) ?>" <?= $tipoMatchFiltro === $tipoMatchOpcao ? 'selected' : '' ?>>
                            <?= htmlspecialchars(labelTipoMatchDesvinculo($tipoMatchOpcao)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Seguro (exato) e Data movimento sao filtros diferentes.</div>
            </div>
            <div class="col-md-2 d-flex gap-2 justify-content-end">
                <a href="desvincular_recebimentos.php" class="btn btn-outline-secondary">Limpar</a>
                <button type="submit" class="btn btn-primary">Filtrar</button>
            </div>
        </div>
    </form>
</section>

<section class="mb-3">
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($tiposMatchValidos as $tipoMatchResumo): ?>
            <span class="badge text-bg-light border text-dark">
                <?= htmlspecialchars(labelTipoMatchDesvinculo($tipoMatchResumo)) ?>:
                <?= (int)($contagemTiposMatch[$tipoMatchResumo] ?? 0) ?>
            </span>
        <?php endforeach; ?>
    </div>
</section>

<section>
    <div class="bg-white border rounded-2 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-primary">
                    <tr>
                        <th>Recebivel</th>
                        <th>Data</th>
                        <th>Pagador</th>
                        <th class="text-end">Valor</th>
                        <th>CM Rec.</th>
                        <th>CR001</th>
                        <th>Data CR</th>
                        <th class="text-end">Valor CR</th>
                        <th>CM CR</th>
                        <th>Tipo</th>
                        <th>Status</th>
                        <th>Acao</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vinculos as $vinculo): ?>
                        <?php
                            $crcontador = (int)($vinculo['CRCONTADOR'] ?: $vinculo['cr_recebivel']);
                            $recebimentoId = (int)$vinculo['recebimento_id'];
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= $recebimentoId ?></td>
                            <td><?= dataHoraDesvinculo($vinculo['data_venda']) ?></td>
                            <td><?= htmlspecialchars($vinculo['pagador'] ?: '-') ?></td>
                            <td class="text-end"><?= moedaDesvinculo($vinculo['valor_bruto']) ?></td>
                            <td><?= htmlspecialchars((string)$vinculo['cm_recebivel']) ?></td>
                            <td class="fw-semibold"><?= $crcontador ?: '-' ?></td>
                            <td><?= dataHoraDesvinculo($vinculo['DTLANC']) ?></td>
                            <td class="text-end"><?= $vinculo['VLRPARCELA'] !== null ? moedaDesvinculo($vinculo['VLRPARCELA']) : '-' ?></td>
                            <td><?= htmlspecialchars((string)($vinculo['cm_cr001'] ?? '-')) ?></td>
                            <td>
                                <span class="badge text-bg-info"><?= htmlspecialchars(labelTipoMatchDesvinculo($vinculo['tipo_match'] ?? 'manual')) ?></span>
                            </td>
                            <td>
                                <span class="badge text-bg-secondary"><?= htmlspecialchars($vinculo['STATUS'] ?: '-') ?></span>
                                <?php if (($vinculo['excluido_firebird'] ?? 'N') === 'S'): ?>
                                    <span class="badge text-bg-dark">Excluido</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($crcontador > 0): ?>
                                    <form method="POST" class="d-flex gap-2 align-items-center js-form-desvincular">
                                        <input type="hidden" name="acao" value="desvincular">
                                        <input type="hidden" name="recebimento_id" value="<?= $recebimentoId ?>">
                                        <input type="hidden" name="crcontador" value="<?= $crcontador ?>">
                                        <input type="text" name="motivo" class="form-control form-control-sm" placeholder="Motivo" style="min-width: 160px;">
                                        <button type="submit" class="btn btn-sm btn-danger">Desvincular</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">Sem CR001</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($vinculos)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted py-4">Nenhum vinculo encontrado.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-form-desvincular').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!confirm('Confirmar desvinculo deste match? Esta acao sera registrada em log.')) {
                event.preventDefault();
            }
        });
    });
});
</script>

<?php require '../../layout/footer.php'; ?>
