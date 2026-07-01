<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$perfil = strtoupper((string)($_SESSION['nivel'] ?? ''));
$permitido = moduloPermitido($pdo, $empresaId, 'movimentacao_baixa_tipoes', $perfil);

function mbtH($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function mbtProximoTipoes(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COALESCE(MAX(ESCONTADOR), 0) + 1 FROM armazem_bnc005 WHERE EMPRESA = 2");
    return (int)$stmt->fetchColumn();
}

function mbtTipoes(PDO $pdo, int $escontador): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_bnc005
        WHERE EMPRESA = 2
          AND ESCONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$escontador]);
    $tipo = $stmt->fetch(PDO::FETCH_ASSOC);
    return $tipo ?: null;
}

function mbtConta(PDO $pdo, int $cbcontador): ?array
{
    $stmt = $pdo->prepare("
        SELECT CBCONTADOR, NUMERO, DESCABREV, TITULAR
        FROM armazem_bnc002
        WHERE EMPRESA = 2
          AND CBCONTADOR = ?
          AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$cbcontador]);
    $conta = $stmt->fetch(PDO::FETCH_ASSOC);
    return $conta ?: null;
}

function mbtNomeConta(?array $conta): string
{
    if (!$conta) {
        return '';
    }
    $nome = trim((string)($conta['TITULAR'] ?? ''));
    if ($nome === '') {
        $nome = trim((string)($conta['DESCABREV'] ?? ''));
    }
    if ($nome === '') {
        $nome = trim((string)($conta['NUMERO'] ?? ''));
    }
    return $nome !== '' ? $nome : ('Conta ' . (int)$conta['CBCONTADOR']);
}

function mbtDescricaoTipomov(string $tipomov): string
{
    $tipomov = strtoupper($tipomov);
    if ($tipomov === 'D') {
        return 'Debito';
    }
    if ($tipomov === 'C') {
        return 'Credito';
    }
    return 'Nao informado';
}

function mbtUsoTipoes(PDO $pdo, int $tipoes): array
{
    $consultas = [
        'bnc' => "SELECT COUNT(*) FROM armazem_bnc001 WHERE EMPRESA = 2 AND TIPOES = ? AND COALESCE(deletado, 'N') <> 'S'",
        'cp' => "SELECT COUNT(*) FROM armazem_cp001 WHERE EMPRESA = 2 AND TIPOES = ? AND COALESCE(excluido_firebird, 'N') <> 'S'",
        'cr' => "SELECT COUNT(*) FROM armazem_cr001 WHERE EMPRESA = 2 AND TIPOES = ? AND COALESCE(excluido_firebird, 'N') <> 'S'",
        'forn' => "SELECT COUNT(*) FROM armazem_cp003 WHERE EMPRESA = 2 AND TIPOES = ? AND COALESCE(excluido_firebird, 'N') <> 'S'",
    ];
    $retorno = [];
    foreach ($consultas as $chave => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tipoes]);
        $retorno[$chave] = (int)$stmt->fetchColumn();
    }
    return $retorno;
}

