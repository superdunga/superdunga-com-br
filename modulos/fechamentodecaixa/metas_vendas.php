<?php
$modoPdfMetaVendas = defined('METAS_VENDAS_MODO_PDF') && METAS_VENDAS_MODO_PDF === true;
$execucaoInternaMetaVendas = defined('METAS_VENDAS_EXECUCAO_INTERNA') && METAS_VENDAS_EXECUCAO_INTERNA === true;
if (!$execucaoInternaMetaVendas) {
    require __DIR__ . '/../../config/auth.php';
}
require __DIR__ . '/../../config/conexao.php';

date_default_timezone_set('America/Sao_Paulo');
$empresaId = $execucaoInternaMetaVendas
    ? (int)($GLOBALS['METAS_VENDAS_EMPRESA_ID'] ?? 0)
    : (int)($_SESSION['empresa_id'] ?? 0);
$mesSistema = date('Y-m');
$inicioMesSistema = $mesSistema . '-01';
$ontem = date('Y-m-d', strtotime('-1 day'));
$filtroDataIni = (string)($_GET['data_ini'] ?? $inicioMesSistema);
$filtroDataFim = (string)($_GET['data_fim'] ?? max($inicioMesSistema, $ontem));
$dataInicialValida = DateTimeImmutable::createFromFormat('!Y-m-d', $filtroDataIni);
$dataFinalValida = DateTimeImmutable::createFromFormat('!Y-m-d', $filtroDataFim);
$erroFiltro = null;
if (!$dataInicialValida || !$dataFinalValida || $dataInicialValida->format('Y-m-d') !== $filtroDataIni ||
    $dataFinalValida->format('Y-m-d') !== $filtroDataFim ||
    substr($filtroDataIni, 0, 7) !== substr($filtroDataFim, 0, 7) ||
    $filtroDataIni > $filtroDataFim || substr($filtroDataIni, 0, 7) > $mesSistema) {
    $erroFiltro = 'Selecione datas validas dentro de um unico mes, ate o mes atual.';
    $filtroDataIni = $inicioMesSistema;
    $filtroDataFim = max($inicioMesSistema, $ontem);
}
$mesAtual = substr($filtroDataIni, 0, 7);
$inicioMesAtual = $mesAtual . '-01';
$fimMesAtual = date('Y-m-t', strtotime($inicioMesAtual));
$limiteMesAtual = max($inicioMesAtual, min($ontem, $fimMesAtual));
if ($filtroDataFim > $limiteMesAtual) {
    $erroFiltro = 'A data final foi limitada ao ultimo dia fechado do mes selecionado.';
    $filtroDataFim = $limiteMesAtual;
}
if ($filtroDataIni > $filtroDataFim) {
    $erroFiltro = 'A data inicial deve ser anterior ao ultimo dia fechado do mes.';
    $filtroDataIni = $inicioMesAtual;
}
$mesAnterior = date('Y-m', strtotime($inicioMesAtual . ' -1 month'));
$inicioMesAnterior = $mesAnterior . '-01';
$fimMesAnterior = date('Y-m-t', strtotime($inicioMesAnterior));
$dataReferencia = $filtroDataFim;
$temDiasFechados = $ontem >= $inicioMesAtual && $dataReferencia >= $inicioMesAtual;
$diasMesAtual = (int)date('t', strtotime($inicioMesAtual));
$diasDecorridos = $temDiasFechados ? (int)date('j', strtotime($dataReferencia)) : 0;
$diasSemanaMetaVendas = [
    0 => 'Dom',
    1 => 'Seg',
    2 => 'Ter',
    3 => 'Qua',
    4 => 'Qui',
    5 => 'Sex',
    6 => 'Sab',
];
$diasSelecionados = $_GET['dias'] ?? array_keys($diasSemanaMetaVendas);
if (!is_array($diasSelecionados)) {
    $diasSelecionados = [$diasSelecionados];
}
$diasSelecionados = array_values(array_unique(array_filter(array_map('intval', $diasSelecionados), static function ($dia): bool {
    return $dia >= 0 && $dia <= 6;
})));
if (!$diasSelecionados) {
    $diasSelecionados = array_keys($diasSemanaMetaVendas);
}
$mesComparacaoProduto = (string)($_GET['produto_mes_comparacao'] ?? date('Y-m', strtotime($filtroDataIni . ' -1 month')));
$mesComparacaoProdutoValido = DateTimeImmutable::createFromFormat('!Y-m', $mesComparacaoProduto);
if (!$mesComparacaoProdutoValido || $mesComparacaoProdutoValido->format('Y-m') !== $mesComparacaoProduto) {
    $mesComparacaoProduto = date('Y-m', strtotime($filtroDataIni . ' -1 month'));
}
$primeiroDiaMesComparacaoProduto = $mesComparacaoProduto . '-01';
$diasNoMesComparacaoProduto = (int)date('t', strtotime($primeiroDiaMesComparacaoProduto));
$diaInicioComparacaoProduto = min((int)date('d', strtotime($filtroDataIni)), $diasNoMesComparacaoProduto);
$diaFimComparacaoProduto = min((int)date('d', strtotime($filtroDataFim)), $diasNoMesComparacaoProduto);
$inicioComparacaoProduto = sprintf('%s-%02d', $mesComparacaoProduto, $diaInicioComparacaoProduto);
$fimComparacaoProduto = sprintf('%s-%02d', $mesComparacaoProduto, $diaFimComparacaoProduto);
$queryFiltro = http_build_query([
    'data_ini' => $filtroDataIni,
    'data_fim' => $filtroDataFim,
    'dias' => $diasSelecionados,
    'produto_mes_comparacao' => $mesComparacaoProduto,
]);

