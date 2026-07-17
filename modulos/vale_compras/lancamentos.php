<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require __DIR__ . '/../movimentacao_baixa/_empresa2_guard.php';
require_once __DIR__ . '/_lib.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$perfil = strtoupper((string)($_SESSION['nivel'] ?? ''));
$permitido = moduloPermitido($pdo, $empresaId, 'vale_compras_operacoes', $perfil);

garantirTabelasValeCompras($pdo);

$mensagem = '';
$erro = '';

function vcLancRedirect(array $params = []): void
{
    header('Location: lancamentos.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

function vcMovimentoValeEmAcerto(PDO $pdo, int $empresaId, ?int $movcontador): bool
{
    if (!$movcontador) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM financeiro_acertos_extrato_itens ai
        INNER JOIN financeiro_acertos_extrato a
            ON a.id = ai.acerto_id
           AND a.status = 'ATIVO'
        WHERE ai.empresa_id = ?
          AND ai.movcontador = ?
    ");
    $stmt->execute([$empresaId, $movcontador]);
    return (int)$stmt->fetchColumn() > 0;
}

function vcDesfazerFinanceiroMovimentoVale(PDO $pdo, int $empresaId, int $usuarioId, array $movimento): void
{
    $movimentoId = (int)$movimento['id'];
    $isCompra = ($movimento['tipo'] ?? '') === 'COMPRA';

    if ($isCompra) {
        $movimentos = array_values(array_filter([
            !empty($movimento['mov_nominal']) ? (int)$movimento['mov_nominal'] : null,
            !empty($movimento['mov_desagio']) ? (int)$movimento['mov_desagio'] : null,
        ]));

        foreach ($movimentos as $movcontador) {
            if (vcMovimentoValeEmAcerto($pdo, $empresaId, $movcontador)) {
                throw new RuntimeException('Compra vinculada a acerto ativo nao pode ser editada ou excluida. Desfaca o acerto antes.');
            }
        }

        if ($movimentos) {
            $placeholders = implode(',', array_fill(0, count($movimentos), '?'));
            $params = array_merge([$usuarioId ?: null, $empresaId, $movimentoId], $movimentos);
            $stmt = $pdo->prepare("
                UPDATE armazem_bnc001
                SET deletado = 'S',
                    data_delecao_firebird = NOW(),
                    motivo_sync = 'VALE_COMPRAS_MOVIMENTO_REABERTO',
                    USERBNCALT = ?,
                    DTALT = NOW(),
                    REGSTAMP = NOW()
                WHERE EMPRESA = ?
                  AND TIPODOCORIGEM = 'VALE_COMPRAS'
                  AND NUMDOCORIGEM = ?
                  AND MOVCONTADOR IN ($placeholders)
                  AND COALESCE(deletado, 'N') <> 'S'
            ");
            $stmt->execute($params);
        }

        $stmt = $pdo->prepare("
            UPDATE vale_compras_movimentos
            SET mov_nominal = NULL,
                mov_desagio = NULL
            WHERE id = ? AND empresa_id = ?
        ");
        $stmt->execute([$movimentoId, $empresaId]);
        return;
    }

    $crcontador = !empty($movimento['crcontador']) ? (int)$movimento['crcontador'] : 0;
    if (!$crcontador) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_cr001
        WHERE EMPRESA = ?
          AND CRCONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $crcontador]);
    $titulo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$titulo) {
        $pdo->prepare("UPDATE vale_compras_movimentos SET crcontador = NULL WHERE id = ? AND empresa_id = ?")
            ->execute([$movimentoId, $empresaId]);
        return;
    }

    if (($titulo['TIPODOCORIGEM'] ?? '') !== 'VALE_COMPRAS' || (int)($titulo['NUMDOCORIGEM'] ?? 0) !== $movimentoId) {
        throw new RuntimeException('CR vinculado nao foi criado por este lancamento do vale e nao pode ser alterado aqui.');
    }

    if (($titulo['STATUS'] ?? '') === 'QT' || (float)($titulo['VLRPAGO'] ?? 0) > 0) {
        throw new RuntimeException('CR ja quitado ou com pagamento parcial nao pode ser editado ou excluido pelo vale.');
    }

    $stmt = $pdo->prepare("
        UPDATE armazem_cr001
        SET excluido_firebird = 'S',
            data_exclusao_firebird = NOW(),
            motivo_sync = 'VALE_COMPRAS_MOVIMENTO_REABERTO',
            USERALT = ?,
            DTALT = NOW(),
            REGSTAMP = NOW()
        WHERE EMPRESA = ?
          AND CRCONTADOR = ?
    ");
    $stmt->execute([$usuarioId ?: null, $empresaId, $crcontador]);

    $pdo->prepare("UPDATE vale_compras_movimentos SET crcontador = NULL WHERE id = ? AND empresa_id = ?")
        ->execute([$movimentoId, $empresaId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $permitido && $empresaId === 2) {
    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'adicionar_compra') {
            $valeId = (int)($_POST['vale_id'] ?? 0);
            $fornecedorId = (int)($_POST['fornecedor_id'] ?? 0);
            $dataMovimento = trim((string)($_POST['data_movimento'] ?? date('Y-m-d')));
            $valorNominal = vcFloat($_POST['valor_nominal'] ?? '0');
            $taxaDesconto = vcFloat($_POST['taxa_desconto'] ?? '0');
            $valorLiquido = vcFloat($_POST['valor_liquido'] ?? '0');
            $valorPago = vcFloat($_POST['valor_pago'] ?? '0');
            $valorDesagio = vcFloat($_POST['valor_desagio'] ?? '0');

            $pdo->beginTransaction();
            $stmtVale = $pdo->prepare("SELECT * FROM vale_compras_vales WHERE id = ? AND empresa_id = ? FOR UPDATE");
            $stmtVale->execute([$valeId, $empresaId]);
            $vale = $stmtVale->fetch(PDO::FETCH_ASSOC);
            if (!$vale) {
                throw new RuntimeException('Vale-compra nao encontrado.');
            }
            if (($vale['status'] ?? '') === 'ENCERRADO') {
                throw new RuntimeException('Vale-compra encerrado nao recebe novas compras.');
            }
            if (!vcBuscarFornecedor($pdo, $empresaId, $fornecedorId)) {
                throw new RuntimeException('Informe um fornecedor valido.');
            }
            if ($valorNominal <= 0) {
                throw new RuntimeException('Informe o valor do vale maior que zero.');
            }
            if ($taxaDesconto < 0) {
                throw new RuntimeException('A taxa de desconto nao pode ser negativa.');
            }
            if ($valorDesagio <= 0 && $taxaDesconto > 0) {
                $valorDesagio = round($valorNominal * ($taxaDesconto / 100), 2);
            }
            if ($valorLiquido <= 0) {
                $valorLiquido = round($valorNominal - $valorDesagio, 2);
            }
            if ($valorDesagio <= 0) {
                $valorDesagio = round($valorNominal - $valorLiquido, 2);
            }
            if ($taxaDesconto <= 0 && $valorNominal > 0 && $valorDesagio > 0) {
                $taxaDesconto = round(($valorDesagio / $valorNominal) * 100, 4);
            }
            if ($valorPago <= 0) {
                $valorPago = $valorLiquido;
            }
            if ($valorPago > $valorNominal) {
                throw new RuntimeException('O valor pago nao pode ser maior que o valor do vale.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO vale_compras_movimentos (
                    vale_id, empresa_id, tipo, data_movimento, fornecedor_id,
                    valor_nominal, taxa_desconto, valor_liquido, valor_pago, valor_desagio, criado_por
                ) VALUES (?, ?, 'COMPRA', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$valeId, $empresaId, $dataMovimento, $fornecedorId, $valorNominal, $taxaDesconto, $valorLiquido, $valorPago, $valorDesagio, $usuarioId ?: null]);
            $pdo->commit();
            vcLancRedirect(['vale' => $valeId, 'ok' => 'compra']);
        }

        if ($acao === 'adicionar_venda') {
            $valeId = (int)($_POST['vale_id'] ?? 0);
            $clienteId = (int)($_POST['cliente_id'] ?? 0);
            $estabelecimento = trim((string)($_POST['estabelecimento'] ?? ''));
            $dataMovimento = trim((string)($_POST['data_movimento'] ?? date('Y-m-d')));
            $valor = vcFloat($_POST['valor'] ?? '0');

            $pdo->beginTransaction();
            $stmtVale = $pdo->prepare("SELECT * FROM vale_compras_vales WHERE id = ? AND empresa_id = ? FOR UPDATE");
            $stmtVale->execute([$valeId, $empresaId]);
            $vale = $stmtVale->fetch(PDO::FETCH_ASSOC);
            if (!$vale) {
                throw new RuntimeException('Vale-compra nao encontrado.');
            }
            if (($vale['status'] ?? '') === 'ENCERRADO') {
                throw new RuntimeException('Vale-compra encerrado nao recebe novas vendas.');
            }
            if (!vcBuscarCliente($pdo, $empresaId, $clienteId)) {
                throw new RuntimeException('Informe um cliente valido.');
            }
            if ($estabelecimento === '') {
                throw new RuntimeException('Informe o estabelecimento.');
            }
            $estabelecimento = vcRegistrarEstabelecimento($pdo, $empresaId, $estabelecimento);
            if ($valor <= 0) {
                throw new RuntimeException('Informe valor maior que zero.');
            }

            $resumo = vcResumoVale($pdo, $valeId);
            if ($valor > ((float)$resumo['saldo'] + 0.001)) {
                throw new RuntimeException('Valor da venda maior que o saldo disponivel deste vale.');
            }

            $vencimento = vcCalcularVencimento($dataMovimento);
            $stmt = $pdo->prepare("
                INSERT INTO vale_compras_movimentos (
                    vale_id, empresa_id, tipo, data_movimento, cliente_id, estabelecimento, valor, vencimento, criado_por
                ) VALUES (?, ?, 'VENDA', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$valeId, $empresaId, $dataMovimento, $clienteId, $estabelecimento, $valor, $vencimento, $usuarioId ?: null]);
            $pdo->commit();
            vcLancRedirect(['vale' => $valeId, 'ok' => 'venda']);
        }

        if ($acao === 'lancar_compra') {
            $movimentoId = (int)($_POST['movimento_id'] ?? 0);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                SELECT m.*, v.identificacao, f.NOME AS fornecedor_nome, f.APELIDO AS fornecedor_apelido
                FROM vale_compras_movimentos m
                INNER JOIN vale_compras_vales v ON v.id = m.vale_id AND v.empresa_id = m.empresa_id
                INNER JOIN armazem_cp003 f ON f.EMPRESA = m.empresa_id AND f.FCONTADOR = m.fornecedor_id
                WHERE m.id = ? AND m.empresa_id = ? AND m.tipo = 'COMPRA'
                FOR UPDATE
            ");
            $stmt->execute([$movimentoId, $empresaId]);
            $movimento = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$movimento) {
                throw new RuntimeException('Compra do vale nao encontrada.');
            }
            if (!empty($movimento['mov_nominal']) || !empty($movimento['mov_desagio'])) {
                throw new RuntimeException('Compra do vale ja foi lancada no financeiro.');
            }

            $fornecedorNome = trim((string)($movimento['fornecedor_apelido'] ?: $movimento['fornecedor_nome']));
            $movNominal = vcGerarMovimentoCompra($pdo, $empresaId, $usuarioId, $movimentoId, (int)$movimento['vale_id'], (int)$movimento['fornecedor_id'], $movimento['data_movimento'], 'D', 199, (float)$movimento['valor_nominal'], 'VALE-COMPRAS #' . (int)$movimento['vale_id'] . ' - VALOR DO VALE - ' . $fornecedorNome);
            $movDesagio = vcGerarMovimentoCompra($pdo, $empresaId, $usuarioId, $movimentoId, (int)$movimento['vale_id'], (int)$movimento['fornecedor_id'], $movimento['data_movimento'], 'C', 7, (float)$movimento['valor_desagio'], 'VALE-COMPRAS #' . (int)$movimento['vale_id'] . ' - DESCONTO - ' . $fornecedorNome);

            $stmt = $pdo->prepare("UPDATE vale_compras_movimentos SET mov_nominal = ?, mov_desagio = ? WHERE id = ? AND empresa_id = ?");
            $stmt->execute([$movNominal, $movDesagio, $movimentoId, $empresaId]);
            $pdo->commit();
            vcLancRedirect(['vale' => (int)$movimento['vale_id'], 'ok' => 'financeiro']);
        }

        if ($acao === 'lancar_venda') {
            $movimentoId = (int)($_POST['movimento_id'] ?? 0);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                SELECT m.*, v.identificacao, v.id AS vale_id,
                       compra.fornecedor_id, f.NOME AS fornecedor_nome, f.APELIDO AS fornecedor_apelido,
                       c.NOME AS cliente_nome, c.APELIDO AS cliente_apelido
                FROM vale_compras_movimentos m
                INNER JOIN vale_compras_vales v ON v.id = m.vale_id AND v.empresa_id = m.empresa_id
                LEFT JOIN vale_compras_movimentos compra ON compra.vale_id = v.id AND compra.empresa_id = v.empresa_id AND compra.tipo = 'COMPRA'
                LEFT JOIN armazem_cp003 f ON f.EMPRESA = m.empresa_id AND f.FCONTADOR = compra.fornecedor_id
                INNER JOIN armazem_cr002 c ON c.EMPRESA = m.empresa_id AND c.CLICONTADOR = m.cliente_id
                WHERE m.id = ? AND m.empresa_id = ? AND m.tipo = 'VENDA'
                ORDER BY compra.id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$movimentoId, $empresaId]);
            $movimento = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$movimento) {
                throw new RuntimeException('Venda do vale nao encontrada.');
            }
            if (!empty($movimento['crcontador'])) {
                throw new RuntimeException('Venda ja lancada no contas a receber.');
            }

            $vale = ['id' => (int)$movimento['vale_id'], 'identificacao' => $movimento['identificacao']];
            $fornecedorNome = trim((string)($movimento['fornecedor_apelido'] ?: $movimento['fornecedor_nome'] ?: $movimento['identificacao']));
            $clienteNome = trim((string)($movimento['cliente_apelido'] ?: $movimento['cliente_nome']));
            vcGerarTituloReceber($pdo, $empresaId, $usuarioId, $vale, $movimento, $fornecedorNome, $clienteNome);
            $pdo->commit();
            vcLancRedirect(['vale' => (int)$movimento['vale_id'], 'ok' => 'cr']);
        }

        if ($acao === 'salvar_movimento') {
            $movimentoId = (int)($_POST['movimento_id'] ?? 0);
            $valeId = (int)($_POST['vale_id'] ?? 0);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM vale_compras_movimentos WHERE id = ? AND empresa_id = ? AND vale_id = ? FOR UPDATE");
            $stmt->execute([$movimentoId, $empresaId, $valeId]);
            $movimento = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$movimento) {
                throw new RuntimeException('Lancamento do vale nao encontrado.');
            }
            $isCompra = $movimento['tipo'] === 'COMPRA';
            vcDesfazerFinanceiroMovimentoVale($pdo, $empresaId, $usuarioId, $movimento);

            if ($isCompra) {
                $fornecedorId = (int)($_POST['fornecedor_id'] ?? 0);
                $dataMovimento = trim((string)($_POST['data_movimento'] ?? date('Y-m-d')));
                $valorNominal = vcFloat($_POST['valor_nominal'] ?? '0');
                $taxaDesconto = vcFloat($_POST['taxa_desconto'] ?? '0');
                $valorLiquido = vcFloat($_POST['valor_liquido'] ?? '0');
                $valorPago = vcFloat($_POST['valor_pago'] ?? '0');
                $valorDesagio = vcFloat($_POST['valor_desagio'] ?? '0');

                if (!vcBuscarFornecedor($pdo, $empresaId, $fornecedorId)) {
                    throw new RuntimeException('Informe um fornecedor valido.');
                }
                if ($valorNominal <= 0) {
                    throw new RuntimeException('Informe o valor do vale maior que zero.');
                }
                if ($taxaDesconto < 0) {
                    throw new RuntimeException('A taxa de desconto nao pode ser negativa.');
                }
                if ($valorDesagio <= 0 && $taxaDesconto > 0) {
                    $valorDesagio = round($valorNominal * ($taxaDesconto / 100), 2);
                }
                if ($valorLiquido <= 0) {
                    $valorLiquido = round($valorNominal - $valorDesagio, 2);
                }
                if ($valorDesagio <= 0) {
                    $valorDesagio = round($valorNominal - $valorLiquido, 2);
                }
                if ($taxaDesconto <= 0 && $valorNominal > 0 && $valorDesagio > 0) {
                    $taxaDesconto = round(($valorDesagio / $valorNominal) * 100, 4);
                }
                if ($valorPago <= 0) {
                    $valorPago = $valorLiquido;
                }
                if ($valorPago > $valorNominal) {
                    throw new RuntimeException('O valor pago nao pode ser maior que o valor do vale.');
                }

                $stmt = $pdo->prepare("
                    UPDATE vale_compras_movimentos
                    SET data_movimento = ?, fornecedor_id = ?, valor_nominal = ?, taxa_desconto = ?,
                        valor_liquido = ?, valor_pago = ?, valor_desagio = ?
                    WHERE id = ? AND empresa_id = ? AND vale_id = ? AND tipo = 'COMPRA'
                ");
                $stmt->execute([$dataMovimento, $fornecedorId, $valorNominal, $taxaDesconto, $valorLiquido, $valorPago, $valorDesagio, $movimentoId, $empresaId, $valeId]);
            } else {
                $clienteId = (int)($_POST['cliente_id'] ?? 0);
                $estabelecimento = trim((string)($_POST['estabelecimento'] ?? ''));
                $dataMovimento = trim((string)($_POST['data_movimento'] ?? date('Y-m-d')));
                $valor = vcFloat($_POST['valor'] ?? '0');

                if (!vcBuscarCliente($pdo, $empresaId, $clienteId)) {
                    throw new RuntimeException('Informe um cliente valido.');
                }
                if ($estabelecimento === '') {
                    throw new RuntimeException('Informe o estabelecimento.');
                }
                $estabelecimento = vcRegistrarEstabelecimento($pdo, $empresaId, $estabelecimento);
                if ($valor <= 0) {
                    throw new RuntimeException('Informe valor maior que zero.');
                }
                $resumo = vcResumoVale($pdo, $valeId);
                $saldoDisponivel = (float)$resumo['saldo'] + (float)$movimento['valor'];
                if ($valor > ($saldoDisponivel + 0.001)) {
                    throw new RuntimeException('Valor da venda maior que o saldo disponivel deste vale.');
                }

                $vencimento = vcCalcularVencimento($dataMovimento);
                $stmt = $pdo->prepare("
                    UPDATE vale_compras_movimentos
                    SET data_movimento = ?, cliente_id = ?, estabelecimento = ?, valor = ?, vencimento = ?
                    WHERE id = ? AND empresa_id = ? AND vale_id = ? AND tipo = 'VENDA'
                ");
                $stmt->execute([$dataMovimento, $clienteId, $estabelecimento, $valor, $vencimento, $movimentoId, $empresaId, $valeId]);
            }
            $pdo->commit();
            vcLancRedirect(['vale' => $valeId, 'ok' => 'editado']);
        }

        if ($acao === 'excluir_movimento') {
            $movimentoId = (int)($_POST['movimento_id'] ?? 0);
            $valeId = (int)($_POST['vale_id'] ?? 0);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM vale_compras_movimentos WHERE id = ? AND empresa_id = ? AND vale_id = ? FOR UPDATE");
            $stmt->execute([$movimentoId, $empresaId, $valeId]);
            $movimento = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$movimento) {
                throw new RuntimeException('Lancamento do vale nao encontrado.');
            }
            vcDesfazerFinanceiroMovimentoVale($pdo, $empresaId, $usuarioId, $movimento);
            $stmt = $pdo->prepare("DELETE FROM vale_compras_movimentos WHERE id = ? AND empresa_id = ? AND vale_id = ?");
            $stmt->execute([$movimentoId, $empresaId, $valeId]);
            $pdo->commit();
            vcLancRedirect(['vale' => $valeId, 'ok' => 'excluido']);
        }

        if ($acao === 'lancar_compras_lote') {
            $valeId = (int)($_POST['vale_id'] ?? 0);
            $movimentoIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['movimentos'] ?? [])))));
            if (!$movimentoIds) {
                throw new RuntimeException('Selecione ao menos uma compra pendente para lancar.');
            }

            $gerados = 0;
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                SELECT m.*, v.identificacao, f.NOME AS fornecedor_nome, f.APELIDO AS fornecedor_apelido
                FROM vale_compras_movimentos m
                INNER JOIN vale_compras_vales v ON v.id = m.vale_id AND v.empresa_id = m.empresa_id
                INNER JOIN armazem_cp003 f ON f.EMPRESA = m.empresa_id AND f.FCONTADOR = m.fornecedor_id
                WHERE m.id = ? AND m.empresa_id = ? AND m.vale_id = ? AND m.tipo = 'COMPRA'
                FOR UPDATE
            ");
            $stmtUpdate = $pdo->prepare("UPDATE vale_compras_movimentos SET mov_nominal = ?, mov_desagio = ? WHERE id = ? AND empresa_id = ?");
            foreach ($movimentoIds as $movimentoId) {
                $stmt->execute([$movimentoId, $empresaId, $valeId]);
                $movimento = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$movimento || !empty($movimento['mov_nominal']) || !empty($movimento['mov_desagio'])) {
                    continue;
                }

                $fornecedorNome = trim((string)($movimento['fornecedor_apelido'] ?: $movimento['fornecedor_nome']));
                $movNominal = vcGerarMovimentoCompra($pdo, $empresaId, $usuarioId, $movimentoId, (int)$movimento['vale_id'], (int)$movimento['fornecedor_id'], $movimento['data_movimento'], 'D', 199, (float)$movimento['valor_nominal'], 'VALE-COMPRAS #' . (int)$movimento['vale_id'] . ' - VALOR DO VALE - ' . $fornecedorNome);
                $movDesagio = vcGerarMovimentoCompra($pdo, $empresaId, $usuarioId, $movimentoId, (int)$movimento['vale_id'], (int)$movimento['fornecedor_id'], $movimento['data_movimento'], 'C', 7, (float)$movimento['valor_desagio'], 'VALE-COMPRAS #' . (int)$movimento['vale_id'] . ' - DESCONTO - ' . $fornecedorNome);
                $stmtUpdate->execute([$movNominal, $movDesagio, $movimentoId, $empresaId]);
                $gerados++;
            }
            if ($gerados === 0) {
                throw new RuntimeException('Nenhuma compra pendente foi lancada.');
            }
            $pdo->commit();
            vcLancRedirect(['vale' => $valeId, 'ok' => 'lote_compra', 'qtd' => $gerados]);
        }

        if ($acao === 'lancar_vendas_lote') {
            $valeId = (int)($_POST['vale_id'] ?? 0);
            $movimentoIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['movimentos'] ?? [])))));
            if (!$movimentoIds) {
                throw new RuntimeException('Selecione ao menos uma venda pendente para lancar no contas a receber.');
            }

            $gerados = 0;
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                SELECT m.*, v.identificacao, v.id AS vale_id,
                       compra.fornecedor_id, f.NOME AS fornecedor_nome, f.APELIDO AS fornecedor_apelido,
                       c.NOME AS cliente_nome, c.APELIDO AS cliente_apelido
                FROM vale_compras_movimentos m
                INNER JOIN vale_compras_vales v ON v.id = m.vale_id AND v.empresa_id = m.empresa_id
                LEFT JOIN vale_compras_movimentos compra ON compra.vale_id = v.id AND compra.empresa_id = v.empresa_id AND compra.tipo = 'COMPRA'
                LEFT JOIN armazem_cp003 f ON f.EMPRESA = m.empresa_id AND f.FCONTADOR = compra.fornecedor_id
                INNER JOIN armazem_cr002 c ON c.EMPRESA = m.empresa_id AND c.CLICONTADOR = m.cliente_id
                WHERE m.id = ? AND m.empresa_id = ? AND m.vale_id = ? AND m.tipo = 'VENDA'
                ORDER BY compra.id
                LIMIT 1
                FOR UPDATE
            ");
            foreach ($movimentoIds as $movimentoId) {
                $stmt->execute([$movimentoId, $empresaId, $valeId]);
                $movimento = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$movimento || !empty($movimento['crcontador'])) {
                    continue;
                }

                $vale = ['id' => (int)$movimento['vale_id'], 'identificacao' => $movimento['identificacao']];
                $fornecedorNome = trim((string)($movimento['fornecedor_apelido'] ?: $movimento['fornecedor_nome'] ?: $movimento['identificacao']));
                $clienteNome = trim((string)($movimento['cliente_apelido'] ?: $movimento['cliente_nome']));
                vcGerarTituloReceber($pdo, $empresaId, $usuarioId, $vale, $movimento, $fornecedorNome, $clienteNome);
                $gerados++;
            }
            if ($gerados === 0) {
                throw new RuntimeException('Nenhuma venda pendente foi lancada no contas a receber.');
            }
            $pdo->commit();
            vcLancRedirect(['vale' => $valeId, 'ok' => 'lote_cr', 'qtd' => $gerados]);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $erro = $e->getMessage();
    }
}

