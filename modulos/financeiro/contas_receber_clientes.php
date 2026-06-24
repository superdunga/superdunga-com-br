<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$cmClientes = 9;
$carteira = ($_GET['carteira'] ?? 'clientes') === 'recebiveis' ? 'recebiveis' : 'clientes';
$isRecebiveis = $carteira === 'recebiveis';
$tituloCarteira = $isRecebiveis ? 'Recebiveis' : 'Clientes';
$descricaoCarteira = $isRecebiveis
    ? 'Titulos em aberto com CMCONTADOR diferente de 9.'
    : 'Titulos em aberto de clientes com CMCONTADOR 9.';

function garantirCamposContasReceberClientes(PDO $pdo): void
{
    $campos = [
        'financeiro_verificado' => "ALTER TABLE armazem_cr001 ADD financeiro_verificado CHAR(1) NOT NULL DEFAULT 'N'",
        'financeiro_verificado_por' => "ALTER TABLE armazem_cr001 ADD financeiro_verificado_por INT NULL",
        'financeiro_verificado_em' => "ALTER TABLE armazem_cr001 ADD financeiro_verificado_em DATETIME NULL",
    ];

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'armazem_cr001'
          AND COLUMN_NAME = ?
    ");

    foreach ($campos as $campo => $sql) {
        $stmt->execute([$campo]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

garantirCamposContasReceberClientes($pdo_master);

$cliente = trim($_GET['cliente'] ?? '');
$cmFiltro = trim($_GET['cmcontador'] ?? '');
$vencInicio = trim($_GET['venc_ini'] ?? '');
$vencFim = trim($_GET['venc_fim'] ?? '');
$verificado = trim($_GET['verificado'] ?? '');
$visaoPadrao = 'analitico';
$visao = ($_GET['visao'] ?? $visaoPadrao) === 'sintetico' ? 'sintetico' : 'analitico';
$exportar = $_GET['exportar'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'verificar') {
    $crcontador = (int)($_POST['crcontador'] ?? 0);
    $novoStatus = ($_POST['verificado'] ?? 'N') === 'S' ? 'S' : 'N';

    if ($crcontador > 0) {
        $stmt = $pdo_master->prepare("
            UPDATE armazem_cr001
            SET financeiro_verificado = ?,
                financeiro_verificado_por = ?,
                financeiro_verificado_em = CASE WHEN ? = 'S' THEN NOW() ELSE NULL END
            WHERE EMPRESA = ?
              AND CRCONTADOR = ?
        ");
        $stmt->execute([$novoStatus, $usuarioId, $novoStatus, $empresaId, $crcontador]);
    }

    $query = $_GET ? '?' . http_build_query($_GET) : '';
    header('Location: contas_receber_clientes.php' . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'verificar_lote') {
    $crcontadores = $_POST['crcontadores'] ?? [];
    $crcontadores = array_values(array_unique(array_filter(array_map('intval', (array)$crcontadores))));

    if (!empty($crcontadores)) {
        $placeholders = implode(',', array_fill(0, count($crcontadores), '?'));
        $stmt = $pdo_master->prepare("
            UPDATE armazem_cr001
            SET financeiro_verificado = 'S',
                financeiro_verificado_por = ?,
                financeiro_verificado_em = NOW()
            WHERE EMPRESA = ?
              AND CRCONTADOR IN ($placeholders)
        ");
        $stmt->execute(array_merge([$usuarioId, $empresaId], $crcontadores));
    }

    $query = $_GET ? '?' . http_build_query($_GET) : '';
    header('Location: contas_receber_clientes.php' . $query);
    exit;
}

$where = [
    'c.EMPRESA = ?',
    "(c.STATUS IS NULL OR c.STATUS <> 'QT')",
    "COALESCE(c.excluido_firebird, 'N') <> 'S'",
];
$params = [$empresaId];

if ($isRecebiveis) {
    if ($cmFiltro !== '' && ctype_digit($cmFiltro)) {
        $where[] = 'c.CMCONTADOR = ?';
        $params[] = (int)$cmFiltro;
    } else {
        $where[] = 'c.CMCONTADOR <> ?';
        $params[] = $cmClientes;
    }
} else {
    $where[] = 'c.CMCONTADOR = ?';
    $params[] = $cmClientes;
}

if ($cliente !== '') {
    $where[] = "(cli.NOME LIKE ? OR cli.APELIDO LIKE ? OR c.CLICONTADOR LIKE ?)";
    $like = '%' . $cliente . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($vencInicio !== '') {
    $where[] = 'DATE(c.DTVENC) >= ?';
    $params[] = $vencInicio;
}

if ($vencFim !== '') {
    $where[] = 'DATE(c.DTVENC) <= ?';
    $params[] = $vencFim;
}

if (in_array($verificado, ['S', 'N'], true)) {
    $where[] = "COALESCE(c.financeiro_verificado, 'N') = ?";
    $params[] = $verificado;
}

$whereSql = implode("\n      AND ", $where);

$registros = [];
$sintetico = [];
$resumo = ['qtd' => 0, 'total_parcela' => 0, 'total_restante' => 0];

if ($visao === 'sintetico') {
    $stmtSintetico = $pdo_master->prepare("
        SELECT
            c.CLICONTADOR,
            COALESCE(cli.NOME, cli.APELIDO, CONCAT('Cliente ', c.CLICONTADOR)) AS nome_cliente,
            COUNT(*) AS qtd,
            COALESCE(SUM(c.VLRPARCELA), 0) AS total_parcela,
            COALESCE(SUM(c.VLRRESTANTE), 0) AS total_restante
        FROM armazem_cr001 c
        LEFT JOIN armazem_cr002 cli
            ON cli.EMPRESA = c.EMPRESA
           AND cli.CLICONTADOR = c.CLICONTADOR
        WHERE {$whereSql}
        GROUP BY c.CLICONTADOR, nome_cliente
        ORDER BY total_restante DESC, nome_cliente ASC
    ");
    $stmtSintetico->execute($params);
    $sintetico = $stmtSintetico->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sintetico as $linhaSintetica) {
        $resumo['qtd'] += (int)$linhaSintetica['qtd'];
        $resumo['total_parcela'] += (float)$linhaSintetica['total_parcela'];
        $resumo['total_restante'] += (float)$linhaSintetica['total_restante'];
    }
} else {
    $stmtResumo = $pdo_master->prepare("
        SELECT
            COUNT(*) AS qtd,
            COALESCE(SUM(c.VLRPARCELA), 0) AS total_parcela,
            COALESCE(SUM(c.VLRRESTANTE), 0) AS total_restante
        FROM armazem_cr001 c
        LEFT JOIN armazem_cr002 cli
            ON cli.EMPRESA = c.EMPRESA
           AND cli.CLICONTADOR = c.CLICONTADOR
        WHERE {$whereSql}
    ");
    $stmtResumo->execute($params);
    $resumo = $stmtResumo->fetch(PDO::FETCH_ASSOC) ?: $resumo;

    $stmt = $pdo_master->prepare("
        SELECT
            c.CRCONTADOR,
            c.CLICONTADOR,
            COALESCE(cli.NOME, cli.APELIDO, '') AS nome_cliente,
            c.CMCONTADOR,
            c.DTVENC,
            c.DTEMISSAO,
            c.VLRPARCELA,
            c.VLRRESTANTE,
            c.VLRPAGO,
            c.STATUS,
            c.NUMDOCORIGEM,
            COALESCE(c.financeiro_verificado, 'N') AS financeiro_verificado
        FROM armazem_cr001 c
        LEFT JOIN armazem_cr002 cli
            ON cli.EMPRESA = c.EMPRESA
           AND cli.CLICONTADOR = c.CLICONTADOR
        WHERE {$whereSql}
        ORDER BY c.DTVENC ASC, c.CRCONTADOR ASC
    ");
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$itensPorVenda = [];
$vendasIds = array_values(array_unique(array_filter(array_map(static function (array $registro): int {
    return (int)($registro['NUMDOCORIGEM'] ?? 0);
}, $registros))));

if ($visao === 'analitico' && !empty($vendasIds) && !in_array($exportar, ['excel', 'pdf'], true)) {
    $placeholdersVendas = implode(',', array_fill(0, count($vendasIds), '?'));
    $stmtItensVenda = $pdo_master->prepare("
        SELECT
            i.ITEMVENDACONTADOR,
            i.VENDACONTA,
            i.PRODUTO,
            i.QTDE,
            i.VALOR,
            i.TOTPROD,
            p.CODPRODUTO,
            p.DESCPRODUTO,
            p.UNIDADE
        FROM armazem_est008 i
        LEFT JOIN armazem_est004 p
            ON p.EMPRESA = i.EMPRESA
           AND p.CONTAPRODUTO = i.PRODUTO
        WHERE i.EMPRESA = ?
          AND i.ITEMVENDACONTADOR IN ($placeholdersVendas)
          AND COALESCE(i.CANCELADO, 'N') <> 'S'
        ORDER BY i.ITEMVENDACONTADOR ASC, i.VENDACONTA ASC
    ");
    $stmtItensVenda->execute(array_merge([$empresaId], $vendasIds));

    while ($itemVenda = $stmtItensVenda->fetch(PDO::FETCH_ASSOC)) {
        $itensPorVenda[(int)$itemVenda['ITEMVENDACONTADOR']][] = $itemVenda;
    }
}

function moedaFinanceiroClientes($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function dataFinanceiroClientes($valor): string
{
    return $valor ? date('d/m/Y', strtotime($valor)) : '';
}

function queryFinanceiroClientes(array $extra = []): string
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

function escapeExcelFinanceiro($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function textoPdfFinanceiro($valor, int $limite = 0): string
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

function escaparPdfFinanceiro(string $texto): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

function comandoTextoPdfFinanceiro(float $x, float $y, int $tamanho, string $texto, bool $negrito = false): string
{
    $fonte = $negrito ? 'F2' : 'F1';
    return "BT /{$fonte} {$tamanho} Tf 1 0 0 1 " . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . escaparPdfFinanceiro($texto) . ") Tj ET\n";
}

function gerarPdfFinanceiroClientes(string $titulo, array $metadados, array $colunas, array $linhas, string $arquivo, string $orientacao = 'portrait'): void
{
    $largura = $orientacao === 'landscape' ? 842 : 595;
    $altura = $orientacao === 'landscape' ? 595 : 842;
    $margem = 28;
    $linhaAltura = 16;
    $topoTabela = $altura - 110;
    $rodapeY = 22;
    $linhasPorPagina = max(1, (int)floor(($topoTabela - 44) / $linhaAltura));
    $paginas = [];
    $totalLinhas = count($linhas);
    $totalPaginas = max(1, (int)ceil($totalLinhas / $linhasPorPagina));

    for ($pagina = 0; $pagina < $totalPaginas; $pagina++) {
        $conteudo = '';
        $y = $altura - 40;
        $conteudo .= comandoTextoPdfFinanceiro($margem, $y, 15, textoPdfFinanceiro($titulo, 90), true);
        $y -= 18;

        foreach ($metadados as $meta) {
            $conteudo .= comandoTextoPdfFinanceiro($margem, $y, 8, textoPdfFinanceiro($meta, 150));
            $y -= 11;
        }

        $conteudo .= "0.90 0.90 0.90 rg {$margem} " . ($topoTabela - 4) . ' ' . ($largura - ($margem * 2)) . " 18 re f\n0 g\n";
        $x = $margem + 3;
        foreach ($colunas as $coluna) {
            $conteudo .= comandoTextoPdfFinanceiro($x, $topoTabela + 2, 8, textoPdfFinanceiro($coluna['titulo'], $coluna['limite'] ?? 20), true);
            $x += $coluna['largura'];
        }

        $inicio = $pagina * $linhasPorPagina;
        $linhasPagina = array_slice($linhas, $inicio, $linhasPorPagina);
        $y = $topoTabela - 16;

        foreach ($linhasPagina as $linha) {
            $x = $margem + 3;
            foreach ($colunas as $indice => $coluna) {
                $valor = $linha[$indice] ?? '';
                $limite = $coluna['limite'] ?? max(8, (int)floor($coluna['largura'] / 4));
                $conteudo .= comandoTextoPdfFinanceiro($x, $y, 8, textoPdfFinanceiro($valor, $limite));
                $x += $coluna['largura'];
            }
            $y -= $linhaAltura;
        }

        $conteudo .= comandoTextoPdfFinanceiro($margem, $rodapeY, 8, textoPdfFinanceiro('Gerado em ' . date('d/m/Y H:i') . ' - Pagina ' . ($pagina + 1) . ' de ' . $totalPaginas));
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

    $kids = implode(' ', array_map(static function ($id): string {
        return "{$id} 0 R";
    }, $idsPaginas));
    $objetos[2] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($idsPaginas) . " >>";

    ksort($objetos);
    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objetos as $id => $objeto) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$objeto}\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objetos) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
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

if (in_array($exportar, ['excel', 'pdf', 'pdf_itens'], true)) {
    $nomeArquivo = ($isRecebiveis ? 'contas_receber_recebiveis_' : 'contas_receber_clientes_') . date('Ymd_His');

    if ($exportar === 'pdf_itens') {
        $tituloExportacao = 'Contas a Receber - ' . $tituloCarteira . ' - Compras e Itens';
        $metadados = [
            $isRecebiveis ? 'CMCONTADOR: ' . ($cmFiltro !== '' ? $cmFiltro : 'Todos exceto 9') : 'CMCONTADOR 9',
            'Cliente: ' . ($cliente ?: 'Todos'),
            'Vencimento: ' . ($vencInicio ?: 'inicio') . ' ate ' . ($vencFim ?: 'fim'),
            'Verificado: ' . ($verificado ?: 'Todos'),
            'Registros: ' . (int)$resumo['qtd'] . ' | Total em aberto: ' . moedaFinanceiroClientes($resumo['total_restante']),
        ];

        $colunas = [
            ['titulo' => 'CR', 'largura' => 42, 'limite' => 9],
            ['titulo' => 'Venda', 'largura' => 48, 'limite' => 10],
            ['titulo' => 'Cliente', 'largura' => 170, 'limite' => 32],
            ['titulo' => 'Venc.', 'largura' => 55, 'limite' => 10],
            ['titulo' => 'Cod.', 'largura' => 52, 'limite' => 12],
            ['titulo' => 'Produto', 'largura' => 250, 'limite' => 48],
            ['titulo' => 'Qtd', 'largura' => 40, 'limite' => 8],
            ['titulo' => 'Unit.', 'largura' => 60, 'limite' => 12],
            ['titulo' => 'Total', 'largura' => 65, 'limite' => 12],
        ];

        $linhas = [];
        foreach ($registros as $registro) {
            $vendaOrigem = (int)($registro['NUMDOCORIGEM'] ?? 0);
            $itensVenda = $vendaOrigem > 0 ? ($itensPorVenda[$vendaOrigem] ?? []) : [];
            $clienteLinha = (string)($registro['nome_cliente'] ?: 'Cliente ' . $registro['CLICONTADOR']);

            if (empty($itensVenda)) {
                $linhas[] = [
                    (string)(int)$registro['CRCONTADOR'],
                    $vendaOrigem > 0 ? (string)$vendaOrigem : '-',
                    $clienteLinha,
                    dataFinanceiroClientes($registro['DTVENC']),
                    '-',
                    'Sem itens localizados para esta venda/titulo',
                    '',
                    '',
                    moedaFinanceiroClientes($registro['VLRPARCELA']),
                ];
                continue;
            }

            foreach ($itensVenda as $itemVenda) {
                $linhas[] = [
                    (string)(int)$registro['CRCONTADOR'],
                    (string)$vendaOrigem,
                    $clienteLinha,
                    dataFinanceiroClientes($registro['DTVENC']),
                    (string)($itemVenda['CODPRODUTO'] ?? $itemVenda['PRODUTO'] ?? ''),
                    (string)($itemVenda['DESCPRODUTO'] ?? 'Produto ' . ($itemVenda['PRODUTO'] ?? '')),
                    number_format((float)($itemVenda['QTDE'] ?? 0), 3, ',', '.'),
                    moedaFinanceiroClientes($itemVenda['VALOR'] ?? 0),
                    moedaFinanceiroClientes($itemVenda['TOTPROD'] ?? 0),
                ];
            }
        }

        gerarPdfFinanceiroClientes($tituloExportacao, $metadados, $colunas, $linhas, $nomeArquivo . '_itens', 'landscape');
    }

    if ($exportar === 'pdf') {
        $tituloExportacao = $visao === 'sintetico'
            ? 'Contas a Receber - ' . $tituloCarteira . ' - Sintetico'
            : 'Contas a Receber - ' . $tituloCarteira . ' - Analitico';
        $metadados = [
            $isRecebiveis ? 'CMCONTADOR: ' . ($cmFiltro !== '' ? $cmFiltro : 'Todos exceto 9') : 'CMCONTADOR 9',
            'Cliente: ' . ($cliente ?: 'Todos'),
            'Vencimento: ' . ($vencInicio ?: 'inicio') . ' ate ' . ($vencFim ?: 'fim'),
            'Verificado: ' . ($verificado ?: 'Todos'),
        ];

        if ($visao === 'sintetico') {
            $colunas = [
                ['titulo' => 'Codigo', 'largura' => 60, 'limite' => 12],
                ['titulo' => 'Cliente', 'largura' => 330, 'limite' => 55],
                ['titulo' => 'Qtd', 'largura' => 55, 'limite' => 8],
                ['titulo' => 'Valor em aberto', 'largura' => 100, 'limite' => 18],
            ];
            $linhas = array_map(static function ($linha): array {
                return [
                    (string)(int)$linha['CLICONTADOR'],
                    (string)$linha['nome_cliente'],
                    (string)(int)$linha['qtd'],
                    moedaFinanceiroClientes($linha['total_restante']),
                ];
            }, $sintetico);

            gerarPdfFinanceiroClientes($tituloExportacao, $metadados, $colunas, $linhas, $nomeArquivo, 'portrait');
        }

        $colunas = [
            ['titulo' => 'CR', 'largura' => 45, 'limite' => 10],
            ['titulo' => 'CM', 'largura' => 35, 'limite' => 6],
            ['titulo' => 'Cod.', 'largura' => 45, 'limite' => 10],
            ['titulo' => 'Cliente', 'largura' => 210, 'limite' => 38],
            ['titulo' => 'Venc.', 'largura' => 62, 'limite' => 10],
            ['titulo' => 'Emissao', 'largura' => 62, 'limite' => 10],
            ['titulo' => 'Valor', 'largura' => 85, 'limite' => 16],
            ['titulo' => 'Restante', 'largura' => 85, 'limite' => 16],
            ['titulo' => 'Status', 'largura' => 55, 'limite' => 12],
            ['titulo' => 'Verif.', 'largura' => 50, 'limite' => 8],
        ];
        $linhas = array_map(static function ($registro): array {
            return [
                (string)(int)$registro['CRCONTADOR'],
                (string)(int)$registro['CMCONTADOR'],
                (string)(int)$registro['CLICONTADOR'],
                (string)($registro['nome_cliente'] ?: 'Cliente ' . $registro['CLICONTADOR']),
                dataFinanceiroClientes($registro['DTVENC']),
                dataFinanceiroClientes($registro['DTEMISSAO']),
                moedaFinanceiroClientes($registro['VLRPARCELA']),
                moedaFinanceiroClientes($registro['VLRRESTANTE']),
                (string)$registro['STATUS'],
                ($registro['financeiro_verificado'] ?? 'N') === 'S' ? 'Sim' : 'Nao',
            ];
        }, $registros);

        gerarPdfFinanceiroClientes($tituloExportacao, $metadados, $colunas, $linhas, $nomeArquivo, 'landscape');
    }

    if ($exportar === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.xls"');
        echo "\xEF\xBB\xBF";
    }

    $tituloExportacao = $visao === 'sintetico'
        ? 'Contas a Receber - ' . $tituloCarteira . ' - Sintetico'
        : 'Contas a Receber - ' . $tituloCarteira . ' - Analitico';
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
        <meta charset="utf-8">
        <title><?= escapeExcelFinanceiro($tituloExportacao) ?></title>
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
        <h1><?= escapeExcelFinanceiro($tituloExportacao) ?></h1>
        <div class="meta">
            <?= escapeExcelFinanceiro($isRecebiveis ? 'CMCONTADOR: ' . ($cmFiltro !== '' ? $cmFiltro : 'Todos exceto 9') : 'CMCONTADOR 9') ?> |
            Cliente: <?= escapeExcelFinanceiro($cliente ?: 'Todos') ?> |
            Vencimento: <?= escapeExcelFinanceiro($vencInicio ?: 'inicio') ?> ate <?= escapeExcelFinanceiro($vencFim ?: 'fim') ?> |
            Verificado: <?= escapeExcelFinanceiro($verificado ?: 'Todos') ?>
        </div>

        <?php if ($visao === 'sintetico'): ?>
            <table>
                <thead>
                    <tr>
                        <th>Codigo</th>
                        <th>Cliente</th>
                        <th class="num">Qtd</th>
                        <th class="num">Valor em aberto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sintetico as $linha): ?>
                        <tr>
                            <td><?= (int)$linha['CLICONTADOR'] ?></td>
                            <td><?= escapeExcelFinanceiro($linha['nome_cliente']) ?></td>
                            <td class="num"><?= (int)$linha['qtd'] ?></td>
                            <td class="num"><?= number_format((float)$linha['total_restante'], 2, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>CR</th>
                        <th>CM</th>
                        <th>Codigo Cliente</th>
                        <th>Cliente</th>
                        <th>Vencimento</th>
                        <th>Emissao</th>
                        <th class="num">Valor</th>
                        <th class="num">Restante</th>
                        <th>Status</th>
                        <th>Verificado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registros as $registro): ?>
                        <tr>
                            <td><?= (int)$registro['CRCONTADOR'] ?></td>
                            <td><?= (int)$registro['CMCONTADOR'] ?></td>
                            <td><?= (int)$registro['CLICONTADOR'] ?></td>
                            <td><?= escapeExcelFinanceiro($registro['nome_cliente'] ?: 'Cliente ' . $registro['CLICONTADOR']) ?></td>
                            <td><?= escapeExcelFinanceiro(dataFinanceiroClientes($registro['DTVENC'])) ?></td>
                            <td><?= escapeExcelFinanceiro(dataFinanceiroClientes($registro['DTEMISSAO'])) ?></td>
                            <td class="num"><?= number_format((float)$registro['VLRPARCELA'], 2, ',', '.') ?></td>
                            <td class="num"><?= number_format((float)$registro['VLRRESTANTE'], 2, ',', '.') ?></td>
                            <td><?= escapeExcelFinanceiro($registro['STATUS']) ?></td>
                            <td><?= ($registro['financeiro_verificado'] ?? 'N') === 'S' ? 'Sim' : 'Nao' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
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

    .financeiro-grid .col-check {
        width: 46px;
    }

    .financeiro-grid .col-id {
        width: 74px;
        white-space: nowrap;
    }

    .financeiro-grid .col-cm {
        width: 54px;
        white-space: nowrap;
    }

    .financeiro-grid .col-date {
        width: 86px;
        white-space: nowrap;
    }

    .financeiro-grid .col-money {
        width: 118px;
        white-space: nowrap;
    }

    .financeiro-grid .col-status {
        width: 88px;
        white-space: nowrap;
    }

    .financeiro-grid .col-action {
        width: 96px;
        white-space: nowrap;
    }

    .financeiro-grid .cliente-principal {
        line-height: 1.15;
    }

    .financeiro-grid .cliente-meta {
        line-height: 1.2;
    }

    .financeiro-toolbar {
        background: #f8fafc;
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
            flex: 0 0 34%;
            color: #64748b;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            font-size: .72rem;
            line-height: 1.2;
        }

        .financeiro-grid td[data-label="Cliente"] {
            display: block;
            text-align: left !important;
        }

        .financeiro-grid td[data-label="Cliente"]::before {
            display: block;
            margin-bottom: .25rem;
        }

        .financeiro-grid .btn {
            width: 100%;
        }

        .financeiro-toolbar .btn {
            flex: 1 1 46%;
        }
    }
</style>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Contas a Receber</span>
                <h1 class="h3 fw-bold mb-2"><?= htmlspecialchars($tituloCarteira) ?></h1>
                <p class="text-muted mb-0"><?= htmlspecialchars($descricaoCarteira) ?></p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="contas_receber.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<section class="mb-3">
    <form method="GET" class="bg-white border rounded-2 shadow-sm p-3">
        <input type="hidden" name="carteira" value="<?= htmlspecialchars($carteira) ?>">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Nome do cliente</label>
                <input type="text" name="cliente" class="form-control" value="<?= htmlspecialchars($cliente) ?>" placeholder="Nome, apelido ou codigo">
                <div class="mt-2">
                    <?php if ($visao === 'sintetico'): ?>
                        <a href="contas_receber_clientes.php?<?= htmlspecialchars(queryFinanceiroClientes(['visao' => 'analitico'])) ?>" class="btn btn-sm btn-outline-primary w-100">Analitico</a>
                    <?php else: ?>
                        <a href="contas_receber_clientes.php?<?= htmlspecialchars(queryFinanceiroClientes(['visao' => 'sintetico'])) ?>" class="btn btn-sm btn-outline-primary w-100">Sintetico</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($isRecebiveis): ?>
                <div class="col-md-2">
                    <label class="form-label">CMCONTADOR</label>
                    <input type="number" name="cmcontador" class="form-control" value="<?= htmlspecialchars($cmFiltro) ?>" placeholder="Todos exceto 9">
                </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label">Vencimento inicial</label>
                <input type="date" name="venc_ini" class="form-control" value="<?= htmlspecialchars($vencInicio) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Vencimento final</label>
                <input type="date" name="venc_fim" class="form-control" value="<?= htmlspecialchars($vencFim) ?>">
            </div>
            <div class="<?= $isRecebiveis ? 'col-md-2' : 'col-md-2' ?>">
                <label class="form-label">Verificado</label>
                <select name="verificado" class="form-select">
                    <option value="">Todos</option>
                    <option value="N" <?= $verificado === 'N' ? 'selected' : '' ?>>Nao</option>
                    <option value="S" <?= $verificado === 'S' ? 'selected' : '' ?>>Sim</option>
                </select>
            </div>
            <div class="col-12 d-flex gap-2 justify-content-end">
                <a href="contas_receber_clientes.php<?= $isRecebiveis ? '?carteira=recebiveis' : '' ?>" class="btn btn-outline-secondary">Limpar</a>
                <a href="contas_receber_clientes.php?<?= htmlspecialchars(queryFinanceiroClientes(['exportar' => 'excel'])) ?>" class="btn btn-success">Excel</a>
                <a href="contas_receber_clientes.php?<?= htmlspecialchars(queryFinanceiroClientes(['exportar' => 'pdf'])) ?>" class="btn btn-danger">PDF</a>
                <a href="contas_receber_clientes.php?<?= htmlspecialchars(queryFinanceiroClientes(['exportar' => 'pdf_itens', 'visao' => 'analitico'])) ?>" class="btn btn-outline-danger">PDF com itens</a>
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
                <div class="small text-muted">Total em aberto</div>
                <div class="h5 fw-bold mb-0"><?= moedaFinanceiroClientes($resumo['total_restante']) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="bg-white border rounded-2 shadow-sm p-3 h-100">
                <div class="small text-muted">Total dos marcados</div>
                <div class="h5 fw-bold mb-0" id="totalMarcado">R$ 0,00</div>
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
                        <th class="col-id">Cod.</th>
                        <th>Cliente</th>
                        <th class="text-end col-cm">Qtd</th>
                        <th class="text-end col-money">Aberto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sintetico as $linha): ?>
                        <tr>
                            <td data-label="Cod." class="fw-semibold col-id"><?= (int)$linha['CLICONTADOR'] ?></td>
                            <td data-label="Cliente"><?= htmlspecialchars($linha['nome_cliente']) ?></td>
                            <td data-label="Qtd" class="text-end col-cm"><?= (int)$linha['qtd'] ?></td>
                            <td data-label="Aberto" class="text-end fw-semibold col-money"><?= moedaFinanceiroClientes($linha['total_restante']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($sintetico)): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">Nenhum cliente encontrado com os filtros informados.</td>
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
                        <th class="col-id">CR</th>
                        <th class="col-cm">CM</th>
                        <th>Cliente</th>
                        <th class="col-date">Venc.</th>
                        <th class="col-date">Emis.</th>
                        <th class="text-end col-money">Valor</th>
                        <th class="text-end col-money">Aberto</th>
                        <th class="col-status">Status</th>
                        <th class="text-center col-action">Itens</th>
                        <th class="col-action">Verif.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registros as $registro): ?>
                        <?php
                            $verificadoRegistro = ($registro['financeiro_verificado'] ?? 'N') === 'S';
                            $vendaOrigem = (int)($registro['NUMDOCORIGEM'] ?? 0);
                            $itensVenda = $vendaOrigem > 0 ? ($itensPorVenda[$vendaOrigem] ?? []) : [];
                            $collapseItensId = 'itens-cr-' . (int)$registro['CRCONTADOR'];
                        ?>
                        <tr>
                            <td data-label="Somar" class="text-center col-check">
                                <input
                                    type="checkbox"
                                    class="form-check-input js-somar"
                                    value="<?= (int)$registro['CRCONTADOR'] ?>"
                                    data-valor="<?= htmlspecialchars((string)((float)$registro['VLRRESTANTE'])) ?>"
                                >
                            </td>
                            <td data-label="CR" class="fw-semibold col-id"><?= (int)$registro['CRCONTADOR'] ?></td>
                            <td data-label="CM" class="col-cm"><?= (int)$registro['CMCONTADOR'] ?></td>
                            <td data-label="Cliente">
                                <div class="fw-semibold cliente-principal"><?= htmlspecialchars($registro['nome_cliente'] ?: 'Cliente ' . $registro['CLICONTADOR']) ?></div>
                                <div class="small text-muted cliente-meta">Cod. <?= (int)$registro['CLICONTADOR'] ?> | Venda <?= htmlspecialchars((string)$registro['NUMDOCORIGEM']) ?></div>
                            </td>
                            <td data-label="Venc." class="col-date"><?= dataFinanceiroClientes($registro['DTVENC']) ?></td>
                            <td data-label="Emis." class="col-date"><?= dataFinanceiroClientes($registro['DTEMISSAO']) ?></td>
                            <td data-label="Valor" class="text-end fw-semibold col-money"><?= moedaFinanceiroClientes($registro['VLRPARCELA']) ?></td>
                            <td data-label="Aberto" class="text-end col-money"><?= moedaFinanceiroClientes($registro['VLRRESTANTE']) ?></td>
                            <td data-label="Status" class="col-status">
                                <span class="badge text-bg-warning"><?= htmlspecialchars($registro['STATUS'] ?: 'SEM STATUS') ?></span>
                            </td>
                            <td data-label="Itens" class="text-center col-action">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    data-itens-toggle="1"
                                    data-bs-target="#<?= $collapseItensId ?>"
                                    aria-expanded="false"
                                    title="Ver itens da venda"
                                >🔍</button>
                            </td>
                            <td data-label="Verif." class="col-action">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="acao" value="verificar">
                                    <input type="hidden" name="crcontador" value="<?= (int)$registro['CRCONTADOR'] ?>">
                                    <input type="hidden" name="verificado" value="<?= $verificadoRegistro ? 'N' : 'S' ?>">
                                    <button type="submit" class="btn btn-sm <?= $verificadoRegistro ? 'btn-success' : 'btn-outline-secondary' ?>">
                                        <?= $verificadoRegistro ? 'Verificado' : 'Marcar' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <tr class="collapse bg-light" id="<?= $collapseItensId ?>">
                            <td colspan="11">
                                <?php if (empty($itensVenda)): ?>
                                    <div class="text-muted small py-2">
                                        Nenhum item encontrado para a venda <?= htmlspecialchars((string)$vendaOrigem) ?>.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Item</th>
                                                    <th>Produto</th>
                                                    <th>Descricao</th>
                                                    <th class="text-end">Qtde</th>
                                                    <th class="text-end">Unitario</th>
                                                    <th class="text-end">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($itensVenda as $itemVenda): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars((string)($itemVenda['VENDACONTA'] ?? '')) ?></td>
                                                        <td><?= htmlspecialchars((string)($itemVenda['CODPRODUTO'] ?? $itemVenda['PRODUTO'] ?? '')) ?></td>
                                                        <td><?= htmlspecialchars((string)($itemVenda['DESCPRODUTO'] ?? '')) ?></td>
                                                        <td class="text-end"><?= number_format((float)($itemVenda['QTDE'] ?? 0), 3, ',', '.') ?> <?= htmlspecialchars((string)($itemVenda['UNIDADE'] ?? '')) ?></td>
                                                        <td class="text-end"><?= moedaFinanceiroClientes($itemVenda['VALOR'] ?? 0) ?></td>
                                                        <td class="text-end"><?= moedaFinanceiroClientes($itemVenda['TOTPROD'] ?? 0) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($registros)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">Nenhum titulo encontrado com os filtros informados.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<form method="POST" id="formVerificarLote" class="d-none">
    <input type="hidden" name="acao" value="verificar_lote">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const totalMarcado = document.getElementById('totalMarcado');
    const checks = document.querySelectorAll('.js-somar');
    const marcarTodos = document.getElementById('marcarTodos');
    const desmarcarTodos = document.getElementById('desmarcarTodos');
    const marcarSelecionadosVerificados = document.getElementById('marcarSelecionadosVerificados');
    const formVerificarLote = document.getElementById('formVerificarLote');

    function formatarMoeda(valor) {
        return valor.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function atualizarTotal() {
        let total = 0;
        checks.forEach(function (check) {
            if (check.checked) {
                total += Number(check.dataset.valor || 0);
            }
        });
        totalMarcado.textContent = formatarMoeda(total);
    }

    checks.forEach(function (check) {
        check.addEventListener('change', atualizarTotal);
    });

    if (marcarTodos) {
        marcarTodos.addEventListener('click', function () {
            checks.forEach(function (check) {
                check.checked = true;
            });
            atualizarTotal();
        });
    }

    if (desmarcarTodos) {
        desmarcarTodos.addEventListener('click', function () {
            checks.forEach(function (check) {
                check.checked = false;
            });
            atualizarTotal();
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

            formVerificarLote.querySelectorAll('input[name="crcontadores[]"]').forEach(function (input) {
                input.remove();
            });

            selecionados.forEach(function (check) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'crcontadores[]';
                input.value = check.value;
                formVerificarLote.appendChild(input);
            });

            formVerificarLote.submit();
        });
    }

    document.querySelectorAll('[data-itens-toggle]').forEach(function (botao) {
        botao.addEventListener('click', function (event) {
            event.preventDefault();

            const alvoSeletor = botao.getAttribute('data-bs-target');
            const alvo = alvoSeletor ? document.querySelector(alvoSeletor) : null;

            if (!alvo) {
                return;
            }

            alvo.classList.remove('collapsing');
            alvo.classList.toggle('show');
            botao.setAttribute('aria-expanded', alvo.classList.contains('show') ? 'true' : 'false');
        });
    });
});
</script>

<?php require '../../layout/footer.php'; ?>
