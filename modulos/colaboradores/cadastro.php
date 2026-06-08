<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require '../../layout/header.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);

$nomeFiltro = trim($_GET['nome'] ?? '');
$demitidoFiltro = $_GET['demitido'] ?? 'nao_demitidos';
$ativoFiltro = $_GET['ativo'] ?? 'ativos';

function tabelaExisteColaboradores(PDO $pdo, string $tabela): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$tabela]);
    return (int)$stmt->fetchColumn() > 0;
}

function colunasColaboradores(PDO $pdo, string $tabela): array
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION
    ");
    $stmt->execute([$tabela]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function primeiraColunaDisponivel(array $colunas, array $candidatas): ?string
{
    $mapa = array_change_key_case(array_fill_keys($colunas, true), CASE_UPPER);
    foreach ($candidatas as $candidata) {
        $candidataUpper = strtoupper($candidata);
        if (isset($mapa[$candidataUpper])) {
            foreach ($colunas as $coluna) {
                if (strtoupper($coluna) === $candidataUpper) {
                    return $coluna;
                }
            }
        }
    }
    return null;
}

function escaparColaborador(string $coluna): string
{
    return '`' . str_replace('`', '``', $coluna) . '`';
}

function dataColaborador($valor): string
{
    if (!$valor || $valor === '0000-00-00' || $valor === '0000-00-00 00:00:00') {
        return '';
    }
    $timestamp = strtotime((string)$valor);
    return $timestamp ? date('d/m/Y', $timestamp) : (string)$valor;
}

function statusSimNao($valor, bool $invertido = false): bool
{
    $texto = strtoupper(trim((string)$valor));
    $positivo = in_array($texto, ['S', 'SIM', '1', 'TRUE', 'T', 'Y'], true);
    return $invertido ? !$positivo : $positivo;
}

$tabela = 'armazem_REP001';
$tabelaExiste = tabelaExisteColaboradores($pdo_master, $tabela);
$colunas = $tabelaExiste ? colunasColaboradores($pdo_master, $tabela) : [];
$registros = [];
$totalRegistros = 0;
$mensagemTabela = '';

$colunaCodigo = primeiraColunaDisponivel($colunas, ['FUNCCONTADOR', 'REPCONTADOR', 'CODFUNC', 'CODIGO', 'FUNCIONARIO', 'ID']);
$colunaNome = primeiraColunaDisponivel($colunas, ['NOME', 'NOMEREP', 'NOMEFUNC', 'NOMEFUNCIONARIO', 'RAZAO', 'DESCRICAO', 'APELIDO']);
$colunaApelido = primeiraColunaDisponivel($colunas, ['APELIDO', 'FANTASIA', 'NOMEABREV', 'NOME_ABREV']);
$colunaCpf = primeiraColunaDisponivel($colunas, ['CPF', 'CGC', 'CNPJ', 'DOCUMENTO']);
$colunaTelefone = primeiraColunaDisponivel($colunas, ['TELEFONE', 'FONE', 'CELULAR', 'FONECELULAR', 'TELCELULAR']);
$colunaCargo = primeiraColunaDisponivel($colunas, ['CARGO', 'FUNCAO', 'TIPOFUNC', 'TIPOREP']);
$colunaDemitido = primeiraColunaDisponivel($colunas, ['DEMITIDO', 'DEMISSAO', 'DTDEMISSAO', 'DATADEMISSAO', 'DATA_DEMISSAO']);
$colunaAtivo = primeiraColunaDisponivel($colunas, ['ATIVO', 'INATIVO', 'DESATIVADO', 'REGDISAB', 'BLOQUEADO']);
$colunaRegstamp = primeiraColunaDisponivel($colunas, ['REGSTAMP']);

if (!$tabelaExiste) {
    $mensagemTabela = 'A tabela armazem_REP001 ainda nao existe. Execute a sincronizacao da REP001 para carregar o cadastro.';
} elseif (empty($colunas)) {
    $mensagemTabela = 'A tabela armazem_REP001 existe, mas ainda nao possui colunas sincronizadas.';
} else {
    $where = ['1=1'];
    $params = [];

    if (in_array('EMPRESA', $colunas, true) && $empresaId > 0) {
        $where[] = 'EMPRESA = ?';
        $params[] = $empresaId;
    }

    if ($nomeFiltro !== '') {
        $buscaNome = [];
        foreach (array_filter([$colunaNome, $colunaApelido, $colunaCodigo, $colunaCpf]) as $colunaBusca) {
            $buscaNome[] = escaparColaborador($colunaBusca) . ' LIKE ?';
            $params[] = '%' . $nomeFiltro . '%';
        }
        if (!empty($buscaNome)) {
            $where[] = '(' . implode(' OR ', $buscaNome) . ')';
        }
    }

    if ($colunaDemitido && in_array($demitidoFiltro, ['demitidos', 'nao_demitidos'], true)) {
        $colunaSql = escaparColaborador($colunaDemitido);
        if (stripos($colunaDemitido, 'DT') === 0 || stripos($colunaDemitido, 'DATA') !== false) {
            $where[] = $demitidoFiltro === 'demitidos'
                ? "($colunaSql IS NOT NULL AND $colunaSql <> '0000-00-00' AND $colunaSql <> '0000-00-00 00:00:00')"
                : "($colunaSql IS NULL OR $colunaSql = '0000-00-00' OR $colunaSql = '0000-00-00 00:00:00')";
        } else {
            $where[] = $demitidoFiltro === 'demitidos'
                ? "UPPER(COALESCE(CAST($colunaSql AS CHAR), 'N')) IN ('S','SIM','1','TRUE','T','Y')"
                : "UPPER(COALESCE(CAST($colunaSql AS CHAR), 'N')) NOT IN ('S','SIM','1','TRUE','T','Y')";
        }
    }

    if ($colunaAtivo && in_array($ativoFiltro, ['ativos', 'inativos'], true)) {
        $colunaSql = escaparColaborador($colunaAtivo);
        $colunaUpper = strtoupper($colunaAtivo);
        $campoInvertido = in_array($colunaUpper, ['INATIVO', 'DESATIVADO', 'REGDISAB', 'BLOQUEADO'], true);

        if ($campoInvertido) {
            $where[] = $ativoFiltro === 'ativos'
                ? "UPPER(COALESCE(CAST($colunaSql AS CHAR), 'N')) NOT IN ('S','SIM','1','TRUE','T','Y')"
                : "UPPER(COALESCE(CAST($colunaSql AS CHAR), 'N')) IN ('S','SIM','1','TRUE','T','Y')";
        } else {
            $where[] = $ativoFiltro === 'ativos'
                ? "UPPER(COALESCE(CAST($colunaSql AS CHAR), 'S')) IN ('S','SIM','1','TRUE','T','Y')"
                : "UPPER(COALESCE(CAST($colunaSql AS CHAR), 'S')) NOT IN ('S','SIM','1','TRUE','T','Y')";
        }
    }

    $whereSql = implode(' AND ', $where);

    $stmtTotal = $pdo_master->prepare("SELECT COUNT(*) FROM `$tabela` WHERE $whereSql");
    $stmtTotal->execute($params);
    $totalRegistros = (int)$stmtTotal->fetchColumn();

    $ordem = $colunaNome ? escaparColaborador($colunaNome) : ($colunaCodigo ? escaparColaborador($colunaCodigo) : '1');
    $stmt = $pdo_master->prepare("
        SELECT *
        FROM `$tabela`
        WHERE $whereSql
        ORDER BY $ordem
        LIMIT 500
    ");
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$queryBase = [
    'nome' => $nomeFiltro,
    'demitido' => $demitidoFiltro,
    'ativo' => $ativoFiltro,
];
?>

<style>
    .colab-filter {
        display: grid;
        grid-template-columns: minmax(180px, 1fr) minmax(150px, 210px) minmax(150px, 190px) auto auto;
        gap: .75rem;
        align-items: end;
    }

    .colab-table {
        table-layout: fixed;
        min-width: 760px;
    }

    .colab-table th,
    .colab-table td {
        vertical-align: middle;
    }

    .col-code { width: 90px; }
    .col-name { width: 260px; }
    .col-doc { width: 150px; }
    .col-phone { width: 140px; }
    .col-status { width: 120px; }
    .col-date { width: 130px; }

    @media (max-width: 767.98px) {
        .colab-filter {
            grid-template-columns: 1fr;
        }

        .colab-table-wrap {
            overflow-x: auto;
        }
    }
</style>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Colaboradores</span>
                <h1 class="h3 fw-bold mb-2">Cadastro</h1>
                <p class="text-muted mb-0">Consulta dos funcionarios sincronizados da tabela REP001.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_colaboradores.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<section class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="colab-filter">
            <div>
                <label class="form-label">Nome</label>
                <input type="text" name="nome" value="<?= htmlspecialchars($nomeFiltro) ?>" class="form-control" placeholder="Nome, codigo ou documento">
            </div>
            <div>
                <label class="form-label">Demitidos</label>
                <select name="demitido" class="form-select">
                    <option value="nao_demitidos" <?= $demitidoFiltro === 'nao_demitidos' ? 'selected' : '' ?>>Nao demitidos</option>
                    <option value="demitidos" <?= $demitidoFiltro === 'demitidos' ? 'selected' : '' ?>>Demitidos</option>
                    <option value="todos" <?= $demitidoFiltro === 'todos' ? 'selected' : '' ?>>Todos</option>
                </select>
            </div>
            <div>
                <label class="form-label">Situacao</label>
                <select name="ativo" class="form-select">
                    <option value="ativos" <?= $ativoFiltro === 'ativos' ? 'selected' : '' ?>>Ativos</option>
                    <option value="inativos" <?= $ativoFiltro === 'inativos' ? 'selected' : '' ?>>Inativos</option>
                    <option value="todos" <?= $ativoFiltro === 'todos' ? 'selected' : '' ?>>Todos</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <a href="cadastro.php" class="btn btn-outline-secondary">Limpar</a>
        </form>
    </div>
</section>

<?php if ($mensagemTabela !== ''): ?>
    <div class="alert alert-warning"><?= htmlspecialchars($mensagemTabela) ?></div>
<?php else: ?>
    <section class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted">Registros filtrados</div>
                    <div class="h4 fw-bold mb-0"><?= number_format($totalRegistros, 0, ',', '.') ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted">Exibindo</div>
                    <div class="h4 fw-bold mb-0"><?= number_format(count($registros), 0, ',', '.') ?></div>
                </div>
            </div>
        </div>
    </section>

    <section class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center gap-3">
            <div>
                <h2 class="h6 fw-bold mb-0">Funcionarios</h2>
                <small class="text-muted">Limite visual de 500 registros por consulta.</small>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive colab-table-wrap">
                <table class="table table-sm table-hover mb-0 colab-table">
                    <thead class="table-dark">
                        <tr>
                            <th class="col-code">Codigo</th>
                            <th class="col-name">Nome</th>
                            <th class="col-doc">Documento</th>
                            <th class="col-phone">Telefone</th>
                            <th>Cargo</th>
                            <th class="col-status">Ativo</th>
                            <th class="col-status">Demitido</th>
                            <th class="col-date">Atualizado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($registros)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">Nenhum colaborador encontrado.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($registros as $registro): ?>
                            <?php
                                $codigo = $colunaCodigo ? ($registro[$colunaCodigo] ?? '') : '';
                                $nome = $colunaNome ? ($registro[$colunaNome] ?? '') : '';
                                $doc = $colunaCpf ? ($registro[$colunaCpf] ?? '') : '';
                                $telefone = $colunaTelefone ? ($registro[$colunaTelefone] ?? '') : '';
                                $cargo = $colunaCargo ? ($registro[$colunaCargo] ?? '') : '';

                                $ativo = true;
                                if ($colunaAtivo) {
                                    $ativo = statusSimNao($registro[$colunaAtivo] ?? '', in_array(strtoupper($colunaAtivo), ['INATIVO', 'DESATIVADO', 'REGDISAB', 'BLOQUEADO'], true));
                                }

                                $demitido = false;
                                if ($colunaDemitido) {
                                    $valorDemitido = $registro[$colunaDemitido] ?? null;
                                    if (stripos($colunaDemitido, 'DT') === 0 || stripos($colunaDemitido, 'DATA') !== false) {
                                        $demitido = !empty($valorDemitido) && $valorDemitido !== '0000-00-00' && $valorDemitido !== '0000-00-00 00:00:00';
                                    } else {
                                        $demitido = statusSimNao($valorDemitido);
                                    }
                                }
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars((string)$codigo) ?></td>
                                <td class="fw-semibold text-truncate" title="<?= htmlspecialchars((string)$nome) ?>"><?= htmlspecialchars((string)$nome) ?></td>
                                <td class="text-truncate"><?= htmlspecialchars((string)$doc) ?></td>
                                <td class="text-truncate"><?= htmlspecialchars((string)$telefone) ?></td>
                                <td class="text-truncate"><?= htmlspecialchars((string)$cargo) ?></td>
                                <td>
                                    <span class="badge <?= $ativo ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                        <?= $ativo ? 'Ativo' : 'Inativo' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $demitido ? 'text-bg-danger' : 'text-bg-success' ?>">
                                        <?= $demitido ? 'Demitido' : 'Nao' ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($colunaRegstamp ? dataColaborador($registro[$colunaRegstamp] ?? '') : '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php require '../../layout/footer.php'; ?>
