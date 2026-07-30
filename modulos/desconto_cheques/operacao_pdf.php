<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

garantirTabelasDescontoCheques($pdo_master);

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$operacaoId = (int)($_GET['id'] ?? 0);

$stmtOperacao = $pdo_master->prepare("
    SELECT
        o.*,
        c.nome AS cliente_nome,
        c.celular,
        c.taxa_desconto,
        c.usa_adicional_prazo,
        c.limite_credito
    FROM desconto_cheques_operacoes o
    INNER JOIN desconto_cheques_clientes c ON c.id = o.cliente_id
    WHERE o.id = ?
      AND o.empresa_id = ?
    LIMIT 1
");
$stmtOperacao->execute([$operacaoId, $empresaId]);
$operacao = $stmtOperacao->fetch(PDO::FETCH_ASSOC);

if (!$operacao) {
    http_response_code(404);
    echo 'Operacao nao encontrada.';
    exit;
}

$stmtDocs = $pdo_master->prepare("
    SELECT *
    FROM desconto_cheques_documentos
    WHERE operacao_id = ?
    ORDER BY data_vencimento, id
");
$stmtDocs->execute([$operacaoId]);
$documentos = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

$valorLiquidoTitulos = 0.0;
foreach ($documentos as $documento) {
    $valorLiquidoTitulos += (float)$documento['valor_liquido'];
}

$basePublicDir = dirname(__DIR__, 2);
$anexosCheques = [];
foreach ($documentos as $doc) {
    if (($doc['tipo_documento'] ?? '') !== 'CHEQUE') {
        continue;
    }

    $frenteCaminho = (string)($doc['arquivo_frente_caminho'] ?: $doc['arquivo_caminho'] ?: '');
    $frenteNome = (string)($doc['arquivo_frente_nome'] ?: $doc['arquivo_nome'] ?: 'Frente do cheque');
    $versoCaminho = (string)($doc['arquivo_verso_caminho'] ?? '');
    $versoNome = (string)($doc['arquivo_verso_nome'] ?: 'Verso do cheque');

    foreach ([
        ['lado' => 'Frente', 'caminho' => $frenteCaminho, 'nome' => $frenteNome],
        ['lado' => 'Verso', 'caminho' => $versoCaminho, 'nome' => $versoNome],
    ] as $anexo) {
        if ($anexo['caminho'] === '') {
            continue;
        }

        $arquivoAbs = $basePublicDir . '/' . ltrim($anexo['caminho'], '/\\');
        $ext = strtolower(pathinfo($anexo['caminho'], PATHINFO_EXTENSION));
        $larguraImagem = 0;
        $alturaImagem = 0;
        if (is_file($arquivoAbs) && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            $dimensoes = @getimagesize($arquivoAbs);
            if (is_array($dimensoes)) {
                $larguraImagem = (int)($dimensoes[0] ?? 0);
                $alturaImagem = (int)($dimensoes[1] ?? 0);
            }
        }
        $anexosCheques[] = [
            'documento' => trim((string)($doc['numero_documento'] ?? '')),
            'lado' => $anexo['lado'],
            'nome' => $anexo['nome'],
            'caminho' => $anexo['caminho'],
            'existe' => is_file($arquivoAbs),
            'imagem' => in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true),
            'rotacionar' => $alturaImagem > $larguraImagem,
        ];
    }
}

$nomeArquivo = 'desconto_cheques_operacao_' . $operacaoId . '.pdf';

function textoPdfDescontoCheques($valor, int $limite = 0): string
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

function escaparPdfDescontoCheques(string $texto): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

function comandoTextoPdfDescontoCheques(float $x, float $y, int $tamanho, string $texto, bool $negrito = false): string
{
    $fonte = $negrito ? 'F2' : 'F1';
    return "BT /{$fonte} {$tamanho} Tf 1 0 0 1 " . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . escaparPdfDescontoCheques($texto) . ") Tj ET\n";
}

function corPdfDescontoCheques(float $r, float $g, float $b): string
{
    return number_format($r, 3, '.', '') . ' ' . number_format($g, 3, '.', '') . ' ' . number_format($b, 3, '.', '') . " rg\n";
}

function retanguloPdfDescontoCheques(float $x, float $y, float $w, float $h): string
{
    return number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' ' . number_format($w, 2, '.', '') . ' ' . number_format($h, 2, '.', '') . " re f\n";
}

function enviarPdfDescontoCheques(array $paginas, string $arquivo): void
{
    $largura = 595;
    $altura = 842;
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

function gerarPdfArquivoDescontoCheques(array $operacao, array $documentos, float $valorLiquidoTitulos, int $operacaoId, string $nomeArquivo): void
{
    $largura = 595;
    $altura = 842;
    $margem = 28;
    $paginas = [];
    $conteudo = '';
    $pagina = 0;

    $novaPagina = static function () use (&$conteudo, &$pagina, $largura, $altura, $margem, $operacao, $operacaoId): float {
        $pagina++;
        $conteudo = '';
        $conteudo .= corPdfDescontoCheques(0.08, 0.18, 0.40);
        $conteudo .= retanguloPdfDescontoCheques(0, $altura - 88, $largura, 88);
        $conteudo .= "1 1 1 rg\n";
        $conteudo .= comandoTextoPdfDescontoCheques($margem, $altura - 34, 17, textoPdfDescontoCheques('OPERACAO DE DESCONTO DE CHEQUES #' . $operacaoId), true);
        $conteudo .= comandoTextoPdfDescontoCheques($margem, $altura - 55, 10, textoPdfDescontoCheques('Cliente: ' . ($operacao['cliente_nome'] ?? ''), 95));
        $conteudo .= comandoTextoPdfDescontoCheques($margem, $altura - 71, 9, textoPdfDescontoCheques('Data: ' . dataBRDC($operacao['data_referencia'] ?? '') . ' | Status: ' . ($operacao['status'] ?? '')), false);
        $conteudo .= "0 0 0 rg\n";
        return $altura - 118;
    };

    $salvarPagina = static function () use (&$paginas, &$conteudo): void {
        if ($conteudo !== '') {
            $paginas[] = $conteudo;
        }
    };

    $linhaTexto = static function (float $x, float &$y, int $tamanho, string $texto, bool $negrito = false) use (&$conteudo): void {
        $conteudo .= comandoTextoPdfDescontoCheques($x, $y, $tamanho, textoPdfDescontoCheques($texto), $negrito);
        $y -= $tamanho + 6;
    };

    $y = $novaPagina();
    $conteudo .= corPdfDescontoCheques(0.93, 0.96, 0.99);
    $conteudo .= retanguloPdfDescontoCheques($margem, $y - 74, $largura - ($margem * 2), 74);
    $conteudo .= "0 0 0 rg\n";
    $resumoTituloY = $y - 18;
    $linhaTexto($margem + 10, $resumoTituloY, 12, 'Resumo da operacao', true);
    $resumoY = $y - 42;
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 10, $resumoY, 9, textoPdfDescontoCheques('Valor bruto: ' . moedaDC($operacao['valor_bruto'] ?? 0)), true);
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 160, $resumoY, 9, textoPdfDescontoCheques('Desconto: ' . moedaDC($operacao['valor_desconto'] ?? 0)), true);
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 310, $resumoY, 9, textoPdfDescontoCheques('Liquido titulos: ' . moedaDC($valorLiquidoTitulos)), true);
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 10, $resumoY - 18, 9, textoPdfDescontoCheques('Taxas/tarifas: ' . moedaDC($operacao['valor_taxas_tarifas'] ?? 0)));
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 160, $resumoY - 18, 9, textoPdfDescontoCheques('Valores a descontar: ' . moedaDC($operacao['valor_descontar'] ?? 0)));
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 310, $resumoY - 18, 9, textoPdfDescontoCheques('Liquido operacao: ' . moedaDC($operacao['valor_liquido'] ?? 0)), true);

    $y -= 102;
    $linhaTexto($margem, $y, 12, 'Titulos', true);
    $conteudo .= corPdfDescontoCheques(0.08, 0.18, 0.40);
    $conteudo .= retanguloPdfDescontoCheques($margem, $y - 15, $largura - ($margem * 2), 18);
    $conteudo .= "1 1 1 rg\n";
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 6, $y - 10, 8, textoPdfDescontoCheques('Documento'), true);
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 92, $y - 10, 8, textoPdfDescontoCheques('Emissor'), true);
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 280, $y - 10, 8, textoPdfDescontoCheques('Venc.'), true);
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 340, $y - 10, 8, textoPdfDescontoCheques('Dias'), true);
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 382, $y - 10, 8, textoPdfDescontoCheques('Valor'), true);
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 448, $y - 10, 8, textoPdfDescontoCheques('Desc.'), true);
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 506, $y - 10, 8, textoPdfDescontoCheques('Liq.'), true);
    $conteudo .= "0 0 0 rg\n";
    $y -= 35;

    foreach ($documentos as $doc) {
        if ($y < 64) {
            $salvarPagina();
            $y = $novaPagina();
            $linhaTexto($margem, $y, 12, 'Titulos (continuidade)', true);
        }
        $emissor = trim((string)($doc['nome_emissor'] ?: '-'));
        if (!empty($doc['cnpj_cpf_emissor'])) {
            $emissor .= ' | ' . formatarCpfCnpjDC($doc['cnpj_cpf_emissor']);
        }
        $conteudo .= comandoTextoPdfDescontoCheques($margem + 6, $y, 8, textoPdfDescontoCheques(trim((string)$doc['tipo_documento'] . ' ' . (string)$doc['numero_documento']), 18));
        $conteudo .= comandoTextoPdfDescontoCheques($margem + 92, $y, 8, textoPdfDescontoCheques($emissor, 38));
        $conteudo .= comandoTextoPdfDescontoCheques($margem + 280, $y, 8, textoPdfDescontoCheques(dataBRDC($doc['data_vencimento'] ?? '')));
        $conteudo .= comandoTextoPdfDescontoCheques($margem + 345, $y, 8, textoPdfDescontoCheques((string)(int)($doc['prazo_dias'] ?? 0)));
        $conteudo .= comandoTextoPdfDescontoCheques($margem + 382, $y, 8, textoPdfDescontoCheques(moedaDC($doc['valor'] ?? 0)));
        $conteudo .= comandoTextoPdfDescontoCheques($margem + 448, $y, 8, textoPdfDescontoCheques(moedaDC($doc['desconto_valor'] ?? 0)));
        $conteudo .= comandoTextoPdfDescontoCheques($margem + 506, $y, 8, textoPdfDescontoCheques(moedaDC($doc['valor_liquido'] ?? 0)));
        $y -= 17;
    }

    $y -= 10;
    if ($y < 92) {
        $salvarPagina();
        $y = $novaPagina();
    }
    $conteudo .= corPdfDescontoCheques(0.93, 0.96, 0.99);
    $conteudo .= retanguloPdfDescontoCheques($margem, $y - 34, $largura - ($margem * 2), 34);
    $conteudo .= "0 0 0 rg\n";
    $conteudo .= comandoTextoPdfDescontoCheques($margem + 10, $y - 22, 11, textoPdfDescontoCheques('Valor liquido da operacao: ' . moedaDC($operacao['valor_liquido'] ?? 0)), true);

    if ((float)($operacao['valor_taxas_tarifas'] ?? 0) > 0 || (float)($operacao['valor_descontar'] ?? 0) > 0) {
        $y -= 58;
        if ($y < 90) {
            $salvarPagina();
            $y = $novaPagina();
        }
        $linhaTexto($margem, $y, 12, 'Ajustes da operacao', true);
        if ((float)($operacao['valor_taxas_tarifas'] ?? 0) > 0) {
            $linhaTexto($margem + 8, $y, 9, 'Taxas/tarifas: ' . moedaDC($operacao['valor_taxas_tarifas']) . ' - ' . (string)($operacao['historico_taxas_tarifas'] ?? ''));
        }
        if ((float)($operacao['valor_descontar'] ?? 0) > 0) {
            $linhaTexto($margem + 8, $y, 9, 'Valores a descontar: ' . moedaDC($operacao['valor_descontar']) . ' - ' . (string)($operacao['historico_descontar'] ?? ''));
        }
    }

    $y -= 18;
    if (!empty($documentos)) {
        $linhaTexto($margem, $y, 8, 'Observacao: para visualizar as fotos dos cheques, abra a operacao no sistema.', false);
    }
    $salvarPagina();
    enviarPdfDescontoCheques($paginas, $nomeArquivo);
}

