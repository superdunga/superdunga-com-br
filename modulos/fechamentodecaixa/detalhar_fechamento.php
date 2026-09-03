<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$data = $_GET['data'] ?? '';
$usuario = $_GET['user'] ?? '';
$empresa_id = (int)$_SESSION['empresa_id'];
$produtoFiltro = trim((string)($_GET['produto'] ?? ''));
$vendaPdfIndividual = (int)($_GET['venda'] ?? 0);

if (!$data || !$usuario) {
    require '../../layout/header.php';
    echo "<div class='alert alert-danger'>Parâmetros inválidos</div>";
    exit;
}

$data_inicio = date('Y-m-d 07:00:00', strtotime($data));
$data_fim    = date('Y-m-d 03:00:00', strtotime($data . ' +1 day'));

function moedaDetalheCaixa($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function dataHoraDetalheCaixa($valor): string
{
    if (empty($valor)) {
        return '';
    }
    $ts = strtotime((string)$valor);
    return $ts ? date('d/m/Y H:i', $ts) : (string)$valor;
}

function normalizarBuscaDetalheCaixa(string $valor): string
{
    $convertido = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
    if ($convertido !== false) {
        $valor = $convertido;
    }
    $valor = strtoupper($valor);
    $valor = preg_replace('/[^A-Z0-9]+/', ' ', $valor);
    return preg_replace('/\s+/', ' ', trim($valor));
}

function textoPdfDetalheCaixa($valor, int $limite = 0): string
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

function escaparPdfDetalheCaixa(string $texto): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

function textoCmdPdfDetalheCaixa(float $x, float $y, int $tamanho, string $texto, bool $negrito = false): string
{
    $fonte = $negrito ? 'F2' : 'F1';
    return "BT /{$fonte} {$tamanho} Tf 1 0 0 1 " . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . escaparPdfDetalheCaixa($texto) . ") Tj ET\n";
}

function retanguloPdfDetalheCaixa(float $x, float $y, float $w, float $h): string
{
    return number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' ' . number_format($w, 2, '.', '') . ' ' . number_format($h, 2, '.', '') . " re f\n";
}

function enviarPdfDetalheCaixa(array $paginas, string $arquivo): void
{
    $objetos = [
        1 => "<< /Type /Catalog /Pages 2 0 R >>",
        2 => '',
        3 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
        4 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>",
    ];
    $idsPaginas = [];
    $proximoId = 5;

    foreach ($paginas as $pagina) {
        $conteudo = (string)($pagina['conteudo'] ?? '');
        $largura = (float)($pagina['largura'] ?? 842);
        $altura = (float)($pagina['altura'] ?? 595);
        $conteudoId = $proximoId++;
        $paginaId = $proximoId++;
        $objetos[$conteudoId] = "<< /Length " . strlen($conteudo) . " >>\nstream\n{$conteudo}endstream";
        $objetos[$paginaId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$largura} {$altura}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$conteudoId} 0 R >>";
        $idsPaginas[] = $paginaId;
    }

    $objetos[2] = "<< /Type /Pages /Kids [" . implode(' ', array_map(static function ($id) {
        return "{$id} 0 R";
    }, $idsPaginas)) . "] /Count " . count($idsPaginas) . " >>";
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
    header('Content-Disposition: attachment; filename="' . $arquivo . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function gerarPdfDetalheFechamentoCaixa(array $vendasRelatorio, array $itensPorVenda, string $data, string $usuario, string $dataInicio, string $dataFim, string $produtoFiltro, int $vendaIndividual, float $totalRelatorio): void
{
    $largura = 842;
    $altura = 595;
    $margem = 28;
    $paginas = [];
    $conteudo = '';
    $y = 0.0;

    $novaPagina = static function () use (&$conteudo, &$y, $largura, $altura, $margem, $data, $usuario, $dataInicio, $dataFim, $produtoFiltro, $vendaIndividual, $totalRelatorio): void {
        $conteudo = '';
        $conteudo .= "0.07 0.20 0.42 rg\n";
        $conteudo .= retanguloPdfDetalheCaixa(0, $altura - 70, $largura, 70);
        $conteudo .= "1 1 1 rg\n";
        $titulo = $vendaIndividual > 0 ? 'Relatorio da Venda ' . $vendaIndividual : 'Relatorio de Vendas do Caixa';
        $conteudo .= textoCmdPdfDetalheCaixa($margem, $altura - 28, 16, textoPdfDetalheCaixa($titulo, 90), true);
        $conteudo .= textoCmdPdfDetalheCaixa($margem, $altura - 47, 9, textoPdfDetalheCaixa('Data: ' . date('d/m/Y', strtotime($data)) . ' | Operador: ' . $usuario, 110));
        $conteudo .= textoCmdPdfDetalheCaixa($margem, $altura - 61, 8, textoPdfDetalheCaixa('Periodo: ' . dataHoraDetalheCaixa($dataInicio) . ' ate ' . dataHoraDetalheCaixa($dataFim), 120));
        $conteudo .= textoCmdPdfDetalheCaixa($largura - 250, $altura - 47, 9, textoPdfDetalheCaixa('Total: ' . moedaDetalheCaixa($totalRelatorio), 45), true);
        if ($produtoFiltro !== '') {
            $conteudo .= textoCmdPdfDetalheCaixa($largura - 250, $altura - 61, 8, textoPdfDetalheCaixa('Produto: ' . $produtoFiltro, 45));
        }
        $conteudo .= "0 0 0 rg\n";
        $y = $altura - 94;
    };

    $salvarPagina = static function () use (&$paginas, &$conteudo, $largura, $altura): void {
        if ($conteudo !== '') {
            $paginas[] = ['conteudo' => $conteudo, 'largura' => $largura, 'altura' => $altura];
        }
    };

    $cabecalhoTabela = static function () use (&$conteudo, &$y, $margem, $largura): void {
        $conteudo .= "0.92 0.94 0.97 rg\n";
        $conteudo .= retanguloPdfDetalheCaixa($margem, $y - 14, $largura - ($margem * 2), 18);
        $conteudo .= "0 0 0 rg\n";
        $conteudo .= textoCmdPdfDetalheCaixa($margem + 5, $y - 9, 7, textoPdfDetalheCaixa('Item'), true);
        $conteudo .= textoCmdPdfDetalheCaixa($margem + 50, $y - 9, 7, textoPdfDetalheCaixa('Codigo'), true);
        $conteudo .= textoCmdPdfDetalheCaixa($margem + 115, $y - 9, 7, textoPdfDetalheCaixa('Descricao'), true);
        $conteudo .= textoCmdPdfDetalheCaixa($margem + 515, $y - 9, 7, textoPdfDetalheCaixa('Qtde'), true);
        $conteudo .= textoCmdPdfDetalheCaixa($margem + 610, $y - 9, 7, textoPdfDetalheCaixa('Unitario'), true);
        $conteudo .= textoCmdPdfDetalheCaixa($margem + 705, $y - 9, 7, textoPdfDetalheCaixa('Total'), true);
        $y -= 23;
    };

    $novaPagina();

    if (empty($vendasRelatorio)) {
        $conteudo .= textoCmdPdfDetalheCaixa($margem, $y, 11, textoPdfDetalheCaixa('Nenhuma venda encontrada para os filtros informados.'));
        $salvarPagina();
        enviarPdfDetalheCaixa($paginas, 'vendas_caixa_' . date('Ymd', strtotime($data)) . '.pdf');
    }

    foreach ($vendasRelatorio as $vendaPdf) {
        if ($y < 95) {
            $salvarPagina();
            $novaPagina();
        }

        $vendaPdfId = (int)($vendaPdf['VENDACONTADOR'] ?? 0);
        $cliente = trim((string)($vendaPdf['CLIENTE'] ?? '') . ' - ' . (string)($vendaPdf['nome_cliente'] ?? ''));
        $conteudo .= "0.07 0.20 0.42 rg\n";
        $conteudo .= retanguloPdfDetalheCaixa($margem, $y - 18, $largura - ($margem * 2), 22);
        $conteudo .= "1 1 1 rg\n";
        $conteudo .= textoCmdPdfDetalheCaixa($margem + 6, $y - 11, 8, textoPdfDetalheCaixa('Venda ' . $vendaPdfId), true);
        $conteudo .= textoCmdPdfDetalheCaixa($margem + 100, $y - 11, 8, textoPdfDetalheCaixa(dataHoraDetalheCaixa($vendaPdf['DTLANC'] ?? ''), 20), true);
        $conteudo .= textoCmdPdfDetalheCaixa($margem + 215, $y - 11, 8, textoPdfDetalheCaixa('CM ' . (string)($vendaPdf['CMCONTADOR'] ?? ''), 18), true);
        $conteudo .= textoCmdPdfDetalheCaixa($margem + 310, $y - 11, 8, textoPdfDetalheCaixa($cliente, 58), true);
        $conteudo .= textoCmdPdfDetalheCaixa($margem + 690, $y - 11, 8, textoPdfDetalheCaixa(moedaDetalheCaixa($vendaPdf['valor'] ?? 0), 18), true);
        $conteudo .= "0 0 0 rg\n";
        $y -= 31;

        $cabecalhoTabela();
        $itensPdf = $itensPorVenda[$vendaPdfId] ?? [];
        if (empty($itensPdf)) {
            $conteudo .= textoCmdPdfDetalheCaixa($margem + 5, $y, 8, textoPdfDetalheCaixa('Nenhum item encontrado para esta venda.'));
            $y -= 18;
            continue;
        }

        foreach ($itensPdf as $itemPdf) {
            if ($y < 45) {
                $salvarPagina();
                $novaPagina();
                $conteudo .= textoCmdPdfDetalheCaixa($margem, $y, 8, textoPdfDetalheCaixa('Venda ' . $vendaPdfId . ' - continuacao'), true);
                $y -= 16;
                $cabecalhoTabela();
            }
            $conteudo .= textoCmdPdfDetalheCaixa($margem + 5, $y, 7, textoPdfDetalheCaixa($itemPdf['VENDACONTA'] ?? '', 8));
            $conteudo .= textoCmdPdfDetalheCaixa($margem + 50, $y, 7, textoPdfDetalheCaixa($itemPdf['CODPRODUTO'] ?? $itemPdf['PRODUTO'] ?? '', 12));
            $conteudo .= textoCmdPdfDetalheCaixa($margem + 115, $y, 7, textoPdfDetalheCaixa($itemPdf['DESCPRODUTO'] ?? '', 70));
            $conteudo .= textoCmdPdfDetalheCaixa($margem + 515, $y, 7, textoPdfDetalheCaixa(number_format((float)($itemPdf['QTDE'] ?? 0), 3, ',', '.') . ' ' . (string)($itemPdf['UNIDADE'] ?? ''), 16));
            $conteudo .= textoCmdPdfDetalheCaixa($margem + 610, $y, 7, textoPdfDetalheCaixa(moedaDetalheCaixa($itemPdf['VALOR'] ?? 0), 16));
            $conteudo .= textoCmdPdfDetalheCaixa($margem + 705, $y, 7, textoPdfDetalheCaixa(moedaDetalheCaixa($itemPdf['TOTPROD'] ?? 0), 16));
            $y -= 13;
        }

        $y -= 12;
    }

    $salvarPagina();
    $arquivo = $vendaIndividual > 0
        ? 'venda_' . $vendaIndividual . '_caixa_' . date('Ymd', strtotime($data)) . '.pdf'
        : 'vendas_caixa_' . date('Ymd', strtotime($data)) . '.pdf';
    enviarPdfDetalheCaixa($paginas, $arquivo);
}

$stmt = $pdo_master->prepare("
    SELECT
        e.VENDACONTADOR,
        e.DTLANC,
        e.CMCONTADOR,
        e.CLIENTE,
        COALESCE(c.NOME, c.APELIDO, '') AS nome_cliente,
        e.TOTGERAL AS valor
    FROM armazem_est007 e
    LEFT JOIN armazem_cr002 c
        ON c.CLICONTADOR = e.CLIENTE
       AND c.EMPRESA = e.EMPRESA
    WHERE e.DTLANC BETWEEN ? AND ?
      AND e.USERLANC = ?
      AND e.EMPRESA = ?
      AND e.CANCELADO = 'N'
      AND COALESCE(e.excluido_firebird, 'N') <> 'S'
    ORDER BY e.DTLANC, e.VENDACONTADOR
");
$stmt->execute([$data_inicio, $data_fim, $usuario, $empresa_id]);
$vendas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$itensPorVenda = [];
$recebiveisPorVenda = [];
$vendasIds = array_values(array_filter(array_map(function ($venda) {
    return (int)($venda['VENDACONTADOR'] ?? 0);
}, $vendas)));

if (!empty($vendasIds)) {
    $placeholders = implode(',', array_fill(0, count($vendasIds), '?'));
    $stmtItens = $pdo_master->prepare("
        SELECT
            i.VENDACONTA,
            i.ITEMVENDACONTADOR,
            i.PRODUTO,
            i.QTDE,
            i.VALOR,
            i.TOTPROD,
            p.CODPRODUTO,
            p.DESCPRODUTO,
            p.UNIDADE
        FROM armazem_est008 i
        LEFT JOIN armazem_est004 p
            ON p.CONTAPRODUTO = i.PRODUTO
           AND p.EMPRESA = i.EMPRESA
        WHERE i.ITEMVENDACONTADOR IN ($placeholders)
          AND i.EMPRESA = ?
        ORDER BY i.ITEMVENDACONTADOR, i.VENDACONTA
    ");
    $stmtItens->execute(array_merge($vendasIds, [$empresa_id]));

    while ($item = $stmtItens->fetch(PDO::FETCH_ASSOC)) {
        $itensPorVenda[(int)$item['ITEMVENDACONTADOR']][] = $item;
    }

    $stmtRecebiveis = $pdo_master->prepare("
        SELECT
            CRCONTADOR,
            NUMDOCORIGEM,
            DTLANC,
            DTVENC,
            VLRPARCELA,
            CMCONTADOR,
            STATUS,
            recebimento_id
        FROM armazem_cr001
        WHERE NUMDOCORIGEM IN ($placeholders)
          AND EMPRESA = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        ORDER BY NUMDOCORIGEM, DTVENC, CRCONTADOR
    ");
    $stmtRecebiveis->execute(array_merge($vendasIds, [$empresa_id]));

    while ($recebivel = $stmtRecebiveis->fetch(PDO::FETCH_ASSOC)) {
        $recebiveisPorVenda[(int)$recebivel['NUMDOCORIGEM']][] = $recebivel;
    }
}

$produtoFiltroNormalizado = normalizarBuscaDetalheCaixa($produtoFiltro);
$produtoFiltroTermos = array_values(array_filter(explode(' ', $produtoFiltroNormalizado), static function ($termo) {
    return $termo !== '';
}));
$vendasRelatorio = $vendas;

if ($produtoFiltroNormalizado !== '') {
    $vendasRelatorio = [];
    foreach ($vendas as $vendaFiltro) {
        $vendaFiltroId = (int)($vendaFiltro['VENDACONTADOR'] ?? 0);
        $itensFiltro = $itensPorVenda[$vendaFiltroId] ?? [];
        $encontrouProduto = false;

        foreach ($itensFiltro as $itemFiltro) {
            $textoItem = normalizarBuscaDetalheCaixa(implode(' ', [
                (string)($itemFiltro['CODPRODUTO'] ?? ''),
                (string)($itemFiltro['DESCPRODUTO'] ?? ''),
                (string)($itemFiltro['PRODUTO'] ?? ''),
            ]));

            $todosTermosEncontrados = true;
            foreach ($produtoFiltroTermos as $termoProdutoFiltro) {
                if (strpos($textoItem, $termoProdutoFiltro) === false) {
                    $todosTermosEncontrados = false;
                    break;
                }
            }

            if ($todosTermosEncontrados) {
                $encontrouProduto = true;
                break;
            }
        }

        if ($encontrouProduto) {
            $vendasRelatorio[] = $vendaFiltro;
        }
    }
}

if ($vendaPdfIndividual > 0) {
    $vendasRelatorio = array_values(array_filter($vendasRelatorio, static function ($vendaFiltro) use ($vendaPdfIndividual) {
        return (int)($vendaFiltro['VENDACONTADOR'] ?? 0) === $vendaPdfIndividual;
    }));
}

$total_venda = 0;
foreach ($vendas as $vendaTotal) {
    $total_venda += (float)($vendaTotal['valor'] ?? 0);
}

$total_venda_relatorio = 0;
foreach ($vendasRelatorio as $vendaTotalRelatorio) {
    $total_venda_relatorio += (float)($vendaTotalRelatorio['valor'] ?? 0);
}

if (($_GET['exportar_vendas'] ?? '') === 'pdf') {
    gerarPdfDetalheFechamentoCaixa($vendasRelatorio, $itensPorVenda, $data, (string)$usuario, $data_inicio, $data_fim, $produtoFiltro, $vendaPdfIndividual, $total_venda_relatorio);
?>
<style>
    .relatorio-vendas-caixa {
        background: #fff;
        color: #172033;
        max-width: 980px;
        margin: 0 auto;
        padding: 22px;
        font-size: 12px;
    }

    .relatorio-vendas-caixa .relatorio-topo {
        border-bottom: 3px solid #123a78;
        margin-bottom: 18px;
        padding-bottom: 10px;
    }

    .relatorio-vendas-caixa h1 {
        font-size: 20px;
        font-weight: 800;
        margin: 0 0 6px;
    }

    .relatorio-vendas-caixa .venda-bloco {
        border: 1px solid #cfd8e3;
        margin-bottom: 12px;
        page-break-inside: avoid;
    }

    .relatorio-vendas-caixa .venda-cabecalho {
        display: grid;
        grid-template-columns: 1fr 1fr 90px 120px;
        gap: 8px;
        background: #123a78;
        color: #fff;
        padding: 8px 10px;
        font-weight: 700;
    }

    .relatorio-vendas-caixa .venda-dados {
        display: grid;
        grid-template-columns: 110px 120px 80px 1fr 120px;
        gap: 8px;
        padding: 8px 10px;
        background: #eef3f9;
        border-bottom: 1px solid #cfd8e3;
    }

    .relatorio-vendas-caixa table {
        width: 100%;
        border-collapse: collapse;
    }

    .relatorio-vendas-caixa th,
    .relatorio-vendas-caixa td {
        border-bottom: 1px solid #d7dee8;
        padding: 5px 6px;
        vertical-align: top;
    }

    .relatorio-vendas-caixa th {
        background: #f5f7fa;
        font-weight: 800;
    }

    .relatorio-vendas-caixa .text-end {
        text-align: right;
    }

    @media print {
        header, nav, .navbar, .topbar, .btn, .no-print {
            display: none !important;
        }

        body {
            background: #fff !important;
        }

        .container, .container-fluid {
            width: 100% !important;
            max-width: none !important;
            padding: 0 !important;
        }

        .relatorio-vendas-caixa {
            max-width: none;
            padding: 0;
        }
    }
</style>

<div class="relatorio-vendas-caixa">
    <div class="relatorio-topo">
        <h1><?= $vendaPdfIndividual > 0 ? 'Relatorio da Venda ' . (int)$vendaPdfIndividual : 'Relatorio de Vendas do Caixa' ?></h1>
        <div>Data: <?= date('d/m/Y', strtotime($data)) ?> | Operador: <?= htmlspecialchars((string)$usuario) ?></div>
        <div>Periodo: <?= dataHoraDetalheCaixa($data_inicio) ?> ate <?= dataHoraDetalheCaixa($data_fim) ?></div>
        <?php if ($produtoFiltro !== ''): ?>
            <div>Produto: <?= htmlspecialchars($produtoFiltro) ?></div>
        <?php endif; ?>
        <?php if ($vendaPdfIndividual > 0): ?>
            <div>Venda: <?= (int)$vendaPdfIndividual ?></div>
        <?php endif; ?>
        <div>Total de vendas: <?= count($vendasRelatorio) ?> | Valor total: <?= moedaDetalheCaixa($total_venda_relatorio) ?></div>
    </div>

    <div class="no-print mb-3">
        <form method="get" class="row g-2 align-items-end mb-2">
            <input type="hidden" name="data" value="<?= htmlspecialchars($data) ?>">
            <input type="hidden" name="user" value="<?= htmlspecialchars($usuario) ?>">
            <input type="hidden" name="exportar_vendas" value="pdf">
            <?php if ($vendaPdfIndividual > 0): ?>
                <input type="hidden" name="venda" value="<?= (int)$vendaPdfIndividual ?>">
            <?php endif; ?>
            <div class="col-md-7">
                <label class="form-label mb-1">Produto</label>
                <input type="text" name="produto" value="<?= htmlspecialchars($produtoFiltro) ?>" class="form-control form-control-sm" placeholder="Ex.: CERV. 473ML BRAHMA">
            </div>
            <div class="col-md-5 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                <a href="detalhar_fechamento.php?data=<?= urlencode($data) ?>&user=<?= urlencode($usuario) ?>&exportar_vendas=pdf" class="btn btn-sm btn-outline-secondary">Completo</a>
                <a href="detalhar_fechamento.php?data=<?= urlencode($data) ?>&user=<?= urlencode($usuario) ?>&exportar_vendas=pdf<?= $vendaPdfIndividual > 0 ? '&venda=' . (int)$vendaPdfIndividual : '' ?><?= $produtoFiltro !== '' ? '&produto=' . urlencode($produtoFiltro) : '' ?>" class="btn btn-sm btn-primary">Baixar PDF</a>
            </div>
        </form>
        <a href="detalhar_fechamento.php?data=<?= urlencode($data) ?>&user=<?= urlencode($usuario) ?>" class="btn btn-sm btn-outline-secondary">Voltar</a>
    </div>

    <?php if (empty($vendasRelatorio)): ?>
        <div class="alert alert-info">Nenhuma venda encontrada.</div>
    <?php endif; ?>

    <?php foreach ($vendasRelatorio as $vendaPdf): ?>
        <?php
            $vendaPdfId = (int)$vendaPdf['VENDACONTADOR'];
            $itensPdf = $itensPorVenda[$vendaPdfId] ?? [];
        ?>
        <section class="venda-bloco">
            <div class="venda-cabecalho">
                <div>Venda <?= $vendaPdfId ?></div>
                <div>Cliente <?= htmlspecialchars(trim((string)($vendaPdf['CLIENTE'] ?? '') . ' - ' . (string)($vendaPdf['nome_cliente'] ?? ''))) ?></div>
                <div>CM <?= htmlspecialchars((string)($vendaPdf['CMCONTADOR'] ?? '')) ?></div>
                <div class="text-end"><?= moedaDetalheCaixa($vendaPdf['valor'] ?? 0) ?></div>
            </div>
            <div class="venda-dados">
                <div><strong>Numero</strong><br><?= $vendaPdfId ?></div>
                <div><strong>Data/Hora</strong><br><?= dataHoraDetalheCaixa($vendaPdf['DTLANC'] ?? '') ?></div>
                <div><strong>CM</strong><br><?= htmlspecialchars((string)($vendaPdf['CMCONTADOR'] ?? '')) ?></div>
                <div><strong>Cliente</strong><br><?= htmlspecialchars(trim((string)($vendaPdf['CLIENTE'] ?? '') . ' - ' . (string)($vendaPdf['nome_cliente'] ?? ''))) ?></div>
                <div class="text-end"><strong>Total</strong><br><?= moedaDetalheCaixa($vendaPdf['valor'] ?? 0) ?></div>
            </div>
            <?php if (empty($itensPdf)): ?>
                <div class="p-2 text-muted">Nenhum item encontrado para esta venda.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 55px;">Item</th>
                            <th style="width: 90px;">Codigo</th>
                            <th>Descricao</th>
                            <th class="text-end" style="width: 95px;">Qtde</th>
                            <th class="text-end" style="width: 95px;">Unitario</th>
                            <th class="text-end" style="width: 105px;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itensPdf as $itemPdf): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$itemPdf['VENDACONTA']) ?></td>
                                <td><?= htmlspecialchars((string)($itemPdf['CODPRODUTO'] ?? $itemPdf['PRODUTO'])) ?></td>
                                <td><?= htmlspecialchars((string)($itemPdf['DESCPRODUTO'] ?? '')) ?></td>
                                <td class="text-end"><?= number_format((float)$itemPdf['QTDE'], 3, ',', '.') ?> <?= htmlspecialchars((string)($itemPdf['UNIDADE'] ?? '')) ?></td>
                                <td class="text-end"><?= moedaDetalheCaixa($itemPdf['VALOR'] ?? 0) ?></td>
                                <td class="text-end"><?= moedaDetalheCaixa($itemPdf['TOTPROD'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

<?php
    exit;
}

require '../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center gap-3">
        <div>
        <h5>🔍 Detalhamento do Caixa</h5>
        <small>Data: <?php echo date('d/m/Y', strtotime($data)); ?> | Operador: <?php echo $usuario; ?></small>
        </div>
        <a
            href="detalhar_fechamento.php?data=<?= urlencode($data) ?>&user=<?= urlencode($usuario) ?>&exportar_vendas=pdf<?= $produtoFiltro !== '' ? '&produto=' . urlencode($produtoFiltro) : '' ?>"
            class="btn btn-sm btn-outline-primary"
        >
            Exportar vendas PDF
        </a>
    </div>

    <div class="card-body">

        <!-- =========================
             VENDAS
        ========================== -->
        <h6>🧾 Vendas</h6>

        <form method="get" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="data" value="<?= htmlspecialchars($data) ?>">
            <input type="hidden" name="user" value="<?= htmlspecialchars($usuario) ?>">
            <div class="col-md-7">
                <label class="form-label mb-1">Produto</label>
                <input type="text" name="produto" value="<?= htmlspecialchars($produtoFiltro) ?>" class="form-control form-control-sm" placeholder="Ex.: CERV. 473ML BRAHMA">
            </div>
            <div class="col-md-5 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
                <a href="detalhar_fechamento.php?data=<?= urlencode($data) ?>&user=<?= urlencode($usuario) ?>" class="btn btn-sm btn-outline-secondary">Completo</a>
                <a href="detalhar_fechamento.php?data=<?= urlencode($data) ?>&user=<?= urlencode($usuario) ?>&exportar_vendas=pdf<?= $produtoFiltro !== '' ? '&produto=' . urlencode($produtoFiltro) : '' ?>" class="btn btn-sm btn-outline-success">PDF</a>
            </div>
        </form>

        <?php if ($produtoFiltro !== ''): ?>
            <div class="alert alert-info py-2">
                Exibindo vendas com produto contendo: <strong><?= htmlspecialchars($produtoFiltro) ?></strong>
            </div>
        <?php endif; ?>

        <table class="table table-sm table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>Venda</th>
                    <th>Data/Hora</th>
                    <th>CM</th>
                    <th>Cliente</th>
                    <th>Nome do Cliente</th>
                    <th>Valor</th>
                    <th>Detalhe</th>
                </tr>
            </thead>
            <tbody>

<?php
if (empty($vendasRelatorio)) {
    echo '<tr><td colspan="7" class="text-center text-muted">Nenhuma venda encontrada para o filtro informado.</td></tr>';
}

foreach ($vendasRelatorio as $v) {
    $vendaId = (int)$v['VENDACONTADOR'];
    $collapseId = 'itens-venda-' . $vendaId;
    $collapseRecebiveisId = 'recebiveis-venda-' . $vendaId;
    $itens = $itensPorVenda[$vendaId] ?? [];
    $recebiveis = $recebiveisPorVenda[$vendaId] ?? [];
?>
<tr>
    <td><?= $vendaId ?></td>
    <td><?= !empty($v['DTLANC']) ? date('d/m/Y H:i', strtotime($v['DTLANC'])) : '' ?></td>
    <td><?= htmlspecialchars($v['CMCONTADOR'] ?? '') ?></td>
    <td><?= htmlspecialchars($v['CLIENTE'] ?? '') ?></td>
    <td><?= htmlspecialchars($v['nome_cliente'] ?? '') ?></td>
    <td>R$ <?= number_format((float)$v['valor'], 2, ',', '.') ?></td>
    <td>
        <div class="d-flex gap-1 flex-wrap">
            <button class="btn btn-sm btn-outline-primary"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#<?= $collapseId ?>">
                Detalhe
            </button>
            <button class="btn btn-sm btn-outline-dark"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#<?= $collapseRecebiveisId ?>"
                    aria-controls="<?= $collapseRecebiveisId ?>"
                    aria-expanded="false"
                    title="Ver contas a receber da venda">
                &#128269;
            </button>
            <a
                href="detalhar_fechamento.php?data=<?= urlencode($data) ?>&user=<?= urlencode($usuario) ?>&exportar_vendas=pdf&venda=<?= $vendaId ?>"
                class="btn btn-sm btn-outline-success"
            >
                PDF
            </a>
        </div>
    </td>
</tr>
<tr class="collapse" id="<?= $collapseRecebiveisId ?>">
    <td colspan="7" class="bg-light">
        <div class="fw-semibold mb-2">Contas a receber da venda <?= $vendaId ?></div>
        <?php if (empty($recebiveis)): ?>
            <div class="text-muted small">Nenhum lancamento de contas a receber encontrado para esta venda.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead>
                        <tr>
                            <th>CR</th>
                            <th>Data</th>
                            <th>Vencimento</th>
                            <th>CM</th>
                            <th>Status</th>
                            <th>Valor</th>
                            <th>Conciliação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recebiveis as $recebivel): ?>
                            <?php $conciliado = !empty($recebivel['recebimento_id']); ?>
                            <tr>
                                <td><?= (int)$recebivel['CRCONTADOR'] ?></td>
                                <td><?= dataHoraDetalheCaixa($recebivel['DTLANC'] ?? '') ?></td>
                                <td><?= !empty($recebivel['DTVENC']) ? date('d/m/Y', strtotime($recebivel['DTVENC'])) : '-' ?></td>
                                <td><?= htmlspecialchars((string)($recebivel['CMCONTADOR'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($recebivel['STATUS'] ?? '')) ?></td>
                                <td><?= moedaDetalheCaixa($recebivel['VLRPARCELA'] ?? 0) ?></td>
                                <td>
                                    <span class="badge bg-<?= $conciliado ? 'success' : 'warning' ?> text-<?= $conciliado ? 'white' : 'dark' ?>">
                                        <?= $conciliado ? 'Conciliado' : 'Nao conciliado' ?>
                                    </span>
                                    <?php if ($conciliado): ?>
                                        <span class="small text-muted ms-1">#<?= (int)$recebivel['recebimento_id'] ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </td>
</tr>
<tr class="collapse" id="<?= $collapseId ?>">
    <td colspan="7" class="bg-light">
        <?php if (empty($itens)): ?>
            <div class="text-muted small">Nenhum item encontrado para esta venda.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Produto</th>
                            <th>Descricao</th>
                            <th>Qtde</th>
                            <th>Unitario</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itens as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['VENDACONTA']) ?></td>
                                <td><?= htmlspecialchars($item['CODPRODUTO'] ?? $item['PRODUTO']) ?></td>
                                <td><?= htmlspecialchars($item['DESCPRODUTO'] ?? '') ?></td>
                                <td><?= number_format((float)$item['QTDE'], 3, ',', '.') ?> <?= htmlspecialchars($item['UNIDADE'] ?? '') ?></td>
                                <td>R$ <?= number_format((float)$item['VALOR'], 2, ',', '.') ?></td>
                                <td>R$ <?= number_format((float)$item['TOTPROD'], 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </td>
</tr>
<?php
}
?>

<tr class="table-secondary fw-bold">
    <td colspan="5">Total</td>
    <td>R$ <?php echo number_format($total_venda_relatorio, 2, ',', '.'); ?></td>
    <td></td>
</tr>

            </tbody>
        </table>

        <!-- =========================
             VISTA
        ========================== -->
        <h6 class="mt-4">💰 Recebimentos (Vista)</h6>

        <table class="table table-sm table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>NUMDOCORIGEM</th>
                    <th>TIPOMOV</th>
                    <th>VALOR</th>
                </tr>
            </thead>
            <tbody>

<?php
$stmt = $pdo_master->prepare("
    SELECT b.NUMDOCORIGEM, b.TIPOMOV,
           CASE 
               WHEN b.TIPOMOV = 'C' THEN b.VALORMOV
               WHEN b.TIPOMOV = 'D' THEN -b.VALORMOV
               ELSE 0
           END AS valor
    FROM armazem_bnc001 b
    INNER JOIN (
        SELECT DISTINCT VENDACONTADOR
        FROM armazem_est007
        WHERE DTLANC BETWEEN ? AND ?
          AND USERLANC = ?
          AND EMPRESA = ?
          AND CANCELADO = 'N'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
    ) e ON e.VENDACONTADOR = b.NUMDOCORIGEM
    WHERE b.EMPRESA = ?
      AND b.TIPODOCORIGEM = 'VENDA'
      AND COALESCE(b.deletado, 'N') <> 'S'
    ORDER BY b.NUMDOCORIGEM
");
$stmt->execute([$data_inicio, $data_fim, $usuario, $empresa_id, $empresa_id]);

$total_vista = 0;

while ($v = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $total_vista += $v['valor'];

    echo "<tr>
            <td>{$v['NUMDOCORIGEM']}</td>
            <td>{$v['TIPOMOV']}</td>
            <td>R$ " . number_format($v['valor'], 2, ',', '.') . "</td>
          </tr>";
}
?>

<tr class="table-secondary fw-bold">
    <td colspan="2">Total</td>
    <td>R$ <?php echo number_format($total_vista, 2, ',', '.'); ?></td>
</tr>

            </tbody>
        </table>

        <!-- =========================
             PRAZO
        ========================== -->
        <h6 class="mt-4">📅 Recebimentos (Prazo)</h6>

        <table class="table table-sm table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>NUMDOCORIGEM</th>
                    <th>PARCELA</th>
                    <th>VALOR</th>
                </tr>
            </thead>
            <tbody>

<?php
$stmt = $pdo_master->prepare("
    SELECT c.NUMDOCORIGEM, c.CMCONTADOR, c.VLRPARCELA
    FROM armazem_cr001 c
    INNER JOIN (
        SELECT DISTINCT VENDACONTADOR
        FROM armazem_est007
        WHERE DTLANC BETWEEN ? AND ?
          AND USERLANC = ?
          AND EMPRESA = ?
          AND CANCELADO = 'N'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
    ) e ON e.VENDACONTADOR = c.NUMDOCORIGEM
    WHERE c.EMPRESA = ?
      AND COALESCE(c.excluido_firebird, 'N') <> 'S'
");
$stmt->execute([$data_inicio, $data_fim, $usuario, $empresa_id, $empresa_id]);

$total_prazo = 0;

while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $total_prazo += $p['VLRPARCELA'];

    echo "<tr>
            <td>{$p['NUMDOCORIGEM']}</td>
            <td>{$p['CMCONTADOR']}</td>
            <td>R$ " . number_format($p['VLRPARCELA'], 2, ',', '.') . "</td>
          </tr>";
}
?>

<tr class="table-secondary fw-bold">
    <td colspan="2">Total</td>
    <td>R$ <?php echo number_format($total_prazo, 2, ',', '.'); ?></td>
</tr>

            </tbody>
        </table>

        <!-- =========================
             VENDAS SEM PAGAMENTO
        ========================== -->
        <h6 class="mt-4">Vendas sem pagamento completo</h6>

        <table class="table table-sm table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>Venda</th>
                    <th>Data/Hora</th>
                    <th>CM</th>
                    <th>Cliente</th>
                    <th>Valor da Venda</th>
                    <th>Recebido</th>
                    <th>Diferença</th>
                </tr>
            </thead>
            <tbody>

<?php
$stmt = $pdo_master->prepare("
    SELECT
        e.VENDACONTADOR,
        e.DTLANC,
        e.CMCONTADOR,
        e.CLIENTE,
        e.TOTGERAL,
        COALESCE(v.vista, 0) AS vista,
        COALESCE(p.prazo, 0) AS prazo,
        e.TOTGERAL - (COALESCE(v.vista, 0) + COALESCE(p.prazo, 0)) AS diferenca
    FROM armazem_est007 e
    LEFT JOIN (
        SELECT
            EMPRESA,
            NUMDOCORIGEM,
            SUM(
                CASE
                    WHEN TIPOMOV = 'C' THEN VALORMOV
                    WHEN TIPOMOV = 'D' THEN -VALORMOV
                    ELSE 0
                END
            ) AS vista
        FROM armazem_bnc001
        WHERE TIPODOCORIGEM = 'VENDA'
          AND COALESCE(deletado, 'N') <> 'S'
        GROUP BY EMPRESA, NUMDOCORIGEM
    ) v ON v.EMPRESA = e.EMPRESA
       AND v.NUMDOCORIGEM = e.VENDACONTADOR
    LEFT JOIN (
        SELECT EMPRESA, NUMDOCORIGEM, SUM(VLRPARCELA) AS prazo
        FROM armazem_cr001
        WHERE COALESCE(excluido_firebird, 'N') <> 'S'
        GROUP BY EMPRESA, NUMDOCORIGEM
    ) p ON p.EMPRESA = e.EMPRESA
       AND p.NUMDOCORIGEM = e.VENDACONTADOR
    WHERE e.DTLANC BETWEEN ? AND ?
      AND e.USERLANC = ?
      AND e.EMPRESA = ?
      AND e.CANCELADO = 'N'
      AND COALESCE(e.excluido_firebird, 'N') <> 'S'
      AND COALESCE(e.CMCONTADOR, 0) <> 10
    HAVING ABS(diferenca) >= 0.01
    ORDER BY e.DTLANC, e.VENDACONTADOR
");
$stmt->execute([$data_inicio, $data_fim, $usuario, $empresa_id]);
$vendasSemPagamento = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($vendasSemPagamento)) {
    echo '<tr><td colspan="7" class="text-center text-muted">Nenhuma venda sem pagamento completo.</td></tr>';
} else {
    foreach ($vendasSemPagamento as $pendente) {
        $recebido = (float)$pendente['vista'] + (float)$pendente['prazo'];
        echo '<tr>
                <td>' . (int)$pendente['VENDACONTADOR'] . '</td>
                <td>' . (!empty($pendente['DTLANC']) ? date('d/m/Y H:i', strtotime($pendente['DTLANC'])) : '') . '</td>
                <td>' . htmlspecialchars($pendente['CMCONTADOR'] ?? '') . '</td>
                <td>' . htmlspecialchars($pendente['CLIENTE'] ?? '') . '</td>
                <td>R$ ' . number_format((float)$pendente['TOTGERAL'], 2, ',', '.') . '</td>
                <td>R$ ' . number_format($recebido, 2, ',', '.') . '</td>
                <td class="fw-bold text-danger">R$ ' . number_format((float)$pendente['diferenca'], 2, ',', '.') . '</td>
              </tr>';
    }
}
?>

            </tbody>
        </table>

        <!-- =========================
             RESULTADO FINAL
        ========================== -->
        <div class="mt-4 alert alert-info">
            <strong>Resumo:</strong><br>
            Venda: R$ <?php echo number_format($total_venda, 2, ',', '.'); ?><br>
            Vista: R$ <?php echo number_format($total_vista, 2, ',', '.'); ?><br>
            Prazo: R$ <?php echo number_format($total_prazo, 2, ',', '.'); ?><br>

            <hr>

            <strong>Diferença:</strong>
            R$ <?php echo number_format($total_venda - ($total_vista + $total_prazo), 2, ',', '.'); ?>
        </div>

    </div>
</div>

<?php require '../../layout/footer.php'; ?>
