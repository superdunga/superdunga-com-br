<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require __DIR__ . '/_empresa2_guard.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

function mbFloat($valor)
{
    if ($valor === null || $valor === '') {
        return 0.0;
    }

    $valor = trim((string)$valor);
    $valor = str_replace(['R$', ' '], '', $valor);

    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }

    return (float)$valor;
}

function mbMoeda($valor)
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function mbDataHora($valor)
{
    if (!$valor) {
        return '';
    }
    return date('d/m/Y H:i', strtotime($valor));
}

function mbData($valor)
{
    if (!$valor) {
        return '';
    }
    return date('d/m/Y', strtotime($valor));
}

function mbNormalizarDataMovimento($valor)
{
    $valor = trim((string)$valor);
    if ($valor === '') {
        return null;
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m)) {
        $ano = (int)$m[1];
        if ($ano < 2000 || $ano > 2099 || !checkdate((int)$m[2], (int)$m[3], $ano)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $ano, (int)$m[2], (int)$m[3]);
    }

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})$/', $valor, $m)) {
        $ano = (int)$m[3];
        if ($ano < 100) {
            $ano += 2000;
        }
        if ($ano < 2000 || $ano > 2099 || !checkdate((int)$m[2], (int)$m[1], $ano)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $ano, (int)$m[2], (int)$m[1]);
    }

    return null;
}

function mbH($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function mbGarantirEstruturaContrapartidaAberta(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mov_baixa_contrapartidas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            movcontador INT NOT NULL,
            tipo_contrapartida ENUM('CP','CR') NOT NULL,
            contador_contrapartida INT NOT NULL,
            valor DECIMAL(15,2) NOT NULL DEFAULT 0,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mb_contrap_mov (empresa_id, movcontador),
            UNIQUE KEY uniq_mb_contrap_titulo (empresa_id, tipo_contrapartida, contador_contrapartida),
            KEY idx_mb_contrap_empresa_tipo (empresa_id, tipo_contrapartida)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

mbGarantirEstruturaContrapartidaAberta($pdo);

function mbProximoMovcontador(PDO $pdo)
{
    $stmt = $pdo->query("SELECT COALESCE(MAX(MOVCONTADOR), 0) + 1 FROM armazem_bnc001");
    return (int)$stmt->fetchColumn();
}

function mbProximoCpcontador(PDO $pdo, $empresaId)
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CPCONTADOR), 0) + 1 FROM armazem_cp001 WHERE EMPRESA = ?");
    $stmt->execute([$empresaId]);
    return (int)$stmt->fetchColumn();
}

function mbProximoCrcontador(PDO $pdo, $empresaId)
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CRCONTADOR), 0) + 1 FROM armazem_cr001 WHERE EMPRESA = ?");
    $stmt->execute([$empresaId]);
    return (int)$stmt->fetchColumn();
}

function mbInvertirTipomov($tipomov)
{
    return strtoupper((string)$tipomov) === 'D' ? 'C' : 'D';
}

function mbNomeTipomov($tipomov)
{
    $tipomov = strtoupper((string)$tipomov);
    if ($tipomov === 'D') {
        return 'Debito';
    }
    if ($tipomov === 'C') {
        return 'Credito';
    }
    return '';
}

function mbBuscarConta(PDO $pdo, $empresaId, $cbcontador)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_bnc002
        WHERE EMPRESA = ?
          AND CBCONTADOR = ?
          AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $cbcontador]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function mbBuscarTipo(PDO $pdo, $empresaId, $tipoes)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_bnc005
        WHERE EMPRESA = ?
          AND ESCONTADOR = ?
          AND COALESCE(REGDISAB, 'N') <> 'S'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $tipoes]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function mbBuscarFornecedor(PDO $pdo, $empresaId, $fcontador)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_cp003
        WHERE EMPRESA = ?
          AND FCONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(INATIVO, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $fcontador]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function mbBuscarCliente(PDO $pdo, $empresaId, $clicontador)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_cr002
        WHERE EMPRESA = ?
          AND CLICONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND COALESCE(INATIVO, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $clicontador]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function mbDescricaoConta($conta)
{
    if (!$conta) {
        return '';
    }

    if (!empty($conta['DESCABREV'])) {
        $fallback = $conta['DESCABREV'];
    } elseif (!empty($conta['NUMERO'])) {
        $fallback = $conta['NUMERO'];
    } else {
        $fallback = $conta['CBCONTADOR'] ?? '';
    }

    return trim((string)($conta['TITULAR'] ?? '')) ?: (string)$fallback;
}

function mbCarregarContrapartida(PDO $pdo, $empresaId, $movcontador)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_bnc001
        WHERE EMPRESA = ?
          AND ORIGEMCPART = ?
          AND COALESCE(deletado, 'N') <> 'S'
          AND TIPODOCORIGEM = 'SUPERDUNGA'
        ORDER BY MOVCONTADOR
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $movcontador]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function mbCarregarContrapartidaAberta(PDO $pdo, $empresaId, $movcontador)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM mov_baixa_contrapartidas
        WHERE empresa_id = ?
          AND movcontador = ?
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $movcontador]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function mbMovimentoTemContrapartidaAberta(PDO $pdo, $empresaId, $movcontador)
{
    return (bool)mbCarregarContrapartidaAberta($pdo, $empresaId, $movcontador);
}

function mbValidarContrapartidaAberta(PDO $pdo, $empresaId, array $dados)
{
    $erros = [];
    $criar = !empty($dados['criar_contrap_aberta']);

    if (!$criar) {
        return $erros;
    }

    $tipo = strtoupper((string)($dados['contrap_aberta_tipo'] ?? ''));
    if (!in_array($tipo, ['CP', 'CR'], true)) {
        $erros[] = 'Informe se a contrapartida em aberto sera a pagar ou a receber.';
    }

    if (empty($dados['contrap_aberta_vencimento'])) {
        $erros[] = 'Informe o vencimento da contrapartida em aberto.';
    }

    $valorContrap = mbFloat($dados['contrap_aberta_valor'] ?? $dados['valor'] ?? 0);
    if ($valorContrap <= 0) {
        $erros[] = 'Informe um valor valido para a contrapartida em aberto.';
    }

    if (empty($dados['contrap_aberta_tipoes']) || !mbBuscarTipo($pdo, $empresaId, (int)$dados['contrap_aberta_tipoes'])) {
        $erros[] = 'Informe um TIPOES valido para a contrapartida em aberto.';
    }

    if ($tipo === 'CP') {
        if (empty($dados['contrap_aberta_fcontador']) || !mbBuscarFornecedor($pdo, $empresaId, (int)$dados['contrap_aberta_fcontador'])) {
            $erros[] = 'Informe um fornecedor valido para a contrapartida a pagar.';
        }
    } elseif ($tipo === 'CR') {
        if (empty($dados['contrap_aberta_clicontador']) || !mbBuscarCliente($pdo, $empresaId, (int)$dados['contrap_aberta_clicontador'])) {
            $erros[] = 'Informe um cliente valido para a contrapartida a receber.';
        }
    }

    return $erros;
}

