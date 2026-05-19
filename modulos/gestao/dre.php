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
$mesAtualLabel = date('m/Y', strtotime($inicioPeriodo));
$mesSeguinteLabel = date('m/Y', strtotime($inicioPeriodo . ' +1 month'));

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
      AND DATE(c.DTVENC) BETWEEN ? AND ?
");
$stmtReceberResumo->execute(array_merge($paramEmpresa, [$inicioPeriodo, $fimPeriodo]));
$receberResumo = $stmtReceberResumo->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total_restante' => 0];

$stmtReceber = $pdo_master->prepare("
    SELECT
        c.CRCONTADOR,
        c.CMCONTADOR,
        c.CLICONTADOR,
        COALESCE(cli.NOME, cli.APELIDO, CONCAT('Cliente ', c.CLICONTADOR)) AS cliente,
        c.DTVENC,
        c.VLRPARCELA,
        c.VLRRESTANTE
    FROM armazem_cr001 c
    LEFT JOIN armazem_cr002 cli
        ON cli.EMPRESA = c.EMPRESA
       AND cli.CLICONTADOR = c.CLICONTADOR
    WHERE c.EMPRESA {$filtroEmpresaSql}
      AND (c.STATUS IS NULL OR c.STATUS <> 'QT')
      AND COALESCE(c.excluido_firebird, 'N') <> 'S'
      AND COALESCE(c.financeiro_verificado, 'N') <> 'S'
      AND DATE(c.DTVENC) BETWEEN ? AND ?
    ORDER BY c.DTVENC ASC, cliente ASC, c.CRCONTADOR ASC
    LIMIT 300
");
$stmtReceber->execute(array_merge($paramEmpresa, [$inicioPeriodo, $fimPeriodo]));
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
      AND DATE(cp.DTVENC) BETWEEN ? AND ?
");
$stmtPagarResumo->execute(array_merge($paramEmpresa, [$inicioPeriodo, $fimPeriodo]));
$pagarResumo = $stmtPagarResumo->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total_restante' => 0];

$stmtPagar = $pdo_master->prepare("
    SELECT
        cp.CPCONTADOR,
        cp.FCONTADOR,
        COALESCE(f.NOME, f.APELIDO, CONCAT('Fornecedor ', cp.FCONTADOR)) AS fornecedor,
        cp.TIPODOCORIGEM,
        cp.NUMDOCORIGEM,
        cp.TIPOES,
        COALESCE(NULLIF(cp.TITULO, ''), NULLIF(cp.NOTAFISCAL, ''), NULLIF(cp.NUMCH, ''), cp.IDENTIFICACAO) AS documento,
        cp.DTVENC,
        cp.VLRPARCELA,
        cp.VLRRESTANTE
    FROM armazem_cp001 cp
    LEFT JOIN armazem_cp003 f
        ON f.EMPRESA = cp.EMPRESA
       AND f.FCONTADOR = cp.FCONTADOR
    WHERE cp.EMPRESA {$filtroEmpresaSql}
      AND (cp.STATUS IS NULL OR cp.STATUS <> 'QT')
      AND COALESCE(cp.excluido_firebird, 'N') <> 'S'
      AND COALESCE(cp.financeiro_verificado, 'N') <> 'S'
      AND DATE(cp.DTVENC) BETWEEN ? AND ?
    ORDER BY cp.DTVENC ASC, fornecedor ASC, cp.CPCONTADOR ASC
    LIMIT 300
");
$stmtPagar->execute(array_merge($paramEmpresa, [$inicioPeriodo, $fimPeriodo]));
$pagar = $stmtPagar->fetchAll(PDO::FETCH_ASSOC);

$stmtContas = $pdo_master->prepare("
    SELECT
        c.CBCONTADOR,
        c.EMPRESA,
        emp.nome_fantasia AS empresa_nome,
        TRIM(COALESCE(NULLIF(c.TITULAR, ''), NULLIF(c.DESCABREV, ''), CONCAT('Conta ', c.CBCONTADOR))) AS nome_conta,
        COALESCE(s.valor_saldo, 0) AS saldo_inicial,
        s.data_saldo,
        COALESCE((
            SELECT SUM(CASE WHEN b.TIPOMOV = 'C' THEN ABS(b.VALORMOV) ELSE -ABS(b.VALORMOV) END)
            FROM armazem_bnc001 b
            WHERE b.EMPRESA = c.EMPRESA
              AND b.CBCONTADOR = c.CBCONTADOR
              AND COALESCE(b.deletado, 'N') <> 'S'
              AND DATE(b.DTMOV) > COALESCE(s.data_saldo, '1900-01-01')
              AND DATE(b.DTMOV) <= ?
        ), 0) AS movimento
    FROM armazem_bnc002 c
    LEFT JOIN empresas emp
        ON emp.id = c.EMPRESA
    LEFT JOIN financeiro_contas_saldos s
        ON s.empresa_id = c.EMPRESA
       AND s.cbcontador = c.CBCONTADOR
    WHERE c.EMPRESA {$filtroEmpresaSql}
      AND COALESCE(c.excluido_firebird, 'N') <> 'S'
      AND COALESCE(c.CONTABLOQUEADA, 'N') <> 'S'
    ORDER BY nome_conta ASC, c.CBCONTADOR ASC
");
$stmtContas->execute(array_merge([$fimPeriodo], $paramEmpresa));
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

$saldoContasTotal = 0.0;
foreach ($contas as &$conta) {
    $conta['saldo'] = (float)$conta['saldo_inicial'] + (float)$conta['movimento'];
    $saldoContasTotal += (float)$conta['saldo'];
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
                    Visao gerencial de <?= htmlspecialchars($empresaNome) ?> para <?= htmlspecialchars($mesAtualLabel) ?> e <?= htmlspecialchars($mesSeguinteLabel) ?>.
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
                <div class="small text-muted">Ate <?= dataGestao($fimPeriodo) ?></div>
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
    <div class="card-header">
        <h2 class="h5 mb-0">Contas a Receber</h2>
        <small class="text-muted">Vencimento entre <?= dataGestao($inicioPeriodo) ?> e <?= dataGestao($fimPeriodo) ?>, verificado = nao.</small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
                <tr>
                    <th>Venc.</th>
                    <th>Cliente</th>
                    <th class="text-end">CM</th>
                    <th class="text-end">Parcela</th>
                    <th class="text-end">Aberto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($receber as $linha): ?>
                    <tr>
                        <td><?= dataGestao($linha['DTVENC']) ?></td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($linha['cliente']) ?></div>
                            <div class="small text-muted">CR <?= (int)$linha['CRCONTADOR'] ?></div>
                        </td>
                        <td class="text-end"><?= (int)$linha['CMCONTADOR'] ?></td>
                        <td class="text-end"><?= moedaGestao($linha['VLRPARCELA']) ?></td>
                        <td class="text-end fw-semibold"><?= moedaGestao($linha['VLRRESTANTE']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($receber)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhum contas a receber pendente no periodo.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card shadow-sm mb-4">
    <div class="card-header">
        <h2 class="h5 mb-0">Contas a Pagar</h2>
        <small class="text-muted">Vencimento entre <?= dataGestao($inicioPeriodo) ?> e <?= dataGestao($fimPeriodo) ?>, verificado = nao.</small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
                <tr>
                    <th>Venc.</th>
                    <th>Fornecedor</th>
                    <th>Documento</th>
                    <th class="text-end">TipoES</th>
                    <th class="text-end">Parcela</th>
                    <th class="text-end">Aberto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pagar as $linha): ?>
                    <tr>
                        <td><?= dataGestao($linha['DTVENC']) ?></td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($linha['fornecedor']) ?></div>
                            <div class="small text-muted">CP <?= (int)$linha['CPCONTADOR'] ?></div>
                        </td>
                        <td><?= htmlspecialchars((string)($linha['documento'] ?: $linha['NUMDOCORIGEM'])) ?></td>
                        <td class="text-end"><?= (int)$linha['TIPOES'] ?></td>
                        <td class="text-end"><?= moedaGestao($linha['VLRPARCELA']) ?></td>
                        <td class="text-end fw-semibold"><?= moedaGestao($linha['VLRRESTANTE']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($pagar)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nenhum contas a pagar pendente no periodo.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card shadow-sm mb-4">
    <div class="card-header">
        <h2 class="h5 mb-0">Saldo das Contas</h2>
        <small class="text-muted">Contas nao bloqueadas, calculadas ate <?= dataGestao($fimPeriodo) ?>.</small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
                <tr>
                    <th>Conta</th>
                    <?php if ($empresaFiltro === 0): ?>
                        <th>Empresa</th>
                    <?php endif; ?>
                    <th>Nome</th>
                    <th class="text-end">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contas as $conta): ?>
                    <tr>
                        <td><?= (int)$conta['CBCONTADOR'] ?></td>
                        <?php if ($empresaFiltro === 0): ?>
                            <td><?= htmlspecialchars($conta['empresa_nome'] ?: ('Empresa ' . (int)$conta['EMPRESA'])) ?></td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($conta['nome_conta']) ?></td>
                        <td class="text-end fw-semibold <?= ((float)$conta['saldo']) < 0 ? 'text-danger' : 'text-success' ?>"><?= moedaGestao($conta['saldo']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($contas)): ?>
                    <tr><td colspan="<?= $empresaFiltro === 0 ? 4 : 3 ?>" class="text-center text-muted py-4">Nenhuma conta encontrada para este filtro.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