if (($_GET['download'] ?? '') === '1') {
    gerarPdfArquivoDescontoCheques($operacao, $documentos, $valorLiquidoTitulos, $operacaoId, $nomeArquivo);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Operacao de Desconto #<?= (int)$operacaoId ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #111827;
            margin: 0;
            background: #e5e7eb;
        }

        .page {
            width: 190mm;
            min-height: 277mm;
            margin: 12mm auto;
            background: #fff;
            box-shadow: 0 8px 30px rgba(15, 23, 42, .16);
        }

        .topo {
            background: #163272;
            color: #fff;
            padding: 18mm 16mm 10mm;
        }

        .topo h1 {
            margin: 0 0 5mm;
            font-size: 21px;
            letter-spacing: .3px;
        }

        .topo .meta {
            font-size: 12px;
            line-height: 1.55;
        }

        .content {
            padding: 12mm 16mm 16mm;
        }

        .section-title {
            background: #e8eef8;
            color: #0f2d68;
            font-weight: bold;
            padding: 7px 9px;
            margin: 0 0 8px;
            font-size: 13px;
            text-transform: uppercase;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            margin-bottom: 14px;
        }

        .box {
            border: 1px solid #d9e2ef;
            padding: 9px;
            border-radius: 4px;
        }

        .box .label {
            font-size: 10px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 3px;
        }

        .box .value {
            font-size: 14px;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 11px;
            table-layout: fixed;
        }

        th, td {
            border: 1px solid #d9e2ef;
            padding: 6px;
            vertical-align: middle;
        }

        th {
            background: #163272;
            color: #fff;
            text-align: left;
        }

        .text-end {
            text-align: right;
        }

        .total-row td {
            font-weight: bold;
            background: #f8fafc;
        }

        .final {
            border: 2px solid #163272;
            padding: 11px;
            text-align: right;
            font-size: 17px;
            font-weight: bold;
            color: #0f2d68;
        }

        .titulos th:nth-child(1) { width: 16%; }
        .titulos th:nth-child(2) { width: 29%; }
        .titulos th:nth-child(3) { width: 12%; }
        .titulos th:nth-child(4) { width: 8%; }
        .titulos th:nth-child(5) { width: 12%; }
        .titulos th:nth-child(6) { width: 11%; }
        .titulos th:nth-child(7) { width: 12%; }

        .anexos {
            margin-top: 16px;
            page-break-before: auto;
        }

        .anexo-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .anexo-card {
            border: 1px solid #d9e2ef;
            border-radius: 4px;
            padding: 8px;
            page-break-inside: avoid;
        }

        .anexo-title {
            color: #0f2d68;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .anexo-frame {
            align-items: center;
            background: #f8fafc;
            border: 1px solid #eef2f7;
            display: flex;
            height: 80mm;
            justify-content: center;
            overflow: hidden;
            position: relative;
            width: 170mm;
        }

        .anexo-frame img {
            display: block;
            max-height: 80mm;
            max-width: 170mm;
            object-fit: contain;
        }

        .anexo-frame.rotacionar img {
            height: auto;
            max-height: 170mm;
            max-width: 80mm;
            transform: rotate(-90deg);
            transform-origin: center center;
            width: auto;
        }

        .anexo-card {
            max-width: 100%;
        }

        .no-print {
            text-align: center;
            margin: 14px 0;
        }

        .no-print button {
            background: #163272;
            color: #fff;
            border: 0;
            border-radius: 4px;
            padding: 10px 16px;
            cursor: pointer;
        }

        @media print {
            body {
                background: #fff;
            }

            .page {
                margin: 0;
                width: auto;
                min-height: auto;
                box-shadow: none;
            }

            .no-print {
                display: none;
            }

            @page {
                size: A4;
                margin: 0;
            }
        }
    </style>
