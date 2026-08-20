<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$acertoId = (int)($_GET['id'] ?? 0);

if ($acertoId <= 0) {
    http_response_code(400);
    exit('Acerto invalido.');
}

$stmt = $pdo_master->prepare("
    SELECT a.*, COALESCE(NULLIF(c.TITULAR, ''), NULLIF(c.DESCABREV, ''), CONCAT('Conta ', a.cbcontador)) AS conta_nome
    FROM financeiro_acertos_extrato a
    LEFT JOIN armazem_bnc002 c
      ON c.EMPRESA = a.empresa_id
     AND c.CBCONTADOR = a.cbcontador
    WHERE a.id = ?
      AND a.empresa_id = ?
    LIMIT 1
");
$stmt->execute([$acertoId, $empresaId]);
$acerto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$acerto) {
    http_response_code(404);
    exit('Acerto nao encontrado.');
}

$stmt = $pdo_master->prepare("
    SELECT ai.movcontador, ai.tipo_mov, ai.valor, b.DTMOV, b.TIPOES, b.HISTMOV
    FROM financeiro_acertos_extrato_itens ai
    LEFT JOIN armazem_bnc001 b
      ON b.EMPRESA = ai.empresa_id
     AND b.MOVCONTADOR = ai.movcontador
    WHERE ai.acerto_id = ?
      AND ai.empresa_id = ?
    ORDER BY b.DTMOV, ai.movcontador
");
$stmt->execute([$acertoId, $empresaId]);
$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

function acertoPdfTexto($valor): string
{
    $texto = preg_replace('/\s+/', ' ', trim((string)$valor));
    $convertido = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $texto);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $convertido === false ? $texto : $convertido);
}

function acertoPdfLinha(float $x1, float $y1, float $x2, float $y2): string
{
    return "0.78 0.82 0.88 RG {$x1} {$y1} m {$x2} {$y2} l S\n";
}

function acertoPdfEscrever(float $x, float $y, int $tamanho, string $texto, bool $negrito = false): string
{
    $fonte = $negrito ? 'F2' : 'F1';
    return "BT /{$fonte} {$tamanho} Tf {$x} {$y} Td (" . acertoPdfTexto($texto) . ") Tj ET\n";
}

function acertoPdfMoeda($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function acertoPdfQuebrar(string $texto, int $limite = 64): array
{
    $texto = preg_replace('/\s+/', ' ', trim($texto));
    if ($texto === '') {
        return ['-'];
    }
    return explode("\n", wordwrap($texto, $limite, "\n", true));
}

$largura = 842;
$altura = 595;
$margem = 28;
$topoTabela = 442;
$rodape = 24;
$linhasPorPagina = 18;
$paginasItens = array_chunk($itens, $linhasPorPagina);
if (!$paginasItens) {
    $paginasItens = [[]];
}

$paginas = [];
$totalPaginas = count($paginasItens);
foreach ($paginasItens as $indicePagina => $itensPagina) {
    $conteudo = "0.08 0.22 0.48 rg 0 535 {$largura} 60 re f\n";
    $conteudo .= "1 1 1 rg\n";
    $conteudo .= acertoPdfEscrever($margem, 565, 16, 'SUPERDUNGA - ACERTO FINANCEIRO', true);
    $conteudo .= acertoPdfEscrever($margem, 546, 10, 'Acerto #' . $acertoId . ' - Empresa ' . $empresaId);
    $conteudo .= "0 g\n";
    $conteudo .= acertoPdfEscrever($margem, 518, 12, (string)$acerto['descricao'], true);
    $conteudo .= acertoPdfEscrever($margem, 500, 9, 'Conta: ' . (int)$acerto['cbcontador'] . ' - ' . $acerto['conta_nome']);
    $conteudo .= acertoPdfEscrever(410, 500, 9, 'Data: ' . date('d/m/Y', strtotime((string)$acerto['data_acerto'])));
    $conteudo .= acertoPdfEscrever($margem, 483, 9, 'Debitos: ' . acertoPdfMoeda($acerto['total_debitos']) . '   Creditos: ' . acertoPdfMoeda($acerto['total_creditos']) . '   Diferenca: ' . acertoPdfMoeda($acerto['diferenca']));

    $conteudo .= "0.90 0.94 0.98 rg {$margem} {$topoTabela} 786 22 re f\n0 g\n";
    $conteudo .= acertoPdfEscrever(34, $topoTabela + 8, 8, 'MOV.', true);
    $conteudo .= acertoPdfEscrever(84, $topoTabela + 8, 8, 'DATA', true);
    $conteudo .= acertoPdfEscrever(145, $topoTabela + 8, 8, 'TIPOES', true);
    $conteudo .= acertoPdfEscrever(195, $topoTabela + 8, 8, 'HISTORICO', true);
    $conteudo .= acertoPdfEscrever(704, $topoTabela + 8, 8, 'D/C', true);
    $conteudo .= acertoPdfEscrever(745, $topoTabela + 8, 8, 'VALOR', true);

    $y = $topoTabela - 18;
    foreach ($itensPagina as $item) {
        $historico = acertoPdfQuebrar((string)($item['HISTMOV'] ?? ''), 76);
        $conteudo .= acertoPdfEscrever(34, $y, 8, (string)$item['movcontador']);
        $conteudo .= acertoPdfEscrever(84, $y, 8, !empty($item['DTMOV']) ? date('d/m/Y', strtotime((string)$item['DTMOV'])) : '-');
        $conteudo .= acertoPdfEscrever(145, $y, 8, (string)($item['TIPOES'] ?? '-'));
        $conteudo .= acertoPdfEscrever(195, $y, 8, $historico[0] ?? '-');
        $conteudo .= acertoPdfEscrever(710, $y, 8, (string)$item['tipo_mov'], true);
        $conteudo .= acertoPdfEscrever(742, $y, 8, acertoPdfMoeda($item['valor']));
        $conteudo .= acertoPdfLinha($margem, $y - 6, 814, $y - 6);
        $y -= 22;
    }

    if (!$itensPagina) {
        $conteudo .= acertoPdfEscrever($margem, $y, 9, 'Nenhum movimento vinculado ao acerto.');
    }

    $conteudo .= acertoPdfEscrever($margem, $rodape, 8, 'Gerado em ' . date('d/m/Y H:i') . ' - Pagina ' . ($indicePagina + 1) . ' de ' . $totalPaginas);
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
    $objetos[$paginaId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$largura} {$altura}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$conteudoId} 0 R >>";
    $idsPaginas[] = $paginaId;
}
$objetos[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static function ($id) {
    return "{$id} 0 R";
}, $idsPaginas)) . '] /Count ' . count($idsPaginas) . ' >>';
ksort($objetos);

$pdf = "%PDF-1.4\n";
$offsets = [0 => 0];
foreach ($objetos as $id => $objeto) {
    $offsets[$id] = strlen($pdf);
    $pdf .= "{$id} 0 obj\n{$objeto}\nendobj\n";
}
$xref = strlen($pdf);
$pdf .= 'xref' . "\n0 " . (count($objetos) + 1) . "\n0000000000 65535 f \n";
for ($i = 1; $i <= count($objetos); $i++) {
    $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
}
$pdf .= "trailer\n<< /Size " . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

$arquivo = 'acerto_' . $acertoId . '_' . date('Ymd', strtotime((string)$acerto['data_acerto'])) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $arquivo . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
exit;
