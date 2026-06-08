<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);

function moedaFolha($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function dataFolha($valor): string
{
    if (!$valor || $valor === '0000-00-00' || $valor === '0000-00-00 00:00:00') {
        return '';
    }
    $ts = strtotime((string)$valor);
    return $ts ? date('d/m/Y', $ts) : (string)$valor;
}

function mesPorExtensoFolha(string $referencia): string
{
    $partes = explode('-', $referencia);
    $mes = (int)($partes[1] ?? 0);
    $ano = (int)($partes[0] ?? 0);
    $nomes = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Marco', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
    ];
    return ($nomes[$mes] ?? $referencia) . ' de ' . $ano;
}

function valorBrFolha($valor): float
{
    $texto = trim((string)$valor);
    if ($texto === '') {
        return 0.0;
    }
    $texto = str_replace(['R$', ' '], '', $texto);
    if (strpos($texto, ',') !== false) {
        $texto = str_replace('.', '', $texto);
        $texto = str_replace(',', '.', $texto);
    }
    return (float)$texto;
}

function linhaCompraRodape(array $linhas): string
{
    if (empty($linhas)) {
        return 'Sem lancamentos.';
    }
    $partes = [];
    foreach ($linhas as $linha) {
        $partes[] = dataFolha($linha['DTEMISSAO'] ?? '') . ' ' . moedaFolha($linha['VLRPARCELA'] ?? 0);
    }
    return implode(' | ', $partes);
}

function garantirTabelaParametrosFolha(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS colaboradores_folha_parametros (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            referencia CHAR(7) NOT NULL,
            data_pagamento DATE NOT NULL,
            atualizado_por INT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_empresa_referencia (empresa_id, referencia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function colunaExisteFolha(PDO $pdo, string $tabela, string $coluna): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tabela, $coluna]);
    return (int)$stmt->fetchColumn() > 0;
}

garantirTabelaParametrosFolha($pdo_master);

$referencia = $_REQUEST['referencia'] ?? date('Y-m');
$gerarRecibos = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'gerar');
$salariosInformados = $_POST['salario_liquido'] ?? [];
$premiacoesInformadas = $_POST['premiacao'] ?? [];
$outrosValoresInformados = $_POST['outros_valores'] ?? [];
$errosFolha = [];

if (!preg_match('/^\d{4}-\d{2}$/', $referencia)) {
    $referencia = date('Y-m');
}

