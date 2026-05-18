<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

function garantirCamposContasPagar(PDO $pdo): void
{
    $campos = [
        'financeiro_verificado' => "ALTER TABLE armazem_cp001 ADD financeiro_verificado CHAR(1) NOT NULL DEFAULT 'N'",
        'financeiro_verificado_por' => "ALTER TABLE armazem_cp001 ADD financeiro_verificado_por INT NULL",
        'financeiro_verificado_em' => "ALTER TABLE armazem_cp001 ADD financeiro_verificado_em DATETIME NULL",
    ];

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'armazem_cp001'
          AND COLUMN_NAME = ?
    ");

    foreach ($campos as $campo => $sql) {
        $stmt->execute([$campo]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

garantirCamposContasPagar($pdo_master);

$fornecedor = trim($_GET['fornecedor'] ?? '');
$documento = trim($_GET['documento'] ?? '');
$tipoDocOrigem = trim($_GET['tipo_doc_origem'] ?? '');
$numDocOrigem = trim($_GET['num_doc_origem'] ?? '');
$tipoes = trim($_GET['tipoes'] ?? '');
$status = trim($_GET['status'] ?? '');
$verificado = trim($_GET['verificado'] ?? '');
$compraIni = trim($_GET['compra_ini'] ?? '');
$compraFim = trim($_GET['compra_fim'] ?? '');
$vencIni = trim($_GET['venc_ini'] ?? '');
$vencFim = trim($_GET['venc_fim'] ?? '');
$valorMin = trim($_GET['valor_min'] ?? '');
$valorMax = trim($_GET['valor_max'] ?? '');
$exportar = $_GET['exportar'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'verificar') {
    $cpcontador = (int)($_POST['cpcontador'] ?? 0);
    $novoStatus = ($_POST['verificado'] ?? 'N') === 'S' ? 'S' : 'N';

    if ($cpcontador > 0) {
        $stmt = $pdo_master->prepare("
            UPDATE armazem_cp001
            SET financeiro_verificado = ?,
                financeiro_verificado_por = ?,
                financeiro_verificado_em = CASE WHEN ? = 'S' THEN NOW() ELSE NULL END
            WHERE EMPRESA = ?
              AND CPCONTADOR = ?
        ");
        $stmt->execute([$novoStatus, $usuarioId, $novoStatus, $empresaId, $cpcontador]);
    }

    $query = $_GET ? '?' . http_build_query($_GET) : '';
    header('Location: contas_pagar.php' . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'verificar_lote') {
    $cpcontadores = $_POST['cpcontadores'] ?? [];
    $cpcontadores = array_values(array_unique(array_filter(array_map('intval', (array)$cpcontadores))));

    if (!empty($cpcontadores)) {
        $placeholders = implode(',', array_fill(0, count($cpcontadores), '?'));
        $stmt = $pdo_master->prepare("
            UPDATE armazem_cp001
            SET financeiro_verificado = 'S',
                financeiro_verificado_por = ?,
                financeiro_verificado_em = NOW()
            WHERE EMPRESA = ?
              AND CPCONTADOR IN ($placeholders)
        ");
        $stmt->execute(array_merge([$usuarioId, $empresaId], $cpcontadores));
    }

    $query = $_GET ? '?' . http_build_query($_GET) : '';
    header('Location: contas_pagar.php' . $query);
    exit;
}

$where = [
    'cp.EMPRESA = ?',
    "(cp.STATUS IS NULL OR cp.STATUS <> 'QT')",
    "COALESCE(cp.excluido_firebird, 'N') <> 'S'",
];
$params = [$empresaId];

if ($status !== '' && $status !== 'QT') {
    $where[] = 'cp.STATUS = ?';
    $params[] = $status;
}

if (in_array($verificado, ['S', 'N'], true)) {
    $where[] = "COALESCE(cp.financeiro_verificado, 'N') = ?";
    $params[] = $verificado;
}

if ($fornecedor !== '') {
    $where[] = "(f.NOME LIKE ? OR f.APELIDO LIKE ? OR cp.FCONTADOR LIKE ?)";
    $like = '%' . $fornecedor . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($documento !== '') {
    $where[] = "(cp.TITULO LIKE ? OR cp.NOTAFISCAL LIKE ? OR cp.NUMCH LIKE ? OR cp.IDENTIFICACAO LIKE ?)";
    $like = '%' . $documento . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($tipoDocOrigem !== '') {
    $where[] = 'cp.TIPODOCORIGEM = ?';
    $params[] = $tipoDocOrigem;
}

if ($numDocOrigem !== '') {
    $where[] = 'cp.NUMDOCORIGEM LIKE ?';
    $params[] = '%' . $numDocOrigem . '%';
}

if ($tipoes !== '' && ctype_digit($tipoes)) {
    $where[] = 'cp.TIPOES = ?';
    $params[] = (int)$tipoes;
}

if ($compraIni !== '') {
    $where[] = 'DATE(cp.DTCOMPRA) >= ?';
    $params[] = $compraIni;
}

if ($compraFim !== '') {
    $where[] = 'DATE(cp.DTCOMPRA) <= ?';
    $params[] = $compraFim;
}

if ($vencIni !== '') {
    $where[] = 'DATE(cp.DTVENC) >= ?';
    $params[] = $vencIni;
}

if ($vencFim !== '') {
    $where[] = 'DATE(cp.DTVENC) <= ?';
    $params[] = $vencFim;
}

if ($valorMin !== '' && is_numeric(str_replace(',', '.', $valorMin))) {
    $where[] = 'cp.VLRPARCELA >= ?';
    $params[] = (float)str_replace(',', '.', $valorMin);
}

if ($valorMax !== '' && is_numeric(str_replace(',', '.', $valorMax))) {
    $where[] = 'cp.VLRPARCELA <= ?';
    $params[] = (float)str_replace(',', '.', $valorMax);
}

$whereSql = implode("\n      AND ", $where);

$stmtResumo = $pdo_master->prepare("
    SELECT
        COUNT(*) AS qtd,
        COALESCE(SUM(cp.VLRPARCELA), 0) AS total_parcela,
        COALESCE(SUM(cp.VLRRESTANTE), 0) AS total_restante
    FROM armazem_cp001 cp
    LEFT JOIN armazem_cp003 f
        ON f.EMPRESA = cp.EMPRESA
       AND f.FCONTADOR = cp.FCONTADOR
    WHERE {$whereSql}
");
$stmtResumo->execute($params);
$resumo = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total_parcela' => 0, 'total_restante' => 0];

$stmt = $pdo_master->prepare("
    SELECT
        cp.CPCONTADOR,
        cp.DTCOMPRA,
        cp.FCONTADOR,
        COALESCE(f.NOME, f.APELIDO, CONCAT('Fornecedor ', cp.FCONTADOR)) AS fornecedor_nome,
        cp.TIPODOCORIGEM,
        cp.NUMDOCORIGEM,
        cp.TIPOES,
        COALESCE(NULLIF(cp.TITULO, ''), NULLIF(cp.NOTAFISCAL, ''), NULLIF(cp.IDENTIFICACAO, ''), NULLIF(cp.NUMCH, ''), '') AS documento,
        cp.VLRPARCELA,
        cp.VLRRESTANTE,
        cp.DTVENC,
        cp.STATUS,
        COALESCE(cp.financeiro_verificado, 'N') AS financeiro_verificado
    FROM armazem_cp001 cp
    LEFT JOIN armazem_cp003 f
        ON f.EMPRESA = cp.EMPRESA
       AND f.FCONTADOR = cp.FCONTADOR
    WHERE {$whereSql}
    ORDER BY cp.DTVENC ASC, cp.DTCOMPRA ASC, cp.CPCONTADOR ASC
    " . (in_array($exportar, ['excel', 'pdf'], true) ? "" : "LIMIT 1000") . "
");
$stmt->execute($params);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmtTiposDoc = $pdo_master->prepare("
    SELECT DISTINCT TIPODOCORIGEM
    FROM armazem_cp001
    WHERE EMPRESA = ?
      AND TIPODOCORIGEM IS NOT NULL
      AND TIPODOCORIGEM <> ''
    ORDER BY TIPODOCORIGEM
");
$stmtTiposDoc->execute([$empresaId]);
$tiposDoc = $stmtTiposDoc->fetchAll(PDO::FETCH_COLUMN);

$stmtStatus = $pdo_master->prepare("
    SELECT DISTINCT STATUS
    FROM armazem_cp001
    WHERE EMPRESA = ?
      AND STATUS IS NOT NULL
      AND STATUS <> ''
      AND STATUS <> 'QT'
    ORDER BY STATUS
");
$stmtStatus->execute([$empresaId]);
$statusOpcoes = $stmtStatus->fetchAll(PDO::FETCH_COLUMN);

function moedaContasPagar($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function dataContasPagar($valor): string
{
    return $valor ? date('d/m/Y', strtotime($valor)) : '';
}

function queryContasPagar(array $extra = []): string
{
    $params = $_GET;
    unset($params['exportar']);
    foreach ($extra as $chave => $valor) {
        if ($valor === null) {
            unset($params[$chave]);
        } else {
            $params[$chave] = $valor;
        }
    }
    return http_build_query($params);
}

function escapeExcelContasPagar($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function textoPdfContasPagar($valor, int $limite = 0): string
{
    $texto = preg_replace('/\s+/', ' ', trim((string)$valor));
    if ($limite > 0) {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($texto, 'UTF-8') > $limite) {
                $texto = mb_substr($texto, 0, max(0, $limite - 3), 'UTF-8') . '...';
            }
        } elseif (strlen($texto) > $limite) {
            $texto = substr($texto, 0, max(0, $limite - 3)) . '...';
        }
    }

    $convertido = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto);
    return $convertido === false ? $texto : $convertido;
}

function escaparPdfContasPagar(string $texto): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

function comandoTextoPdfContasPagar(float $x, float $y, int $tamanho, string $texto, bool $negrito = false): string
{
    $fonte = $negrito ? 'F2' : 'F1';
    return "BT /{$fonte} {$tamanho} Tf 1 0 0 1 " . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . escaparPdfContasPagar($texto) . ") Tj ET\n";
}

function gerarPdfContasPagar(string $titulo, array $metadados, array $colunas, array $linhas, string $arquivo): void
{
    $largura = 842;
    $altura = 595;
    $margem = 28;
    $linhaAltura = 16;
    $topoTabela = $altura - 112;
    $rodapeY = 22;
    $linhasPorPagina = max(1, (int)floor(($topoTabela - 44) / $linhaAltura));
    $paginas = [];
    $totalPaginas = max(1, (int)ceil(count($linhas) / $linhasPorPagina));

    for ($pagina = 0; $pagina < $totalPaginas; $pagina++) {
        $conteudo = '';
        $y = $altura - 40;
        $conteudo .= comandoTextoPdfContasPagar($margem, $y, 15, textoPdfContasPagar($titulo, 90), true);
        $y -= 18;

        foreach ($metadados as $meta) {
            $conteudo .= comandoTextoPdfContasPagar($margem, $y, 8, textoPdfContasPagar($meta, 155));
            $y -= 11;
        }

        $conteudo .= "0.90 0.90 0.90 rg {$margem} " . ($topoTabela - 4) . ' ' . ($largura - ($margem * 2)) . " 18 re f\n0 g\n";
        $x = $margem + 3;
        foreach ($colunas as $coluna) {
            $conteudo .= comandoTextoPdfContasPagar($x, $topoTabela + 2, 8, textoPdfContasPagar($coluna['titulo'], $coluna['limite'] ?? 20), true);
            $x += $coluna['largura'];
        }

        $linhasPagina = array_slice($linhas, $pagina * $linhasPorPagina, $linhasPorPagina);
        $y = $topoTabela - 16;
        foreach ($linhasPagina as $linha) {
            $x = $margem + 3;
            foreach ($colunas as $indice => $coluna) {
                $conteudo .= comandoTextoPdfContasPagar($x, $y, 8, textoPdfContasPagar($linha[$indice] ?? '', $coluna['limite'] ?? 20));
                $x += $coluna['largura'];
            }
            $y -= $linhaAltura;
        }

        $conteudo .= comandoTextoPdfContasPagar($margem, $rodapeY, 8, textoPdfContasPagar('Gerado em ' . date('d/m/Y H:i') . ' - Pagina ' . ($pagina + 1) . ' de ' . $totalPaginas));
        $paginas[] = $conteudo;
    }

    $objetos = [
        1 => "<< /Type /Catalog /Pages 2 0 R >>",
        2 => '',
        3 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
        4 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>",
    ];
    $idsPaginas = [];
    $proximoId = 5;

    foreach ($paginas as $conteudo) {
        $conteudoId = $proximoId++;
        $paginaId = $proximoId++;
        $objetos[$conteudoId] = "<< /Length " . strlen($conteudo) . " >>\nstream\n{$conteudo}endstream";
        $objetos[$paginaId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$largura} {$altura}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$conteudoId} 0 R >>";
        $idsPaginas[] = $paginaId;
    }

    $objetos[2] = "<< /Type /Pages /Kids [" . implode(' ', array_map(static fn($id) => "{$id} 0 R", $idsPaginas)) . "] /Count " . count($idsPaginas) . " >>";
    ksort($objetos);

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objetos as $id => $objeto) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$objeto}\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objetos) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objetos); $i++) {
        $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $arquivo . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if (in_array($exportar, ['excel', 'pdf'], true)) {
    $nomeArquivo = 'contas_pagar_' . date('Ymd_His');
    $tituloExportacao = 'Contas a Pagar';
    $metadados = [
        'Fornecedor: ' . ($fornecedor ?: 'Todos'),
        'Documento: ' . ($documento ?: 'Todos'),
        'Compra: ' . ($compraIni ?: 'inicio') . ' ate ' . ($compraFim ?: 'fim'),
        'Vencimento: ' . ($vencIni ?: 'inicio') . ' ate ' . ($vencFim ?: 'fim'),
        'Verificado: ' . ($verificado ?: 'Todos'),
    ];

    if ($exportar === 'pdf') {
        $colunas = [
            ['titulo' => 'Compra', 'largura' => 58, 'limite' => 10],
            ['titulo' => 'Cod.', 'largura' => 42, 'limite' => 8],
            ['titulo' => 'Fornecedor', 'largura' => 160, 'limite' => 30],
            ['titulo' => 'Orig.', 'largura' => 45, 'limite' => 8],
            ['titulo' => 'Num. Orig.', 'largura' => 65, 'limite' => 12],
            ['titulo' => 'TipoES', 'largura' => 42, 'limite' => 6],
            ['titulo' => 'Documento', 'largura' => 110, 'limite' => 20],
            ['titulo' => 'Parcela', 'largura' => 78, 'limite' => 15],
            ['titulo' => 'Restante', 'largura' => 78, 'limite' => 15],
            ['titulo' => 'Venc.', 'largura' => 58, 'limite' => 10],
            ['titulo' => 'Verif.', 'largura' => 42, 'limite' => 8],
        ];
        $linhas = array_map(static function ($registro): array {
            return [
                dataContasPagar($registro['DTCOMPRA']),
                (string)(int)$registro['FCONTADOR'],
                (string)$registro['fornecedor_nome'],
                (string)$registro['TIPODOCORIGEM'],
                (string)$registro['NUMDOCORIGEM'],
                (string)$registro['TIPOES'],
                (string)$registro['documento'],
                moedaContasPagar($registro['VLRPARCELA']),
                moedaContasPagar($registro['VLRRESTANTE']),
                dataContasPagar($registro['DTVENC']),
                ($registro['financeiro_verificado'] ?? 'N') === 'S' ? 'Sim' : 'Nao',
            ];
        }, $registros);
        gerarPdfContasPagar($tituloExportacao, $metadados, $colunas, $linhas, $nomeArquivo);
    }

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.xls"');
    echo "\xEF\xBB\xBF";
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
        <meta charset="utf-8">
        <title><?= escapeExcelContasPagar($tituloExportacao) ?></title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 12px; color: #111; }
            h1 { font-size: 18px; margin-bottom: 4px; }
            .meta { color: #555; margin-bottom: 14px; }
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #999; padding: 6px; }
            th { background: #d9e8ff; text-align: left; }
            .num { text-align: right; }
        </style>
    </head>
    <body>
        <h1><?= escapeExcelContasPagar($tituloExportacao) ?></h1>
        <div class="meta"><?= escapeExcelContasPagar(implode(' | ', $metadados)) ?></div>
        <table>
            <thead>
                <tr>
                    <th>Data da compra</th>
                    <th>Codigo fornecedor</th>
                    <th>Fornecedor</th>
                    <th>TipoDocOrigem</th>
                    <th>NumDocOrigem</th>
                    <th>TipoES</th>
                    <th>Documento</th>
                    <th class="num">Valor parcela</th>
                    <th class="num">Valor restante</th>
                    <th>Vencimento</th>
                    <th>Status</th>
                    <th>Verificado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registros as $registro): ?>
                    <tr>
                        <td><?= escapeExcelContasPagar(dataContasPagar($registro['DTCOMPRA'])) ?></td>
                        <td><?= (int)$registro['FCONTADOR'] ?></td>
                        <td><?= escapeExcelContasPagar($registro['fornecedor_nome']) ?></td>
                        <td><?= escapeExcelContasPagar($registro['TIPODOCORIGEM']) ?></td>
                        <td><?= escapeExcelContasPagar($registro['NUMDOCORIGEM']) ?></td>
                        <td><?= escapeExcelContasPagar($registro['TIPOES']) ?></td>
                        <td><?= escapeExcelContasPagar($registro['documento']) ?></td>
                        <td class="num"><?= number_format((float)$registro['VLRPARCELA'], 2, ',', '.') ?></td>
                        <td class="num"><?= number_format((float)$registro['VLRRESTANTE'], 2, ',', '.') ?></td>
                        <td><?= escapeExcelContasPagar(dataContasPagar($registro['DTVENC'])) ?></td>
                        <td><?= escapeExcelContasPagar($registro['STATUS']) ?></td>
                        <td><?= ($registro['financeiro_verificado'] ?? 'N') === 'S' ? 'Sim' : 'Nao' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

require '../../layout/header.php';
?>

<style>
    .financeiro-grid {
        font-size: .9rem;
    }

    .financeiro-grid th {
        white-space: nowrap;
        font-size: .78rem;
        text-transform: uppercase;
        vertical-align: middle;
    }

    .financeiro-grid td {
        vertical-align: middle;
    }

    .financeiro-grid .col-date {
        width: 86px;
        white-space: nowrap;
    }

    .financeiro-grid .col-code {
        width: 70px;
        white-space: nowrap;
    }

    .financeiro-grid .col-small {
        width: 58px;
        white-space: nowrap;
    }

    .financeiro-grid .col-doc {
        width: 120px;
    }

    .financeiro-grid .col-money {
        width: 118px;
        white-space: nowrap;
    }

    .financeiro-grid .col-action {
        width: 96px;
        white-space: nowrap;
    }

    .financeiro-grid .fornecedor-principal,
    .financeiro-grid .documento-principal {
        line-height: 1.15;
    }

    @media (max-width: 575.98px) {
        .financeiro-grid {
            border-collapse: separate;
            border-spacing: 0 .75rem;
        }

        .financeiro-grid thead {
            display: none;
        }

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

        .financeiro-grid td:last-child {
            border-bottom: 0;
        }

        .financeiro-grid td::before {
            content: attr(data-label);
            flex: 0 0 36%;
            color: #64748b;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            font-size: .72rem;
            line-height: 1.2;
        }

        .financeiro-grid td[data-label="Fornecedor"],
        .financeiro-grid td[data-label="Documento"] {
            display: block;
            text-align: left !important;
        }

        .financeiro-grid td[data-label="Fornecedor"]::before,
        .financeiro-grid td[data-label="Documento"]::before {
            display: block;
            margin-bottom: .25rem;
        }

        .financeiro-grid .btn {
            width: 100%;
        }
    }
</style>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Financeiro</span>
                <h1 class="h3 fw-bold mb-2">Contas a Pagar</h1>
                <p class="text-muted mb-0">Parcelas em aberto por fornecedor, origem, documento, valor e vencimento.</p>
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
                <label class="form-label">Fornecedor</label>
                <input type="text" name="fornecedor" class="form-control" value="<?= htmlspecialchars($fornecedor) ?>" placeholder="Nome, apelido ou codigo">
            </div>
            <div class="col-md-4">
                <label class="form-label">Documento</label>
                <input type="text" name="documento" class="form-control" value="<?= htmlspecialchars($documento) ?>" placeholder="Titulo, NF, identificacao ou cheque">
            </div>
            <div class="col-md-2">
                <label class="form-label">TipoDocOrigem</label>
                <select name="tipo_doc_origem" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($tiposDoc as $tipo): ?>
                        <option value="<?= htmlspecialchars($tipo) ?>" <?= $tipoDocOrigem === $tipo ? 'selected' : '' ?>><?= htmlspecialchars($tipo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">NumDocOrigem</label>
                <input type="text" name="num_doc_origem" class="form-control" value="<?= htmlspecialchars($numDocOrigem) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">TipoES</label>
                <input type="number" name="tipoes" class="form-control" value="<?= htmlspecialchars($tipoes) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">Todos em aberto</option>
                    <?php foreach ($statusOpcoes as $opcaoStatus): ?>
                        <option value="<?= htmlspecialchars($opcaoStatus) ?>" <?= $status === $opcaoStatus ? 'selected' : '' ?>><?= htmlspecialchars($opcaoStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Verificado</label>
                <select name="verificado" class="form-select">
                    <option value="">Todos</option>
                    <option value="N" <?= $verificado === 'N' ? 'selected' : '' ?>>Nao</option>
                    <option value="S" <?= $verificado === 'S' ? 'selected' : '' ?>>Sim</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Compra inicial</label>
                <input type="date" name="compra_ini" class="form-control" value="<?= htmlspecialchars($compraIni) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Compra final</label>
                <input type="date" name="compra_fim" class="form-control" value="<?= htmlspecialchars($compraFim) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Vencimento inicial</label>
                <input type="date" name="venc_ini" class="form-control" value="<?= htmlspecialchars($vencIni) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Vencimento final</label>
                <input type="date" name="venc_fim" class="form-control" value="<?= htmlspecialchars($vencFim) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Valor minimo</label>
                <input type="text" name="valor_min" class="form-control" value="<?= htmlspecialchars($valorMin) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Valor maximo</label>
                <input type="text" name="valor_max" class="form-control" value="<?= htmlspecialchars($valorMax) ?>">
            </div>
            <div class="col-12 d-flex gap-2 justify-content-end">
                <a href="contas_pagar.php" class="btn btn-outline-secondary">Limpar</a>
                <a href="contas_pagar.php?<?= htmlspecialchars(queryContasPagar(['exportar' => 'excel'])) ?>" class="btn btn-success">Excel</a>
                <a href="contas_pagar.php?<?= htmlspecialchars(queryContasPagar(['exportar' => 'pdf'])) ?>" class="btn btn-danger">PDF</a>
                <button type="submit" class="btn btn-primary">Filtrar</button>
            </div>
        </div>
    </form>
</section>

<section class="mb-3">
    <div class="row g-3">
        <div class="col-md-4">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Registros filtrados</div>
                <div class="h5 fw-bold mb-0"><?= (int)$resumo['qtd'] ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Total das parcelas</div>
                <div class="h5 fw-bold mb-0"><?= moedaContasPagar($resumo['total_parcela']) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Total restante</div>
                <div class="h5 fw-bold mb-0"><?= moedaContasPagar($resumo['total_restante']) ?></div>
            </div>
        </div>
    </div>
</section>

<section>
    <div class="bg-white border rounded-2 shadow-sm overflow-hidden">
        <div class="d-flex flex-wrap justify-content-start gap-2 p-3 border-bottom financeiro-toolbar">
            <button type="button" class="btn btn-sm btn-outline-primary" id="marcarTodos">Marcar todos</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="desmarcarTodos">Desmarcar todos</button>
            <button type="button" class="btn btn-sm btn-success" id="marcarSelecionadosVerificados">Marcar selecionados como verificados</button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 financeiro-grid">
                <thead class="table-primary">
                    <tr>
                        <th class="text-center col-check">Sel.</th>
                        <th class="col-date">Compra</th>
                        <th class="col-code">Cod.</th>
                        <th>Fornecedor</th>
                        <th class="col-small">Orig.</th>
                        <th class="col-doc">Num. Orig.</th>
                        <th class="col-small">TipoES</th>
                        <th>Documento</th>
                        <th class="text-end col-money">Parcela</th>
                        <th class="text-end col-money">Restante</th>
                        <th class="col-date">Venc.</th>
                        <th class="col-action">Verif.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registros as $registro): ?>
                        <tr>
                            <td data-label="Sel." class="text-center col-check">
                                <input
                                    type="checkbox"
                                    class="form-check-input js-selecionar"
                                    value="<?= (int)$registro['CPCONTADOR'] ?>"
                                >
                            </td>
                            <td data-label="Compra" class="col-date"><?= dataContasPagar($registro['DTCOMPRA']) ?></td>
                            <td data-label="Cod." class="fw-semibold col-code"><?= (int)$registro['FCONTADOR'] ?></td>
                            <td data-label="Fornecedor">
                                <div class="fw-semibold fornecedor-principal"><?= htmlspecialchars($registro['fornecedor_nome']) ?></div>
                            </td>
                            <td data-label="Orig." class="col-small"><?= htmlspecialchars((string)$registro['TIPODOCORIGEM']) ?></td>
                            <td data-label="Num. Orig." class="col-doc"><?= htmlspecialchars((string)$registro['NUMDOCORIGEM']) ?></td>
                            <td data-label="TipoES" class="col-small"><?= htmlspecialchars((string)$registro['TIPOES']) ?></td>
                            <td data-label="Documento">
                                <div class="fw-semibold documento-principal"><?= htmlspecialchars((string)$registro['documento']) ?></div>
                                <div class="small text-muted">CP: <?= (int)$registro['CPCONTADOR'] ?> | Status: <?= htmlspecialchars((string)($registro['STATUS'] ?: 'SEM STATUS')) ?></div>
                            </td>
                            <td data-label="Parcela" class="text-end fw-semibold col-money"><?= moedaContasPagar($registro['VLRPARCELA']) ?></td>
                            <td data-label="Restante" class="text-end col-money"><?= moedaContasPagar($registro['VLRRESTANTE']) ?></td>
                            <td data-label="Venc." class="col-date"><?= dataContasPagar($registro['DTVENC']) ?></td>
                            <td data-label="Verif." class="col-action">
                                <?php $verificadoRegistro = ($registro['financeiro_verificado'] ?? 'N') === 'S'; ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="acao" value="verificar">
                                    <input type="hidden" name="cpcontador" value="<?= (int)$registro['CPCONTADOR'] ?>">
                                    <input type="hidden" name="verificado" value="<?= $verificadoRegistro ? 'N' : 'S' ?>">
                                    <button type="submit" class="btn btn-sm <?= $verificadoRegistro ? 'btn-success' : 'btn-outline-secondary' ?>">
                                        <?= $verificadoRegistro ? 'Verificado' : 'Marcar' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($registros)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted py-4">Nenhum titulo encontrado com os filtros informados.</td>
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

<form method="POST" id="formVerificarLote" class="d-none">
    <input type="hidden" name="acao" value="verificar_lote">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checks = document.querySelectorAll('.js-selecionar');
    const marcarTodos = document.getElementById('marcarTodos');
    const desmarcarTodos = document.getElementById('desmarcarTodos');
    const marcarSelecionadosVerificados = document.getElementById('marcarSelecionadosVerificados');
    const formVerificarLote = document.getElementById('formVerificarLote');

    if (marcarTodos) {
        marcarTodos.addEventListener('click', function () {
            checks.forEach(function (check) {
                check.checked = true;
            });
        });
    }

    if (desmarcarTodos) {
        desmarcarTodos.addEventListener('click', function () {
            checks.forEach(function (check) {
                check.checked = false;
            });
        });
    }

    if (marcarSelecionadosVerificados && formVerificarLote) {
        marcarSelecionadosVerificados.addEventListener('click', function () {
            const selecionados = Array.from(checks).filter(function (check) {
                return check.checked;
            });

            if (selecionados.length === 0) {
                alert('Selecione ao menos um titulo.');
                return;
            }

            if (!confirm('Marcar os titulos selecionados como verificados?')) {
                return;
            }

            formVerificarLote.querySelectorAll('input[name="cpcontadores[]"]').forEach(function (input) {
                input.remove();
            });

            selecionados.forEach(function (check) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'cpcontadores[]';
                input.value = check.value;
                formVerificarLote.appendChild(input);
            });

            formVerificarLote.submit();
        });
    }
});
</script>

<?php require '../../layout/footer.php'; ?>
