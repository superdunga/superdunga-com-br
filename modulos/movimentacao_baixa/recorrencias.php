<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';

$pdo = $pdo_master;
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

function recH($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function recFloat($valor)
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

function recMoeda($valor)
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function recData($valor)
{
    return $valor ? date('d/m/Y', strtotime($valor)) : '';
}

function recCompetenciaValida($competencia)
{
    return is_string($competencia) && preg_match('/^\d{4}-\d{2}$/', $competencia);
}

function recVencimentoCompetencia($competencia, $dia)
{
    if (!recCompetenciaValida($competencia)) {
        throw new RuntimeException('Competencia invalida.');
    }
    [$ano, $mes] = array_map('intval', explode('-', $competencia));
    $ultimoDia = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
    $dia = min(max(1, (int)$dia), $ultimoDia);
    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}

function recGarantirTabelas(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mov_baixa_recorrencias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            tipo CHAR(1) NOT NULL,
            fcontador INT NULL,
            clicontador INT NULL,
            tipoes INT NOT NULL,
            titulo VARCHAR(120) NOT NULL,
            observacao TEXT NULL,
            valor DECIMAL(15,2) NOT NULL DEFAULT 0,
            dia_vencimento TINYINT NOT NULL DEFAULT 1,
            inicio_competencia CHAR(7) NULL,
            fim_competencia CHAR(7) NULL,
            ativa CHAR(1) NOT NULL DEFAULT 'S',
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_por INT NULL,
            atualizado_em DATETIME NULL,
            INDEX idx_rec_empresa_tipo (empresa_id, tipo, ativa),
            INDEX idx_rec_competencia (empresa_id, inicio_competencia, fim_competencia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mov_baixa_recorrencias_geracoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            recorrencia_id INT NOT NULL,
            competencia CHAR(7) NOT NULL,
            tabela_destino VARCHAR(10) NOT NULL,
            contador_destino INT NOT NULL,
            valor DECIMAL(15,2) NOT NULL DEFAULT 0,
            usuario_id INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_rec_geracao (empresa_id, recorrencia_id, competencia),
            INDEX idx_rec_geracao_destino (empresa_id, tabela_destino, contador_destino)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function recProximoCpcontador(PDO $pdo, $empresaId)
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CPCONTADOR), 0) + 1 FROM armazem_cp001 WHERE EMPRESA = ?");
    $stmt->execute([$empresaId]);
    return (int)$stmt->fetchColumn();
}

function recProximoCrcontador(PDO $pdo, $empresaId)
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CRCONTADOR), 0) + 1 FROM armazem_cr001 WHERE EMPRESA = ?");
    $stmt->execute([$empresaId]);
    return (int)$stmt->fetchColumn();
}

