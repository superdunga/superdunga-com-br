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

function normalizarContasSelecionadas($valor): array
{
    $valores = is_array($valor) ? $valor : [$valor];
    $contas = [];

    foreach ($valores as $item) {
        if ($item === '' || $item === null) {
            continue;
        }

        $conta = (int)$item;
        if ($conta > 0) {
            $contas[$conta] = true;
        }
    }

    return array_keys($contas);
}

$contasSelecionadas = normalizarContasSelecionadas($_GET['cbcontador'] ?? []);
$contaSelecionada = count($contasSelecionadas) === 1 ? $contasSelecionadas[0] : 0;
$dataIni = trim($_GET['data_ini'] ?? '');
$dataFim = trim($_GET['data_fim'] ?? '');
$tipoes = trim($_GET['tipoes'] ?? '');
$historico = trim($_GET['historico'] ?? '');
$documento = trim($_GET['documento'] ?? '');
$dc = strtoupper(trim($_GET['dc'] ?? ''));
$visao = trim($_GET['visao'] ?? 'extrato');

if (!in_array($visao, ['extrato', 'sintetico'], true)) {
    $visao = 'extrato';
}

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
      AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
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
        ORDER BY CBCONTADOR
    ");
    $stmtContasFallback->execute([$empresaId]);
    $contas = $stmtContasFallback->fetchAll(PDO::FETCH_ASSOC);
}

if ($contaSelecionada === 0 && count($contas) === 1) {
    $contaSelecionada = (int)$contas[0]['CBCONTADOR'];
    $contasSelecionadas = [$contaSelecionada];
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
        ORDER BY TIPOES
    ");
    $stmtTiposFallback->execute([$empresaId]);
    $tipos = $stmtTiposFallback->fetchAll(PDO::FETCH_ASSOC);
}

