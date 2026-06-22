<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$mesAtual = date('Y-m');
$inicioMesAtual = $mesAtual . '-01';
$fimMesAtual = date('Y-m-t', strtotime($inicioMesAtual));
$mesAnterior = date('Y-m', strtotime($inicioMesAtual . ' -1 month'));
$inicioMesAnterior = $mesAnterior . '-01';
$fimMesAnterior = date('Y-m-t', strtotime($inicioMesAnterior));
$dataReferencia = min(date('Y-m-d', strtotime('-1 day')), $fimMesAtual);
$temDiasFechados = $dataReferencia >= $inicioMesAtual;
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
$filtroDataIni = (string)($_GET['data_ini'] ?? $inicioMesAtual);
$filtroDataFim = (string)($_GET['data_fim'] ?? $dataReferencia);
$filtroDataIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtroDataIni) ? $filtroDataIni : $inicioMesAtual;
$filtroDataFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtroDataFim) ? $filtroDataFim : $dataReferencia;
$filtroDataFim = min($filtroDataFim, $dataReferencia);
if ($filtroDataIni > $filtroDataFim) {
    $filtroDataIni = $filtroDataFim;
}
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_meta_vendas') {
    $valorMeta = dinheiroParaFloatMetaVendas((string)($_POST['valor_meta'] ?? '0'));
    $stmtMetaSalvar = $pdo_master->prepare("
        INSERT INTO fechamento_metas_vendas (empresa_id, mes, valor_meta, atualizado_em)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE valor_meta = VALUES(valor_meta), atualizado_em = NOW()
    ");
    $stmtMetaSalvar->execute([$empresaId, $mesAtual, $valorMeta]);
    header('Location: metas_vendas.php?meta=ok');
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

$vendasMesAtual = vendasPorDiaMetaVendas($pdo_master, $empresaId, $filtroDataIni, $filtroDataFim, $diasSelecionados);
$vendasMesAnterior = vendasPorDiaMetaVendas($pdo_master, $empresaId, $inicioMesAnterior, $fimMesAnterior, $diasSelecionados);
$vendasPorHora = vendasPorHoraMetaVendas($pdo_master, $empresaId, $filtroDataIni, $filtroDataFim, $diasSelecionados);

$comparativoDias = [];
$totalAtualAteReferencia = 0.0;
$totalAnteriorComparavel = 0.0;
$qtdVendasAtualAteReferencia = 0;
$qtdVendasAnteriorComparavel = 0;
$maiorAlta = null;
$maiorQueda = null;

$cursor = strtotime($filtroDataIni);
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
    $diferenca = $valorAtual - $valorAnterior;
    $percentual = $valorAnterior > 0 ? (($valorAtual / $valorAnterior) - 1) * 100 : null;

    $totalAtualAteReferencia += $valorAtual;
    $totalAnteriorComparavel += $valorAnterior;
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
        'diferenca' => $diferenca,
        'percentual' => $percentual,
        'ocorrencia' => ocorrenciaSemanaMesMetaVendas($dataAtualLoop),
    ];

    $cursor = strtotime('+1 day', $cursor);
}

$totalMesAnterior = array_sum(array_column($vendasMesAnterior, 'total'));
$diasComparativo = count($comparativoDias);
$mediaDiaAtual = $diasComparativo > 0 ? $totalAtualAteReferencia / $diasComparativo : 0.0;
$ticketMedioAtualAteReferencia = $qtdVendasAtualAteReferencia > 0 ? $totalAtualAteReferencia / $qtdVendasAtualAteReferencia : 0.0;
$previsaoFechamento = $totalAnteriorComparavel > 0
    ? ($totalAtualAteReferencia / $totalAnteriorComparavel) * $totalMesAnterior
    : $mediaDiaAtual * $diasMesAtual;
$variacaoComparavel = $totalAnteriorComparavel > 0 ? (($totalAtualAteReferencia / $totalAnteriorComparavel) - 1) * 100 : null;
$percentualMeta = $metaVendas > 0 ? min(100, ($totalAtualAteReferencia / $metaVendas) * 100) : 0;
$faltanteMeta = max(0, $metaVendas - $totalAtualAteReferencia);
$mediaNecessaria = $metaVendas > 0
    ? ($faltanteMeta / max(1, $diasMesAtual - $diasDecorridos))
    : 0.0;
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

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-warning mb-3">Fechamento</span>
                <h1 class="h3 fw-bold mb-2">Metas de Vendas</h1>
                <p class="text-muted mb-0">Acompanhe a meta mensal, compare o ritmo com o mes anterior e projete o fechamento.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
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
                    <div class="small opacity-75">Mes atual: <?= date('m/Y', strtotime($inicioMesAtual)) ?> | Comparativo: <?= date('m/Y', strtotime($inicioMesAnterior)) ?></div>
                </div>
                <form method="post" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-end">
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
            <?php if (($_GET['meta'] ?? '') === 'ok'): ?>
                <div class="alert alert-success py-2">Meta de vendas salva.</div>
            <?php endif; ?>

            <form method="get" class="border rounded-2 p-3 mb-3 bg-light">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label for="data_ini" class="form-label small fw-semibold">Data inicial</label>
                        <input type="date" name="data_ini" id="data_ini" class="form-control form-control-sm" value="<?= htmlspecialchars($filtroDataIni) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="data_fim" class="form-label small fw-semibold">Data final</label>
                        <input type="date" name="data_fim" id="data_fim" class="form-control form-control-sm" value="<?= htmlspecialchars($filtroDataFim) ?>" max="<?= htmlspecialchars($dataReferencia) ?>">
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

            <div class="row g-3 mb-3">
                <div class="col-md-6 col-xl-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">
                            <?= $temDiasFechados ? 'Periodo filtrado' : 'Sem dias fechados no mes' ?>
                        </div>
                        <div class="h4 mb-1"><?= moedaMetaVendas($totalAtualAteReferencia) ?></div>
                        <div class="small"><?= date('d/m', strtotime($filtroDataIni)) ?> ate <?= date('d/m', strtotime($filtroDataFim)) ?> | Meta: <?= $metaVendas > 0 ? moedaMetaVendas($metaVendas) : 'Nao informada' ?></div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Comparavel ao mes anterior</div>
                        <div class="h4 mb-1 <?= $variacaoComparavel !== null && $variacaoComparavel < 0 ? 'text-danger' : 'text-success' ?>">
                            <?= percentualMetaVendas($variacaoComparavel) ?>
                        </div>
                        <div class="small"><?= moedaMetaVendas($totalAtualAteReferencia - $totalAnteriorComparavel) ?> sobre os mesmos dias</div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Previsao de fechamento</div>
                        <div class="h4 mb-1"><?= moedaMetaVendas($previsaoFechamento) ?></div>
                        <div class="small">Baseada no ritmo versus <?= date('m/Y', strtotime($inicioMesAnterior)) ?></div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Necessario por dia</div>
                        <div class="h4 mb-1"><?= $metaVendas > 0 ? moedaMetaVendas($mediaNecessaria) : '-' ?></div>
                        <div class="small">Para atingir a meta no fim do mes</div>
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
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
