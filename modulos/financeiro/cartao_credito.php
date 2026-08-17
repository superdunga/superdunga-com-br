<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_cartao_credito_lib.php';

$empresaSessao = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$nivel = strtoupper((string)($_SESSION['nivel'] ?? ''));

if ($nivel !== 'MASTER') {
    renderizarAcessoNegadoModulo('A rotina de cartao de credito esta liberada somente para perfil MASTER.');
}

garantirTabelasCartaoCredito($pdo_master);

$mensagemOk = '';
$mensagemErro = '';
$faturaId = (int)($_GET['fatura_id'] ?? $_POST['fatura_id'] ?? 0);
$etapa = trim((string)($_GET['etapa'] ?? $_POST['etapa'] ?? ($faturaId > 0 ? 'conferencia' : 'faturas')));
if (!in_array($etapa, ['faturas', 'conferencia', 'processamento'], true)) {
    $etapa = $faturaId > 0 ? 'conferencia' : 'faturas';
}
if ($faturaId <= 0) {
    $etapa = 'faturas';
}
$competenciaFiltro = trim((string)($_GET['competencia'] ?? date('Y-m')));
$lancDataIni = trim((string)($_GET['l_data_ini'] ?? ''));
$lancDataFim = trim((string)($_GET['l_data_fim'] ?? ''));
$lancBusca = trim((string)($_GET['l_busca'] ?? ''));
$lancFornecedor = trim((string)($_GET['l_fornecedor'] ?? ''));
$lancNatureza = strtoupper(trim((string)($_GET['l_natureza'] ?? '')));
$lancStatus = trim((string)($_GET['l_status'] ?? ''));
$candidatoLancamentoId = (int)($_GET['candidato_lancamento'] ?? 0);
$lancTipoesParam = trim((string)($_GET['l_tipoes'] ?? ''));
$lancTipoes = ctype_digit($lancTipoesParam) ? (int)$lancTipoesParam : 0;
$empresaSelecionada = $empresaSessao;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lancDataIni)) {
    $lancDataIni = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lancDataFim)) {
    $lancDataFim = '';
}
if (!in_array($lancNatureza, ['D', 'C'], true)) {
    $lancNatureza = '';
}
if (!in_array($lancStatus, ['pendente', 'pronto', 'gerado', 'sem_financeiro', 'ignorado'], true)) {
    $lancStatus = '';
}