$ok = $_GET['ok'] ?? '';
if ($ok === 'compra') {
    $mensagem = 'Compra registrada dentro do vale.';
} elseif ($ok === 'venda') {
    $mensagem = 'Venda registrada dentro do vale.';
} elseif ($ok === 'financeiro') {
    $mensagem = 'Compra do vale lancada no caixa/banco.';
} elseif ($ok === 'cr') {
    $mensagem = 'Venda lancada no contas a receber.';
} elseif ($ok === 'lote_compra') {
    $mensagem = 'Compras selecionadas lancadas no caixa/banco: ' . (int)($_GET['qtd'] ?? 0) . '.';
} elseif ($ok === 'lote_cr') {
    $mensagem = 'Vendas selecionadas lancadas no contas a receber: ' . (int)($_GET['qtd'] ?? 0) . '.';
} elseif ($ok === 'editado') {
    $mensagem = 'Lancamento do vale alterado com sucesso.';
} elseif ($ok === 'excluido') {
    $mensagem = 'Lancamento do vale excluido.';
}

$fornecedores = [];
$clientes = [];
$estabelecimentos = [];
$valeAtual = null;
$movimentoEditar = null;
$movimentos = [];
$resumoAtual = ['total_compras' => 0, 'total_vendas' => 0, 'saldo' => 0, 'compras_pendentes' => 0, 'vendas_pendentes' => 0];
$filtrosExtrato = [
    'tipo' => trim((string)($_GET['tipo'] ?? '')),
    'data_ini' => trim((string)($_GET['data_ini'] ?? '')),
    'data_fim' => trim((string)($_GET['data_fim'] ?? '')),
    'pessoa' => trim((string)($_GET['pessoa'] ?? '')),
    'estabelecimento' => trim((string)($_GET['estabelecimento'] ?? '')),
    'valor_min' => trim((string)($_GET['valor_min'] ?? '')),
    'valor_max' => trim((string)($_GET['valor_max'] ?? '')),
    'venc_ini' => trim((string)($_GET['venc_ini'] ?? '')),
    'venc_fim' => trim((string)($_GET['venc_fim'] ?? '')),
    'financeiro' => trim((string)($_GET['financeiro'] ?? '')),
];

