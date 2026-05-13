<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

function garantirTabelaSaldosContas(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_contas_saldos (
            empresa_id INT NOT NULL,
            cbcontador INT NOT NULL,
            data_saldo DATE NOT NULL,
            valor_saldo DECIMAL(15,4) NOT NULL DEFAULT 0,
            atualizado_por INT NULL,
            atualizado_em DATETIME NULL,
            PRIMARY KEY (empresa_id, cbcontador),
            INDEX idx_fin_contas_saldos_data (empresa_id, data_saldo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

garantirTabelaSaldosContas($pdo_master);

function moedaContasBanco($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function dataContasBanco($valor): string
{
    return $valor ? date('d/m/Y', strtotime($valor)) : '';
}

function normalizarDecimalConta(string $valor): float
{
    $valor = trim($valor);
    if ($valor === '') {
        return 0.0;
    }

    $valor = str_replace('.', '', $valor);
    $valor = str_replace(',', '.', $valor);
    return is_numeric($valor) ? (float)$valor : 0.0;
}

$contaSelecionada = (int)($_GET['cbcontador'] ?? 0);
$dataIni = trim($_GET['data_ini'] ?? '');
$dataFim = trim($_GET['data_fim'] ?? '');
$tipoes = trim($_GET['tipoes'] ?? '');
$historico = trim($_GET['historico'] ?? '');
$documento = trim($_GET['documento'] ?? '');
$dc = strtoupper(trim($_GET['dc'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_saldo') {
    $cbcontadorPost = (int)($_POST['cbcontador'] ?? 0);
    $dataSaldo = trim($_POST['data_saldo'] ?? '');
    $valorSaldo = normalizarDecimalConta((string)($_POST['valor_saldo'] ?? '0'));

    if ($cbcontadorPost > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataSaldo)) {
        $stmtSaldo = $pdo_master->prepare("
            INSERT INTO financeiro_contas_saldos (
                empresa_id, cbcontador, data_saldo, valor_saldo, atualizado_por, atualizado_em
            ) VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                data_saldo = VALUES(data_saldo),
                valor_saldo = VALUES(valor_saldo),
                atualizado_por = VALUES(atualizado_por),
                atualizado_em = NOW()
        ");
        $stmtSaldo->execute([$empresaId, $cbcontadorPost, $dataSaldo, $valorSaldo, $usuarioId]);
    }

    $query = $_GET ? '?' . http_build_query($_GET) : '';
    header('Location: contas.php' . $query);
    exit;
}

$stmtContas = $pdo_master->prepare("
    SELECT CBCONTADOR, TITULAR, DESCABREV
    FROM armazem_bnc002
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY CBCONTADOR
");
$stmtContas->execute([$empresaId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

if (empty($contas)) {
    $stmtContasFallback = $pdo_master->prepare("
        SELECT DISTINCT
            CBCONTADOR,
            CONCAT('Conta ', CBCONTADOR) AS TITULAR,
            CONCAT('Conta ', CBCONTADOR) AS DESCABREV
        FROM armazem_bnc001
        WHERE EMPRESA = ?
          AND CBCONTADOR IS NOT NULL
          AND COALESCE(deletado, 'N') <> 'S'
        ORDER BY CBCONTADOR
    ");
    $stmtContasFallback->execute([$empresaId]);
    $contas = $stmtContasFallback->fetchAll(PDO::FETCH_ASSOC);
}

if ($contaSelecionada === 0 && count($contas) === 1) {
    $contaSelecionada = (int)$contas[0]['CBCONTADOR'];
}

$stmtTipos = $pdo_master->prepare("
    SELECT ESCONTADOR, DESCES
    FROM armazem_bnc005
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY DESCES
");
$stmtTipos->execute([$empresaId]);
$tipos = $stmtTipos->fetchAll(PDO::FETCH_ASSOC);

if (empty($tipos)) {
    $stmtTiposFallback = $pdo_master->prepare("
        SELECT DISTINCT
            TIPOES AS ESCONTADOR,
            CONCAT('Tipo ', TIPOES) AS DESCES
        FROM armazem_bnc001
        WHERE EMPRESA = ?
          AND TIPOES IS NOT NULL
          AND COALESCE(deletado, 'N') <> 'S'
        ORDER BY TIPOES
    ");
    $stmtTiposFallback->execute([$empresaId]);
    $tipos = $stmtTiposFallback->fetchAll(PDO::FETCH_ASSOC);
}

$saldoInicial = null;
if ($contaSelecionada > 0) {
    $stmtSaldoAtual = $pdo_master->prepare("
        SELECT *
        FROM financeiro_contas_saldos
        WHERE empresa_id = ?
          AND cbcontador = ?
        LIMIT 1
    ");
    $stmtSaldoAtual->execute([$empresaId, $contaSelecionada]);
    $saldoInicial = $stmtSaldoAtual->fetch(PDO::FETCH_ASSOC) ?: null;
}

$where = [
    'b.EMPRESA = ?',
    "COALESCE(b.deletado, 'N') <> 'S'",
];
$params = [$empresaId];

if ($contaSelecionada > 0) {
    $where[] = 'b.CBCONTADOR = ?';
    $params[] = $contaSelecionada;
}

if ($dataIni !== '') {
    $where[] = 'DATE(b.DTMOV) >= ?';
    $params[] = $dataIni;
}

if ($dataFim !== '') {
    $where[] = 'DATE(b.DTMOV) <= ?';
    $params[] = $dataFim;
}

if ($tipoes !== '' && ctype_digit($tipoes)) {
    $where[] = 'b.TIPOES = ?';
    $params[] = (int)$tipoes;
}

if ($historico !== '') {
    $where[] = 'b.HISTMOV LIKE ?';
    $params[] = '%' . $historico . '%';
}

if ($documento !== '') {
    $where[] = "(b.NUMDOC LIKE ? OR b.NUMDOCORIGEM LIKE ? OR b.NUMCONTROLE LIKE ?)";
    $likeDoc = '%' . $documento . '%';
    $params[] = $likeDoc;
    $params[] = $likeDoc;
    $params[] = $likeDoc;
}

if (in_array($dc, ['C', 'D'], true)) {
    $where[] = 'b.TIPOMOV = ?';
    $params[] = $dc;
}

$whereSql = implode("\n      AND ", $where);

$stmtResumo = $pdo_master->prepare("
    SELECT
        COUNT(*) AS qtd,
        COALESCE(SUM(CASE WHEN b.TIPOMOV = 'C' THEN ABS(b.VALORMOV) ELSE 0 END), 0) AS total_creditos,
        COALESCE(SUM(CASE WHEN b.TIPOMOV = 'D' THEN ABS(b.VALORMOV) ELSE 0 END), 0) AS total_debitos
    FROM armazem_bnc001 b
    WHERE {$whereSql}
");
$stmtResumo->execute($params);
$resumo = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total_creditos' => 0, 'total_debitos' => 0];

$saldoBase = 0.0;
$saldoBaseData = null;

if ($saldoInicial) {
    $saldoBase = (float)$saldoInicial['valor_saldo'];
    $saldoBaseData = $saldoInicial['data_saldo'];
}

if ($contaSelecionada > 0 && $saldoInicial) {
    $paramsAntes = [$empresaId, $contaSelecionada, $saldoBaseData];
    $filtroAntes = "b.EMPRESA = ? AND b.CBCONTADOR = ? AND COALESCE(b.deletado, 'N') <> 'S' AND DATE(b.DTMOV) > ?";

    if ($dataIni !== '') {
        $filtroAntes .= " AND DATE(b.DTMOV) < ?";
        $paramsAntes[] = $dataIni;
    }

    $stmtAntes = $pdo_master->prepare("
        SELECT COALESCE(SUM(CASE WHEN b.TIPOMOV = 'C' THEN ABS(b.VALORMOV) ELSE -ABS(b.VALORMOV) END), 0)
        FROM armazem_bnc001 b
        WHERE {$filtroAntes}
    ");
    $stmtAntes->execute($paramsAntes);
    $saldoBase += (float)$stmtAntes->fetchColumn();
}

$saldoResumo = $saldoBase + (float)$resumo['total_creditos'] - (float)$resumo['total_debitos'];

$stmt = $pdo_master->prepare("
    SELECT
        b.MOVCONTADOR,
        b.DTMOV,
        b.TIPOES,
        COALESCE(t.DESCES, CONCAT('Tipo ', b.TIPOES)) AS tipo_nome,
        b.HISTMOV,
        b.NUMDOC,
        b.NUMDOCORIGEM,
        b.NUMCONTROLE,
        b.TIPOMOV,
        b.VALORMOV,
        b.CBCONTADOR,
        COALESCE(c.TITULAR, CONCAT('Conta ', b.CBCONTADOR)) AS conta_nome
    FROM armazem_bnc001 b
    LEFT JOIN armazem_bnc005 t
        ON t.EMPRESA = b.EMPRESA
       AND t.ESCONTADOR = b.TIPOES
    LEFT JOIN armazem_bnc002 c
        ON c.EMPRESA = b.EMPRESA
       AND c.CBCONTADOR = b.CBCONTADOR
    WHERE {$whereSql}
    ORDER BY b.DTMOV ASC, b.MOVCONTADOR ASC
    LIMIT 1500
");
$stmt->execute($params);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

$saldoCorrente = $saldoBase;
foreach ($registros as &$registro) {
    $valor = abs((float)$registro['VALORMOV']);
    $saldoCorrente += strtoupper((string)$registro['TIPOMOV']) === 'C' ? $valor : -$valor;
    $registro['saldo_calculado'] = $saldoCorrente;
}
unset($registro);

function queryContasBanco(array $extra = []): string
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

require '../../layout/header.php';
?>

<style>
    .financeiro-grid { font-size: .9rem; }
    .financeiro-grid th {
        white-space: nowrap;
        font-size: .78rem;
        text-transform: uppercase;
        vertical-align: middle;
    }
    .financeiro-grid td { vertical-align: middle; }
    .financeiro-grid .col-date { width: 92px; white-space: nowrap; }
    .financeiro-grid .col-type { width: 190px; }
    .financeiro-grid .col-doc { width: 110px; }
    .financeiro-grid .col-dc { width: 48px; white-space: nowrap; }
    .financeiro-grid .col-money { width: 122px; white-space: nowrap; }
    .historico-principal { line-height: 1.2; }
    .saldo-card-negativo { color: #dc3545; }
    .saldo-card-positivo { color: #087f5b; }

    @media (max-width: 575.98px) {
        .financeiro-grid {
            border-collapse: separate;
            border-spacing: 0 .75rem;
        }
        .financeiro-grid thead { display: none; }
        .financeiro-grid,
        .financeiro-grid tbody,
        .financeiro-grid tr,
        .financeiro-grid td {
            display: block;
            width: 100%;
        }
        .financeiro-grid tr {
            border: 1px solid #d7dee8;
            border-radius: .5rem;
            background: #fff;
            overflow: hidden;
        }
        .financeiro-grid td {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: .55rem .75rem;
            border: 0;
            border-bottom: 1px solid #edf1f5;
            text-align: right !important;
        }
        .financeiro-grid td:last-child { border-bottom: 0; }
        .financeiro-grid td::before {
            content: attr(data-label);
            flex: 0 0 34%;
            color: #64748b;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            font-size: .72rem;
            line-height: 1.2;
        }
        .financeiro-grid td[data-label="Historico"],
        .financeiro-grid td[data-label="TipoEs"] {
            display: block;
            text-align: left !important;
        }
        .financeiro-grid td[data-label="Historico"]::before,
        .financeiro-grid td[data-label="TipoEs"]::before {
            display: block;
            margin-bottom: .25rem;
        }
    }
</style>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Financeiro</span>
                <h1 class="h3 fw-bold mb-2">Contas</h1>
                <p class="text-muted mb-0">Extrato por conta com saldos, creditos, debitos e base para conciliacao bancaria.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_financeiro.php" class="btn btn-outline-secondary">Voltar ao financeiro</a>
            </div>
        </div>
    </div>
</section>

<section class="mb-3">
    <form method="GET" class="bg-white border rounded-2 shadow-sm p-3">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Conta</label>
                <select name="cbcontador" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach ($contas as $conta): ?>
                        <option value="<?= (int)$conta['CBCONTADOR'] ?>" <?= $contaSelecionada === (int)$conta['CBCONTADOR'] ? 'selected' : '' ?>>
                            <?= (int)$conta['CBCONTADOR'] ?> - <?= htmlspecialchars($conta['TITULAR'] ?: $conta['DESCABREV']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Data inicial</label>
                <input type="date" name="data_ini" class="form-control" value="<?= htmlspecialchars($dataIni) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Data final</label>
                <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($dataFim) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">D/C</label>
                <select name="dc" class="form-select">
                    <option value="">Todos</option>
                    <option value="C" <?= $dc === 'C' ? 'selected' : '' ?>>Credito</option>
                    <option value="D" <?= $dc === 'D' ? 'selected' : '' ?>>Debito</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">TipoEs</label>
                <select name="tipoes" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($tipos as $tipo): ?>
                        <option value="<?= (int)$tipo['ESCONTADOR'] ?>" <?= $tipoes === (string)(int)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                            <?= (int)$tipo['ESCONTADOR'] ?> - <?= htmlspecialchars($tipo['DESCES']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Historico</label>
                <input type="text" name="historico" class="form-control" value="<?= htmlspecialchars($historico) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Documento</label>
                <input type="text" name="documento" class="form-control" value="<?= htmlspecialchars($documento) ?>">
            </div>
            <div class="col-md-4 d-flex gap-2 justify-content-end">
                <a href="contas.php" class="btn btn-outline-secondary">Limpar</a>
                <button type="submit" class="btn btn-primary">Filtrar</button>
            </div>
        </div>
    </form>
</section>

<section class="mb-3">
    <div class="bg-white border rounded-2 shadow-sm p-3">
        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="acao" value="salvar_saldo">
            <input type="hidden" name="cbcontador" value="<?= (int)$contaSelecionada ?>">
            <div class="col-md-4">
                <div class="small text-muted">Saldo inicial da conta selecionada</div>
                <div class="fw-semibold">
                    <?php if ($contaSelecionada > 0): ?>
                        Conta <?= (int)$contaSelecionada ?>
                    <?php else: ?>
                        Selecione uma conta para configurar
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Data do saldo</label>
                <input type="date" name="data_saldo" class="form-control" value="<?= htmlspecialchars($saldoInicial['data_saldo'] ?? date('Y-m-d')) ?>" <?= $contaSelecionada > 0 ? '' : 'disabled' ?>>
            </div>
            <div class="col-md-3">
                <label class="form-label">Valor inicial</label>
                <input type="text" name="valor_saldo" class="form-control" value="<?= $saldoInicial ? number_format((float)$saldoInicial['valor_saldo'], 2, ',', '.') : '0,00' ?>" <?= $contaSelecionada > 0 ? '' : 'disabled' ?>>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-success w-100" <?= $contaSelecionada > 0 ? '' : 'disabled' ?>>Gravar</button>
            </div>
        </form>
    </div>
</section>

<section class="mb-3">
    <div class="row g-3">
        <div class="col-md-3">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Registros filtrados</div>
                <div class="h5 fw-bold mb-0"><?= (int)$resumo['qtd'] ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Creditos</div>
                <div class="h5 fw-bold mb-0 saldo-card-positivo"><?= moedaContasBanco($resumo['total_creditos']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Debitos</div>
                <div class="h5 fw-bold mb-0 saldo-card-negativo"><?= moedaContasBanco($resumo['total_debitos']) ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Saldo</div>
                <div class="h5 fw-bold mb-0 <?= $saldoResumo < 0 ? 'saldo-card-negativo' : 'saldo-card-positivo' ?>"><?= moedaContasBanco($saldoResumo) ?></div>
            </div>
        </div>
    </div>
</section>

<section>
    <div class="bg-white border rounded-2 shadow-sm overflow-hidden">
        <?php if ($contaSelecionada === 0): ?>
            <div class="alert alert-info m-3 mb-0">Selecione uma conta para acompanhar o saldo linha a linha do extrato.</div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 financeiro-grid">
                <thead class="table-primary">
                    <tr>
                        <th class="col-date">Data</th>
                        <th class="col-type">TipoEs</th>
                        <th>Historico</th>
                        <th class="col-doc">Documento</th>
                        <th class="col-dc">D/C</th>
                        <th class="text-end col-money">Valor</th>
                        <th class="text-end col-money">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registros as $registro): ?>
                        <?php
                            $doc = $registro['NUMDOC'] ?: ($registro['NUMDOCORIGEM'] ?: $registro['NUMCONTROLE']);
                            $mov = strtoupper((string)$registro['TIPOMOV']);
                        ?>
                        <tr>
                            <td data-label="Data" class="col-date"><?= dataContasBanco($registro['DTMOV']) ?></td>
                            <td data-label="TipoEs" class="col-type">
                                <div class="fw-semibold"><?= (int)$registro['TIPOES'] ?> - <?= htmlspecialchars($registro['tipo_nome']) ?></div>
                                <?php if ($contaSelecionada === 0): ?>
                                    <div class="small text-muted">Conta <?= (int)$registro['CBCONTADOR'] ?> - <?= htmlspecialchars($registro['conta_nome']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Historico">
                                <div class="historico-principal"><?= htmlspecialchars((string)$registro['HISTMOV']) ?></div>
                                <div class="small text-muted">Mov. <?= (int)$registro['MOVCONTADOR'] ?></div>
                            </td>
                            <td data-label="Documento" class="col-doc"><?= htmlspecialchars((string)$doc) ?></td>
                            <td data-label="D/C" class="col-dc">
                                <span class="badge <?= $mov === 'C' ? 'text-bg-success' : 'text-bg-danger' ?>"><?= htmlspecialchars($mov ?: '-') ?></span>
                            </td>
                            <td data-label="Valor" class="text-end fw-semibold col-money"><?= moedaContasBanco(abs((float)$registro['VALORMOV'])) ?></td>
                            <td data-label="Saldo" class="text-end col-money <?= ((float)$registro['saldo_calculado']) < 0 ? 'saldo-card-negativo' : 'saldo-card-positivo' ?>">
                                <?= $contaSelecionada > 0 ? moedaContasBanco($registro['saldo_calculado']) : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($registros)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Nenhum lancamento encontrado com os filtros informados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ((int)$resumo['qtd'] > count($registros)): ?>
            <div class="small text-muted p-3 border-top">
                Exibindo os primeiros <?= count($registros) ?> registros de <?= (int)$resumo['qtd'] ?> filtrados. Refine os filtros para ver um conjunto menor.
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