function voltarCartaoCredito(array $extra = []): string
{
    $base = [
        'competencia' => $_POST['competencia'] ?? $_GET['competencia'] ?? date('Y-m'),
        'fatura_id' => $_POST['fatura_id'] ?? $_GET['fatura_id'] ?? '',
        'etapa' => $_POST['etapa'] ?? $_GET['etapa'] ?? '',
    ];
    foreach ($extra as $k => $v) {
        $base[$k] = $v;
    }
    $base = array_filter($base, function ($v) {
        return $v !== '' && $v !== null;
    });
    return 'cartao_credito.php' . ($base ? '?' . http_build_query($base) : '');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'importar') {
            $empresaImportacao = $empresaSessao;
            $competencia = trim((string)($_POST['competencia'] ?? ''));
            $dataVencimento = trim((string)($_POST['data_vencimento'] ?? ''));
            $cartaoNome = trim((string)($_POST['cartao_nome'] ?? ''));

            if ($empresaImportacao <= 0 || !preg_match('/^\d{4}-\d{2}$/', $competencia) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataVencimento) || $cartaoNome === '') {
                throw new RuntimeException('Informe empresa, competencia, vencimento e nome do cartao.');
            }
            if (empty($_FILES['arquivo_csv']['tmp_name']) || !is_uploaded_file($_FILES['arquivo_csv']['tmp_name'])) {
                throw new RuntimeException('Selecione o arquivo CSV da fatura.');
            }

            $linhas = lerCsvFaturaCartao($_FILES['arquivo_csv']['tmp_name']);
            if (empty($linhas)) {
                throw new RuntimeException('Nenhuma linha valida foi encontrada no CSV.');
            }

            $pdo_master->beginTransaction();

            $totalCompras = 0.0;
            $totalPagamentos = 0.0;
            foreach ($linhas as $linha) {
                if ($linha['natureza'] === 'D') {
                    $totalCompras += (float)$linha['valor'];
                } else {
                    $totalPagamentos += (float)$linha['valor'];
                }
            }

            $stmtFatura = $pdo_master->prepare("
                INSERT INTO financeiro_cartao_faturas (
                    empresa_id, competencia, data_vencimento, cartao_nome, nome_arquivo,
                    total_compras, total_pagamentos, total_liquido, total_linhas, usuario_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtFatura->execute([
                $empresaImportacao,
                $competencia,
                $dataVencimento,
                $cartaoNome,
                $_FILES['arquivo_csv']['name'] ?? null,
                $totalCompras,
                $totalPagamentos,
                $totalCompras - $totalPagamentos,
                count($linhas),
                $usuarioId ?: null,
            ]);
            $novaFaturaId = (int)$pdo_master->lastInsertId();

            $stmtLanc = $pdo_master->prepare("
                INSERT IGNORE INTO financeiro_cartao_lancamentos (
                    fatura_id, empresa_id, data_compra, descricao, categoria, tipo_lancamento,
                    valor, natureza, hash_linha
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($linhas as $linha) {
                $stmtLanc->execute([
                    $novaFaturaId,
                    $empresaImportacao,
                    $linha['data_compra'],
                    $linha['descricao'],
                    $linha['categoria'],
                    $linha['tipo_lancamento'],
                    $linha['valor'],
                    $linha['natureza'],
                    $linha['hash_linha'],
                ]);
            }

            aplicarMapeamentosCartaoCredito($pdo_master, $novaFaturaId);
            $pdo_master->commit();

            header('Location: cartao_credito.php?competencia=' . urlencode($competencia) . '&fatura_id=' . $novaFaturaId . '&etapa=conferencia&ok=importado');
            exit;
        }

        if ($acao === 'corrigir_vencimento') {
            $faturaPost = (int)($_POST['fatura_id'] ?? 0);
            $novoVencimento = trim((string)($_POST['novo_vencimento'] ?? ''));
            if ($faturaPost <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $novoVencimento)) {
                throw new RuntimeException('Informe a nova data de vencimento.');
            }

            $pdo_master->beginTransaction();
            $stmtFaturaCorrigir = $pdo_master->prepare("
                SELECT id, data_vencimento
                FROM financeiro_cartao_faturas
                WHERE id = ? AND empresa_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmtFaturaCorrigir->execute([$faturaPost, $empresaSessao]);
            $faturaCorrigir = $stmtFaturaCorrigir->fetch(PDO::FETCH_ASSOC);
            if (!$faturaCorrigir) {
                throw new RuntimeException('Fatura nao encontrada para esta empresa.');
            }

            $stmtCpFatura = $pdo_master->prepare("
                SELECT l.id AS lancamento_id, l.cpcontador, l.status AS vinculo_status,
                       cp.DTVENC, cp.DTPAGTO, cp.VLRPARCELA, cp.VLRRESTANTE, cp.VLRPAGO,
                       cp.excluido_firebird
                FROM financeiro_cartao_lancamentos l
                LEFT JOIN armazem_cp001 cp
                  ON cp.EMPRESA = l.empresa_id
                 AND cp.CPCONTADOR = l.cpcontador
                WHERE l.fatura_id = ?
                  AND l.empresa_id = ?
                  AND l.cpcontador IS NOT NULL
                FOR UPDATE
            ");
            $stmtCpFatura->execute([$faturaPost, $empresaSessao]);
            $cpsGerados = [];
            $cpsVinculados = 0;
            foreach ($stmtCpFatura->fetchAll(PDO::FETCH_ASSOC) as $cpFatura) {
                if (($cpFatura['vinculo_status'] ?? '') === 'CP_VINCULADO') {
                    $cpsVinculados++;
                    continue;
                }
                if (empty($cpFatura['cpcontador']) || $cpFatura['DTVENC'] === null || ($cpFatura['excluido_firebird'] ?? 'N') === 'S') {
                    throw new RuntimeException('Um CP001 gerado pela fatura nao foi encontrado ou esta excluido. Vencimento nao alterado.');
                }
                $valorParcela = (float)($cpFatura['VLRPARCELA'] ?? 0);
                $valorRestante = (float)($cpFatura['VLRRESTANTE'] ?? 0);
                $valorPago = (float)($cpFatura['VLRPAGO'] ?? 0);
                if (!empty($cpFatura['DTPAGTO']) || $valorPago > 0.009 || $valorRestante + 0.009 < $valorParcela) {
                    throw new RuntimeException('O CP001 ' . (int)$cpFatura['cpcontador'] . ' ja possui pagamento total ou parcial. Vencimento nao alterado.');
                }
                $cpsGerados[] = (int)$cpFatura['cpcontador'];
            }

            $pdo_master->prepare("
                UPDATE financeiro_cartao_faturas
                SET data_vencimento = ?
                WHERE id = ? AND empresa_id = ?
            ")->execute([$novoVencimento, $faturaPost, $empresaSessao]);

            $stmtAtualizarCpVencimento = $pdo_master->prepare("
                UPDATE armazem_cp001
                SET DTVENC = ?, USERALT = ?, DTALT = NOW(), REGSTAMP = NOW()
                WHERE EMPRESA = ? AND CPCONTADOR = ?
            ");
            foreach ($cpsGerados as $cpcontadorGerado) {
                $stmtAtualizarCpVencimento->execute([$novoVencimento, $usuarioId ?: null, $empresaSessao, $cpcontadorGerado]);
            }
            $pdo_master->commit();

            header('Location: ' . voltarCartaoCredito([
                'etapa' => 'conferencia',
                'ok' => 'vencimento',
                'cps_atualizados' => count($cpsGerados),
                'cps_vinculados' => $cpsVinculados,
            ]));
            exit;
        }

        if ($acao === 'mapear_fornecedor') {
            $lancamentoId = (int)($_POST['lancamento_id'] ?? 0);
            $fcontador = (int)($_POST['fcontador'] ?? 0);
            $novoFornecedor = trim((string)($_POST['novo_fornecedor'] ?? ''));

            $stmtLinha = $pdo_master->prepare("SELECT id, empresa_id, descricao FROM financeiro_cartao_lancamentos WHERE id = ? LIMIT 1");
            $stmtLinha->execute([$lancamentoId]);
            $linha = $stmtLinha->fetch(PDO::FETCH_ASSOC);
            if (!$linha) {
                throw new RuntimeException('Lancamento nao encontrado.');
            }

            $empresaLinha = (int)$linha['empresa_id'];
            if ($fcontador <= 0 && $novoFornecedor !== '') {
                $fcontador = criarFornecedorCartaoCredito($pdo_master, $empresaLinha, $novoFornecedor, $usuarioId);
            }
            if ($fcontador <= 0 || !fornecedorCartaoExiste($pdo_master, $empresaLinha, $fcontador)) {
                throw new RuntimeException('Fornecedor invalido.');
            }

            salvarMapeamentoFornecedorCartao($pdo_master, $empresaLinha, $linha['descricao'], $fcontador);
            $pdo_master->prepare("
                UPDATE financeiro_cartao_lancamentos
                SET fornecedor_fcontador = ?
                WHERE empresa_id = ?
                  AND descricao = ?
                  AND fornecedor_fcontador IS NULL
            ")->execute([$fcontador, $empresaLinha, $linha['descricao']]);

            header('Location: ' . voltarCartaoCredito(['ok' => 'fornecedor']));
            exit;
        }

        if ($acao === 'mapear_fornecedor_lote') {
            $faturaPost = (int)($_POST['fatura_id'] ?? 0);
            $novosFornecedores = $_POST['novo_fornecedor_lote'] ?? [];
            $novosFornecedores = is_array($novosFornecedores) ? $novosFornecedores : [];
            $fornecedoresExistentes = $_POST['fcontador_lote'] ?? [];
            $fornecedoresExistentes = is_array($fornecedoresExistentes) ? $fornecedoresExistentes : [];
            $criados = 0;
            $amarrados = 0;

            $stmtLinha = $pdo_master->prepare("
                SELECT id, empresa_id, descricao, fornecedor_fcontador, cpcontador, bnc001_movcontador, natureza
                FROM financeiro_cartao_lancamentos
                WHERE id = ?
                  AND fatura_id = ?
                LIMIT 1
            ");

            foreach ($novosFornecedores as $lancamentoId => $nomeFornecedor) {
                $lancamentoId = (int)$lancamentoId;
                if ($lancamentoId <= 0) {
                    continue;
                }

                $stmtLinha->execute([$lancamentoId, $faturaPost]);
                $linha = $stmtLinha->fetch(PDO::FETCH_ASSOC);
                if (!$linha || $linha['natureza'] !== 'D' || !empty($linha['cpcontador']) || !empty($linha['bnc001_movcontador'])) {
                    continue;
                }

                $empresaLinha = (int)$linha['empresa_id'];
                $fcontador = (int)($fornecedoresExistentes[$lancamentoId] ?? 0);
                if ($fcontador > 0) {
                    if (!fornecedorCartaoExiste($pdo_master, $empresaLinha, $fcontador)) {
                        continue;
                    }
                    $amarrados++;
                } else {
                    $nomeFornecedor = trim((string)$nomeFornecedor);
                    if ($nomeFornecedor === '') {
                        continue;
                    }
                    $fcontador = criarFornecedorCartaoCredito($pdo_master, $empresaLinha, $nomeFornecedor, $usuarioId);
                    $criados++;
                }

                salvarMapeamentoFornecedorCartao($pdo_master, $empresaLinha, $linha['descricao'], $fcontador);
                $pdo_master->prepare("
                    UPDATE financeiro_cartao_lancamentos
                    SET fornecedor_fcontador = ?
                    WHERE empresa_id = ?
                      AND descricao = ?
                      AND cpcontador IS NULL
                      AND bnc001_movcontador IS NULL
                ")->execute([$fcontador, $empresaLinha, $linha['descricao']]);
            }

            header('Location: ' . voltarCartaoCredito(['ok' => 'fornecedor_lote', 'criados' => $criados, 'amarrados' => $amarrados]));
            exit;
        }

        if ($acao === 'gerar_cp001') {
            $lancamentoId = (int)($_POST['lancamento_id'] ?? 0);
            gerarCp001CartaoCredito($pdo_master, $lancamentoId, $usuarioId);
            header('Location: ' . voltarCartaoCredito(['ok' => 'cp001']));
            exit;
        }

        if ($acao === 'vincular_cp001') {
            $lancamentoId = (int)($_POST['lancamento_id'] ?? 0);
            $cpcontador = (int)($_POST['cpcontador'] ?? 0);
            vincularCp001CartaoCredito($pdo_master, $lancamentoId, $cpcontador);
            header('Location: ' . voltarCartaoCredito(['ok' => 'cp_vinculado']));
            exit;
        }

        if ($acao === 'vincular_bnc001') {
            $lancamentoId = (int)($_POST['lancamento_id'] ?? 0);
            $movcontador = (int)($_POST['movcontador'] ?? 0);
            vincularBnc001CartaoCredito($pdo_master, $lancamentoId, $movcontador);
            header('Location: ' . voltarCartaoCredito(['ok' => 'bnc_vinculado']));
            exit;
        }

        if ($acao === 'gerar_cp001_lote') {
            $stmtPendentes = $pdo_master->prepare("
                SELECT id
                FROM financeiro_cartao_lancamentos
                WHERE fatura_id = ?
                  AND natureza = 'D'
                  AND cpcontador IS NULL
                  AND bnc001_movcontador IS NULL
                  AND fornecedor_fcontador IS NOT NULL
                ORDER BY data_compra, id
            ");
            $stmtPendentes->execute([$faturaId]);
            $gerados = 0;
            foreach ($stmtPendentes->fetchAll(PDO::FETCH_ASSOC) as $linha) {
                gerarCp001CartaoCredito($pdo_master, (int)$linha['id'], $usuarioId);
                $gerados++;
            }
            header('Location: ' . voltarCartaoCredito(['ok' => 'lote', 'gerados' => $gerados]));
            exit;
        }

        if ($acao === 'gerar_bnc001_lote') {
            $lancamentoIds = $_POST['lancamentos_bnc'] ?? [];
            $lancamentoIds = is_array($lancamentoIds) ? array_values(array_unique(array_map('intval', $lancamentoIds))) : [];
            $cbcontador = (int)($_POST['bnc_cbcontador'] ?? 0);
            $tipoes = (int)($_POST['bnc_tipoes'] ?? 0);
            $contrapCbcontador = (int)($_POST['bnc_contrap_cbcontador'] ?? 0);
            $gerados = 0;

            if (!$lancamentoIds) {
                throw new RuntimeException('Selecione ao menos um lancamento da fatura para gerar BNC001.');
            }

            foreach ($lancamentoIds as $lancamentoId) {
                if ($lancamentoId <= 0) {
                    continue;
                }
                gerarBnc001CartaoCredito($pdo_master, $lancamentoId, $usuarioId, $cbcontador, $tipoes, $contrapCbcontador);
                $gerados++;
            }

            header('Location: ' . voltarCartaoCredito(['ok' => 'bnc001', 'gerados' => $gerados]));
            exit;
        }

        if ($acao === 'atualizar_tipoes_fornecedores') {
            $faturaPost = (int)($_POST['fatura_id'] ?? 0);
            $stmtAtualizaTipoes = $pdo_master->prepare("
                UPDATE armazem_cp001 cp
                INNER JOIN financeiro_cartao_lancamentos l
                   ON l.empresa_id = cp.EMPRESA
                  AND l.cpcontador = cp.CPCONTADOR
                INNER JOIN financeiro_cartao_faturas fat
                   ON fat.id = l.fatura_id
                  AND fat.empresa_id = cp.EMPRESA
                INNER JOIN armazem_cp003 f
                   ON f.EMPRESA = cp.EMPRESA
                  AND f.FCONTADOR = cp.FCONTADOR
                SET cp.TIPOES = f.TIPOES,
                    cp.DTALT = NOW(),
                    cp.REGSTAMP = NOW()
                WHERE fat.id = ?
                  AND fat.empresa_id = ?
                  AND cp.TIPODOCORIGEM = 'CARTAO'
                  AND cp.CONTROLE = 'SUPERDUNGA_CARTAO'
                  AND f.TIPOES IS NOT NULL
                  AND f.TIPOES > 0
                  AND (cp.TIPOES IS NULL OR cp.TIPOES <> f.TIPOES)
            ");
            $stmtAtualizaTipoes->execute([$faturaPost, $empresaSessao]);
            header('Location: ' . voltarCartaoCredito(['ok' => 'tipoes_fornecedor', 'atualizados' => $stmtAtualizaTipoes->rowCount()]));
            exit;
        }
    }
} catch (Throwable $e) {
    if ($pdo_master->inTransaction()) {
        $pdo_master->rollBack();
    }
    $mensagemErro = $e->getMessage();
}

if (($_GET['ok'] ?? '') === 'importado') {
    $mensagemOk = 'Fatura importada para conferencia.';
} elseif (($_GET['ok'] ?? '') === 'fornecedor') {
    $mensagemOk = 'Fornecedor vinculado ao lancamento.';
} elseif (($_GET['ok'] ?? '') === 'cp001') {
    $mensagemOk = 'Lancamento gerado em contas a pagar.';
} elseif (($_GET['ok'] ?? '') === 'cp_vinculado') {
    $mensagemOk = 'Lancamento vinculado ao CP001 existente.';
} elseif (($_GET['ok'] ?? '') === 'bnc_vinculado') {
    $mensagemOk = 'Lancamento conciliado com o BNC001 existente.';
} elseif (($_GET['ok'] ?? '') === 'lote') {
    $mensagemOk = 'Lancamentos gerados em contas a pagar: ' . (int)($_GET['gerados'] ?? 0);
} elseif (($_GET['ok'] ?? '') === 'bnc001') {
    $mensagemOk = 'Lancamentos gerados em BNC001: ' . (int)($_GET['gerados'] ?? 0);
} elseif (($_GET['ok'] ?? '') === 'fornecedor_lote') {
    $mensagemOk = 'Fornecedores amarrados: ' . (int)($_GET['amarrados'] ?? 0) . ' | Criados: ' . (int)($_GET['criados'] ?? 0);
} elseif (($_GET['ok'] ?? '') === 'tipoes_fornecedor') {
    $mensagemOk = 'TIPOES atualizados pelo cadastro do fornecedor: ' . (int)($_GET['atualizados'] ?? 0);
} elseif (($_GET['ok'] ?? '') === 'vencimento') {
    $mensagemOk = 'Vencimento corrigido. CP001 gerados atualizados: ' . (int)($_GET['cps_atualizados'] ?? 0)
        . '. CP001 apenas vinculados e preservados: ' . (int)($_GET['cps_vinculados'] ?? 0) . '.';
}

$fornecedores = buscarFornecedoresCartaoCredito($pdo_master, $empresaSelecionada);

$stmtTiposCartao = $pdo_master->prepare("
    SELECT ESCONTADOR, DESCES, TIPOMOV, CONTRAP_TIPOES, CONTRAP_TIPOMOV, CONTRAP_CBCONTADOR
    FROM armazem_bnc005
    WHERE EMPRESA = ?
      AND COALESCE(REGDISAB, 'N') <> 'S'
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY DESCES, ESCONTADOR
");
$stmtTiposCartao->execute([$empresaSelecionada]);
$tiposCartao = $stmtTiposCartao->fetchAll(PDO::FETCH_ASSOC);

$stmtContasCartao = $pdo_master->prepare("
    SELECT CBCONTADOR,
           TRIM(COALESCE(NULLIF(TITULAR, ''), NULLIF(DESCABREV, ''), CONCAT('Conta ', CBCONTADOR))) AS nome_conta
    FROM armazem_bnc002
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
      AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
    ORDER BY nome_conta, CBCONTADOR
");
$stmtContasCartao->execute([$empresaSelecionada]);
$contasCartao = $stmtContasCartao->fetchAll(PDO::FETCH_ASSOC);

$stmtFaturas = $pdo_master->prepare("
    SELECT f.*,
           COUNT(l.id) AS qtd_linhas,
           SUM(CASE WHEN l.natureza = 'D' THEN 1 ELSE 0 END) AS qtd_compras,
           SUM(CASE WHEN l.natureza = 'C' THEN 1 ELSE 0 END) AS qtd_creditos,
           SUM(CASE WHEN l.natureza = 'D' AND l.cpcontador IS NOT NULL THEN 1 ELSE 0 END) AS qtd_cp,
           SUM(CASE WHEN l.bnc001_movcontador IS NOT NULL THEN 1 ELSE 0 END) AS qtd_bnc
    FROM financeiro_cartao_faturas f
    LEFT JOIN financeiro_cartao_lancamentos l ON l.fatura_id = f.id
    WHERE f.empresa_id = ?
    GROUP BY f.id
    ORDER BY f.competencia DESC, f.data_vencimento DESC, f.id DESC
");
$stmtFaturas->execute([$empresaSelecionada]);
$faturas = $stmtFaturas->fetchAll(PDO::FETCH_ASSOC);

$faturaAtual = null;
$lancamentos = [];
$candidatosBnc = [];
$candidatosCp = [];
$resumoFaturaAtual = [
    'compras' => 0,
    'creditos' => 0,
    'pendentes' => 0,
    'prontos' => 0,
    'gerados' => 0,
];
if ($faturaId > 0) {
    $stmtFaturaAtual = $pdo_master->prepare("SELECT * FROM financeiro_cartao_faturas WHERE id = ? AND empresa_id = ? LIMIT 1");
    $stmtFaturaAtual->execute([$faturaId, $empresaSelecionada]);
    $faturaAtual = $stmtFaturaAtual->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($faturaAtual) {
        aplicarMapeamentosCartaoCredito($pdo_master, $faturaId);
        $whereLancamentos = ['l.fatura_id = ?'];
        $paramsLancamentos = [$faturaId];

        if ($lancDataIni !== '') {
            $whereLancamentos[] = 'l.data_compra >= ?';
            $paramsLancamentos[] = $lancDataIni;
        }
        if ($lancDataFim !== '') {
            $whereLancamentos[] = 'l.data_compra <= ?';
            $paramsLancamentos[] = $lancDataFim;
        }
        if ($lancBusca !== '') {
            $whereLancamentos[] = '(l.descricao LIKE ? OR l.categoria LIKE ? OR l.tipo_lancamento LIKE ?)';
            $likeLanc = '%' . $lancBusca . '%';
            array_push($paramsLancamentos, $likeLanc, $likeLanc, $likeLanc);
        }
        if ($lancFornecedor !== '') {
            $whereLancamentos[] = "(f.NOME LIKE ? OR f.APELIDO LIKE ? OR l.fornecedor_fcontador LIKE ?)";
            $likeFornecedor = '%' . $lancFornecedor . '%';
            array_push($paramsLancamentos, $likeFornecedor, $likeFornecedor, $likeFornecedor);
        }
        if ($lancNatureza !== '') {
            $whereLancamentos[] = 'l.natureza = ?';
            $paramsLancamentos[] = $lancNatureza;
        }
        if ($lancTipoesParam === 'sem_padrao') {
            $whereLancamentos[] = 'COALESCE(cp.TIPOES, f.TIPOES, 0) = 0';
        } elseif ($lancTipoes > 0) {
            $whereLancamentos[] = 'COALESCE(cp.TIPOES, f.TIPOES, 301) = ?';
            $paramsLancamentos[] = $lancTipoes;
        }
        if ($lancStatus === 'gerado') {
            $whereLancamentos[] = '(l.cpcontador IS NOT NULL OR l.bnc001_movcontador IS NOT NULL)';
        } elseif (in_array($lancStatus, ['sem_financeiro', 'ignorado'], true)) {
            $whereLancamentos[] = 'l.cpcontador IS NULL AND l.bnc001_movcontador IS NULL';
        } elseif ($lancStatus === 'pronto') {
            $whereLancamentos[] = "l.natureza = 'D' AND l.cpcontador IS NULL AND l.bnc001_movcontador IS NULL AND l.fornecedor_fcontador IS NOT NULL";
        } elseif ($lancStatus === 'pendente') {
            $whereLancamentos[] = "l.cpcontador IS NULL AND l.bnc001_movcontador IS NULL AND (l.natureza <> 'D' OR l.fornecedor_fcontador IS NULL)";
        }

        $stmtLancamentos = $pdo_master->prepare("
            SELECT l.*,
                   f.NOME AS fornecedor_nome,
                   f.APELIDO AS fornecedor_apelido,
                   f.TIPOES AS fornecedor_tipoes,
                   cp.TIPOES AS cp_tipoes,
                   tipo.DESCES AS tipoes_desc,
                   bnc.CBCONTADOR AS bnc_cbcontador,
                   bnc.TIPOES AS bnc_tipoes,
                   bnc.TIPOMOV AS bnc_tipomov
            FROM financeiro_cartao_lancamentos l
            LEFT JOIN armazem_cp003 f
                ON f.EMPRESA = l.empresa_id
               AND f.FCONTADOR = l.fornecedor_fcontador
            LEFT JOIN armazem_cp001 cp
                ON cp.EMPRESA = l.empresa_id
               AND cp.CPCONTADOR = l.cpcontador
            LEFT JOIN armazem_bnc005 tipo
                ON tipo.EMPRESA = l.empresa_id
               AND tipo.ESCONTADOR = COALESCE(cp.TIPOES, f.TIPOES, 301)
            LEFT JOIN armazem_bnc001 bnc
                ON bnc.EMPRESA = l.bnc001_empresa
               AND bnc.MOVCONTADOR = l.bnc001_movcontador
            WHERE " . implode(' AND ', $whereLancamentos) . "
            ORDER BY l.natureza DESC, l.data_compra, l.id
        ");
        $stmtLancamentos->execute($paramsLancamentos);
        $lancamentos = $stmtLancamentos->fetchAll(PDO::FETCH_ASSOC);

        if ($etapa === 'processamento') {
            $buscarCandidatosAutomaticamente = count($lancamentos) <= 10;
            foreach ($lancamentos as $linhaCandidato) {
                $linhaIdCandidato = (int)$linhaCandidato['id'];
                $deveBuscarCandidato = $buscarCandidatosAutomaticamente
                    || ($candidatoLancamentoId > 0 && $candidatoLancamentoId === $linhaIdCandidato);
                if ($deveBuscarCandidato && empty($linhaCandidato['cpcontador']) && empty($linhaCandidato['bnc001_movcontador'])) {
                    $candidatosBnc[$linhaIdCandidato] = buscarCandidatosBncCartaoCredito($pdo_master, $linhaCandidato);
                    if (($linhaCandidato['natureza'] ?? '') === 'D') {
                        $candidatosCp[$linhaIdCandidato] = buscarCandidatosCpCartaoCredito(
                            $pdo_master,
                            $linhaCandidato,
                            (string)$faturaAtual['data_vencimento']
                        );
                    }
                }
            }
        }

        $stmtResumoFatura = $pdo_master->prepare("
            SELECT
                SUM(natureza = 'D') AS compras,
                SUM(natureza = 'C') AS creditos,
                SUM(cpcontador IS NOT NULL OR bnc001_movcontador IS NOT NULL) AS gerados,
                SUM(natureza = 'D' AND cpcontador IS NULL AND bnc001_movcontador IS NULL AND fornecedor_fcontador IS NOT NULL) AS prontos,
                SUM(cpcontador IS NULL AND bnc001_movcontador IS NULL AND (natureza <> 'D' OR fornecedor_fcontador IS NULL)) AS pendentes
            FROM financeiro_cartao_lancamentos
            WHERE fatura_id = ?
        ");
        $stmtResumoFatura->execute([$faturaId]);
        $resumoBanco = $stmtResumoFatura->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (array_keys($resumoFaturaAtual) as $chaveResumo) {
            $resumoFaturaAtual[$chaveResumo] = (int)($resumoBanco[$chaveResumo] ?? 0);
        }
    }
}

require '../../layout/header.php';
?>

<style>
    .cartao-panel {
        border: 1px solid #dbe3ef;
        border-radius: 8px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .06);
    }
    .cartao-panel .card-header {
        border-bottom-color: #e6edf5;
    }
    .cartao-kpi {
        border: 1px solid #dbe3ef;
        border-radius: 8px;
        padding: .85rem 1rem;
        background: #fff;
        height: 100%;
    }
    .cartao-kpi .small { color: #64748b; }
    .cartao-kpi .h5 { color: #1f2a37; }
    .cartao-table { min-width: 1040px; }
    .cartao-status { white-space: nowrap; }
    .cartao-desc { min-width: 260px; }
    .cartao-fornecedor-input { min-width: 280px; }
    .cartao-fornecedor-busca { position: relative; }
    .cartao-fornecedor-resultados {
        position: absolute;
        z-index: 1050;
        top: 100%;
        right: 0;
        left: 0;
        max-height: 220px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #ced4da;
        border-radius: 0 0 4px 4px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, .12);
    }
    .cartao-fornecedor-opcao {
        display: block;
        width: 100%;
        padding: .4rem .55rem;
        border: 0;
        background: #fff;
        color: #212529;
        text-align: left;
        font-size: .875rem;
    }
    .cartao-fornecedor-opcao:hover,
    .cartao-fornecedor-opcao.ativo { background: #e9f2ff; }
    .cartao-money { font-variant-numeric: tabular-nums; }
    .cartao-section-title {
        font-size: .82rem;
        letter-spacing: .02em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: .45rem;
    }
    @media (max-width: 768px) {
        .cartao-actions { display: grid; gap: .5rem; }
        .cartao-actions .btn { width: 100%; }
        .cartao-panel .card-body { padding: 1rem; }
        .cartao-table {
            min-width: 0;
            border-collapse: separate;
            border-spacing: 0 .75rem;
        }
        .cartao-table thead { display: none; }
        .cartao-table,
        .cartao-table tbody,
        .cartao-table tr,
        .cartao-table td {
            display: block;
            width: 100%;
        }
        .cartao-table tr {
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            background: #fff;
            padding: .75rem;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .05);
        }
        .cartao-table tr.table-secondary { background: #f8fafc; }
        .cartao-table td {
            border: 0;
            padding: .25rem 0;
        }
        .cartao-table td::before {
            content: attr(data-label);
            display: block;
            font-size: .72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: .1rem;
        }
        .cartao-table td.text-end { text-align: left !important; }
        .cartao-desc,
        .cartao-fornecedor-input { min-width: 0; }
    }
</style>

<?php if ($etapa === 'faturas'): ?>
<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-primary mb-3">Financeiro</span>
                <h1 class="h3 fw-bold mb-2">Cartao de Credito</h1>
                <p class="text-muted mb-0">Importe a fatura, confira os dados e depois processe os lançamentos financeiros.</p>
            </div>
            <div class="col-lg-4 text-lg-end cartao-actions">
                <a href="menu_financeiro.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagemOk): ?><div class="alert alert-success"><?= htmlspecialchars($mensagemOk) ?></div><?php endif; ?>
<?php if ($mensagemErro): ?><div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div><?php endif; ?>

<section class="mb-4">
    <div class="card cartao-panel">
        <div class="card-header bg-white fw-semibold">Importar fatura</div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <input type="hidden" name="acao" value="importar">
                <div class="col-md-2">
                    <label class="form-label">Competencia</label>
                    <input type="month" name="competencia" class="form-control" value="<?= htmlspecialchars($competenciaFiltro) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Vencimento</label>
                    <input type="date" name="data_vencimento" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cartao</label>
                    <input type="text" name="cartao_nome" class="form-control" value="INTER" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">CSV da fatura</label>
                    <input type="file" name="arquivo_csv" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100">Importar</button>
                </div>
            </form>
        </div>
    </div>
</section>

<section class="mb-4">
    <div class="card cartao-panel">
        <div class="card-header bg-white fw-semibold">Faturas importadas</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Cartao</th>
                            <th>Competencia</th>
                            <th>Vencimento</th>
                            <th class="text-end">Compras</th>
                            <th class="text-end">Pagamentos</th>
                            <th class="text-end">Liquido</th>
                            <th>Situação</th>
                            <th class="text-center">Financeiro</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($faturas as $fatura): ?>
                            <tr>
                                <td><?= (int)$fatura['id'] ?></td>
                                <td><?= htmlspecialchars($fatura['cartao_nome']) ?></td>
                                <td><?= htmlspecialchars($fatura['competencia']) ?></td>
                                <td><?= dataCartaoCredito($fatura['data_vencimento']) ?></td>
                                <td class="text-end cartao-money"><?= moedaCartaoCredito($fatura['total_compras']) ?></td>
                                <td class="text-end text-muted cartao-money"><?= moedaCartaoCredito($fatura['total_pagamentos']) ?></td>
                                <td class="text-end fw-semibold cartao-money"><?= moedaCartaoCredito($fatura['total_liquido']) ?></td>
                                <?php
                                    $qtdFinanceiroFatura = (int)$fatura['qtd_cp'] + (int)$fatura['qtd_bnc'];
                                    $qtdLinhasFatura = (int)$fatura['qtd_linhas'];
                                    $situacaoFatura = $qtdFinanceiroFatura === 0 ? 'Pendente' : ($qtdFinanceiroFatura >= $qtdLinhasFatura ? 'Concluida' : 'Parcial');
                                    $classeSituacao = $situacaoFatura === 'Concluida' ? 'text-bg-success' : ($situacaoFatura === 'Parcial' ? 'text-bg-warning' : 'text-bg-secondary');
                                ?>
                                <td><span class="badge <?= $classeSituacao ?>"><?= $situacaoFatura ?></span></td>
                                <td class="text-center"><?= (int)$fatura['qtd_cp'] ?> CP | <?= (int)$fatura['qtd_bnc'] ?> BNC</td>
                                <td class="text-end">
                                    <a href="cartao_credito.php?competencia=<?= urlencode($competenciaFiltro) ?>&fatura_id=<?= (int)$fatura['id'] ?>&etapa=conferencia" class="btn btn-sm btn-outline-primary">Conferir</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($faturas)): ?>
                            <tr><td colspan="10" class="text-center text-muted py-4">Nenhuma fatura importada nesse filtro.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($faturaAtual && in_array($etapa, ['conferencia', 'processamento'], true)): ?>
<section>
    <div class="card cartao-panel">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Fatura #<?= (int)$faturaAtual['id'] ?></strong>
                <span class="text-muted">| <?= htmlspecialchars($faturaAtual['cartao_nome']) ?> | <?= htmlspecialchars($faturaAtual['competencia']) ?> | Venc. <?= dataCartaoCredito($faturaAtual['data_vencimento']) ?></span>
            </div>
            <a href="cartao_credito.php?competencia=<?= urlencode($competenciaFiltro) ?>" class="btn btn-outline-secondary btn-sm">Voltar às faturas</a>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 mb-4 border-bottom pb-3">
                <a href="cartao_credito.php?competencia=<?= urlencode($competenciaFiltro) ?>&fatura_id=<?= (int)$faturaAtual['id'] ?>&etapa=conferencia" class="btn btn-sm <?= $etapa === 'conferencia' ? 'btn-primary' : 'btn-outline-primary' ?>">1. Conferência</a>
                <a href="cartao_credito.php?competencia=<?= urlencode($competenciaFiltro) ?>&fatura_id=<?= (int)$faturaAtual['id'] ?>&etapa=processamento" class="btn btn-sm <?= $etapa === 'processamento' ? 'btn-primary' : 'btn-outline-primary' ?>">2. Processamento financeiro</a>
            </div>

            <div class="alert alert-light border py-2 small mb-3">
                <?php if ($etapa === 'conferencia'): ?>
                    Confira fornecedores, TIPOES e vencimento. Nenhum lançamento financeiro é criado nesta etapa.
                <?php else: ?>
                    Primeiro concilie com CP001/BNC001 já existentes. Gere um lançamento novo somente quando não houver candidato correto.
                <?php endif; ?>
            </div>

            <?php if ($etapa === 'conferencia'): ?>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button type="submit" form="form-fornecedores-lote" class="btn btn-outline-primary btn-sm">Salvar fornecedores</button>
                <form method="post" onsubmit="return confirm('Atualizar o TIPOES dos lancamentos ja gerados usando o cadastro atual dos fornecedores?');">
                    <input type="hidden" name="acao" value="atualizar_tipoes_fornecedores">
                    <input type="hidden" name="fatura_id" value="<?= (int)$faturaAtual['id'] ?>">
                    <input type="hidden" name="competencia" value="<?= htmlspecialchars($competenciaFiltro) ?>">
                    <input type="hidden" name="etapa" value="conferencia">
                    <button class="btn btn-outline-secondary btn-sm">Atualizar TIPOES</button>
                </form>
            </div>
            <form method="post" id="form-fornecedores-lote">
                <input type="hidden" name="acao" value="mapear_fornecedor_lote">
                <input type="hidden" name="fatura_id" value="<?= (int)$faturaAtual['id'] ?>">
                <input type="hidden" name="competencia" value="<?= htmlspecialchars($competenciaFiltro) ?>">
                <input type="hidden" name="etapa" value="conferencia">
            </form>
            <form method="post" class="border rounded-2 p-3 mb-3" onsubmit="return confirm('Corrigir o vencimento desta fatura e dos CP001 gerados que ainda estejam totalmente abertos?');">
                <input type="hidden" name="acao" value="corrigir_vencimento">
                <input type="hidden" name="fatura_id" value="<?= (int)$faturaAtual['id'] ?>">
                <input type="hidden" name="competencia" value="<?= htmlspecialchars($competenciaFiltro) ?>">
                <input type="hidden" name="etapa" value="conferencia">
                <div class="row g-2 align-items-end">
                    <div class="col-sm-4 col-lg-3">
                        <label class="form-label small fw-semibold">Corrigir vencimento</label>
                        <input type="date" name="novo_vencimento" class="form-control form-control-sm" value="<?= htmlspecialchars((string)$faturaAtual['data_vencimento']) ?>" required>
                    </div>
                    <div class="col-sm-auto">
                        <button class="btn btn-outline-warning btn-sm">Corrigir vencimento</button>
                    </div>
                    <div class="col-12">
                        <small class="text-muted">CP001 apenas vinculados mantêm o vencimento próprio. CP001 pagos ou parcialmente pagos bloqueiam a correção.</small>
                    </div>
                </div>
            </form>
            <?php else: ?>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <form method="post" onsubmit="return confirm('Gerar CP001 para todos os lançamentos prontos?');">
                    <input type="hidden" name="acao" value="gerar_cp001_lote">
                    <input type="hidden" name="fatura_id" value="<?= (int)$faturaAtual['id'] ?>">
                    <input type="hidden" name="competencia" value="<?= htmlspecialchars($competenciaFiltro) ?>">
                    <input type="hidden" name="etapa" value="processamento">
                    <button class="btn btn-success btn-sm">Gerar CP001 prontos</button>
                </form>
            </div>
            <?php endif; ?>
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3">
                    <div class="cartao-kpi">
                        <div class="small">Compras</div>
                        <div class="h5 fw-bold mb-0 cartao-money"><?= moedaCartaoCredito($faturaAtual['total_compras']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="cartao-kpi">
                        <div class="small">Pagamentos</div>
                        <div class="h5 fw-bold mb-0 text-muted cartao-money"><?= moedaCartaoCredito($faturaAtual['total_pagamentos']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="cartao-kpi">
                        <div class="small">Liquido</div>
                        <div class="h5 fw-bold mb-0 cartao-money"><?= moedaCartaoCredito($faturaAtual['total_liquido']) ?></div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="cartao-kpi">
                        <div class="small">Status financeiro</div>
                        <div class="h6 fw-bold mb-0">
                            <?= (int)$resumoFaturaAtual['gerados'] ?> gerados
                            <span class="text-muted">| <?= (int)$resumoFaturaAtual['prontos'] ?> prontos | <?= (int)$resumoFaturaAtual['pendentes'] ?> pend.</span>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($etapa === 'processamento'): ?>
            <div class="cartao-section-title">Gerar BNC001 dos selecionados</div>
            <form method="post" id="form-bnc-lote" class="border rounded-2 p-3 mb-3" onsubmit="return confirm('Lancar os itens selecionados no BNC001?');">
                <input type="hidden" name="acao" value="gerar_bnc001_lote">
                <input type="hidden" name="fatura_id" value="<?= (int)$faturaAtual['id'] ?>">
                <input type="hidden" name="competencia" value="<?= htmlspecialchars($competenciaFiltro) ?>">
                <input type="hidden" name="etapa" value="processamento">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-lg-4">
                        <label class="form-label small fw-semibold">Conta BNC001</label>
                        <select name="bnc_cbcontador" class="form-select form-select-sm" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($contasCartao as $contaCartao): ?>
                                <option value="<?= (int)$contaCartao['CBCONTADOR'] ?>">
                                    <?= (int)$contaCartao['CBCONTADOR'] ?> - <?= htmlspecialchars($contaCartao['nome_conta']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-lg-4">
                        <label class="form-label small fw-semibold">TIPOES BNC001</label>
                        <select name="bnc_tipoes" class="form-select form-select-sm" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($tiposCartao as $tipoCartao): ?>
                                <option value="<?= (int)$tipoCartao['ESCONTADOR'] ?>">
                                    <?= htmlspecialchars(($tipoCartao['TIPOMOV'] ?? '') . ' - ' . $tipoCartao['ESCONTADOR'] . ' - ' . ($tipoCartao['DESCES'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-lg-3">
                        <label class="form-label small fw-semibold">Conta contrapartida</label>
                        <select name="bnc_contrap_cbcontador" class="form-select form-select-sm">
                            <option value="">Somente se o TIPOES pedir</option>
                            <?php foreach ($contasCartao as $contaCartao): ?>
                                <option value="<?= (int)$contaCartao['CBCONTADOR'] ?>">
                                    <?= (int)$contaCartao['CBCONTADOR'] ?> - <?= htmlspecialchars($contaCartao['nome_conta']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-lg-1 d-flex">
                        <button class="btn btn-primary btn-sm w-100">BNC</button>
                    </div>
                    <div class="col-12">
                        <small class="text-muted">Selecione linhas sem CP/BNC na grade. O sistema valida D/C do TIPOES antes de lancar.</small>
                    </div>
                </div>
            </form>
            <?php endif; ?>

            <div class="cartao-section-title">Filtros dos lançamentos</div>
            <form method="get" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="competencia" value="<?= htmlspecialchars($competenciaFiltro) ?>">
                <input type="hidden" name="fatura_id" value="<?= (int)$faturaAtual['id'] ?>">
                <input type="hidden" name="etapa" value="<?= htmlspecialchars($etapa) ?>">
                <div class="col-6 col-lg-2">
                    <label class="form-label small fw-semibold">Data inicial</label>
                    <input type="date" name="l_data_ini" class="form-control form-control-sm" value="<?= htmlspecialchars($lancDataIni) ?>">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small fw-semibold">Data final</label>
                    <input type="date" name="l_data_fim" class="form-control form-control-sm" value="<?= htmlspecialchars($lancDataFim) ?>">
                </div>
                <div class="col-12 col-lg-3">
                    <label class="form-label small fw-semibold">Lancamento</label>
                    <input type="text" name="l_busca" class="form-control form-control-sm" value="<?= htmlspecialchars($lancBusca) ?>" placeholder="Descricao, categoria ou tipo">
                </div>
                <div class="col-12 col-lg-3">
                    <label class="form-label small fw-semibold">Fornecedor</label>
                    <input type="text" name="l_fornecedor" class="form-control form-control-sm" value="<?= htmlspecialchars($lancFornecedor) ?>" placeholder="Nome ou codigo">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small fw-semibold">Natureza</label>
                    <select name="l_natureza" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        <option value="D" <?= $lancNatureza === 'D' ? 'selected' : '' ?>>Compra</option>
                        <option value="C" <?= $lancNatureza === 'C' ? 'selected' : '' ?>>Credito/Pagamento</option>
                    </select>
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label small fw-semibold">TIPOES</label>
                    <select name="l_tipoes" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="sem_padrao" <?= $lancTipoesParam === 'sem_padrao' ? 'selected' : '' ?>>Sem padrao</option>
                        <?php foreach ($tiposCartao as $tipoCartao): ?>
                            <option value="<?= (int)$tipoCartao['ESCONTADOR'] ?>" <?= $lancTipoes === (int)$tipoCartao['ESCONTADOR'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(($tipoCartao['DESCES'] ?? '') . ' (' . $tipoCartao['ESCONTADOR'] . ' - ' . $tipoCartao['TIPOMOV'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="l_status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="pendente" <?= $lancStatus === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                        <option value="pronto" <?= $lancStatus === 'pronto' ? 'selected' : '' ?>>Pronto</option>
                        <option value="gerado" <?= $lancStatus === 'gerado' ? 'selected' : '' ?>>Gerado</option>
                        <option value="sem_financeiro" <?= in_array($lancStatus, ['sem_financeiro', 'ignorado'], true) ? 'selected' : '' ?>>Sem financeiro</option>
                    </select>
                </div>
                <div class="col-12 col-lg-4 d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-primary btn-sm">Filtrar</button>
                    <a class="btn btn-outline-secondary btn-sm" href="cartao_credito.php?competencia=<?= urlencode($competenciaFiltro) ?>&fatura_id=<?= (int)$faturaAtual['id'] ?>&etapa=<?= urlencode($etapa) ?>">Limpar filtros</a>
                    <span class="text-muted small align-self-center"><?= count($lancamentos) ?> lancamento(s)</span>
                </div>
            </form>

            <div class="cartao-section-title">Lancamentos da fatura</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 cartao-table">
                    <thead class="table-light">
                        <tr>
                            <?php if ($etapa === 'processamento'): ?><th>Sel.</th><?php endif; ?>
                            <th>Data</th>
                            <th class="cartao-desc">Lancamento</th>
                            <th>Categoria</th>
                            <th>Tipo</th>
                            <th class="text-end">Valor</th>
                            <th>Fornecedor</th>
                            <th>TIPOES</th>
                            <th>Status</th>
                            <?php if ($etapa === 'processamento'): ?><th>Ação</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lancamentos as $linha): ?>
                            <?php $ehCompra = $linha['natureza'] === 'D'; ?>
                            <?php $possuiFinanceiro = !empty($linha['cpcontador']) || !empty($linha['bnc001_movcontador']); ?>
                            <tr class="<?= $ehCompra ? '' : 'table-secondary' ?>">
                                <?php if ($etapa === 'processamento'): ?>
                                    <td data-label="Sel.">
                                        <?php if (!$possuiFinanceiro): ?>
                                            <input type="checkbox" name="lancamentos_bnc[]" value="<?= (int)$linha['id'] ?>" form="form-bnc-lote">
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td data-label="Data"><?= dataCartaoCredito($linha['data_compra']) ?></td>
                                <td data-label="Lancamento" class="cartao-desc">
                                    <div class="fw-semibold"><?= htmlspecialchars($linha['descricao']) ?></div>
                                    <?php if (!$ehCompra): ?><small class="text-muted">Credito/pagamento da fatura. Pode ser lancado no BNC001.</small><?php endif; ?>
                                </td>
                                <td data-label="Categoria"><?= htmlspecialchars($linha['categoria'] ?? '') ?></td>
                                <td data-label="Tipo"><?= htmlspecialchars($linha['tipo_lancamento'] ?? '') ?></td>
                                <td data-label="Valor" class="text-end cartao-money <?= $ehCompra ? '' : 'text-muted' ?>"><?= $ehCompra ? moedaCartaoCredito($linha['valor']) : '-' . moedaCartaoCredito($linha['valor']) ?></td>
                                <td data-label="Fornecedor">
                                    <?php if ($ehCompra): ?>
                                        <?php if (!empty($linha['fornecedor_fcontador']) && ($etapa !== 'conferencia' || $possuiFinanceiro)): ?>
                                            <div class="small fw-semibold">
                                                <?= (int)$linha['fornecedor_fcontador'] ?> - <?= htmlspecialchars($linha['fornecedor_apelido'] ?: $linha['fornecedor_nome'] ?: 'Fornecedor') ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($etapa === 'conferencia' && !$possuiFinanceiro): ?>
                                            <div class="cartao-fornecedor-input">
                                                <div class="cartao-fornecedor-busca mb-1">
                                                    <input type="search" class="form-control form-control-sm cartao-fornecedor-termo" autocomplete="off" placeholder="Buscar fornecedor pelo nome..." value="<?= htmlspecialchars((string)($linha['fornecedor_apelido'] ?: $linha['fornecedor_nome'] ?: '')) ?>">
                                                    <input type="hidden" name="fcontador_lote[<?= (int)$linha['id'] ?>]" form="form-fornecedores-lote" class="cartao-fornecedor-codigo" value="<?= (int)($linha['fornecedor_fcontador'] ?? 0) ?>">
                                                    <div class="cartao-fornecedor-resultados d-none"></div>
                                                </div>
                                                <input type="text" name="novo_fornecedor_lote[<?= (int)$linha['id'] ?>]" form="form-fornecedores-lote" class="form-control form-control-sm" value="<?= htmlspecialchars(sugerirNomeFornecedorCartao($linha['descricao'])) ?>" placeholder="Ou novo fornecedor">
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="TIPOES">
                                    <?php if ($ehCompra): ?>
                                        <?php
                                            $tipoesExibicao = (int)($linha['cp_tipoes'] ?: $linha['fornecedor_tipoes'] ?: 301);
                                            $origemTipoes = !empty($linha['cp_tipoes'])
                                                ? 'CP001'
                                                : (!empty($linha['fornecedor_tipoes']) ? 'Fornecedor' : 'Padrao cartao');
                                        ?>
                                        <div class="fw-semibold"><?= htmlspecialchars($linha['tipoes_desc'] ?: ('Tipo ' . $tipoesExibicao)) ?></div>
                                        <div class="small text-muted"><?= $tipoesExibicao ?> | <?= htmlspecialchars($origemTipoes) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status" class="cartao-status">
                                    <?php if (!empty($linha['cpcontador'])): ?>
                                        <span class="badge text-bg-success">CP <?= (int)$linha['cpcontador'] ?></span>
                                        <?php if (($linha['status'] ?? '') === 'CP_VINCULADO'): ?><div class="small text-muted">Vinculado</div><?php endif; ?>
                                    <?php elseif (!empty($linha['bnc001_movcontador'])): ?>
                                        <span class="badge text-bg-primary">BNC <?= (int)$linha['bnc001_movcontador'] ?></span>
                                        <div class="small text-muted"><?= (int)($linha['bnc_cbcontador'] ?? 0) ?> | <?= htmlspecialchars((string)($linha['bnc_tipomov'] ?? '')) ?></div>
                                    <?php elseif (!$ehCompra): ?>
                                        <span class="badge text-bg-light text-dark border">Pendente BNC</span>
                                    <?php elseif (!empty($linha['fornecedor_fcontador'])): ?>
                                        <span class="badge text-bg-warning">Pronto</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-light text-dark border">Pendente</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($etapa === 'processamento'): ?>
                                <td data-label="Ação" class="text-end">
                                    <?php
                                        $candidatosBncLinha = $candidatosBnc[(int)$linha['id']] ?? [];
                                        $candidatosCpLinha = $candidatosCp[(int)$linha['id']] ?? [];
                                        $candidatosPesquisados = count($lancamentos) <= 10 || $candidatoLancamentoId === (int)$linha['id'];
                                        $queryBuscarCandidatos = array_merge($_GET, [
                                            'competencia' => $competenciaFiltro,
                                            'fatura_id' => (int)$faturaAtual['id'],
                                            'etapa' => 'processamento',
                                            'candidato_lancamento' => (int)$linha['id'],
                                        ]);
                                    ?>
                                    <?php if (!$possuiFinanceiro && !$candidatosPesquisados): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="cartao_credito.php?<?= htmlspecialchars(http_build_query($queryBuscarCandidatos)) ?>">Buscar CP/BNC</a>
                                    <?php endif; ?>
                                    <?php if (!$possuiFinanceiro && $ehCompra && !empty($candidatosCpLinha)): ?>
                                        <form method="post" class="mb-1" onsubmit="return confirm('Conciliar esta linha com o CP001 selecionado?');">
                                            <input type="hidden" name="acao" value="vincular_cp001">
                                            <input type="hidden" name="fatura_id" value="<?= (int)$faturaAtual['id'] ?>">
                                            <input type="hidden" name="competencia" value="<?= htmlspecialchars($competenciaFiltro) ?>">
                                            <input type="hidden" name="etapa" value="processamento">
                                            <input type="hidden" name="lancamento_id" value="<?= (int)$linha['id'] ?>">
                                            <div class="d-flex gap-1 justify-content-end">
                                                <select name="cpcontador" class="form-select form-select-sm" style="min-width:320px" required>
                                                    <option value="">Candidatos CP001...</option>
                                                    <?php foreach ($candidatosCpLinha as $candidatoCp): ?>
                                                        <option value="<?= (int)$candidatoCp['CPCONTADOR'] ?>">
                                                            CP <?= (int)$candidatoCp['CPCONTADOR'] ?> | Venc. <?= dataCartaoCredito($candidatoCp['DTVENC']) ?> | <?= htmlspecialchars($candidatoCp['fornecedor_nome']) ?> | <?= htmlspecialchars(mb_substr((string)$candidatoCp['TITULO'], 0, 55)) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="btn btn-sm btn-primary text-nowrap">Conciliar CP</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (!$possuiFinanceiro && !empty($candidatosBncLinha)): ?>
                                        <form method="post" class="mb-1" onsubmit="return confirm('Conciliar esta linha com o BNC001 selecionado?');">
                                            <input type="hidden" name="acao" value="vincular_bnc001">
                                            <input type="hidden" name="fatura_id" value="<?= (int)$faturaAtual['id'] ?>">
                                            <input type="hidden" name="competencia" value="<?= htmlspecialchars($competenciaFiltro) ?>">
                                            <input type="hidden" name="etapa" value="processamento">
                                            <input type="hidden" name="lancamento_id" value="<?= (int)$linha['id'] ?>">
                                            <div class="d-flex gap-1 justify-content-end">
                                                <select name="movcontador" class="form-select form-select-sm" style="min-width:320px" required>
                                                    <option value="">Candidatos BNC001...</option>
                                                    <?php foreach ($candidatosBncLinha as $candidatoBnc): ?>
                                                        <option value="<?= (int)$candidatoBnc['MOVCONTADOR'] ?>">
                                                            MOV <?= (int)$candidatoBnc['MOVCONTADOR'] ?> | <?= dataCartaoCredito($candidatoBnc['DTMOV']) ?> | <?= (int)$candidatoBnc['CBCONTADOR'] ?> - <?= htmlspecialchars($candidatoBnc['conta_nome']) ?> | <?= htmlspecialchars(mb_substr((string)$candidatoBnc['HISTMOV'], 0, 55)) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="btn btn-sm btn-primary text-nowrap">Conciliar BNC</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (!$possuiFinanceiro && $candidatosPesquisados && empty($candidatosCpLinha) && empty($candidatosBncLinha)): ?>
                                        <div class="small text-muted mb-1">Sem candidato CP001/BNC001 compatível.</div>
                                    <?php endif; ?>
                                    <?php if ($ehCompra && !$possuiFinanceiro && !empty($linha['fornecedor_fcontador'])): ?>
                                        <form method="post" onsubmit="return confirm('Gerar este lancamento no CP001?');">
                                            <input type="hidden" name="acao" value="gerar_cp001">
                                            <input type="hidden" name="fatura_id" value="<?= (int)$faturaAtual['id'] ?>">
                                            <input type="hidden" name="competencia" value="<?= htmlspecialchars($competenciaFiltro) ?>">
                                            <input type="hidden" name="etapa" value="processamento">
                                            <input type="hidden" name="lancamento_id" value="<?= (int)$linha['id'] ?>">
                                            <button class="btn btn-sm btn-success">Gerar CP001</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($lancamentos)): ?>
                            <tr><td colspan="<?= $etapa === 'processamento' ? 10 : 8 ?>" class="text-center text-muted py-4">Nenhum lançamento nesta fatura.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<script>
(() => {
    const fornecedores = <?= json_encode(array_map(static function ($fornecedor) {
        return [
            'codigo' => (int)$fornecedor['FCONTADOR'],
            'nome' => (string)$fornecedor['nome'],
        ];
    }, $fornecedores), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const normalizar = (texto) => String(texto || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleUpperCase('pt-BR');

    document.querySelectorAll('.cartao-fornecedor-busca').forEach((busca) => {
        const termo = busca.querySelector('.cartao-fornecedor-termo');
        const codigo = busca.querySelector('.cartao-fornecedor-codigo');
        const resultados = busca.querySelector('.cartao-fornecedor-resultados');
        let opcoes = [];
        let ativo = -1;

        const fechar = () => {
            resultados.classList.add('d-none');
            resultados.innerHTML = '';
            opcoes = [];
            ativo = -1;
        };
        const selecionar = (fornecedor) => {
            termo.value = fornecedor.nome;
            codigo.value = fornecedor.codigo;
            fechar();
        };
        const renderizar = () => {
            codigo.value = '';
            const filtro = normalizar(termo.value.trim());
            if (!filtro) {
                fechar();
                return;
            }
            opcoes = fornecedores.filter((fornecedor) => normalizar(fornecedor.nome).includes(filtro)).slice(0, 20);
            ativo = -1;
            resultados.innerHTML = '';
            opcoes.forEach((fornecedor) => {
                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'cartao-fornecedor-opcao';
                botao.textContent = fornecedor.nome;
                botao.addEventListener('mousedown', (evento) => {
                    evento.preventDefault();
                    selecionar(fornecedor);
                });
                resultados.appendChild(botao);
            });
            resultados.classList.toggle('d-none', opcoes.length === 0);
        };
        const marcarAtivo = () => {
            resultados.querySelectorAll('.cartao-fornecedor-opcao').forEach((opcao, indice) => {
                opcao.classList.toggle('ativo', indice === ativo);
            });
        };

        termo.addEventListener('input', renderizar);
        termo.addEventListener('focus', renderizar);
        termo.addEventListener('blur', () => window.setTimeout(fechar, 120));
        termo.addEventListener('keydown', (evento) => {
            if (!opcoes.length || !['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].includes(evento.key)) return;
            evento.preventDefault();
            if (evento.key === 'Escape') return fechar();
            if (evento.key === 'ArrowDown') ativo = Math.min(ativo + 1, opcoes.length - 1);
            if (evento.key === 'ArrowUp') ativo = Math.max(ativo - 1, 0);
            if (evento.key === 'Enter' && ativo >= 0) return selecionar(opcoes[ativo]);
            marcarAtivo();
        });
    });
})();
</script>

<?php require '../../layout/footer.php'; ?>