$stmtParametro = $pdo_master->prepare("
    SELECT data_pagamento
    FROM colaboradores_folha_parametros
    WHERE empresa_id = ?
      AND referencia = ?
    LIMIT 1
");
$stmtParametro->execute([$empresaId, $referencia]);
$dataPagamentoSalva = (string)($stmtParametro->fetchColumn() ?: '');
$dataPagamento = $_REQUEST['data_pagamento'] ?? ($dataPagamentoSalva !== '' ? $dataPagamentoSalva : date('Y-m-d'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPagamento)) {
    $dataPagamento = date('Y-m-d');
}

$inicioMes = $referencia . '-01';
$fimMes = date('Y-m-t', strtotime($inicioMes));
$referenciaBr = date('m/Y', strtotime($inicioMes));

$stmtFuncionarios = $pdo_master->prepare("
    SELECT
        r.EMPRESA,
        r.FUNCCONTADOR,
        r.NOMEFUNC,
        r.CPF,
        r.CARGO,
        r.DTADMISSAO,
        r.DTDEMISSAO,
        r.CODFORNECEDOR,
        r.DEPARTAMENTO,
        r.SALARIO,
        r.SALARIOREAL
    FROM armazem_REP001 r
    WHERE r.EMPRESA = ?
      AND r.DTADMISSAO <= ?
      AND (
            r.DTDEMISSAO IS NULL
         OR r.DTDEMISSAO = '0000-00-00'
         OR r.DTDEMISSAO = '0000-00-00 00:00:00'
         OR DATE(r.DTDEMISSAO) > ?
      )
      AND COALESCE(r.INATIVO, 'N') NOT IN ('S', '1')
    ORDER BY r.NOMEFUNC
");
$stmtFuncionarios->execute([$empresaId, $fimMes, $fimMes]);
$funcionarios = $stmtFuncionarios->fetchAll(PDO::FETCH_ASSOC);

$recibos = [];

if ($gerarRecibos && empty($errosFolha)) {
    $stmtSalvarParametro = $pdo_master->prepare("
        INSERT INTO colaboradores_folha_parametros (
            empresa_id, referencia, data_pagamento, atualizado_por, atualizado_em
        ) VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            data_pagamento = VALUES(data_pagamento),
            atualizado_por = VALUES(atualizado_por),
            atualizado_em = NOW()
    ");
    $stmtSalvarParametro->execute([
        $empresaId,
        $referencia,
        $dataPagamento,
        (int)($_SESSION['usuario_id'] ?? $_SESSION['user_id'] ?? 0) ?: null,
    ]);

    $stmtVales = $pdo_master->prepare("
        SELECT VALECONTADOR, DATA, DTLANC, MESCOMPETENCIA, EVENTO, HISTORICO, VALOR, DEBCRED
        FROM armazem_FUNC001
        WHERE EMPRESA = ?
          AND FUNCIONARIO = ?
          AND TRIM(MESCOMPETENCIA) = ?
        ORDER BY COALESCE(DATA, DTLANC), VALECONTADOR
    ");

    $stmtComprasAberto = $pdo_master->prepare("
        SELECT CRCONTADOR, DTEMISSAO, DTVENC, DTPAGTO, STATUS, VLRPARCELA, VLRRESTANTE
        FROM armazem_cr001
        WHERE EMPRESA = ?
          AND CLICONTADOR = ?
          AND DATE(DTEMISSAO) <= ?
          AND STATUS <> 'QT'
          AND COALESCE(excluido_firebird, 0) = 0
        ORDER BY DTEMISSAO, CRCONTADOR
    ");

    $stmtComprasPagas = $pdo_master->prepare("
        SELECT CRCONTADOR, DTEMISSAO, DTVENC, DTPAGTO, STATUS, VLRPARCELA, VLRPAGO
        FROM armazem_cr001
        WHERE EMPRESA = ?
          AND CLICONTADOR = ?
          AND TIPODOCORIGEM = 'CRAP'
          AND DATE(DTPAGTO) = ?
          AND COALESCE(excluido_firebird, 0) = 0
        ORDER BY DTEMISSAO, CRCONTADOR
    ");

    $stmtComprasPagasVenda = $pdo_master->prepare("
        SELECT CRCONTADOR, DTEMISSAO, DTVENC, DTPAGTO, STATUS, VLRPARCELA, VLRPAGO
        FROM armazem_cr001
        WHERE EMPRESA = ?
          AND CLICONTADOR = ?
          AND TIPODOCORIGEM = 'VENDA'
          AND DATE(DTPAGTO) = ?
          AND COALESCE(excluido_firebird, 0) = 0
        ORDER BY DTEMISSAO, CRCONTADOR
    ");

    foreach ($funcionarios as $funcionario) {
        $funcId = (int)$funcionario['FUNCCONTADOR'];
        $salarioLiquido = valorBrFolha($salariosInformados[$funcId] ?? 0);
        $premiacao = valorBrFolha($premiacoesInformadas[$funcId] ?? 0);
        $outrosValores = valorBrFolha($outrosValoresInformados[$funcId] ?? 0);
        if (abs($salarioLiquido) < 0.005 && abs($premiacao) < 0.005 && abs($outrosValores) < 0.005) {
            continue;
        }

        $stmtVales->execute([$empresaId, $funcId, $referenciaBr]);
        $vales = $stmtVales->fetchAll(PDO::FETCH_ASSOC);

        $vencimentos = [];
        $vencimentos[] = [
            'codigo' => 'FOLHA',
            'descricao' => 'Salario liquido da folha',
            'referencia' => mesPorExtensoFolha($referencia),
            'valor' => $salarioLiquido,
        ];

        if (abs($premiacao) >= 0.005) {
            $vencimentos[] = [
                'codigo' => 'PREMIO',
                'descricao' => 'Premiacao',
                'referencia' => mesPorExtensoFolha($referencia),
                'valor' => $premiacao,
            ];
        }

        if (abs($outrosValores) >= 0.005) {
            $vencimentos[] = [
                'codigo' => 'OUTROS',
                'descricao' => 'Outros valores',
                'referencia' => mesPorExtensoFolha($referencia),
                'valor' => $outrosValores,
            ];
        }

        $totalVales = array_sum(array_map(static function ($v) {
            $valor = (float)($v['VALOR'] ?? 0);
            return strtoupper((string)($v['DEBCRED'] ?? 'D')) === 'C' ? -$valor : $valor;
        }, $vales));
        $descontosVales = [];
        if (abs($totalVales) >= 0.005) {
            $descontosVales[] = [
                'codigo' => 'VALES',
                'descricao' => 'Soma dos vales FUNC001 do mes',
                'referencia' => count($vales) . ' vale(s)',
                'valor' => $totalVales,
            ];
        }

        $comprasAberto = [];
        $comprasPagas = [];
        $comprasPagasVenda = [];
        if ((int)($funcionario['DEPARTAMENTO'] ?? 0) > 0) {
            $stmtComprasAberto->execute([$empresaId, (int)$funcionario['DEPARTAMENTO'], $fimMes]);
            $comprasAberto = $stmtComprasAberto->fetchAll(PDO::FETCH_ASSOC);

            $stmtComprasPagas->execute([$empresaId, (int)$funcionario['DEPARTAMENTO'], $dataPagamento]);
            $comprasPagas = $stmtComprasPagas->fetchAll(PDO::FETCH_ASSOC);

            $stmtComprasPagasVenda->execute([$empresaId, (int)$funcionario['DEPARTAMENTO'], $dataPagamento]);
            $comprasPagasVenda = $stmtComprasPagasVenda->fetchAll(PDO::FETCH_ASSOC);
        }

        $totalComprasAberto = array_sum(array_map(static fn($c) => (float)($c['VLRRESTANTE'] ?? $c['VLRPARCELA'] ?? 0), $comprasAberto));
        $totalComprasPagas = array_sum(array_map(static fn($c) => (float)($c['VLRPAGO'] ?? $c['VLRPARCELA'] ?? 0), $comprasPagas));

        $descontos = array_merge($descontosVales, [
            [
                'codigo' => 'CR ABERTO',
                'descricao' => 'Total de compras em aberto',
                'referencia' => count($comprasAberto) . ' titulo(s)',
                'valor' => $totalComprasAberto,
            ],
            [
                'codigo' => 'CR PAGO',
                'descricao' => 'Total de compras do mes',
                'referencia' => dataFolha($dataPagamento),
                'valor' => $totalComprasPagas,
            ],
        ]);

        $totalVencimentos = array_sum(array_column($vencimentos, 'valor'));
        $totalDescontos = array_sum(array_column($descontos, 'valor'));

        $recibos[] = [
            'funcionario' => $funcionario,
            'salario_liquido' => $salarioLiquido,
            'premiacao' => $premiacao,
            'outros_valores' => $outrosValores,
            'vales' => $vales,
            'vencimentos' => $vencimentos,
            'descontos' => $descontos,
            'compras_aberto' => $comprasAberto,
            'compras_pagas' => $comprasPagas,
            'compras_pagas_venda' => $comprasPagasVenda,
            'total_vencimentos' => $totalVencimentos,
            'total_descontos' => $totalDescontos,
            'valor_receber' => $totalVencimentos - $totalDescontos,
        ];
    }
}
?>

<style>
    .folha-filter {
        display: grid;
        grid-template-columns: minmax(150px, 180px) minmax(150px, 180px) auto;
        gap: .75rem;
        align-items: end;
    }

    .salary-table {
        min-width: 760px;
    }

    .salary-input {
        max-width: 150px;
        margin-left: auto;
    }

    .recibo-folha {
        border: 1px solid #1f2937;
        background: #fff;
        color: #111827;
        margin-bottom: 1.25rem;
        page-break-inside: avoid;
    }

    .recibo-topo {
        display: grid;
        grid-template-columns: 1.3fr .7fr;
        gap: .75rem;
        padding: .8rem 1rem;
        border-bottom: 1px solid #1f2937;
    }

    .recibo-topo h2 {
        font-size: 1rem;
        font-weight: 800;
        margin: 0 0 .2rem;
    }

    .recibo-meta {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: .4rem;
        padding: .65rem 1rem;
        border-bottom: 1px solid #d1d5db;
        font-size: .85rem;
    }

    .recibo-label {
        color: #4b5563;
        display: block;
        font-size: .74rem;
        text-transform: uppercase;
        font-weight: 700;
    }

    .recibo-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .recibo-col {
        padding: .75rem 1rem;
    }

    .recibo-col + .recibo-col {
        border-left: 1px solid #d1d5db;
    }

    .recibo-col h3 {
        font-size: .88rem;
        text-transform: uppercase;
        font-weight: 800;
        background: #e8eef8;
        padding: .35rem .45rem;
        margin: 0 0 .55rem;
    }

    .recibo-linha {
        display: grid;
        grid-template-columns: 64px 1fr 82px 112px;
        gap: .4rem;
        padding: .28rem 0;
        border-bottom: 1px dotted #d1d5db;
        font-size: .82rem;
    }

    .recibo-linha.cab {
        font-weight: 800;
        color: #374151;
        border-bottom: 1px solid #9ca3af;
    }

    .recibo-total {
        display: grid;
        grid-template-columns: 1fr 150px;
        gap: .75rem;
        padding: .55rem 1rem;
        border-top: 1px solid #1f2937;
        font-weight: 800;
    }

    .recibo-rodape {
        padding: .65rem 1rem 1rem;
        font-size: .76rem;
        border-top: 1px solid #d1d5db;
    }

    .recibo-assinatura {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        padding: 1.1rem 1rem .9rem;
        font-size: .82rem;
    }

    .linha-assinatura {
        border-top: 1px solid #111827;
        text-align: center;
        padding-top: .25rem;
    }

    @media (max-width: 767.98px) {
        .folha-filter,
        .recibo-topo,
        .recibo-meta,
        .recibo-grid,
        .recibo-assinatura {
            grid-template-columns: 1fr;
        }

        .recibo-col + .recibo-col {
            border-left: 0;
            border-top: 1px solid #d1d5db;
        }

        .recibo-linha {
            grid-template-columns: 52px 1fr 70px 90px;
            font-size: .76rem;
        }
    }

    @media print {
        header, nav, .no-print, .btn, form {
            display: none !important;
        }

        body {
            background: #fff !important;
        }

        .container, .container-fluid {
            max-width: none !important;
            width: 100% !important;
        }

        .recibo-folha {
            margin: 0 0 .7rem;
        }
    }
</style>

<section class="mb-4 no-print">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-success mb-3">Colaboradores</span>
                <h1 class="h3 fw-bold mb-2">Folha de Pagamento</h1>
                <p class="text-muted mb-0">Monte os recibos da folha a partir da REP001, FUNC001, CP001 e CR001.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_colaboradores.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<section class="card shadow-sm mb-4 no-print">
    <div class="card-body">
        <form method="get" class="folha-filter">
            <div>
                <label class="form-label">Referencia da folha</label>
                <input type="month" name="referencia" value="<?= htmlspecialchars($referencia) ?>" class="form-control">
            </div>
            <div>
                <label class="form-label">Data do pagamento</label>
                <input type="date" name="data_pagamento" value="<?= htmlspecialchars($dataPagamento) ?>" class="form-control">
            </div>
            <button type="submit" class="btn btn-primary">Carregar funcionarios</button>
        </form>
    </div>
</section>

<form method="post" class="no-print">
    <input type="hidden" name="acao" value="gerar">
    <input type="hidden" name="referencia" value="<?= htmlspecialchars($referencia) ?>">
    <input type="hidden" name="data_pagamento" value="<?= htmlspecialchars($dataPagamento) ?>">

    <section class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center gap-3">
            <div>
                <h2 class="h6 fw-bold mb-0">Salario liquido informado</h2>
                <small class="text-muted">
                    Funcionarios admitidos ate <?= dataFolha($fimMes) ?>. Informe o valor liquido da folha para gerar os recibos.
                </small>
            </div>
            <button type="submit" class="btn btn-success">Gerar recibos</button>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($errosFolha)): ?>
                <div class="alert alert-warning m-3 mb-0"><?= htmlspecialchars($errosFolha[0]) ?></div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 salary-table">
                    <thead class="table-dark">
                        <tr>
                            <th>Codigo</th>
                            <th>Funcionario</th>
                            <th>Admissao</th>
                            <th>Cargo</th>
                            <th class="text-end">Salario liquido da folha</th>
                            <th class="text-end">Valor da premiacao</th>
                            <th class="text-end">Outros valores</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($funcionarios)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">Nenhum funcionario encontrado para esta referencia.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($funcionarios as $funcionario): ?>
                            <?php
                                $funcId = (int)$funcionario['FUNCCONTADOR'];
                                $valorAtual = $salariosInformados[$funcId] ?? '';
                                $premiacaoAtual = $premiacoesInformadas[$funcId] ?? '';
                                $outrosValoresAtual = $outrosValoresInformados[$funcId] ?? '';
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= $funcId ?></td>
                                <td><?= htmlspecialchars((string)$funcionario['NOMEFUNC']) ?></td>
                                <td><?= dataFolha($funcionario['DTADMISSAO'] ?? '') ?></td>
                                <td><?= htmlspecialchars((string)($funcionario['CARGO'] ?? '')) ?></td>
                                <td>
                                    <input
                                        type="text"
                                        name="salario_liquido[<?= $funcId ?>]"
                                        value="<?= htmlspecialchars((string)$valorAtual) ?>"
                                        class="form-control form-control-sm text-end salary-input"
                                        placeholder="0,00"
                                        inputmode="decimal"
                                    >
                                </td>
                                <td>
                                    <input
                                        type="text"
                                        name="premiacao[<?= $funcId ?>]"
                                        value="<?= htmlspecialchars((string)$premiacaoAtual) ?>"
                                        class="form-control form-control-sm text-end salary-input"
                                        placeholder="0,00"
                                        inputmode="decimal"
                                    >
                                </td>
                                <td>
                                    <input
                                        type="text"
                                        name="outros_valores[<?= $funcId ?>]"
                                        value="<?= htmlspecialchars((string)$outrosValoresAtual) ?>"
                                        class="form-control form-control-sm text-end salary-input"
                                        placeholder="0,00"
                                        inputmode="decimal"
                                    >
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</form>

<?php if ($gerarRecibos): ?>
    <section class="d-flex justify-content-between align-items-center gap-3 mb-3 no-print">
        <div class="fw-semibold"><?= count($recibos) ?> recibo(s) gerado(s).</div>
        <button type="button" class="btn btn-outline-primary" onclick="window.print()">Imprimir / Salvar PDF</button>
    </section>

    <?php foreach ($recibos as $recibo): ?>
        <?php $funcionario = $recibo['funcionario']; ?>
        <article class="recibo-folha">
            <div class="recibo-topo">
                <div>
                    <h2>COMERCIAL GOMES SILVEIRA LTDA</h2>
                    <div>CNPJ: 12.020.040/0001-84</div>
                </div>
                <div class="text-md-end">
                    <strong>Recibo de Pagamento</strong><br>
                    Folha Mensal<br>
                    <?= htmlspecialchars(mesPorExtensoFolha($referencia)) ?>
                </div>
            </div>

            <div class="recibo-meta">
                <div><span class="recibo-label">Codigo</span><?= (int)$funcionario['FUNCCONTADOR'] ?></div>
                <div><span class="recibo-label">Funcionario</span><?= htmlspecialchars((string)$funcionario['NOMEFUNC']) ?></div>
                <div><span class="recibo-label">Admissao</span><?= dataFolha($funcionario['DTADMISSAO'] ?? '') ?></div>
                <div><span class="recibo-label">Pagamento</span><?= dataFolha($dataPagamento) ?></div>
                <div><span class="recibo-label">Cargo</span><?= htmlspecialchars((string)($funcionario['CARGO'] ?? '')) ?></div>
                <div><span class="recibo-label">Fornecedor CP</span><?= htmlspecialchars((string)($funcionario['CODFORNECEDOR'] ?? '')) ?></div>
                <div><span class="recibo-label">Cliente CR</span><?= htmlspecialchars((string)($funcionario['DEPARTAMENTO'] ?? '')) ?></div>
                <div><span class="recibo-label">Salario informado</span><?= moedaFolha($recibo['salario_liquido']) ?></div>
            </div>

            <div class="recibo-grid">
                <div class="recibo-col">
                    <h3>Vencimentos</h3>
                    <div class="recibo-linha cab">
                        <div>Codigo</div>
                        <div>Descricao</div>
                        <div>Ref.</div>
                        <div class="text-end">Valor</div>
                    </div>
                    <?php foreach ($recibo['vencimentos'] as $linha): ?>
                        <div class="recibo-linha">
                            <div><?= htmlspecialchars((string)$linha['codigo']) ?></div>
                            <div><?= htmlspecialchars((string)$linha['descricao']) ?></div>
                            <div><?= htmlspecialchars((string)$linha['referencia']) ?></div>
                            <div class="text-end"><?= moedaFolha($linha['valor']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="recibo-col">
                    <h3>Descontos</h3>
                    <div class="recibo-linha cab">
                        <div>Codigo</div>
                        <div>Descricao</div>
                        <div>Ref.</div>
                        <div class="text-end">Valor</div>
                    </div>
                    <?php foreach ($recibo['descontos'] as $linha): ?>
                        <div class="recibo-linha">
                            <div><?= htmlspecialchars((string)$linha['codigo']) ?></div>
                            <div><?= htmlspecialchars((string)$linha['descricao']) ?></div>
                            <div><?= htmlspecialchars((string)$linha['referencia']) ?></div>
                            <div class="text-end"><?= moedaFolha($linha['valor']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="recibo-total">
                <div>Total de Vencimentos</div>
                <div class="text-end"><?= moedaFolha($recibo['total_vencimentos']) ?></div>
                <div>Total de Descontos</div>
                <div class="text-end"><?= moedaFolha($recibo['total_descontos']) ?></div>
                <div>Valor a Receber</div>
                <div class="text-end"><?= moedaFolha($recibo['valor_receber']) ?></div>
            </div>

            <div class="recibo-rodape">
                <strong>Compras em aberto:</strong> <?= htmlspecialchars(linhaCompraRodape($recibo['compras_aberto'])) ?><br>
                <strong>Compras pagas na data do pagamento:</strong> <?= htmlspecialchars(linhaCompraRodape($recibo['compras_pagas_venda'])) ?><br>
                <strong>Vales FUNC001 da referencia:</strong>
                <?php if (empty($recibo['vales'])): ?>
                    Sem lancamentos.
                <?php else: ?>
                    <?php
                        $valesRodape = [];
                        foreach ($recibo['vales'] as $vale) {
                            $dcVale = strtoupper((string)($vale['DEBCRED'] ?? 'D'));
                            $sinalVale = $dcVale === 'C' ? '-' : '';
                            $valesRodape[] = '#' . (int)$vale['VALECONTADOR'] . ' ' . dataFolha($vale['DATA'] ?? $vale['DTLANC'] ?? '') . ' ' . $sinalVale . moedaFolha($vale['VALOR'] ?? 0);
                        }
                        echo htmlspecialchars(implode(' | ', $valesRodape));
                    ?>
                <?php endif; ?>
            </div>

            <div class="recibo-assinatura">
                <div class="linha-assinatura">Data</div>
                <div class="linha-assinatura">Assinatura do Funcionario</div>
            </div>
        </article>
    <?php endforeach; ?>
<?php endif; ?>

<?php require '../../layout/footer.php'; ?>