if ($permitido && $empresaId === 2) {
    $stmt = $pdo->prepare("
        SELECT FCONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Fornecedor ', FCONTADOR)) AS nome
        FROM armazem_cp003
        WHERE EMPRESA = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(INATIVO, 'N') <> 'S'
          AND COALESCE(REGDISAB, 'N') <> 'S'
        ORDER BY nome, FCONTADOR
    ");
    $stmt->execute([$empresaId]);
    $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT CLICONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Cliente ', CLICONTADOR)) AS nome
        FROM armazem_cr002
        WHERE EMPRESA = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(INATIVO, 'N') <> 'S'
        ORDER BY nome, CLICONTADOR
    ");
    $stmt->execute([$empresaId]);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT nome
        FROM vale_compras_estabelecimentos
        WHERE empresa_id = ?
        ORDER BY COALESCE(ultimo_uso, criado_em) DESC, nome
        LIMIT 300
    ");
    $stmt->execute([$empresaId]);
    $estabelecimentos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $valeId = (int)($_GET['vale'] ?? 0);
    if ($valeId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM vale_compras_vales WHERE id = ? AND empresa_id = ?");
        $stmt->execute([$valeId, $empresaId]);
        $valeAtual = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($valeAtual) {
            $resumoAtual = vcResumoVale($pdo, $valeId);
            $movWhere = ["m.vale_id = ?", "m.empresa_id = ?"];
            $movParams = [$valeId, $empresaId];
            if (in_array($filtrosExtrato['tipo'], ['COMPRA', 'VENDA'], true)) {
                $movWhere[] = "m.tipo = ?";
                $movParams[] = $filtrosExtrato['tipo'];
            }
            if ($filtrosExtrato['data_ini'] !== '') {
                $movWhere[] = "m.data_movimento >= ?";
                $movParams[] = $filtrosExtrato['data_ini'];
            }
            if ($filtrosExtrato['data_fim'] !== '') {
                $movWhere[] = "m.data_movimento <= ?";
                $movParams[] = $filtrosExtrato['data_fim'];
            }
            if ($filtrosExtrato['pessoa'] !== '') {
                $movWhere[] = "(
                    f.NOME LIKE ? OR f.APELIDO LIKE ? OR f.FCONTADOR = ?
                    OR c.NOME LIKE ? OR c.APELIDO LIKE ? OR c.CLICONTADOR = ?
                )";
                $likePessoa = '%' . $filtrosExtrato['pessoa'] . '%';
                $codigoPessoa = ctype_digit($filtrosExtrato['pessoa']) ? (int)$filtrosExtrato['pessoa'] : 0;
                array_push($movParams, $likePessoa, $likePessoa, $codigoPessoa, $likePessoa, $likePessoa, $codigoPessoa);
            }
            if ($filtrosExtrato['estabelecimento'] !== '') {
                $movWhere[] = "m.estabelecimento LIKE ?";
                $movParams[] = '%' . $filtrosExtrato['estabelecimento'] . '%';
            }
            if ($filtrosExtrato['valor_min'] !== '') {
                $movWhere[] = "(CASE WHEN m.tipo = 'COMPRA' THEN m.valor_nominal ELSE m.valor END) >= ?";
                $movParams[] = vcFloat($filtrosExtrato['valor_min']);
            }
            if ($filtrosExtrato['valor_max'] !== '') {
                $movWhere[] = "(CASE WHEN m.tipo = 'COMPRA' THEN m.valor_nominal ELSE m.valor END) <= ?";
                $movParams[] = vcFloat($filtrosExtrato['valor_max']);
            }
            if ($filtrosExtrato['venc_ini'] !== '') {
                $movWhere[] = "m.vencimento >= ?";
                $movParams[] = $filtrosExtrato['venc_ini'];
            }
            if ($filtrosExtrato['venc_fim'] !== '') {
                $movWhere[] = "m.vencimento <= ?";
                $movParams[] = $filtrosExtrato['venc_fim'];
            }
            if ($filtrosExtrato['financeiro'] === 'pendente') {
                $movWhere[] = "(
                    (m.tipo = 'COMPRA' AND m.mov_nominal IS NULL AND m.mov_desagio IS NULL)
                    OR (m.tipo = 'VENDA' AND m.crcontador IS NULL)
                )";
            } elseif ($filtrosExtrato['financeiro'] === 'lancado') {
                $movWhere[] = "(
                    (m.tipo = 'COMPRA' AND (m.mov_nominal IS NOT NULL OR m.mov_desagio IS NOT NULL))
                    OR (m.tipo = 'VENDA' AND m.crcontador IS NOT NULL)
                )";
            }
            $stmt = $pdo->prepare("
                SELECT m.*,
                       f.NOME AS fornecedor_nome, f.APELIDO AS fornecedor_apelido,
                       c.NOME AS cliente_nome, c.APELIDO AS cliente_apelido
                FROM vale_compras_movimentos m
                LEFT JOIN armazem_cp003 f ON f.EMPRESA = m.empresa_id AND f.FCONTADOR = m.fornecedor_id
                LEFT JOIN armazem_cr002 c ON c.EMPRESA = m.empresa_id AND c.CLICONTADOR = m.cliente_id
                WHERE " . implode(' AND ', $movWhere) . "
                ORDER BY m.data_movimento, m.id
            ");
            $stmt->execute($movParams);
            $movimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $editarMovimentoId = (int)($_GET['editar_movimento'] ?? 0);
            if ($editarMovimentoId > 0) {
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM vale_compras_movimentos
                    WHERE id = ? AND empresa_id = ? AND vale_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$editarMovimentoId, $empresaId, $valeId]);
                $movimentoEditar = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
    }
}