$saldosIniciaisPorConta = [];
$stmtSaldos = $pdo_master->prepare("
    SELECT cbcontador, data_saldo, valor_saldo
    FROM financeiro_contas_saldos
    WHERE empresa_id = ?
");
$stmtSaldos->execute([$empresaId]);
foreach ($stmtSaldos->fetchAll(PDO::FETCH_ASSOC) as $saldoConta) {
    $saldosIniciaisPorConta[(int)$saldoConta['cbcontador']] = $saldoConta;
}

$saldoInicial = $contaSelecionada > 0 ? ($saldosIniciaisPorConta[$contaSelecionada] ?? null) : null;

$where = [
    'b.EMPRESA = ?',
    "COALESCE(b.deletado, 'N') <> 'S'",
];
$params = [$empresaId];

if (!empty($contasSelecionadas)) {
    $where[] = 'b.CBCONTADOR IN (' . implode(',', array_fill(0, count($contasSelecionadas), '?')) . ')';
    foreach ($contasSelecionadas as $contaFiltro) {
        $params[] = $contaFiltro;
    }
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

foreach ($contasSelecionadas as $contaSaldoBase) {
    $saldoContaBase = $saldosIniciaisPorConta[$contaSaldoBase] ?? null;
    if (!$saldoContaBase) {
        continue;
    }

    $saldoBase += (float)$saldoContaBase['valor_saldo'];
    $saldoBaseData = $saldoContaBase['data_saldo'];
    $paramsAntes = [$empresaId, $contaSaldoBase, $saldoBaseData];
    $filtroAntes = "b.EMPRESA = ? AND b.CBCONTADOR = ? AND DATE(b.DTMOV) > ? AND COALESCE(b.deletado, 'N') <> 'S'";

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

$stmtSintetico = $pdo_master->prepare("
    SELECT
        b.CBCONTADOR,
        COALESCE(c.TITULAR, c.DESCABREV, CONCAT('Conta ', b.CBCONTADOR)) AS conta_nome,
        COUNT(*) AS qtd,
        COALESCE(SUM(CASE WHEN b.TIPOMOV = 'C' THEN ABS(b.VALORMOV) ELSE 0 END), 0) AS total_creditos,
        COALESCE(SUM(CASE WHEN b.TIPOMOV = 'D' THEN ABS(b.VALORMOV) ELSE 0 END), 0) AS total_debitos
    FROM armazem_bnc001 b
    LEFT JOIN armazem_bnc002 c
        ON c.EMPRESA = b.EMPRESA
       AND c.CBCONTADOR = b.CBCONTADOR
    WHERE {$whereSql}
    GROUP BY b.CBCONTADOR, conta_nome
    ORDER BY b.CBCONTADOR
");
$stmtSintetico->execute($params);
$contasSintetico = $stmtSintetico->fetchAll(PDO::FETCH_ASSOC);

foreach ($contasSintetico as &$contaResumo) {
    $cbcontadorResumo = (int)$contaResumo['CBCONTADOR'];
    $saldoBaseConta = 0.0;
    $saldoConta = $saldosIniciaisPorConta[$cbcontadorResumo] ?? null;

    if ($saldoConta) {
        $saldoBaseConta = (float)$saldoConta['valor_saldo'];
        $paramsAntesConta = [$empresaId, $cbcontadorResumo, $saldoConta['data_saldo']];
        $filtroAntesConta = "b.EMPRESA = ? AND b.CBCONTADOR = ? AND DATE(b.DTMOV) > ? AND COALESCE(b.deletado, 'N') <> 'S'";

        if ($dataIni !== '') {
            $filtroAntesConta .= " AND DATE(b.DTMOV) < ?";
            $paramsAntesConta[] = $dataIni;
        }

        $stmtAntesConta = $pdo_master->prepare("
            SELECT COALESCE(SUM(CASE WHEN b.TIPOMOV = 'C' THEN ABS(b.VALORMOV) ELSE -ABS(b.VALORMOV) END), 0)
            FROM armazem_bnc001 b
            WHERE {$filtroAntesConta}
        ");
        $stmtAntesConta->execute($paramsAntesConta);
        $saldoBaseConta += (float)$stmtAntesConta->fetchColumn();
    }

    $contaResumo['saldo_base'] = $saldoBaseConta;
    $contaResumo['saldo'] = $saldoBaseConta + (float)$contaResumo['total_creditos'] - (float)$contaResumo['total_debitos'];
}
unset($contaResumo);

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
        COALESCE(b.deletado, 'N') AS deletado,
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

if (($_GET['exportar'] ?? '') === 'excel') {
    $nomeArquivo = 'contas_' . $visao . '_' . date('Ymd_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<table border="1">';

    if ($visao === 'sintetico') {
        echo '<tr>';
        foreach (['Conta', 'Nome', 'Creditos', 'Debitos', 'Saldo'] as $cabecalho) {
            echo '<th>' . htmlspecialchars($cabecalho) . '</th>';
        }
        echo '</tr>';

        foreach ($contasSintetico as $contaResumo) {
            echo '<tr>';
            echo '<td>' . (int)$contaResumo['CBCONTADOR'] . '</td>';
            echo '<td>' . htmlspecialchars((string)$contaResumo['conta_nome']) . '</td>';
            echo '<td>' . number_format((float)$contaResumo['total_creditos'], 2, ',', '.') . '</td>';
            echo '<td>' . number_format((float)$contaResumo['total_debitos'], 2, ',', '.') . '</td>';
            echo '<td>' . number_format((float)$contaResumo['saldo'], 2, ',', '.') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr>';
        foreach (['Data', 'Conta', 'TipoEs', 'Historico', 'Documento', 'D/C', 'Valor', 'Saldo'] as $cabecalho) {
            echo '<th>' . htmlspecialchars($cabecalho) . '</th>';
        }
        echo '</tr>';

        foreach ($registros as $registro) {
            $documentoExcel = $registro['NUMDOC'] ?: ($registro['NUMDOCORIGEM'] ?: $registro['NUMCONTROLE']);
            echo '<tr>';
            echo '<td>' . htmlspecialchars(dataContasBanco($registro['DTMOV'])) . '</td>';
            echo '<td>' . (int)$registro['CBCONTADOR'] . ' - ' . htmlspecialchars((string)$registro['conta_nome']) . '</td>';
            echo '<td>' . (int)$registro['TIPOES'] . ' - ' . htmlspecialchars((string)$registro['tipo_nome']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$registro['HISTMOV']) . '</td>';
            echo '<td>' . htmlspecialchars((string)$documentoExcel) . '</td>';
            echo '<td>' . htmlspecialchars((string)$registro['TIPOMOV']) . '</td>';
            echo '<td>' . number_format(abs((float)$registro['VALORMOV']), 2, ',', '.') . '</td>';
            echo '<td>' . (!empty($contasSelecionadas) ? number_format((float)$registro['saldo_calculado'], 2, ',', '.') : '') . '</td>';
            echo '</tr>';
        }
    }

    echo '</table>';
    exit;
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
    .contas-filter-actions .btn { white-space: nowrap; }
    .contas-selector {
        max-height: 190px;
        overflow-y: auto;
        border: 1px solid #d7dee8;
        border-radius: .5rem;
        padding: .35rem .45rem;
        background: #fff;
    }
    .contas-selector-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: .18rem .75rem;
    }
    .conta-check {
        display: flex;
        align-items: center;
        gap: .45rem;
        min-width: 0;
        padding: .18rem .45rem;
        border-radius: .4rem;
    }
    .conta-check:hover { background: #f5f8fc; }
    .conta-check input { flex: 0 0 auto; }
    .conta-check span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: .9rem;
    }

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
        .contas-filter-actions {
            display: grid !important;
            grid-template-columns: 1fr 1fr;
        }
        .contas-filter-actions .btn,
        .contas-filter-actions button {
            width: 100%;
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
        <input type="hidden" name="visao" value="<?= htmlspecialchars($visao) ?>">
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Conta</label>
                <div class="contas-selector">
                    <div class="contas-selector-grid">
                        <?php foreach ($contas as $conta): ?>
                            <?php $codigoConta = (int)$conta['CBCONTADOR']; ?>
                            <label class="conta-check" title="<?= htmlspecialchars($codigoConta . ' - ' . ($conta['TITULAR'] ?: $conta['DESCABREV'])) ?>">
                                <input type="checkbox" name="cbcontador[]" value="<?= $codigoConta ?>" <?= in_array($codigoConta, $contasSelecionadas, true) ? 'checked' : '' ?>>
                                <span><?= $codigoConta ?> - <?= htmlspecialchars($conta['TITULAR'] ?: $conta['DESCABREV']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-text">Marque uma ou mais contas. Sem marcar nenhuma, a tela mostra todas.</div>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label">Data inicial</label>
                <input type="date" name="data_ini" class="form-control" value="<?= htmlspecialchars($dataIni) ?>">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label">Data final</label>
                <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($dataFim) ?>">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label">D/C</label>
                <select name="dc" class="form-select">
                    <option value="">Todos</option>
                    <option value="C" <?= $dc === 'C' ? 'selected' : '' ?>>Credito</option>
                    <option value="D" <?= $dc === 'D' ? 'selected' : '' ?>>Debito</option>
                </select>
            </div>
            <div class="col-6 col-lg-2">
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
            <div class="col-12 col-lg-4">
                <label class="form-label">Historico</label>
                <input type="text" name="historico" class="form-control" value="<?= htmlspecialchars($historico) ?>">
            </div>
            <div class="col-12 col-lg-3">
                <label class="form-label">Documento</label>
                <input type="text" name="documento" class="form-control" value="<?= htmlspecialchars($documento) ?>">
            </div>
            <div class="col-12 col-lg-9 d-flex flex-wrap gap-2 justify-content-lg-end contas-filter-actions">
                <a href="contas.php" class="btn btn-outline-secondary">Limpar</a>
                <a href="contas.php?<?= htmlspecialchars(queryContasBanco(['visao' => 'sintetico'])) ?>" class="btn <?= $visao === 'sintetico' ? 'btn-success' : 'btn-outline-success' ?>">Sintetico</a>
                <a href="contas.php?<?= htmlspecialchars(queryContasBanco(['visao' => 'extrato'])) ?>" class="btn <?= $visao === 'extrato' ? 'btn-primary' : 'btn-outline-primary' ?>">Extrato</a>
                <a href="contas.php?<?= htmlspecialchars(queryContasBanco(['exportar' => 'excel'])) ?>" class="btn btn-outline-success">Exportar Excel</a>
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
                    <?php elseif (count($contasSelecionadas) > 1): ?>
                        Selecione apenas uma conta para configurar
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

<?php if ($visao === 'sintetico'): ?>
<section>
    <div class="bg-white border rounded-2 shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 financeiro-grid">
                <thead class="table-primary">
                    <tr>
                        <th>Conta</th>
                        <th class="text-end col-money">Registros</th>
                        <th class="text-end col-money">Creditos</th>
                        <th class="text-end col-money">Debitos</th>
                        <th class="text-end col-money">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contasSintetico as $contaResumo): ?>
                        <?php $saldoContaResumo = (float)$contaResumo['saldo']; ?>
                        <tr>
                            <td data-label="Conta">
                                <div class="fw-semibold"><?= (int)$contaResumo['CBCONTADOR'] ?> - <?= htmlspecialchars((string)$contaResumo['conta_nome']) ?></div>
                            </td>
                            <td data-label="Registros" class="text-end col-money"><?= (int)$contaResumo['qtd'] ?></td>
                            <td data-label="Creditos" class="text-end col-money saldo-card-positivo"><?= moedaContasBanco($contaResumo['total_creditos']) ?></td>
                            <td data-label="Debitos" class="text-end col-money saldo-card-negativo"><?= moedaContasBanco($contaResumo['total_debitos']) ?></td>
                            <td data-label="Saldo" class="text-end fw-bold col-money <?= $saldoContaResumo < 0 ? 'saldo-card-negativo' : 'saldo-card-positivo' ?>">
                                <?= moedaContasBanco($saldoContaResumo) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($contasSintetico)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">Nenhuma conta encontrada com os filtros informados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php else: ?>
<section>
    <div class="bg-white border rounded-2 shadow-sm overflow-hidden">
        <?php if (empty($contasSelecionadas)): ?>
            <div class="alert alert-info m-3 mb-0">Selecione uma ou mais contas para acompanhar o saldo linha a linha do extrato.</div>
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
                                <?php if (count($contasSelecionadas) !== 1): ?>
                                    <div class="small text-muted">Conta <?= (int)$registro['CBCONTADOR'] ?> - <?= htmlspecialchars($registro['conta_nome']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Historico">
                                <div class="historico-principal"><?= htmlspecialchars((string)$registro['HISTMOV']) ?></div>
                                <div class="small text-muted">
                                    Mov. <?= (int)$registro['MOVCONTADOR'] ?>
                                    <?php if (($registro['deletado'] ?? 'N') === 'S'): ?>
                                        <span class="badge text-bg-secondary ms-1">Excluido</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Documento" class="col-doc"><?= htmlspecialchars((string)$doc) ?></td>
                            <td data-label="D/C" class="col-dc">
                                <span class="badge <?= $mov === 'C' ? 'text-bg-success' : 'text-bg-danger' ?>"><?= htmlspecialchars($mov ?: '-') ?></span>
                            </td>
                            <td data-label="Valor" class="text-end fw-semibold col-money"><?= moedaContasBanco(abs((float)$registro['VALORMOV'])) ?></td>
                            <td data-label="Saldo" class="text-end col-money <?= ((float)$registro['saldo_calculado']) < 0 ? 'saldo-card-negativo' : 'saldo-card-positivo' ?>">
                                <?= !empty($contasSelecionadas) ? moedaContasBanco($registro['saldo_calculado']) : '-' ?>
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
<?php endif; ?>

<?php require '../../layout/footer.php'; ?>