function mbCriarContrapartidaAberta(PDO $pdo, $empresaId, $usuarioId, $movcontador, array $dados)
{
    if (empty($dados['criar_contrap_aberta'])) {
        return null;
    }

    $tipo = strtoupper((string)($dados['contrap_aberta_tipo'] ?? ''));
    $valor = mbFloat($dados['contrap_aberta_valor'] ?? $dados['valor'] ?? 0);
    $vencimento = $dados['contrap_aberta_vencimento'];
    $tipoes = (int)$dados['contrap_aberta_tipoes'];
    $historicoBase = trim((string)($dados['historico'] ?? ''));
    $historico = trim((string)($dados['contrap_aberta_historico'] ?? ''));
    if ($historico === '') {
        $historico = 'CONTRAPARTIDA EM ABERTO - ' . $historicoBase;
    }
    $numdoc = trim((string)($dados['numdoc'] ?? ''));
    $titulo = $numdoc !== '' ? $numdoc : ('MOV ' . $movcontador);

    if ($tipo === 'CP') {
        $contador = mbProximoCpcontador($pdo, $empresaId);
        $chave = 'MOVBAIXA-CONTRAP-CP-' . $empresaId . '-' . $movcontador . '-' . $contador;
        $stmt = $pdo->prepare("
            INSERT INTO armazem_cp001 (
                EMPRESA, CPCONTADOR, DTCOMPRA, NUMPARCELA, TITULO, VALORCOMPRA,
                FCONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
                VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
                TIPOCP, TIPOES, NOTAFISCAL, REGSTAMP, REGIMPORT, USERLANC, DTLANC,
                USERALT, DTALT, CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
            ) VALUES (
                ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, '1/1', ?, ?, 0, 'AB', 'SUPERDUNGA', ?, 'MOV_BAIXA_CONTRAPARTIDA',
                'CP', ?, NULL, NOW(), 'S', ?, NOW(), ?, NOW(), ?, 'N', 'N'
            )
        ");
        $stmt->execute([
            $empresaId,
            $contador,
            $dados['dtmov'],
            $titulo,
            $valor,
            (int)$dados['contrap_aberta_fcontador'],
            $historico,
            $dados['dtmov'],
            $valor,
            $vencimento,
            $valor,
            $movcontador,
            $tipoes,
            $usuarioId ?: null,
            $usuarioId ?: null,
            $chave,
        ]);
    } else {
        $contador = mbProximoCrcontador($pdo, $empresaId);
        $chave = 'MOVBAIXA-CONTRAP-CR-' . $empresaId . '-' . $movcontador . '-' . $contador;
        $stmt = $pdo->prepare("
            INSERT INTO armazem_cr001 (
                EMPRESA, CRCONTADOR, DTVENDA, NUMPARCELA, TITULO, VALORVENDA,
                CLICONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
                VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
                TIPOCR, TIPOES, NOTAFISCAL, REGSTAMP, USERLANC, DTLANC,
                USERALT, DTALT, CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
            ) VALUES (
                ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, '1/1', ?, ?, 0, 'AB', 'SUPERDUNGA', ?, 'MOV_BAIXA_CONTRAPARTIDA',
                'CR', ?, NULL, NOW(), ?, NOW(), ?, NOW(), ?, 'N', 'N'
            )
        ");
        $stmt->execute([
            $empresaId,
            $contador,
            $dados['dtmov'],
            $titulo,
            $valor,
            (int)$dados['contrap_aberta_clicontador'],
            $historico,
            $dados['dtmov'],
            $valor,
            $vencimento,
            $valor,
            $movcontador,
            $tipoes,
            $usuarioId ?: null,
            $usuarioId ?: null,
            $chave,
        ]);
    }

    $stmtVinculo = $pdo->prepare("
        INSERT INTO mov_baixa_contrapartidas
            (empresa_id, movcontador, tipo_contrapartida, contador_contrapartida, valor, criado_por)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmtVinculo->execute([
        $empresaId,
        $movcontador,
        $tipo,
        $contador,
        $valor,
        $usuarioId ?: null,
    ]);

    return ['tipo' => $tipo, 'contador' => $contador];
}

function mbValidarLancamento(PDO $pdo, $empresaId, $dados)
{
    $erros = [];

    if (empty($dados['dtmov']) || !mbNormalizarDataMovimento($dados['dtmov'])) {
        $erros[] = 'Informe uma data do movimento valida.';
    }

    if (empty($dados['cbcontador']) || !mbBuscarConta($pdo, $empresaId, $dados['cbcontador'])) {
        $erros[] = 'Informe uma conta valida.';
    }

    $tipo = null;
    if (empty($dados['tipoes'])) {
        $erros[] = 'Informe o tipo de movimentacao.';
    } else {
        $tipo = mbBuscarTipo($pdo, $empresaId, $dados['tipoes']);
        if (!$tipo) {
            $erros[] = 'Tipo de movimentacao nao encontrado.';
        } elseif (empty($tipo['TIPOMOV'])) {
            $erros[] = 'O tipo de movimentacao selecionado nao possui TIPOMOV configurado.';
        }
    }

    if (mbFloat($dados['valor']) <= 0) {
        $erros[] = 'Informe um valor maior que zero.';
    }

    if (trim((string)$dados['historico']) === '') {
        $erros[] = 'Informe o historico.';
    }

    $contrapTipo = $tipo && !empty($tipo['CONTRAP_TIPOES']) ? (int)$tipo['CONTRAP_TIPOES'] : 0;
    if ($contrapTipo > 0) {
        $contrapConta = !empty($dados['contrap_cbcontador']) ? (int)$dados['contrap_cbcontador'] : (int)($tipo['CONTRAP_CBCONTADOR'] ?? 0);
        if ($contrapConta <= 0 || !mbBuscarConta($pdo, $empresaId, $contrapConta)) {
            $erros[] = 'Informe a conta da contrapartida.';
        }
        $tipoContrap = mbBuscarTipo($pdo, $empresaId, $contrapTipo);
        if (!$tipoContrap) {
            $erros[] = 'Tipo de contrapartida nao encontrado no plano de contas.';
        }
    }

    $erros = array_merge($erros, mbValidarContrapartidaAberta($pdo, $empresaId, $dados));

    return [$erros, $tipo];
}

function mbSalvarLancamento(PDO $pdo, $empresaId, $usuarioId, $dados, $movcontadorEdicao = null)
{
    $dados['dtmov'] = mbNormalizarDataMovimento($dados['dtmov'] ?? '');
    list($erros, $tipo) = mbValidarLancamento($pdo, $empresaId, $dados);
    if ($erros) {
        throw new RuntimeException(implode(' ', $erros));
    }

    $valor = mbFloat($dados['valor']);
    $tipoes = (int)$dados['tipoes'];
    $tipomov = strtoupper((string)$tipo['TIPOMOV']);
    $contrapTipoes = !empty($tipo['CONTRAP_TIPOES']) ? (int)$tipo['CONTRAP_TIPOES'] : 0;
    $exigeContrap = $contrapTipoes > 0;
    $contrapCbcontador = $exigeContrap
        ? (int)($dados['contrap_cbcontador'] ?: ($tipo['CONTRAP_CBCONTADOR'] ?? 0))
        : null;
    $contrapTipomov = $exigeContrap
        ? strtoupper((string)($tipo['CONTRAP_TIPOMOV'] ?: mbInvertirTipomov($tipomov)))
        : null;
    $numdoc = trim((string)($dados['numdoc'] ?? ''));
    $historico = trim((string)$dados['historico']);

    $pdo->beginTransaction();

    try {
        if ($movcontadorEdicao) {
            $stmt = $pdo->prepare("
                SELECT *
                FROM armazem_bnc001
                WHERE EMPRESA = ?
                  AND MOVCONTADOR = ?
                  AND TIPODOCORIGEM = 'SUPERDUNGA'
                  AND COALESCE(ORIGEMCPART, 0) = 0
                  AND COALESCE(deletado, 'N') <> 'S'
                LIMIT 1
            ");
            $stmt->execute([$empresaId, $movcontadorEdicao]);
            $atual = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$atual) {
                throw new RuntimeException('Lancamento nao encontrado para edicao.');
            }

            if (mbMovimentoTemContrapartidaAberta($pdo, $empresaId, $movcontadorEdicao)) {
                throw new RuntimeException('Lancamento com contrapartida em aberto nao pode ser editado. O par deve permanecer integro.');
            }

            if (mbMovimentoVinculadoAcerto($pdo, $empresaId, $movcontadorEdicao)) {
                throw new RuntimeException('Lancamento vinculado a acerto ativo nao pode ser editado. Desfaca o acerto antes de alterar.');
            }

            $contrapAtual = mbCarregarContrapartida($pdo, $empresaId, $movcontadorEdicao);
            if ($contrapAtual && mbMovimentoVinculadoAcerto($pdo, $empresaId, (int)$contrapAtual['MOVCONTADOR'])) {
                throw new RuntimeException('Contrapartida vinculada a acerto ativo nao pode ser editada. Desfaca o acerto antes de alterar.');
            }

            $stmt = $pdo->prepare("
                UPDATE armazem_bnc001
                SET DTMOV = ?,
                    NUMDOC = ?,
                    CBCONTADOR = ?,
                    TIPOES = ?,
                    TIPOMOV = ?,
                    HISTMOV = ?,
                    VALORMOV = ?,
                    CONTRAPARTIDA = ?,
                    USERBNCALT = ?,
                    DTALT = NOW(),
                    REGSTAMP = NOW()
                WHERE EMPRESA = ?
                  AND MOVCONTADOR = ?
            ");
            $stmt->execute([
                $dados['dtmov'],
                $numdoc !== '' ? $numdoc : null,
                (int)$dados['cbcontador'],
                $tipoes,
                $tipomov,
                $historico,
                $valor,
                $exigeContrap ? 'S' : 'N',
                $usuarioId,
                $empresaId,
                $movcontadorEdicao,
            ]);

            $movcontadorPrincipal = (int)$movcontadorEdicao;

            if ($exigeContrap) {
                if ($contrapAtual) {
                    $stmt = $pdo->prepare("
                        UPDATE armazem_bnc001
                        SET DTMOV = ?,
                            NUMDOC = ?,
                            CBCONTADOR = ?,
                            TIPOES = ?,
                            TIPOMOV = ?,
                            HISTMOV = ?,
                            VALORMOV = ?,
                            USERBNCALT = ?,
                            DTALT = NOW(),
                            REGSTAMP = NOW()
                        WHERE EMPRESA = ?
                          AND MOVCONTADOR = ?
                    ");
                    $stmt->execute([
                        $dados['dtmov'],
                        $numdoc !== '' ? $numdoc : null,
                        $contrapCbcontador,
                        $contrapTipoes,
                        $contrapTipomov,
                        'CONTRAPARTIDA - ' . $historico,
                        $valor,
                        $usuarioId,
                        $empresaId,
                        (int)$contrapAtual['MOVCONTADOR'],
                    ]);
                } else {
                    $movcontadorContrap = mbProximoMovcontador($pdo);
                    $stmt = $pdo->prepare("
                        INSERT INTO armazem_bnc001
                            (EMPRESA, MOVCONTADOR, DTMOV, NUMDOC, CBCONTADOR, TIPOES, TIPOMOV, HISTMOV, VALORMOV,
                             TIPODOCORIGEM, NUMDOCORIGEM, CONTRAPARTIDA, ORIGEMCPART, USERBNCLANC, DTLANC,
                             DTPROCESSADO, REGSTAMP, deletado)
                        VALUES
                            (?, ?, ?, ?, ?, ?, ?, ?, ?, 'SUPERDUNGA', ?, 'N', ?, ?, NOW(), NOW(), NOW(), 'N')
                    ");
                    $stmt->execute([
                        $empresaId,
                        $movcontadorContrap,
                        $dados['dtmov'],
                        $numdoc !== '' ? $numdoc : null,
                        $contrapCbcontador,
                        $contrapTipoes,
                        $contrapTipomov,
                        'CONTRAPARTIDA - ' . $historico,
                        $valor,
                        $numdoc !== '' ? $numdoc : $movcontadorPrincipal,
                        $movcontadorPrincipal,
                        $usuarioId,
                    ]);
                }
            } elseif ($contrapAtual) {
                $stmt = $pdo->prepare("
                    DELETE FROM armazem_bnc001
                    WHERE EMPRESA = ?
                      AND MOVCONTADOR = ?
                      AND TIPODOCORIGEM = 'SUPERDUNGA'
                      AND ORIGEMCPART = ?
                ");
                $stmt->execute([$empresaId, (int)$contrapAtual['MOVCONTADOR'], $movcontadorPrincipal]);
            }
        } else {
            $movcontadorPrincipal = mbProximoMovcontador($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO armazem_bnc001
                    (EMPRESA, MOVCONTADOR, DTMOV, NUMDOC, CBCONTADOR, TIPOES, TIPOMOV, HISTMOV, VALORMOV,
                     TIPODOCORIGEM, NUMDOCORIGEM, CONTRAPARTIDA, ORIGEMCPART, USERBNCLANC, DTLANC,
                     DTPROCESSADO, REGSTAMP, deletado)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, 'SUPERDUNGA', ?, ?, 0, ?, NOW(), NOW(), NOW(), 'N')
            ");
            $stmt->execute([
                $empresaId,
                $movcontadorPrincipal,
                $dados['dtmov'],
                $numdoc !== '' ? $numdoc : null,
                (int)$dados['cbcontador'],
                $tipoes,
                $tipomov,
                $historico,
                $valor,
                $numdoc !== '' ? $numdoc : $movcontadorPrincipal,
                $exigeContrap ? 'S' : 'N',
                $usuarioId,
            ]);

            if ($exigeContrap) {
                $movcontadorContrap = mbProximoMovcontador($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO armazem_bnc001
                        (EMPRESA, MOVCONTADOR, DTMOV, NUMDOC, CBCONTADOR, TIPOES, TIPOMOV, HISTMOV, VALORMOV,
                         TIPODOCORIGEM, NUMDOCORIGEM, CONTRAPARTIDA, ORIGEMCPART, USERBNCLANC, DTLANC,
                         DTPROCESSADO, REGSTAMP, deletado)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, 'SUPERDUNGA', ?, 'N', ?, ?, NOW(), NOW(), NOW(), 'N')
                ");
                $stmt->execute([
                    $empresaId,
                    $movcontadorContrap,
                    $dados['dtmov'],
                    $numdoc !== '' ? $numdoc : null,
                    $contrapCbcontador,
                    $contrapTipoes,
                    $contrapTipomov,
                    'CONTRAPARTIDA - ' . $historico,
                    $valor,
                    $numdoc !== '' ? $numdoc : $movcontadorPrincipal,
                    $movcontadorPrincipal,
                    $usuarioId,
                ]);
            }

            mbCriarContrapartidaAberta($pdo, $empresaId, $usuarioId, $movcontadorPrincipal, $dados);
        }

        $pdo->commit();
        return $movcontadorPrincipal;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function mbMovimentoVinculadoAcerto(PDO $pdo, $empresaId, $movcontador)
{
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

function mbMovimentoConciliadoExtrato(PDO $pdo, $empresaId, $movcontador)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM financeiro_extrato_bancario
        WHERE bnc001_empresa = ?
          AND bnc001_movcontador = ?
          AND conciliado = 'S'
    ");
    $stmt->execute([$empresaId, $movcontador]);
    return (int)$stmt->fetchColumn() > 0;
}

function mbExcluirLancamento(PDO $pdo, $empresaId, $usuarioId, $movcontador)
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_bnc001
        WHERE EMPRESA = ?
          AND MOVCONTADOR = ?
          AND COALESCE(deletado, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $movcontador]);
    $lancamento = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lancamento) {
        throw new RuntimeException('Lancamento nao encontrado para exclusao.');
    }

    if (($lancamento['TIPODOCORIGEM'] ?? '') !== 'SUPERDUNGA' || (int)($lancamento['ORIGEMCPART'] ?? 0) !== 0) {
        throw new RuntimeException('Somente lancamentos criados diretamente na tela Caixa/Banco podem ser excluidos aqui.');
    }

    if (mbMovimentoTemContrapartidaAberta($pdo, $empresaId, $movcontador)) {
        throw new RuntimeException('Lancamento com contrapartida em aberto nao pode ser excluido. O par deve permanecer integro.');
    }

    if (mbMovimentoVinculadoAcerto($pdo, $empresaId, $movcontador)) {
        throw new RuntimeException('Lancamento vinculado a acerto ativo nao pode ser excluido.');
    }

    if (mbMovimentoConciliadoExtrato($pdo, $empresaId, $movcontador)) {
        throw new RuntimeException('Lancamento conciliado com extrato bancario nao pode ser excluido. Desfaca a conciliacao bancaria antes de excluir.');
    }

    $contrap = mbCarregarContrapartida($pdo, $empresaId, $movcontador);
    if ($contrap && mbMovimentoVinculadoAcerto($pdo, $empresaId, (int)$contrap['MOVCONTADOR'])) {
        throw new RuntimeException('Contrapartida vinculada a acerto ativo nao pode ser excluida.');
    }

    if ($contrap && mbMovimentoConciliadoExtrato($pdo, $empresaId, (int)$contrap['MOVCONTADOR'])) {
        throw new RuntimeException('Contrapartida conciliada com extrato bancario nao pode ser excluida. Desfaca a conciliacao bancaria antes de excluir.');
    }

    $pdo->beginTransaction();
    try {
        $stmtDelete = $pdo->prepare("
            UPDATE armazem_bnc001
            SET deletado = 'S',
                data_delecao_firebird = NOW(),
                motivo_sync = 'EXCLUIDO_MOVIMENTACAO_BAIXA_CAIXA_BANCO',
                USERBNCALT = ?,
                DTALT = NOW(),
                REGSTAMP = NOW()
            WHERE EMPRESA = ?
              AND MOVCONTADOR = ?
              AND TIPODOCORIGEM = 'SUPERDUNGA'
              AND COALESCE(deletado, 'N') <> 'S'
        ");
        $stmtDelete->execute([$usuarioId ?: null, $empresaId, $movcontador]);

        if ($contrap) {
            $stmtDelete->execute([$usuarioId ?: null, $empresaId, (int)$contrap['MOVCONTADOR']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $acao = $_POST['acao'] ?? 'salvar';
        if ($acao === 'excluir') {
            $movcontadorExcluir = (int)($_POST['movcontador'] ?? 0);
            mbExcluirLancamento($pdo, $empresaId, $usuarioId, $movcontadorExcluir);
            $mensagem = 'Lancamento #' . $movcontadorExcluir . ' excluido com sucesso.';
        } else {
            $movcontadorEdicao = !empty($_POST['movcontador_edicao']) ? (int)$_POST['movcontador_edicao'] : null;
            $movcontadorSalvo = mbSalvarLancamento($pdo, $empresaId, $usuarioId, $_POST, $movcontadorEdicao);
            $mensagem = $movcontadorEdicao
                ? 'Lancamento #' . $movcontadorSalvo . ' atualizado com sucesso.'
                : 'Lancamento #' . $movcontadorSalvo . ' gravado com sucesso.';
            $_GET['editar'] = null;
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$contasStmt = $pdo->prepare("
    SELECT CBCONTADOR, NUMERO, DESCABREV, TITULAR
    FROM armazem_bnc002
    WHERE EMPRESA = ?
      AND COALESCE(CONTABLOQUEADA, 'N') <> 'S'
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY
        CASE WHEN TRIM(COALESCE(TITULAR, '')) = '' THEN 1 ELSE 0 END,
        TITULAR,
        CBCONTADOR
");
$contasStmt->execute([$empresaId]);
$contas = $contasStmt->fetchAll(PDO::FETCH_ASSOC);

$tiposStmt = $pdo->prepare("
    SELECT ESCONTADOR, DESCES, TIPOMOV, GRUPOBNC, CONTRAP_TIPOES, CONTRAP_TIPOMOV, CONTRAP_CBCONTADOR
    FROM armazem_bnc005
    WHERE EMPRESA = ?
      AND COALESCE(REGDISAB, 'N') <> 'S'
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY
        COALESCE(GRUPOBNC, 0),
        ESCONTADOR
");
$tiposStmt->execute([$empresaId]);
$tipos = $tiposStmt->fetchAll(PDO::FETCH_ASSOC);

$fornecedoresStmt = $pdo->prepare("
    SELECT FCONTADOR, NOME, APELIDO
    FROM armazem_cp003
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
      AND COALESCE(INATIVO, 'N') <> 'S'
    ORDER BY COALESCE(NULLIF(APELIDO, ''), NOME), FCONTADOR
");
$fornecedoresStmt->execute([$empresaId]);
$fornecedores = $fornecedoresStmt->fetchAll(PDO::FETCH_ASSOC);

$clientesStmt = $pdo->prepare("
    SELECT CLICONTADOR, NOME, APELIDO
    FROM armazem_cr002
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
      AND COALESCE(INATIVO, 'N') <> 'S'
    ORDER BY COALESCE(NULLIF(APELIDO, ''), NOME), CLICONTADOR
");
$clientesStmt->execute([$empresaId]);
$clientes = $clientesStmt->fetchAll(PDO::FETCH_ASSOC);

$contasPorId = [];
foreach ($contas as $conta) {
    $contasPorId[(int)$conta['CBCONTADOR']] = $conta;
}

$tiposPorId = [];
foreach ($tipos as $tipo) {
    $tiposPorId[(int)$tipo['ESCONTADOR']] = $tipo;
}

$editarMovcontador = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$lancamentoEdicao = null;
$contrapEdicao = null;

if ($editarMovcontador > 0) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM armazem_bnc001
        WHERE EMPRESA = ?
          AND MOVCONTADOR = ?
          AND TIPODOCORIGEM = 'SUPERDUNGA'
          AND COALESCE(ORIGEMCPART, 0) = 0
          AND COALESCE(deletado, 'N') <> 'S'
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $editarMovcontador]);
    $lancamentoEdicao = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lancamentoEdicao) {
        $contrapEdicao = mbCarregarContrapartida($pdo, $empresaId, $editarMovcontador);
    } else {
        $erro = $erro ?: 'Lancamento nao encontrado para edicao.';
    }
}

$form = [
    'movcontador_edicao' => $lancamentoEdicao['MOVCONTADOR'] ?? '',
    'dtmov' => $lancamentoEdicao ? date('Y-m-d', strtotime($lancamentoEdicao['DTMOV'])) : date('Y-m-d'),
    'numdoc' => $lancamentoEdicao['NUMDOC'] ?? '',
    'cbcontador' => $lancamentoEdicao['CBCONTADOR'] ?? '',
    'tipoes' => $lancamentoEdicao['TIPOES'] ?? '',
    'valor' => $lancamentoEdicao ? number_format((float)$lancamentoEdicao['VALORMOV'], 2, ',', '.') : '',
    'historico' => $lancamentoEdicao['HISTMOV'] ?? '',
    'contrap_cbcontador' => $contrapEdicao['CBCONTADOR'] ?? '',
    'criar_contrap_aberta' => '',
    'contrap_aberta_tipo' => '',
    'contrap_aberta_fcontador' => '',
    'contrap_aberta_clicontador' => '',
    'contrap_aberta_vencimento' => date('Y-m-d'),
    'contrap_aberta_tipoes' => '',
    'contrap_aberta_valor' => '',
    'contrap_aberta_historico' => '',
];

$fDataIni = $_GET['data_ini'] ?? date('Y-m-01');
$fDataFim = $_GET['data_fim'] ?? date('Y-m-d');
$fConta = $_GET['f_conta'] ?? '';
$fTipoes = $_GET['f_tipoes'] ?? '';
$fTipomov = $_GET['f_tipomov'] ?? '';
$fHistorico = trim((string)($_GET['f_historico'] ?? ''));
$fDocumento = trim((string)($_GET['f_documento'] ?? ''));
$fValorMin = trim((string)($_GET['f_valor_min'] ?? ''));
$fValorMax = trim((string)($_GET['f_valor_max'] ?? ''));

$where = [
    "b.EMPRESA = ?",
    "COALESCE(b.deletado, 'N') <> 'S'",
];
$params = [$empresaId];

if ($fDataIni !== '') {
    $where[] = "DATE(b.DTMOV) >= ?";
    $params[] = $fDataIni;
}
if ($fDataFim !== '') {
    $where[] = "DATE(b.DTMOV) <= ?";
    $params[] = $fDataFim;
}
if ($fConta !== '') {
    $where[] = "b.CBCONTADOR = ?";
    $params[] = (int)$fConta;
}
if ($fTipoes !== '') {
    $where[] = "b.TIPOES = ?";
    $params[] = (int)$fTipoes;
}
if ($fTipomov !== '') {
    $where[] = "b.TIPOMOV = ?";
    $params[] = $fTipomov;
}
if ($fHistorico !== '') {
    $where[] = "b.HISTMOV LIKE ?";
    $params[] = '%' . $fHistorico . '%';
}
if ($fDocumento !== '') {
    $where[] = "b.NUMDOC LIKE ?";
    $params[] = '%' . $fDocumento . '%';
}
if ($fValorMin !== '') {
    $where[] = "b.VALORMOV >= ?";
    $params[] = mbFloat($fValorMin);
}
if ($fValorMax !== '') {
    $where[] = "b.VALORMOV <= ?";
    $params[] = mbFloat($fValorMax);
}

$sqlLista = "
    SELECT b.*,
           c.NUMERO AS CONTA_NUMERO,
           c.DESCABREV AS CONTA_DESC,
           t.DESCES AS TIPO_DESC,
           t.CONTRAP_TIPOES,
           cp.MOVCONTADOR AS CONTRAP_MOVCONTADOR,
           cp.CBCONTADOR AS CONTRAP_CONTA,
           ext.extrato_id AS EXTRATO_ID,
           ext.extrato_data AS EXTRATO_DATA,
           ext.extrato_valor AS EXTRATO_VALOR,
           ext.extrato_conta AS EXTRATO_CONTA,
           mca.tipo_contrapartida AS ABERTA_TIPO,
           mca.contador_contrapartida AS ABERTA_CONTADOR,
           mca.valor AS ABERTA_VALOR
    FROM armazem_bnc001 b
    LEFT JOIN armazem_bnc002 c
      ON c.EMPRESA = b.EMPRESA AND c.CBCONTADOR = b.CBCONTADOR
    LEFT JOIN armazem_bnc005 t
      ON t.EMPRESA = b.EMPRESA AND t.ESCONTADOR = b.TIPOES
    LEFT JOIN armazem_bnc001 cp
      ON cp.EMPRESA = b.EMPRESA
     AND cp.ORIGEMCPART = b.MOVCONTADOR
     AND cp.TIPODOCORIGEM = 'SUPERDUNGA'
     AND COALESCE(cp.deletado, 'N') <> 'S'
    LEFT JOIN (
        SELECT
            bnc001_empresa,
            bnc001_movcontador,
            MIN(id) AS extrato_id,
            MIN(data_movimento) AS extrato_data,
            MAX(valor) AS extrato_valor,
            MIN(cbcontador) AS extrato_conta
        FROM financeiro_extrato_bancario
        WHERE conciliado = 'S'
          AND bnc001_empresa IS NOT NULL
          AND bnc001_movcontador IS NOT NULL
        GROUP BY bnc001_empresa, bnc001_movcontador
    ) ext
      ON ext.bnc001_empresa = b.EMPRESA
     AND ext.bnc001_movcontador = b.MOVCONTADOR
    LEFT JOIN mov_baixa_contrapartidas mca
      ON mca.empresa_id = b.EMPRESA
     AND mca.movcontador = b.MOVCONTADOR
    WHERE " . implode(' AND ', $where) . "
    ORDER BY b.DTMOV DESC, b.MOVCONTADOR DESC
    LIMIT 200
";
$stmtLista = $pdo->prepare($sqlLista);
$stmtLista->execute($params);
$lancamentos = $stmtLista->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../layout/header.php';
?>

<style>
    .mb-page {
        max-width: 1180px;
        margin: 0 auto;
        padding: 18px;
    }
    .mb-hero {
        background: #153b68;
        color: #fff;
        border-radius: 6px;
        padding: 22px;
        margin-bottom: 18px;
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: center;
    }
    .mb-hero h1 {
        margin: 0 0 6px;
        font-size: 1.55rem;
    }
    .mb-card {
        background: #fff;
        border: 1px solid #d8dee8;
        border-radius: 6px;
        padding: 16px;
        margin-bottom: 16px;
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
    }
    .mb-title {
        margin: 0 0 14px;
        font-size: 1.05rem;
        color: #1f2937;
    }
    .mb-grid {
        display: grid;
        grid-template-columns: repeat(12, 1fr);
        gap: 12px;
    }
    .mb-field {
        grid-column: span 3;
        min-width: 0;
    }
    .mb-field.w2 { grid-column: span 2; }
    .mb-field.w4 { grid-column: span 4; }
    .mb-field.w6 { grid-column: span 6; }
    .mb-field.w12 { grid-column: span 12; }
    .mb-field label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: #334155;
        font-size: 0.88rem;
    }
    .mb-field input,
    .mb-field select,
    .mb-field textarea {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 5px;
        padding: 8px 9px;
        font-size: 0.95rem;
        background: #fff;
    }
    .mb-field input[readonly] {
        background: #f1f5f9;
        color: #334155;
    }
    .mb-field textarea {
        min-height: 76px;
        resize: vertical;
    }
    .mb-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        margin-top: 14px;
    }
    .mb-btn {
        border: 0;
        border-radius: 5px;
        padding: 9px 13px;
        background: #173b73;
        color: #fff;
        font-weight: 700;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }
    .mb-btn.secondary {
        background: #64748b;
    }
    .mb-btn.light {
        background: #e2e8f0;
        color: #0f172a;
    }
    .mb-btn.danger {
        background: #b91c1c;
        color: #fff;
    }
    .mb-alert {
        border-radius: 5px;
        padding: 11px 13px;
        margin-bottom: 14px;
    }
    .mb-alert.ok {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #86efac;
    }
    .mb-alert.err {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    .mb-note {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #475569;
        border-radius: 5px;
        padding: 10px 12px;
        font-size: 0.9rem;
    }
    .mb-table-wrap {
        overflow-x: auto;
    }
    .mb-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 960px;
    }
    .mb-table th,
    .mb-table td {
        border-bottom: 1px solid #e2e8f0;
        padding: 9px 8px;
        text-align: left;
        vertical-align: top;
        font-size: 0.9rem;
    }
    .mb-table th {
        background: #12336b;
        color: #fff;
        font-size: 0.82rem;
        text-transform: uppercase;
    }
    .mb-badge {
        display: inline-block;
        border-radius: 999px;
        padding: 3px 8px;
        font-size: 0.78rem;
        font-weight: 700;
        background: #e2e8f0;
        color: #0f172a;
    }
    .mb-badge.d {
        background: #fee2e2;
        color: #991b1b;
    }
    .mb-badge.c {
        background: #dcfce7;
        color: #166534;
    }
    .mb-contrap-box {
        display: none;
        grid-column: span 12;
        border: 1px solid #bfdbfe;
        background: #eff6ff;
        border-radius: 6px;
        padding: 12px;
    }
    .mb-contrap-box.active {
        display: block;
    }
    .mb-contrap-aberta-box {
        grid-column: span 12;
        border: 1px solid #bbf7d0;
        background: #f0fdf4;
        border-radius: 6px;
        padding: 12px;
    }
    .mb-switch-line {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 700;
        color: #14532d;
        margin-bottom: 10px;
    }
    .mb-switch-line input {
        width: 18px;
        height: 18px;
    }
    .mb-contrap-aberta-campos {
        display: none;
    }
    .mb-contrap-aberta-box.active .mb-contrap-aberta-campos {
        display: block;
    }
    @media (max-width: 820px) {
        .mb-page {
            padding: 12px;
        }
        .mb-hero {
            display: block;
            padding: 18px;
        }
        .mb-grid {
            grid-template-columns: 1fr;
        }
        .mb-field,
        .mb-field.w2,
        .mb-field.w4,
        .mb-field.w6,
        .mb-field.w12,
        .mb-contrap-box,
        .mb-contrap-aberta-box {
            grid-column: span 1;
        }
        .mb-actions .mb-btn {
            width: 100%;
        }
    }
</style>

<div class="mb-page">
    <div class="mb-hero">
        <div>
            <h1>Caixa/Banco</h1>
            <div>Lancamento direto em BNC001 para movimentacoes simples.</div>
        </div>
        <a class="mb-btn light" href="menu_movimentacao_baixa.php">Voltar</a>
    </div>

    <?php if ($mensagem): ?>
        <div class="mb-alert ok"><?= mbH($mensagem) ?></div>
    <?php endif; ?>
    <?php if ($erro): ?>
        <div class="mb-alert err"><?= mbH($erro) ?></div>
    <?php endif; ?>

    <div class="mb-card">
        <h2 class="mb-title">
            <?= $lancamentoEdicao ? 'Editar lancamento #' . mbH($lancamentoEdicao['MOVCONTADOR']) : 'Novo lancamento' ?>
        </h2>

        <form method="post" id="formCaixaBanco" autocomplete="off">
            <input type="hidden" name="movcontador_edicao" value="<?= mbH($form['movcontador_edicao']) ?>">

            <div class="mb-grid">
                <div class="mb-field w2">
                    <label for="dtmov">Data movimento</label>
                    <input type="date" id="dtmov" name="dtmov" value="<?= mbH($form['dtmov']) ?>" required>
                </div>

                <div class="mb-field w2">
                    <label for="numdoc">Documento</label>
                    <input type="text" id="numdoc" name="numdoc" value="<?= mbH($form['numdoc']) ?>" maxlength="60">
                </div>

                <div class="mb-field w4">
                    <label for="cbcontador">Conta</label>
                    <select id="cbcontador" name="cbcontador" required>
                        <option value="">Selecione</option>
                        <?php foreach ($contas as $conta): ?>
                            <option value="<?= (int)$conta['CBCONTADOR'] ?>" <?= (string)$form['cbcontador'] === (string)$conta['CBCONTADOR'] ? 'selected' : '' ?>>
                                <?= mbH(mbDescricaoConta($conta) . ' (' . $conta['CBCONTADOR'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-field w4">
                    <label for="tipoes">Tipo de movimentacao</label>
                    <select id="tipoes" name="tipoes" required>
                        <option value="">Selecione</option>
                        <?php foreach ($tipos as $tipo): ?>
                            <?php
                                $contrapTipo = !empty($tipo['CONTRAP_TIPOES']) ? (int)$tipo['CONTRAP_TIPOES'] : 0;
                                $contrapConta = !empty($tipo['CONTRAP_CBCONTADOR']) ? (int)$tipo['CONTRAP_CBCONTADOR'] : '';
                            ?>
                            <option
                                value="<?= (int)$tipo['ESCONTADOR'] ?>"
                                data-tipomov="<?= mbH($tipo['TIPOMOV'] ?? '') ?>"
                                data-contrap="<?= $contrapTipo ?>"
                                data-contrap-conta="<?= mbH($contrapConta) ?>"
                                <?= (string)$form['tipoes'] === (string)$tipo['ESCONTADOR'] ? 'selected' : '' ?>
                            >
                                <?= mbH(($tipo['DESCES'] ?? '') . ' (' . $tipo['ESCONTADOR'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-field w2">
                    <label>Tipo do movimento</label>
                    <input type="text" id="tipomov_visual" value="" readonly>
                </div>

                <div class="mb-field w2">
                    <label for="valor">Valor</label>
                    <input
                        type="text"
                        id="valor"
                        name="valor"
                        value="<?= mbH($form['valor']) ?>"
                        inputmode="decimal"
                        autocomplete="off"
                        pattern="[0-9.,]*"
                        placeholder="0,00"
                        required
                    >
                </div>

                <div class="mb-field w12">
                    <label for="historico">Historico</label>
                    <textarea id="historico" name="historico" required><?= mbH($form['historico']) ?></textarea>
                </div>

                <div id="contrapBox" class="mb-contrap-box">
                    <div class="mb-grid">
                        <div class="mb-field w6">
                            <label for="contrap_cbcontador">Conta contrapartida</label>
                            <select id="contrap_cbcontador" name="contrap_cbcontador">
                                <option value="">Selecione</option>
                                <?php foreach ($contas as $conta): ?>
                                    <option value="<?= (int)$conta['CBCONTADOR'] ?>" <?= (string)$form['contrap_cbcontador'] === (string)$conta['CBCONTADOR'] ? 'selected' : '' ?>>
                                        <?= mbH(mbDescricaoConta($conta) . ' (' . $conta['CBCONTADOR'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-field w6">
                            <label>Regra da contrapartida</label>
                            <div class="mb-note" id="contrapInfo">
                                O tipo selecionado exige lancamento de contrapartida. Ao salvar, o sistema cria o registro invertido automaticamente.
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!$lancamentoEdicao): ?>
                    <div id="contrapAbertaBox" class="mb-contrap-aberta-box">
                        <label class="mb-switch-line">
                            <input type="checkbox" id="criar_contrap_aberta" name="criar_contrap_aberta" value="1">
                            Criar contrapartida em aberto
                        </label>
                        <div class="mb-note" style="margin-bottom:12px;">
                            O movimento em Caixa/Banco sera gravado agora e o sistema criara uma conta a pagar ou a receber em aberto. Depois de salvo, o par fica protegido contra edicao ou exclusao individual.
                        </div>
                        <div class="mb-contrap-aberta-campos">
                            <div class="mb-grid">
                                <div class="mb-field w3">
                                    <label for="contrap_aberta_tipo">Tipo</label>
                                    <select id="contrap_aberta_tipo" name="contrap_aberta_tipo">
                                        <option value="">Selecione</option>
                                        <option value="CR">Conta a receber</option>
                                        <option value="CP">Conta a pagar</option>
                                    </select>
                                </div>
                                <div class="mb-field w3 mb-campo-cr">
                                    <label for="contrap_aberta_clicontador">Cliente</label>
                                    <select id="contrap_aberta_clicontador" name="contrap_aberta_clicontador">
                                        <option value="">Selecione</option>
                                        <?php foreach ($clientes as $cliente): ?>
                                            <option value="<?= (int)$cliente['CLICONTADOR'] ?>">
                                                <?= mbH(trim((string)($cliente['APELIDO'] ?: $cliente['NOME'])) . ' (' . $cliente['CLICONTADOR'] . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-field w3 mb-campo-cp">
                                    <label for="contrap_aberta_fcontador">Fornecedor</label>
                                    <select id="contrap_aberta_fcontador" name="contrap_aberta_fcontador">
                                        <option value="">Selecione</option>
                                        <?php foreach ($fornecedores as $fornecedor): ?>
                                            <option value="<?= (int)$fornecedor['FCONTADOR'] ?>">
                                                <?= mbH(trim((string)($fornecedor['APELIDO'] ?: $fornecedor['NOME'])) . ' (' . $fornecedor['FCONTADOR'] . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-field w3">
                                    <label for="contrap_aberta_vencimento">Vencimento</label>
                                    <input type="date" id="contrap_aberta_vencimento" name="contrap_aberta_vencimento" value="<?= mbH($form['contrap_aberta_vencimento']) ?>">
                                </div>
                                <div class="mb-field w4">
                                    <label for="contrap_aberta_tipoes">TIPOES do titulo</label>
                                    <select id="contrap_aberta_tipoes" name="contrap_aberta_tipoes">
                                        <option value="">Selecione</option>
                                        <?php foreach ($tipos as $tipo): ?>
                                            <option value="<?= (int)$tipo['ESCONTADOR'] ?>">
                                                <?= mbH(($tipo['DESCES'] ?? '') . ' (' . $tipo['ESCONTADOR'] . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-field w2">
                                    <label for="contrap_aberta_valor">Valor</label>
                                    <input type="text" id="contrap_aberta_valor" name="contrap_aberta_valor" inputmode="decimal" placeholder="Mesmo valor">
                                </div>
                                <div class="mb-field w6">
                                    <label for="contrap_aberta_historico">Historico do titulo</label>
                                    <input type="text" id="contrap_aberta_historico" name="contrap_aberta_historico" placeholder="Se vazio, usa o historico do movimento">
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif (mbMovimentoTemContrapartidaAberta($pdo, $empresaId, (int)$lancamentoEdicao['MOVCONTADOR'])): ?>
                    <div class="mb-contrap-aberta-box active">
                        <div class="mb-note">
                            Este lancamento possui contrapartida em aberto vinculada e nao pode ser alterado parcialmente.
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mb-field w12">
                    <div class="mb-note">
                        Data de processamento sera preenchida automaticamente pelo sistema no momento da gravacao.
                    </div>
                </div>
            </div>

            <div class="mb-actions">
                <button type="submit" class="mb-btn"><?= $lancamentoEdicao ? 'Salvar edicao' : 'Salvar lancamento' ?></button>
                <?php if ($lancamentoEdicao): ?>
                    <a href="caixa_banco.php" class="mb-btn secondary">Novo lancamento</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="mb-card">
        <h2 class="mb-title">Filtros</h2>
        <form method="get" class="mb-grid" autocomplete="off">
            <div class="mb-field w2">
                <label for="data_ini">Data inicial</label>
                <input type="date" id="data_ini" name="data_ini" value="<?= mbH($fDataIni) ?>">
            </div>
            <div class="mb-field w2">
                <label for="data_fim">Data final</label>
                <input type="date" id="data_fim" name="data_fim" value="<?= mbH($fDataFim) ?>">
            </div>
            <div class="mb-field w3">
                <label for="f_conta">Conta</label>
                <select id="f_conta" name="f_conta">
                    <option value="">Todas</option>
                    <?php foreach ($contas as $conta): ?>
                        <option value="<?= (int)$conta['CBCONTADOR'] ?>" <?= (string)$fConta === (string)$conta['CBCONTADOR'] ? 'selected' : '' ?>>
                            <?= mbH(mbDescricaoConta($conta) . ' (' . $conta['CBCONTADOR'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-field w3">
                <label for="f_tipoes">Tipo</label>
                <select id="f_tipoes" name="f_tipoes">
                    <option value="">Todos</option>
                    <?php foreach ($tipos as $tipo): ?>
                        <option value="<?= (int)$tipo['ESCONTADOR'] ?>" <?= (string)$fTipoes === (string)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                            <?= mbH(($tipo['DESCES'] ?? '') . ' (' . $tipo['ESCONTADOR'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-field w2">
                <label for="f_tipomov">D/C</label>
                <select id="f_tipomov" name="f_tipomov">
                    <option value="">Todos</option>
                    <option value="D" <?= $fTipomov === 'D' ? 'selected' : '' ?>>Debito</option>
                    <option value="C" <?= $fTipomov === 'C' ? 'selected' : '' ?>>Credito</option>
                </select>
            </div>
            <div class="mb-field w4">
                <label for="f_historico">Historico</label>
                <input type="text" id="f_historico" name="f_historico" value="<?= mbH($fHistorico) ?>">
            </div>
            <div class="mb-field w3">
                <label for="f_documento">Documento</label>
                <input type="text" id="f_documento" name="f_documento" value="<?= mbH($fDocumento) ?>">
            </div>
            <div class="mb-field w2">
                <label for="f_valor_min">Valor inicial</label>
                <input type="text" id="f_valor_min" name="f_valor_min" inputmode="decimal" value="<?= mbH($fValorMin) ?>" placeholder="0,00">
            </div>
            <div class="mb-field w2">
                <label for="f_valor_max">Valor final</label>
                <input type="text" id="f_valor_max" name="f_valor_max" inputmode="decimal" value="<?= mbH($fValorMax) ?>" placeholder="0,00">
            </div>
            <div class="mb-field w12">
                <div class="mb-actions">
                    <button type="submit" class="mb-btn">Filtrar</button>
                    <a class="mb-btn light" href="caixa_banco.php">Limpar</a>
                </div>
            </div>
        </form>
    </div>

    <div class="mb-card">
        <h2 class="mb-title">Lancamentos</h2>
        <div class="mb-table-wrap">
            <table class="mb-table">
                <thead>
                    <tr>
                        <th>Mov.</th>
                        <th>Data</th>
                        <th>Conta</th>
                        <th>Tipo</th>
                        <th>D/C</th>
                        <th>Origem</th>
                        <th>Documento</th>
                        <th>Historico</th>
                        <th>Valor</th>
                        <th>Extrato</th>
                        <th>Contrap.</th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$lancamentos): ?>
                        <tr>
                            <td colspan="12" style="text-align:center;color:#64748b;">Nenhum lancamento encontrado.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($lancamentos as $linha): ?>
                        <?php
                            $dc = strtoupper((string)$linha['TIPOMOV']);
                            $origem = trim((string)($linha['TIPODOCORIGEM'] ?? ''));
                            $temContrapAberta = !empty($linha['ABERTA_TIPO']) && !empty($linha['ABERTA_CONTADOR']);
                            $editavel = $origem === 'SUPERDUNGA' && (int)($linha['ORIGEMCPART'] ?? 0) === 0 && !$temContrapAberta;
                        ?>
                        <tr>
                            <td><?= (int)$linha['MOVCONTADOR'] ?></td>
                            <td><?= mbH(mbData($linha['DTMOV'])) ?></td>
                            <td><?= mbH(($linha['CBCONTADOR'] ?? '') . ' - ' . ($linha['CONTA_DESC'] ?: $linha['CONTA_NUMERO'])) ?></td>
                            <td><?= mbH(($linha['TIPOES'] ?? '') . ' - ' . ($linha['TIPO_DESC'] ?? '')) ?></td>
                            <td><span class="mb-badge <?= strtolower($dc) ?>"><?= mbH(mbNomeTipomov($dc)) ?></span></td>
                            <td><?= mbH($origem !== '' ? $origem : '-') ?></td>
                            <td><?= mbH($linha['NUMDOC'] ?? '') ?></td>
                            <td><?= mbH($linha['HISTMOV'] ?? '') ?></td>
                            <td><?= mbH(mbMoeda($linha['VALORMOV'])) ?></td>
                            <td>
                                <?php if (!empty($linha['EXTRATO_ID'])): ?>
                                    <span class="mb-badge c">Conciliado</span>
                                    <div style="margin-top:4px;color:#475569;font-size:0.82rem;">
                                        Extrato #<?= (int)$linha['EXTRATO_ID'] ?><br>
                                        <?= mbH(mbData($linha['EXTRATO_DATA'])) ?> |
                                        Conta <?= (int)$linha['EXTRATO_CONTA'] ?> |
                                        <?= mbH(mbMoeda($linha['EXTRATO_VALOR'])) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="mb-badge">Pendente</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($linha['CONTRAP_MOVCONTADOR'])): ?>
                                    #<?= (int)$linha['CONTRAP_MOVCONTADOR'] ?> / conta <?= mbH($linha['CONTRAP_CONTA']) ?>
                                <?php elseif ($temContrapAberta): ?>
                                    <span class="mb-badge c"><?= mbH($linha['ABERTA_TIPO']) ?> aberto #<?= (int)$linha['ABERTA_CONTADOR'] ?></span>
                                    <div style="margin-top:4px;color:#475569;font-size:0.82rem;">
                                        <?= mbH(mbMoeda($linha['ABERTA_VALOR'])) ?>
                                    </div>
                                <?php elseif (!empty($linha['CONTRAP_TIPOES'])): ?>
                                    <span class="mb-badge">Pendente</span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($editavel): ?>
                                    <a class="mb-btn light" href="caixa_banco.php?editar=<?= (int)$linha['MOVCONTADOR'] ?>">Editar</a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Excluir este lancamento do Caixa/Banco?');">
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="movcontador" value="<?= (int)$linha['MOVCONTADOR'] ?>">
                                        <button type="submit" class="mb-btn danger">Excluir</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#64748b;">Consulta</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const tipoSelect = document.getElementById('tipoes');
    const tipoVisual = document.getElementById('tipomov_visual');
    const contrapBox = document.getElementById('contrapBox');
    const contrapSelect = document.getElementById('contrap_cbcontador');
    const valorInput = document.getElementById('valor');
    const historicoInput = document.getElementById('historico');
    const criarContrapAberta = document.getElementById('criar_contrap_aberta');
    const contrapAbertaBox = document.getElementById('contrapAbertaBox');
    const contrapAbertaTipo = document.getElementById('contrap_aberta_tipo');
    const contrapAbertaValor = document.getElementById('contrap_aberta_valor');
    const contrapAbertaHistorico = document.getElementById('contrap_aberta_historico');
    const contrapAbertaVencimento = document.getElementById('contrap_aberta_vencimento');
    const contrapAbertaTipoes = document.getElementById('contrap_aberta_tipoes');
    const contrapAbertaCliente = document.getElementById('contrap_aberta_clicontador');
    const contrapAbertaFornecedor = document.getElementById('contrap_aberta_fcontador');
    const camposCr = document.querySelectorAll('.mb-campo-cr');
    const camposCp = document.querySelectorAll('.mb-campo-cp');

    function nomeTipoMov(valor) {
        valor = (valor || '').toUpperCase();
        if (valor === 'D') return 'Debito';
        if (valor === 'C') return 'Credito';
        return 'Tipo nao configurado';
    }

    function atualizarTipo() {
        const option = tipoSelect.options[tipoSelect.selectedIndex];
        const tipomov = option ? option.getAttribute('data-tipomov') : '';
        const contrap = option ? parseInt(option.getAttribute('data-contrap') || '0', 10) : 0;
        const contaPadrao = option ? option.getAttribute('data-contrap-conta') : '';

        tipoVisual.value = nomeTipoMov(tipomov);

        if (contrapAbertaTipo && criarContrapAberta && criarContrapAberta.checked && !contrapAbertaTipo.value) {
            contrapAbertaTipo.value = (tipomov || '').toUpperCase() === 'D' ? 'CR' : ((tipomov || '').toUpperCase() === 'C' ? 'CP' : '');
        }

        if (contrap > 0) {
            contrapBox.classList.add('active');
            contrapSelect.required = true;
            if (contaPadrao && !contrapSelect.value) {
                contrapSelect.value = contaPadrao;
            }
        } else {
            contrapBox.classList.remove('active');
            contrapSelect.required = false;
            contrapSelect.value = '';
        }
    }

    tipoSelect.addEventListener('change', atualizarTipo);
    atualizarTipo();

    function atualizarContrapAberta() {
        if (!criarContrapAberta || !contrapAbertaBox) {
            return;
        }

        const ativo = criarContrapAberta.checked;
        contrapAbertaBox.classList.toggle('active', ativo);

        const option = tipoSelect.options[tipoSelect.selectedIndex];
        const tipomov = option ? (option.getAttribute('data-tipomov') || '').toUpperCase() : '';
        if (ativo && contrapAbertaTipo && !contrapAbertaTipo.value) {
            contrapAbertaTipo.value = tipomov === 'D' ? 'CR' : (tipomov === 'C' ? 'CP' : '');
        }

        if (ativo && contrapAbertaValor && !contrapAbertaValor.value && valorInput) {
            contrapAbertaValor.value = valorInput.value;
        }

        if (ativo && contrapAbertaHistorico && !contrapAbertaHistorico.value && historicoInput) {
            contrapAbertaHistorico.value = 'CONTRAPARTIDA EM ABERTO - ' + historicoInput.value;
        }

        const tipo = contrapAbertaTipo ? contrapAbertaTipo.value : '';
        camposCr.forEach(function (campo) {
            campo.style.display = tipo === 'CR' ? '' : 'none';
        });
        camposCp.forEach(function (campo) {
            campo.style.display = tipo === 'CP' ? '' : 'none';
        });

        [contrapAbertaTipo, contrapAbertaValor, contrapAbertaVencimento, contrapAbertaTipoes].forEach(function (campo) {
            if (campo) campo.required = ativo;
        });
        if (contrapAbertaCliente) {
            contrapAbertaCliente.required = ativo && tipo === 'CR';
        }
        if (contrapAbertaFornecedor) {
            contrapAbertaFornecedor.required = ativo && tipo === 'CP';
        }
    }

    if (criarContrapAberta) {
        criarContrapAberta.addEventListener('change', atualizarContrapAberta);
    }
    if (contrapAbertaTipo) {
        contrapAbertaTipo.addEventListener('change', atualizarContrapAberta);
    }
    atualizarContrapAberta();

    function formatarValorDecimal(valor) {
        valor = (valor || '').toString().trim().replace(/[^\d.,]/g, '');
        if (!valor) {
            return '';
        }

        let numero;
        if (valor.includes(',')) {
            numero = Number(valor.replace(/\./g, '').replace(',', '.'));
        } else {
            numero = Number(valor);
        }

        if (!Number.isFinite(numero)) {
            return '';
        }

        return numero.toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    if (valorInput) {
        valorInput.addEventListener('input', function () {
            this.value = this.value.replace(/[^\d.,]/g, '');
        });

        valorInput.addEventListener('blur', function () {
            this.value = formatarValorDecimal(this.value);
            if (contrapAbertaValor && criarContrapAberta && criarContrapAberta.checked && !contrapAbertaValor.value) {
                contrapAbertaValor.value = this.value;
            }
        });

        const form = valorInput.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                valorInput.value = formatarValorDecimal(valorInput.value);
            });
        }

        valorInput.value = formatarValorDecimal(valorInput.value);
    }

    if (contrapAbertaValor) {
        contrapAbertaValor.addEventListener('input', function () {
            this.value = this.value.replace(/[^\d.,]/g, '');
        });
        contrapAbertaValor.addEventListener('blur', function () {
            this.value = formatarValorDecimal(this.value);
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
