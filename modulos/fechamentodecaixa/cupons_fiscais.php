<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
if (!moduloPermitido($pdo_master, $empresaId, 'fechamento_cupons_fiscais')) {
    http_response_code(403);
    exit('Acesso negado.');
}

$inicio = (string)($_GET['inicio'] ?? date('Y-m-01'));
$fim = (string)($_GET['fim'] ?? date('Y-m-d'));
$dataInicio = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
$dataFim = DateTimeImmutable::createFromFormat('!Y-m-d', $fim);
$erroPeriodo = null;
if (!$dataInicio || !$dataFim || $dataInicio->format('Y-m-d') !== $inicio ||
    $dataFim->format('Y-m-d') !== $fim || $dataFim < $dataInicio ||
    $dataInicio->diff($dataFim)->days > 366) {
    $erroPeriodo = 'Informe um periodo valido de ate 366 dias.';
    $inicio = date('Y-m-01');
    $fim = date('Y-m-d');
    $dataInicio = new DateTimeImmutable($inicio);
    $dataFim = new DateTimeImmutable($fim);
}

$fiscaisDisponiveis = false;
foreach (['armazem_est007_auxiliar', 'armazem_est026_auxiliar'] as $tabela) {
    $consulta = $pdo_master->prepare("SELECT 1 FROM $tabela WHERE EMPRESA = ? LIMIT 1");
    $consulta->execute([$empresaId]);
    if ($consulta->fetchColumn()) {
        $fiscaisDisponiveis = true;
        break;
    }
}

$diasCupons = [];
$totaisCupons = ['cupons' => 0.0, 'notas' => 0.0, 'recebiveis' => 0.0];
$fimExclusivo = $dataFim->modify('+1 day')->format('Y-m-d');
$consultas = [
    'recebiveis' => "SELECT DATE(data_venda) AS dia, SUM(COALESCE(valor_bruto, 0)) AS total
                     FROM armazem_conciliacao_recebimentos
                     WHERE empresa_id = :empresa AND data_venda >= :inicio AND data_venda < :fim
                       AND CMCONTADOR IN (1, 2, 3, 12)
                     GROUP BY DATE(data_venda)",
];
if ($fiscaisDisponiveis) {
    $consultas = array_merge([
        'cupons' => "SELECT DATE(DTEMISSAO) AS dia, SUM(COALESCE(TOTGERAL, 0)) AS total
                     FROM armazem_est007_auxiliar
                     WHERE EMPRESA = :empresa AND DTEMISSAO >= :inicio AND DTEMISSAO < :fim
                       AND excluido_firebird = 'N' AND COALESCE(CANCELADO, 'N') <> 'S'
                       AND DTCANCELADO IS NULL
                     GROUP BY DATE(DTEMISSAO)",
        'notas' => "SELECT DATE(DTEMISSAO) AS dia, SUM(COALESCE(TOTGERAL, 0)) AS total
                    FROM armazem_est026_auxiliar
                    WHERE EMPRESA = :empresa AND DTEMISSAO >= :inicio AND DTEMISSAO < :fim
                      AND excluido_firebird = 'N' AND DTCANCELADO IS NULL
                      AND DTHRCANCNFE IS NULL
                      AND (SITUACAONF IS NULL OR UPPER(TRIM(SITUACAONF)) NOT IN ('C', 'CANCELADA', 'CANCELADO'))
                    GROUP BY DATE(DTEMISSAO)",
    ], $consultas);
}
foreach ($consultas as $tipo => $sql) {
    $consulta = $pdo_master->prepare($sql);
    $consulta->execute(['empresa' => $empresaId, 'inicio' => $inicio, 'fim' => $fimExclusivo]);
    foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $dia = (string)$linha['dia'];
        $diasCupons[$dia][$tipo] = (float)$linha['total'];
        $totaisCupons[$tipo] += (float)$linha['total'];
    }
}
krsort($diasCupons);

$moedaCupons = static function (float $valor): string {
    return 'R$ ' . number_format($valor, 2, ',', '.');
};

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-warning mb-3">Fechamento</span>
                <h1 class="h3 fw-bold mb-2">Cupons fiscais</h1>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_fechamento.php" class="btn btn-outline-secondary">Voltar ao fechamento</a>
            </div>
        </div>
    </div>
</section>

<section>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="get" class="d-flex flex-wrap align-items-end gap-2 mb-3">
                <div>
                    <label class="form-label small mb-1" for="cupons-inicio">Data inicial</label>
                    <input class="form-control form-control-sm" type="date" id="cupons-inicio" name="inicio" value="<?= htmlspecialchars($inicio) ?>" required>
                </div>
                <div>
                    <label class="form-label small mb-1" for="cupons-fim">Data final</label>
                    <input class="form-control form-control-sm" type="date" id="cupons-fim" name="fim" value="<?= htmlspecialchars($fim) ?>" required>
                </div>
                <button class="btn btn-primary btn-sm" type="submit">Filtrar</button>
            </form>
            <?php if ($erroPeriodo): ?>
                <div class="alert alert-warning" role="alert"><?= htmlspecialchars($erroPeriodo) ?></div>
            <?php endif; ?>
            <?php if (!$fiscaisDisponiveis): ?>
                <div class="alert alert-warning" role="alert">
                    Dados fiscais auxiliares ainda nao sincronizados para esta empresa. Os recebiveis disponiveis sao exibidos abaixo.
                </div>
            <?php endif; ?>
            <?php if (!$diasCupons): ?>
                <p class="text-muted mb-0">Nenhum movimento no periodo.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Data</th>
                                <th scope="col" class="text-end">Total de cupons</th>
                                <th scope="col" class="text-end">Total de notas fiscais</th>
                                <th scope="col" class="text-end">Total do dia</th>
                                <th scope="col" class="text-end">Recebiveis</th>
                                <th scope="col" class="text-end">Diferenca</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($diasCupons as $dia => $valores):
                                $cupons = $valores['cupons'] ?? 0.0;
                                $notas = $valores['notas'] ?? 0.0;
                                $recebiveis = $valores['recebiveis'] ?? 0.0;
                                $totalDia = $cupons + $notas;
                            ?>
                                <tr>
                                    <th scope="row"><?= date('d/m/Y', strtotime($dia)) ?></th>
                                    <td class="text-end text-nowrap"><?= $moedaCupons($cupons) ?></td>
                                    <td class="text-end text-nowrap"><?= $moedaCupons($notas) ?></td>
                                    <td class="text-end text-nowrap fw-semibold"><?= $moedaCupons($totalDia) ?></td>
                                    <td class="text-end text-nowrap"><?= $moedaCupons($recebiveis) ?></td>
                                    <td class="text-end text-nowrap fw-semibold"><?= $moedaCupons($totalDia - $recebiveis) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-semibold">
                            <tr>
                                <th scope="row">Total</th>
                                <td class="text-end text-nowrap"><?= $moedaCupons($totaisCupons['cupons']) ?></td>
                                <td class="text-end text-nowrap"><?= $moedaCupons($totaisCupons['notas']) ?></td>
                                <td class="text-end text-nowrap"><?= $moedaCupons($totaisCupons['cupons'] + $totaisCupons['notas']) ?></td>
                                <td class="text-end text-nowrap"><?= $moedaCupons($totaisCupons['recebiveis']) ?></td>
                                <td class="text-end text-nowrap"><?= $moedaCupons($totaisCupons['cupons'] + $totaisCupons['notas'] - $totaisCupons['recebiveis']) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