function mbtMapearUsoTipoes(PDO $pdo, array $tipoesIds): array
{
    $tipoesIds = array_values(array_unique(array_filter(array_map('intval', $tipoesIds))));
    if (!$tipoesIds) {
        return [];
    }

    $usos = [];
    foreach ($tipoesIds as $id) {
        $usos[$id] = ['bnc' => 0, 'cp' => 0, 'cr' => 0, 'forn' => 0];
    }

    $placeholders = implode(',', array_fill(0, count($tipoesIds), '?'));
    $consultas = [
        'bnc' => "SELECT TIPOES AS tipoes, COUNT(*) AS qtd FROM armazem_bnc001 WHERE EMPRESA = 2 AND TIPOES IN ($placeholders) AND COALESCE(deletado, 'N') <> 'S' GROUP BY TIPOES",
        'cp' => "SELECT TIPOES AS tipoes, COUNT(*) AS qtd FROM armazem_cp001 WHERE EMPRESA = 2 AND TIPOES IN ($placeholders) AND COALESCE(excluido_firebird, 'N') <> 'S' GROUP BY TIPOES",
        'cr' => "SELECT TIPOES AS tipoes, COUNT(*) AS qtd FROM armazem_cr001 WHERE EMPRESA = 2 AND TIPOES IN ($placeholders) AND COALESCE(excluido_firebird, 'N') <> 'S' GROUP BY TIPOES",
        'forn' => "SELECT TIPOES AS tipoes, COUNT(*) AS qtd FROM armazem_cp003 WHERE EMPRESA = 2 AND TIPOES IN ($placeholders) AND COALESCE(excluido_firebird, 'N') <> 'S' GROUP BY TIPOES",
    ];

    foreach ($consultas as $chave => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($tipoesIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $id = (int)$linha['tipoes'];
            if (isset($usos[$id])) {
                $usos[$id][$chave] = (int)$linha['qtd'];
            }
        }
    }

    return $usos;
}

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $permitido && $empresaId === 2) {
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'salvar') {
            $escontador = (int)($_POST['escontador'] ?? 0);
            $descricao = trim((string)($_POST['desces'] ?? ''));
            $tipomov = strtoupper(trim((string)($_POST['tipomov'] ?? '')));
            $grupobnc = (int)($_POST['grupobnc'] ?? 0);
            $subgrupobnc = (int)($_POST['subgrupobnc'] ?? 0);
            $ordem = (int)($_POST['ordemtipo'] ?? 0);
            $pagtoem = trim((string)($_POST['pagtoem'] ?? ''));
            $contaContabil = trim((string)($_POST['contacontabil'] ?? ''));
            $classificaDre = trim((string)($_POST['classificadre'] ?? ''));
            $relatDre = ($_POST['relat_dre'] ?? 'N') === 'S' ? 'S' : 'N';
            $inativo = ($_POST['regdisab'] ?? 'N') === 'S' ? 'S' : 'N';
            $contrapTipoes = (int)($_POST['contrap_tipoes'] ?? 0);
            $contrapTipomov = strtoupper(trim((string)($_POST['contrap_tipomov'] ?? '')));
            $contrapCbcontador = (int)($_POST['contrap_cbcontador'] ?? 0);

            if ($descricao === '') {
                throw new RuntimeException('Informe a descricao do TIPOES.');
            }
            if (!in_array($tipomov, ['D', 'C'], true)) {
                throw new RuntimeException('Informe se o TIPOES e debito ou credito.');
            }
            if ($contrapTipoes > 0) {
                if ($contrapTipoes === $escontador && $escontador > 0) {
                    throw new RuntimeException('O TIPOES de contrapartida nao pode ser o proprio TIPOES.');
                }
                if (!mbtTipoes($pdo, $contrapTipoes)) {
                    throw new RuntimeException('TIPOES de contrapartida nao encontrado.');
                }
                if (!in_array($contrapTipomov, ['D', 'C'], true)) {
                    throw new RuntimeException('Informe o D/C da contrapartida.');
                }
                if ($contrapCbcontador <= 0 || !mbtConta($pdo, $contrapCbcontador)) {
                    throw new RuntimeException('Informe uma conta valida para receber a contrapartida/investimento.');
                }
            } else {
                $contrapTipomov = null;
                $contrapCbcontador = null;
            }

            if ($escontador > 0 && mbtTipoes($pdo, $escontador)) {
                $stmt = $pdo->prepare("
                    UPDATE armazem_bnc005
                    SET DESCES = ?,
                        TIPOMOV = ?,
                        GRUPOBNC = ?,
                        SUBGRUPOBNC = ?,
                        ORDEMTIPO = ?,
                        PAGTOEM = ?,
                        CONTACONTABIL = ?,
                        CLASSIFICADRE = ?,
                        RELAT_DRE = ?,
                        REGDISAB = ?,
                        DESATIVARTIPOMOV = ?,
                        CONTRAP_TIPOES = ?,
                        CONTRAP_TIPOMOV = ?,
                        CONTRAP_EMP = ?,
                        CONTRAP_CBCONTADOR = ?,
                        USERALT = ?,
                        DTALT = NOW(),
                        REGSTAMP = NOW(),
                        REGIMPORT = 'N'
                    WHERE EMPRESA = 2
                      AND ESCONTADOR = ?
                ");
                $stmt->execute([
                    $descricao,
                    $tipomov,
                    $grupobnc ?: null,
                    $subgrupobnc ?: null,
                    $ordem ?: null,
                    $pagtoem !== '' ? $pagtoem : null,
                    $contaContabil !== '' ? $contaContabil : null,
                    $classificaDre !== '' ? $classificaDre : null,
                    $relatDre,
                    $inativo,
                    $inativo,
                    $contrapTipoes ?: null,
                    $contrapTipomov,
                    $contrapTipoes > 0 ? 2 : null,
                    $contrapCbcontador,
                    $usuarioId ?: null,
                    $escontador,
                ]);
                $mensagem = 'TIPOES atualizado com sucesso.';
            } else {
                $escontador = $escontador > 0 ? $escontador : mbtProximoTipoes($pdo);
                if (mbtTipoes($pdo, $escontador)) {
                    throw new RuntimeException('Ja existe TIPOES com este codigo.');
                }
                $stmt = $pdo->prepare("
                    INSERT INTO armazem_bnc005 (
                        EMPRESA, ESCONTADOR, DESCES, TIPOMOV, GRUPOBNC, SUBGRUPOBNC, ORDEMTIPO,
                        PAGTOEM, CONTACONTABIL, CLASSIFICADRE, RELAT_DRE, REGDISAB,
                        DESATIVARTIPOMOV, CONTRAP_TIPOES, CONTRAP_TIPOMOV, CONTRAP_EMP,
                        CONTRAP_CBCONTADOR, USERLANC, USERALT, DTLANC, DTALT, REGSTAMP,
                        REGIMPORT, excluido_firebird
                    ) VALUES (
                        2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), 'N', 'N'
                    )
                ");
                $stmt->execute([
                    $escontador,
                    $descricao,
                    $tipomov,
                    $grupobnc ?: null,
                    $subgrupobnc ?: null,
                    $ordem ?: null,
                    $pagtoem !== '' ? $pagtoem : null,
                    $contaContabil !== '' ? $contaContabil : null,
                    $classificaDre !== '' ? $classificaDre : null,
                    $relatDre,
                    $inativo,
                    $inativo,
                    $contrapTipoes ?: null,
                    $contrapTipomov,
                    $contrapTipoes > 0 ? 2 : null,
                    $contrapCbcontador,
                    $usuarioId ?: null,
                    $usuarioId ?: null,
                ]);
                $mensagem = 'TIPOES cadastrado com sucesso.';
            }
        } elseif ($acao === 'inativar') {
            $escontador = (int)($_POST['escontador'] ?? 0);
            $tipo = mbtTipoes($pdo, $escontador);
            if (!$tipo) {
                throw new RuntimeException('TIPOES nao encontrado.');
            }
            $novaSituacao = (($tipo['REGDISAB'] ?? 'N') === 'S') ? 'N' : 'S';
            $stmt = $pdo->prepare("
                UPDATE armazem_bnc005
                SET REGDISAB = ?,
                    DESATIVARTIPOMOV = ?,
                    USERALT = ?,
                    DTALT = NOW(),
                    REGSTAMP = NOW(),
                    REGIMPORT = 'N'
                WHERE EMPRESA = 2
                  AND ESCONTADOR = ?
            ");
            $stmt->execute([$novaSituacao, $novaSituacao, $usuarioId ?: null, $escontador]);
            $mensagem = 'Situacao do TIPOES alterada.';
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$editarId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$editar = ($permitido && $empresaId === 2 && $editarId > 0) ? mbtTipoes($pdo, $editarId) : null;

$contas = [];
$tiposReferencia = [];
$tipos = [];
$usosPorTipo = [];
$totais = ['qtd' => 0, 'ativos' => 0, 'inativos' => 0, 'vinculados' => 0];

$fCodigo = trim((string)($_GET['codigo'] ?? ''));
$fDescricao = trim((string)($_GET['descricao'] ?? ''));
$fTipomov = strtoupper(trim((string)($_GET['tipomov'] ?? '')));
$fGrupo = trim((string)($_GET['grupo'] ?? ''));
$fSituacao = $_GET['situacao'] ?? 'ativos';
$fInvestimento = $_GET['investimento'] ?? 'todos';

if ($permitido && $empresaId === 2) {
    $stmt = $pdo->query("
        SELECT CBCONTADOR, NUMERO, DESCABREV, TITULAR
        FROM armazem_bnc002
        WHERE EMPRESA = 2
          AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        ORDER BY
            CASE WHEN TRIM(COALESCE(TITULAR, '')) = '' THEN 1 ELSE 0 END,
            TITULAR,
            DESCABREV,
            CBCONTADOR
    ");
    $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT ESCONTADOR, DESCES, TIPOMOV
        FROM armazem_bnc005
        WHERE EMPRESA = 2
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        ORDER BY GRUPOBNC, ESCONTADOR
    ");
    $tiposReferencia = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS qtd,
            SUM(CASE WHEN COALESCE(REGDISAB, 'N') = 'S' THEN 0 ELSE 1 END) AS ativos,
            SUM(CASE WHEN COALESCE(REGDISAB, 'N') = 'S' THEN 1 ELSE 0 END) AS inativos,
            SUM(CASE WHEN CONTRAP_TIPOES IS NOT NULL AND CONTRAP_TIPOES > 0 THEN 1 ELSE 0 END) AS vinculados
        FROM armazem_bnc005
        WHERE EMPRESA = 2
          AND COALESCE(excluido_firebird, 'N') <> 'S'
    ");
    $totais = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: $totais);

    $where = ["t.EMPRESA = 2", "COALESCE(t.excluido_firebird, 'N') <> 'S'"];
    $params = [];

    if ($fCodigo !== '' && ctype_digit($fCodigo)) {
        $where[] = "t.ESCONTADOR = ?";
        $params[] = (int)$fCodigo;
    }
    if ($fDescricao !== '') {
        $where[] = "(t.DESCES LIKE ? OR t.PAGTOEM LIKE ? OR t.CONTACONTABIL LIKE ? OR t.CLASSIFICADRE LIKE ?)";
        $like = '%' . $fDescricao . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if (in_array($fTipomov, ['D', 'C'], true)) {
        $where[] = "t.TIPOMOV = ?";
        $params[] = $fTipomov;
    }
    if ($fGrupo !== '' && ctype_digit($fGrupo)) {
        $where[] = "t.GRUPOBNC = ?";
        $params[] = (int)$fGrupo;
    }
    if ($fSituacao === 'ativos') {
        $where[] = "COALESCE(t.REGDISAB, 'N') <> 'S'";
    } elseif ($fSituacao === 'inativos') {
        $where[] = "COALESCE(t.REGDISAB, 'N') = 'S'";
    }
    if ($fInvestimento === 'vinculados') {
        $where[] = "t.CONTRAP_TIPOES IS NOT NULL AND t.CONTRAP_TIPOES > 0";
    } elseif ($fInvestimento === 'nao_vinculados') {
        $where[] = "(t.CONTRAP_TIPOES IS NULL OR t.CONTRAP_TIPOES = 0)";
    }

    $stmt = $pdo->prepare("
        SELECT
            t.*,
            tc.DESCES AS contrap_desc,
            c.NUMERO AS contrap_numero,
            c.DESCABREV AS contrap_descabrev,
            c.TITULAR AS contrap_titular
        FROM armazem_bnc005 t
        LEFT JOIN armazem_bnc005 tc
          ON tc.EMPRESA = t.EMPRESA
         AND tc.ESCONTADOR = t.CONTRAP_TIPOES
        LEFT JOIN armazem_bnc002 c
          ON c.EMPRESA = t.EMPRESA
         AND c.CBCONTADOR = t.CONTRAP_CBCONTADOR
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(t.GRUPOBNC, 999999), COALESCE(t.ORDEMTIPO, 999999), t.ESCONTADOR
        LIMIT 500
    ");
    $stmt->execute($params);
    $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $usosPorTipo = mbtMapearUsoTipoes($pdo, array_column($tipos, 'ESCONTADOR'));
}

$form = [
    'escontador' => $editar['ESCONTADOR'] ?? '',
    'desces' => $editar['DESCES'] ?? '',
    'tipomov' => $editar['TIPOMOV'] ?? 'D',
    'grupobnc' => $editar['GRUPOBNC'] ?? '',
    'subgrupobnc' => $editar['SUBGRUPOBNC'] ?? '',
    'ordemtipo' => $editar['ORDEMTIPO'] ?? '',
    'pagtoem' => $editar['PAGTOEM'] ?? '',
    'contacontabil' => $editar['CONTACONTABIL'] ?? '',
    'classificadre' => $editar['CLASSIFICADRE'] ?? '',
    'relat_dre' => $editar['RELAT_DRE'] ?? 'N',
    'regdisab' => $editar['REGDISAB'] ?? 'N',
    'contrap_tipoes' => $editar['CONTRAP_TIPOES'] ?? '',
    'contrap_tipomov' => $editar['CONTRAP_TIPOMOV'] ?? '',
    'contrap_cbcontador' => $editar['CONTRAP_CBCONTADOR'] ?? '',
];

require '../../layout/header.php';
?>

<style>
.mbt-wrap { max-width: 1280px; margin: 0 auto; }
.mbt-hero { background:#123c69; color:#fff; border-radius:8px; padding:24px; display:flex; justify-content:space-between; gap:16px; align-items:center; }
.mbt-card { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:18px; margin-top:16px; }
.mbt-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
.mbt-field label { display:block; font-size:12px; font-weight:700; color:#495057; margin-bottom:4px; }
.mbt-field input, .mbt-field select, .mbt-field textarea { width:100%; border:1px solid #ced4da; border-radius:6px; padding:9px 10px; background:#fff; }
.mbt-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.mbt-table { width:100%; border-collapse:collapse; font-size:13px; }
.mbt-table th, .mbt-table td { border-bottom:1px solid #e9ecef; padding:9px; vertical-align:top; }
.mbt-table th { background:#f1f5f9; font-size:12px; text-transform:uppercase; color:#334155; white-space:nowrap; }
.mbt-pill { display:inline-flex; align-items:center; border-radius:999px; padding:3px 8px; font-size:12px; font-weight:700; }
.mbt-pill.d { background:#fee2e2; color:#991b1b; }
.mbt-pill.c { background:#dcfce7; color:#166534; }
.mbt-pill.link { background:#e0f2fe; color:#075985; }
.mbt-pill.off { background:#f1f5f9; color:#475569; }
.mbt-metrics { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-top:16px; }
.mbt-metric { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:14px; }
.mbt-metric small { display:block; color:#64748b; font-weight:700; }
.mbt-metric strong { font-size:22px; color:#0f172a; }
.mbt-help { border-left:4px solid #0d6efd; background:#f8fbff; padding:12px 14px; border-radius:6px; color:#334155; }
@media (max-width: 900px) {
    .mbt-hero { display:block; }
    .mbt-grid, .mbt-metrics { grid-template-columns:1fr; }
    .mbt-scroll { overflow-x:auto; }
    .mbt-table { min-width:1050px; }
}
</style>

<div class="mbt-wrap">
    <section class="mbt-hero">
        <div>
            <span class="badge text-bg-light mb-2">Mov/Baixa</span>
            <h1 class="h4 fw-bold mb-1">Cadastro de TipoES</h1>
            <p class="mb-0 opacity-75">Plano de tipos de movimentacao da empresa 2, com regra clara para investimento/contrapartida.</p>
        </div>
        <a href="menu_movimentacao_baixa.php" class="btn btn-outline-light">Voltar</a>
    </section>

    <?php if (!$permitido): ?>
        <div class="alert alert-warning mt-3">Voce nao tem permissao para acessar este cadastro.</div>
    <?php elseif ($empresaId !== 2): ?>
        <div class="alert alert-info mt-3">Cadastro disponivel somente para a empresa 2, pois esta empresa usa as tabelas espelhadas sem Firebird proprio.</div>
    <?php else: ?>
        <?php if ($mensagem): ?><div class="alert alert-success mt-3"><?= mbtH($mensagem) ?></div><?php endif; ?>
        <?php if ($erro): ?><div class="alert alert-danger mt-3"><?= mbtH($erro) ?></div><?php endif; ?>

        <section class="mbt-metrics">
            <div class="mbt-metric"><small>Total</small><strong><?= (int)$totais['qtd'] ?></strong></div>
            <div class="mbt-metric"><small>Ativos</small><strong><?= (int)$totais['ativos'] ?></strong></div>
            <div class="mbt-metric"><small>Inativos</small><strong><?= (int)$totais['inativos'] ?></strong></div>
            <div class="mbt-metric"><small>Com investimento/contrapartida</small><strong><?= (int)$totais['vinculados'] ?></strong></div>
        </section>

        <section class="mbt-card">
            <h2 class="h6 fw-bold mb-3"><?= $editar ? 'Editar TIPOES #' . (int)$editar['ESCONTADOR'] : 'Novo TIPOES' ?></h2>
            <form method="post">
                <input type="hidden" name="acao" value="salvar">
                <div class="mbt-grid">
                    <div class="mbt-field">
                        <label>Codigo</label>
                        <input type="number" name="escontador" value="<?= mbtH($form['escontador']) ?>" placeholder="Automatico se vazio">
                    </div>
                    <div class="mbt-field" style="grid-column:span 2;">
                        <label>Descricao</label>
                        <input type="text" name="desces" value="<?= mbtH($form['desces']) ?>" required>
                    </div>
                    <div class="mbt-field">
                        <label>Movimento</label>
                        <select name="tipomov" required>
                            <option value="D" <?= strtoupper((string)$form['tipomov']) === 'D' ? 'selected' : '' ?>>Debito</option>
                            <option value="C" <?= strtoupper((string)$form['tipomov']) === 'C' ? 'selected' : '' ?>>Credito</option>
                        </select>
                    </div>
                    <div class="mbt-field">
                        <label>Grupo BNC</label>
                        <input type="number" name="grupobnc" value="<?= mbtH($form['grupobnc']) ?>">
                    </div>
                    <div class="mbt-field">
                        <label>Subgrupo BNC</label>
                        <input type="number" name="subgrupobnc" value="<?= mbtH($form['subgrupobnc']) ?>">
                    </div>
                    <div class="mbt-field">
                        <label>Ordem</label>
                        <input type="number" name="ordemtipo" value="<?= mbtH($form['ordemtipo']) ?>">
                    </div>
                    <div class="mbt-field">
                        <label>Forma/Pagto em</label>
                        <input type="text" name="pagtoem" value="<?= mbtH($form['pagtoem']) ?>">
                    </div>
                    <div class="mbt-field">
                        <label>Conta contabil</label>
                        <input type="text" name="contacontabil" value="<?= mbtH($form['contacontabil']) ?>">
                    </div>
                    <div class="mbt-field">
                        <label>Classificacao DRE</label>
                        <input type="text" name="classificadre" value="<?= mbtH($form['classificadre']) ?>">
                    </div>
                    <div class="mbt-field">
                        <label>Relatorio DRE</label>
                        <select name="relat_dre">
                            <option value="N" <?= ($form['relat_dre'] ?? 'N') !== 'S' ? 'selected' : '' ?>>Nao</option>
                            <option value="S" <?= ($form['relat_dre'] ?? 'N') === 'S' ? 'selected' : '' ?>>Sim</option>
                        </select>
                    </div>
                    <div class="mbt-field">
                        <label>Situacao</label>
                        <select name="regdisab">
                            <option value="N" <?= ($form['regdisab'] ?? 'N') !== 'S' ? 'selected' : '' ?>>Ativo</option>
                            <option value="S" <?= ($form['regdisab'] ?? 'N') === 'S' ? 'selected' : '' ?>>Inativo</option>
                        </select>
                    </div>
                </div>

                <div class="mbt-help mt-3">
                    <strong>Regra de investimento/contrapartida:</strong>
                    quando este TIPOES for usado na baixa de um titulo, o sistema cria o lancamento principal na conta paga/recebida e, se houver TIPOES de contrapartida abaixo, cria automaticamente o segundo lancamento na conta de investimento/contrapartida.
                </div>

                <div class="mbt-grid mt-3">
                    <div class="mbt-field" style="grid-column:span 2;">
                        <label>TIPOES de contrapartida</label>
                        <select name="contrap_tipoes" id="contrap_tipoes">
                            <option value="">Sem contrapartida</option>
                            <?php foreach ($tiposReferencia as $tipoRef): ?>
                                <option value="<?= (int)$tipoRef['ESCONTADOR'] ?>" <?= (string)$form['contrap_tipoes'] === (string)$tipoRef['ESCONTADOR'] ? 'selected' : '' ?>>
                                    <?= mbtH($tipoRef['ESCONTADOR'] . ' - ' . $tipoRef['DESCES'] . ' (' . mbtDescricaoTipomov((string)$tipoRef['TIPOMOV']) . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mbt-field">
                        <label>D/C da contrapartida</label>
                        <select name="contrap_tipomov" id="contrap_tipomov">
                            <option value="">Automatico/informe</option>
                            <option value="D" <?= strtoupper((string)$form['contrap_tipomov']) === 'D' ? 'selected' : '' ?>>Debito</option>
                            <option value="C" <?= strtoupper((string)$form['contrap_tipomov']) === 'C' ? 'selected' : '' ?>>Credito</option>
                        </select>
                    </div>
                    <div class="mbt-field">
                        <label>Conta investimento/contrapartida</label>
                        <select name="contrap_cbcontador" id="contrap_cbcontador">
                            <option value="">Selecione se houver contrapartida</option>
                            <?php foreach ($contas as $conta): ?>
                                <option value="<?= (int)$conta['CBCONTADOR'] ?>" <?= (string)$form['contrap_cbcontador'] === (string)$conta['CBCONTADOR'] ? 'selected' : '' ?>>
                                    <?= mbtH(mbtNomeConta($conta) . ' (' . $conta['CBCONTADOR'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mbt-actions mt-3">
                    <button class="btn btn-primary">Salvar TIPOES</button>
                    <a href="tipoes.php" class="btn btn-outline-secondary">Novo/Limpar</a>
                </div>
            </form>
        </section>

        <section class="mbt-card">
            <h2 class="h6 fw-bold mb-3">Filtros</h2>
            <form method="get" class="mbt-grid">
                <div class="mbt-field">
                    <label>Codigo</label>
                    <input type="number" name="codigo" value="<?= mbtH($fCodigo) ?>">
                </div>
                <div class="mbt-field" style="grid-column:span 2;">
                    <label>Descricao / classificacao</label>
                    <input type="text" name="descricao" value="<?= mbtH($fDescricao) ?>">
                </div>
                <div class="mbt-field">
                    <label>D/C</label>
                    <select name="tipomov">
                        <option value="">Todos</option>
                        <option value="D" <?= $fTipomov === 'D' ? 'selected' : '' ?>>Debito</option>
                        <option value="C" <?= $fTipomov === 'C' ? 'selected' : '' ?>>Credito</option>
                    </select>
                </div>
                <div class="mbt-field">
                    <label>Grupo</label>
                    <input type="number" name="grupo" value="<?= mbtH($fGrupo) ?>">
                </div>
                <div class="mbt-field">
                    <label>Situacao</label>
                    <select name="situacao">
                        <option value="ativos" <?= $fSituacao === 'ativos' ? 'selected' : '' ?>>Ativos</option>
                        <option value="inativos" <?= $fSituacao === 'inativos' ? 'selected' : '' ?>>Inativos</option>
                        <option value="todos" <?= $fSituacao === 'todos' ? 'selected' : '' ?>>Todos</option>
                    </select>
                </div>
                <div class="mbt-field">
                    <label>Investimento/contrapartida</label>
                    <select name="investimento">
                        <option value="todos" <?= $fInvestimento === 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="vinculados" <?= $fInvestimento === 'vinculados' ? 'selected' : '' ?>>Com vinculo</option>
                        <option value="nao_vinculados" <?= $fInvestimento === 'nao_vinculados' ? 'selected' : '' ?>>Sem vinculo</option>
                    </select>
                </div>
                <div class="mbt-actions" style="align-self:end;">
                    <button class="btn btn-outline-primary">Filtrar</button>
                    <a href="tipoes.php" class="btn btn-outline-secondary">Limpar</a>
                </div>
            </form>
        </section>

        <section class="mbt-card">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
                <h2 class="h6 fw-bold mb-0">TipoES cadastrados</h2>
                <span class="text-muted small"><?= count($tipos) ?> registro(s) filtrado(s)</span>
            </div>
            <div class="mbt-scroll">
                <table class="mbt-table">
                    <thead>
                        <tr>
                            <th>Codigo</th>
                            <th>Descricao</th>
                            <th>D/C</th>
                            <th>Grupo</th>
                            <th>Investimento/contrapartida</th>
                            <th>Uso</th>
                            <th>Situacao</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tipos as $tipo): ?>
                            <?php
                                $uso = $usosPorTipo[(int)$tipo['ESCONTADOR']] ?? ['bnc' => 0, 'cp' => 0, 'cr' => 0, 'forn' => 0];
                                $contaContrap = trim((string)($tipo['contrap_titular'] ?? ''));
                                if ($contaContrap === '') {
                                    $contaContrap = trim((string)($tipo['contrap_descabrev'] ?? ''));
                                }
                                if ($contaContrap === '') {
                                    $contaContrap = trim((string)($tipo['contrap_numero'] ?? ''));
                                }
                            ?>
                            <tr>
                                <td><strong><?= (int)$tipo['ESCONTADOR'] ?></strong></td>
                                <td>
                                    <div class="fw-semibold"><?= mbtH($tipo['DESCES'] ?? '') ?></div>
                                    <?php if (!empty($tipo['PAGTOEM']) || !empty($tipo['CLASSIFICADRE'])): ?>
                                        <div class="text-muted small">
                                            <?= mbtH(trim(($tipo['PAGTOEM'] ?? '') . ' ' . ($tipo['CLASSIFICADRE'] ?? ''))) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="mbt-pill <?= strtoupper((string)$tipo['TIPOMOV']) === 'C' ? 'c' : 'd' ?>">
                                        <?= mbtH(mbtDescricaoTipomov((string)$tipo['TIPOMOV'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div>Grupo: <?= mbtH($tipo['GRUPOBNC'] ?? '-') ?></div>
                                    <div class="text-muted small">Subgrupo: <?= mbtH($tipo['SUBGRUPOBNC'] ?? '-') ?> | Ordem: <?= mbtH($tipo['ORDEMTIPO'] ?? '-') ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($tipo['CONTRAP_TIPOES'])): ?>
                                        <span class="mbt-pill link">Vinculado</span>
                                        <div class="mt-1">
                                            TIPOES <?= (int)$tipo['CONTRAP_TIPOES'] ?> - <?= mbtH($tipo['contrap_desc'] ?? '') ?>
                                        </div>
                                        <div class="text-muted small">
                                            <?= mbtH(mbtDescricaoTipomov((string)$tipo['CONTRAP_TIPOMOV'])) ?> na conta
                                            <?= (int)($tipo['CONTRAP_CBCONTADOR'] ?? 0) ?><?= $contaContrap !== '' ? ' - ' . mbtH($contaContrap) : '' ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="mbt-pill off">Sem vinculo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    BNC001: <?= (int)$uso['bnc'] ?><br>
                                    CP001: <?= (int)$uso['cp'] ?> | CR001: <?= (int)$uso['cr'] ?><br>
                                    Fornec.: <?= (int)$uso['forn'] ?>
                                </td>
                                <td>
                                    <?php if (($tipo['REGDISAB'] ?? 'N') === 'S'): ?>
                                        <span class="badge text-bg-secondary">Inativo</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-success">Ativo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="mbt-actions">
                                        <a href="tipoes.php?editar=<?= (int)$tipo['ESCONTADOR'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                        <form method="post" onsubmit="return confirm('Alterar a situacao deste TIPOES?');">
                                            <input type="hidden" name="acao" value="inativar">
                                            <input type="hidden" name="escontador" value="<?= (int)$tipo['ESCONTADOR'] ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">
                                                <?= ($tipo['REGDISAB'] ?? 'N') === 'S' ? 'Ativar' : 'Inativar' ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$tipos): ?>
                            <tr><td colspan="8" class="text-muted">Nenhum TIPOES encontrado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<script>
(function () {
    const contrapTipoes = document.getElementById('contrap_tipoes');
    const contrapTipomov = document.getElementById('contrap_tipomov');
    const contrapConta = document.getElementById('contrap_cbcontador');
    if (!contrapTipoes || !contrapTipomov || !contrapConta) {
        return;
    }
    function atualizarObrigatorios() {
        const exige = contrapTipoes.value !== '';
        contrapTipomov.required = exige;
        contrapConta.required = exige;
    }
    contrapTipoes.addEventListener('change', atualizarObrigatorios);
    atualizarObrigatorios();
})();
</script>

<?php require '../../layout/footer.php'; ?>