</head>
<body>
<div class="no-print">
    <button onclick="window.print()">Salvar em PDF</button>
</div>

<main class="page">
    <header class="topo">
        <h1>OPERACAO DE DESCONTO DE CHEQUES #<?= (int)$operacaoId ?></h1>
        <div class="meta">
            Cliente: <?= htmlspecialchars($operacao['cliente_nome']) ?><br>
            Data de referencia: <?= dataBRDC($operacao['data_referencia']) ?> | Status: <?= htmlspecialchars($operacao['status']) ?><br>
            Taxa mensal cadastrada: <?= percentualDC($operacao['taxa_desconto']) ?> | Adicional de prazo: <?= $operacao['usa_adicional_prazo'] === 'S' ? 'Sim' : 'Nao' ?>
        </div>
    </header>

    <section class="content">
        <div class="section-title">Resumo da operacao</div>
        <div class="grid">
            <div class="box"><div class="label">Valor bruto</div><div class="value"><?= moedaDC($operacao['valor_bruto']) ?></div></div>
            <div class="box"><div class="label">Desconto dos titulos</div><div class="value"><?= moedaDC($operacao['valor_desconto']) ?></div></div>
            <div class="box"><div class="label">Liquido dos titulos</div><div class="value"><?= moedaDC($valorLiquidoTitulos) ?></div></div>
            <?php if ((float)$operacao['valor_taxas_tarifas'] > 0): ?>
                <div class="box"><div class="label">Taxas/tarifas</div><div class="value"><?= moedaDC($operacao['valor_taxas_tarifas']) ?></div></div>
            <?php endif; ?>
            <?php if ((float)$operacao['valor_descontar'] > 0): ?>
                <div class="box"><div class="label">Valores a descontar</div><div class="value"><?= moedaDC($operacao['valor_descontar']) ?></div></div>
            <?php endif; ?>
        </div>

        <div class="section-title">Titulos</div>
        <table class="titulos">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Emissor</th>
                    <th>Vencimento</th>
                    <th class="text-end">Dias</th>
                    <th class="text-end">Valor</th>
                    <th class="text-end">Desconto</th>
                    <th class="text-end">Liquido</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($documentos as $doc): ?>
                    <tr>
                        <td><?= htmlspecialchars($doc['tipo_documento']) ?> <?= htmlspecialchars((string)$doc['numero_documento']) ?></td>
                        <td>
                            <?= htmlspecialchars((string)($doc['nome_emissor'] ?: '-')) ?>
                            <?php if (!empty($doc['cnpj_cpf_emissor'])): ?>
                                | <?= htmlspecialchars(formatarCpfCnpjDC($doc['cnpj_cpf_emissor'])) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= dataBRDC($doc['data_vencimento']) ?></td>
                        <td class="text-end"><?= (int)$doc['prazo_dias'] ?></td>
                        <td class="text-end"><?= moedaDC($doc['valor']) ?></td>
                        <td class="text-end"><?= moedaDC($doc['desconto_valor']) ?></td>
                        <td class="text-end"><?= moedaDC($doc['valor_liquido']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="4">Total</td>
                    <td class="text-end"><?= moedaDC($operacao['valor_bruto']) ?></td>
                    <td class="text-end"><?= moedaDC($operacao['valor_desconto']) ?></td>
                    <td class="text-end"><?= moedaDC($valorLiquidoTitulos) ?></td>
                </tr>
            </tbody>
        </table>

        <?php if ((float)$operacao['valor_taxas_tarifas'] > 0 || (float)$operacao['valor_descontar'] > 0): ?>
            <div class="section-title">Ajustes da operacao</div>
            <table>
                <tbody>
                    <?php if ((float)$operacao['valor_taxas_tarifas'] > 0): ?>
                        <tr>
                            <td>Taxas/tarifas</td>
                            <td><?= htmlspecialchars((string)$operacao['historico_taxas_tarifas']) ?></td>
                            <td class="text-end"><?= moedaDC($operacao['valor_taxas_tarifas']) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ((float)$operacao['valor_descontar'] > 0): ?>
                        <tr>
                            <td>Valores a descontar</td>
                            <td><?= htmlspecialchars((string)$operacao['historico_descontar']) ?></td>
                            <td class="text-end"><?= moedaDC($operacao['valor_descontar']) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="final">Valor liquido da operacao: <?= moedaDC($operacao['valor_liquido']) ?></div>

        <?php if (!empty($anexosCheques)): ?>
            <section class="anexos">
                <div class="section-title">Anexos dos cheques</div>
                <div class="anexo-grid">
                    <?php foreach ($anexosCheques as $anexo): ?>
                        <div class="anexo-card">
                            <div class="anexo-title">
                                Cheque <?= htmlspecialchars($anexo['documento'] ?: '-') ?> - <?= htmlspecialchars($anexo['lado']) ?>
                            </div>
                            <?php if ($anexo['existe'] && $anexo['imagem']): ?>
                                <div class="anexo-frame <?= $anexo['rotacionar'] ? 'rotacionar' : '' ?>">
                                    <img src="../../<?= htmlspecialchars($anexo['caminho']) ?>" alt="<?= htmlspecialchars($anexo['lado'] . ' do cheque ' . ($anexo['documento'] ?: '')) ?>">
                                </div>
                            <?php else: ?>
                                <a target="_blank" href="../../<?= htmlspecialchars($anexo['caminho']) ?>"><?= htmlspecialchars($anexo['nome'] ?: 'Abrir anexo') ?></a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </section>
</main>

<script>
document.title = <?= json_encode($nomeArquivo) ?>;
</script>
</body>
</html>
