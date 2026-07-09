<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

garantirTabelasEnergia($pdo_master);

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$mensagem = '';
$erro = '';

function moedaEnergiaOperacao(float $valor): string
{
    return number_format($valor, 2, ',', '.');
}

function qtdEnergiaOperacao(float $valor): string
{
    return number_format($valor, 3, ',', '.');
}

function decimalPostEnergiaOperacao(string $valor): float
{
    return valorPtEnergia($valor);
}

function proximoMovcontadorEnergia(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COALESCE(MAX(MOVCONTADOR), 0) + 1 FROM armazem_bnc001");
    return (int)$stmt->fetchColumn();
}

function proximoCrcontadorEnergia(PDO $pdo, int $empresaId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CRCONTADOR), 0) + 1 FROM armazem_cr001 WHERE EMPRESA = ?");
    $stmt->execute([$empresaId]);
    return (int)$stmt->fetchColumn();
}

function gerarMovimentoEnergia(PDO $pdo, int $empresaId, int $usuarioId, int $operacaoId, string $data, string $tipomov, int $tipoes, float $valor, string $historico): ?int
{
    if ($valor <= 0) {
        return null;
    }

    $movcontador = proximoMovcontadorEnergia($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO armazem_bnc001 (
            EMPRESA, MOVCONTADOR, DTMOV, NUMDOC, TIPOMOV, CBCONTADOR, TIPOES,
            HISTMOV, VALORMOV, TIPODOCORIGEM, NUMDOCORIGEM, REGSTAMP,
            USERBNCLANC, CONTRAPARTIDA, ORIGEMCPART, DTLANC, DTPROCESSADO, deletado
        ) VALUES (
            ?, ?, ?, ?, ?, 38, ?, ?, ?, 'ENERGIA', ?, NOW(),
            ?, 'N', 0, NOW(), NOW(), 'N'
        )
    ");
    $stmt->execute([
        $empresaId,
        $movcontador,
        $data,
        'ENERG-' . $operacaoId,
        $tipomov,
        $tipoes,
        $historico,
        $valor,
        $operacaoId,
        $usuarioId ?: null,
    ]);

    return $movcontador;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    try {
        if ($acao === 'salvar_operacao') {
            $id = (int)($_POST['id'] ?? 0);
            $contaId = (int)($_POST['conta_id'] ?? 0);
            if ($contaId <= 0) {
                throw new RuntimeException('Selecione uma conta de energia para a operacao.');
            }

            $stmtConta = $pdo_master->prepare("
                SELECT id, valor_unitario_kw, custo_disponibilidade, contribuicao_iluminacao
                FROM energia_contas
                WHERE id = ?
                  AND empresa_id = ?
            ");
            $stmtConta->execute([$contaId, $empresaId]);
            $contaOperacao = $stmtConta->fetch(PDO::FETCH_ASSOC);
            if (!$contaOperacao) {
                throw new RuntimeException('A conta de energia selecionada nao pertence a empresa logada.');
            }

            $quantidadeKw = decimalPostEnergiaOperacao((string)($_POST['quantidade_kw_injetada'] ?? '0'));
            $percentualDesconto = decimalPostEnergiaOperacao((string)($_POST['percentual_desconto_venda'] ?? '0'));
            $valorUnitarioKw = (float)$contaOperacao['valor_unitario_kw'];
            $custoDisponibilidade = (float)$contaOperacao['custo_disponibilidade'];
            $contribuicaoIluminacao = (float)$contaOperacao['contribuicao_iluminacao'];
            $valorContaComDesconto = round(
                ($quantidadeKw * $valorUnitarioKw * (1 - ($percentualDesconto / 100)))
                + $custoDisponibilidade
                + $contribuicaoIluminacao,
                2
            );
            if ($valorContaComDesconto < 0) {
                $valorContaComDesconto = 0;
            }

            $dados = [
                'quantidade_kw_injetada' => $quantidadeKw,
                'percentual_desconto_venda' => $percentualDesconto,
                'valor_conta_com_desconto' => $valorContaComDesconto,
                'valor_total_pago_fornecedor' => decimalPostEnergiaOperacao((string)($_POST['valor_total_pago_fornecedor'] ?? '0')),
                'observacao' => trim((string)($_POST['observacao'] ?? '')),
            ];

            if ($id > 0) {
                $stmtExiste = $pdo_master->prepare("
                    SELECT status
                    FROM energia_operacoes
                    WHERE id = ?
                      AND empresa_id = ?
                    LIMIT 1
                ");
                $stmtExiste->execute([$id, $empresaId]);
                $statusAtual = $stmtExiste->fetchColumn();
                if ($statusAtual === false) {
                    throw new RuntimeException('Operacao nao encontrada.');
                }
                if ($statusAtual !== 'ABERTA') {
                    throw new RuntimeException('Operacao nao esta aberta para edicao.');
                }

                $stmt = $pdo_master->prepare("
                    UPDATE energia_operacoes
                    SET conta_id = ?,
                        quantidade_kw_injetada = ?,
                        percentual_desconto_venda = ?,
                        valor_conta_com_desconto = ?,
                        valor_total_pago_fornecedor = ?,
                        observacao = ?
                    WHERE id = ?
                      AND empresa_id = ?
                      AND status = 'ABERTA'
                ");
                $stmt->execute([
                    $contaId,
                    $dados['quantidade_kw_injetada'],
                    $dados['percentual_desconto_venda'],
                    $dados['valor_conta_com_desconto'],
                    $dados['valor_total_pago_fornecedor'],
                    $dados['observacao'],
                    $id,
                    $empresaId,
                ]);
            } else {
                $stmt = $pdo_master->prepare("
                    INSERT INTO energia_operacoes (
                        empresa_id, conta_id, quantidade_kw_injetada, percentual_desconto_venda,
                        valor_conta_com_desconto, valor_total_pago_fornecedor, status, observacao, criado_por
                    ) VALUES (?, ?, ?, ?, ?, ?, 'ABERTA', ?, ?)
                ");
                $stmt->execute([
                    $empresaId,
                    $contaId,
                    $dados['quantidade_kw_injetada'],
                    $dados['percentual_desconto_venda'],
                    $dados['valor_conta_com_desconto'],
                    $dados['valor_total_pago_fornecedor'],
                    $dados['observacao'],
                    $usuarioId ?: null,
                ]);
                $id = (int)$pdo_master->lastInsertId();
            }

            header('Location: operacoes.php?ok=salvo&editar=' . $id);
            exit;
        }

        if ($acao === 'fechar_operacao') {
            $id = (int)($_POST['id'] ?? 0);
            $fornecedorId = (int)($_POST['fornecedor_id'] ?? 0);
            $clienteId = (int)($_POST['cliente_id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Operacao nao localizada para fechamento.');
            }
            if ($fornecedorId <= 0) {
                throw new RuntimeException('Informe o fornecedor da operacao.');
            }
            if ($clienteId <= 0) {
                throw new RuntimeException('Informe o cliente da operacao.');
            }

            $stmtFornecedor = $pdo_master->prepare("SELECT FCONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Fornecedor ', FCONTADOR)) AS nome FROM armazem_cp003 WHERE EMPRESA = ? AND FCONTADOR = ? AND COALESCE(excluido_firebird, 'N') <> 'S'");
            $stmtFornecedor->execute([$empresaId, $fornecedorId]);
            $fornecedor = $stmtFornecedor->fetch(PDO::FETCH_ASSOC);
            if (!$fornecedor) {
                throw new RuntimeException('Fornecedor nao localizado na empresa logada.');
            }

            $stmtCliente = $pdo_master->prepare("SELECT CLICONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Cliente ', CLICONTADOR)) AS nome FROM armazem_cr002 WHERE EMPRESA = ? AND CLICONTADOR = ? AND COALESCE(excluido_firebird, 'N') <> 'S'");
            $stmtCliente->execute([$empresaId, $clienteId]);
            $cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);
            if (!$cliente) {
                throw new RuntimeException('Cliente nao localizado na empresa logada.');
            }

            $stmtOperacao = $pdo_master->prepare("
                SELECT o.*, c.referencia, c.vencimento, c.logradouro_complemento
                FROM energia_operacoes o
                INNER JOIN energia_contas c ON c.id = o.conta_id AND c.empresa_id = o.empresa_id
                WHERE o.id = ?
                  AND o.empresa_id = ?
                LIMIT 1
            ");
            $stmtOperacao->execute([$id, $empresaId]);
            $operacaoFechar = $stmtOperacao->fetch(PDO::FETCH_ASSOC);
            if (!$operacaoFechar) {
                throw new RuntimeException('Operacao nao encontrada.');
            }
            if ($operacaoFechar['status'] !== 'ABERTA') {
                throw new RuntimeException('Operacao ja foi fechada.');
            }

            $valorContaComDesconto = (float)$operacaoFechar['valor_conta_com_desconto'];
            $valorPagoFornecedor = (float)$operacaoFechar['valor_total_pago_fornecedor'];
            $valorComissao = round($valorContaComDesconto - $valorPagoFornecedor, 2);
            if ($valorContaComDesconto <= 0) {
                throw new RuntimeException('Valor da conta com desconto precisa ser maior que zero.');
            }
            if ($valorComissao < 0) {
                throw new RuntimeException('Valor total pago ao fornecedor maior que o valor da conta com desconto. Nao e possivel gerar comissao negativa.');
            }

            $dataMovimento = $operacaoFechar['vencimento'] ?: date('Y-m-d');
            $referencia = trim((string)($operacaoFechar['referencia'] ?: ''));
            $endereco = trim((string)($operacaoFechar['logradouro_complemento'] ?: ''));
            $fornecedorNome = (string)$fornecedor['nome'];
            $clienteNome = (string)$cliente['nome'];

            $pdo_master->beginTransaction();
            try {
                $historicoBase = trim('ENERGIA OP #' . $id . ' ' . $referencia . ' - ' . $endereco);
                $movContasPagar = gerarMovimentoEnergia(
                    $pdo_master,
                    $empresaId,
                    $usuarioId,
                    $id,
                    $dataMovimento,
                    'D',
                    199,
                    $valorContaComDesconto,
                    $historicoBase . ' - CONTAS A PAGAR - ' . $fornecedorNome
                );
                $movComissao = gerarMovimentoEnergia(
                    $pdo_master,
                    $empresaId,
                    $usuarioId,
                    $id,
                    $dataMovimento,
                    'C',
                    6,
                    $valorComissao,
                    $historicoBase . ' - COMISSAO ENERGIA - ' . $clienteNome
                );

                $crcontador = proximoCrcontadorEnergia($pdo_master, $empresaId);
                $titulo = trim('ENERGIA OP #' . $id . ' - ' . $referencia . ' - ' . $endereco);
                $stmtCr = $pdo_master->prepare("
                    INSERT INTO armazem_cr001 (
                        EMPRESA, CRCONTADOR, DTVENDA, NUMPARCELA, TITULO, VALORVENDA,
                        CLICONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
                        VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
                        TIPOCR, TIPOES, NOTAFISCAL, REGSTAMP, USERLANC, DTLANC,
                        USERALT, DTALT, CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
                    ) VALUES (
                        ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, '1/1', ?, ?, 0, 'AB', 'ENERGIA', ?, 'ENERGIA_OPERACAO',
                        'CR', 50, NULL, NOW(), ?, NOW(), ?, NOW(), ?, 'N', 'N'
                    )
                ");
                $chave = 'ENERGIA-OPERACAO-' . $empresaId . '-' . $id;
                $stmtCr->execute([
                    $empresaId,
                    $crcontador,
                    $dataMovimento,
                    $titulo,
                    $valorContaComDesconto,
                    $clienteId,
                    'Operacao de energia #' . $id . ' | Cliente: ' . $clienteNome . ' | Fornecedor: ' . $fornecedorNome,
                    $dataMovimento,
                    $valorContaComDesconto,
                    $dataMovimento,
                    $valorContaComDesconto,
                    $id,
                    $usuarioId ?: null,
                    $usuarioId ?: null,
                    $chave,
                ]);

                $stmtUpdate = $pdo_master->prepare("
                    UPDATE energia_operacoes
                    SET status = 'FECHADA',
                        fornecedor_id = ?,
                        cliente_id = ?,
                        mov_contas_pagar = ?,
                        mov_comissao = ?,
                        crcontador = ?,
                        fechado_por = ?,
                        fechado_em = NOW()
                    WHERE id = ?
                      AND empresa_id = ?
                      AND status = 'ABERTA'
                ");
                $stmtUpdate->execute([
                    $fornecedorId,
                    $clienteId,
                    $movContasPagar,
                    $movComissao,
                    $crcontador,
                    $usuarioId ?: null,
                    $id,
                    $empresaId,
                ]);
                if ($stmtUpdate->rowCount() !== 1) {
                    throw new RuntimeException('Nao foi possivel marcar a operacao como fechada.');
                }

                $pdo_master->commit();
            } catch (Throwable $e) {
                $pdo_master->rollBack();
                throw $e;
            }

            header('Location: operacoes.php?ok=fechada&editar=' . $id);
            exit;
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

if (isset($_GET['ok'])) {
    $mensagem = $_GET['ok'] === 'fechada' ? 'Operacao de energia fechada e lancamentos gerados.' : 'Operacao de energia salva.';
}

$editarId = (int)($_GET['editar'] ?? 0);
$operacaoEditar = null;
if ($editarId > 0) {
    $stmt = $pdo_master->prepare("
        SELECT o.*, c.referencia, c.vencimento, c.logradouro_complemento, c.unidade_consumidora,
               c.valor_total, c.consumo_kwh, c.valor_unitario_kw, c.franquia_minima,
               c.custo_disponibilidade, c.contribuicao_iluminacao
        FROM energia_operacoes o
        INNER JOIN energia_contas c ON c.id = o.conta_id AND c.empresa_id = o.empresa_id
        WHERE o.id = ?
          AND o.empresa_id = ?
    ");
    $stmt->execute([$editarId, $empresaId]);
    $operacaoEditar = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$fReferencia = trim((string)($_GET['referencia'] ?? ''));
$fEndereco = trim((string)($_GET['endereco'] ?? ''));
$fStatus = trim((string)($_GET['status'] ?? ''));

$where = ['o.empresa_id = ?'];
$params = [$empresaId];
if ($fReferencia !== '') {
    $where[] = 'c.referencia LIKE ?';
    $params[] = '%' . $fReferencia . '%';
}
if ($fEndereco !== '') {
    $where[] = 'c.logradouro_complemento LIKE ?';
    $params[] = '%' . $fEndereco . '%';
}
if ($fStatus !== '') {
    $where[] = 'o.status = ?';
    $params[] = $fStatus;
}

$stmt = $pdo_master->prepare("
    SELECT o.*, c.referencia, c.vencimento, c.logradouro_complemento, c.unidade_consumidora,
           c.valor_total, c.consumo_kwh, c.valor_unitario_kw, c.franquia_minima,
           c.custo_disponibilidade, c.contribuicao_iluminacao
    FROM energia_operacoes o
    INNER JOIN energia_contas c ON c.id = o.conta_id AND c.empresa_id = o.empresa_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY o.id DESC
    LIMIT 250
");
$stmt->execute($params);
$operacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmtContas = $pdo_master->prepare("
    SELECT id, referencia, vencimento, logradouro_complemento, unidade_consumidora, valor_total,
           consumo_kwh, valor_unitario_kw, custo_disponibilidade, contribuicao_iluminacao
    FROM energia_contas
    WHERE empresa_id = ?
      AND NOT EXISTS (
          SELECT 1
          FROM energia_operacoes eo
          WHERE eo.empresa_id = energia_contas.empresa_id
            AND eo.conta_id = energia_contas.id
            AND eo.status = 'FECHADA'
      )
    ORDER BY COALESCE(vencimento, '9999-12-31') DESC, id DESC
    LIMIT 300
");
$stmtContas->execute([$empresaId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

$stmtFornecedores = $pdo_master->prepare("
    SELECT FCONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Fornecedor ', FCONTADOR)) AS nome
    FROM armazem_cp003
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY nome, FCONTADOR
");
$stmtFornecedores->execute([$empresaId]);
$fornecedores = $stmtFornecedores->fetchAll(PDO::FETCH_ASSOC);

$stmtClientes = $pdo_master->prepare("
    SELECT CLICONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Cliente ', CLICONTADOR)) AS nome
    FROM armazem_cr002
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY nome, CLICONTADOR
");
$stmtClientes->execute([$empresaId]);
$clientes = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);

$totais = [
    'kw' => 0.0,
    'desconto' => 0.0,
    'conta_desconto' => 0.0,
    'fornecedor' => 0.0,
];
foreach ($operacoes as $op) {
    $totais['kw'] += (float)$op['quantidade_kw_injetada'];
    $totais['desconto'] += (float)$op['percentual_desconto_venda'];
    $totais['conta_desconto'] += (float)$op['valor_conta_com_desconto'];
    $totais['fornecedor'] += (float)$op['valor_total_pago_fornecedor'];
}

$form = $operacaoEditar ?: [
    'id' => 0,
    'conta_id' => (int)($_GET['conta_id'] ?? 0),
    'quantidade_kw_injetada' => 0,
    'percentual_desconto_venda' => 0,
    'valor_conta_com_desconto' => 0,
    'valor_total_pago_fornecedor' => 0,
    'observacao' => '',
    'status' => 'ABERTA',
];

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-success mb-2">Energia</span>
                <h1 class="h4 fw-bold mb-1">Operacoes de Energia</h1>
                <p class="text-muted mb-0">Informe os dados comerciais ligados a uma conta de energia importada.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_energia.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagem): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensagem) ?></div>
<?php endif; ?>
<?php if ($erro): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<section class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3"><?= ((int)$form['id'] > 0) ? 'Editar operacao' : 'Nova operacao' ?></h2>
                <form method="post" class="row g-3">
                    <input type="hidden" name="acao" value="salvar_operacao">
                    <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

                    <div class="col-12">
                        <label class="form-label small fw-semibold">Conta de energia</label>
                        <select name="conta_id" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($contas as $conta): ?>
                                <?php
                                $label = '#' . (int)$conta['id'] . ' - ' . ($conta['referencia'] ?: 'Sem referencia')
                                    . ' - ' . ($conta['logradouro_complemento'] ?: $conta['unidade_consumidora'])
                                    . ' - R$ ' . moedaEnergiaOperacao((float)$conta['valor_total']);
                                ?>
                                <option value="<?= (int)$conta['id'] ?>"
                                    data-valor-kw="<?= htmlspecialchars((string)$conta['valor_unitario_kw']) ?>"
                                    data-custo-disponibilidade="<?= htmlspecialchars((string)$conta['custo_disponibilidade']) ?>"
                                    data-iluminacao="<?= htmlspecialchars((string)$conta['contribuicao_iluminacao']) ?>"
                                    <?= ((int)$form['conta_id'] === (int)$conta['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Quantidade de kW injetada</label>
                        <input type="text" name="quantidade_kw_injetada" id="quantidade_kw_injetada" inputmode="decimal" class="form-control" value="<?= qtdEnergiaOperacao((float)$form['quantidade_kw_injetada']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">% desconto na venda</label>
                        <input type="text" name="percentual_desconto_venda" id="percentual_desconto_venda" inputmode="decimal" class="form-control" value="<?= number_format((float)$form['percentual_desconto_venda'], 4, ',', '.') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Valor da conta com desconto</label>
                        <input type="text" name="valor_conta_com_desconto" id="valor_conta_com_desconto" inputmode="decimal" class="form-control" value="<?= moedaEnergiaOperacao((float)$form['valor_conta_com_desconto']) ?>" readonly>
                        <div class="form-text">kW injetado x valor kW x (1 - desconto) + custo disponibilidade + iluminacao publica.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Valor total pago ao fornecedor</label>
                        <input type="text" name="valor_total_pago_fornecedor" inputmode="decimal" class="form-control" value="<?= moedaEnergiaOperacao((float)$form['valor_total_pago_fornecedor']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Observacao</label>
                        <textarea name="observacao" class="form-control" rows="3"><?= htmlspecialchars((string)$form['observacao']) ?></textarea>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button class="btn btn-success">Salvar operacao</button>
                        <a href="operacoes.php" class="btn btn-outline-secondary">Nova</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3">Conta vinculada</h2>
                <?php if ($operacaoEditar): ?>
                    <div class="row g-3 small">
                        <div class="col-md-6">
                            <span class="text-muted d-block">Endereco</span>
                            <strong><?= htmlspecialchars((string)$operacaoEditar['logradouro_complemento']) ?></strong>
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted d-block">Referencia</span>
                            <strong><?= htmlspecialchars((string)$operacaoEditar['referencia']) ?></strong>
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted d-block">Vencimento</span>
                            <strong><?= $operacaoEditar['vencimento'] ? date('d/m/Y', strtotime((string)$operacaoEditar['vencimento'])) : '-' ?></strong>
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted d-block">Valor conta</span>
                            <strong>R$ <?= moedaEnergiaOperacao((float)$operacaoEditar['valor_total']) ?></strong>
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted d-block">Consumo</span>
                            <strong><?= qtdEnergiaOperacao((float)$operacaoEditar['consumo_kwh']) ?> kWh</strong>
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted d-block">Valor kW</span>
                            <strong><?= number_format((float)$operacaoEditar['valor_unitario_kw'], 6, ',', '.') ?></strong>
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted d-block">Franquia</span>
                            <strong><?= qtdEnergiaOperacao((float)$operacaoEditar['franquia_minima']) ?></strong>
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted d-block">Custo disp.</span>
                            <strong>R$ <?= moedaEnergiaOperacao((float)$operacaoEditar['custo_disponibilidade']) ?></strong>
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted d-block">Iluminacao</span>
                            <strong>R$ <?= moedaEnergiaOperacao((float)$operacaoEditar['contribuicao_iluminacao']) ?></strong>
                        </div>
                    </div>
                    <hr>
                    <?php if ($operacaoEditar['status'] === 'ABERTA'): ?>
                        <h3 class="h6 fw-bold mb-3">Fechamento da operacao</h3>
                        <form method="post" class="row g-3" onsubmit="return confirm('Fechar esta operacao e gerar os lancamentos financeiros?');">
                            <input type="hidden" name="acao" value="fechar_operacao">
                            <input type="hidden" name="id" value="<?= (int)$operacaoEditar['id'] ?>">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Fornecedor</label>
                                <select name="fornecedor_id" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($fornecedores as $fornecedor): ?>
                                        <option value="<?= (int)$fornecedor['FCONTADOR'] ?>">
                                            <?= htmlspecialchars($fornecedor['nome'] . ' (' . $fornecedor['FCONTADOR'] . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Usado no historico do lancamento de contas a pagar.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Cliente</label>
                                <select name="cliente_id" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?= (int)$cliente['CLICONTADOR'] ?>">
                                            <?= htmlspecialchars($cliente['nome'] . ' (' . $cliente['CLICONTADOR'] . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Usado no contas a receber gerado.</div>
                            </div>
                            <div class="col-12">
                                <div class="alert alert-warning small mb-0">
                                    Ao fechar, o sistema cria na conta 38: debito TIPOES 199, credito TIPOES 6 pela diferenca, e um CR001 TIPOES 50 para o cliente.
                                </div>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-danger">Fechar operacao e gerar lancamentos</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <h3 class="h6 fw-bold mb-3">Operacao fechada</h3>
                        <div class="row g-2 small">
                            <div class="col-md-4"><span class="text-muted d-block">Mov. contas pagar</span><strong><?= (int)($operacaoEditar['mov_contas_pagar'] ?? 0) ?: '-' ?></strong></div>
                            <div class="col-md-4"><span class="text-muted d-block">Mov. comissao</span><strong><?= (int)($operacaoEditar['mov_comissao'] ?? 0) ?: '-' ?></strong></div>
                            <div class="col-md-4"><span class="text-muted d-block">CR gerado</span><strong><?= (int)($operacaoEditar['crcontador'] ?? 0) ?: '-' ?></strong></div>
                            <div class="col-md-6"><span class="text-muted d-block">Fornecedor</span><strong><?= (int)($operacaoEditar['fornecedor_id'] ?? 0) ?: '-' ?></strong></div>
                            <div class="col-md-6"><span class="text-muted d-block">Cliente</span><strong><?= (int)($operacaoEditar['cliente_id'] ?? 0) ?: '-' ?></strong></div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-info mb-0">Selecione uma operacao salva para ver o resumo da conta vinculada.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<section class="mb-3">
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Referencia</label>
                    <input type="text" name="referencia" class="form-control" value="<?= htmlspecialchars($fReferencia) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Endereco</label>
                    <input type="text" name="endereco" class="form-control" value="<?= htmlspecialchars($fEndereco) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        <option value="">Todos</option>
                        <option value="ABERTA" <?= $fStatus === 'ABERTA' ? 'selected' : '' ?>>Aberta</option>
                        <option value="FECHADA" <?= $fStatus === 'FECHADA' ? 'selected' : '' ?>>Fechada</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary">Filtrar</button>
                </div>
            </form>
        </div>
    </div>
</section>

<section>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <div class="badge text-bg-light border p-2">Operacoes: <?= count($operacoes) ?></div>
        <div class="badge text-bg-light border p-2">kW injetado: <?= qtdEnergiaOperacao($totais['kw']) ?></div>
        <div class="badge text-bg-light border p-2">Conta com desconto: R$ <?= moedaEnergiaOperacao($totais['conta_desconto']) ?></div>
        <div class="badge text-bg-light border p-2">Pago fornecedor: R$ <?= moedaEnergiaOperacao($totais['fornecedor']) ?></div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Referencia</th>
                        <th>Endereco</th>
                        <th class="text-end">kW injetado</th>
                        <th class="text-end">% desconto</th>
                        <th class="text-end">Conta desconto</th>
                        <th class="text-end">Pago fornecedor</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($operacoes as $op): ?>
                        <tr>
                            <td><?= (int)$op['id'] ?></td>
                            <td><?= htmlspecialchars((string)$op['referencia']) ?></td>
                            <td><?= htmlspecialchars((string)$op['logradouro_complemento']) ?></td>
                            <td class="text-end"><?= qtdEnergiaOperacao((float)$op['quantidade_kw_injetada']) ?></td>
                            <td class="text-end"><?= number_format((float)$op['percentual_desconto_venda'], 4, ',', '.') ?>%</td>
                            <td class="text-end">R$ <?= moedaEnergiaOperacao((float)$op['valor_conta_com_desconto']) ?></td>
                            <td class="text-end">R$ <?= moedaEnergiaOperacao((float)$op['valor_total_pago_fornecedor']) ?></td>
                            <td><span class="badge text-bg-<?= $op['status'] === 'FECHADA' ? 'secondary' : 'success' ?>"><?= htmlspecialchars((string)$op['status']) ?></span></td>
                            <td class="text-end">
                                <a href="operacoes.php?editar=<?= (int)$op['id'] ?>" class="btn btn-sm btn-outline-primary">Abrir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($operacoes)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Nenhuma operacao de energia encontrada.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const conta = document.querySelector('select[name="conta_id"]');
    const quantidade = document.getElementById('quantidade_kw_injetada');
    const desconto = document.getElementById('percentual_desconto_venda');
    const valorFinal = document.getElementById('valor_conta_com_desconto');
    const parsePt = (valor) => {
        valor = String(valor || '').replace(/[^\d,.-]/g, '');
        if (valor.includes(',')) {
            valor = valor.replace(/\./g, '').replace(',', '.');
        }
        return Number.parseFloat(valor) || 0;
    };
    const formatMoeda = (valor) => valor.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    const recalcular = () => {
        if (!conta || !quantidade || !desconto || !valorFinal) {
            return;
        }
        const option = conta.options[conta.selectedIndex];
        const valorKw = parsePt(option ? option.dataset.valorKw : 0);
        const custoDisponibilidade = parsePt(option ? option.dataset.custoDisponibilidade : 0);
        const iluminacao = parsePt(option ? option.dataset.iluminacao : 0);
        const qtd = parsePt(quantidade.value);
        const perc = parsePt(desconto.value);
        const fator = Math.max(0, 1 - (perc / 100));
        valorFinal.value = formatMoeda((qtd * valorKw * fator) + custoDisponibilidade + iluminacao);
    };
    if (conta && quantidade && desconto && valorFinal) {
        conta.addEventListener('change', recalcular);
        quantidade.addEventListener('input', recalcular);
        desconto.addEventListener('input', recalcular);
    }
});
</script>

<?php require '../../layout/footer.php'; ?>
