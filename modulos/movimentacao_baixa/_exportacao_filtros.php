<?php

function mbxTextoPdf($valor, $limite = 0)
{
    $texto = preg_replace('/\s+/', ' ', trim((string)$valor));
    if ($limite > 0 && strlen($texto) > $limite) {
        $texto = substr($texto, 0, max(0, $limite - 3)) . '...';
    }
    $convertido = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto);
    return $convertido === false ? $texto : $convertido;
}

function mbxEscaparPdf($texto)
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

function mbxComandoPdf($x, $y, $tamanho, $texto, $negrito = false)
{
    $fonte = $negrito ? 'F2' : 'F1';
    return 'BT /' . $fonte . ' ' . (int)$tamanho . ' Tf 1 0 0 1 '
        . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '')
        . ' Tm (' . mbxEscaparPdf($texto) . ") Tj ET\n";
}

function mbxExportarCsv($nomeArquivo, array $cabecalhos, array $linhas)
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, $cabecalhos, ';');
    foreach ($linhas as $linha) {
        fputcsv($saida, $linha, ';');
    }
    fclose($saida);
    exit;
}

function mbxExportarPdf($nomeArquivo, $titulo, array $metadados, array $colunas, array $linhas)
{
    $largura = 842;
    $altura = 595;
    $margem = 24;
    $linhaAltura = 15;
    $topoTabela = $altura - 106;
    $linhasPorPagina = max(1, (int)floor(($topoTabela - 38) / $linhaAltura));
    $totalPaginas = max(1, (int)ceil(count($linhas) / $linhasPorPagina));
    $paginas = [];

    for ($pagina = 0; $pagina < $totalPaginas; $pagina++) {
        $conteudo = mbxComandoPdf($margem, $altura - 34, 15, mbxTextoPdf($titulo, 100), true);
        $yMeta = $altura - 51;
        foreach ($metadados as $meta) {
            $conteudo .= mbxComandoPdf($margem, $yMeta, 8, mbxTextoPdf($meta, 150));
            $yMeta -= 10;
        }
        $conteudo .= "0.90 0.93 0.97 rg {$margem} " . ($topoTabela - 4) . ' ' . ($largura - ($margem * 2)) . " 18 re f\n0 g\n";
        $x = $margem + 3;
        foreach ($colunas as $coluna) {
            $conteudo .= mbxComandoPdf($x, $topoTabela + 2, 7, mbxTextoPdf($coluna['titulo'], $coluna['limite']), true);
            $x += $coluna['largura'];
        }
        $y = $topoTabela - 15;
        foreach (array_slice($linhas, $pagina * $linhasPorPagina, $linhasPorPagina) as $linha) {
            $x = $margem + 3;
            foreach ($colunas as $indice => $coluna) {
                $conteudo .= mbxComandoPdf($x, $y, 7, mbxTextoPdf(isset($linha[$indice]) ? $linha[$indice] : '', $coluna['limite']));
                $x += $coluna['largura'];
            }
            $y -= $linhaAltura;
        }
        $conteudo .= mbxComandoPdf($margem, 18, 7, mbxTextoPdf('Gerado em ' . date('d/m/Y H:i') . ' - Pagina ' . ($pagina + 1) . ' de ' . $totalPaginas));
        $paginas[] = $conteudo;
    }

    $objetos = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
    ];
    $idsPaginas = [];
    $proximoId = 5;
    foreach ($paginas as $conteudo) {
        $conteudoId = $proximoId++;
        $paginaId = $proximoId++;
        $objetos[$conteudoId] = '<< /Length ' . strlen($conteudo) . ">>\nstream\n{$conteudo}endstream";
        $objetos[$paginaId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $largura . ' ' . $altura . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $conteudoId . ' 0 R >>';
        $idsPaginas[] = $paginaId;
    }
    $referencias = array_map(static function ($id) {
        return $id . ' 0 R';
    }, $idsPaginas);
    $objetos[2] = '<< /Type /Pages /Kids [' . implode(' ', $referencias) . '] /Count ' . count($idsPaginas) . ' >>';
    ksort($objetos);

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objetos as $id => $objeto) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $objeto . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objetos) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objetos); $i++) {
        $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function mbxUrlExportacao($formato)
{
    $query = $_GET;
    unset($query['editar']);
    $query['exportar'] = $formato;
    return http_build_query($query);
}
