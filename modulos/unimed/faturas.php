<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

garantirTabelasUnimed($pdo_master);

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$mensagemSucesso = '';
$mensagemErro = '';

function textoPdfRelatorioUnimed($valor, int $limite = 0): string
{
    $texto = preg_replace('/\s+/', ' ', trim((string)$valor));
    if ($limite > 0 && strlen($texto) > $limite) {
        $texto = substr($texto, 0, max(0, $limite - 3)) . '...';
    }
    $convertido = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto);
    return $convertido === false ? $texto : $convertido;
}

function escapePdfRelatorioUnimed(string $texto): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

function textoCmdPdfRelatorioUnimed(float $x, float $y, int $tamanho, string $texto, bool $negrito = false): string
{
    $fonte = $negrito ? 'F2' : 'F1';
    return "BT /{$fonte} {$tamanho} Tf 1 0 0 1 " . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . escapePdfRelatorioUnimed($texto) . ") Tj ET\n";
}

function retanguloPdfRelatorioUnimed(float $x, float $y, float $w, float $h): string
{
    return number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' ' . number_format($w, 2, '.', '') . ' ' . number_format($h, 2, '.', '') . " re f\n";
}

function enviarPdfRelatorioUnimed(array $paginas, string $arquivo): void
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
        $conteudo = (string)$pagina['conteudo'];
        $conteudoId = $proximoId++;
        $paginaId = $proximoId++;
        $objetos[$conteudoId] = "<< /Length " . strlen($conteudo) . " >>\nstream\n{$conteudo}endstream";
        $objetos[$paginaId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$conteudoId} 0 R >>";
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
    header('Content-Disposition: attachment; filename="' . $arquivo . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function gerarPdfResponsaveisUnimed(array $responsaveisRelatorio, array $faturaAtual, string $nomeArquivoRelatorio): void
{
    $paginas = [];
    $conteudo = '';
    $y = 0.0;
    $margem = 28.0;
    $largura = 595.0;
    $altura = 842.0;

    $novaPagina = static function () use (&$conteudo, &$y, $margem, $largura, $altura, $faturaAtual): void {
        $conteudo = "0.07 0.20 0.42 rg\n";
        $conteudo .= retanguloPdfRelatorioUnimed(0, $altura - 62, $largura, 62);
        $conteudo .= "1 1 1 rg\n";
        $conteudo .= textoCmdPdfRelatorioUnimed($margem, $altura - 25, 15, textoPdfRelatorioUnimed('DEMONSTRATIVO UNIMED POR RESPONSAVEL'), true);
        $conteudo .= textoCmdPdfRelatorioUnimed($margem, $altura - 43, 9, textoPdfRelatorioUnimed('Fatura mensal: ' . ($faturaAtual['numero_fatura'] ?? '-') . ' - ' . competenciaUnimed($faturaAtual['competencia'] ?? '')));
        $utilizacao = !empty($faturaAtual['numero_fatura_utilizacao']) ? (string)$faturaAtual['numero_fatura_utilizacao'] : '-';
        $conteudo .= textoCmdPdfRelatorioUnimed(355, $altura - 43, 9, textoPdfRelatorioUnimed('Fatura utilizacao: ' . $utilizacao));
        $conteudo .= "0 0 0 rg\n";
        $y = $altura - 86;
    };

    $salvarPagina = static function () use (&$paginas, &$conteudo): void {
        if ($conteudo !== '') {
            $paginas[] = ['conteudo' => $conteudo];
        }
    };

    $linha = static function (string $texto, int $tamanho = 8, bool $negrito = false) use (&$conteudo, &$y, $margem, $novaPagina, $salvarPagina): void {
        if ($y < 42) {
            $salvarPagina();
            $novaPagina();
        }
        $conteudo .= textoCmdPdfRelatorioUnimed($margem, $y, $tamanho, textoPdfRelatorioUnimed($texto, 118), $negrito);
        $y -= $tamanho + 5;
    };

    $novaPagina();

    if (empty($responsaveisRelatorio)) {
        $linha('Nenhum responsavel encontrado para esta fatura.', 10, true);
        $salvarPagina();
        enviarPdfRelatorioUnimed($paginas, $nomeArquivoRelatorio);
    }

    foreach ($responsaveisRelatorio as $responsavel) {
        if ($y < 170) {
            $salvarPagina();
            $novaPagina();
        }

        $totalResponsavel = (float)$responsavel['mensalidade'] + (float)$responsavel['utilizacao'];
        $linha('Responsavel: ' . $responsavel['nome'], 11, true);
        $linha('Telefone: ' . (($responsavel['telefone'] ?? '') !== '' ? $responsavel['telefone'] : '-') . ' | Codigo: ' . (($responsavel['codigo'] ?? '') !== '' ? $responsavel['codigo'] : '-'), 8);
        $linha('Mensalidade: ' . moedaUnimed($responsavel['mensalidade']) . ' | Utilizacao: ' . moedaUnimed($responsavel['utilizacao']) . ' | Total: ' . moedaUnimed($totalResponsavel), 9, true);

        $beneficiariosResp = $responsavel['beneficiarios'];
        uasort($beneficiariosResp, static function (array $a, array $b): int {
            return strcasecmp($a['nome'], $b['nome']);
        });

        $linha('Resumo por beneficiario', 8, true);
        foreach ($beneficiariosResp as $beneficiario) {
            $linha($beneficiario['codigo'] . ' | ' . $beneficiario['nome'] . ' | Mens. ' . moedaUnimed($beneficiario['mensalidade']) . ' | Util. ' . moedaUnimed($beneficiario['utilizacao']) . ' | Total ' . moedaUnimed((float)$beneficiario['mensalidade'] + (float)$beneficiario['utilizacao']), 7);
        }

        $linha('Mensalidades', 8, true);
        foreach ($responsavel['mensalidades'] as $mensalidade) {
            $linha($mensalidade['codigo_completo'] . ' | ' . $mensalidade['nome'] . ' | ' . $mensalidade['lancamento'] . ' | ' . moedaUnimed($mensalidade['valor_mensalidade']), 7);
        }

        $linha('Utilizacoes', 8, true);
        if (empty($responsavel['utilizacoes'])) {
            $linha('Nenhuma utilizacao.', 7);
        }
        foreach ($responsavel['utilizacoes'] as $utilizacaoLinha) {
            $dataAtendimento = !empty($utilizacaoLinha['data_atendimento']) ? date('d/m/Y', strtotime($utilizacaoLinha['data_atendimento'])) : '-';
            $linha($dataAtendimento . ' | ' . $utilizacaoLinha['codigo_completo'] . ' | ' . $utilizacaoLinha['nome'] . ' | ' . $utilizacaoLinha['prestador'] . ' | Doc ' . $utilizacaoLinha['documento'] . ' | ' . moedaUnimed($utilizacaoLinha['valor_total']), 7);
        }
        $y -= 8;
    }

    $salvarPagina();
    enviarPdfRelatorioUnimed($paginas, $nomeArquivoRelatorio);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    try {
        if ($acao === 'upload_mensalidade') {
            $upload = salvarUploadUnimed($_FILES['arquivo_mensalidade'] ?? [], 'mensalidade');
            $resultado = importarFaturaMensalidadeUnimed($pdo_master, $empresaId, $upload['absoluto'], $upload['original']);
            $query = http_build_query([
                'fatura_id' => $resultado['fatura_id'],
                'ok' => 'mensalidade',
                'itens' => $resultado['itens'],
                'total' => number_format((float)$resultado['total'], 2, '.', ''),
            ]);
            header('Location: faturas.php?' . $query);
            exit;
        }

        if ($acao === 'upload_utilizacao') {
            $faturaDestinoId = (int)($_POST['fatura_destino_id'] ?? 0);
            $upload = salvarUploadUnimed($_FILES['arquivo_utilizacao'] ?? [], 'utilizacao');
            $resultado = importarFaturaUtilizacaoUnimed($pdo_master, $empresaId, $faturaDestinoId, $upload['absoluto'], $upload['original']);
            $query = http_build_query([
                'fatura_id' => $resultado['fatura_id'],
                'ok' => 'utilizacao',
                'itens' => $resultado['itens'],
                'total' => number_format((float)$resultado['total'], 2, '.', ''),
            ]);
            header('Location: faturas.php?' . $query);
            exit;
        }
    } catch (Throwable $e) {
        $mensagemErro = $e->getMessage();
    }
}

if (($_GET['ok'] ?? '') === 'mensalidade') {
    $mensagemSucesso = 'Fatura de mensalidade importada: ' . (int)($_GET['itens'] ?? 0) . ' item(ns), total ' . moedaUnimed((float)($_GET['total'] ?? 0)) . '.';
} elseif (($_GET['ok'] ?? '') === 'utilizacao') {
    $mensagemSucesso = 'Fatura de utilizacao importada: ' . (int)($_GET['itens'] ?? 0) . ' item(ns), total ' . moedaUnimed((float)($_GET['total'] ?? 0)) . '.';
}

$competencia = trim((string)($_GET['competencia'] ?? ''));
$faturaId = (int)($_GET['fatura_id'] ?? 0);

$where = ['empresa_id = ?'];
$params = [$empresaId];

if ($competencia !== '') {
    $where[] = 'competencia = ?';
    $params[] = preg_replace('/\D/', '', $competencia);
}

$stmtFaturas = $pdo_master->prepare("
    SELECT *
    FROM unimed_faturas
    WHERE " . implode(' AND ', $where) . "
    ORDER BY competencia DESC, numero_fatura DESC
");
$stmtFaturas->execute($params);
$faturas = $stmtFaturas->fetchAll(PDO::FETCH_ASSOC);

if ($faturaId <= 0 && !empty($faturas)) {
    $faturaId = (int)$faturas[0]['id'];
}

$faturaAtual = null;
$itens = [];
$familias = [];
$utilizacoes = [];
$responsaveisPdf = [];

if ($faturaId > 0) {
    $stmtFatura = $pdo_master->prepare("SELECT * FROM unimed_faturas WHERE id = ? AND empresa_id = ?");
    $stmtFatura->execute([$faturaId, $empresaId]);
    $faturaAtual = $stmtFatura->fetch(PDO::FETCH_ASSOC);

    if ($faturaAtual) {
        $stmtItens = $pdo_master->prepare("
            SELECT *
            FROM unimed_fatura_itens
            WHERE fatura_id = ?
            ORDER BY familia, dependente, nome
        ");
        $stmtItens->execute([$faturaId]);
        $itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

        $stmtFamilias = $pdo_master->prepare("
            SELECT
                familia,
                SUM(qtd) AS qtd,
                SUM(mensalidade) AS mensalidade,
                SUM(utilizacao) AS utilizacao,
                SUM(total) AS total
            FROM (
                SELECT familia, COUNT(*) AS qtd, SUM(valor_mensalidade) AS mensalidade, 0 AS utilizacao, SUM(valor_mensalidade) AS total
                FROM unimed_fatura_itens
                WHERE fatura_id = ?
                GROUP BY familia
                UNION ALL
                SELECT familia, 0 AS qtd, 0 AS mensalidade, SUM(valor_total) AS utilizacao, SUM(valor_total) AS total
                FROM unimed_utilizacoes
                WHERE fatura_id = ?
                GROUP BY familia
            ) x
            GROUP BY familia
            ORDER BY familia
        ");
        $stmtFamilias->execute([$faturaId, $faturaId]);
        $familias = $stmtFamilias->fetchAll(PDO::FETCH_ASSOC);

        $stmtUtilizacoes = $pdo_master->prepare("
            SELECT *
            FROM unimed_utilizacoes
            WHERE fatura_id = ?
            ORDER BY familia, dependente, data_atendimento, prestador, id
        ");
        $stmtUtilizacoes->execute([$faturaId]);
        $utilizacoes = $stmtUtilizacoes->fetchAll(PDO::FETCH_ASSOC);

        $stmtResponsaveisPdf = $pdo_master->prepare("
            SELECT
                responsavel_id,
                responsavel_nome,
                responsavel_codigo,
                responsavel_telefone,
                SUM(mensalidade) AS mensalidade,
                SUM(utilizacao) AS utilizacao,
                SUM(mensalidade + utilizacao) AS total,
                COUNT(DISTINCT codigo_completo) AS beneficiarios
            FROM (
                SELECT
                    COALESCE(resp.id, b.id, i.beneficiario_id, 0) AS responsavel_id,
                    COALESCE(resp.nome, b.nome, 'Responsavel nao informado') AS responsavel_nome,
                    COALESCE(resp.codigo_completo, b.codigo_completo, '') AS responsavel_codigo,
                    COALESCE(resp.telefone_whatsapp, '') AS responsavel_telefone,
                    i.codigo_completo,
                    i.valor_mensalidade AS mensalidade,
                    0 AS utilizacao
                FROM unimed_fatura_itens i
                LEFT JOIN unimed_beneficiarios b
                    ON b.id = i.beneficiario_id
                LEFT JOIN unimed_beneficiarios resp
                    ON resp.id = COALESCE(b.responsavel_pagamento_id, b.id)
                WHERE i.fatura_id = ?
                UNION ALL
                SELECT
                    COALESCE(resp.id, b.id, u.beneficiario_id, 0) AS responsavel_id,
                    COALESCE(resp.nome, b.nome, 'Responsavel nao informado') AS responsavel_nome,
                    COALESCE(resp.codigo_completo, b.codigo_completo, '') AS responsavel_codigo,
                    COALESCE(resp.telefone_whatsapp, '') AS responsavel_telefone,
                    u.codigo_completo,
                    0 AS mensalidade,
                    u.valor_total AS utilizacao
                FROM unimed_utilizacoes u
                LEFT JOIN unimed_beneficiarios b
                    ON b.id = u.beneficiario_id
                LEFT JOIN unimed_beneficiarios resp
                    ON resp.id = COALESCE(b.responsavel_pagamento_id, b.id)
                WHERE u.fatura_id = ?
            ) x
            GROUP BY responsavel_id, responsavel_nome, responsavel_codigo, responsavel_telefone
            ORDER BY responsavel_nome
        ");
        $stmtResponsaveisPdf->execute([$faturaId, $faturaId]);
        $responsaveisPdf = $stmtResponsaveisPdf->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (($_GET['relatorio_responsaveis'] ?? '') === 'pdf' && $faturaAtual) {
    $responsavelFiltroId = (int)($_GET['responsavel_id'] ?? 0);
    $filtroResponsavelMensalidade = '';
    $filtroResponsavelUtilizacao = '';
    $paramsMensalidadesResp = [$faturaId];
    $paramsUtilizacoesResp = [$faturaId];

    if ($responsavelFiltroId > 0) {
        $filtroResponsavelMensalidade = " AND COALESCE(resp.id, b.id, i.beneficiario_id, 0) = ?";
        $filtroResponsavelUtilizacao = " AND COALESCE(resp.id, b.id, u.beneficiario_id, 0) = ?";
        $paramsMensalidadesResp[] = $responsavelFiltroId;
        $paramsUtilizacoesResp[] = $responsavelFiltroId;
    }

    $stmtMensalidadesResp = $pdo_master->prepare("
        SELECT
            i.*,
            COALESCE(resp.id, b.id, i.beneficiario_id, 0) AS responsavel_id,
            COALESCE(resp.nome, b.nome, 'Responsavel nao informado') AS responsavel_nome,
            COALESCE(resp.codigo_completo, b.codigo_completo, '') AS responsavel_codigo,
            COALESCE(resp.telefone_whatsapp, '') AS responsavel_telefone
        FROM unimed_fatura_itens i
        LEFT JOIN unimed_beneficiarios b
            ON b.id = i.beneficiario_id
        LEFT JOIN unimed_beneficiarios resp
            ON resp.id = COALESCE(b.responsavel_pagamento_id, b.id)
        WHERE i.fatura_id = ?
        $filtroResponsavelMensalidade
        ORDER BY responsavel_nome, i.familia, i.dependente, i.nome
    ");
    $stmtMensalidadesResp->execute($paramsMensalidadesResp);
    $mensalidadesResp = $stmtMensalidadesResp->fetchAll(PDO::FETCH_ASSOC);

    $stmtUtilizacoesResp = $pdo_master->prepare("
        SELECT
            u.*,
            COALESCE(resp.id, b.id, u.beneficiario_id, 0) AS responsavel_id,
            COALESCE(resp.nome, b.nome, 'Responsavel nao informado') AS responsavel_nome,
            COALESCE(resp.codigo_completo, b.codigo_completo, '') AS responsavel_codigo,
            COALESCE(resp.telefone_whatsapp, '') AS responsavel_telefone
        FROM unimed_utilizacoes u
        LEFT JOIN unimed_beneficiarios b
            ON b.id = u.beneficiario_id
        LEFT JOIN unimed_beneficiarios resp
            ON resp.id = COALESCE(b.responsavel_pagamento_id, b.id)
        WHERE u.fatura_id = ?
        $filtroResponsavelUtilizacao
        ORDER BY responsavel_nome, u.familia, u.dependente, u.nome, u.data_atendimento, u.id
    ");
    $stmtUtilizacoesResp->execute($paramsUtilizacoesResp);
    $utilizacoesResp = $stmtUtilizacoesResp->fetchAll(PDO::FETCH_ASSOC);

    $responsaveisRelatorio = [];
    $inicializarResponsavel = static function (array $linha) use (&$responsaveisRelatorio): int {
        $responsavelId = (int)($linha['responsavel_id'] ?? 0);
        if (!isset($responsaveisRelatorio[$responsavelId])) {
            $responsaveisRelatorio[$responsavelId] = [
                'id' => $responsavelId,
                'nome' => (string)($linha['responsavel_nome'] ?? 'Responsavel nao informado'),
                'codigo' => (string)($linha['responsavel_codigo'] ?? ''),
                'telefone' => (string)($linha['responsavel_telefone'] ?? ''),
                'mensalidade' => 0.0,
                'utilizacao' => 0.0,
                'beneficiarios' => [],
                'mensalidades' => [],
                'utilizacoes' => [],
            ];
        }

        return $responsavelId;
    };

    foreach ($mensalidadesResp as $linha) {
        $responsavelId = $inicializarResponsavel($linha);
        $codigoBeneficiario = (string)$linha['codigo_completo'];
        if (!isset($responsaveisRelatorio[$responsavelId]['beneficiarios'][$codigoBeneficiario])) {
            $responsaveisRelatorio[$responsavelId]['beneficiarios'][$codigoBeneficiario] = [
                'codigo' => $codigoBeneficiario,
                'nome' => (string)$linha['nome'],
                'familia' => (string)$linha['familia'],
                'mensalidade' => 0.0,
                'utilizacao' => 0.0,
            ];
        }

        $valor = (float)$linha['valor_mensalidade'];
        $responsaveisRelatorio[$responsavelId]['mensalidade'] += $valor;
        $responsaveisRelatorio[$responsavelId]['beneficiarios'][$codigoBeneficiario]['mensalidade'] += $valor;
        $responsaveisRelatorio[$responsavelId]['mensalidades'][] = $linha;
    }

    foreach ($utilizacoesResp as $linha) {
        $responsavelId = $inicializarResponsavel($linha);
        $codigoBeneficiario = (string)$linha['codigo_completo'];
        if (!isset($responsaveisRelatorio[$responsavelId]['beneficiarios'][$codigoBeneficiario])) {
            $responsaveisRelatorio[$responsavelId]['beneficiarios'][$codigoBeneficiario] = [
                'codigo' => $codigoBeneficiario,
                'nome' => (string)$linha['nome'],
                'familia' => (string)$linha['familia'],
                'mensalidade' => 0.0,
                'utilizacao' => 0.0,
            ];
        }

        $valor = (float)$linha['valor_total'];
        $responsaveisRelatorio[$responsavelId]['utilizacao'] += $valor;
        $responsaveisRelatorio[$responsavelId]['beneficiarios'][$codigoBeneficiario]['utilizacao'] += $valor;
        $responsaveisRelatorio[$responsavelId]['utilizacoes'][] = $linha;
    }

    uasort($responsaveisRelatorio, static function (array $a, array $b): int {
        return strcasecmp($a['nome'], $b['nome']);
    });

    $nomeArquivoRelatorio = 'unimed' . preg_replace('/\D/', '', (string)$faturaAtual['competencia']) . 'responsaveis';
    if ($responsavelFiltroId > 0 && count($responsaveisRelatorio) === 1) {
        $responsavelTitulo = reset($responsaveisRelatorio);
        $nomeResponsavelArquivo = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$responsavelTitulo['nome']);
        if ($nomeResponsavelArquivo === false) {
            $nomeResponsavelArquivo = (string)$responsavelTitulo['nome'];
        }
        $nomeResponsavelArquivo = strtolower($nomeResponsavelArquivo);
        $nomeResponsavelArquivo = preg_replace('/[^a-z0-9]+/', '', $nomeResponsavelArquivo);
        $nomeArquivoRelatorio = 'unimed' . preg_replace('/\D/', '', (string)$faturaAtual['competencia']) . $nomeResponsavelArquivo;
    }

    if (($_GET['preview'] ?? '') !== '1') {
        gerarPdfResponsaveisUnimed($responsaveisRelatorio, $faturaAtual, $nomeArquivoRelatorio);
    }

    require '../../layout/header.php';
?>
<style>
    .unimed-relatorio {
        max-width: 980px;
        margin: 0 auto;
        background: #fff;
        color: #182033;
        font-size: 12px;
    }

    .unimed-relatorio .no-print {
        margin: 0 0 14px;
    }

    .unimed-recibo {
        border: 1px solid #cbd5e1;
        margin-bottom: 18px;
        page-break-after: always;
    }

    .unimed-recibo:last-child {
        page-break-after: auto;
    }

    .unimed-topo {
        background: #123a78;
        color: #fff;
        padding: 16px 18px;
        border-bottom: 4px solid #f0b429;
    }

    .unimed-topo h1 {
        font-size: 19px;
        margin: 0 0 6px;
        font-weight: 800;
        letter-spacing: .02em;
    }

    .unimed-topo .sub {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px 18px;
    }

    .unimed-bloco {
        padding: 12px 18px;
        border-bottom: 1px solid #d7dee8;
    }

    .unimed-titulo {
        background: #e8eef7;
        color: #0f2d68;
        font-weight: 800;
        padding: 7px 9px;
        margin: 0 0 8px;
        text-transform: uppercase;
    }

    .unimed-resumo {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
    }

    .unimed-box {
        border: 1px solid #d7dee8;
        padding: 8px;
        background: #f8fafc;
    }

    .unimed-box .label {
        color: #64748b;
        font-size: 11px;
        text-transform: uppercase;
    }

    .unimed-box .valor {
        font-size: 16px;
        font-weight: 800;
    }

    .unimed-relatorio table {
        width: 100%;
        border-collapse: collapse;
    }

    .unimed-relatorio th,
    .unimed-relatorio td {
        border-bottom: 1px solid #e2e8f0;
        padding: 5px 6px;
        vertical-align: top;
    }

    .unimed-relatorio th {
        background: #f1f5f9;
        color: #0f2d68;
        font-weight: 800;
    }

    .unimed-relatorio .text-end {
        text-align: right;
    }

    .unimed-total-final {
        text-align: right;
        font-size: 18px;
        font-weight: 900;
        color: #0f2d68;
        padding: 14px 18px 18px;
    }

    @media print {
        header, nav, .navbar, .topbar, .no-print, .btn {
            display: none !important;
        }

        body {
            background: #fff !important;
        }

        .container, .container-fluid {
            max-width: none !important;
            width: 100% !important;
            padding: 0 !important;
        }

        .unimed-relatorio {
            max-width: none;
            font-size: 11px;
        }

        .unimed-recibo {
            border: 0;
            margin: 0;
        }
    }
</style>

<div class="unimed-relatorio">
    <div class="no-print d-flex gap-2 mb-3">
        <a href="faturas.php?fatura_id=<?= (int)$faturaId ?>&relatorio_responsaveis=pdf<?= $responsavelFiltroId > 0 ? '&responsavel_id=' . (int)$responsavelFiltroId : '' ?>" class="btn btn-primary">Baixar PDF</a>
        <a href="faturas.php?fatura_id=<?= (int)$faturaId ?>" class="btn btn-outline-secondary">Voltar</a>
    </div>

    <?php if (empty($responsaveisRelatorio)): ?>
        <div class="alert alert-info">Nenhum responsavel encontrado para esta fatura.</div>
    <?php endif; ?>

    <?php foreach ($responsaveisRelatorio as $responsavel): ?>
        <?php
            $totalResponsavel = (float)$responsavel['mensalidade'] + (float)$responsavel['utilizacao'];
            $beneficiariosResp = $responsavel['beneficiarios'];
            uasort($beneficiariosResp, static function (array $a, array $b): int {
                return strcasecmp($a['nome'], $b['nome']);
            });
        ?>
        <section class="unimed-recibo">
            <div class="unimed-topo">
                <h1>DEMONSTRATIVO UNIMED POR RESPONSAVEL</h1>
                <div class="sub">
                    <div><strong>Responsavel:</strong> <?= htmlspecialchars($responsavel['nome']) ?></div>
                    <div><strong>Telefone:</strong> <?= htmlspecialchars($responsavel['telefone'] !== '' ? $responsavel['telefone'] : '-') ?></div>
                    <div><strong>Fatura mensal:</strong> <?= htmlspecialchars($faturaAtual['numero_fatura']) ?> - <?= htmlspecialchars(competenciaUnimed($faturaAtual['competencia'])) ?></div>
                    <div><strong>Fatura utilizacao:</strong> <?= htmlspecialchars((string)($faturaAtual['numero_fatura_utilizacao'] ?: '-')) ?><?= !empty($faturaAtual['competencia_utilizacao']) ? ' - ' . htmlspecialchars(competenciaUnimed($faturaAtual['competencia_utilizacao'])) : '' ?></div>
                </div>
            </div>

            <div class="unimed-bloco">
                <div class="unimed-resumo">
                    <div class="unimed-box"><div class="label">Beneficiarios</div><div class="valor"><?= count($beneficiariosResp) ?></div></div>
                    <div class="unimed-box"><div class="label">Mensalidade</div><div class="valor"><?= moedaUnimed($responsavel['mensalidade']) ?></div></div>
                    <div class="unimed-box"><div class="label">Utilizacao</div><div class="valor"><?= moedaUnimed($responsavel['utilizacao']) ?></div></div>
                    <div class="unimed-box"><div class="label">Total a pagar</div><div class="valor"><?= moedaUnimed($totalResponsavel) ?></div></div>
                </div>
            </div>

            <div class="unimed-bloco">
                <div class="unimed-titulo">Resumo por beneficiario</div>
                <table>
                    <thead>
                        <tr>
                            <th>Codigo</th>
                            <th>Beneficiario</th>
                            <th>Familia</th>
                            <th class="text-end">Mensalidade</th>
                            <th class="text-end">Utilizacao</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($beneficiariosResp as $beneficiarioResumo): ?>
                            <tr>
                                <td><?= htmlspecialchars($beneficiarioResumo['codigo']) ?></td>
                                <td><?= htmlspecialchars($beneficiarioResumo['nome']) ?></td>
                                <td><?= htmlspecialchars($beneficiarioResumo['familia']) ?></td>
                                <td class="text-end"><?= moedaUnimed($beneficiarioResumo['mensalidade']) ?></td>
                                <td class="text-end"><?= moedaUnimed($beneficiarioResumo['utilizacao']) ?></td>
                                <td class="text-end"><strong><?= moedaUnimed((float)$beneficiarioResumo['mensalidade'] + (float)$beneficiarioResumo['utilizacao']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="unimed-bloco">
                <div class="unimed-titulo">Mensalidades</div>
                <table>
                    <thead>
                        <tr>
                            <th>Codigo</th>
                            <th>Beneficiario</th>
                            <th>Lancamento</th>
                            <th class="text-end">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($responsavel['mensalidades'])): ?>
                            <tr><td colspan="4">Nenhuma mensalidade.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($responsavel['mensalidades'] as $mensalidade): ?>
                            <tr>
                                <td><?= htmlspecialchars($mensalidade['codigo_completo']) ?></td>
                                <td><?= htmlspecialchars($mensalidade['nome']) ?></td>
                                <td><?= htmlspecialchars($mensalidade['lancamento']) ?></td>
                                <td class="text-end"><?= moedaUnimed($mensalidade['valor_mensalidade']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="unimed-bloco">
                <div class="unimed-titulo">Utilizacoes do plano</div>
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Codigo</th>
                            <th>Beneficiario</th>
                            <th>Prestador</th>
                            <th>Doc.</th>
                            <th class="text-end">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($responsavel['utilizacoes'])): ?>
                            <tr><td colspan="6">Nenhuma utilizacao.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($responsavel['utilizacoes'] as $utilizacaoLinha): ?>
                            <tr>
                                <td><?= !empty($utilizacaoLinha['data_atendimento']) ? date('d/m/Y', strtotime($utilizacaoLinha['data_atendimento'])) : '-' ?></td>
                                <td><?= htmlspecialchars($utilizacaoLinha['codigo_completo']) ?></td>
                                <td><?= htmlspecialchars($utilizacaoLinha['nome']) ?></td>
                                <td><?= htmlspecialchars((string)$utilizacaoLinha['prestador']) ?></td>
                                <td><?= htmlspecialchars((string)$utilizacaoLinha['documento']) ?></td>
                                <td class="text-end"><?= moedaUnimed($utilizacaoLinha['valor_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="unimed-total-final">
                TOTAL A PAGAR: <?= moedaUnimed($totalResponsavel) ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<?php
    require '../../layout/footer.php';
    exit;
}

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Unimed</span>
                <h1 class="h3 fw-bold mb-2">Faturas Mensais</h1>
                <p class="text-muted mb-0">Fechamento mensal por usuario e familia, separando mensalidade e utilizacao do plano.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_unimed.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagemSucesso !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensagemSucesso) ?></div>
<?php endif; ?>
<?php if ($mensagemErro !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div>
<?php endif; ?>

<section class="mb-3">
    <div class="row g-3">
        <div class="col-lg-6">
            <form method="post" enctype="multipart/form-data" class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Importar Analitico de Taxa</h2>
                </div>
                <div class="card-body">
                    <input type="hidden" name="acao" value="upload_mensalidade">
                    <label class="form-label">Arquivo PDF do Analitico de Taxa</label>
                    <input type="file" name="arquivo_mensalidade" accept="application/pdf,.pdf,text/csv,.csv" class="form-control" required>
                    <div class="form-text">Use preferencialmente o CSV de taxa da Unimed. O PDF continua aceito quando detalhar as mensalidades por beneficiario.</div>
                </div>
                <div class="card-footer bg-white text-end">
                    <button type="submit" class="btn btn-primary">Enviar Analitico de Taxa</button>
                </div>
            </form>
        </div>
        <div class="col-lg-6">
            <form method="post" enctype="multipart/form-data" class="card shadow-sm h-100">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Importar utilizacoes do plano</h2>
                </div>
                <div class="card-body">
                    <input type="hidden" name="acao" value="upload_utilizacao">
                    <label class="form-label">Fatura mensal de destino</label>
                    <select name="fatura_destino_id" class="form-select mb-3" required>
                        <option value="">Selecione</option>
                        <?php foreach ($faturas as $fatura): ?>
                            <option value="<?= (int)$fatura['id'] ?>" <?= (int)$fatura['id'] === $faturaId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fatura['numero_fatura']) ?> - <?= htmlspecialchars(competenciaUnimed($fatura['competencia'])) ?> - <?= moedaUnimed($fatura['total_fatura']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label">Arquivo PDF ou CSV de utilizacoes</label>
                    <input type="file" name="arquivo_utilizacao" accept="application/pdf,.pdf,text/csv,.csv" class="form-control" required>
                    <div class="form-text">Use preferencialmente o CSV de servico da Unimed. O envio substitui as utilizacoes vinculadas a fatura mensal selecionada, evitando duplicidade.</div>
                </div>
                <div class="card-footer bg-white text-end">
                    <button type="submit" class="btn btn-primary">Enviar utilizacoes</button>
                </div>
            </form>
        </div>
    </div>
</section>

<section class="mb-3">
    <form method="get" class="card shadow-sm">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Competencia</label>
                    <input type="text" name="competencia" value="<?= htmlspecialchars($competencia) ?>" class="form-control" placeholder="202606">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Fatura</label>
                    <select name="fatura_id" class="form-select">
                        <?php foreach ($faturas as $fatura): ?>
                            <option value="<?= (int)$fatura['id'] ?>" <?= (int)$fatura['id'] === $faturaId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fatura['numero_fatura']) ?> - <?= htmlspecialchars(competenciaUnimed($fatura['competencia'])) ?> - <?= moedaUnimed($fatura['total_fatura']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </div>
        </div>
    </form>
</section>

<?php if (!$faturaAtual): ?>
    <div class="alert alert-info">Nenhuma fatura Unimed cadastrada para esta empresa.</div>
<?php else: ?>
    <section class="mb-3">
        <div class="row g-3">
            <div class="col-md-6 col-xl"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Fatura</div><div class="h5 mb-0"><?= htmlspecialchars($faturaAtual['numero_fatura']) ?></div></div></div></div>
            <div class="col-md-6 col-xl"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Competencia</div><div class="h5 mb-0"><?= htmlspecialchars(competenciaUnimed($faturaAtual['competencia'])) ?></div></div></div></div>
            <div class="col-md-4 col-xl"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Mensalidade</div><div class="h5 mb-0"><?= moedaUnimed($faturaAtual['total_mensalidade']) ?></div></div></div></div>
            <div class="col-md-4 col-xl"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Utilizacao</div><div class="h5 mb-0"><?= moedaUnimed($faturaAtual['total_utilizacao']) ?></div></div></div></div>
            <div class="col-md-4 col-xl"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Total</div><div class="h5 mb-0"><?= moedaUnimed($faturaAtual['total_fatura']) ?></div></div></div></div>
        </div>
    </section>

    <?php if (!empty($faturaAtual['numero_fatura_utilizacao'])): ?>
        <section class="mb-3">
            <div class="alert alert-info mb-0">
                Utilizacao vinculada: fatura <?= htmlspecialchars($faturaAtual['numero_fatura_utilizacao']) ?>
                <?php if (!empty($faturaAtual['competencia_utilizacao'])): ?>
                    | competencia <?= htmlspecialchars(competenciaUnimed($faturaAtual['competencia_utilizacao'])) ?>
                <?php endif; ?>
                | total <?= moedaUnimed($faturaAtual['total_utilizacao']) ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="mb-3">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h2 class="h6 mb-0">PDFs por responsavel de pagamento</h2>
                <a href="faturas.php?fatura_id=<?= (int)$faturaId ?>&relatorio_responsaveis=pdf" target="_blank" class="btn btn-sm btn-outline-danger">
                    PDF geral
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Responsavel</th>
                                <th>Telefone</th>
                                <th class="text-end">Beneficiarios</th>
                                <th class="text-end">Mensalidade</th>
                                <th class="text-end">Utilizacao</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">PDF</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($responsaveisPdf)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">Nenhum responsavel encontrado.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($responsaveisPdf as $responsavelPdf): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($responsavelPdf['responsavel_nome']) ?></strong>
                                        <?php if (!empty($responsavelPdf['responsavel_codigo'])): ?>
                                            <small class="d-block text-muted"><?= htmlspecialchars($responsavelPdf['responsavel_codigo']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string)($responsavelPdf['responsavel_telefone'] ?: '-')) ?></td>
                                    <td class="text-end"><?= (int)$responsavelPdf['beneficiarios'] ?></td>
                                    <td class="text-end"><?= moedaUnimed($responsavelPdf['mensalidade']) ?></td>
                                    <td class="text-end"><?= moedaUnimed($responsavelPdf['utilizacao']) ?></td>
                                    <td class="text-end fw-semibold"><?= moedaUnimed($responsavelPdf['total']) ?></td>
                                    <td class="text-end">
                                        <a href="faturas.php?fatura_id=<?= (int)$faturaId ?>&relatorio_responsaveis=pdf&responsavel_id=<?= (int)$responsavelPdf['responsavel_id'] ?>" target="_blank" class="btn btn-sm btn-danger">
                                            PDF
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <section class="card shadow-sm mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Resumo por familia</h2></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead><tr><th>Familia</th><th class="text-end">Usuarios</th><th class="text-end">Mensalidade</th><th class="text-end">Utilizacao</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($familias as $familiaLinha): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($familiaLinha['familia']) ?></td>
                                <td class="text-end"><?= (int)$familiaLinha['qtd'] ?></td>
                                <td class="text-end"><?= moedaUnimed($familiaLinha['mensalidade']) ?></td>
                                <td class="text-end"><?= moedaUnimed($familiaLinha['utilizacao']) ?></td>
                                <td class="text-end fw-semibold"><?= moedaUnimed($familiaLinha['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card shadow-sm">
        <div class="card-header"><h2 class="h6 mb-0">Itens da fatura</h2></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead><tr><th>Codigo</th><th>Familia</th><th>Nome</th><th>Lancamento</th><th class="text-end">Mensalidade</th><th class="text-end">Utilizacao</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($itens as $item): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($item['codigo_completo']) ?></td>
                                <td><?= htmlspecialchars($item['familia']) ?></td>
                                <td><?= htmlspecialchars($item['nome']) ?></td>
                                <td><?= htmlspecialchars($item['lancamento']) ?></td>
                                <td class="text-end"><?= moedaUnimed($item['valor_mensalidade']) ?></td>
                                <td class="text-end"><?= moedaUnimed($item['valor_utilizacao']) ?></td>
                                <td class="text-end fw-semibold"><?= moedaUnimed($item['valor_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card shadow-sm mt-3">
        <div class="card-header"><h2 class="h6 mb-0">Utilizacoes do plano</h2></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead><tr><th>Codigo</th><th>Familia</th><th>Nome</th><th>Data</th><th>Prestador</th><th>Doc.</th><th class="text-end">Valor</th></tr></thead>
                    <tbody>
                        <?php if (empty($utilizacoes ?? [])): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma utilizacao vinculada a esta fatura.</td></tr>
                        <?php endif; ?>
                        <?php foreach (($utilizacoes ?? []) as $utilizacao): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($utilizacao['codigo_completo']) ?></td>
                                <td><?= htmlspecialchars($utilizacao['familia']) ?></td>
                                <td><?= htmlspecialchars($utilizacao['nome']) ?></td>
                                <td><?= !empty($utilizacao['data_atendimento']) ? date('d/m/Y', strtotime($utilizacao['data_atendimento'])) : '-' ?></td>
                                <td><?= htmlspecialchars((string)$utilizacao['prestador']) ?></td>
                                <td><?= htmlspecialchars((string)$utilizacao['documento']) ?></td>
                                <td class="text-end fw-semibold"><?= moedaUnimed($utilizacao['valor_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php require '../../layout/footer.php'; ?>