function recSalvar(PDO $pdo, $empresaId, $usuarioId, array $dados)
{
    $id = (int)($dados['id'] ?? 0);
    $tipo = strtoupper((string)($dados['tipo'] ?? ''));
    $tipoes = (int)($dados['tipoes'] ?? 0);
    $valor = round(recFloat($dados['valor'] ?? 0), 2);
    $dia = (int)($dados['dia_vencimento'] ?? 0);
    $titulo = trim((string)($dados['titulo'] ?? ''));
    $observacao = trim((string)($dados['observacao'] ?? ''));
    $inicio = trim((string)($dados['inicio_competencia'] ?? ''));
    $fim = trim((string)($dados['fim_competencia'] ?? ''));
    $ativa = ($_POST['ativa'] ?? 'S') === 'S' ? 'S' : 'N';
    $fcontador = (int)($dados['fcontador'] ?? 0);
    $clicontador = (int)($dados['clicontador'] ?? 0);

    if (!in_array($tipo, ['P', 'R'], true)) {
        throw new RuntimeException('Informe se a recorrencia e despesa ou receita.');
    }
    if ($titulo === '') {
        throw new RuntimeException('Informe o titulo/documento padrao.');
    }
    if ($valor <= 0) {
        throw new RuntimeException('Informe um valor maior que zero.');
    }
    if ($dia < 1 || $dia > 31) {
        throw new RuntimeException('Informe um dia de vencimento entre 1 e 31.');
    }
    if ($tipoes <= 0) {
        throw new RuntimeException('Informe o TIPOES.');
    }
    $stmtTipo = $pdo->prepare("
        SELECT TIPOMOV
        FROM armazem_bnc005
        WHERE EMPRESA = ?
          AND ESCONTADOR = ?
          AND COALESCE(REGDISAB, 'N') <> 'S'
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmtTipo->execute([$empresaId, $tipoes]);
    $tipoesRegistro = $stmtTipo->fetch(PDO::FETCH_ASSOC);
    $tipomovEsperado = $tipo === 'P' ? 'D' : 'C';
    if (!$tipoesRegistro || strtoupper((string)$tipoesRegistro['TIPOMOV']) !== $tipomovEsperado) {
        throw new RuntimeException($tipo === 'P'
            ? 'Despesa recorrente deve usar TIPOES de debito.'
            : 'Receita recorrente deve usar TIPOES de credito.');
    }
    if ($inicio !== '' && !recCompetenciaValida($inicio)) {
        throw new RuntimeException('Competencia inicial invalida.');
    }
    if ($fim !== '' && !recCompetenciaValida($fim)) {
        throw new RuntimeException('Competencia final invalida.');
    }
    if ($tipo === 'P' && $fcontador <= 0) {
        throw new RuntimeException('Informe o fornecedor da despesa.');
    }
    if ($tipo === 'R' && $clicontador <= 0) {
        throw new RuntimeException('Informe o cliente da receita.');
    }
    if ($tipo === 'P') {
        $stmtFornecedor = $pdo->prepare("
            SELECT COUNT(*)
            FROM armazem_cp003
            WHERE EMPRESA = ?
              AND FCONTADOR = ?
              AND COALESCE(excluido_firebird, 'N') <> 'S'
        ");
        $stmtFornecedor->execute([$empresaId, $fcontador]);
        if ((int)$stmtFornecedor->fetchColumn() <= 0) {
            throw new RuntimeException('Fornecedor nao encontrado.');
        }
    }
    if ($tipo === 'R') {
        $stmtCliente = $pdo->prepare("
            SELECT COUNT(*)
            FROM armazem_cr002
            WHERE EMPRESA = ?
              AND CLICONTADOR = ?
              AND COALESCE(excluido_firebird, 'N') <> 'S'
        ");
        $stmtCliente->execute([$empresaId, $clicontador]);
        if ((int)$stmtCliente->fetchColumn() <= 0) {
            throw new RuntimeException('Cliente nao encontrado.');
        }
    }

    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE mov_baixa_recorrencias
            SET tipo = ?,
                fcontador = ?,
                clicontador = ?,
                tipoes = ?,
                titulo = ?,
                observacao = ?,
                valor = ?,
                dia_vencimento = ?,
                inicio_competencia = ?,
                fim_competencia = ?,
                ativa = ?,
                atualizado_por = ?,
                atualizado_em = NOW()
            WHERE id = ?
              AND empresa_id = ?
        ");
        $stmt->execute([
            $tipo,
            $tipo === 'P' ? $fcontador : null,
            $tipo === 'R' ? $clicontador : null,
            $tipoes,
            $titulo,
            $observacao !== '' ? $observacao : null,
            $valor,
            $dia,
            $inicio !== '' ? $inicio : null,
            $fim !== '' ? $fim : null,
            $ativa,
            $usuarioId ?: null,
            $id,
            $empresaId,
        ]);
        return $id;
    }

    $stmt = $pdo->prepare("
        INSERT INTO mov_baixa_recorrencias (
            empresa_id, tipo, fcontador, clicontador, tipoes, titulo, observacao,
            valor, dia_vencimento, inicio_competencia, fim_competencia, ativa,
            criado_por, atualizado_por, atualizado_em
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )
    ");
    $stmt->execute([
        $empresaId,
        $tipo,
        $tipo === 'P' ? $fcontador : null,
        $tipo === 'R' ? $clicontador : null,
        $tipoes,
        $titulo,
        $observacao !== '' ? $observacao : null,
        $valor,
        $dia,
        $inicio !== '' ? $inicio : null,
        $fim !== '' ? $fim : null,
        $ativa,
        $usuarioId ?: null,
        $usuarioId ?: null,
    ]);
    return (int)$pdo->lastInsertId();
}

function recGerarCompetencia(PDO $pdo, $empresaId, $usuarioId, $competencia)
{
    if (!recCompetenciaValida($competencia)) {
        throw new RuntimeException('Informe uma competencia valida.');
    }

    $stmt = $pdo->prepare("
        SELECT r.*,
               f.NOME AS fornecedor_nome,
               c.NOME AS cliente_nome
        FROM mov_baixa_recorrencias r
        LEFT JOIN armazem_cp003 f ON f.EMPRESA = r.empresa_id AND f.FCONTADOR = r.fcontador
        LEFT JOIN armazem_cr002 c ON c.EMPRESA = r.empresa_id AND c.CLICONTADOR = r.clicontador
        WHERE r.empresa_id = ?
          AND r.ativa = 'S'
          AND (r.inicio_competencia IS NULL OR r.inicio_competencia = '' OR r.inicio_competencia <= ?)
          AND (r.fim_competencia IS NULL OR r.fim_competencia = '' OR r.fim_competencia >= ?)
          AND NOT EXISTS (
              SELECT 1
              FROM mov_baixa_recorrencias_geracoes g
              WHERE g.empresa_id = r.empresa_id
                AND g.recorrencia_id = r.id
                AND g.competencia = ?
          )
        ORDER BY r.tipo, r.titulo, r.id
    ");
    $stmt->execute([$empresaId, $competencia, $competencia, $competencia]);
    $recorrencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $gerados = [];
    $pdo->beginTransaction();
    try {
        foreach ($recorrencias as $rec) {
            $valor = round((float)$rec['valor'], 2);
            $vencimento = recVencimentoCompetencia($competencia, (int)$rec['dia_vencimento']);
            $titulo = trim((string)$rec['titulo']);
            $obs = trim((string)($rec['observacao'] ?? ''));
            $obs = trim(($obs !== '' ? $obs . ' | ' : '') . 'Recorrencia #' . (int)$rec['id'] . ' competencia ' . $competencia);

            if ($rec['tipo'] === 'P') {
                $contador = recProximoCpcontador($pdo, $empresaId);
                $chave = 'MOVBAIXA-REC-CP-' . $empresaId . '-' . $rec['id'] . '-' . $competencia;
                $stmtIns = $pdo->prepare("
                    INSERT INTO armazem_cp001 (
                        EMPRESA, CPCONTADOR, DTCOMPRA, NUMPARCELA, TITULO, VALORCOMPRA,
                        FCONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
                        VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
                        TIPOCP, TIPOES, REGSTAMP, REGIMPORT, USERLANC, DTLANC, USERALT, DTALT,
                        CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
                    ) VALUES (
                        ?, ?, CURDATE(), 1, ?, ?, ?, ?, CURDATE(), ?, '1/1', ?,
                        ?, 0, 'AB', 'SUPERDUNGA', ?, 'RECORRENCIA_MOV_BAIXA',
                        'CP', ?, NOW(), 'S', ?, NOW(), ?, NOW(), ?, 'N', 'N'
                    )
                ");
                $stmtIns->execute([
                    $empresaId,
                    $contador,
                    $titulo,
                    $valor,
                    (int)$rec['fcontador'],
                    $obs,
                    $valor,
                    $vencimento,
                    $valor,
                    $contador,
                    (int)$rec['tipoes'],
                    $usuarioId ?: null,
                    $usuarioId ?: null,
                    $chave,
                ]);
                $destino = 'CP001';
            } else {
                $contador = recProximoCrcontador($pdo, $empresaId);
                $chave = 'MOVBAIXA-REC-CR-' . $empresaId . '-' . $rec['id'] . '-' . $competencia;
                $stmtIns = $pdo->prepare("
                    INSERT INTO armazem_cr001 (
                        EMPRESA, CRCONTADOR, DTVENDA, NUMPARCELA, TITULO, VALORVENDA,
                        CLICONTADOR, OBSERVACAO, DTEMISSAO, VLRPARCELA, PARCELA, DTVENC,
                        VLRRESTANTE, VLRPAGO, STATUS, TIPODOCORIGEM, NUMDOCORIGEM, CONTROLE,
                        TIPOCR, TIPOES, REGSTAMP, USERLANC, DTLANC, USERALT, DTALT,
                        CHAVEINTEGRACAO, financeiro_verificado, excluido_firebird
                    ) VALUES (
                        ?, ?, CURDATE(), 1, ?, ?, ?, ?, CURDATE(), ?, '1/1', ?,
                        ?, 0, 'AB', 'SUPERDUNGA', ?, 'RECORRENCIA_MOV_BAIXA',
                        'CR', ?, NOW(), ?, NOW(), ?, NOW(), ?, 'N', 'N'
                    )
                ");
                $stmtIns->execute([
                    $empresaId,
                    $contador,
                    $titulo,
                    $valor,
                    (int)$rec['clicontador'],
                    $obs,
                    $valor,
                    $vencimento,
                    $valor,
                    $contador,
                    (int)$rec['tipoes'],
                    $usuarioId ?: null,
                    $usuarioId ?: null,
                    $chave,
                ]);
                $destino = 'CR001';
            }

            $stmtGer = $pdo->prepare("
                INSERT INTO mov_baixa_recorrencias_geracoes (
                    empresa_id, recorrencia_id, competencia, tabela_destino,
                    contador_destino, valor, usuario_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtGer->execute([
                $empresaId,
                (int)$rec['id'],
                $competencia,
                $destino,
                $contador,
                $valor,
                $usuarioId ?: null,
            ]);

            $gerados[] = $destino . ' #' . $contador;
        }

        $pdo->commit();
        return $gerados;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

recGarantirTabelas($pdo);

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'salvar_recorrencia') {
            $id = recSalvar($pdo, $empresaId, $usuarioId, $_POST);
            $mensagem = 'Recorrencia #' . $id . ' salva com sucesso.';
            $_GET['editar'] = null;
        } elseif ($acao === 'gerar_competencia') {
            $competencia = trim((string)($_POST['competencia'] ?? ''));
            $gerados = recGerarCompetencia($pdo, $empresaId, $usuarioId, $competencia);
            $mensagem = $gerados
                ? 'Titulos gerados: ' . implode(', ', $gerados) . '.'
                : 'Nenhuma recorrencia pendente para gerar nesta competencia.';
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$fornecedores = $pdo->prepare("
    SELECT FCONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Fornecedor ', FCONTADOR)) AS nome
    FROM armazem_cp003
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
      AND COALESCE(INATIVO, 'N') <> 'S'
    ORDER BY nome, FCONTADOR
");
$fornecedores->execute([$empresaId]);
$fornecedores = $fornecedores->fetchAll(PDO::FETCH_ASSOC);

$clientes = $pdo->prepare("
    SELECT CLICONTADOR, COALESCE(NULLIF(APELIDO, ''), NOME, CONCAT('Cliente ', CLICONTADOR)) AS nome
    FROM armazem_cr002
    WHERE EMPRESA = ?
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY nome, CLICONTADOR
");
$clientes->execute([$empresaId]);
$clientes = $clientes->fetchAll(PDO::FETCH_ASSOC);

$tipos = $pdo->prepare("
    SELECT ESCONTADOR, DESCES, TIPOMOV
    FROM armazem_bnc005
    WHERE EMPRESA = ?
      AND COALESCE(REGDISAB, 'N') <> 'S'
      AND COALESCE(excluido_firebird, 'N') <> 'S'
    ORDER BY TIPOMOV, DESCES, ESCONTADOR
");
$tipos->execute([$empresaId]);
$tipos = $tipos->fetchAll(PDO::FETCH_ASSOC);

$editarId = (int)($_GET['editar'] ?? 0);
$recorrenciaEdicao = null;
if ($editarId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM mov_baixa_recorrencias WHERE empresa_id = ? AND id = ? LIMIT 1");
    $stmt->execute([$empresaId, $editarId]);
    $recorrenciaEdicao = $stmt->fetch(PDO::FETCH_ASSOC);
}

$form = [
    'id' => $recorrenciaEdicao['id'] ?? '',
    'tipo' => $recorrenciaEdicao['tipo'] ?? 'P',
    'fcontador' => $recorrenciaEdicao['fcontador'] ?? '',
    'clicontador' => $recorrenciaEdicao['clicontador'] ?? '',
    'tipoes' => $recorrenciaEdicao['tipoes'] ?? '',
    'titulo' => $recorrenciaEdicao['titulo'] ?? '',
    'observacao' => $recorrenciaEdicao['observacao'] ?? '',
    'valor' => $recorrenciaEdicao ? number_format((float)$recorrenciaEdicao['valor'], 2, ',', '.') : '',
    'dia_vencimento' => $recorrenciaEdicao['dia_vencimento'] ?? date('d'),
    'inicio_competencia' => $recorrenciaEdicao['inicio_competencia'] ?? date('Y-m'),
    'fim_competencia' => $recorrenciaEdicao['fim_competencia'] ?? '',
    'ativa' => $recorrenciaEdicao['ativa'] ?? 'S',
];

$competenciaFiltro = $_GET['competencia'] ?? date('Y-m');

$stmt = $pdo->prepare("
    SELECT r.*,
           COALESCE(NULLIF(f.APELIDO, ''), f.NOME, CONCAT('Fornecedor ', r.fcontador)) AS fornecedor_nome,
           COALESCE(NULLIF(c.APELIDO, ''), c.NOME, CONCAT('Cliente ', r.clicontador)) AS cliente_nome,
           t.DESCES AS tipoes_desc,
           g.competencia AS gerada_competencia,
           g.tabela_destino,
           g.contador_destino
    FROM mov_baixa_recorrencias r
    LEFT JOIN armazem_cp003 f ON f.EMPRESA = r.empresa_id AND f.FCONTADOR = r.fcontador
    LEFT JOIN armazem_cr002 c ON c.EMPRESA = r.empresa_id AND c.CLICONTADOR = r.clicontador
    LEFT JOIN armazem_bnc005 t ON t.EMPRESA = r.empresa_id AND t.ESCONTADOR = r.tipoes
    LEFT JOIN mov_baixa_recorrencias_geracoes g
      ON g.empresa_id = r.empresa_id
     AND g.recorrencia_id = r.id
     AND g.competencia = ?
    WHERE r.empresa_id = ?
    ORDER BY r.ativa DESC, r.tipo, r.titulo, r.id
");
$stmt->execute([$competenciaFiltro, $empresaId]);
$recorrencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

require '../../layout/header.php';
?>

<style>
    .rec-wrap { max-width:1180px; margin:0 auto; padding:18px; }
    .rec-hero { background:#153b68; color:#fff; border-radius:6px; padding:22px; margin-bottom:18px; display:flex; justify-content:space-between; gap:16px; align-items:center; }
    .rec-hero h1 { margin:0 0 6px; font-size:1.55rem; }
    .rec-card { background:#fff; border:1px solid #d8dee8; border-radius:6px; padding:16px; margin-bottom:16px; box-shadow:0 2px 10px rgba(15,23,42,.04); }
    .rec-title { margin:0 0 14px; font-size:1.05rem; color:#1f2937; }
    .rec-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:12px; }
    .rec-field { grid-column:span 3; min-width:0; }
    .rec-field.w2 { grid-column:span 2; }
    .rec-field.w4 { grid-column:span 4; }
    .rec-field.w6 { grid-column:span 6; }
    .rec-field.w12 { grid-column:span 12; }
    .rec-field label { display:block; margin-bottom:5px; font-weight:600; color:#334155; font-size:.88rem; }
    .rec-field input, .rec-field select, .rec-field textarea { width:100%; border:1px solid #cbd5e1; border-radius:5px; padding:8px 9px; font-size:.95rem; background:#fff; }
    .rec-field textarea { min-height:74px; resize:vertical; }
    .rec-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:14px; }
    .rec-btn { border:0; border-radius:5px; padding:9px 13px; background:#173b73; color:#fff; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; }
    .rec-btn.secondary { background:#64748b; }
    .rec-btn.light { background:#e2e8f0; color:#0f172a; }
    .rec-alert { border-radius:5px; padding:11px 13px; margin-bottom:14px; }
    .rec-alert.ok { background:#dcfce7; color:#166534; border:1px solid #86efac; }
    .rec-alert.err { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
    .rec-table-wrap { overflow-x:auto; }
    .rec-table { width:100%; border-collapse:collapse; min-width:980px; }
    .rec-table th, .rec-table td { border-bottom:1px solid #e2e8f0; padding:9px 8px; text-align:left; vertical-align:top; font-size:.9rem; }
    .rec-table th { background:#12336b; color:#fff; font-size:.82rem; text-transform:uppercase; }
    .rec-badge { display:inline-block; border-radius:999px; padding:3px 8px; font-size:.78rem; font-weight:700; background:#e2e8f0; color:#0f172a; }
    .rec-badge.ok { background:#dcfce7; color:#166534; }
    .rec-badge.warn { background:#fff7ed; color:#9a3412; }
    @media (max-width:820px) {
        .rec-wrap { padding:12px; }
        .rec-hero { display:block; padding:18px; }
        .rec-grid { grid-template-columns:1fr; }
        .rec-field, .rec-field.w2, .rec-field.w4, .rec-field.w6, .rec-field.w12 { grid-column:span 1; }
        .rec-actions .rec-btn { width:100%; }
    }
</style>

<div class="rec-wrap">
    <div class="rec-hero">
        <div>
            <h1>Recorrencias</h1>
            <div>Despesas e receitas mensais geradas em lote para CP001 e CR001.</div>
        </div>
        <a class="rec-btn light" href="menu_movimentacao_baixa.php">Voltar</a>
    </div>

    <?php if ($mensagem): ?>
        <div class="rec-alert ok"><?= recH($mensagem) ?></div>
    <?php endif; ?>
    <?php if ($erro): ?>
        <div class="rec-alert err"><?= recH($erro) ?></div>
    <?php endif; ?>

    <div class="rec-card">
        <h2 class="rec-title"><?= $recorrenciaEdicao ? 'Editar recorrencia #' . (int)$recorrenciaEdicao['id'] : 'Nova recorrencia' ?></h2>
        <form method="post" autocomplete="off">
            <input type="hidden" name="acao" value="salvar_recorrencia">
            <input type="hidden" name="id" value="<?= recH($form['id']) ?>">
            <div class="rec-grid">
                <div class="rec-field w2">
                    <label for="tipo">Tipo</label>
                    <select id="tipo" name="tipo" required>
                        <option value="P" <?= $form['tipo'] === 'P' ? 'selected' : '' ?>>Despesa</option>
                        <option value="R" <?= $form['tipo'] === 'R' ? 'selected' : '' ?>>Receita</option>
                    </select>
                </div>
                <div class="rec-field w4 rec-despesa">
                    <label for="fcontador">Fornecedor</label>
                    <select id="fcontador" name="fcontador">
                        <option value="">Selecione</option>
                        <?php foreach ($fornecedores as $fornecedor): ?>
                            <option value="<?= (int)$fornecedor['FCONTADOR'] ?>" <?= (string)$form['fcontador'] === (string)$fornecedor['FCONTADOR'] ? 'selected' : '' ?>>
                                <?= recH($fornecedor['FCONTADOR'] . ' - ' . $fornecedor['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rec-field w4 rec-receita">
                    <label for="clicontador">Cliente</label>
                    <select id="clicontador" name="clicontador">
                        <option value="">Selecione</option>
                        <?php foreach ($clientes as $cliente): ?>
                            <option value="<?= (int)$cliente['CLICONTADOR'] ?>" <?= (string)$form['clicontador'] === (string)$cliente['CLICONTADOR'] ? 'selected' : '' ?>>
                                <?= recH($cliente['CLICONTADOR'] . ' - ' . $cliente['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rec-field w4">
                    <label for="tipoes">TIPOES</label>
                    <select id="tipoes" name="tipoes" required>
                        <option value="">Selecione</option>
                        <?php foreach ($tipos as $tipo): ?>
                            <option value="<?= (int)$tipo['ESCONTADOR'] ?>" data-tipomov="<?= recH($tipo['TIPOMOV']) ?>" <?= (string)$form['tipoes'] === (string)$tipo['ESCONTADOR'] ? 'selected' : '' ?>>
                                <?= recH($tipo['TIPOMOV'] . ' - ' . $tipo['ESCONTADOR'] . ' - ' . ($tipo['DESCES'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rec-field w4">
                    <label for="titulo">Documento/Titulo padrao</label>
                    <input type="text" id="titulo" name="titulo" value="<?= recH($form['titulo']) ?>" required>
                </div>
                <div class="rec-field w2">
                    <label for="valor">Valor</label>
                    <input type="text" id="valor" name="valor" inputmode="decimal" value="<?= recH($form['valor']) ?>" required>
                </div>
                <div class="rec-field w2">
                    <label for="dia_vencimento">Dia vencimento</label>
                    <input type="number" id="dia_vencimento" name="dia_vencimento" min="1" max="31" value="<?= recH($form['dia_vencimento']) ?>" required>
                </div>
                <div class="rec-field w2">
                    <label for="inicio_competencia">Inicio</label>
                    <input type="month" id="inicio_competencia" name="inicio_competencia" value="<?= recH($form['inicio_competencia']) ?>">
                </div>
                <div class="rec-field w2">
                    <label for="fim_competencia">Fim</label>
                    <input type="month" id="fim_competencia" name="fim_competencia" value="<?= recH($form['fim_competencia']) ?>">
                </div>
                <div class="rec-field w2">
                    <label for="ativa">Status</label>
                    <select id="ativa" name="ativa">
                        <option value="S" <?= $form['ativa'] === 'S' ? 'selected' : '' ?>>Ativa</option>
                        <option value="N" <?= $form['ativa'] === 'N' ? 'selected' : '' ?>>Inativa</option>
                    </select>
                </div>
                <div class="rec-field w12">
                    <label for="observacao">Observacao</label>
                    <textarea id="observacao" name="observacao"><?= recH($form['observacao']) ?></textarea>
                </div>
            </div>
            <div class="rec-actions">
                <button type="submit" class="rec-btn">Salvar recorrencia</button>
                <?php if ($recorrenciaEdicao): ?>
                    <a href="recorrencias.php" class="rec-btn secondary">Nova recorrencia</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="rec-card">
        <h2 class="rec-title">Gerar competencia</h2>
        <form method="post" class="rec-grid" autocomplete="off" onsubmit="return confirm('Gerar titulos recorrentes desta competencia?');">
            <input type="hidden" name="acao" value="gerar_competencia">
            <div class="rec-field w3">
                <label for="competencia">Competencia</label>
                <input type="month" id="competencia" name="competencia" value="<?= recH($competenciaFiltro) ?>" required>
            </div>
            <div class="rec-field w9">
                <label>&nbsp;</label>
                <button type="submit" class="rec-btn">Gerar titulos da competencia</button>
                <a class="rec-btn light" href="recorrencias.php?competencia=<?= recH($competenciaFiltro) ?>">Atualizar lista</a>
            </div>
        </form>
    </div>

    <div class="rec-card">
        <h2 class="rec-title">Recorrencias cadastradas</h2>
        <div class="rec-table-wrap">
            <table class="rec-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tipo</th>
                        <th>Cliente/Fornecedor</th>
                        <th>Titulo</th>
                        <th>TIPOES</th>
                        <th>Valor</th>
                        <th>Venc.</th>
                        <th>Status</th>
                        <th>Competencia <?= recH($competenciaFiltro) ?></th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$recorrencias): ?>
                        <tr><td colspan="10" style="text-align:center;color:#64748b;">Nenhuma recorrencia cadastrada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recorrencias as $rec): ?>
                        <tr>
                            <td><?= (int)$rec['id'] ?></td>
                            <td><?= $rec['tipo'] === 'P' ? 'Despesa' : 'Receita' ?></td>
                            <td><?= recH($rec['tipo'] === 'P' ? (($rec['fcontador'] ?? '') . ' - ' . ($rec['fornecedor_nome'] ?? '')) : (($rec['clicontador'] ?? '') . ' - ' . ($rec['cliente_nome'] ?? ''))) ?></td>
                            <td>
                                <strong><?= recH($rec['titulo']) ?></strong>
                                <?php if (!empty($rec['observacao'])): ?>
                                    <div class="text-muted small"><?= recH($rec['observacao']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= recH(($rec['tipoes'] ?? '') . ' - ' . ($rec['tipoes_desc'] ?? '')) ?></td>
                            <td><?= recH(recMoeda($rec['valor'])) ?></td>
                            <td>Dia <?= (int)$rec['dia_vencimento'] ?></td>
                            <td>
                                <span class="rec-badge <?= $rec['ativa'] === 'S' ? 'ok' : 'warn' ?>">
                                    <?= $rec['ativa'] === 'S' ? 'Ativa' : 'Inativa' ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($rec['gerada_competencia'])): ?>
                                    <span class="rec-badge ok">Gerada <?= recH($rec['tabela_destino']) ?> #<?= (int)$rec['contador_destino'] ?></span>
                                <?php else: ?>
                                    <span class="rec-badge warn">Pendente</span>
                                <?php endif; ?>
                            </td>
                            <td><a class="rec-btn light" href="recorrencias.php?editar=<?= (int)$rec['id'] ?>&competencia=<?= recH($competenciaFiltro) ?>">Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const tipo = document.getElementById('tipo');
    const despesa = document.querySelector('.rec-despesa');
    const receita = document.querySelector('.rec-receita');
    const fcontador = document.getElementById('fcontador');
    const clicontador = document.getElementById('clicontador');
    const tipoes = document.getElementById('tipoes');

    function atualizarTipo() {
        const isReceita = tipo && tipo.value === 'R';
        if (despesa) despesa.style.display = isReceita ? 'none' : '';
        if (receita) receita.style.display = isReceita ? '' : 'none';
        if (fcontador) fcontador.required = !isReceita;
        if (clicontador) clicontador.required = isReceita;
        if (!tipoes) return;
        Array.from(tipoes.options).forEach(option => {
            if (!option.value) return;
            const esperado = isReceita ? 'C' : 'D';
            option.hidden = option.getAttribute('data-tipomov') !== esperado;
        });
        const selecionado = tipoes.options[tipoes.selectedIndex];
        if (selecionado && selecionado.hidden) {
            tipoes.value = '';
        }
    }

    if (tipo) {
        tipo.addEventListener('change', atualizarTipo);
        atualizarTipo();
    }
})();
</script>

<?php require '../../layout/footer.php'; ?>
