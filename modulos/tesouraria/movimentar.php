<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/auth.php';
require '../../config/conexao.php';

/* =========================
   MODO EDIÇÃO
========================= */
$id = $_GET['id'] ?? null;
$modoEdicao = !empty($id);
$fluxo = $_GET['fluxo'] ?? '';
$fluxosEspeciais = [
    'abertura' => [
        'titulo' => 'Abertura de Caixa',
        'tipo' => 'D',
        'historico' => 'Abertura de caixa ',
    ],
    'fechamento' => [
        'titulo' => 'Fechamento de Caixa',
        'tipo' => 'C',
        'historico' => 'Fechamento de caixa ',
    ],
];
$fluxoEspecial = (!$modoEdicao && isset($fluxosEspeciais[$fluxo])) ? $fluxosEspeciais[$fluxo] : null;
$dispensaComprovante = $fluxoEspecial !== null;

if ($modoEdicao && $_SESSION['nivel'] !== 'MASTER') {
    die("Acesso negado: edição permitida apenas para MASTER.");
}

/* =========================
   CARREGAR DADOS (EDIÇÃO)
========================= */
$mov = null;
$entradaEdicao = [];
$saidaEdicao = [];

if ($modoEdicao) {

    /* BUSCAR MOVIMENTAÇÃO */
    $stmt = $pdo_master->prepare("
        SELECT *
        FROM tesouraria_movimentacoes
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    $mov = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mov) {
        die("Movimentação não encontrada");
    }
    
    /* BLOQUEIO SE CONCILIADO */
if (!empty($mov['conciliado']) && $mov['conciliado'] == 'S') {
    die("Este lançamento já foi conciliado e não pode ser editado.");
}

    /* BUSCAR DETALHES */
    $stmt = $pdo_master->prepare("
        SELECT *
        FROM tesouraria_movimentacoes_detalhes
        WHERE movimentacao_id = ?
    ");
    $stmt->execute([$id]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        if ($d['tipo'] === 'entrada') {
            $entradaEdicao[$d['tipo_dinheiro_id']] = $d['quantidade'];
        } else {
            $saidaEdicao[$d['tipo_dinheiro_id']] = $d['quantidade'];
        }
    }
}

/* =========================
   CARREGAR TIPOS DE DINHEIRO
========================= */
$stmtTipos = $pdo_master->query("
    SELECT id, valor
    FROM tesouraria_tipos_dinheiro
    ORDER BY valor ASC
");
$tiposDinheiro = $stmtTipos->fetchAll(PDO::FETCH_ASSOC);

$tiposMap = [];
foreach ($tiposDinheiro as $t) {
    $tiposMap[(int)$t['id']] = (float)$t['valor'];
}

function saldosDinheiroTesouraria(PDO $pdo, int $empresaId, ?int $ignorarMovimentacaoId = null): array
{
    $whereIgnorar = '';
    $params = [$empresaId];

    if ($ignorarMovimentacaoId !== null) {
        $whereIgnorar = ' AND m.id <> ?';
        $params[] = $ignorarMovimentacaoId;
    }

    $stmt = $pdo->prepare("
        SELECT
            d.tipo_dinheiro_id,
            SUM(
                CASE
                    WHEN d.tipo = 'entrada' THEN d.quantidade
                    WHEN d.tipo = 'saida' THEN -d.quantidade
                    ELSE 0
                END
            ) AS saldo
        FROM tesouraria_movimentacoes_detalhes d
        INNER JOIN tesouraria_movimentacoes m
            ON m.id = d.movimentacao_id
        WHERE m.empresa_id = ?
          $whereIgnorar
        GROUP BY d.tipo_dinheiro_id
    ");
    $stmt->execute($params);

    $saldos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $saldos[(int)$row['tipo_dinheiro_id']] = (int)$row['saldo'];
    }

    return $saldos;
}

function nomeArquivoSeguroTesouraria(string $nome): string
{
    $nome = basename(str_replace('\\', '/', $nome));
    $nome = trim($nome);

    if ($nome === '') {
        return 'comprovante';
    }

    if (function_exists('mb_check_encoding') && !mb_check_encoding($nome, 'UTF-8')) {
        $convertido = @mb_convert_encoding($nome, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
        if (is_string($convertido) && $convertido !== '') {
            $nome = $convertido;
        }
    }

    $normalizado = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
    if (is_string($normalizado) && $normalizado !== '') {
        $nome = $normalizado;
    }

    $nome = preg_replace('/[^\w.\- ]+/', '_', $nome);
    $nome = preg_replace('/\s+/', ' ', (string)$nome);
    $nome = trim((string)$nome, " ._\t\n\r\0\x0B");

    if ($nome === '') {
        return 'comprovante';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($nome, 0, 180, 'UTF-8');
    }

    return substr($nome, 0, 180);
}

/* =========================
   SEPARAR MOEDAS E CÉDULAS
========================= */
$moedas = [];
$cedulas = [];

foreach ($tiposDinheiro as $t) {
    $valor = (float)$t['valor'];

    if ($valor < 2) {
        $moedas[] = [
            'id'    => (int)$t['id'],
            'valor' => $valor
        ];
    } else {
        $cedulas[] = [
            'id'    => (int)$t['id'],
            'valor' => $valor
        ];
    }
}

$linhas = max(count($moedas), count($cedulas));

/* =========================
   BACKEND COMPLETO
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    /* BLOQUEIO DE EDIÇÃO SE CONCILIADO */
if ($modoEdicao) {
    $stmtCheck = $pdo_master->prepare("
        SELECT conciliado 
        FROM tesouraria_movimentacoes
        WHERE id = ?
    ");
    $stmtCheck->execute([$id]);
    $conciliado = $stmtCheck->fetchColumn();

    if (!empty($conciliado) && $conciliado == 1) {
        die("Este lançamento já foi conciliado e não pode ser alterado.");
    }
}

    $empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
    $usuario_id = (int)($_SESSION['usuario_id'] ?? 0);

    $tipo = $_POST['tipo_movimentacao'] ?? '';
    if ($fluxoEspecial) {
        $tipo = $fluxoEspecial['tipo'];
    }

    $observacao = trim($_POST['observacao'] ?? '');

    /* 🔥 CORREÇÃO DA DATA */
    $data_mov = !empty($_POST['data_mov']) 
    ? date('Y-m-d H:i:s', strtotime($_POST['data_mov'])) 
    : date('Y-m-d H:i:s');

    $entrada = $_POST['entrada'] ?? [];
    $saida   = $_POST['saida'] ?? [];

    $totalEntrada = 0.0;
    $totalSaida   = 0.0;

    foreach ($entrada as $tipo_dinheiro_id => $qtd) {
        $tipo_dinheiro_id = (int)$tipo_dinheiro_id;
        $qtd = (int)$qtd;

        if ($qtd > 0 && isset($tiposMap[$tipo_dinheiro_id])) {
            $totalEntrada += $tiposMap[$tipo_dinheiro_id] * $qtd;
        }
    }

    foreach ($saida as $tipo_dinheiro_id => $qtd) {
        $tipo_dinheiro_id = (int)$tipo_dinheiro_id;
        $qtd = (int)$qtd;

        if ($qtd > 0 && isset($tiposMap[$tipo_dinheiro_id])) {
            $totalSaida += $tiposMap[$tipo_dinheiro_id] * $qtd;
        }
    }

    $saldo = $totalEntrada - $totalSaida;

    /* =========================
       VALIDAÇÕES BACKEND
    ========================= */
    if (!in_array($tipo, ['C', 'D', 'T'], true)) {
        die('Tipo de movimentação inválido.');
    }

    if ($observacao === '') {
        die('Informe o histórico.');
    }

    $totalArquivos = 0;
    if (isset($_FILES['comprovante']['name']) && is_array($_FILES['comprovante']['name'])) {
        foreach ($_FILES['comprovante']['name'] as $nomeArquivo) {
            if (trim($nomeArquivo) !== '') {
                $totalArquivos++;
            }
        }
    }

    $totalArquivosExistentes = 0;
    if ($modoEdicao) {
        $stmtExist = $pdo_master->prepare("
            SELECT COUNT(*) 
            FROM tesouraria_comprovantes
            WHERE movimentacao_id = ?
        ");
        $stmtExist->execute([$id]);
        $totalArquivosExistentes = (int)$stmtExist->fetchColumn();
    }

    $exigirComprovante = !$modoEdicao && !$dispensaComprovante && ($tipo === 'D' || $tipo === 'C');
    if ($exigirComprovante && ($totalArquivos + $totalArquivosExistentes) === 0) {
        die('Anexe pelo menos um comprovante.');
    }

    if ($tipo === 'D' && $totalSaida <= $totalEntrada) {
        die('Débito inválido.');
    }

    if ($tipo === 'C' && $totalEntrada <= $totalSaida) {
        die('Crédito inválido.');
    }

    if ($tipo === 'T' && round($totalEntrada, 2) !== round($totalSaida, 2)) {
        die('Troca inválida.');
    }

    $saldosAtuais = saldosDinheiroTesouraria($pdo_master, $empresa_id, $modoEdicao ? (int)$id : null);
    $tiposMovimentados = array_unique(array_merge(array_keys($entrada), array_keys($saida)));

    foreach ($tiposMovimentados as $tipo_dinheiro_id) {
        $tipo_dinheiro_id = (int)$tipo_dinheiro_id;
        $qtdEntrada = max(0, (int)($entrada[$tipo_dinheiro_id] ?? 0));
        $qtdSaida = max(0, (int)($saida[$tipo_dinheiro_id] ?? 0));

        if ($qtdEntrada === 0 && $qtdSaida === 0) {
            continue;
        }

        $saldoAtualTipo = (int)($saldosAtuais[$tipo_dinheiro_id] ?? 0);
        $saldoDepois = $saldoAtualTipo + $qtdEntrada - $qtdSaida;

        if ($saldoDepois < 0) {
            $valorTipo = $tiposMap[$tipo_dinheiro_id] ?? 0;
            die(
                'Saldo insuficiente para R$ ' .
                number_format((float)$valorTipo, 2, ',', '.') .
                '. Saldo atual: ' . $saldoAtualTipo .
                ', saida informada: ' . $qtdSaida . '.'
            );
        }
    }

    try {
        $pdo_master->beginTransaction();

        if (!$modoEdicao) {
            /* =========================
               EVITAR DUPLICIDADE
            ========================= */
            $verifica = $pdo_master->prepare("
                SELECT id
                FROM tesouraria_movimentacoes
                WHERE empresa_id = ?
                  AND usuario_id = ?
                  AND tipo_operacao = ?
                  AND valor_operacao = ?
                  AND valor_entregue = ?
                  AND valor_troco = ?
                  AND observacao = ?
                  AND DATE(data_mov) = DATE(?)
                ORDER BY id DESC
                LIMIT 1
            ");

            $verifica->execute([
                $empresa_id,
                $usuario_id,
                $tipo,
                round($saldo, 2),
                round($totalSaida, 2),
                round($totalEntrada, 2),
                $observacao,
                $data_mov
            ]);

            $jaExiste = $verifica->fetch(PDO::FETCH_ASSOC);

            if ($jaExiste) {
                $pdo_master->rollBack();
                header("Location: movimentar.php?sucesso=1&mov_id=" . $jaExiste['id'] . ($fluxoEspecial ? "&fluxo=" . urlencode($fluxo) : ""));
                exit;
            }
        }

        if ($modoEdicao) {

            /* =========================
               REVERTER ESTOQUE ANTIGO
            ========================= */
            $stmtAntigos = $pdo_master->prepare("
                SELECT tipo, tipo_dinheiro_id, quantidade
                FROM tesouraria_movimentacoes_detalhes
                WHERE movimentacao_id = ?
            ");
            $stmtAntigos->execute([$id]);
            $detalhesAntigos = $stmtAntigos->fetchAll(PDO::FETCH_ASSOC);

            $stmtReverteEntrada = $pdo_master->prepare("
                UPDATE tesouraria_estoque
                SET quantidade = quantidade - ?
                WHERE tipo_dinheiro_id = ?
            ");

            $stmtReverteSaida = $pdo_master->prepare("
                INSERT INTO tesouraria_estoque (tipo_dinheiro_id, quantidade)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade)
            ");

            foreach ($detalhesAntigos as $det) {
                if ($det['tipo'] === 'entrada') {
                    $stmtReverteEntrada->execute([
                        (int)$det['quantidade'],
                        (int)$det['tipo_dinheiro_id']
                    ]);
                } else {
                    $stmtReverteSaida->execute([
                        (int)$det['tipo_dinheiro_id'],
                        (int)$det['quantidade']
                    ]);
                }
            }

            /* =========================
               APAGAR DETALHES ANTIGOS
            ========================= */
            $stmtDeleteDetalhes = $pdo_master->prepare("
                DELETE FROM tesouraria_movimentacoes_detalhes
                WHERE movimentacao_id = ?
            ");
            $stmtDeleteDetalhes->execute([$id]);

            /* =========================
               ATUALIZAR CABEÇALHO
            ========================= */
            $stmt = $pdo_master->prepare("
                UPDATE tesouraria_movimentacoes
                SET
                    tipo_operacao = ?,
                    valor_operacao = ?,
                    valor_entregue = ?,
                    valor_troco = ?,
                    observacao = ?,
                    data_mov = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $tipo,
                round($saldo, 2),
                round($totalSaida, 2),
                round($totalEntrada, 2),
                $observacao,
                $data_mov,
                $id
            ]);

            $mov_id = $id;

        } else {

            /* =========================
               SALVAR CABEÇALHO
            ========================= */
            $stmt = $pdo_master->prepare("
                INSERT INTO tesouraria_movimentacoes
                (
                    empresa_id,
                    usuario_id,
                    tipo_operacao,
                    valor_operacao,
                    valor_entregue,
                    valor_troco,
                    observacao,
                    data_mov
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $empresa_id,
                $usuario_id,
                $tipo,
                round($saldo, 2),
                round($totalSaida, 2),
                round($totalEntrada, 2),
                $observacao,
                $data_mov
            ]);

            $mov_id = $pdo_master->lastInsertId();
        }

        /* =========================
           SALVAR COMPROVANTES
        ========================= */
        if (!empty($_FILES['comprovante']['name'][0])) {

            $pastaBase = __DIR__ . "/../../uploads/comprovantes/";

            if (!is_dir($pastaBase)) {
                mkdir($pastaBase, 0777, true);
            }

            $stmtComp = $pdo_master->prepare("
                INSERT INTO tesouraria_comprovantes
                (movimentacao_id, caminho_arquivo, nome_original, usuario_id)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($_FILES['comprovante']['name'] as $key => $nomeOriginal) {

                if (trim($nomeOriginal) === '') {
                    continue;
                }

                $tmp = $_FILES['comprovante']['tmp_name'][$key] ?? '';
                $erro = $_FILES['comprovante']['error'][$key] ?? UPLOAD_ERR_NO_FILE;

                if ($erro !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                    continue;
                }

                $nomeOriginalSeguro = nomeArquivoSeguroTesouraria((string)$nomeOriginal);
                $ext = strtolower(pathinfo($nomeOriginalSeguro, PATHINFO_EXTENSION));
                $ext = preg_replace('/[^a-z0-9]+/', '', (string)$ext);
                $novoNome = uniqid('comp_', true) . ($ext ? "." . $ext : '');
                $destino = $pastaBase . $novoNome;

                if (move_uploaded_file($tmp, $destino)) {
                    $caminhoBanco = "uploads/comprovantes/" . $novoNome;

                    $stmtComp->execute([
                        $mov_id,
                        $caminhoBanco,
                        $nomeOriginalSeguro,
                        $usuario_id
                    ]);
                }
            }
        }

        /* =========================
           SALVAR DETALHES - ENTRADA
        ========================= */
        $stmtDetalheEntrada = $pdo_master->prepare("
            INSERT INTO tesouraria_movimentacoes_detalhes
            (movimentacao_id, tipo, tipo_dinheiro_id, quantidade, valor_unitario)
            VALUES (?, 'entrada', ?, ?, ?)
        ");

        foreach ($entrada as $tipo_dinheiro_id => $qtd) {
            $tipo_dinheiro_id = (int)$tipo_dinheiro_id;
            $qtd = (int)$qtd;

            if ($qtd > 0 && isset($tiposMap[$tipo_dinheiro_id])) {
                $stmtDetalheEntrada->execute([
                    $mov_id,
                    $tipo_dinheiro_id,
                    $qtd,
                    $tiposMap[$tipo_dinheiro_id]
                ]);
            }
        }

        /* =========================
           SALVAR DETALHES - SAÍDA
        ========================= */
        $stmtDetalheSaida = $pdo_master->prepare("
            INSERT INTO tesouraria_movimentacoes_detalhes
            (movimentacao_id, tipo, tipo_dinheiro_id, quantidade, valor_unitario)
            VALUES (?, 'saida', ?, ?, ?)
        ");

        foreach ($saida as $tipo_dinheiro_id => $qtd) {
            $tipo_dinheiro_id = (int)$tipo_dinheiro_id;
            $qtd = (int)$qtd;

            if ($qtd > 0 && isset($tiposMap[$tipo_dinheiro_id])) {
                $stmtDetalheSaida->execute([
                    $mov_id,
                    $tipo_dinheiro_id,
                    $qtd,
                    $tiposMap[$tipo_dinheiro_id]
                ]);
            }
        }

        /* =========================
           ATUALIZAR ESTOQUE - ENTRADA
        ========================= */
        $stmtEstoqueEntrada = $pdo_master->prepare("
            INSERT INTO tesouraria_estoque (tipo_dinheiro_id, quantidade)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade)
        ");

        foreach ($entrada as $tipo_dinheiro_id => $qtd) {
            $tipo_dinheiro_id = (int)$tipo_dinheiro_id;
            $qtd = (int)$qtd;

            if ($qtd > 0 && isset($tiposMap[$tipo_dinheiro_id])) {
                $stmtEstoqueEntrada->execute([$tipo_dinheiro_id, $qtd]);
            }
        }

        /* =========================
           ATUALIZAR ESTOQUE - SAÍDA
        ========================= */
        $stmtEstoqueSaida = $pdo_master->prepare("
            UPDATE tesouraria_estoque
            SET quantidade = quantidade - ?
            WHERE tipo_dinheiro_id = ?
        ");

        foreach ($saida as $tipo_dinheiro_id => $qtd) {
            $tipo_dinheiro_id = (int)$tipo_dinheiro_id;
            $qtd = (int)$qtd;

            if ($qtd > 0 && isset($tiposMap[$tipo_dinheiro_id])) {
                $stmtEstoqueSaida->execute([$qtd, $tipo_dinheiro_id]);
            }
        }

        $pdo_master->commit();

        header("Location: movimentar.php?sucesso=1&mov_id=" . $mov_id . ($modoEdicao ? "&id=" . $mov_id : "") . ($fluxoEspecial ? "&fluxo=" . urlencode($fluxo) : ""));
        exit;

    } catch (Throwable $e) {
        if ($pdo_master->inTransaction()) {
            $pdo_master->rollBack();
        }

        die("Erro ao salvar movimentação: " . $e->getMessage());
    }
}

require '../../layout/header.php';

if (isset($_GET['sucesso'])) {
    echo "<script>setTimeout(function(){ window.location.href='menu_movimentacao.php'; }, 0);</script>";
    echo "<script>alert('Movimentação salva com sucesso');</script>";
}

$mov_id = $modoEdicao ? $id : ($_GET['mov_id'] ?? null);
$comprovantes = [];
$tipoFormulario = $mov['tipo_operacao'] ?? ($fluxoEspecial['tipo'] ?? '');
$historicoFormulario = $mov['observacao'] ?? ($fluxoEspecial['historico'] ?? '');
$urlVoltar = 'menu_movimentacao.php';
$mostrarEntrada = !$fluxoEspecial || $fluxo === 'fechamento';
$mostrarSaida = !$fluxoEspecial || $fluxo === 'abertura';
$colunaDinheiro = ($mostrarEntrada && $mostrarSaida) ? 'col-12 col-md-6' : 'col-12';

if ($mov_id) {
    $stmt = $pdo_master->prepare("
        SELECT *
        FROM tesouraria_comprovantes
        WHERE movimentacao_id = ?
    ");
    $stmt->execute([$mov_id]);
    $comprovantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<style>
body {
    font-size: 13px;
}

.card-body {
    padding: 10px;
}

h4 {
    font-size: 16px;
    margin-bottom: 6px;
}

h5 {
    font-size: 14px;
    margin-bottom: 6px;
}

label {
    font-size: 12px;
    margin-bottom: 2px;
    font-weight: 600;
}

hr.my-2 {
    margin-top: 8px !important;
    margin-bottom: 8px !important;
}

/* Tipo de movimentação */
.btn-tipo {
    width: 100%;
    font-size: 12px;
    padding: 6px 2px;
    background: #f1f1f1;
    color: #333;
    border: 1px solid #ccc;
    font-weight: 600;
}

.btn-tipo:hover {
    background: #e0e0e0;
}

.btn-tipo.ativo {
    background: #212529;
    color: #fff;
    border: 2px solid #000;
}

/* Blocos de dinheiro */
.titulo-secao {
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 4px;
}

.subcabecalho {
    display: flex;
    gap: 8px;
    margin-bottom: 4px;
    font-size: 12px;
    font-weight: 700;
}

.subcabecalho .coluna {
    width: 50%;
}

.linha-dupla {
    display: flex;
    gap: 8px;
    margin-bottom: 4px;
}

.bloco-dinheiro {
    width: 50%;
    display: flex;
    align-items: center;
    gap: 4px;
    min-height: 32px;
}

.valor-label {
    width: 55px;
    min-width: 55px;
    font-size: 14px;
    font-weight: 600;
    text-align: right;
}

.qtd {
    width: 48px;
    min-width: 48px;
    height: 30px;
    text-align: center;
    background-color: #e9ecef;
    font-size: 15px;
    font-weight: 600;
    padding: 2px;
    cursor: default;
}

.btn-ajuste {
    padding: 2px 6px;
    font-size: 12px;
    line-height: 1.1;
}

.total-denominacao {
    min-width: 72px;
    font-size: 12px;
    font-weight: 700;
    text-align: right;
    color: #495057;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 3px 6px;
    user-select: none;
}

.total-secao {
    font-size: 12px;
    font-weight: 700;
    margin-top: 4px;
}

/* Resultado */
#saldoFinal {
    font-size: 16px;
    margin: 2px 0;
    font-weight: 700;
}

#msgSaldo {
    font-size: 12px;
    font-weight: 700;
}

/* Histórico e anexos */
textarea {
    font-size: 12px;
    min-height: 58px;
}

input[type="file"] {
    font-size: 12px;
}

#listaArquivos {
    font-size: 12px;
    margin-top: 6px;
}

.arquivo-item {
    background: #f8f9fa;
    padding: 4px 6px;
    border-radius: 6px;
    margin-bottom: 4px;
    border: 1px solid #ddd;
    font-size: 11px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}

.arquivo-item .nome-arquivo {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
}

/* Mobile */
@media (max-width: 576px) {
    body {
        font-size: 12px;
    }

    .card-body {
        padding: 8px;
    }

    h4 {
        font-size: 15px;
    }

    h5 {
        font-size: 13px;
    }

    .btn-tipo {
        font-size: 11px;
        padding: 5px 2px;
    }

    .valor-label {
        width: 52px;
        min-width: 52px;
        font-size: 13px;
    }

    .qtd {
        width: 44px;
        min-width: 44px;
        height: 28px;
        font-size: 14px;
    }

    .btn-ajuste {
        padding: 2px 5px;
        font-size: 11px;
    }

    .total-denominacao {
        min-width: 62px;
        font-size: 11px;
        padding: 2px 4px;
    }

    #saldoFinal {
        font-size: 15px;
    }

    .subcabecalho {
        font-size: 11px;
    }
}
</style>

<div class="card shadow-sm">
    <div class="card-body">

        <h4 class="mb-1"><?= htmlspecialchars($fluxoEspecial['titulo'] ?? 'Tesouraria') ?></h4>

        <form method="POST" enctype="multipart/form-data" id="formMovimentacao">

            <div class="mb-2">
                <label>Tipo de Movimentação</label>

                <div class="row mt-1">
                    <div class="col-4">
                        <button type="button" class="btn btn-tipo" onclick="setTipo('C', this)" <?= $fluxoEspecial ? 'disabled' : '' ?>>
                            Crédito
                        </button>
                    </div>

                    <div class="col-4">
                        <button type="button" class="btn btn-tipo" onclick="setTipo('D', this)" <?= $fluxoEspecial ? 'disabled' : '' ?>>
                            Débito
                        </button>
                    </div>

                    <div class="col-4">
                        <button type="button" class="btn btn-tipo" onclick="setTipo('T', this)" <?= $fluxoEspecial ? 'disabled' : '' ?>>
                            Troca
                        </button>
                    </div>
                </div>

                <?php if ($fluxoEspecial): ?>
                    <div class="form-text">Tipo fixo para esta rotina.</div>
                <?php endif; ?>

                <input type="hidden" name="tipo_movimentacao" id="tipoMovimentacao" value="<?= htmlspecialchars($tipoFormulario) ?>">
            </div>

            <div class="row">
                <!-- Entrada -->
                <?php if ($mostrarEntrada): ?>
                <div class="<?= htmlspecialchars($colunaDinheiro) ?> mb-2">
                    <div class="titulo-secao text-success">Entrada de dinheiro</div>

                    <div class="subcabecalho">
                        <div class="coluna">Moedas</div>
                        <div class="coluna">Cédulas</div>
                    </div>

                    <?php for ($i = 0; $i < $linhas; $i++): ?>
                        <div class="linha-dupla">

                            <div class="bloco-dinheiro">
                                <?php if (isset($moedas[$i])): ?>
                                    <span class="valor-label">R$ <?= number_format($moedas[$i]['valor'], 2, ',', '.') ?></span>

                                    <button type="button" class="btn btn-outline-danger btn-ajuste btn-minus">-</button>

                                    <input
                                        type="text"
                                        readonly
                                        class="form-control qtd entrada"
                                        name="entrada[<?= $moedas[$i]['id'] ?>]"
                                        data-valor="<?= htmlspecialchars($moedas[$i]['valor']) ?>"
                                        value="<?= $entradaEdicao[$moedas[$i]['id']] ?? 0 ?>"
                                    >

                                    <button type="button" class="btn btn-outline-success btn-ajuste btn-plus">+</button>
                                    <button type="button" class="btn btn-outline-primary btn-ajuste btn-plus10">+10</button>
                                    <span class="total-denominacao">R$ 0,00</span>
                                <?php endif; ?>
                            </div>

                            <div class="bloco-dinheiro">
                                <?php if (isset($cedulas[$i])): ?>
                                    <span class="valor-label">R$ <?= number_format($cedulas[$i]['valor'], 2, ',', '.') ?></span>

                                    <button type="button" class="btn btn-outline-danger btn-ajuste btn-minus">-</button>

                                    <input
                                        type="text"
                                        readonly
                                        class="form-control qtd entrada"
                                        name="entrada[<?= $cedulas[$i]['id'] ?>]"
                                        data-valor="<?= htmlspecialchars($cedulas[$i]['valor']) ?>"
                                        value="<?= $entradaEdicao[$cedulas[$i]['id']] ?? 0 ?>"
                                    >

                                    <button type="button" class="btn btn-outline-success btn-ajuste btn-plus">+</button>
                                    <button type="button" class="btn btn-outline-primary btn-ajuste btn-plus10">+10</button>
                                    <span class="total-denominacao">R$ 0,00</span>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endfor; ?>

                    <div class="total-secao">
                        Total Entrada: R$ <span id="totalEntrada">0,00</span>
                    </div>
                </div>
                <?php else: ?>
                    <span id="totalEntrada" class="d-none">0,00</span>
                <?php endif; ?>

                <!-- Saída -->
                <?php if ($mostrarSaida): ?>
                <div class="<?= htmlspecialchars($colunaDinheiro) ?> mb-2">
                    <div class="titulo-secao text-danger">Saída de dinheiro</div>

                    <div class="subcabecalho">
                        <div class="coluna">Moedas</div>
                        <div class="coluna">Cédulas</div>
                    </div>

                    <?php for ($i = 0; $i < $linhas; $i++): ?>
                        <div class="linha-dupla">

                            <div class="bloco-dinheiro">
                                <?php if (isset($moedas[$i])): ?>
                                    <span class="valor-label">R$ <?= number_format($moedas[$i]['valor'], 2, ',', '.') ?></span>

                                    <button type="button" class="btn btn-outline-danger btn-ajuste btn-minus">-</button>

                                    <input
                                        type="text"
                                        readonly
                                        class="form-control qtd saida"
                                        name="saida[<?= $moedas[$i]['id'] ?>]"
                                        data-valor="<?= htmlspecialchars($moedas[$i]['valor']) ?>"
                                        value="<?= $saidaEdicao[$moedas[$i]['id']] ?? 0 ?>"
                                    >

                                    <button type="button" class="btn btn-outline-success btn-ajuste btn-plus">+</button>
                                    <button type="button" class="btn btn-outline-primary btn-ajuste btn-plus10">+10</button>
                                    <span class="total-denominacao">R$ 0,00</span>
                                <?php endif; ?>
                            </div>

                            <div class="bloco-dinheiro">
                                <?php if (isset($cedulas[$i])): ?>
                                    <span class="valor-label">R$ <?= number_format($cedulas[$i]['valor'], 2, ',', '.') ?></span>

                                    <button type="button" class="btn btn-outline-danger btn-ajuste btn-minus">-</button>

                                    <input
                                        type="text"
                                        readonly
                                        class="form-control qtd saida"
                                        name="saida[<?= $cedulas[$i]['id'] ?>]"
                                        data-valor="<?= htmlspecialchars($cedulas[$i]['valor']) ?>"
                                        value="<?= $saidaEdicao[$cedulas[$i]['id']] ?? 0 ?>"
                                    >

                                    <button type="button" class="btn btn-outline-success btn-ajuste btn-plus">+</button>
                                    <button type="button" class="btn btn-outline-primary btn-ajuste btn-plus10">+10</button>
                                    <span class="total-denominacao">R$ 0,00</span>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endfor; ?>

                    <div class="total-secao">
                        Total Saída: R$ <span id="totalSaida">0,00</span>
                    </div>
                </div>
                <?php else: ?>
                    <span id="totalSaida" class="d-none">0,00</span>
                <?php endif; ?>
            </div>

            <hr class="my-2">

            <div class="text-center">
                <h5>Resultado da Movimentação</h5>
                <h3 id="saldoFinal">R$ 0,00</h3>
                <div id="msgSaldo"></div>
            </div>

            <hr class="my-2">

            <?php if ($modoEdicao): ?>
<div class="row mb-2">
    <div class="col-md-4">
        <label>Data do Lançamento</label>
        <input type="datetime-local" 
               name="data_mov" 
               class="form-control"
               value="<?= !empty($mov['data_mov']) ? date('Y-m-d\TH:i', strtotime($mov['data_mov'])) : '' ?>">
    </div>
</div>
<?php endif; ?>

            <div class="row">
                <div class="<?= $dispensaComprovante ? 'col-md-12' : 'col-md-6' ?> mb-2">
                    <label>Histórico *</label>
                    <textarea name="observacao" class="form-control"><?= htmlspecialchars($historicoFormulario) ?></textarea>
                </div>

                <?php if (!$dispensaComprovante): ?>
                <div class="col-md-6 mb-2">
                    <label>Comprovantes <?= ($dispensaComprovante || $modoEdicao) ? '(opcional)' : '*' ?></label>

                    <?php if (!empty($comprovantes)): ?>
                        <div style="margin-top:10px;">
                            <strong>Comprovantes salvos:</strong>

                            <?php foreach ($comprovantes as $c): ?>
                                <div>
                                    <a href="/<?= htmlspecialchars($c['caminho_arquivo']) ?>" target="_blank">
                                        📎 <?= htmlspecialchars($c['nome_original']) ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <input type="file" id="comprovante" name="comprovante[]" multiple class="form-control">
                    <div id="listaArquivos"></div>
                </div>
                <?php endif; ?>
            </div>

            <div class="mt-2">
                <button type="submit" id="btnSalvar" class="btn btn-success w-100" <?= $modoEdicao ? '' : 'disabled' ?>>
                    Salvar Movimentação
                </button>
            </div>

        </form>

    </div>
</div>

<script>
function setTipo(tipo, btn) {
    document.getElementById('tipoMovimentacao').value = tipo;

    document.querySelectorAll('.btn-tipo').forEach(function(botao) {
        botao.classList.remove('ativo');
    });

    btn.classList.add('ativo');
    calcular();
}

function formatar(v) {
    return v.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function atualizarTotalDenominacao(input) {
    const bloco = input.closest('.bloco-dinheiro');
    const totalLinha = bloco ? bloco.querySelector('.total-denominacao') : null;

    if (!totalLinha) {
        return;
    }

    const valor = parseFloat(input.dataset.valor || 0);
    const qtd = parseInt(input.value || 0, 10);
    const total = Math.round((valor * qtd) * 100) / 100;
    totalLinha.innerText = 'R$ ' + formatar(total);
}

function renderListaArquivos() {
    const lista = document.getElementById('listaArquivos');
    const input = document.getElementById('comprovante');

    if (!lista || !input) {
        return;
    }

    lista.innerHTML = '';

    if (input.files.length === 0) {
        return;
    }

    Array.from(input.files).forEach(function(arquivo) {
        const item = document.createElement('div');
        item.className = 'arquivo-item';
        item.innerHTML = `<span class="nome-arquivo">📎 ${arquivo.name}</span>`;
        lista.appendChild(item);
    });
}

const inputComprovante = document.getElementById('comprovante');
if (inputComprovante) {
    inputComprovante.addEventListener('change', function() {
        renderListaArquivos();
        calcular();
    });
}

function calcular() {
    let totalEntrada = 0;
    let totalSaida = 0;

    document.querySelectorAll('.entrada').forEach(function(input) {
        const valor = parseFloat(input.dataset.valor);
        const qtd = parseInt(input.value || 0, 10);
        totalEntrada += valor * qtd;
        atualizarTotalDenominacao(input);
    });

    document.querySelectorAll('.saida').forEach(function(input) {
        const valor = parseFloat(input.dataset.valor);
        const qtd = parseInt(input.value || 0, 10);
        totalSaida += valor * qtd;
        atualizarTotalDenominacao(input);
    });

    totalEntrada = Math.round(totalEntrada * 100) / 100;
    totalSaida = Math.round(totalSaida * 100) / 100;

    document.getElementById('totalEntrada').innerText = formatar(totalEntrada);
    document.getElementById('totalSaida').innerText = formatar(totalSaida);

    const saldo = Math.round((totalEntrada - totalSaida) * 100) / 100;
    document.getElementById('saldoFinal').innerText = 'R$ ' + formatar(saldo);

    const tipo = document.getElementById('tipoMovimentacao').value;
    const historico = document.querySelector('textarea[name="observacao"]').value.trim();
    const inputArquivo = document.getElementById('comprovante');
    const totalArquivosNovos = inputArquivo ? inputArquivo.files.length : 0;
    const totalArquivosExistentes = <?= count($comprovantes) ?>;
    const totalArquivos = totalArquivosNovos + totalArquivosExistentes;
    const dispensaComprovante = <?= $dispensaComprovante ? 'true' : 'false' ?>;
    const modoEdicao = <?= $modoEdicao ? 'true' : 'false' ?>;

    let ok = true;
    let msg = '';

    if (!tipo) {
        ok = false;
        msg = 'Selecione o tipo de movimentação';
    } else if (!historico) {
        ok = false;
        msg = 'Informe o histórico';
    } else if (!modoEdicao && !dispensaComprovante && (tipo === 'D' || tipo === 'C') && totalArquivos === 0) {
        ok = false;
        msg = 'Anexe pelo menos um comprovante';
    } else if (tipo === 'D' && totalSaida <= totalEntrada) {
        ok = false;
        msg = 'Débito inválido';
    } else if (tipo === 'C' && totalEntrada <= totalSaida) {
        ok = false;
        msg = 'Crédito inválido';
    } else if (tipo === 'T' && saldo !== 0) {
        ok = false;
        msg = 'Troca inválida';
    }

    const btnSalvar = document.getElementById('btnSalvar');
    const saldoFinal = document.getElementById('saldoFinal');
    const msgSaldo = document.getElementById('msgSaldo');

    if (!ok) {
        btnSalvar.disabled = true;
        saldoFinal.style.color = 'red';
        msgSaldo.innerText = msg;
    } else {
        btnSalvar.disabled = false;
        saldoFinal.style.color = 'green';
        msgSaldo.innerText = '';
    }
}

document.querySelectorAll('.btn-plus').forEach(function(botao) {
    botao.onclick = function() {
        const input = botao.parentElement.querySelector('input');
        input.value = parseInt(input.value || 0, 10) + 1;
        calcular();
    };
});

document.querySelectorAll('.btn-minus').forEach(function(botao) {
    botao.onclick = function() {
        const input = botao.parentElement.querySelector('input');
        input.value = Math.max(0, parseInt(input.value || 0, 10) - 1);
        calcular();
    };
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('btn-plus10')) {
        const bloco = e.target.closest('.bloco-dinheiro');
        const input = bloco.querySelector('input');
        input.value = parseInt(input.value || 0, 10) + 10;
        calcular();
    }
});

document.querySelectorAll('.qtd').forEach(function(input) {
    input.addEventListener('keydown', function(e) {
        e.preventDefault();
    });
});

document.querySelector('textarea[name="observacao"]').addEventListener('input', calcular);

document.getElementById('formMovimentacao').addEventListener('submit', function(e) {
    const btnSalvar = document.getElementById('btnSalvar');

    if (btnSalvar.disabled) {
        e.preventDefault();
        return false;
    }

    btnSalvar.disabled = true;
    btnSalvar.innerText = 'Salvando...';
});

document.addEventListener('DOMContentLoaded', function() {
    const tipoInicial = document.getElementById('tipoMovimentacao').value;

    if (tipoInicial) {
        document.querySelectorAll('.btn-tipo').forEach(function(botao) {
            const onClick = botao.getAttribute('onclick') || '';
            if (onClick.indexOf("'" + tipoInicial + "'") !== -1) {
                botao.classList.add('ativo');
            }
        });
    }

    calcular();
});
</script>

<style>
.btn-voltar-fixo {
    position: fixed;
    bottom: 15px;
    left: 15px;
    z-index: 9999;
}

@media (max-width: 768px) {
    .btn-voltar-fixo {
        position: static;
        width: 100%;
        margin-top: 15px;
    }
}
</style>

<div class="btn-voltar-fixo">
    <button onclick="window.location.href='<?= htmlspecialchars($urlVoltar) ?>'" class="btn btn-secondary">
        ← Voltar
    </button>
</div>

<?php require '../../layout/footer.php'; ?>