require '../../layout/header.php';
?>

<style>
.vc-wrap { max-width: 1240px; margin: 0 auto; }
.vc-hero { background:#123c69; color:#fff; border-radius:8px; padding:24px; display:flex; justify-content:space-between; gap:16px; align-items:center; }
.vc-card { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:18px; margin-top:16px; }
.vc-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; }
.vc-field label { font-size:12px; font-weight:700; color:#495057; margin-bottom:4px; display:block; }
.vc-field input, .vc-field select { width:100%; border:1px solid #ced4da; border-radius:6px; padding:9px 10px; }
.vc-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.vc-filter { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px; align-items:end; margin-bottom:14px; }
.vc-table { width:100%; border-collapse:collapse; font-size:12px; table-layout:fixed; }
.vc-table th, .vc-table td { border-bottom:1px solid #e9ecef; padding:7px 6px; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; }
.vc-table th { background:#f1f5f9; font-size:11px; text-transform:uppercase; color:#334155; white-space:nowrap; }
.vc-table th:nth-child(1), .vc-table td:nth-child(1) { width:36px; text-align:center; }
.vc-table th:nth-child(2), .vc-table td:nth-child(2) { width:54px; }
.vc-table th:nth-child(3), .vc-table td:nth-child(3) { width:70px; }
.vc-table th:nth-child(4), .vc-table td:nth-child(4) { width:82px; }
.vc-table th:nth-child(5), .vc-table td:nth-child(5) { width:150px; }
.vc-table th:nth-child(6), .vc-table td:nth-child(6) { width:120px; }
.vc-table th:nth-child(7), .vc-table td:nth-child(7) { width:96px; text-align:left; }
.vc-table th:nth-child(8), .vc-table td:nth-child(8) { width:92px; }
.vc-table th:nth-child(9), .vc-table td:nth-child(9) { width:86px; text-align:left; }
.vc-table th:nth-child(10), .vc-table td:nth-child(10) { width:176px; text-align:left; white-space:nowrap; padding-right:10px; }
.vc-cell-money { text-align:left; white-space:nowrap; }
.vc-kpi { border:1px solid #e2e8f0; border-radius:8px; padding:14px; background:#f8fafc; }
.vc-kpi small { color:#64748b; display:block; font-weight:700; text-transform:uppercase; font-size:11px; }
.vc-kpi strong { font-size:20px; }
.vc-section-title { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
.vc-row-actions { display:flex; justify-content:flex-start; gap:6px; align-items:center; flex-wrap:nowrap; width:100%; }
.vc-row-actions form { display:inline; margin:0; }
.vc-row-actions .btn { min-width:44px; padding:3px 7px; line-height:1.25; font-size:11px; }
@media (max-width: 900px) { .vc-hero { display:block; } .vc-grid, .vc-filter { grid-template-columns:1fr; } .vc-scroll { overflow-x:visible; } }
</style>

<div class="vc-wrap">
    <section class="vc-hero">
        <div>
            <span class="badge text-bg-light mb-2">Vale-Compras</span>
            <h1 class="h4 fw-bold mb-1">Lançamentos do Vale</h1>
            <p class="mb-0 opacity-75">Registre compras e vendas somente no vale selecionado.</p>
        </div>
        <div class="vc-actions">
            <a href="cadastro.php" class="btn btn-outline-light">Voltar</a>
            <a href="cadastro.php" class="btn btn-warning">Cadastro de vales</a>
        </div>
    </section>

    <?php if (!$permitido): ?>
        <div class="alert alert-danger mt-3">Seu usuario nao possui permissao para acessar esta rotina.</div>
    <?php else: ?>
        <?php if ($mensagem): ?><div class="alert alert-success mt-3"><?= vcH($mensagem) ?></div><?php endif; ?>
        <?php if ($erro): ?><div class="alert alert-danger mt-3"><?= vcH($erro) ?></div><?php endif; ?>

        <datalist id="vcEstabelecimentos">
            <?php foreach ($estabelecimentos as $estabelecimentoOpcao): ?>
                <option value="<?= vcH($estabelecimentoOpcao) ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <?php if ($valeAtual): ?>
            <section class="vc-card">
                <div class="vc-section-title">
                    <div>
                        <h2 class="h6 fw-bold mb-1">Vale #<?= (int)$valeAtual['id'] ?> - <?= vcH($valeAtual['identificacao']) ?></h2>
                        <small class="text-muted">O saldo é formado pelas compras menos as vendas.</small>
                    </div>
                    <a class="btn btn-sm btn-outline-secondary" href="cadastro.php?editar=<?= (int)$valeAtual['id'] ?>">Editar cadastro</a>
                </div>
                <div class="row g-3">
                    <div class="col-md-3"><div class="vc-kpi"><small>Compras</small><strong><?= vcMoeda($resumoAtual['total_compras']) ?></strong></div></div>
                    <div class="col-md-3"><div class="vc-kpi"><small>Vendas</small><strong><?= vcMoeda($resumoAtual['total_vendas']) ?></strong></div></div>
                    <div class="col-md-3"><div class="vc-kpi"><small>Saldo</small><strong><?= vcMoeda($resumoAtual['saldo']) ?></strong></div></div>
                    <div class="col-md-3"><div class="vc-kpi"><small>Pendências</small><strong><?= (int)$resumoAtual['compras_pendentes'] + (int)$resumoAtual['vendas_pendentes'] ?></strong></div></div>
                </div>
            </section>

            <?php if ($movimentoEditar): ?>
                <?php $editandoCompra = $movimentoEditar['tipo'] === 'COMPRA'; ?>
                <section class="vc-card">
                    <div class="vc-section-title">
                        <h2 class="h6 fw-bold mb-0">Editar lançamento #<?= (int)$movimentoEditar['id'] ?> - <?= vcH($movimentoEditar['tipo']) ?></h2>
                        <a class="btn btn-sm btn-outline-secondary" href="lancamentos.php?vale=<?= (int)$valeAtual['id'] ?>">Cancelar edição</a>
                    </div>
                    <form method="post">
                        <input type="hidden" name="acao" value="salvar_movimento">
                        <input type="hidden" name="vale_id" value="<?= (int)$valeAtual['id'] ?>">
                        <input type="hidden" name="movimento_id" value="<?= (int)$movimentoEditar['id'] ?>">
                        <?php if ($editandoCompra): ?>
                            <div class="vc-grid">
                                <div class="vc-field">
                                    <label>Fornecedor</label>
                                    <select name="fornecedor_id" required>
                                        <option value="">Selecione</option>
                                        <?php foreach ($fornecedores as $fornecedor): ?>
                                            <option value="<?= (int)$fornecedor['FCONTADOR'] ?>" <?= (int)$movimentoEditar['fornecedor_id'] === (int)$fornecedor['FCONTADOR'] ? 'selected' : '' ?>><?= vcH($fornecedor['nome'] . ' (' . $fornecedor['FCONTADOR'] . ')') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="vc-field">
                                    <label>Data da compra</label>
                                    <input type="date" name="data_movimento" required value="<?= vcH($movimentoEditar['data_movimento']) ?>">
                                </div>
                                <div class="vc-field">
                                    <label>Valor do Vale</label>
                                    <input type="text" name="valor_nominal" inputmode="decimal" required value="<?= vcH(number_format((float)$movimentoEditar['valor_nominal'], 2, ',', '.')) ?>">
                                </div>
                                <div class="vc-field">
                                    <label>Taxa de desconto (%)</label>
                                    <input type="text" name="taxa_desconto" inputmode="decimal" value="<?= vcH(number_format((float)$movimentoEditar['taxa_desconto'], 4, ',', '.')) ?>">
                                </div>
                                <div class="vc-field">
                                    <label>Valor Líquido</label>
                                    <input type="text" name="valor_liquido" inputmode="decimal" value="<?= vcH(number_format((float)$movimentoEditar['valor_liquido'], 2, ',', '.')) ?>">
                                </div>
                                <div class="vc-field">
                                    <label>Valor Pago</label>
                                    <input type="text" name="valor_pago" inputmode="decimal" value="<?= vcH(number_format((float)$movimentoEditar['valor_pago'], 2, ',', '.')) ?>">
                                </div>
                                <div class="vc-field">
                                    <label>Valor do desconto</label>
                                    <input type="text" name="valor_desagio" inputmode="decimal" value="<?= vcH(number_format((float)$movimentoEditar['valor_desagio'], 2, ',', '.')) ?>">
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="vc-grid">
                                <div class="vc-field">
                                    <label>Cliente</label>
                                    <select name="cliente_id" required>
                                        <option value="">Selecione</option>
                                        <?php foreach ($clientes as $cliente): ?>
                                            <option value="<?= (int)$cliente['CLICONTADOR'] ?>" <?= (int)$movimentoEditar['cliente_id'] === (int)$cliente['CLICONTADOR'] ? 'selected' : '' ?>><?= vcH($cliente['nome'] . ' (' . $cliente['CLICONTADOR'] . ')') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="vc-field">
                                    <label>Estabelecimento</label>
                                    <input type="text" name="estabelecimento" required maxlength="180" list="vcEstabelecimentos" value="<?= vcH($movimentoEditar['estabelecimento'] ?? '') ?>" placeholder="Digite para buscar no historico">
                                </div>
                                <div class="vc-field">
                                    <label>Data da venda</label>
                                    <input type="date" name="data_movimento" required value="<?= vcH($movimentoEditar['data_movimento']) ?>">
                                </div>
                                <div class="vc-field">
                                    <label>Valor usado</label>
                                    <input type="text" name="valor" inputmode="decimal" required value="<?= vcH(number_format((float)$movimentoEditar['valor'], 2, ',', '.')) ?>">
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="vc-actions mt-3">
                            <button class="btn btn-primary">Salvar alteração</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <section class="vc-card">
                <div class="vc-section-title">
                    <h2 class="h6 fw-bold mb-0">Registrar compra</h2>
                    <small class="text-muted">Entrada de saldo</small>
                </div>
                <form method="post">
                    <input type="hidden" name="acao" value="adicionar_compra">
                    <input type="hidden" name="vale_id" value="<?= (int)$valeAtual['id'] ?>">
                    <div class="vc-grid">
                        <div class="vc-field">
                            <label>Fornecedor</label>
                            <select name="fornecedor_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($fornecedores as $fornecedor): ?>
                                    <option value="<?= (int)$fornecedor['FCONTADOR'] ?>"><?= vcH($fornecedor['nome'] . ' (' . $fornecedor['FCONTADOR'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="vc-field">
                            <label>Data da compra</label>
                            <input type="date" name="data_movimento" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="vc-field">
                            <label>Valor do Vale</label>
                            <input type="text" name="valor_nominal" id="valor_nominal" inputmode="decimal" required>
                        </div>
                        <div class="vc-field">
                            <label>Taxa de desconto (%)</label>
                            <input type="text" name="taxa_desconto" id="taxa_desconto" inputmode="decimal">
                        </div>
                        <div class="vc-field">
                            <label>Valor Líquido</label>
                            <input type="text" name="valor_liquido" id="valor_liquido" inputmode="decimal">
                        </div>
                        <div class="vc-field">
                            <label>Valor Pago</label>
                            <input type="text" name="valor_pago" id="valor_pago" inputmode="decimal">
                        </div>
                        <div class="vc-field">
                            <label>Valor do desconto</label>
                            <input type="text" name="valor_desagio" id="valor_desagio" inputmode="decimal">
                        </div>
                    </div>
                    <div class="vc-actions mt-3">
                        <button class="btn btn-success">Adicionar compra</button>
                    </div>
                </form>
            </section>

            <section class="vc-card">
                <div class="vc-section-title">
                    <h2 class="h6 fw-bold mb-0">Registrar venda</h2>
                    <small class="text-muted">Saída de saldo</small>
                </div>
                <form method="post">
                    <input type="hidden" name="acao" value="adicionar_venda">
                    <input type="hidden" name="vale_id" value="<?= (int)$valeAtual['id'] ?>">
                    <div class="vc-grid">
                        <div class="vc-field">
                            <label>Cliente</label>
                            <select name="cliente_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($clientes as $cliente): ?>
                                    <option value="<?= (int)$cliente['CLICONTADOR'] ?>"><?= vcH($cliente['nome'] . ' (' . $cliente['CLICONTADOR'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="vc-field">
                            <label>Estabelecimento</label>
                            <input type="text" name="estabelecimento" required maxlength="180" list="vcEstabelecimentos" placeholder="Digite para buscar no historico">
                        </div>
                        <div class="vc-field">
                            <label>Data da venda</label>
                            <input type="date" name="data_movimento" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="vc-field">
                            <label>Valor usado</label>
                            <input type="text" name="valor" inputmode="decimal" required>
                        </div>
                    </div>
                    <div class="vc-actions mt-3">
                        <button class="btn btn-primary">Adicionar venda</button>
                        <span class="text-muted small">Vencimento calculado para todo dia 15, respeitando mínimo de 40 dias.</span>
                    </div>
                </form>
            </section>

            <section class="vc-card">
                <div class="vc-section-title">
                    <h2 class="h6 fw-bold mb-0">Extrato do vale</h2>
                    <div class="vc-actions">
                        <form id="extratoLoteForm" method="post"></form>
                        <input form="extratoLoteForm" type="hidden" name="vale_id" value="<?= (int)$valeAtual['id'] ?>">
                        <button form="extratoLoteForm" name="acao" value="lancar_compras_lote" class="btn btn-sm btn-outline-success" onclick="return confirm('Lancar as compras selecionadas no caixa/banco?');">Lançar compras selecionadas</button>
                        <button form="extratoLoteForm" name="acao" value="lancar_vendas_lote" class="btn btn-sm btn-outline-primary" onclick="return confirm('Lancar as vendas selecionadas no contas a receber?');">Lançar CR selecionados</button>
                    </div>
                </div>
                <form method="get" class="vc-filter">
                    <input type="hidden" name="vale" value="<?= (int)$valeAtual['id'] ?>">
                    <div class="vc-field">
                        <label>Tipo</label>
                        <select name="tipo">
                            <option value="">Todos</option>
                            <option value="COMPRA" <?= $filtrosExtrato['tipo'] === 'COMPRA' ? 'selected' : '' ?>>Compra</option>
                            <option value="VENDA" <?= $filtrosExtrato['tipo'] === 'VENDA' ? 'selected' : '' ?>>Venda</option>
                        </select>
                    </div>
                    <div class="vc-field">
                        <label>Data inicial</label>
                        <input type="date" name="data_ini" value="<?= vcH($filtrosExtrato['data_ini']) ?>">
                    </div>
                    <div class="vc-field">
                        <label>Data final</label>
                        <input type="date" name="data_fim" value="<?= vcH($filtrosExtrato['data_fim']) ?>">
                    </div>
                    <div class="vc-field">
                        <label>Fornecedor/Cliente</label>
                        <input type="text" name="pessoa" value="<?= vcH($filtrosExtrato['pessoa']) ?>" placeholder="Nome ou codigo">
                    </div>
                    <div class="vc-field">
                        <label>Estabelecimento</label>
                        <input type="text" name="estabelecimento" list="vcEstabelecimentos" value="<?= vcH($filtrosExtrato['estabelecimento']) ?>">
                    </div>
                    <div class="vc-field">
                        <label>Valor minimo</label>
                        <input type="text" name="valor_min" inputmode="decimal" value="<?= vcH($filtrosExtrato['valor_min']) ?>">
                    </div>
                    <div class="vc-field">
                        <label>Valor maximo</label>
                        <input type="text" name="valor_max" inputmode="decimal" value="<?= vcH($filtrosExtrato['valor_max']) ?>">
                    </div>
                    <div class="vc-field">
                        <label>Vencimento inicial</label>
                        <input type="date" name="venc_ini" value="<?= vcH($filtrosExtrato['venc_ini']) ?>">
                    </div>
                    <div class="vc-field">
                        <label>Vencimento final</label>
                        <input type="date" name="venc_fim" value="<?= vcH($filtrosExtrato['venc_fim']) ?>">
                    </div>
                    <div class="vc-field">
                        <label>Financeiro</label>
                        <select name="financeiro">
                            <option value="">Todos</option>
                            <option value="pendente" <?= $filtrosExtrato['financeiro'] === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                            <option value="lancado" <?= $filtrosExtrato['financeiro'] === 'lancado' ? 'selected' : '' ?>>Lançado</option>
                        </select>
                    </div>
                    <div class="vc-actions">
                        <button class="btn btn-sm btn-outline-secondary">Filtrar</button>
                        <a class="btn btn-sm btn-outline-light text-dark border" href="lancamentos.php?vale=<?= (int)$valeAtual['id'] ?>">Limpar</a>
                    </div>
                </form>
                <div class="vc-scroll">
                    <table class="vc-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selecionarTodosMovimentos" title="Selecionar pendentes"></th>
                                <th># Lanc.</th>
                                <th>Tipo</th>
                                <th>Data</th>
                                <th>Fornecedor/Cliente</th>
                                <th>Estabelecimento</th>
                                <th>Valor</th>
                                <th>Venc.</th>
                                <th>Financeiro</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movimentos as $movimento): ?>
                                <?php
                                $isCompra = $movimento['tipo'] === 'COMPRA';
                                $nomePessoa = $isCompra
                                    ? trim((string)($movimento['fornecedor_apelido'] ?: $movimento['fornecedor_nome']))
                                    : trim((string)($movimento['cliente_apelido'] ?: $movimento['cliente_nome']));
                                ?>
                                <tr>
                                    <td>
                                        <?php if (($isCompra && empty($movimento['mov_nominal']) && empty($movimento['mov_desagio'])) || (!$isCompra && empty($movimento['crcontador']))): ?>
                                            <input form="extratoLoteForm" type="checkbox" name="movimentos[]" value="<?= (int)$movimento['id'] ?>" class="movimento-lote">
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int)$movimento['id'] ?></td>
                                    <td>
                                        <span class="badge text-bg-<?= $isCompra ? 'success' : 'primary' ?>"><?= vcH($movimento['tipo']) ?></span>
                                    </td>
                                    <td><?= vcData($movimento['data_movimento']) ?></td>
                                    <td title="<?= vcH($nomePessoa) ?>"><?= vcH($nomePessoa) ?></td>
                                    <td title="<?= vcH($movimento['estabelecimento'] ?? '') ?>"><?= !$isCompra && trim((string)($movimento['estabelecimento'] ?? '')) !== '' ? vcH($movimento['estabelecimento']) : '-' ?></td>
                                    <td class="vc-cell-money"><?= $isCompra ? vcMoeda($movimento['valor_nominal']) : vcMoeda($movimento['valor']) ?></td>
                                    <td><?= !$isCompra ? vcData($movimento['vencimento']) : '-' ?></td>
                                    <td>
                                        <?php if ($isCompra): ?>
                                            <?php if (!empty($movimento['mov_nominal']) || !empty($movimento['mov_desagio'])): ?>
                                                <span class="badge text-bg-success">MOV <?= (int)$movimento['mov_nominal'] ?><?= $movimento['mov_desagio'] ? ' / ' . (int)$movimento['mov_desagio'] : '' ?></span>
                                            <?php else: ?>
                                                <span class="badge text-bg-warning">Pendente</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if (!empty($movimento['crcontador'])): ?>
                                                <span class="badge text-bg-success">CR <?= (int)$movimento['crcontador'] ?></span>
                                            <?php else: ?>
                                                <span class="badge text-bg-warning">Pendente</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="vc-row-actions">
                                            <a class="btn btn-sm btn-outline-secondary" href="lancamentos.php?vale=<?= (int)$valeAtual['id'] ?>&editar_movimento=<?= (int)$movimento['id'] ?>">Editar</a>
                                            <form method="post" onsubmit="return confirm('Excluir este lancamento? Se houver financeiro aberto e permitido, ele tambem sera excluido.');">
                                                <input type="hidden" name="acao" value="excluir_movimento">
                                                <input type="hidden" name="vale_id" value="<?= (int)$valeAtual['id'] ?>">
                                                <input type="hidden" name="movimento_id" value="<?= (int)$movimento['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger">Excluir</button>
                                            </form>
                                        <?php if ($isCompra && empty($movimento['mov_nominal']) && empty($movimento['mov_desagio'])): ?>
                                            <form method="post" onsubmit="return confirm('Lançar compra no caixa/banco?');">
                                                <input type="hidden" name="acao" value="lancar_compra">
                                                <input type="hidden" name="movimento_id" value="<?= (int)$movimento['id'] ?>">
                                                <button class="btn btn-sm btn-outline-success">Lancar</button>
                                            </form>
                                        <?php elseif (!$isCompra && empty($movimento['crcontador'])): ?>
                                            <form method="post" onsubmit="return confirm('Lançar venda no contas a receber?');">
                                                <input type="hidden" name="acao" value="lancar_venda">
                                                <input type="hidden" name="movimento_id" value="<?= (int)$movimento['id'] ?>">
                                                <button class="btn btn-sm btn-outline-primary">CR</button>
                                            </form>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$movimentos): ?>
                                <tr><td colspan="10" class="text-center text-muted py-4">Nenhum movimento registrado para este vale.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php else: ?>
            <section class="vc-card">
                <div class="alert alert-info mb-0">Abra um vale pelo Cadastro de Vales para registrar compras e vendas.</div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const valorVale = document.getElementById('valor_nominal');
    const taxa = document.getElementById('taxa_desconto');
    const liquido = document.getElementById('valor_liquido');
    const pago = document.getElementById('valor_pago');
    const desconto = document.getElementById('valor_desagio');

    function parseMoney(value) {
        value = String(value || '').replace(/[R$\s]/g, '');
        if (value.indexOf(',') !== -1) {
            value = value.replace(/\./g, '').replace(',', '.');
        }
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function formatMoney(value) {
        return Number(value || 0).toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function calcular() {
        if (!valorVale || !taxa || !liquido || !pago || !desconto) {
            return;
        }
        const valor = parseMoney(valorVale.value);
        const taxaPercentual = parseMoney(taxa.value);
        if (valor <= 0 || taxaPercentual < 0) {
            return;
        }
        const valorDesconto = Math.round((valor * taxaPercentual / 100) * 100) / 100;
        const valorLiquido = Math.max(0, Math.round((valor - valorDesconto) * 100) / 100);
        desconto.value = formatMoney(valorDesconto);
        liquido.value = formatMoney(valorLiquido);
        if (!pago.dataset.editado || parseMoney(pago.value) === 0) {
            pago.value = formatMoney(valorLiquido);
        }
    }

    if (pago) {
        pago.addEventListener('input', function () {
            pago.dataset.editado = '1';
        });
    }
    [valorVale, taxa].forEach(function (campo) {
        if (campo) {
            campo.addEventListener('input', calcular);
            campo.addEventListener('blur', calcular);
        }
    });

    const selecionarTodos = document.getElementById('selecionarTodosMovimentos');
    if (selecionarTodos) {
        selecionarTodos.addEventListener('change', function () {
            document.querySelectorAll('.movimento-lote').forEach(function (campo) {
                campo.checked = selecionarTodos.checked;
            });
        });
    }
});
</script>

<?php require '../../layout/footer.php'; ?>
