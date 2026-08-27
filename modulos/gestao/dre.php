<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';

$empresaSessao = (int)($_SESSION['empresa_id'] ?? 0);
$nivelUsuario = $_SESSION['nivel'] ?? '';
$empresaFiltro = (int)($_GET['empresa_id'] ?? $empresaSessao);
$mesFiltro = trim($_GET['mes'] ?? date('Y-m'));

if (!preg_match('/^\d{4}-\d{2}$/', $mesFiltro)) {
    $mesFiltro = date('Y-m');
}

if ($nivelUsuario !== 'MASTER') {
    $empresaFiltro = $empresaSessao;
}

$inicioPeriodo = $mesFiltro . '-01';
$fimPeriodo = date('Y-m-t', strtotime($inicioPeriodo . ' +1 month'));
$fimMesBase = date('Y-m-t', strtotime($inicioPeriodo));
$mesAtualLabel = date('m/Y', strtotime($inicioPeriodo));
$mesSeguinteLabel = date('m/Y', strtotime($inicioPeriodo . ' +1 month'));
$limiteVencimentoLabel = dataGestao($fimPeriodo);

function moedaGestao($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function dataGestao($valor): string
{
    return $valor ? date('d/m/Y', strtotime($valor)) : '';
}

function garantirCamposDre(PDO $pdo): void
{
    $campos = [
        'armazem_cr001' => [
            'financeiro_verificado' => "ALTER TABLE armazem_cr001 ADD financeiro_verificado CHAR(1) NOT NULL DEFAULT 'N'",
            'financeiro_verificado_por' => "ALTER TABLE armazem_cr001 ADD financeiro_verificado_por INT NULL",
            'financeiro_verificado_em' => "ALTER TABLE armazem_cr001 ADD financeiro_verificado_em DATETIME NULL",
        ],
        'armazem_cp001' => [
            'financeiro_verificado' => "ALTER TABLE armazem_cp001 ADD financeiro_verificado CHAR(1) NOT NULL DEFAULT 'N'",
            'financeiro_verificado_por' => "ALTER TABLE armazem_cp001 ADD financeiro_verificado_por INT NULL",
            'financeiro_verificado_em' => "ALTER TABLE armazem_cp001 ADD financeiro_verificado_em DATETIME NULL",
        ],
    ];

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");

    foreach ($campos as $tabela => $lista) {
        foreach ($lista as $campo => $sql) {
            $stmt->execute([$tabela, $campo]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec($sql);
            }
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_contas_saldos (
            empresa_id INT NOT NULL,
            cbcontador INT NOT NULL,
            data_saldo DATE NOT NULL,
            valor_saldo DECIMAL(15,4) NOT NULL DEFAULT 0,
            atualizado_por INT NULL,
            atualizado_em DATETIME NULL,
            PRIMARY KEY (empresa_id, cbcontador),
            INDEX idx_fin_contas_saldos_data (empresa_id, data_saldo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

garantirCamposDre($pdo_master);

$stmtEmpresas = $pdo_master->query("
    SELECT id, nome_fantasia
    FROM empresas
    WHERE status = 'ATIVA'
    ORDER BY nome_fantasia
");
$empresas = $stmtEmpresas->fetchAll(PDO::FETCH_ASSOC);

if ($empresaFiltro <= 0) {
    $empresaFiltro = $empresaSessao;
}

$empresaNome = 'Todas as empresas';
if ($empresaFiltro > 0) {
    $stmtEmpresa = $pdo_master->prepare("SELECT nome_fantasia FROM empresas WHERE id = ? LIMIT 1");
    $stmtEmpresa->execute([$empresaFiltro]);
    $empresaNome = $stmtEmpresa->fetchColumn() ?: 'Empresa ' . $empresaFiltro;
}

$filtroEmpresaSql = $empresaFiltro > 0 ? '= ?' : '> 0';
$paramEmpresa = $empresaFiltro > 0 ? [$empresaFiltro] : [];

$stmtReceberResumo = $pdo_master->prepare("
    SELECT
        COUNT(*) AS qtd,
        COALESCE(SUM(c.VLRRESTANTE), 0) AS total_restante
    FROM armazem_cr001 c
    WHERE c.EMPRESA {$filtroEmpresaSql}
      AND (c.STATUS IS NULL OR c.STATUS <> 'QT')
      AND COALESCE(c.excluido_firebird, 'N') <> 'S'
      AND COALESCE(c.financeiro_verificado, 'N') <> 'S'
      AND DATE(c.DTVENC) <= ?
");
$stmtReceberResumo->execute(array_merge($paramEmpresa, [$fimPeriodo]));
$receberResumo = $stmtReceberResumo->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total_restante' => 0];

$stmtReceber = $pdo_master->prepare("
    SELECT
        c.CMCONTADOR,
        COUNT(*) AS qtd,
        COALESCE(SUM(c.VLRPARCELA), 0) AS total_parcela,
        COALESCE(SUM(c.VLRRESTANTE), 0) AS total_restante
    FROM armazem_cr001 c
    WHERE c.EMPRESA {$filtroEmpresaSql}
      AND (c.STATUS IS NULL OR c.STATUS <> 'QT')
      AND COALESCE(c.excluido_firebird, 'N') <> 'S'
      AND COALESCE(c.financeiro_verificado, 'N') <> 'S'
      AND DATE(c.DTVENC) <= ?
    GROUP BY c.CMCONTADOR
    ORDER BY c.CMCONTADOR
");
$stmtReceber->execute(array_merge($paramEmpresa, [$fimPeriodo]));
$receber = $stmtReceber->fetchAll(PDO::FETCH_ASSOC);

$stmtPagarResumo = $pdo_master->prepare("
    SELECT
        COUNT(*) AS qtd,
        COALESCE(SUM(cp.VLRRESTANTE), 0) AS total_restante
    FROM armazem_cp001 cp
    WHERE cp.EMPRESA {$filtroEmpresaSql}
      AND (cp.STATUS IS NULL OR cp.STATUS <> 'QT')
      AND COALESCE(cp.excluido_firebird, 'N') <> 'S'
      AND COALESCE(cp.financeiro_verificado, 'N') <> 'S'
      AND DATE(cp.DTVENC) <= ?
");
$stmtPagarResumo->execute(array_merge($paramEmpresa, [$fimPeriodo]));
$pagarResumo = $stmtPagarResumo->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total_restante' => 0];

$stmtPagar = $pdo_master->prepare("
    SELECT
        cp.TIPOES,
        COALESCE(NULLIF(t.DESCES, ''), 'Sem descricao') AS tipoes_descricao,
        COUNT(*) AS qtd,
        COALESCE(SUM(cp.VLRPARCELA), 0) AS total_parcela,
        COALESCE(SUM(cp.VLRRESTANTE), 0) AS total_restante
    FROM armazem_cp001 cp
    LEFT JOIN armazem_bnc005 t
        ON t.EMPRESA = cp.EMPRESA
       AND t.ESCONTADOR = cp.TIPOES
    WHERE cp.EMPRESA {$filtroEmpresaSql}
      AND (cp.STATUS IS NULL OR cp.STATUS <> 'QT')
      AND COALESCE(cp.excluido_firebird, 'N') <> 'S'
      AND COALESCE(cp.financeiro_verificado, 'N') <> 'S'
      AND DATE(cp.DTVENC) <= ?
    GROUP BY cp.TIPOES, t.DESCES
    ORDER BY cp.TIPOES
");
$stmtPagar->execute(array_merge($paramEmpresa, [$fimPeriodo]));
$pagar = $stmtPagar->fetchAll(PDO::FETCH_ASSOC);

$stmtMovimentacaoTipoes = $pdo_master->prepare("
    SELECT
        COALESCE(t.GRUPOBNC, 0) AS GRUPOBNC,
        COALESCE(b.TIPOES, 0) AS TIPOES,
        COALESCE(NULLIF(t.DESCES, ''), 'Sem TIPOES') AS tipoes_descricao,
        COUNT(*) AS qtd,
        COALESCE(SUM(CASE WHEN b.TIPOMOV = 'C' THEN ABS(b.VALORMOV) ELSE 0 END), 0) AS creditos,
        COALESCE(SUM(CASE WHEN b.TIPOMOV = 'D' THEN ABS(b.VALORMOV) ELSE 0 END), 0) AS debitos,
        COALESCE(SUM(CASE
            WHEN b.TIPOMOV = 'C' THEN ABS(b.VALORMOV)
            WHEN b.TIPOMOV = 'D' THEN -ABS(b.VALORMOV)
            ELSE 0
        END), 0) AS saldo
    FROM armazem_bnc001 b
    LEFT JOIN armazem_bnc005 t
        ON t.EMPRESA = b.EMPRESA
       AND t.ESCONTADOR = b.TIPOES
    WHERE b.EMPRESA {$filtroEmpresaSql}
      AND COALESCE(b.deletado, 'N') <> 'S'
      AND DATE(b.DTMOV) BETWEEN ? AND ?
    GROUP BY COALESCE(t.GRUPOBNC, 0), COALESCE(b.TIPOES, 0), t.DESCES, t.ORDEMTIPO
    ORDER BY COALESCE(t.GRUPOBNC, 999999), COALESCE(t.ORDEMTIPO, 999999), COALESCE(b.TIPOES, 0)
");
$stmtMovimentacaoTipoes->execute(array_merge($paramEmpresa, [$inicioPeriodo, $fimMesBase]));
$movimentacaoTipoes = $stmtMovimentacaoTipoes->fetchAll(PDO::FETCH_ASSOC);
$saldoMovimentacaoTipoes = array_sum(array_column($movimentacaoTipoes, 'saldo'));
$subtotaisGrupoBnc = [];
foreach ($movimentacaoTipoes as $linha) {
    $grupoBnc = (int)$linha['GRUPOBNC'];
    if (!isset($subtotaisGrupoBnc[$grupoBnc])) {
        $subtotaisGrupoBnc[$grupoBnc] = [
            'qtd' => 0,
            'creditos' => 0.0,
            'debitos' => 0.0,
            'saldo' => 0.0,
        ];
    }
    $subtotaisGrupoBnc[$grupoBnc]['qtd'] += (int)$linha['qtd'];
    $subtotaisGrupoBnc[$grupoBnc]['creditos'] += (float)$linha['creditos'];
    $subtotaisGrupoBnc[$grupoBnc]['debitos'] += (float)$linha['debitos'];
    $subtotaisGrupoBnc[$grupoBnc]['saldo'] += (float)$linha['saldo'];
}

$stmtContas = $pdo_master->prepare("
    SELECT
        COALESCE(NULLIF(TRIM(c.CLASSIFICACAO), ''), 'Sem classificacao') AS CLASSIFICACAO,
        c.CBCONTADOR,
        c.EMPRESA,
        emp.nome_fantasia AS empresa_nome,
        TRIM(COALESCE(NULLIF(c.TITULAR, ''), NULLIF(c.DESCABREV, ''), CONCAT('Conta ', c.CBCONTADOR))) AS nome_conta,
        CASE
            WHEN s.data_saldo IS NULL OR s.data_saldo < ? THEN
                COALESCE(s.valor_saldo, 0) + COALESCE(SUM(CASE
                    WHEN DATE(b.DTMOV) > COALESCE(s.data_saldo, '1900-01-01')
                     AND DATE(b.DTMOV) < ?
                    THEN CASE WHEN b.TIPOMOV = 'C' THEN ABS(b.VALORMOV) ELSE -ABS(b.VALORMOV) END
                    ELSE 0
                END), 0)
            ELSE
                COALESCE(s.valor_saldo, 0) - COALESCE(SUM(CASE
                    WHEN DATE(b.DTMOV) >= ?
                     AND DATE(b.DTMOV) <= s.data_saldo
                    THEN CASE WHEN b.TIPOMOV = 'C' THEN ABS(b.VALORMOV) ELSE -ABS(b.VALORMOV) END
                    ELSE 0
                END), 0)
        END AS saldo_anterior,
        COALESCE(SUM(CASE
            WHEN b.TIPOMOV = 'D' AND DATE(b.DTMOV) BETWEEN ? AND ? THEN ABS(b.VALORMOV)
            ELSE 0
        END), 0) AS debitos,
        COALESCE(SUM(CASE
            WHEN b.TIPOMOV = 'C' AND DATE(b.DTMOV) BETWEEN ? AND ? THEN ABS(b.VALORMOV)
            ELSE 0
        END), 0) AS creditos
    FROM armazem_bnc002 c
    LEFT JOIN empresas emp
        ON emp.id = c.EMPRESA
    LEFT JOIN financeiro_contas_saldos s
        ON s.empresa_id = c.EMPRESA
       AND s.cbcontador = c.CBCONTADOR
    LEFT JOIN armazem_bnc001 b
        ON b.EMPRESA = c.EMPRESA
       AND b.CBCONTADOR = c.CBCONTADOR
       AND COALESCE(b.deletado, 'N') <> 'S'
    WHERE c.EMPRESA {$filtroEmpresaSql}
      AND COALESCE(c.excluido_firebird, 'N') <> 'S'
      AND COALESCE(c.CONTABLOQUEADA, 'N') <> 'S'
    GROUP BY
        c.CLASSIFICACAO,
        c.CBCONTADOR,
        c.EMPRESA,
        emp.nome_fantasia,
        c.TITULAR,
        c.DESCABREV,
        s.valor_saldo,
        s.data_saldo
    ORDER BY
        CASE WHEN NULLIF(TRIM(c.CLASSIFICACAO), '') IS NULL THEN 1 ELSE 0 END,
        c.CLASSIFICACAO ASC,
        nome_conta ASC,
        c.CBCONTADOR ASC
");
$stmtContas->execute(array_merge([
    $inicioPeriodo,
    $inicioPeriodo,
    $inicioPeriodo,
    $inicioPeriodo,
    $fimMesBase,
    $inicioPeriodo,
    $fimMesBase,
], $paramEmpresa));
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

$saldoContasTotal = 0.0;
$saldoMesContasTotal = 0.0;
$subtotaisClassificacao = [];
foreach ($contas as &$conta) {
    $conta['saldo_mes'] = (float)$conta['creditos'] - (float)$conta['debitos'];
    $conta['saldo'] = (float)$conta['saldo_anterior'] + (float)$conta['saldo_mes'];
    $saldoMesContasTotal += (float)$conta['saldo_mes'];
    $saldoContasTotal += (float)$conta['saldo'];
    $classificacao = (string)$conta['CLASSIFICACAO'];
    if (!isset($subtotaisClassificacao[$classificacao])) {
        $subtotaisClassificacao[$classificacao] = [
            'saldo_anterior' => 0.0,
            'debitos' => 0.0,
            'creditos' => 0.0,
            'saldo_mes' => 0.0,
            'saldo' => 0.0,
        ];
    }
    $subtotaisClassificacao[$classificacao]['saldo_anterior'] += (float)$conta['saldo_anterior'];
    $subtotaisClassificacao[$classificacao]['debitos'] += (float)$conta['debitos'];
    $subtotaisClassificacao[$classificacao]['creditos'] += (float)$conta['creditos'];
    $subtotaisClassificacao[$classificacao]['saldo_mes'] += (float)$conta['saldo_mes'];
    $subtotaisClassificacao[$classificacao]['saldo'] += (float)$conta['saldo'];
}
unset($conta);

$stmtEstoque = $pdo_master->prepare("
    SELECT
        COALESCE(SUM(valor_estoque), 0) AS valor_estoque
    FROM (
        SELECT
            p.CONTAPRODUTO,
            ((COALESCE(p.ESTINICIAL, 0) + COALESCE(e.qtd_entrada, 0) - COALESCE(s.qtd_saida, 0)) * COALESCE(p.PRECOFINAL, 0)) AS valor_estoque
        FROM armazem_est004 p
        LEFT JOIN (
            SELECT i.EMPRESA, i.PRODUTO, SUM(COALESCE(i.QTDE, 0)) AS qtd_entrada
            FROM armazem_est006 i
            LEFT JOIN armazem_est005 c
                ON c.EMPRESA = i.EMPRESA
               AND c.COMPRACONTADOR = i.COMPRACONTA
            WHERE i.EMPRESA {$filtroEmpresaSql}
              AND COALESCE(i.excluido_firebird, 'N') <> 'S'
              AND COALESCE(i.CANCELADO, 'N') <> 'S'
              AND COALESCE(i.MOVESTOQUE, 'S') <> 'N'
              AND COALESCE(c.excluido_firebird, 'N') <> 'S'
              AND COALESCE(c.CANCELADO, 'N') <> 'S'
              AND COALESCE(c.BAIXAESTOQUE, 'S') <> 'N'
            GROUP BY i.EMPRESA, i.PRODUTO
        ) e ON e.EMPRESA = p.EMPRESA
           AND e.PRODUTO = p.CONTAPRODUTO
        LEFT JOIN (
            SELECT i.EMPRESA, i.PRODUTO, SUM(COALESCE(i.QTDE, 0)) AS qtd_saida
            FROM armazem_est008 i
            WHERE i.EMPRESA {$filtroEmpresaSql}
              AND COALESCE(i.CANCELADO, 'N') <> 'S'
              AND i.MOVESTOQUE = 'S'
            GROUP BY i.EMPRESA, i.PRODUTO
        ) s ON s.EMPRESA = p.EMPRESA
           AND s.PRODUTO = p.CONTAPRODUTO
        WHERE p.EMPRESA {$filtroEmpresaSql}
          AND COALESCE(p.excluido_firebird, 'N') <> 'S'
          AND COALESCE(p.INATIVO, 'N') <> 'S'
    ) estoque
");
$stmtEstoque->execute(array_merge($paramEmpresa, $paramEmpresa, $paramEmpresa));
$valorEstoque = (float)$stmtEstoque->fetchColumn();

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Gestão</span>
                <h1 class="h3 fw-bold mb-2">DRE</h1>
                <p class="text-muted mb-0">
                    Visao gerencial de <?= htmlspecialchars($empresaNome) ?> com titulos abertos vencendo ate <?= htmlspecialchars($limiteVencimentoLabel) ?>.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_gestao.php" class="btn btn-outline-secondary">Voltar a gestão</a>
            </div>
        </div>
    </div>
</section>

<section class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Empresa</label>
                <select name="empresa_id" class="form-select" <?= $nivelUsuario === 'MASTER' ? '' : 'disabled' ?>>
                    <?php if ($nivelUsuario === 'MASTER'): ?>
                        <option value="0" <?= $empresaFiltro === 0 ? 'selected' : '' ?>>Todas as empresas</option>
                    <?php endif; ?>
                    <?php foreach ($empresas as $empresa): ?>
                        <?php if ($nivelUsuario !== 'MASTER' && (int)$empresa['id'] !== $empresaSessao) { continue; } ?>
                        <option value="<?= (int)$empresa['id'] ?>" <?= (int)$empresa['id'] === $empresaFiltro ? 'selected' : '' ?>>
                            <?= htmlspecialchars($empresa['nome_fantasia']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($nivelUsuario !== 'MASTER'): ?>
                    <input type="hidden" name="empresa_id" value="<?= (int)$empresaFiltro ?>">
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label">Mês base</label>
                <input type="month" name="mes" class="form-control" value="<?= htmlspecialchars($mesFiltro) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Filtrar</button>
            </div>
        </form>
    </div>
</section>

<section class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <div class="small text-muted">Contas a receber nao verificado</div>
                <div class="h5 fw-bold text-success mb-1"><?= moedaGestao($receberResumo['total_restante']) ?></div>
                <div class="small text-muted"><?= (int)$receberResumo['qtd'] ?> titulo(s)</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <div class="small text-muted">Contas a pagar nao verificado</div>
                <div class="h5 fw-bold text-danger mb-1"><?= moedaGestao($pagarResumo['total_restante']) ?></div>
                <div class="small text-muted"><?= (int)$pagarResumo['qtd'] ?> titulo(s)</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <div class="small text-muted">Saldo das contas</div>
                <div class="h5 fw-bold <?= $saldoContasTotal < 0 ? 'text-danger' : 'text-success' ?> mb-1"><?= moedaGestao($saldoContasTotal) ?></div>
                <div class="small text-muted">Saldo atual das contas</div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <div class="small text-muted">Valor em estoque a custo</div>
                <div class="h5 fw-bold text-primary mb-1"><?= moedaGestao($valorEstoque) ?></div>
                <div class="small text-muted">Qtd saldo x PRECOFINAL</div>
            </div>
        </div>
    </div>
</section>

<section class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 class="h5 mb-0">Movimentacao por TIPOES</h2>
            <small class="text-muted">Movimentos entre <?= dataGestao($inicioPeriodo) ?> e <?= dataGestao($fimMesBase) ?>.</small>
        </div>
        <div class="text-end">
            <div class="small text-muted">Saldo</div>
            <div class="fw-bold <?= $saldoMovimentacaoTipoes < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaGestao($saldoMovimentacaoTipoes) ?></div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
                <tr>
                    <th>GRUPOBNC</th>
                    <th>TIPOES</th>
                    <th>Descricao</th>
                    <th class="text-end">Movimentos</th>
                    <th class="text-end">Creditos</th>
                    <th class="text-end">Debitos</th>
                    <th class="text-end">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movimentacaoTipoes as $indice => $linha): ?>
                    <?php $grupoBnc = (int)$linha['GRUPOBNC']; ?>
                    <tr>
                        <td><?= $grupoBnc > 0 ? $grupoBnc : '-' ?></td>
                        <td class="fw-semibold"><?= (int)$linha['TIPOES'] ?></td>
                        <td><?= htmlspecialchars($linha['tipoes_descricao']) ?></td>
                        <td class="text-end"><?= (int)$linha['qtd'] ?></td>
                        <td class="text-end text-success"><?= moedaGestao($linha['creditos']) ?></td>
                        <td class="text-end text-danger"><?= moedaGestao($linha['debitos']) ?></td>
                        <td class="text-end fw-semibold <?= (float)$linha['saldo'] < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaGestao($linha['saldo']) ?></td>
                    </tr>
                    <?php
                    $proximoGrupoBnc = isset($movimentacaoTipoes[$indice + 1])
                        ? (int)$movimentacaoTipoes[$indice + 1]['GRUPOBNC']
                        : null;
                    if ($proximoGrupoBnc !== $grupoBnc):
                        $subtotalGrupo = $subtotaisGrupoBnc[$grupoBnc];
                    ?>
                        <tr class="table-secondary fw-bold">
                            <td colspan="3">Subtotal GRUPOBNC <?= $grupoBnc > 0 ? $grupoBnc : 'sem grupo' ?></td>
                            <td class="text-end"><?= (int)$subtotalGrupo['qtd'] ?></td>
                            <td class="text-end text-success"><?= moedaGestao($subtotalGrupo['creditos']) ?></td>
                            <td class="text-end text-danger"><?= moedaGestao($subtotalGrupo['debitos']) ?></td>
                            <td class="text-end <?= (float)$subtotalGrupo['saldo'] < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaGestao($subtotalGrupo['saldo']) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (empty($movimentacaoTipoes)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma movimentacao encontrada no mes-base.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 class="h5 mb-0">Contas a Receber por CM</h2>
            <small class="text-muted">Resumo dos titulos em aberto com vencimento ate <?= dataGestao($fimPeriodo) ?>, verificado = nao.</small>
        </div>
        <div class="text-end">
            <div class="small text-muted">Total em aberto</div>
            <div class="fw-bold text-success"><?= moedaGestao($receberResumo['total_restante']) ?></div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
                <tr>
                    <th>CM</th>
                    <th class="text-end">Titulos</th>
                    <th class="text-end">Total das parcelas</th>
                    <th class="text-end">Saldo aberto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($receber as $linha): ?>
                    <tr>
                        <td class="fw-semibold"><?= (int)$linha['CMCONTADOR'] ?></td>
                        <td class="text-end"><?= (int)$linha['qtd'] ?></td>
                        <td class="text-end"><?= moedaGestao($linha['total_parcela']) ?></td>
                        <td class="text-end fw-semibold"><?= moedaGestao($linha['total_restante']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($receber)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Nenhum contas a receber pendente no periodo.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 class="h5 mb-0">Contas a Pagar por TIPOES</h2>
            <small class="text-muted">Resumo dos titulos em aberto com vencimento ate <?= dataGestao($fimPeriodo) ?>, verificado = nao.</small>
        </div>
        <div class="text-end">
            <div class="small text-muted">Total em aberto</div>
            <div class="fw-bold text-danger"><?= moedaGestao($pagarResumo['total_restante']) ?></div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
                <tr>
                    <th>TIPOES</th>
                    <th>Descricao</th>
                    <th class="text-end">Titulos</th>
                    <th class="text-end">Total das parcelas</th>
                    <th class="text-end">Saldo aberto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pagar as $linha): ?>
                    <tr>
                        <td class="fw-semibold"><?= (int)$linha['TIPOES'] ?></td>
                        <td><?= htmlspecialchars($linha['tipoes_descricao']) ?></td>
                        <td class="text-end"><?= (int)$linha['qtd'] ?></td>
                        <td class="text-end"><?= moedaGestao($linha['total_parcela']) ?></td>
                        <td class="text-end fw-semibold"><?= moedaGestao($linha['total_restante']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($pagar)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhum contas a pagar pendente no periodo.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 class="h5 mb-0">Saldo das Contas</h2>
            <small class="text-muted">Contas nao bloqueadas com saldo atual calculado.</small>
        </div>
        <div class="d-flex gap-4 text-end">
            <div>
                <div class="small text-muted">Saldo do mes</div>
                <div class="fw-bold <?= $saldoMesContasTotal < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaGestao($saldoMesContasTotal) ?></div>
            </div>
            <div>
                <div class="small text-muted">Saldo total</div>
                <div class="fw-bold <?= $saldoContasTotal < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaGestao($saldoContasTotal) ?></div>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
                <tr>
                    <th>Classificacao</th>
                    <th>Conta</th>
                    <?php if ($empresaFiltro === 0): ?>
                        <th>Empresa</th>
                    <?php endif; ?>
                    <th>Nome</th>
                    <th class="text-end">Saldo Anterior</th>
                    <th class="text-end">Debitos</th>
                    <th class="text-end">Creditos</th>
                    <th class="text-end">Saldo do mes</th>
                    <th class="text-end">Saldo Atual</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contas as $indice => $conta): ?>
                    <?php $classificacao = (string)$conta['CLASSIFICACAO']; ?>
                    <tr>
                        <td><?= htmlspecialchars($classificacao) ?></td>
                        <td><?= (int)$conta['CBCONTADOR'] ?></td>
                        <?php if ($empresaFiltro === 0): ?>
                            <td><?= htmlspecialchars($conta['empresa_nome'] ?: ('Empresa ' . (int)$conta['EMPRESA'])) ?></td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($conta['nome_conta']) ?></td>
                        <td class="text-end fw-semibold <?= ((float)$conta['saldo_anterior']) < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaGestao($conta['saldo_anterior']) ?></td>
                        <td class="text-end text-danger"><?= moedaGestao($conta['debitos']) ?></td>
                        <td class="text-end text-success"><?= moedaGestao($conta['creditos']) ?></td>
                        <td class="text-end fw-semibold <?= ((float)$conta['saldo_mes']) < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaGestao($conta['saldo_mes']) ?></td>
                        <td class="text-end fw-semibold <?= ((float)$conta['saldo']) < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaGestao($conta['saldo']) ?></td>
                    </tr>
                    <?php
                    $proximaClassificacao = isset($contas[$indice + 1])
                        ? (string)$contas[$indice + 1]['CLASSIFICACAO']
                        : null;
                    if ($proximaClassificacao !== $classificacao):
                        $subtotalClassificacao = $subtotaisClassificacao[$classificacao];
                    ?>
                        <tr class="table-secondary fw-bold">
                            <td colspan="<?= $empresaFiltro === 0 ? 4 : 3 ?>">Subtotal <?= htmlspecialchars($classificacao) ?></td>
                            <td class="text-end <?= $subtotalClassificacao['saldo_anterior'] < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaGestao($subtotalClassificacao['saldo_anterior']) ?></td>
                            <td class="text-end text-danger"><?= moedaGestao($subtotalClassificacao['debitos']) ?></td>
                            <td class="text-end text-success"><?= moedaGestao($subtotalClassificacao['creditos']) ?></td>
                            <td class="text-end <?= $subtotalClassificacao['saldo_mes'] < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaGestao($subtotalClassificacao['saldo_mes']) ?></td>
                            <td class="text-end <?= $subtotalClassificacao['saldo'] < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaGestao($subtotalClassificacao['saldo']) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (empty($contas)): ?>
                    <tr><td colspan="<?= $empresaFiltro === 0 ? 9 : 8 ?>" class="text-center text-muted py-4">Nenhuma conta encontrada para este filtro.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