$pdo_master->exec("
    CREATE TABLE IF NOT EXISTS fechamento_metas_vendas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        empresa_id INT NOT NULL,
        mes CHAR(7) NOT NULL,
        valor_meta DECIMAL(15,2) NOT NULL DEFAULT 0,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NULL,
        UNIQUE KEY uniq_fechamento_meta_empresa_mes (empresa_id, mes)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo_master->exec("
    CREATE TABLE IF NOT EXISTS fechamento_metas_vendas_dias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        empresa_id INT NOT NULL,
        mes CHAR(7) NOT NULL,
        dia_semana TINYINT UNSIGNED NOT NULL,
        trabalha CHAR(1) NOT NULL DEFAULT 'S',
        valor_meta_dia DECIMAL(15,2) NOT NULL DEFAULT 0,
        atualizado_em DATETIME NULL,
        UNIQUE KEY uniq_meta_vendas_dia (empresa_id, mes, dia_semana)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if (!function_exists('dinheiroParaFloatMetaVendas')) {
function dinheiroParaFloatMetaVendas(string $valor): float
{
    $valor = trim($valor);
    if ($valor === '') {
        return 0.0;
    }
    $valor = str_replace(['R$', ' '], '', $valor);
    $valor = str_replace('.', '', $valor);
    $valor = str_replace(',', '.', $valor);
    return (float)$valor;
}

function moedaMetaVendas(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function percentualMetaVendas(?float $valor): string
{
    if ($valor === null) {
        return '-';
    }
    return number_format($valor, 1, ',', '.') . '%';
}

function numeroMetaVendas(int $valor): string
{
    return number_format($valor, 0, ',', '.');
}

function quantidadeMetaVendas(float $valor): string
{
    return rtrim(rtrim(number_format($valor, 3, ',', '.'), '0'), ',');
}

function classeBarraHoraMetaVendas(float $valor, float $maiorValor): string
{
    if ($valor <= 0 || $maiorValor <= 0) {
        return 'bg-secondary';
    }
    $percentual = ($valor / $maiorValor) * 100;
    if ($percentual >= 80) {
        return 'bg-success';
    }
    if ($percentual >= 50) {
        return 'bg-primary';
    }
    if ($percentual >= 25) {
        return 'bg-warning';
    }
    return 'bg-danger';
}

function rotuloDataMetaVendas(string $data): string
{
    $dias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'];
    $ts = strtotime($data);
    return date('d/m/Y', $ts) . ' (' . $dias[(int)date('w', $ts)] . ')';
}

function ocorrenciaSemanaMesMetaVendas(string $data): int
{
    return intdiv(((int)date('j', strtotime($data))) - 1, 7) + 1;
}

function mesmaOcorrenciaSemanaMesAnteriorMetaVendas(string $dataAtual, string $inicioMesAnterior, string $fimMesAnterior): ?string
{
    $weekday = (int)date('w', strtotime($dataAtual));
    $ocorrencia = ocorrenciaSemanaMesMetaVendas($dataAtual);
    $cursor = strtotime($inicioMesAnterior);
    $fim = strtotime($fimMesAnterior);
    $encontrados = 0;

    while ($cursor <= $fim) {
        if ((int)date('w', $cursor) === $weekday) {
            $encontrados++;
            if ($encontrados === $ocorrencia) {
                return date('Y-m-d', $cursor);
            }
        }
        $cursor = strtotime('+1 day', $cursor);
    }

    return null;
}

function vendasPorDiaMetaVendas(PDO $pdo, int $empresaId, string $inicio, string $fim, array $diasSemana = []): array
{
    $dataCaixaSql = "DATE(CASE WHEN TIME(DTLANC) < '03:00:00' THEN DATE_SUB(DTLANC, INTERVAL 1 DAY) ELSE DTLANC END)";
    $inicioPeriodo = $inicio . ' 07:00:00';
    $fimPeriodo = date('Y-m-d 03:00:00', strtotime($fim . ' +1 day'));
    $filtroDiasSql = '';
    $params = [$inicioPeriodo, $fimPeriodo, $empresaId, $inicio, $fim];
    if ($diasSemana) {
        $placeholdersDias = implode(',', array_fill(0, count($diasSemana), '?'));
        $filtroDiasSql = " AND (DAYOFWEEK($dataCaixaSql) - 1) IN ($placeholdersDias)";
        $params = array_merge($params, array_values($diasSemana));
    }
    $stmt = $pdo->prepare("
        SELECT data, SUM(valor) AS total_venda, COUNT(*) AS qtd_vendas
        FROM (
            SELECT $dataCaixaSql AS data, NUMDOC, MAX(TOTGERAL) AS valor
            FROM armazem_est007
            WHERE DTLANC >= ?
              AND DTLANC <= ?
              AND EMPRESA = ?
              AND $dataCaixaSql BETWEEN ? AND ?
              AND CANCELADO = 'N'
              AND COALESCE(excluido_firebird, 'N') <> 'S'
              AND COALESCE(CMCONTADOR, 0) <> 10
              AND (TIME(DTLANC) >= '07:00:00' OR TIME(DTLANC) < '03:00:00')
              $filtroDiasSql
            GROUP BY $dataCaixaSql, NUMDOC
        ) x
        GROUP BY data
        ORDER BY data
    ");
    $stmt->execute($params);

    $vendas = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $totalVenda = (float)$row['total_venda'];
        $qtdVendas = (int)$row['qtd_vendas'];
        $vendas[(string)$row['data']] = [
            'total' => $totalVenda,
            'qtd' => $qtdVendas,
            'ticket_medio' => $qtdVendas > 0 ? $totalVenda / $qtdVendas : 0.0,
        ];
    }

    return $vendas;
}

function vendasPorHoraMetaVendas(PDO $pdo, int $empresaId, string $inicio, string $fim, array $diasSemana = []): array
{
    $dataCaixaSql = "DATE(CASE WHEN TIME(DTLANC) < '03:00:00' THEN DATE_SUB(DTLANC, INTERVAL 1 DAY) ELSE DTLANC END)";
    $inicioPeriodo = $inicio . ' 07:00:00';
    $fimPeriodo = date('Y-m-d 03:00:00', strtotime($fim . ' +1 day'));
    $filtroDiasSql = '';
    $params = [$inicioPeriodo, $fimPeriodo, $empresaId, $inicio, $fim];
    if ($diasSemana) {
        $placeholdersDias = implode(',', array_fill(0, count($diasSemana), '?'));
        $filtroDiasSql = " AND (DAYOFWEEK($dataCaixaSql) - 1) IN ($placeholdersDias)";
        $params = array_merge($params, array_values($diasSemana));
    }

    $stmt = $pdo->prepare("
        SELECT hora, SUM(valor) AS total_venda, COUNT(*) AS qtd_vendas
        FROM (
            SELECT $dataCaixaSql AS data, HOUR(DTLANC) AS hora, NUMDOC, MAX(TOTGERAL) AS valor
            FROM armazem_est007
            WHERE DTLANC >= ?
              AND DTLANC <= ?
              AND EMPRESA = ?
              AND $dataCaixaSql BETWEEN ? AND ?
              AND CANCELADO = 'N'
              AND COALESCE(excluido_firebird, 'N') <> 'S'
              AND COALESCE(CMCONTADOR, 0) <> 10
              AND (TIME(DTLANC) >= '07:00:00' OR TIME(DTLANC) < '03:00:00')
              $filtroDiasSql
            GROUP BY $dataCaixaSql, HOUR(DTLANC), NUMDOC
        ) x
        GROUP BY hora
        ORDER BY hora
    ");
    $stmt->execute($params);

    $horas = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hora = (int)$row['hora'];
        $horas[$hora] = [
            'total' => (float)$row['total_venda'],
            'qtd' => (int)$row['qtd_vendas'],
        ];
    }

    return $horas;
}

function vendasPorProdutoMetaVendas(PDO $pdo, int $empresaId, string $inicio, string $fim, array $diasSemana = []): array
{
    $dataCaixaSql = "DATE(CASE WHEN TIME(v.DTLANC) < '03:00:00' THEN DATE_SUB(v.DTLANC, INTERVAL 1 DAY) ELSE v.DTLANC END)";
    $inicioPeriodo = $inicio . ' 07:00:00';
    $fimPeriodo = date('Y-m-d 03:00:00', strtotime($fim . ' +1 day'));
    $filtroDiasSql = '';
    $params = [$inicioPeriodo, $fimPeriodo, $empresaId, $inicio, $fim];
    if ($diasSemana) {
        $placeholdersDias = implode(',', array_fill(0, count($diasSemana), '?'));
        $filtroDiasSql = " AND (DAYOFWEEK($dataCaixaSql) - 1) IN ($placeholdersDias)";
        $params = array_merge($params, array_values($diasSemana));
    }
    $stmt = $pdo->prepare("
        SELECT
            i.PRODUTO,
            COALESCE(NULLIF(MAX(p.CODPRODUTO), ''), CAST(i.PRODUTO AS CHAR)) AS codigo,
            COALESCE(NULLIF(MAX(p.DESCPRODUTO), ''), CONCAT('Produto ', i.PRODUTO)) AS descricao,
            COALESCE(NULLIF(MAX(p.UNIDADE), ''), '-') AS unidade,
            SUM(COALESCE(i.QTDE, 0)) AS quantidade,
            SUM(COALESCE(i.TOTPROD, 0)) AS total
        FROM armazem_est007 v
        INNER JOIN armazem_est008 i
            ON i.EMPRESA = v.EMPRESA
           AND i.ITEMVENDACONTADOR = v.VENDACONTADOR
        LEFT JOIN armazem_est004 p
            ON p.EMPRESA = i.EMPRESA
           AND p.CONTAPRODUTO = i.PRODUTO
        WHERE v.DTLANC >= ?
          AND v.DTLANC <= ?
          AND v.EMPRESA = ?
          AND $dataCaixaSql BETWEEN ? AND ?
          AND v.CANCELADO = 'N'
          AND COALESCE(v.excluido_firebird, 'N') <> 'S'
          AND COALESCE(v.CMCONTADOR, 0) <> 10
          AND (TIME(v.DTLANC) >= '07:00:00' OR TIME(v.DTLANC) < '03:00:00')
          AND COALESCE(i.CANCELADO, 'N') <> 'S'
          AND COALESCE(i.excluido_firebird, 'N') <> 'S'
          $filtroDiasSql
        GROUP BY i.PRODUTO
        ORDER BY total DESC, descricao
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function produtosComEstoqueSemVendaMetaVendas(PDO $pdo, int $empresaId, string $inicio, string $fim, array $diasSemana = []): array
{
    $dataCaixaSql = "DATE(CASE WHEN TIME(v.DTLANC) < '03:00:00' THEN DATE_SUB(v.DTLANC, INTERVAL 1 DAY) ELSE v.DTLANC END)";
    $inicioPeriodo = $inicio . ' 07:00:00';
    $fimPeriodo = date('Y-m-d 03:00:00', strtotime($fim . ' +1 day'));
    $filtroDiasSql = '';
    $paramsVendas = [$inicioPeriodo, $fimPeriodo, $empresaId, $inicio, $fim];
    if ($diasSemana) {
        $placeholdersDias = implode(',', array_fill(0, count($diasSemana), '?'));
        $filtroDiasSql = " AND (DAYOFWEEK($dataCaixaSql) - 1) IN ($placeholdersDias)";
        $paramsVendas = array_merge($paramsVendas, array_values($diasSemana));
    }

    $stmt = $pdo->prepare("
        SELECT
            p.CONTAPRODUTO,
            p.CODPRODUTO,
            p.DESCPRODUTO,
            p.UNIDADE,
            COALESCE(p.PRECOFINAL, 0) AS preco_venda,
            CASE
                WHEN p.ESTOQUE_CALCULADO_EM IS NOT NULL THEN COALESCE(p.ESTOQUE_DISPONIVEL, 0)
                ELSE COALESCE(p.ESTINICIAL, 0) + COALESCE(e.qtd_entrada, 0) - COALESCE(s.qtd_saida, 0)
            END AS estoque_atual,
            COALESCE(vp.qtd_vendida, 0) AS quantidade_vendida_periodo,
            p.ESTOQUE_CALCULADO_EM
        FROM armazem_est004 p
        LEFT JOIN (
            SELECT i.EMPRESA, i.PRODUTO, SUM(COALESCE(i.QTDE, 0)) AS qtd_entrada
            FROM armazem_est006 i
            LEFT JOIN armazem_est005 c
                ON c.EMPRESA = i.EMPRESA
               AND c.COMPRACONTADOR = i.COMPRACONTA
            WHERE i.EMPRESA = ?
              AND COALESCE(i.excluido_firebird, 'N') <> 'S'
              AND COALESCE(i.CANCELADO, 'N') <> 'S'
              AND COALESCE(i.MOVESTOQUE, 'S') <> 'N'
              AND COALESCE(c.excluido_firebird, 'N') <> 'S'
              AND COALESCE(c.CANCELADO, 'N') <> 'S'
              AND COALESCE(c.BAIXAESTOQUE, 'S') <> 'N'
            GROUP BY i.EMPRESA, i.PRODUTO
        ) e ON e.EMPRESA = p.EMPRESA AND e.PRODUTO = p.CONTAPRODUTO
        LEFT JOIN (
            SELECT i.EMPRESA, i.PRODUTO, SUM(COALESCE(i.QTDE, 0)) AS qtd_saida
            FROM armazem_est008 i
            WHERE i.EMPRESA = ?
              AND COALESCE(i.CANCELADO, 'N') <> 'S'
              AND i.MOVESTOQUE = 'S'
            GROUP BY i.EMPRESA, i.PRODUTO
        ) s ON s.EMPRESA = p.EMPRESA AND s.PRODUTO = p.CONTAPRODUTO
        LEFT JOIN (
            SELECT i.EMPRESA, i.PRODUTO, SUM(COALESCE(i.QTDE, 0)) AS qtd_vendida
            FROM armazem_est007 v
            INNER JOIN armazem_est008 i
                ON i.EMPRESA = v.EMPRESA
               AND i.ITEMVENDACONTADOR = v.VENDACONTADOR
            WHERE v.DTLANC >= ?
              AND v.DTLANC <= ?
              AND v.EMPRESA = ?
              AND $dataCaixaSql BETWEEN ? AND ?
              AND v.CANCELADO = 'N'
              AND COALESCE(v.excluido_firebird, 'N') <> 'S'
              AND COALESCE(v.CMCONTADOR, 0) <> 10
              AND (TIME(v.DTLANC) >= '07:00:00' OR TIME(v.DTLANC) < '03:00:00')
              AND COALESCE(i.CANCELADO, 'N') <> 'S'
              AND COALESCE(i.excluido_firebird, 'N') <> 'S'
              $filtroDiasSql
            GROUP BY i.EMPRESA, i.PRODUTO
        ) vp ON vp.EMPRESA = p.EMPRESA AND vp.PRODUTO = p.CONTAPRODUTO
        WHERE p.EMPRESA = ?
          AND COALESCE(p.excluido_firebird, 'N') <> 'S'
          AND COALESCE(p.INATIVO, 'N') <> 'S'
        HAVING estoque_atual > 0
           AND quantidade_vendida_periodo = 0
        ORDER BY preco_venda DESC, estoque_atual DESC, p.DESCPRODUTO, p.CODPRODUTO
    ");
    $params = array_merge([$empresaId, $empresaId], $paramsVendas, [$empresaId]);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_meta_vendas') {
    $valorMeta = dinheiroParaFloatMetaVendas((string)($_POST['valor_meta'] ?? '0'));
    $stmtMetaSalvar = $pdo_master->prepare("
        INSERT INTO fechamento_metas_vendas (empresa_id, mes, valor_meta, atualizado_em)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE valor_meta = VALUES(valor_meta), atualizado_em = NOW()
    ");
    $stmtMetaSalvar->execute([$empresaId, $mesAtual, $valorMeta]);
    header('Location: metas_vendas.php?' . $queryFiltro . '&meta=ok');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_distribuicao_meta') {
    $trabalhaFimSemana = [
        0 => isset($_POST['trabalha_domingo']) ? 'S' : 'N',
        6 => isset($_POST['trabalha_sabado']) ? 'S' : 'N',
    ];
    $valoresDias = is_array($_POST['valor_meta_dia'] ?? null) ? $_POST['valor_meta_dia'] : [];
    $stmtDistribuicao = $pdo_master->prepare("
        INSERT INTO fechamento_metas_vendas_dias
            (empresa_id, mes, dia_semana, trabalha, valor_meta_dia, atualizado_em)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            trabalha = VALUES(trabalha),
            valor_meta_dia = VALUES(valor_meta_dia),
            atualizado_em = NOW()
    ");
    for ($dia = 0; $dia <= 6; $dia++) {
        $trabalha = $trabalhaFimSemana[$dia] ?? 'S';
        $valorDia = $trabalha === 'S'
            ? dinheiroParaFloatMetaVendas((string)($valoresDias[$dia] ?? '0'))
            : 0.0;
        $stmtDistribuicao->execute([$empresaId, $mesAtual, $dia, $trabalha, $valorDia]);
    }
    header('Location: metas_vendas.php?' . $queryFiltro . '&distribuicao=ok');
    exit;
}

$stmtMeta = $pdo_master->prepare("
    SELECT valor_meta
    FROM fechamento_metas_vendas
    WHERE empresa_id = ?
      AND mes = ?
    LIMIT 1
");
$stmtMeta->execute([$empresaId, $mesAtual]);
$metaVendas = (float)($stmtMeta->fetchColumn() ?: 0);

$stmtDistribuicao = $pdo_master->prepare("
    SELECT dia_semana, trabalha, valor_meta_dia
    FROM fechamento_metas_vendas_dias
    WHERE empresa_id = ? AND mes = ?
");
$stmtDistribuicao->execute([$empresaId, $mesAtual]);
$distribuicaoSalva = [];
foreach ($stmtDistribuicao->fetchAll(PDO::FETCH_ASSOC) as $linhaDistribuicao) {
    $distribuicaoSalva[(int)$linhaDistribuicao['dia_semana']] = $linhaDistribuicao;
}
$quantidadeDiasMesPorSemana = array_fill(0, 7, 0);
$cursorDiasMes = strtotime($inicioMesAtual);
$fimDiasMes = strtotime($fimMesAtual);
while ($cursorDiasMes <= $fimDiasMes) {
    $quantidadeDiasMesPorSemana[(int)date('w', $cursorDiasMes)]++;
    $cursorDiasMes = strtotime('+1 day', $cursorDiasMes);
}
$distribuicaoMeta = [];
$totalDiasTrabalhadosMeta = 0;
$previsaoDistribuidaMeta = 0.0;
foreach ($diasSemanaMetaVendas as $diaValor => $diaNome) {
    $trabalhaPadrao = in_array($diaValor, [0, 6], true) ? 'N' : 'S';
    $trabalha = (string)($distribuicaoSalva[$diaValor]['trabalha'] ?? $trabalhaPadrao);
    $quantidade = $quantidadeDiasMesPorSemana[$diaValor];
    if ($trabalha === 'S') {
        $totalDiasTrabalhadosMeta += $quantidade;
        $previsaoDistribuidaMeta += $quantidade * (float)($distribuicaoSalva[$diaValor]['valor_meta_dia'] ?? 0);
    }
    $distribuicaoMeta[$diaValor] = [
        'nome' => $diaNome,
        'trabalha' => $trabalha,
        'quantidade' => $quantidade,
        'valor_dia' => (float)($distribuicaoSalva[$diaValor]['valor_meta_dia'] ?? 0),
    ];
}
$diferencaDistribuicaoMeta = $previsaoDistribuidaMeta - $metaVendas;

$vendasMesAtualCompleto = $temDiasFechados
    ? vendasPorDiaMetaVendas($pdo_master, $empresaId, $inicioMesAtual, $dataReferencia)
    : [];
$faturamentoRealMes = array_sum(array_column($vendasMesAtualCompleto, 'total'));
$previsaoRestantePorMeta = 0.0;
$diasRestantesTrabalhados = 0;
$diasRestantesPelaMeta = 0;
$cursorFechamentoDesempenho = strtotime($temDiasFechados ? $dataReferencia . ' +1 day' : $inicioMesAtual);
while ($cursorFechamentoDesempenho <= $fimDiasMes) {
    $diaSemanaPrevisaoAtual = (int)date('w', $cursorFechamentoDesempenho);
    if (($distribuicaoMeta[$diaSemanaPrevisaoAtual]['trabalha'] ?? 'N') === 'S') {
        $diasRestantesTrabalhados++;
        $previsaoRestantePorMeta += (float)($distribuicaoMeta[$diaSemanaPrevisaoAtual]['valor_dia'] ?? 0.0);
        $diasRestantesPelaMeta++;
    }
    $cursorFechamentoDesempenho = strtotime('+1 day', $cursorFechamentoDesempenho);
}
$previsaoFechamentoDesempenho = $faturamentoRealMes + $previsaoRestantePorMeta;
$percentualPrevisaoMeta = $metaVendas > 0 ? ($previsaoFechamentoDesempenho / $metaVendas) * 100 : null;

$vendasMesAtual = $temDiasFechados
    ? vendasPorDiaMetaVendas($pdo_master, $empresaId, $filtroDataIni, $filtroDataFim, $diasSelecionados)
    : [];
$vendasMesAnterior = vendasPorDiaMetaVendas($pdo_master, $empresaId, $inicioMesAnterior, $fimMesAnterior, $diasSelecionados);
$vendasMesAnteriorCompleto = vendasPorDiaMetaVendas($pdo_master, $empresaId, $inicioMesAnterior, $fimMesAnterior);
$vendasPorHora = $temDiasFechados
    ? vendasPorHoraMetaVendas($pdo_master, $empresaId, $filtroDataIni, $filtroDataFim, $diasSelecionados)
    : [];
$vendasPorProduto = $temDiasFechados
    ? vendasPorProdutoMetaVendas($pdo_master, $empresaId, $filtroDataIni, $filtroDataFim, $diasSelecionados)
    : [];
$produtosComEstoqueSemVenda = $temDiasFechados
    ? produtosComEstoqueSemVendaMetaVendas($pdo_master, $empresaId, $filtroDataIni, $filtroDataFim, $diasSelecionados)
    : [];
$estoqueAtualSemVenda = array_sum(array_map(static function (array $produto): float {
    return (float)$produto['estoque_atual'];
}, $produtosComEstoqueSemVenda));
$estoqueCalculadoEmSemVenda = null;
foreach ($produtosComEstoqueSemVenda as $produtoSemVenda) {
    $calculadoEm = $produtoSemVenda['ESTOQUE_CALCULADO_EM'] ?? null;
    if ($calculadoEm !== null && ($estoqueCalculadoEmSemVenda === null || $calculadoEm > $estoqueCalculadoEmSemVenda)) {
        $estoqueCalculadoEmSemVenda = $calculadoEm;
    }
}
$vendasPorProdutoComparacao = vendasPorProdutoMetaVendas(
    $pdo_master,
    $empresaId,
    $inicioComparacaoProduto,
    $fimComparacaoProduto,
    $diasSelecionados
);
$quantidadesProdutosComparacao = [];
$valoresProdutosComparacao = [];
foreach ($vendasPorProdutoComparacao as $produtoComparacao) {
    $produtoIdComparacao = (string)$produtoComparacao['PRODUTO'];
    $quantidadesProdutosComparacao[$produtoIdComparacao] = (float)$produtoComparacao['quantidade'];
    $valoresProdutosComparacao[$produtoIdComparacao] = (float)$produtoComparacao['total'];
}
$quantidadeTotalProdutos = array_sum(array_map(static function (array $produto): float {
    return (float)$produto['quantidade'];
}, $vendasPorProduto));
$quantidadeTotalProdutosComparacao = array_sum(array_map(static function (array $produto) use ($quantidadesProdutosComparacao): float {
    return (float)($quantidadesProdutosComparacao[(string)$produto['PRODUTO']] ?? 0);
}, $vendasPorProduto));
$valorTotalProdutosComparacao = array_sum(array_map(static function (array $produto) use ($valoresProdutosComparacao): float {
    return (float)($valoresProdutosComparacao[(string)$produto['PRODUTO']] ?? 0);
}, $vendasPorProduto));
$valorTotalProdutos = array_sum(array_map(static function (array $produto): float {
    return (float)$produto['total'];
}, $vendasPorProduto));

$comparativoDias = [];
$totalAtualAteReferencia = 0.0;
$totalAnteriorComparavel = 0.0;
$totalMetaDistribuidaComparavel = 0.0;
$qtdVendasAtualAteReferencia = 0;
$qtdVendasAnteriorComparavel = 0;
$maiorAlta = null;
$maiorQueda = null;

$cursor = strtotime($temDiasFechados ? $filtroDataIni : $filtroDataFim . ' +1 day');
$fimComparativo = strtotime($filtroDataFim);
while ($cursor <= $fimComparativo) {
    $dataAtualLoop = date('Y-m-d', $cursor);
    if (!in_array((int)date('w', $cursor), $diasSelecionados, true)) {
        $cursor = strtotime('+1 day', $cursor);
        continue;
    }
    $dataAnteriorComparada = mesmaOcorrenciaSemanaMesAnteriorMetaVendas($dataAtualLoop, $inicioMesAnterior, $fimMesAnterior);
    $vendaAtual = $vendasMesAtual[$dataAtualLoop] ?? ['total' => 0.0, 'qtd' => 0, 'ticket_medio' => 0.0];
    $vendaAnterior = $dataAnteriorComparada
        ? ($vendasMesAnterior[$dataAnteriorComparada] ?? ['total' => 0.0, 'qtd' => 0, 'ticket_medio' => 0.0])
        : ['total' => 0.0, 'qtd' => 0, 'ticket_medio' => 0.0];
    $valorAtual = (float)$vendaAtual['total'];
    $valorAnterior = (float)$vendaAnterior['total'];
    $qtdAtual = (int)$vendaAtual['qtd'];
    $qtdAnterior = (int)$vendaAnterior['qtd'];
    $diaSemanaAtual = (int)date('w', $cursor);
    $metaDiaDistribuida = ($distribuicaoMeta[$diaSemanaAtual]['trabalha'] ?? 'N') === 'S'
        ? (float)($distribuicaoMeta[$diaSemanaAtual]['valor_dia'] ?? 0)
        : 0.0;
    $diferencaMetaDia = $valorAtual - $metaDiaDistribuida;
    $percentualMetaDia = $metaDiaDistribuida > 0 ? ($valorAtual / $metaDiaDistribuida) * 100 : null;
    $diferenca = $valorAtual - $valorAnterior;
    $percentual = $valorAnterior > 0 ? (($valorAtual / $valorAnterior) - 1) * 100 : null;

    $totalAtualAteReferencia += $valorAtual;
    $totalAnteriorComparavel += $valorAnterior;
    $totalMetaDistribuidaComparavel += $metaDiaDistribuida;
    $qtdVendasAtualAteReferencia += $qtdAtual;
    $qtdVendasAnteriorComparavel += $qtdAnterior;

    if ($valorAnterior > 0 || $valorAtual > 0) {
        if ($maiorAlta === null || $diferenca > $maiorAlta['diferenca']) {
            $maiorAlta = ['data' => $dataAtualLoop, 'diferenca' => $diferenca, 'percentual' => $percentual];
        }
        if ($maiorQueda === null || $diferenca < $maiorQueda['diferenca']) {
            $maiorQueda = ['data' => $dataAtualLoop, 'diferenca' => $diferenca, 'percentual' => $percentual];
        }
    }

    $comparativoDias[] = [
        'data_atual' => $dataAtualLoop,
        'data_anterior' => $dataAnteriorComparada,
        'valor_atual' => $valorAtual,
        'valor_anterior' => $valorAnterior,
        'qtd_atual' => $qtdAtual,
        'ticket_medio_atual' => $qtdAtual > 0 ? $valorAtual / $qtdAtual : 0.0,
        'meta_distribuida' => $metaDiaDistribuida,
        'diferenca_meta' => $diferencaMetaDia,
        'percentual_meta' => $percentualMetaDia,
        'diferenca' => $diferenca,
        'percentual' => $percentual,
        'ocorrencia' => ocorrenciaSemanaMesMetaVendas($dataAtualLoop),
    ];

    $cursor = strtotime('+1 day', $cursor);
}

$totalMesAnterior = array_sum(array_column($vendasMesAnteriorCompleto, 'total'));
$diasComparativo = count($comparativoDias);
$mediaDiaAtual = $diasComparativo > 0 ? $totalAtualAteReferencia / $diasComparativo : 0.0;
$ticketMedioAtualAteReferencia = $qtdVendasAtualAteReferencia > 0 ? $totalAtualAteReferencia / $qtdVendasAtualAteReferencia : 0.0;
$ticketMedioAnteriorComparavel = $qtdVendasAnteriorComparavel > 0 ? $totalAnteriorComparavel / $qtdVendasAnteriorComparavel : 0.0;
$totaisMesAnteriorPorDiaSemana = array_fill(0, 7, 0.0);
$quantidadesMesAnteriorPorDiaSemana = array_fill(0, 7, 0);
$cursorHistoricoPrevisao = strtotime($inicioMesAnterior);
$fimHistoricoPrevisao = strtotime($fimMesAnterior);
while ($cursorHistoricoPrevisao <= $fimHistoricoPrevisao) {
    $dataHistorica = date('Y-m-d', $cursorHistoricoPrevisao);
    $diaSemanaHistorico = (int)date('w', $cursorHistoricoPrevisao);
    $totaisMesAnteriorPorDiaSemana[$diaSemanaHistorico] += (float)($vendasMesAnteriorCompleto[$dataHistorica]['total'] ?? 0.0);
    $quantidadesMesAnteriorPorDiaSemana[$diaSemanaHistorico]++;
    $cursorHistoricoPrevisao = strtotime('+1 day', $cursorHistoricoPrevisao);
}
$previsaoDiasRestantes = 0.0;
$cursorPrevisao = strtotime($temDiasFechados ? $filtroDataFim . ' +1 day' : $inicioMesAtual);
$fimPrevisao = strtotime($fimMesAtual);
while ($cursorPrevisao <= $fimPrevisao) {
    $diaSemanaPrevisao = (int)date('w', $cursorPrevisao);
    $quantidadeOcorrencias = $quantidadesMesAnteriorPorDiaSemana[$diaSemanaPrevisao];
    if ($quantidadeOcorrencias > 0) {
        $previsaoDiasRestantes += $totaisMesAnteriorPorDiaSemana[$diaSemanaPrevisao] / $quantidadeOcorrencias;
    }
    $cursorPrevisao = strtotime('+1 day', $cursorPrevisao);
}
$previsaoFechamento = $faturamentoRealMes + $previsaoDiasRestantes;
$variacaoComparavel = $totalAnteriorComparavel > 0 ? (($totalAtualAteReferencia / $totalAnteriorComparavel) - 1) * 100 : null;
$variacaoMesAnterior = $totalMesAnterior > 0 ? (($previsaoFechamento / $totalMesAnterior) - 1) * 100 : null;
$percentualMeta = $metaVendas > 0 ? min(100, ($faturamentoRealMes / $metaVendas) * 100) : 0;
$faltanteMeta = max(0, $metaVendas - $faturamentoRealMes);
$diasRestantesCalendario = max(0, $diasMesAtual - $diasDecorridos);
$mediaNecessaria = $metaVendas > 0 && $diasRestantesCalendario > 0
    ? $faltanteMeta / $diasRestantesCalendario
    : null;
$ordemHorasMetaVendas = array_merge(range(7, 23), range(0, 2));
$maiorValorHora = 0.0;
$graficoHoras = [];
foreach ($ordemHorasMetaVendas as $hora) {
    $totalHora = (float)($vendasPorHora[$hora]['total'] ?? 0.0);
    $qtdHora = (int)($vendasPorHora[$hora]['qtd'] ?? 0);
    $maiorValorHora = max($maiorValorHora, $totalHora);
    $graficoHoras[] = [
        'hora' => $hora,
        'rotulo' => str_pad((string)$hora, 2, '0', STR_PAD_LEFT) . ':00',
        'total' => $totalHora,
        'qtd' => $qtdHora,
    ];
}

$vendasPorDiaSemana = [];
foreach ($diasSemanaMetaVendas as $diaSemana => $nomeDiaSemana) {
    $vendasPorDiaSemana[$diaSemana] = [
        'nome' => $nomeDiaSemana,
        'dias_ocorridos' => 0,
        'total' => 0.0,
        'qtd' => 0,
        'media_dia' => 0.0,
        'ticket_medio' => 0.0,
    ];
}
$cursorDiaSemana = strtotime($filtroDataIni);
$fimDiaSemana = strtotime($filtroDataFim);
while ($cursorDiaSemana <= $fimDiaSemana) {
    $diaSemana = (int)date('w', $cursorDiaSemana);
    if (in_array($diaSemana, $diasSelecionados, true)) {
        $vendasPorDiaSemana[$diaSemana]['dias_ocorridos']++;
    }
    $cursorDiaSemana = strtotime('+1 day', $cursorDiaSemana);
}
foreach ($vendasMesAtual as $dataVenda => $vendaDia) {
    $diaSemana = (int)date('w', strtotime($dataVenda));
    $vendasPorDiaSemana[$diaSemana]['total'] += (float)$vendaDia['total'];
    $vendasPorDiaSemana[$diaSemana]['qtd'] += (int)$vendaDia['qtd'];
}
foreach ($vendasPorDiaSemana as $diaSemana => $vendaDiaSemana) {
    $quantidadeVendas = (int)$vendaDiaSemana['qtd'];
    $diasOcorridos = (int)$vendaDiaSemana['dias_ocorridos'];
    $vendasPorDiaSemana[$diaSemana]['media_dia'] = $diasOcorridos > 0
        ? (float)$vendaDiaSemana['total'] / $diasOcorridos
        : 0.0;
    $vendasPorDiaSemana[$diaSemana]['ticket_medio'] = $quantidadeVendas > 0
        ? (float)$vendaDiaSemana['total'] / $quantidadeVendas
        : 0.0;
}

if ($modoPdfMetaVendas) {
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <style>
            @page { margin: 8mm; }
            * { box-sizing: border-box; }
            body { background: #f3f6fb; color: #1f2937; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
            h1, h2, h3, p { margin-top: 0; }
            h1, .h3 { font-size: 18px; }
            h2, .h5 { font-size: 14px; }
            h3, .h6 { font-size: 11px; }
            .small { font-size: 8px; }
            .fw-bold, .fw-semibold { font-weight: bold; }
            .text-muted { color: #65748b; }
            .text-white { color: #fff; }
            .text-end, .text-md-end, .text-lg-end { text-align: right; }
            .text-center { text-align: center; }
            .text-start { text-align: left; }
            .text-success { color: #198754; }
            .text-danger { color: #dc3545; }
            .text-nowrap { white-space: nowrap; }
            .mb-0 { margin-bottom: 0; }
            .mb-1 { margin-bottom: 3px; }
            .mb-2 { margin-bottom: 6px; }
            .mb-3 { margin-bottom: 10px; }
            .mb-4 { margin-bottom: 14px; }
            .mt-1 { margin-top: 3px; }
            .mt-3 { margin-top: 10px; }
            .mt-4 { margin-top: 14px; }
            section { margin-bottom: 10px !important; }
            .p-4, .p-lg-5 { padding: 12px; }
            .p-3 { padding: 8px; }
            .border { border: 1px solid #ced7e4; }
            .border-bottom { border-bottom: 1px solid #ced7e4; }
            .border-primary { border-color: #1f6fff; }
            .rounded-2, .card { border-radius: 4px; }
            .card { background: #fff; border: 1px solid #ced7e4; }
            .card-header { padding: 10px; }
            .card-body { padding: 10px; }
            .badge { display: inline-block; padding: 3px 6px; background: #ffc107; color: #111827; border-radius: 3px; }
            .row { width: 100%; }
            .row:after { content: ''; display: table; clear: both; }
            .col, [class*='col-'] { float: left; padding: 3px; }
            .col-sm-4 { width: 33.333%; }
            .col-lg-8 { width: 66.666%; }
            .col-lg-4 { width: 33.333%; }
            .col-lg-3 { width: 25%; }
            .col-lg-2 { width: 16.666%; }
            .row-cols-xl-5 > .col { width: 20%; }
            .d-flex { display: block; }
            .d-grid { display: block; }
            .h-100 { height: auto !important; }
            .card, .border, .rounded-2 { border-color: #ced7e4 !important; }
            .shadow-sm { box-shadow: none !important; }
            .card-header { background: #173a78 !important; color: #fff !important; }
            .table-responsive { overflow: visible !important; }
            .form-control, .form-select { border: 1px solid #ced7e4; padding: 4px; width: 100%; }
            .btn { display: inline-block; padding: 4px 8px; border: 1px solid #6b7280; border-radius: 3px; }
            .bg-light { background: #f8fafc; }
            table { width: 100% !important; min-width: 0 !important; border-collapse: collapse; page-break-inside: auto; }
            thead { display: table-header-group; }
            tfoot { display: table-row-group; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            th, td { border: 1px solid #d6dde7; padding: 3px 4px; font-size: 7.2px; }
            .table-primary th { background: #173a78 !important; color: #fff !important; }
            .table-secondary td { background: #e9edf3 !important; }
            .bg-primary-subtle { background: #dbe8ff !important; }
            .progress { height: 12px; background: #dce2ea !important; }
            .progress-bar { height: 12px; background: #1f6fff !important; }
            .bg-success { background: #198754 !important; }
            .bg-warning { background: #ffc107 !important; }
            .bg-danger { background: #dc3545 !important; }
            .pdf-ocultar { display: none !important; }
            input, button, select { font-size: 8px !important; }
            h1, h2, h3 { page-break-after: avoid; }
        </style>
    </head>
    <body>
    <?php
} else {
    require __DIR__ . '/../../layout/header.php';
}
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-warning mb-3">Fechamento</span>
                <h1 class="h3 fw-bold mb-2">Metas de Vendas</h1>
                <p class="text-muted mb-0">Acompanhe a meta mensal, compare o ritmo com o mes anterior e projete o fechamento.</p>
            </div>
            <div class="col-lg-4 text-lg-end<?= $modoPdfMetaVendas ? ' pdf-ocultar' : '' ?>">
                <a href="metas_vendas_pdf.php?<?= htmlspecialchars($queryFiltro) ?>" class="btn btn-danger" target="_blank">PDF</a>
                <a href="menu_fechamento.php" class="btn btn-outline-secondary">Voltar ao fechamento</a>
            </div>
        </div>
    </div>
</section>

<section>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-primary text-white">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                <div>
                    <h2 class="h5 mb-1">Meta e evolucao das vendas</h2>
                    <div class="small opacity-75">Mes selecionado: <?= date('m/Y', strtotime($inicioMesAtual)) ?> | Comparativo: <?= date('m/Y', strtotime($inicioMesAnterior)) ?></div>
                </div>
                <form method="post" action="metas_vendas.php?<?= htmlspecialchars($queryFiltro) ?>" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-end">
                    <input type="hidden" name="acao" value="salvar_meta_vendas">
                    <div>
                        <label for="valor_meta" class="form-label small mb-1 text-white">Meta do mes</label>
                        <input
                            type="text"
                            name="valor_meta"
                            id="valor_meta"
                            class="form-control form-control-sm"
                            inputmode="decimal"
                            value="<?= number_format($metaVendas, 2, ',', '.') ?>"
                        >
                    </div>
                    <button type="submit" class="btn btn-warning btn-sm fw-semibold">Salvar meta</button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <?php if ($erroFiltro): ?>
                <div class="alert alert-warning py-2" role="alert"><?= htmlspecialchars($erroFiltro) ?></div>
            <?php endif; ?>
            <?php if (($_GET['meta'] ?? '') === 'ok'): ?>
                <div class="alert alert-success py-2">Meta de vendas salva.</div>
            <?php endif; ?>
            <?php if (($_GET['distribuicao'] ?? '') === 'ok'): ?>
                <div class="alert alert-success py-2">Distribuicao diaria da meta salva.</div>
            <?php endif; ?>

            <form method="post" action="metas_vendas.php?<?= htmlspecialchars($queryFiltro) ?>" class="border rounded-2 mb-3" id="form-distribuicao-meta">
                <input type="hidden" name="acao" value="salvar_distribuicao_meta">
                <div class="p-3 border-bottom d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
                    <div>
                        <h3 class="h6 fw-bold mb-1">Distribuicao da meta por dia da semana</h3>
                        <div class="small text-muted">Defina a meta de um dia; o sistema multiplica pelas ocorrencias desse dia em <?= date('m/Y', strtotime($inicioMesAtual)) ?>.</div>
                    </div>
                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input js-dia-trabalhado" type="checkbox" role="switch" name="trabalha_sabado" id="trabalha_sabado" data-dia="6" <?= $distribuicaoMeta[6]['trabalha'] === 'S' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="trabalha_sabado">Trabalha aos sabados</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input js-dia-trabalhado" type="checkbox" role="switch" name="trabalha_domingo" id="trabalha_domingo" data-dia="0" <?= $distribuicaoMeta[0]['trabalha'] === 'S' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="trabalha_domingo">Trabalha aos domingos</label>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Dia da semana</th><th class="text-center">Dias no mes</th><th class="text-center">Participacao dos dias</th><th class="text-end">Meta por dia</th><th class="text-end">Previsao no mes</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($distribuicaoMeta as $diaValor => $dia): ?>
                                <?php $habilitado = $dia['trabalha'] === 'S'; ?>
                                <tr class="js-linha-meta-dia<?= $habilitado ? '' : ' table-secondary' ?>" data-dia="<?= (int)$diaValor ?>" data-quantidade="<?= (int)$dia['quantidade'] ?>">
                                    <td class="fw-semibold"><?= htmlspecialchars($dia['nome']) ?></td>
                                    <td class="text-center js-quantidade-dia"><?= $habilitado ? (int)$dia['quantidade'] : 0 ?></td>
                                    <td class="text-center js-percentual-dia"><?= $habilitado && $totalDiasTrabalhadosMeta > 0 ? number_format(($dia['quantidade'] / $totalDiasTrabalhadosMeta) * 100, 1, ',', '.') . '%' : '-' ?></td>
                                    <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-end js-meta-dia" name="valor_meta_dia[<?= (int)$diaValor ?>]" value="<?= number_format($dia['valor_dia'], 2, ',', '.') ?>" <?= $habilitado ? '' : 'disabled' ?>></td>
                                    <td class="text-end fw-semibold js-subtotal-dia"><?= moedaMetaVendas($habilitado ? $dia['valor_dia'] * $dia['quantidade'] : 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-3 d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
                    <div class="d-flex flex-wrap gap-4">
                        <div><span class="small text-muted d-block">Meta mensal</span><strong id="meta-mensal-distribuicao" data-valor="<?= number_format($metaVendas, 2, '.', '') ?>"><?= moedaMetaVendas($metaVendas) ?></strong></div>
                        <div><span class="small text-muted d-block">Previsao distribuida</span><strong id="previsao-distribuida"><?= moedaMetaVendas($previsaoDistribuidaMeta) ?></strong></div>
                        <div><span class="small text-muted d-block">Diferenca</span><strong id="diferenca-distribuicao"><?= moedaMetaVendas($diferencaDistribuicaoMeta) ?></strong></div>
                    </div>
                    <div class="d-flex flex-column flex-sm-row gap-2 align-items-sm-center">
                        <span class="badge fs-6" id="status-distribuicao"></span>
                        <button type="submit" class="btn btn-primary btn-sm">Salvar distribuicao</button>
                    </div>
                </div>
            </form>

            <form method="get" class="border rounded-2 p-3 mb-3 bg-light">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label for="data_ini" class="form-label small fw-semibold">Data inicial</label>
                        <input type="date" name="data_ini" id="data_ini" class="form-control form-control-sm" value="<?= htmlspecialchars($filtroDataIni) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="data_fim" class="form-label small fw-semibold">Data final</label>
                        <input type="date" name="data_fim" id="data_fim" class="form-control form-control-sm" value="<?= htmlspecialchars($filtroDataFim) ?>" max="<?= htmlspecialchars($ontem) ?>">
                    </div>
                    <div class="col-md-4">
                        <div class="form-label small fw-semibold">Dias da semana</div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($diasSemanaMetaVendas as $diaValor => $diaNome): ?>
                                <label class="form-check form-check-inline m-0 small">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="dias[]"
                                        value="<?= (int)$diaValor ?>"
                                        <?= in_array((int)$diaValor, $diasSelecionados, true) ? 'checked' : '' ?>
                                    >
                                    <span class="form-check-label"><?= htmlspecialchars($diaNome) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
                    </div>
                </div>
            </form>

            <div class="row row-cols-1 row-cols-md-2 row-cols-xl-5 g-3 mb-3">
                <div class="col">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">
                            <?= $temDiasFechados ? 'Periodo filtrado' : 'Sem dias fechados no mes' ?>
                        </div>
                        <div class="h4 mb-1"><?= moedaMetaVendas($totalAtualAteReferencia) ?></div>
                        <div class="small"><?= date('d/m', strtotime($filtroDataIni)) ?> ate <?= date('d/m', strtotime($filtroDataFim)) ?> | Meta: <?= $metaVendas > 0 ? moedaMetaVendas($metaVendas) : 'Nao informada' ?></div>
                    </div>
                </div>
                <div class="col">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small"><?= $diasRestantesCalendario > 0 ? 'Previsao versus mes anterior' : 'Resultado versus mes anterior' ?></div>
                        <div class="h4 mb-1 <?= $variacaoMesAnterior !== null && $variacaoMesAnterior < 0 ? 'text-danger' : 'text-success' ?>">
                            <?= percentualMetaVendas($variacaoMesAnterior) ?>
                        </div>
                        <div class="small">
                            <?= moedaMetaVendas($previsaoFechamento - $totalMesAnterior) ?> sobre <?= moedaMetaVendas($totalMesAnterior) ?> faturados no mes anterior
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small"><?= $diasRestantesCalendario > 0 ? 'Previsao de fechamento' : 'Fechamento realizado' ?></div>
                        <div class="h4 mb-1"><?= moedaMetaVendas($previsaoFechamento) ?></div>
                        <div class="small"><?= $diasRestantesCalendario > 0 ? 'Acumulado mais a media, no mes anterior, dos mesmos dias da semana ainda restantes' : 'Faturamento apurado no mes selecionado' ?></div>
                    </div>
                </div>
                <div class="col">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Necessario por dia</div>
                        <div class="h4 mb-1"><?= $mediaNecessaria !== null ? moedaMetaVendas($mediaNecessaria) : '-' ?></div>
                        <div class="small"><?= $diasRestantesCalendario > 0 ? 'Para atingir a meta no fim do mes' : 'Mes encerrado' ?></div>
                    </div>
                </div>
                <div class="col">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small d-flex justify-content-between gap-2">
                            <span>Ticket medio</span>
                            <span>Total de vendas: <?= numeroMetaVendas($qtdVendasAtualAteReferencia) ?></span>
                        </div>
                        <div class="h4 mb-1"><?= moedaMetaVendas($ticketMedioAtualAteReferencia) ?></div>
                        <div class="small">Mes anterior equivalente: <?= moedaMetaVendas($ticketMedioAnteriorComparavel) ?></div>
                    </div>
                </div>
            </div>

            <div class="border border-primary rounded-2 p-3 mb-3 bg-primary-subtle">
                <div class="row g-3 align-items-center">
                    <div class="col-lg-4">
                        <div class="text-muted small"><?= $diasRestantesCalendario > 0 ? 'Previsao pelo desempenho do mes' : 'Fechamento realizado' ?></div>
                        <div class="h3 mb-1"><?= moedaMetaVendas($previsaoFechamentoDesempenho) ?></div>
                        <div class="small">
                            <?= $percentualPrevisaoMeta !== null ? percentualMetaVendas($percentualPrevisaoMeta) . ' da meta mensal' : 'Meta mensal nao informada' ?>
                        </div>
                    </div>
                    <div class="col-sm-4 col-lg-2">
                        <div class="text-muted small">Realizado no mes</div>
                        <div class="fw-bold"><?= moedaMetaVendas($faturamentoRealMes) ?></div>
                        <div class="small">Ate <?= date('d/m/Y', strtotime($dataReferencia)) ?></div>
                    </div>
                    <div class="col-sm-4 col-lg-3">
                        <div class="text-muted small">Projetado nos dias restantes</div>
                        <div class="fw-bold"><?= moedaMetaVendas($previsaoRestantePorMeta) ?></div>
                        <div class="small"><?= $diasRestantesTrabalhados ?> dia(s) de trabalho restante(s)</div>
                    </div>
                    <div class="col-sm-4 col-lg-3">
                        <div class="text-muted small">Base da projecao</div>
                        <div class="fw-semibold"><?= $diasRestantesPelaMeta ?> dia(s) pela meta distribuida</div>
                        <div class="small">Realizado ate a data mais metas dos dias restantes</div>
                    </div>
                </div>
            </div>

            <?php if ($metaVendas > 0): ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>Progresso da meta</span>
                        <span><?= number_format($percentualMeta, 1, ',', '.') ?>%</span>
                    </div>
                    <div class="progress" role="progressbar" aria-label="Progresso da meta">
                        <div class="progress-bar bg-success" style="width: <?= number_format($percentualMeta, 2, '.', '') ?>%"></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-12">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0 text-center">
                            <thead class="table-primary">
                                <tr>
                                    <th>Mes atual</th>
                                    <th>Mes anterior equivalente</th>
                                    <th class="text-end">Atual</th>
                                    <th class="text-end">Vendas</th>
                                    <th class="text-end">Ticket medio</th>
                                    <th class="text-end">Meta do dia</th>
                                    <th class="text-end">Dif. meta</th>
                                    <th class="text-end">Meta %</th>
                                    <th class="text-end">Anterior</th>
                                    <th class="text-end">Dif.</th>
                                    <th class="text-end">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comparativoDias as $linha): ?>
                                    <tr>
                                        <td class="text-start">
                                            <div class="fw-semibold"><?= rotuloDataMetaVendas($linha['data_atual']) ?></div>
                                            <div class="small text-muted"><?= (int)$linha['ocorrencia'] ?>a ocorrencia do dia da semana</div>
                                        </td>
                                        <td class="text-start"><?= $linha['data_anterior'] ? rotuloDataMetaVendas($linha['data_anterior']) : '-' ?></td>
                                        <td class="text-end"><?= moedaMetaVendas((float)$linha['valor_atual']) ?></td>
                                        <td class="text-end"><?= numeroMetaVendas((int)$linha['qtd_atual']) ?></td>
                                        <td class="text-end"><?= moedaMetaVendas((float)$linha['ticket_medio_atual']) ?></td>
                                        <td class="text-end"><?= (float)$linha['meta_distribuida'] > 0 ? moedaMetaVendas((float)$linha['meta_distribuida']) : '-' ?></td>
                                        <td class="text-end <?= (float)$linha['diferenca_meta'] < 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= (float)$linha['meta_distribuida'] > 0 ? moedaMetaVendas((float)$linha['diferenca_meta']) : '-' ?>
                                        </td>
                                        <td class="text-end"><?= percentualMetaVendas($linha['percentual_meta']) ?></td>
                                        <td class="text-end"><?= moedaMetaVendas((float)$linha['valor_anterior']) ?></td>
                                        <td class="text-end <?= (float)$linha['diferenca'] < 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= moedaMetaVendas((float)$linha['diferenca']) ?>
                                        </td>
                                        <td class="text-end"><?= percentualMetaVendas($linha['percentual']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-secondary fw-semibold">
                                <tr>
                                    <td colspan="2" class="text-start">Total comparavel</td>
                                    <td class="text-end"><?= moedaMetaVendas($totalAtualAteReferencia) ?></td>
                                    <td class="text-end"><?= numeroMetaVendas($qtdVendasAtualAteReferencia) ?></td>
                                    <td class="text-end"><?= moedaMetaVendas($ticketMedioAtualAteReferencia) ?></td>
                                    <td class="text-end"><?= moedaMetaVendas($totalMetaDistribuidaComparavel) ?></td>
                                    <td class="text-end <?= ($totalAtualAteReferencia - $totalMetaDistribuidaComparavel) < 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= moedaMetaVendas($totalAtualAteReferencia - $totalMetaDistribuidaComparavel) ?>
                                    </td>
                                    <td class="text-end"><?= $totalMetaDistribuidaComparavel > 0 ? percentualMetaVendas(($totalAtualAteReferencia / $totalMetaDistribuidaComparavel) * 100) : '-' ?></td>
                                    <td class="text-end"><?= moedaMetaVendas($totalAnteriorComparavel) ?></td>
                                    <td class="text-end <?= ($totalAtualAteReferencia - $totalAnteriorComparavel) < 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= moedaMetaVendas($totalAtualAteReferencia - $totalAnteriorComparavel) ?>
                                    </td>
                                    <td class="text-end"><?= percentualMetaVendas($variacaoComparavel) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-4 border rounded-2 p-3">
                <div class="d-flex flex-column flex-md-row justify-content-between gap-1 mb-3">
                    <div>
                        <h3 class="h6 fw-bold mb-1">Vendas por hora</h3>
                        <div class="small text-muted"><?= date('d/m/Y', strtotime($filtroDataIni)) ?> ate <?= date('d/m/Y', strtotime($filtroDataFim)) ?></div>
                    </div>
                    <div class="small text-muted">Total filtrado: <?= moedaMetaVendas($totalAtualAteReferencia) ?></div>
                </div>
                <?php if ($maiorValorHora <= 0): ?>
                    <div class="text-muted small">Nenhuma venda encontrada para os filtros informados.</div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($graficoHoras as $hora): ?>
                            <?php $larguraBarra = $maiorValorHora > 0 ? max(2, ((float)$hora['total'] / $maiorValorHora) * 100) : 0; ?>
                            <?php $classeBarraHora = classeBarraHoraMetaVendas((float)$hora['total'], $maiorValorHora); ?>
                            <div class="row g-2 align-items-center">
                                <div class="col-2 col-md-1 small fw-semibold"><?= htmlspecialchars($hora['rotulo']) ?></div>
                                <div class="col-7 col-md-8">
                                    <div class="progress" style="height: 18px;">
                                        <div
                                            class="progress-bar <?= htmlspecialchars($classeBarraHora) ?>"
                                            role="progressbar"
                                            style="width: <?= number_format($larguraBarra, 2, '.', '') ?>%;"
                                            aria-valuenow="<?= number_format($larguraBarra, 2, '.', '') ?>"
                                            aria-valuemin="0"
                                            aria-valuemax="100"
                                        ></div>
                                    </div>
                                </div>
                                <div class="col-3 col-md-3 small text-end">
                                    <span class="fw-semibold"><?= moedaMetaVendas((float)$hora['total']) ?></span>
                                    <span class="text-muted d-block d-md-inline">/ <?= numeroMetaVendas((int)$hora['qtd']) ?> venda(s)</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mt-3 border rounded-2 p-3">
                <div class="d-flex flex-column flex-md-row justify-content-between gap-1 mb-3">
                    <div>
                        <h3 class="h6 fw-bold mb-1">Vendas por dia da semana</h3>
                        <div class="small text-muted"><?= date('d/m/Y', strtotime($filtroDataIni)) ?> ate <?= date('d/m/Y', strtotime($filtroDataFim)) ?></div>
                    </div>
                    <div class="small text-muted">Considera os dias da semana selecionados no filtro</div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-primary">
                            <tr>
                                <th>Dia da semana</th>
                                <th class="text-end">Dias ocorridos</th>
                                <th class="text-end">Valor</th>
                                <th class="text-end">Media do dia</th>
                                <th class="text-end">Ticket medio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vendasPorDiaSemana as $diaSemana => $vendaDiaSemana): ?>
                                <?php if (!in_array((int)$diaSemana, $diasSelecionados, true)) continue; ?>
                                <tr>
                                    <td class="fw-semibold"><?= htmlspecialchars((string)$vendaDiaSemana['nome']) ?></td>
                                    <td class="text-end"><?= numeroMetaVendas((int)$vendaDiaSemana['dias_ocorridos']) ?></td>
                                    <td class="text-end"><?= moedaMetaVendas((float)$vendaDiaSemana['total']) ?></td>
                                    <td class="text-end"><?= moedaMetaVendas((float)$vendaDiaSemana['media_dia']) ?></td>
                                    <td class="text-end"><?= moedaMetaVendas((float)$vendaDiaSemana['ticket_medio']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary fw-semibold">
                            <tr>
                                <td>Total filtrado</td>
                                <td class="text-end"><?= numeroMetaVendas(count($comparativoDias)) ?></td>
                                <td class="text-end"><?= moedaMetaVendas($totalAtualAteReferencia) ?></td>
                                <td class="text-end"><?= moedaMetaVendas($mediaDiaAtual) ?></td>
                                <td class="text-end"><?= moedaMetaVendas($ticketMedioAtualAteReferencia) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="mt-3 border rounded-2 p-3">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-3">
                    <div>
                        <h3 class="h6 fw-bold mb-1">Vendas por produto</h3>
                        <div class="small text-muted">Quantidade e valor dos produtos vendidos no periodo filtrado</div>
                        <div class="small text-muted">
                            <?= date('d/m/Y', strtotime($filtroDataIni)) ?> ate <?= date('d/m/Y', strtotime($filtroDataFim)) ?>
                        </div>
                    </div>
                    <form method="get" class="d-flex flex-column flex-sm-row align-items-sm-end gap-2">
                        <input type="hidden" name="data_ini" value="<?= htmlspecialchars($filtroDataIni) ?>">
                        <input type="hidden" name="data_fim" value="<?= htmlspecialchars($filtroDataFim) ?>">
                        <?php foreach ($diasSelecionados as $diaSelecionado): ?>
                            <input type="hidden" name="dias[]" value="<?= (int)$diaSelecionado ?>">
                        <?php endforeach; ?>
                        <div>
                            <label for="produto-mes-comparacao" class="form-label small mb-1">Mes de comparacao</label>
                            <input
                                type="month"
                                class="form-control form-control-sm"
                                id="produto-mes-comparacao"
                                name="produto_mes_comparacao"
                                value="<?= htmlspecialchars($mesComparacaoProduto) ?>"
                            >
                            <div class="small text-muted mt-1 text-nowrap">
                                <?= date('d/m/Y', strtotime($inicioComparacaoProduto)) ?> a <?= date('d/m/Y', strtotime($fimComparacaoProduto)) ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Comparar</button>
                    </form>
                </div>
                <?php if (!$vendasPorProduto): ?>
                    <div class="text-muted small">Nenhum produto vendido no periodo filtrado.</div>
                <?php else: ?>
                    <?php
                    $blocosVendasPorProduto = $modoPdfMetaVendas
                        ? array_chunk($vendasPorProduto, 60)
                        : [$vendasPorProduto];
                    $ultimoBlocoVendasPorProduto = count($blocosVendasPorProduto) - 1;
                    ?>
                    <?php foreach ($blocosVendasPorProduto as $indiceBloco => $blocoVendasPorProduto): ?>
                    <div class="table-responsive<?= $indiceBloco > 0 ? ' mt-3' : '' ?>">
                        <table class="table table-sm table-bordered table-hover align-middle mb-0" style="min-width: 760px;">
                            <thead class="table-primary">
                                <tr>
                                    <th rowspan="2" class="align-middle text-nowrap" style="width: 1%;">Cod.</th>
                                    <th rowspan="2" class="align-middle" style="width: 100%;">Descricao</th>
                                    <th rowspan="2" class="align-middle text-nowrap" style="width: 1%;">Un.</th>
                                    <th colspan="2" class="text-center text-nowrap">Periodo filtrado</th>
                                    <th colspan="2" class="text-center text-nowrap">Comparacao</th>
                                </tr>
                                <tr>
                                    <th class="text-end text-nowrap" style="width: 1%;">QTD</th>
                                    <th class="text-end text-nowrap" style="width: 1%;">Valor</th>
                                    <th class="text-end text-nowrap" style="width: 1%;">QTD</th>
                                    <th class="text-end text-nowrap" style="width: 1%;">Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($blocoVendasPorProduto as $produto): ?>
                                    <tr>
                                        <td class="text-nowrap"><?= htmlspecialchars((string)$produto['codigo']) ?></td>
                                        <td><?= htmlspecialchars((string)$produto['descricao']) ?></td>
                                        <td class="text-nowrap"><?= htmlspecialchars((string)$produto['unidade']) ?></td>
                                        <td class="text-end text-nowrap"><?= quantidadeMetaVendas((float)$produto['quantidade']) ?></td>
                                        <td class="text-end text-nowrap fw-semibold"><?= moedaMetaVendas((float)$produto['total']) ?></td>
                                        <td class="text-end text-nowrap"><?= quantidadeMetaVendas((float)($quantidadesProdutosComparacao[(string)$produto['PRODUTO']] ?? 0)) ?></td>
                                        <td class="text-end text-nowrap"><?= moedaMetaVendas((float)($valoresProdutosComparacao[(string)$produto['PRODUTO']] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if ($indiceBloco === $ultimoBlocoVendasPorProduto): ?>
                            <tfoot class="table-secondary fw-semibold">
                                <tr>
                                    <td colspan="3">Total do periodo</td>
                                    <td class="text-end"><?= quantidadeMetaVendas($quantidadeTotalProdutos) ?></td>
                                    <td class="text-end"><?= moedaMetaVendas($valorTotalProdutos) ?></td>
                                    <td class="text-end"><?= quantidadeMetaVendas($quantidadeTotalProdutosComparacao) ?></td>
                                    <td class="text-end"><?= moedaMetaVendas($valorTotalProdutosComparacao) ?></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="mt-3 border rounded-2 p-3">
                <div class="d-flex flex-column flex-md-row justify-content-between gap-1 mb-3">
                    <div>
                        <h3 class="h6 fw-bold mb-1">Produtos com estoque sem vendas</h3>
                        <div class="small text-muted">
                            Estoque atual maior que zero e nenhuma venda de
                            <?= date('d/m/Y', strtotime($filtroDataIni)) ?> a <?= date('d/m/Y', strtotime($filtroDataFim)) ?>
                        </div>
                    </div>
                    <div class="small text-muted text-md-end">
                        <div><?= numeroMetaVendas(count($produtosComEstoqueSemVenda)) ?> produto(s)</div>
                        <?php if ($estoqueCalculadoEmSemVenda): ?>
                            <div>Estoque Firebird: <?= date('d/m/Y H:i', strtotime($estoqueCalculadoEmSemVenda)) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!$produtosComEstoqueSemVenda): ?>
                    <div class="text-muted small">Nenhum produto com estoque positivo ficou sem vendas no periodo filtrado.</div>
                <?php else: ?>
                    <?php
                    $blocosProdutosSemVenda = $modoPdfMetaVendas
                        ? array_chunk($produtosComEstoqueSemVenda, 60)
                        : [$produtosComEstoqueSemVenda];
                    $ultimoBlocoProdutosSemVenda = count($blocosProdutosSemVenda) - 1;
                    ?>
                    <?php foreach ($blocosProdutosSemVenda as $indiceBloco => $blocoProdutosSemVenda): ?>
                    <div class="table-responsive<?= $indiceBloco > 0 ? ' mt-3' : '' ?>">
                        <table class="table table-sm table-bordered table-hover align-middle mb-0" style="min-width: 560px;">
                            <thead class="table-primary">
                                <tr>
                                    <th class="text-nowrap" style="width: 1%;">Cod.</th>
                                    <th style="width: 100%;">Descricao</th>
                                    <th class="text-nowrap" style="width: 1%;">Un.</th>
                                    <th class="text-end text-nowrap" style="width: 1%;">Estoque atual</th>
                                    <th class="text-end text-nowrap" style="width: 1%;">Preco de venda</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($blocoProdutosSemVenda as $produtoSemVenda): ?>
                                    <tr>
                                        <td class="text-nowrap"><?= htmlspecialchars((string)$produtoSemVenda['CODPRODUTO']) ?></td>
                                        <td><?= htmlspecialchars((string)$produtoSemVenda['DESCPRODUTO']) ?></td>
                                        <td class="text-nowrap"><?= htmlspecialchars((string)$produtoSemVenda['UNIDADE']) ?></td>
                                        <td class="text-end text-nowrap fw-semibold"><?= quantidadeMetaVendas((float)$produtoSemVenda['estoque_atual']) ?></td>
                                        <td class="text-end text-nowrap"><?= moedaMetaVendas((float)$produtoSemVenda['preco_venda']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php if ($indiceBloco === $ultimoBlocoProdutosSemVenda): ?>
                            <tfoot class="table-secondary fw-semibold">
                                <tr>
                                    <td colspan="3">Total</td>
                                    <td class="text-end text-nowrap"><?= quantidadeMetaVendas($estoqueAtualSemVenda) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php if (!$modoPdfMetaVendas): ?>
<script>
(() => {
    const moeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const numero = (valor) => Number.parseFloat(String(valor || '').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.')) || 0;
    const linhas = [...document.querySelectorAll('.js-linha-meta-dia')];
    const metaMensal = Number(document.getElementById('meta-mensal-distribuicao').dataset.valor) || 0;
    function atualizar() {
        let diasTrabalhados = 0;
        linhas.forEach((linha) => { if (!linha.querySelector('.js-meta-dia').disabled) diasTrabalhados += Number(linha.dataset.quantidade); });
        let previsao = 0;
        linhas.forEach((linha) => {
            const campo = linha.querySelector('.js-meta-dia');
            const habilitado = !campo.disabled;
            const quantidade = habilitado ? Number(linha.dataset.quantidade) : 0;
            const subtotal = quantidade * numero(campo.value);
            previsao += subtotal;
            linha.querySelector('.js-quantidade-dia').textContent = quantidade;
            linha.querySelector('.js-percentual-dia').textContent = habilitado && diasTrabalhados ? ((quantidade / diasTrabalhados) * 100).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%' : '-';
            linha.querySelector('.js-subtotal-dia').textContent = moeda.format(subtotal);
        });
        const diferenca = previsao - metaMensal;
        document.getElementById('previsao-distribuida').textContent = moeda.format(previsao);
        document.getElementById('diferenca-distribuicao').textContent = moeda.format(diferenca);
        const status = document.getElementById('status-distribuicao');
        const confere = metaMensal > 0 && Math.abs(diferenca) < 0.01;
        status.className = 'badge fs-6 ' + (confere ? 'text-bg-success' : 'text-bg-warning');
        status.textContent = confere ? 'Distribuicao confere' : 'Ajuste a distribuicao';
    }
    document.querySelectorAll('.js-meta-dia').forEach((campo) => campo.addEventListener('input', atualizar));
    document.querySelectorAll('.js-dia-trabalhado').forEach((controle) => controle.addEventListener('change', () => {
        const linha = document.querySelector(`.js-linha-meta-dia[data-dia="${controle.dataset.dia}"]`);
        linha.querySelector('.js-meta-dia').disabled = !controle.checked;
        linha.classList.toggle('table-secondary', !controle.checked);
        atualizar();
    }));
    atualizar();
})();
</script>

<?php require __DIR__ . '/../../layout/footer.php'; ?>
<?php else: ?>
</body>
</html>
<?php endif; ?>
