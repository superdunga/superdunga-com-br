<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
garantirTabelasDescontoCheques($pdo_master);

if ($empresaId !== 2 || !moduloPermitido($pdo_master, $empresaId, 'desconto_cheques_simulador')) {
    http_response_code(403);
    exit('Acesso nao autorizado.');
}

garantirFeriadosNacionaisFixosDC($pdo_master, $empresaId);
garantirFeriadosVariaveisDC($pdo_master, $empresaId, (int)date('Y') - 1, 6);

$taxaInformada = trim((string)($_GET['taxa'] ?? ''));
$dataOperacaoInformada = trim((string)($_GET['data_operacao'] ?? date('Y-m-d')));
$vencimentosInformados = $_GET['vencimento'] ?? [''];
$valoresInformados = $_GET['valor'] ?? [''];
$vencimentosInformados = is_array($vencimentosInformados) ? $vencimentosInformados : [$vencimentosInformados];
$valoresInformados = is_array($valoresInformados) ? $valoresInformados : [$valoresInformados];
$quantidadeLinhas = max(1, count($vencimentosInformados), count($valoresInformados));
$documentosForm = [];

for ($i = 0; $i < $quantidadeLinhas; $i++) {
    $documentosForm[] = [
        'vencimento' => trim((string)($vencimentosInformados[$i] ?? '')),
        'valor' => trim((string)($valoresInformados[$i] ?? '')),
    ];
}

$resultados = [];
$totais = ['quantidade' => 0, 'bruto' => 0.0, 'desconto' => 0.0, 'liquido' => 0.0];
$mensagemErro = '';

if (isset($_GET['simular'])) {
    $taxa = decimalDC($taxaInformada);
    $documentosValidos = [];
    $dataOperacao = DateTimeImmutable::createFromFormat('!Y-m-d', $dataOperacaoInformada);
    $errosDataOperacao = DateTimeImmutable::getLastErrors();
    $dataOperacaoValida = $dataOperacao !== false && ($errosDataOperacao === false || ($errosDataOperacao['warning_count'] === 0 && $errosDataOperacao['error_count'] === 0));

    if ($taxa < 0) {
        $mensagemErro = 'A taxa de desconto nao pode ser negativa.';
    } elseif (!$dataOperacaoValida) {
        $mensagemErro = 'Informe uma data de operacao valida.';
    } else {
        foreach ($documentosForm as $indice => $documento) {
            $valor = decimalDC($documento['valor']);
            $vencimento = DateTimeImmutable::createFromFormat('!Y-m-d', $documento['vencimento']);
            $errosData = DateTimeImmutable::getLastErrors();
            $dataValida = $vencimento !== false && ($errosData === false || ($errosData['warning_count'] === 0 && $errosData['error_count'] === 0));

            if ($valor <= 0) {
                $mensagemErro = 'Informe um valor maior que zero no documento ' . ($indice + 1) . '.';
                break;
            }
            if (!$dataValida) {
                $mensagemErro = 'Informe um vencimento valido no documento ' . ($indice + 1) . '.';
                break;
            }

            $documentosValidos[] = ['vencimento' => $documento['vencimento'], 'data' => $vencimento, 'valor' => $valor];
        }
    }

    if ($mensagemErro === '' && $documentosValidos) {
        $anos = array_map(static function ($documento) {
            return (int)$documento['data']->format('Y');
        }, $documentosValidos);
        $anos[] = (int)$dataOperacao->format('Y');
        $anoInicial = min($anos);
        $anoFinal = max($anos) + 1;
        garantirFeriadosVariaveisDC($pdo_master, $empresaId, $anoInicial, $anoFinal - $anoInicial);

        $feriadosRecorrentes = feriadosRecorrentesDC($pdo_master, $empresaId);
        $feriadosEspecificos = feriadosEspecificosDC($pdo_master, $empresaId, $anoInicial, $anoFinal);
        $clienteSimulado = ['taxa_desconto' => $taxa, 'usa_adicional_prazo' => 'N'];

        foreach ($documentosValidos as $indice => $documento) {
            $calculo = calcularDocumentoDescontoCheques($documento['valor'], $dataOperacaoInformada, $documento['vencimento'], $clienteSimulado, [], $feriadosRecorrentes, $feriadosEspecificos);
            $resultados[] = ['numero' => $indice + 1, 'valor' => $documento['valor'], 'vencimento' => $documento['vencimento']] + $calculo;
            $totais['quantidade']++;
            $totais['bruto'] += $documento['valor'];
            $totais['desconto'] += $calculo['desconto_valor'];
            $totais['liquido'] += $calculo['valor_liquido'];
        }
    }
}

