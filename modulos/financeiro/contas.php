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

function garantirTabelasAcertosExtrato(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_acertos_extrato (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            cbcontador INT NOT NULL,
            data_acerto DATETIME NOT NULL,
            descricao VARCHAR(255) NOT NULL,
            total_debitos DECIMAL(15,4) NOT NULL DEFAULT 0,
            total_creditos DECIMAL(15,4) NOT NULL DEFAULT 0,
            diferenca DECIMAL(15,4) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'ATIVO',
            usuario_id INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cancelado_por INT NULL,
            cancelado_em DATETIME NULL,
            INDEX idx_fin_acerto_empresa_conta (empresa_id, cbcontador, status, data_acerto)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_acertos_extrato_itens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            acerto_id INT NOT NULL,
            empresa_id INT NOT NULL,
            movcontador INT NOT NULL,
            tipo_mov CHAR(1) NOT NULL,
            valor DECIMAL(15,4) NOT NULL DEFAULT 0,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_fin_acerto_item_ativo (empresa_id, movcontador, acerto_id),
            INDEX idx_fin_acerto_item_mov (empresa_id, movcontador),
            INDEX idx_fin_acerto_item_acerto (acerto_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

garantirTabelaSaldosContas($pdo_master);
garantirTabelasAcertosExtrato($pdo_master);

function moedaContasBanco($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function saldoContasBancoTexto($valor): string
{
    $valor = (float)$valor;
    if (abs($valor) < 0.01) {
        return 'R$ 0,00';
    }

    return 'R$ ' . number_format(abs($valor), 2, ',', '.') . ' ' . ($valor < 0 ? 'D' : 'C');
}

function saldoContasBancoHtml($valor): string
{
    $valor = (float)$valor;
    if (abs($valor) < 0.01) {
        return '<span class="fw-bold">R$ 0,00</span>';
    }

    $sinal = $valor < 0 ? 'D' : 'C';
    $classe = $valor < 0 ? 'text-danger border-danger' : 'text-success border-success';

    return 'R$ ' . number_format(abs($valor), 2, ',', '.') .
        ' <span class="badge bg-white border ' . $classe . '">' . $sinal . '</span>';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_acerto') {
    $movs = $_POST['movcontadores'] ?? [];
    $descricaoAcerto = trim((string)($_POST['descricao_acerto'] ?? ''));
    $redirectQuery = trim((string)($_POST['redirect_query'] ?? ''));
    $redirectBase = 'contas.php' . ($redirectQuery !== '' ? '?' . $redirectQuery : '');

    if (!is_array($movs)) {
        $movs = [];
    }

    $movs = array_map('intval', $movs);
    $movs = array_values(array_unique(array_filter($movs, static function ($mov): bool {
        return $mov > 0;
    })));

    if (count($movs) < 2) {
        header('Location: ' . $redirectBase . (strpos($redirectBase, '?') === false ? '?' : '&') . 'erro_acerto=selecione');
        exit;
    }

    if ($descricaoAcerto === '') {
        $descricaoAcerto = 'Acerto de extrato';
    }

    $placeholdersMov = implode(',', array_fill(0, count($movs), '?'));
    $stmtMovsAcerto = $pdo_master->prepare("
        SELECT
            b.MOVCONTADOR,
            b.EMPRESA,
            b.CBCONTADOR,
            b.DTMOV,
            b.TIPOMOV,
            ABS(b.VALORMOV) AS VALORMOV
        FROM armazem_bnc001 b
        WHERE b.EMPRESA = ?
          AND b.MOVCONTADOR IN ($placeholdersMov)
          AND COALESCE(b.deletado, 'N') <> 'S'
          AND NOT EXISTS (
              SELECT 1
              FROM financeiro_acertos_extrato_itens ai
              INNER JOIN financeiro_acertos_extrato a
                  ON a.id = ai.acerto_id
                 AND a.status = 'ATIVO'
              WHERE ai.empresa_id = b.EMPRESA
                AND ai.movcontador = b.MOVCONTADOR
          )
        ORDER BY b.DTMOV, b.MOVCONTADOR
    ");
    $stmtMovsAcerto->execute(array_merge([$empresaId], $movs));
    $movimentosAcerto = $stmtMovsAcerto->fetchAll(PDO::FETCH_ASSOC);

    $contasAcerto = array_unique(array_map(static function ($item): int {
        return (int)$item['CBCONTADOR'];
    }, $movimentosAcerto));

    if (count($movimentosAcerto) !== count($movs) || count($contasAcerto) !== 1) {
        header('Location: ' . $redirectBase . (strpos($redirectBase, '?') === false ? '?' : '&') . 'erro_acerto=invalidos');
        exit;
    }

    $totalDebitos = 0.0;
    $totalCreditos = 0.0;
    $dataAcerto = null;

    foreach ($movimentosAcerto as $movAcerto) {
        $valorMov = abs((float)$movAcerto['VALORMOV']);
        if (strtoupper((string)$movAcerto['TIPOMOV']) === 'C') {
            $totalCreditos += $valorMov;
        } else {
            $totalDebitos += $valorMov;
        }

        if ($dataAcerto === null || strtotime((string)$movAcerto['DTMOV']) > strtotime($dataAcerto)) {
            $dataAcerto = (string)$movAcerto['DTMOV'];
        }
    }

    $diferencaAcerto = round($totalCreditos - $totalDebitos, 2);

    if (abs($diferencaAcerto) > 0.01) {
        header('Location: ' . $redirectBase . (strpos($redirectBase, '?') === false ? '?' : '&') . 'erro_acerto=diferenca');
        exit;
    }

    $pdo_master->beginTransaction();

    try {
        $stmtAcerto = $pdo_master->prepare("
            INSERT INTO financeiro_acertos_extrato (
                empresa_id, cbcontador, data_acerto, descricao,
                total_debitos, total_creditos, diferenca, status, usuario_id, criado_em
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'ATIVO', ?, NOW())
        ");
        $stmtAcerto->execute([
            $empresaId,
            (int)$contasAcerto[0],
            $dataAcerto,
            $descricaoAcerto,
            $totalDebitos,
            $totalCreditos,
            $diferencaAcerto,
            $usuarioId ?: null,
        ]);
        $acertoId = (int)$pdo_master->lastInsertId();

        $stmtItemAcerto = $pdo_master->prepare("
            INSERT INTO financeiro_acertos_extrato_itens (
                acerto_id, empresa_id, movcontador, tipo_mov, valor, criado_em
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");

        foreach ($movimentosAcerto as $movAcerto) {
            $stmtItemAcerto->execute([
                $acertoId,
                $empresaId,
                (int)$movAcerto['MOVCONTADOR'],
                strtoupper((string)$movAcerto['TIPOMOV']),
                abs((float)$movAcerto['VALORMOV']),
            ]);
        }

        $pdo_master->commit();
    } catch (Throwable $e) {
        $pdo_master->rollBack();
        throw $e;
    }

    header('Location: ' . $redirectBase . (strpos($redirectBase, '?') === false ? '?' : '&') . 'ok_acerto=' . $acertoId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'desfazer_acerto') {
    $acertoId = (int)($_POST['acerto_id'] ?? 0);
    $redirectQuery = trim((string)($_POST['redirect_query'] ?? ''));
    $redirectBase = 'contas.php' . ($redirectQuery !== '' ? '?' . $redirectQuery : '');

    if ($acertoId > 0) {
        $stmtDesfazer = $pdo_master->prepare("
            UPDATE financeiro_acertos_extrato
            SET status = 'CANCELADO',
                cancelado_por = ?,
                cancelado_em = NOW()
            WHERE id = ?
              AND empresa_id = ?
              AND status = 'ATIVO'
        ");
        $stmtDesfazer->execute([$usuarioId ?: null, $acertoId, $empresaId]);
    }

    header('Location: ' . $redirectBase);
    exit;
}

$stmtContas = $pdo_master->prepare("
    SELECT
        CBCONTADOR,
        TITULAR,
        DESCABREV,
        TRIM(COALESCE(NULLIF(TITULAR, ''), NULLIF(DESCABREV, ''), CONCAT('Conta ', CBCONTADOR))) AS nome_conta
    FROM armazem_bnc002
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
      AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
    ORDER BY nome_conta ASC, CBCONTADOR ASC
");
$stmtContas->execute([$empresaId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

if (empty($contas)) {
    $stmtContasFallback = $pdo_master->prepare("
        SELECT DISTINCT
            CBCONTADOR,
            CONCAT('Conta ', CBCONTADOR) AS TITULAR,
            CONCAT('Conta ', CBCONTADOR) AS DESCABREV,
            CONCAT('Conta ', CBCONTADOR) AS nome_conta
        FROM armazem_bnc001
        WHERE EMPRESA = ?
          AND CBCONTADOR IS NOT NULL
        ORDER BY nome_conta ASC, CBCONTADOR ASC
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
    "NOT EXISTS (
        SELECT 1
        FROM financeiro_acertos_extrato_itens ai
        INNER JOIN financeiro_acertos_extrato a
            ON a.id = ai.acerto_id
           AND a.status = 'ATIVO'
        WHERE ai.empresa_id = b.EMPRESA
          AND ai.movcontador = b.MOVCONTADOR
    )",
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
$limiteExtrato = 1500;
$totalRegistrosFiltro = (int)($resumo['qtd'] ?? 0);

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
    SELECT *
    FROM (
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
        ORDER BY b.DTMOV DESC, b.MOVCONTADOR DESC
        LIMIT {$limiteExtrato}
    ) ultimos_movimentos
    ORDER BY DTMOV ASC, MOVCONTADOR ASC
");
$stmt->execute($params);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

$acertosExtrato = [];
$itensAcertos = [];

if ($visao === 'extrato' && $tipoes === '' && $documento === '' && $dc === '') {
    $whereAcertos = [
        'a.empresa_id = ?',
        "a.status = 'ATIVO'",
    ];
    $paramsAcertos = [$empresaId];

    if (!empty($contasSelecionadas)) {
        $whereAcertos[] = 'a.cbcontador IN (' . implode(',', array_fill(0, count($contasSelecionadas), '?')) . ')';
        foreach ($contasSelecionadas as $contaFiltroAcerto) {
            $paramsAcertos[] = $contaFiltroAcerto;
        }
    }

    if ($dataIni !== '') {
        $whereAcertos[] = 'DATE(a.data_acerto) >= ?';
        $paramsAcertos[] = $dataIni;
    }

    if ($dataFim !== '') {
        $whereAcertos[] = 'DATE(a.data_acerto) <= ?';
        $paramsAcertos[] = $dataFim;
    }

    if ($historico !== '') {
        $whereAcertos[] = 'a.descricao LIKE ?';
        $paramsAcertos[] = '%' . $historico . '%';
    }

    $stmtAcertosLista = $pdo_master->prepare("
        SELECT
            a.id,
            a.cbcontador,
            a.data_acerto,
            a.descricao,
            a.total_debitos,
            a.total_creditos,
            a.diferenca,
            COALESCE(c.TITULAR, CONCAT('Conta ', a.cbcontador)) AS conta_nome
        FROM financeiro_acertos_extrato a
        LEFT JOIN armazem_bnc002 c
            ON c.EMPRESA = a.empresa_id
           AND c.CBCONTADOR = a.cbcontador
        WHERE " . implode("\n          AND ", $whereAcertos) . "
        ORDER BY a.data_acerto ASC, a.id ASC
    ");
    $stmtAcertosLista->execute($paramsAcertos);
    $acertosExtrato = $stmtAcertosLista->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($acertosExtrato)) {
        $idsAcertos = array_map(static function ($acerto): int {
            return (int)$acerto['id'];
        }, $acertosExtrato);
        $stmtItensAcertos = $pdo_master->prepare("
            SELECT
                ai.acerto_id,
                ai.movcontador,
                ai.tipo_mov,
                ai.valor,
                b.DTMOV,
                b.TIPOES,
                b.HISTMOV,
                b.NUMDOC,
                b.NUMDOCORIGEM,
                b.NUMCONTROLE
            FROM financeiro_acertos_extrato_itens ai
            LEFT JOIN armazem_bnc001 b
                ON b.EMPRESA = ai.empresa_id
               AND b.MOVCONTADOR = ai.movcontador
            WHERE ai.acerto_id IN (" . implode(',', array_fill(0, count($idsAcertos), '?')) . ")
            ORDER BY ai.acerto_id, b.DTMOV, ai.movcontador
        ");
        $stmtItensAcertos->execute($idsAcertos);

        foreach ($stmtItensAcertos->fetchAll(PDO::FETCH_ASSOC) as $itemAcerto) {
            $itensAcertos[(int)$itemAcerto['acerto_id']][] = $itemAcerto;
        }
    }

    foreach ($acertosExtrato as $acertoExtrato) {
        $registros[] = [
            'tipo_linha' => 'acerto',
            'acerto_id' => (int)$acertoExtrato['id'],
            'MOVCONTADOR' => 0,
            'DTMOV' => $acertoExtrato['data_acerto'],
            'TIPOES' => null,
            'tipo_nome' => 'Acerto',
            'HISTMOV' => 'ACERTO #' . (int)$acertoExtrato['id'] . ' - ' . $acertoExtrato['descricao'],
            'NUMDOC' => '',
            'NUMDOCORIGEM' => '',
            'NUMCONTROLE' => '',
            'TIPOMOV' => 'A',
            'VALORMOV' => (float)$acertoExtrato['diferenca'],
            'deletado' => 'N',
            'CBCONTADOR' => (int)$acertoExtrato['cbcontador'],
            'conta_nome' => $acertoExtrato['conta_nome'],
            'total_debitos' => (float)$acertoExtrato['total_debitos'],
            'total_creditos' => (float)$acertoExtrato['total_creditos'],
        ];
    }

    usort($registros, static function (array $a, array $b): int {
        $cmpData = strcmp((string)$a['DTMOV'], (string)$b['DTMOV']);
        if ($cmpData !== 0) {
            return $cmpData;
        }

        return ((int)($a['MOVCONTADOR'] ?? 0)) <=> ((int)($b['MOVCONTADOR'] ?? 0));
    });
}

if (!empty($contasSelecionadas) && !empty($registros)) {
    $primeiroRegistro = $registros[0];
    $primeiraDataMov = $primeiroRegistro['DTMOV'];
    $primeiroMovcontador = (int)$primeiroRegistro['MOVCONTADOR'];
    $saldoBaseVisual = 0.0;

    foreach ($contasSelecionadas as $contaSaldoVisual) {
        $saldoConta = $saldosIniciaisPorConta[$contaSaldoVisual] ?? null;
        $paramsSaldoVisual = [$empresaId, $contaSaldoVisual];
        $filtrosSaldoVisual = [
            'b.EMPRESA = ?',
            'b.CBCONTADOR = ?',
            "COALESCE(b.deletado, 'N') <> 'S'",
        ];

        if ($saldoConta) {
            $saldoBaseVisual += (float)$saldoConta['valor_saldo'];
            $filtrosSaldoVisual[] = 'DATE(b.DTMOV) > ?';
            $paramsSaldoVisual[] = $saldoConta['data_saldo'];
        }

        $filtrosSaldoVisual[] = '(b.DTMOV < ? OR (b.DTMOV = ? AND b.MOVCONTADOR < ?))';
        $paramsSaldoVisual[] = $primeiraDataMov;
        $paramsSaldoVisual[] = $primeiraDataMov;
        $paramsSaldoVisual[] = $primeiroMovcontador;

        $stmtSaldoVisual = $pdo_master->prepare("
            SELECT COALESCE(SUM(CASE WHEN b.TIPOMOV = 'C' THEN ABS(b.VALORMOV) ELSE -ABS(b.VALORMOV) END), 0)
            FROM armazem_bnc001 b
            WHERE " . implode(' AND ', $filtrosSaldoVisual) . "
        ");
        $stmtSaldoVisual->execute($paramsSaldoVisual);
        $saldoBaseVisual += (float)$stmtSaldoVisual->fetchColumn();
    }

    $saldoBase = $saldoBaseVisual;
}

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
            echo '<td>' . htmlspecialchars(saldoContasBancoTexto($contaResumo['saldo'])) . '</td>';
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
            echo '<td>' . (!empty($contasSelecionadas) ? htmlspecialchars(saldoContasBancoTexto($registro['saldo_calculado'])) : '') . '</td>';
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
        grid-template-columns: 1fr;
        gap: .12rem;
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
        font-size: .9rem;
        line-height: 1.15;
        overflow-wrap: anywhere;
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
                            <?php $nomeConta = trim((string)($conta['nome_conta'] ?? ($conta['TITULAR'] ?: $conta['DESCABREV']))); ?>
                            <label class="conta-check" title="<?= htmlspecialchars($codigoConta . ' - ' . $nomeConta) ?>">
                                <input type="checkbox" name="cbcontador[]" value="<?= $codigoConta ?>" <?= in_array($codigoConta, $contasSelecionadas, true) ? 'checked' : '' ?>>
                                <span><?= $codigoConta ?> - <?= htmlspecialchars($nomeConta) ?></span>
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

<?php if (!empty($_GET['ok_acerto'])): ?>
    <div class="alert alert-success">
        Acerto #<?= (int)$_GET['ok_acerto'] ?> criado com sucesso.
    </div>
<?php endif; ?>

<?php if (!empty($_GET['erro_acerto'])): ?>
    <?php
        $mensagensErroAcerto = [
            'selecione' => 'Selecione pelo menos dois lancamentos para criar um acerto.',
            'invalidos' => 'Os lancamentos selecionados precisam estar ativos, livres de outro acerto e pertencer a mesma conta.',
            'diferenca' => 'O acerto so pode ser criado quando os debitos e creditos selecionados zerarem.',
        ];
        $mensagemErroAcerto = $mensagensErroAcerto[(string)$_GET['erro_acerto']] ?? 'Nao foi possivel criar o acerto.';
    ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensagemErroAcerto) ?></div>
<?php endif; ?>

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
                <div class="h5 fw-bold mb-0 <?= $saldoResumo < 0 ? 'saldo-card-negativo' : 'saldo-card-positivo' ?>"><?= saldoContasBancoHtml($saldoResumo) ?></div>
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
                                <?= saldoContasBancoHtml($saldoContaResumo) ?>
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
        <?php if (!empty($contasSelecionadas)): ?>
            <form method="POST" id="form-criar-acerto" class="border-bottom p-3">
                <input type="hidden" name="acao" value="criar_acerto">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(queryContasBanco(['ok_acerto' => null, 'erro_acerto' => null])) ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-md-7">
                        <label class="form-label">Descricao do acerto</label>
                        <input type="text" name="descricao_acerto" class="form-control" value="Acerto de extrato" maxlength="255">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-success w-100" onclick="return confirm('Criar acerto com os lancamentos marcados?')">Criar acerto</button>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-outline-secondary w-100" id="marcar-acerto-visiveis">Marcar visiveis</button>
                    </div>
                    <div class="col-12">
                        <small class="text-muted">Marque lancamentos de uma mesma conta. O total de debitos e creditos precisa zerar para finalizar o acerto.</small>
                    </div>
                    <div class="col-12">
                        <div class="border rounded-2 bg-light p-2 small" id="resumo-acerto-marcados">
                            <span class="fw-semibold">Selecionados:</span>
                            <span id="acerto-qtd">0</span> |
                            Total marcado: <span class="fw-semibold" id="acerto-total">R$ 0,00</span> |
                            Debitos: <span class="text-danger fw-semibold" id="acerto-debitos">R$ 0,00</span> |
                            Creditos: <span class="text-success fw-semibold" id="acerto-creditos">R$ 0,00</span> |
                            Diferenca: <span class="fw-semibold" id="acerto-diferenca">R$ 0,00</span>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
        <?php if ($totalRegistrosFiltro > $limiteExtrato): ?>
            <div class="alert alert-warning m-3 mb-0">
                Exibindo os ultimos <?= (int)$limiteExtrato ?> lancamentos de <?= (int)$totalRegistrosFiltro ?> encontrados. Use os filtros de data ou conta para refinar o extrato.
            </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 financeiro-grid">
                <thead class="table-primary">
                    <tr>
                        <th class="col-dc">Sel.</th>
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
                            $ehAcerto = ($registro['tipo_linha'] ?? '') === 'acerto';
                        ?>
                        <tr>
                            <td data-label="Sel." class="col-dc">
                                <?php if (!$ehAcerto && !empty($contasSelecionadas)): ?>
                                    <input
                                        type="checkbox"
                                        class="form-check-input acerto-check"
                                        form="form-criar-acerto"
                                        name="movcontadores[]"
                                        value="<?= (int)$registro['MOVCONTADOR'] ?>"
                                        data-tipo="<?= htmlspecialchars($mov) ?>"
                                        data-valor="<?= htmlspecialchars((string)abs((float)$registro['VALORMOV'])) ?>"
                                    >
                                <?php elseif ($ehAcerto): ?>
                                    <span class="badge text-bg-info">Acerto</span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td data-label="Data" class="col-date"><?= dataContasBanco($registro['DTMOV']) ?></td>
                            <td data-label="TipoEs" class="col-type">
                                <div class="fw-semibold">
                                    <?= $ehAcerto ? 'Acerto' : ((int)$registro['TIPOES'] . ' - ' . htmlspecialchars($registro['tipo_nome'])) ?>
                                </div>
                                <?php if (count($contasSelecionadas) !== 1): ?>
                                    <div class="small text-muted">Conta <?= (int)$registro['CBCONTADOR'] ?> - <?= htmlspecialchars($registro['conta_nome']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Historico">
                                <div class="historico-principal"><?= htmlspecialchars((string)$registro['HISTMOV']) ?></div>
                                <div class="small text-muted">
                                    <?php if ($ehAcerto): ?>
                                        Debitos: <?= moedaContasBanco($registro['total_debitos']) ?> |
                                        Creditos: <?= moedaContasBanco($registro['total_creditos']) ?>
                                        <button type="button" class="btn btn-sm btn-outline-dark ms-2" data-bs-toggle="modal" data-bs-target="#modalAcerto<?= (int)$registro['acerto_id'] ?>">Ver itens</button>
                                    <?php else: ?>
                                        Mov. <?= (int)$registro['MOVCONTADOR'] ?>
                                        <?php if (($registro['deletado'] ?? 'N') === 'S'): ?>
                                            <span class="badge text-bg-secondary ms-1">Excluido</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Documento" class="col-doc"><?= htmlspecialchars((string)$doc) ?></td>
                            <td data-label="D/C" class="col-dc">
                                <span class="badge <?= $mov === 'C' ? 'text-bg-success' : ($mov === 'D' ? 'text-bg-danger' : 'text-bg-info') ?>"><?= htmlspecialchars($mov ?: '-') ?></span>
                            </td>
                            <td data-label="Valor" class="text-end fw-semibold col-money"><?= moedaContasBanco(abs((float)$registro['VALORMOV'])) ?></td>
                            <td data-label="Saldo" class="text-end col-money <?= ((float)$registro['saldo_calculado']) < 0 ? 'saldo-card-negativo' : 'saldo-card-positivo' ?>">
                                <?= !empty($contasSelecionadas) ? saldoContasBancoHtml($registro['saldo_calculado']) : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($registros)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Nenhum lancamento encontrado com os filtros informados.</td>
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

<?php foreach ($acertosExtrato as $acertoModal): ?>
    <?php $acertoModalId = (int)$acertoModal['id']; ?>
    <div class="modal fade" id="modalAcerto<?= $acertoModalId ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Acerto #<?= $acertoModalId ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold"><?= htmlspecialchars((string)$acertoModal['descricao']) ?></div>
                        <div class="small text-muted">
                            Conta <?= (int)$acertoModal['cbcontador'] ?> |
                            Data <?= dataContasBanco($acertoModal['data_acerto']) ?> |
                            Debitos <?= moedaContasBanco($acertoModal['total_debitos']) ?> |
                            Creditos <?= moedaContasBanco($acertoModal['total_creditos']) ?> |
                            Diferenca <?= saldoContasBancoTexto($acertoModal['diferenca']) ?>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Mov.</th>
                                    <th>Data</th>
                                    <th>TipoEs</th>
                                    <th>Historico</th>
                                    <th>D/C</th>
                                    <th class="text-end">Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (($itensAcertos[$acertoModalId] ?? []) as $itemAcerto): ?>
                                    <tr>
                                        <td><?= (int)$itemAcerto['movcontador'] ?></td>
                                        <td><?= dataContasBanco($itemAcerto['DTMOV'] ?? null) ?></td>
                                        <td><?= htmlspecialchars((string)($itemAcerto['TIPOES'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string)($itemAcerto['HISTMOV'] ?? '')) ?></td>
                                        <td>
                                            <span class="badge <?= ($itemAcerto['tipo_mov'] ?? '') === 'C' ? 'text-bg-success' : 'text-bg-danger' ?>">
                                                <?= htmlspecialchars((string)$itemAcerto['tipo_mov']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end"><?= moedaContasBanco($itemAcerto['valor']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <form method="POST" onsubmit="return confirm('Desfazer este acerto e voltar a exibir os lancamentos filhos?')">
                        <input type="hidden" name="acao" value="desfazer_acerto">
                        <input type="hidden" name="acerto_id" value="<?= $acertoModalId ?>">
                        <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(queryContasBanco(['ok_acerto' => null, 'erro_acerto' => null])) ?>">
                        <button type="submit" class="btn btn-outline-danger">Desfazer acerto</button>
                    </form>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var botaoMarcar = document.getElementById('marcar-acerto-visiveis');
    var resumoQtd = document.getElementById('acerto-qtd');
    var resumoTotal = document.getElementById('acerto-total');
    var resumoDebitos = document.getElementById('acerto-debitos');
    var resumoCreditos = document.getElementById('acerto-creditos');
    var resumoDiferenca = document.getElementById('acerto-diferenca');

    if (!botaoMarcar || !resumoQtd || !resumoTotal || !resumoDebitos || !resumoCreditos || !resumoDiferenca) {
        return;
    }

    function moeda(valor) {
        return valor.toLocaleString('pt-BR', {
            style: 'currency',
            currency: 'BRL'
        });
    }

    function atualizarResumoAcerto() {
        var checksMarcados = document.querySelectorAll('.acerto-check:checked');
        var total = 0;
        var debitos = 0;
        var creditos = 0;

        checksMarcados.forEach(function (checkbox) {
            var valor = Math.abs(parseFloat(checkbox.dataset.valor || '0'));
            var tipo = (checkbox.dataset.tipo || '').toUpperCase();

            total += valor;

            if (tipo === 'C') {
                creditos += valor;
            } else {
                debitos += valor;
            }
        });

        var diferenca = creditos - debitos;
        resumoQtd.textContent = String(checksMarcados.length);
        resumoTotal.textContent = moeda(total);
        resumoDebitos.textContent = moeda(debitos);
        resumoCreditos.textContent = moeda(creditos);
        resumoDiferenca.textContent = moeda(Math.abs(diferenca)) + (Math.abs(diferenca) < 0.005 ? '' : (diferenca < 0 ? ' D' : ' C'));
        resumoDiferenca.classList.toggle('text-danger', Math.abs(diferenca) >= 0.005 && diferenca < 0);
        resumoDiferenca.classList.toggle('text-success', Math.abs(diferenca) >= 0.005 && diferenca > 0);
    }

    botaoMarcar.addEventListener('click', function () {
        var checks = document.querySelectorAll('.acerto-check');
        var marcar = Array.prototype.some.call(checks, function (checkbox) {
            return !checkbox.checked;
        });

        checks.forEach(function (checkbox) {
            checkbox.checked = marcar;
        });

        atualizarResumoAcerto();
    });

    document.querySelectorAll('.acerto-check').forEach(function (checkbox) {
        checkbox.addEventListener('change', atualizarResumoAcerto);
    });

    atualizarResumoAcerto();
});
</script>

<?php require '../../layout/footer.php'; ?>