require '../../layout/header.php';
?>

<style>
    .dc-simulador-form { max-width: 980px; }
    .dc-documento-linha { display: grid; grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) 40px; gap: 12px; align-items: end; }
    .dc-remover { width: 40px; height: 38px; padding: 0; font-size: 1.35rem; line-height: 1; }
    .dc-simulador-resultados { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
    .dc-simulador-resultado { border: 1px solid #dee2e6; border-radius: 6px; padding: 16px; background: #fff; min-width: 0; }
    .dc-simulador-label { color: #6c757d; font-size: .78rem; font-weight: 700; text-transform: uppercase; }
    .dc-simulador-valor { font-size: 1.2rem; font-weight: 700; overflow-wrap: anywhere; }
    .dc-resultado-table th, .dc-resultado-table td { white-space: nowrap; }
    @media (max-width: 767.98px) {
        .dc-documento-linha { grid-template-columns: 1fr 1fr 40px; }
        .dc-simulador-resultados { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 575.98px) {
        .dc-documento-linha { grid-template-columns: 1fr 40px; }
        .dc-documento-valor { grid-column: 1; }
        .dc-remover { grid-column: 2; grid-row: 1 / span 2; align-self: center; }
        .dc-simulador-resultados { grid-template-columns: 1fr; }
    }
</style>

<section class="mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <span class="badge text-bg-info mb-2">Desconto de Cheques</span>
            <h1 class="h3 fw-bold mb-1">Simulador</h1>
            <p class="text-muted mb-0">Previa de uma operacao com um ou varios documentos.</p>
        </div>
        <a href="menu_desconto_cheques.php" class="btn btn-outline-secondary">Voltar</a>
    </div>
</section>

<?php if ($mensagemErro !== ''): ?>
    <div class="alert alert-danger dc-simulador-form"><?= htmlspecialchars($mensagemErro) ?></div>
<?php endif; ?>

<section class="card shadow-sm mb-4 dc-simulador-form">
    <div class="card-header bg-white fw-semibold">Dados da simulacao</div>
    <div class="card-body">
        <form method="get" id="form-simulador">
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label" for="taxa">Taxa mensal (%)</label>
                    <input type="text" class="form-control" id="taxa" name="taxa" inputmode="decimal" required value="<?= htmlspecialchars($taxaInformada) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="data_operacao">Data da operacao</label>
                    <input type="date" class="form-control" id="data_operacao" name="data_operacao" required value="<?= htmlspecialchars($dataOperacaoInformada) ?>">
                </div>
            </div>

            <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                <h2 class="h6 fw-bold mb-0">Documentos</h2>
                <button type="button" class="btn btn-sm btn-outline-primary" id="adicionar-documento">Adicionar documento</button>
            </div>

            <div id="documentos" class="d-grid gap-3">
                <?php foreach ($documentosForm as $documento): ?>
                    <div class="dc-documento-linha border-bottom pb-3">
                        <div>
                            <label class="form-label">Vencimento</label>
                            <input type="date" class="form-control" name="vencimento[]" required value="<?= htmlspecialchars($documento['vencimento']) ?>">
                        </div>
                        <div class="dc-documento-valor">
                            <label class="form-label">Valor do documento</label>
                            <input type="text" class="form-control" name="valor[]" inputmode="decimal" required value="<?= htmlspecialchars($documento['valor']) ?>">
                        </div>
                        <button type="button" class="btn btn-outline-danger dc-remover" title="Remover documento" aria-label="Remover documento">&times;</button>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" name="simular" value="1" class="btn btn-primary">Simular operacao</button>
                <a href="simulador.php" class="btn btn-outline-secondary">Limpar</a>
            </div>
        </form>
    </div>
</section>

<?php if ($resultados): ?>
    <section class="dc-simulador-form">
        <div class="alert alert-info">Data da operacao: <strong><?= dataBRDC($dataOperacaoInformada) ?></strong>. A simulacao nao grava operacao nem lancamento.</div>
        <div class="dc-simulador-resultados mb-4">
            <div class="dc-simulador-resultado"><div class="dc-simulador-label">Documentos</div><div class="dc-simulador-valor"><?= (int)$totais['quantidade'] ?></div></div>
            <div class="dc-simulador-resultado"><div class="dc-simulador-label">Valor bruto total</div><div class="dc-simulador-valor"><?= moedaDC($totais['bruto']) ?></div></div>
            <div class="dc-simulador-resultado"><div class="dc-simulador-label">Desconto total</div><div class="dc-simulador-valor text-danger"><?= moedaDC($totais['desconto']) ?></div></div>
            <div class="dc-simulador-resultado"><div class="dc-simulador-label">Valor liquido total</div><div class="dc-simulador-valor text-success"><?= moedaDC($totais['liquido']) ?></div></div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Detalhamento dos documentos</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 dc-resultado-table">
                    <thead class="table-light"><tr><th>Documento</th><th>Vencimento</th><th>Compensacao</th><th class="text-end">Prazo</th><th class="text-end">Valor</th><th class="text-end">Desconto</th><th class="text-end">Liquido</th></tr></thead>
                    <tbody>
                        <?php foreach ($resultados as $resultado): ?>
                            <tr>
                                <td class="fw-semibold"><?= (int)$resultado['numero'] ?></td>
                                <td><?= dataBRDC($resultado['vencimento']) ?></td>
                                <td><?= dataBRDC($resultado['data_compensacao']) ?></td>
                                <td class="text-end"><?= (int)$resultado['prazo_dias'] ?> dias</td>
                                <td class="text-end"><?= moedaDC($resultado['valor']) ?></td>
                                <td class="text-end text-danger"><?= moedaDC($resultado['desconto_valor']) ?></td>
                                <td class="text-end text-success fw-semibold"><?= moedaDC($resultado['valor_liquido']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>

<template id="modelo-documento">
    <div class="dc-documento-linha border-bottom pb-3">
        <div><label class="form-label">Vencimento</label><input type="date" class="form-control" name="vencimento[]" required></div>
        <div class="dc-documento-valor"><label class="form-label">Valor do documento</label><input type="text" class="form-control" name="valor[]" inputmode="decimal" required></div>
        <button type="button" class="btn btn-outline-danger dc-remover" title="Remover documento" aria-label="Remover documento">&times;</button>
    </div>
</template>

<script>
(function () {
    const documentos = document.getElementById('documentos');
    const modelo = document.getElementById('modelo-documento');
    const adicionar = document.getElementById('adicionar-documento');

    function atualizarRemocao() {
        const linhas = documentos.querySelectorAll('.dc-documento-linha');
        linhas.forEach(function (linha) {
            linha.querySelector('.dc-remover').disabled = linhas.length === 1;
        });
    }

    adicionar.addEventListener('click', function () {
        documentos.appendChild(modelo.content.cloneNode(true));
        atualizarRemocao();
        const linhas = documentos.querySelectorAll('.dc-documento-linha');
        linhas[linhas.length - 1].querySelector('input').focus();
    });

    documentos.addEventListener('click', function (evento) {
        const botao = evento.target.closest('.dc-remover');
        if (!botao || botao.disabled) return;
        botao.closest('.dc-documento-linha').remove();
        atualizarRemocao();
    });

    atualizarRemocao();
}());
</script>

<?php require '../../layout/footer.php'; ?>
