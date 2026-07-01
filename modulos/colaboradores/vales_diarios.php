<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$nivelUsuario = strtoupper((string)($_SESSION['nivel'] ?? ''));
$podeVerDetalhesBeneficios = in_array($nivelUsuario, ['MASTER', 'GERENTE'], true);

function moedaVales($valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

function decimalVales($valor): float
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
    return round((float)$texto, 2);
}

function valorInputVales($valor): string
{
    return number_format((float)$valor, 2, ',', '.');
}

function dataVales($valor): string
{
    $ts = strtotime((string)$valor);
    return $ts ? date('d/m/Y', $ts) : '';
}

function escalaVales($json): array
{
    $dados = is_array($json) ? $json : json_decode((string)$json, true);
    if (!is_array($dados)) {
        return [];
    }

    $escala = [];
    foreach ($dados as $chave => $valor) {
        if (is_array($valor)) {
            $dia = (int)$chave;
            $ida = !empty($valor['ida']);
            $volta = !empty($valor['volta']);
        } else {
            $dia = (int)$valor;
            $ida = true;
            $volta = true;
        }

        if ($dia <= 0 || (!$ida && !$volta)) {
            continue;
        }
        $escala[$dia] = ['ida' => $ida, 'volta' => $volta];
    }
    ksort($escala);
    return $escala;
}

function diasEscalaVales($json): array
{
    return array_keys(escalaVales($json));
}

function qtdVtEscalaVales(array $escala): int
{
    $qtd = 0;
    foreach ($escala as $dia) {
        $qtd += !empty($dia['ida']) ? 1 : 0;
        $qtd += !empty($dia['volta']) ? 1 : 0;
    }
    return $qtd;
}

function agoraVales(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i');
}

function nomeEmpresaVales(PDO $pdo, int $empresaId): string
{
    if (!empty($_SESSION['empresa_nome'])) {
        return (string)$_SESSION['empresa_nome'];
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(nome_fantasia, ''), NULLIF(razao_social, ''), CONCAT('Empresa ', id)) AS nome
        FROM empresas
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$empresaId]);
    return (string)($stmt->fetchColumn() ?: ('Empresa ' . $empresaId));
}

function imprimirRecibosVales(string $tipo, array $acerto, array $itens, array $totais, string $nomeEmpresa): void
{
    $referencia = (string)$acerto['referencia'];
    $periodo = dataVales($acerto['data_ini']) . ' a ' . dataVales($acerto['data_fim']);
    $titulo = $tipo === 'empresa' ? 'Recibo de pagamento - Vales Diarios' : 'Recibos por colaborador - Vales Diarios';
    $geradoEm = agoraVales();
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($titulo) ?></title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; background: #f3f4f6; color: #111827; font-family: Arial, Helvetica, sans-serif; font-size: 13px; }
            .toolbar { max-width: 900px; margin: 18px auto; display: flex; gap: 8px; justify-content: flex-end; }
            .btn { border: 1px solid #1d4ed8; background: #1d4ed8; color: #fff; padding: 8px 12px; border-radius: 6px; text-decoration: none; font-weight: 700; cursor: pointer; }
            .btn-sec { background: #fff; color: #1d4ed8; }
            .recibo { max-width: 900px; margin: 0 auto 18px; background: #fff; border: 1px solid #1f2937; page-break-inside: avoid; }
            .topo { display: grid; grid-template-columns: 1.4fr .6fr; gap: 12px; padding: 14px 16px; border-bottom: 2px solid #1f2937; }
            .topo h1 { margin: 0 0 4px; font-size: 18px; text-transform: uppercase; }
            .topo .empresa { font-weight: 800; font-size: 15px; }
            .badge { display: inline-block; padding: 4px 8px; border-radius: 999px; background: #dbeafe; color: #1e40af; font-weight: 800; font-size: 11px; }
            .meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; padding: 12px 16px; border-bottom: 1px solid #d1d5db; }
            .label { display: block; color: #4b5563; font-size: 10px; font-weight: 800; text-transform: uppercase; margin-bottom: 2px; }
            .resumo { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; padding: 14px 16px; }
            .box { border: 1px solid #d1d5db; border-radius: 6px; padding: 10px; }
            .box strong { display: block; font-size: 18px; margin-top: 3px; }
            .texto { padding: 12px 16px; line-height: 1.55; border-top: 1px solid #d1d5db; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
            th { background: #e8eef8; font-size: 11px; text-transform: uppercase; }
            .num { text-align: right; white-space: nowrap; }
            .assinaturas { display: grid; grid-template-columns: 1fr 1fr; gap: 42px; padding: 42px 16px 18px; }
            .linha { border-top: 1px solid #111827; text-align: center; padding-top: 6px; }
            .page-break { page-break-after: always; }
            @media print {
                body { background: #fff; }
                .toolbar { display: none; }
                .recibo { margin: 0 0 10px; max-width: none; border-color: #111827; }
            }
        </style>
    </head>
    <body>
        <div class="toolbar">
            <button class="btn" onclick="window.print()">Imprimir / salvar PDF</button>
            <a class="btn btn-sec" href="vales_diarios.php?referencia=<?= urlencode($referencia) ?>&acerto_id=<?= (int)$acerto['id'] ?>">Voltar</a>
        </div>

        <?php if ($tipo === 'empresa'): ?>
            <article class="recibo">
                <div class="topo">
                    <div>
                        <h1>Recibo de pagamento de vales diarios</h1>
                        <div class="empresa"><?= htmlspecialchars($nomeEmpresa) ?></div>
                    </div>
                    <div class="num">
                        <span class="badge">Acerto #<?= (int)$acerto['id'] ?></span>
                    </div>
                </div>
                <div class="meta">
                    <div><span class="label">Competencia</span><?= htmlspecialchars($referencia) ?></div>
                    <div><span class="label">Periodo</span><?= htmlspecialchars($periodo) ?></div>
                    <div><span class="label">Status</span><?= htmlspecialchars((string)$acerto['status']) ?></div>
                    <div><span class="label">Gerado em</span><?= htmlspecialchars($geradoEm) ?></div>
                </div>
                <div class="resumo">
                    <div class="box"><span class="label">Colaboradores</span><strong><?= number_format(count($itens), 0, ',', '.') ?></strong></div>
                    <div class="box"><span class="label">Dias com VA</span><strong><?= number_format($totais['dias_va'], 0, ',', '.') ?></strong></div>
                    <div class="box"><span class="label">VTs</span><strong><?= number_format($totais['qtd_vt'], 0, ',', '.') ?> | <?= moedaVales($totais['vt']) ?></strong></div>
                    <div class="box"><span class="label">VA</span><strong><?= moedaVales($totais['va']) ?></strong></div>
                </div>
                <div class="texto">
                    Declaramos para os devidos fins que a empresa <strong><?= htmlspecialchars($nomeEmpresa) ?></strong>
                    realizou o pagamento de vales diarios referente ao acerto #<?= (int)$acerto['id'] ?>,
                    competencia <?= htmlspecialchars($referencia) ?>, periodo <?= htmlspecialchars($periodo) ?>,
                    no valor total de <strong><?= moedaVales($totais['geral']) ?></strong>.
                </div>
                <div class="resumo">
                    <div class="box" style="grid-column: 1 / -1;"><span class="label">Total geral pago</span><strong><?= moedaVales($totais['geral']) ?></strong></div>
                </div>
                <div class="assinaturas">
                    <div class="linha">Responsavel pela empresa</div>
                    <div class="linha">Conferencia financeira</div>
                </div>
            </article>
        <?php else: ?>
            <?php foreach ($itens as $idx => $item): ?>
                <?php
                    $temVtRecibo = (float)$item['valor_vt'] > 0 || (float)$item['total_vt'] > 0;
                    $temVaRecibo = (float)$item['valor_va'] > 0 || (float)$item['total_va'] > 0;
                    if ($temVtRecibo && $temVaRecibo) {
                        $descricaoBeneficios = 'vale transporte e vale alimentacao';
                    } elseif ($temVtRecibo) {
                        $descricaoBeneficios = 'vale transporte';
                    } elseif ($temVaRecibo) {
                        $descricaoBeneficios = 'vale alimentacao';
                    } else {
                        $descricaoBeneficios = 'vales diarios';
                    }
                ?>
                <article class="recibo <?= $idx < count($itens) - 1 ? 'page-break' : '' ?>">
                    <div class="topo">
                        <div>
                            <h1>Recibo individual de vales diarios</h1>
                            <div class="empresa"><?= htmlspecialchars($nomeEmpresa) ?></div>
                        </div>
                        <div class="num">
                            <span class="badge">Acerto #<?= (int)$acerto['id'] ?></span>
                        </div>
                    </div>
                    <div class="meta">
                        <div><span class="label">Competencia</span><?= htmlspecialchars($referencia) ?></div>
                        <div><span class="label">Periodo</span><?= htmlspecialchars($periodo) ?></div>
                        <div><span class="label">Codigo</span><?= (int)$item['funcionario_id'] ?></div>
                        <div><span class="label">Gerado em</span><?= htmlspecialchars($geradoEm) ?></div>
                    </div>
                    <div class="meta">
                        <div style="grid-column: 1 / -1;"><span class="label">Colaborador</span><strong><?= htmlspecialchars((string)$item['nome_funcionario']) ?></strong></div>
                    </div>
                    <div class="texto">
                        Recebi de <strong><?= htmlspecialchars($nomeEmpresa) ?></strong> os valores abaixo discriminados,
                        referentes ao pagamento antecipado de <?= htmlspecialchars($descricaoBeneficios) ?> do periodo <?= htmlspecialchars($periodo) ?>.
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Descricao</th>
                                <th class="num">Quantidade</th>
                                <th class="num">Valor unitario</th>
                                <th class="num">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ((float)$item['valor_vt'] > 0 || (float)$item['total_vt'] > 0): ?>
                                <tr>
                                    <td>Vale transporte</td>
                                    <td class="num"><?= number_format((int)($item['qtd_vt'] ?? 0), 0, ',', '.') ?></td>
                                    <td class="num"><?= moedaVales($item['valor_vt']) ?></td>
                                    <td class="num"><?= moedaVales($item['total_vt']) ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ((float)$item['valor_va'] > 0 || (float)$item['total_va'] > 0): ?>
                                <tr>
                                    <td>Vale alimentacao</td>
                                    <td class="num"><?= number_format((int)($item['qtd_dias_va'] ?? $item['qtd_dias']), 0, ',', '.') ?></td>
                                    <td class="num"><?= moedaVales($item['valor_va']) ?></td>
                                    <td class="num"><?= moedaVales($item['total_va']) ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3" class="num">Total recebido</th>
                                <th class="num"><?= moedaVales($item['total_geral']) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                    <div class="assinaturas">
                        <div class="linha"><?= htmlspecialchars((string)$item['nome_funcionario']) ?></div>
                        <div class="linha">Responsavel pela empresa</div>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

function colunaExisteVales(PDO $pdo, string $tabela, string $coluna): bool
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

function indiceExisteVales(PDO $pdo, string $tabela, string $indice): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $stmt->execute([$tabela, $indice]);
    return (int)$stmt->fetchColumn() > 0;
}

function garantirTabelasValesDiarios(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS colaboradores_vales_parametros (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            funcionario_id INT NOT NULL,
            recebe_vt CHAR(1) NOT NULL DEFAULT 'N',
            qtd_vt_dia DECIMAL(10,2) NOT NULL DEFAULT 0,
            valor_vt_unitario DECIMAL(15,2) NOT NULL DEFAULT 0,
            recebe_va CHAR(1) NOT NULL DEFAULT 'N',
            valor_vt DECIMAL(15,2) NOT NULL DEFAULT 0,
            valor_va DECIMAL(15,2) NOT NULL DEFAULT 0,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            atualizado_por INT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_vales_param_func (empresa_id, funcionario_id),
            KEY idx_vales_param_empresa (empresa_id, ativo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!colunaExisteVales($pdo, 'colaboradores_vales_parametros', 'qtd_vt_dia')) {
        $pdo->exec("ALTER TABLE colaboradores_vales_parametros ADD COLUMN qtd_vt_dia DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER recebe_vt");
    }
    if (!colunaExisteVales($pdo, 'colaboradores_vales_parametros', 'valor_vt_unitario')) {
        $pdo->exec("ALTER TABLE colaboradores_vales_parametros ADD COLUMN valor_vt_unitario DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER qtd_vt_dia");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS colaboradores_vales_competencias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            referencia CHAR(7) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'ABERTA',
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_por INT NULL,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_vales_competencia (empresa_id, referencia),
            KEY idx_vales_competencia_status (empresa_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS colaboradores_vales_acertos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            competencia_id INT NOT NULL,
            empresa_id INT NOT NULL,
            referencia CHAR(7) NOT NULL,
            data_ini DATE NOT NULL,
            data_fim DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
            observacao VARCHAR(255) NULL,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_por INT NULL,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_vales_acertos_ref (empresa_id, referencia, data_ini, data_fim),
            KEY idx_vales_acertos_status (empresa_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS colaboradores_vales_itens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            competencia_id INT NOT NULL,
            acerto_id INT NULL,
            empresa_id INT NOT NULL,
            referencia CHAR(7) NOT NULL,
            funcionario_id INT NOT NULL,
            nome_funcionario VARCHAR(255) NOT NULL,
            dias_json LONGTEXT NOT NULL,
            qtd_dias INT NOT NULL DEFAULT 0,
            qtd_vt INT NOT NULL DEFAULT 0,
            qtd_dias_va INT NOT NULL DEFAULT 0,
            valor_vt DECIMAL(15,2) NOT NULL DEFAULT 0,
            valor_va DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_vt DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_va DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_geral DECIMAL(15,2) NOT NULL DEFAULT 0,
            atualizado_por INT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_vales_item_empresa_ref (empresa_id, referencia),
            KEY idx_vales_item_acerto (acerto_id, funcionario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!colunaExisteVales($pdo, 'colaboradores_vales_itens', 'acerto_id')) {
        $pdo->exec("ALTER TABLE colaboradores_vales_itens ADD COLUMN acerto_id INT NULL AFTER competencia_id");
    }
    if (!colunaExisteVales($pdo, 'colaboradores_vales_itens', 'qtd_vt')) {
        $pdo->exec("ALTER TABLE colaboradores_vales_itens ADD COLUMN qtd_vt INT NOT NULL DEFAULT 0 AFTER qtd_dias");
    }
    if (!colunaExisteVales($pdo, 'colaboradores_vales_itens', 'qtd_dias_va')) {
        $pdo->exec("ALTER TABLE colaboradores_vales_itens ADD COLUMN qtd_dias_va INT NOT NULL DEFAULT 0 AFTER qtd_vt");
    }
    if (indiceExisteVales($pdo, 'colaboradores_vales_itens', 'uniq_vales_item_func')) {
        $pdo->exec("ALTER TABLE colaboradores_vales_itens DROP INDEX uniq_vales_item_func");
    }
    if (!indiceExisteVales($pdo, 'colaboradores_vales_itens', 'uniq_vales_item_acerto_func')) {
        $pdo->exec("ALTER TABLE colaboradores_vales_itens ADD UNIQUE KEY uniq_vales_item_acerto_func (acerto_id, funcionario_id)");
    }
}

function buscarFuncionariosVales(PDO $pdo, int $empresaId, string $fimMes): array
{
    $stmt = $pdo->prepare("
        SELECT FUNCCONTADOR, NOMEFUNC, DTADMISSAO, DTDEMISSAO, QTDEVALES
        FROM armazem_REP001
        WHERE EMPRESA = ?
          AND DTADMISSAO <= ?
          AND DTDEMISSAO IS NULL
          AND COALESCE(QTDEVALES, 0) = 10
          AND COALESCE(INATIVO, 'N') NOT IN ('S', '1')
        ORDER BY NOMEFUNC
    ");
    $stmt->execute([$empresaId, $fimMes]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarCompetenciaVales(PDO $pdo, int $empresaId, string $referencia): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM colaboradores_vales_competencias WHERE empresa_id = ? AND referencia = ? LIMIT 1");
    $stmt->execute([$empresaId, $referencia]);
    $competencia = $stmt->fetch(PDO::FETCH_ASSOC);
    return $competencia ?: null;
}

function garantirCompetenciaVales(PDO $pdo, int $empresaId, int $usuarioId, string $referencia): array
{
    $stmt = $pdo->prepare("
        INSERT INTO colaboradores_vales_competencias (empresa_id, referencia, status, criado_por, atualizado_por)
        VALUES (?, ?, 'ABERTA', ?, ?)
        ON DUPLICATE KEY UPDATE atualizado_por = VALUES(atualizado_por)
    ");
    $stmt->execute([$empresaId, $referencia, $usuarioId ?: null, $usuarioId ?: null]);
    $competencia = buscarCompetenciaVales($pdo, $empresaId, $referencia);
    if (!$competencia) {
        throw new RuntimeException('Nao foi possivel gerar a competencia.');
    }
    return $competencia;
}

function buscarAcertoVales(PDO $pdo, int $empresaId, int $acertoId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM colaboradores_vales_acertos WHERE empresa_id = ? AND id = ? LIMIT 1");
    $stmt->execute([$empresaId, $acertoId]);
    $acerto = $stmt->fetch(PDO::FETCH_ASSOC);
    return $acerto ?: null;
}

function diasPeriodoVales(string $dataIni, string $dataFim): array
{
    $inicio = new DateTimeImmutable($dataIni);
    $fim = new DateTimeImmutable($dataFim);
    $dias = [];
    while ($inicio <= $fim) {
        $dias[] = [
            'data' => $inicio->format('Y-m-d'),
            'dia' => (int)$inicio->format('d'),
            'label' => $inicio->format('d/m'),
            'semana' => ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'][(int)$inicio->format('N') - 1],
        ];
        $inicio = $inicio->modify('+1 day');
    }
    return $dias;
}

function diasBloqueadosVales(PDO $pdo, int $empresaId, string $referencia, int $funcionarioId, int $acertoAtualId): array
{
    $stmt = $pdo->prepare("
        SELECT a.id AS acerto_id, a.status, i.dias_json
        FROM colaboradores_vales_itens i
        INNER JOIN colaboradores_vales_acertos a ON a.id = i.acerto_id
        WHERE i.empresa_id = ?
          AND i.referencia = ?
          AND i.funcionario_id = ?
          AND i.acerto_id <> ?
          AND a.status IN ('ABERTO', 'FECHADO', 'PAGO')
    ");
    $stmt->execute([$empresaId, $referencia, $funcionarioId, $acertoAtualId]);

    $bloqueados = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        foreach (diasEscalaVales($linha['dias_json']) as $dia) {
            $bloqueados[(int)$dia] = [
                'acerto_id' => (int)$linha['acerto_id'],
                'status' => (string)$linha['status'],
            ];
        }
    }
    return $bloqueados;
}

function gerarItensAcertoVales(PDO $pdo, int $empresaId, int $usuarioId, array $competencia, int $acertoId, array $funcionarios): void
{
    $stmtParam = $pdo->prepare("
        SELECT *
        FROM colaboradores_vales_parametros
        WHERE empresa_id = ?
          AND funcionario_id = ?
          AND ativo = 'S'
        LIMIT 1
    ");
    $stmtItem = $pdo->prepare("
        INSERT INTO colaboradores_vales_itens
            (competencia_id, acerto_id, empresa_id, referencia, funcionario_id, nome_funcionario, dias_json,
             qtd_dias, qtd_vt, qtd_dias_va, valor_vt, valor_va, total_vt, total_va, total_geral, atualizado_por)
        VALUES (?, ?, ?, ?, ?, ?, '[]', 0, 0, 0, ?, ?, 0, 0, 0, ?)
        ON DUPLICATE KEY UPDATE
            nome_funcionario = VALUES(nome_funcionario),
            valor_vt = IF(qtd_dias = 0, VALUES(valor_vt), valor_vt),
            valor_va = IF(qtd_dias = 0, VALUES(valor_va), valor_va),
            atualizado_por = VALUES(atualizado_por)
    ");

    foreach ($funcionarios as $funcionario) {
        $funcionarioId = (int)$funcionario['FUNCCONTADOR'];
        $stmtParam->execute([$empresaId, $funcionarioId]);
        $param = $stmtParam->fetch(PDO::FETCH_ASSOC) ?: [];
        $valorVtUnitario = (float)($param['valor_vt_unitario'] ?? 0);
        $valorVt = ($param['recebe_vt'] ?? 'N') === 'S' ? $valorVtUnitario : 0.0;
        $valorVa = ($param['recebe_va'] ?? 'N') === 'S' ? (float)($param['valor_va'] ?? 0) : 0.0;

        $stmtItem->execute([
            (int)$competencia['id'],
            $acertoId,
            $empresaId,
            (string)$competencia['referencia'],
            $funcionarioId,
            (string)$funcionario['NOMEFUNC'],
            $valorVt,
            $valorVa,
            $usuarioId ?: null,
        ]);
    }
}

function validarConflitosAcertoVales(PDO $pdo, int $empresaId, string $referencia, int $acertoId): array
{
    $stmt = $pdo->prepare("
        SELECT id, funcionario_id, nome_funcionario, dias_json
        FROM colaboradores_vales_itens
        WHERE empresa_id = ?
          AND referencia = ?
          AND acerto_id = ?
        ORDER BY nome_funcionario
    ");
    $stmt->execute([$empresaId, $referencia, $acertoId]);

    $conflitos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $dias = diasEscalaVales($item['dias_json']);
        if (!$dias) {
            continue;
        }
        $bloqueados = diasBloqueadosVales($pdo, $empresaId, $referencia, (int)$item['funcionario_id'], $acertoId);
        $intersecao = array_values(array_intersect($dias, array_keys($bloqueados)));
        if ($intersecao) {
            $conflitos[] = $item['nome_funcionario'] . ': dia(s) ' . implode(', ', $intersecao);
        }
    }
    return $conflitos;
}

garantirTabelasValesDiarios($pdo_master);

$referencia = $_REQUEST['referencia'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $referencia)) {
    $referencia = date('Y-m');
}

$inicioMes = $referencia . '-01';
$fimMes = date('Y-m-t', strtotime($inicioMes));
$acao = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['acao'] ?? '') : '';
$mensagemOk = '';
$mensagemErro = '';
$funcionarios = buscarFuncionariosVales($pdo_master, $empresaId, $fimMes);

try {
    if ($acao === 'salvar_parametros') {
        if (!$podeVerDetalhesBeneficios) {
            throw new RuntimeException('Somente usuarios MASTER ou GERENTE podem alterar os parametros de beneficios.');
        }

        $stmtParam = $pdo_master->prepare("
            INSERT INTO colaboradores_vales_parametros
                (empresa_id, funcionario_id, recebe_vt, qtd_vt_dia, valor_vt_unitario, recebe_va, valor_vt, valor_va, ativo, atualizado_por)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                recebe_vt = VALUES(recebe_vt),
                qtd_vt_dia = VALUES(qtd_vt_dia),
                valor_vt_unitario = VALUES(valor_vt_unitario),
                recebe_va = VALUES(recebe_va),
                valor_vt = VALUES(valor_vt),
                valor_va = VALUES(valor_va),
                ativo = VALUES(ativo),
                atualizado_por = VALUES(atualizado_por)
        ");

        foreach ($_POST['funcionario_id'] ?? [] as $funcionarioId) {
            $funcionarioId = (int)$funcionarioId;
            $valorVtUnitario = decimalVales($_POST['valor_vt_unitario'][$funcionarioId] ?? '0');
            $stmtParam->execute([
                $empresaId,
                $funcionarioId,
                isset($_POST['recebe_vt'][$funcionarioId]) ? 'S' : 'N',
                0,
                $valorVtUnitario,
                isset($_POST['recebe_va'][$funcionarioId]) ? 'S' : 'N',
                $valorVtUnitario,
                decimalVales($_POST['valor_va'][$funcionarioId] ?? '0'),
                isset($_POST['beneficio_ativo'][$funcionarioId]) ? 'S' : 'N',
                $usuarioId ?: null,
            ]);
        }
        $mensagemOk = 'Parametros salvos.';
    }

    if ($acao === 'criar_acerto') {
        $competencia = garantirCompetenciaVales($pdo_master, $empresaId, $usuarioId, $referencia);
        $dataIni = (string)($_POST['data_ini'] ?? '');
        $dataFim = (string)($_POST['data_fim'] ?? '');
        $observacao = trim((string)($_POST['observacao'] ?? ''));
        if ($dataIni < $inicioMes || $dataFim > $fimMes || $dataIni > $dataFim) {
            throw new RuntimeException('Informe um periodo valido dentro da competencia.');
        }

        $stmtAcerto = $pdo_master->prepare("
            INSERT INTO colaboradores_vales_acertos
                (competencia_id, empresa_id, referencia, data_ini, data_fim, status, observacao, criado_por, atualizado_por)
            VALUES (?, ?, ?, ?, ?, 'ABERTO', NULLIF(?, ''), ?, ?)
        ");
        $stmtAcerto->execute([(int)$competencia['id'], $empresaId, $referencia, $dataIni, $dataFim, $observacao, $usuarioId ?: null, $usuarioId ?: null]);
        $acertoIdCriado = (int)$pdo_master->lastInsertId();
        gerarItensAcertoVales($pdo_master, $empresaId, $usuarioId, $competencia, $acertoIdCriado, $funcionarios);
        header('Location: vales_diarios.php?referencia=' . urlencode($referencia) . '&acerto_id=' . $acertoIdCriado . '&ok=acerto');
        exit;
    }

    if ($acao === 'atualizar_colaboradores') {
        $acerto = buscarAcertoVales($pdo_master, $empresaId, (int)($_POST['acerto_id'] ?? 0));
        if (!$acerto || ($acerto['status'] ?? '') !== 'ABERTO') {
            throw new RuntimeException('Selecione um acerto aberto.');
        }
        $competencia = buscarCompetenciaVales($pdo_master, $empresaId, $referencia);
        if (!$competencia) {
            throw new RuntimeException('Competencia nao encontrada.');
        }
        gerarItensAcertoVales($pdo_master, $empresaId, $usuarioId, $competencia, (int)$acerto['id'], $funcionarios);
        $mensagemOk = 'Colaboradores atualizados no acerto.';
    }

    if ($acao === 'salvar_dias') {
        $acerto = buscarAcertoVales($pdo_master, $empresaId, (int)($_POST['acerto_id'] ?? 0));
        if (!$acerto || ($acerto['status'] ?? '') !== 'ABERTO') {
            throw new RuntimeException('Somente acertos abertos podem ser alterados.');
        }
        $diasPermitidos = array_column(diasPeriodoVales($acerto['data_ini'], $acerto['data_fim']), 'dia');
        $stmtItem = $pdo_master->prepare("
            UPDATE colaboradores_vales_itens
            SET dias_json = ?,
                qtd_dias = ?,
                qtd_vt = ?,
                qtd_dias_va = ?,
                valor_vt = ?,
                valor_va = ?,
                total_vt = ?,
                total_va = ?,
                total_geral = ?,
                atualizado_por = ?
            WHERE id = ?
              AND empresa_id = ?
              AND acerto_id = ?
        ");
        $stmtItemAtual = $pdo_master->prepare("
            SELECT valor_vt, valor_va
            FROM colaboradores_vales_itens
            WHERE id = ?
              AND empresa_id = ?
              AND acerto_id = ?
            LIMIT 1
        ");

        foreach ($_POST['item_id'] ?? [] as $itemId) {
            $itemId = (int)$itemId;
            $idas = array_map('intval', $_POST['ida'][$itemId] ?? []);
            $voltas = array_map('intval', $_POST['volta'][$itemId] ?? []);
            $diasComMarcacao = array_values(array_unique(array_merge($idas, $voltas)));
            $diasComMarcacao = array_values(array_intersect($diasComMarcacao, $diasPermitidos));
            sort($diasComMarcacao);

            $escala = [];
            foreach ($diasComMarcacao as $dia) {
                $ida = in_array($dia, $idas, true);
                $volta = in_array($dia, $voltas, true);
                if ($ida || $volta) {
                    $escala[(int)$dia] = ['ida' => $ida, 'volta' => $volta];
                }
            }

            $qtdDias = count($escala);
            $qtdVt = qtdVtEscalaVales($escala);
            $qtdDiasVa = $qtdDias;
            $stmtItemAtual->execute([$itemId, $empresaId, (int)$acerto['id']]);
            $itemAtual = $stmtItemAtual->fetch(PDO::FETCH_ASSOC) ?: ['valor_vt' => 0, 'valor_va' => 0];
            $valorVt = (float)$itemAtual['valor_vt'];
            $valorVa = (float)$itemAtual['valor_va'];
            $totalVt = round($qtdVt * $valorVt, 2);
            $totalVa = round($qtdDiasVa * $valorVa, 2);
            $totalGeral = round($totalVt + $totalVa, 2);
            $stmtItem->execute([
                json_encode($escala, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $qtdDias,
                $qtdVt,
                $qtdDiasVa,
                $valorVt,
                $valorVa,
                $totalVt,
                $totalVa,
                $totalGeral,
                $usuarioId ?: null,
                $itemId,
                $empresaId,
                (int)$acerto['id'],
            ]);
        }

        $conflitos = validarConflitosAcertoVales($pdo_master, $empresaId, $referencia, (int)$acerto['id']);
        if ($conflitos) {
            throw new RuntimeException('Existem dias ja selecionados em outro acerto para este funcionario: ' . implode(' | ', $conflitos));
        }
        $mensagemOk = 'Escala e valores salvos.';
    }

    if (in_array($acao, ['fechar', 'pagar', 'reabrir'], true)) {
        $acerto = buscarAcertoVales($pdo_master, $empresaId, (int)($_POST['acerto_id'] ?? 0));
        if (!$acerto) {
            throw new RuntimeException('Acerto nao encontrado.');
        }
        if (in_array($acao, ['fechar', 'pagar'], true)) {
            $conflitos = validarConflitosAcertoVales($pdo_master, $empresaId, $referencia, (int)$acerto['id']);
            if ($conflitos) {
                throw new RuntimeException('Nao foi possivel fechar/pagar. Dias ja selecionados em outro acerto: ' . implode(' | ', $conflitos));
            }
        }
        $novoStatus = ['fechar' => 'FECHADO', 'pagar' => 'PAGO', 'reabrir' => 'ABERTO'][$acao];
        $stmtStatus = $pdo_master->prepare("
            UPDATE colaboradores_vales_acertos
            SET status = ?,
                atualizado_por = ?
            WHERE empresa_id = ?
              AND id = ?
        ");
        $stmtStatus->execute([$novoStatus, $usuarioId ?: null, $empresaId, (int)$acerto['id']]);
        $mensagemOk = 'Status do acerto atualizado.';
    }
} catch (Throwable $e) {
    $mensagemErro = $e->getMessage();
}

$competencia = buscarCompetenciaVales($pdo_master, $empresaId, $referencia);
$competenciaId = (int)($competencia['id'] ?? 0);

$stmtAcertos = $pdo_master->prepare("
    SELECT a.*,
           COALESCE(SUM(i.total_geral), 0) AS total_geral,
           COALESCE(SUM(i.total_vt), 0) AS total_vt,
           COALESCE(SUM(i.total_va), 0) AS total_va,
           COALESCE(SUM(i.qtd_dias), 0) AS total_dias,
           COALESCE(SUM(i.qtd_vt), 0) AS total_qtd_vt,
           COALESCE(SUM(i.qtd_dias_va), 0) AS total_dias_va
    FROM colaboradores_vales_acertos a
    LEFT JOIN colaboradores_vales_itens i ON i.acerto_id = a.id
    WHERE a.empresa_id = ?
      AND a.referencia = ?
    GROUP BY a.id
    ORDER BY a.data_ini DESC, a.id DESC
");
$stmtAcertos->execute([$empresaId, $referencia]);
$acertos = $stmtAcertos->fetchAll(PDO::FETCH_ASSOC);

$acertoId = (int)($_REQUEST['acerto_id'] ?? ($acertos[0]['id'] ?? 0));
$acertoAtual = $acertoId > 0 ? buscarAcertoVales($pdo_master, $empresaId, $acertoId) : null;
$periodoDias = $acertoAtual ? diasPeriodoVales($acertoAtual['data_ini'], $acertoAtual['data_fim']) : [];

$stmtParametros = $pdo_master->prepare("SELECT * FROM colaboradores_vales_parametros WHERE empresa_id = ?");
$stmtParametros->execute([$empresaId]);
$parametros = [];
foreach ($stmtParametros->fetchAll(PDO::FETCH_ASSOC) as $parametro) {
    $parametros[(int)$parametro['funcionario_id']] = $parametro;
}

$itens = [];
if ($acertoAtual) {
    $stmtItens = $pdo_master->prepare("
        SELECT *
        FROM colaboradores_vales_itens
        WHERE empresa_id = ?
          AND acerto_id = ?
        ORDER BY nome_funcionario
    ");
    $stmtItens->execute([$empresaId, (int)$acertoAtual['id']]);
    $itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);
}

$bloqueiosPorFuncionario = [];
if ($acertoAtual) {
    foreach ($itens as $item) {
        $bloqueiosPorFuncionario[(int)$item['funcionario_id']] = diasBloqueadosVales($pdo_master, $empresaId, $referencia, (int)$item['funcionario_id'], (int)$acertoAtual['id']);
    }
}

$totais = ['dias' => 0, 'qtd_vt' => 0, 'dias_va' => 0, 'vt' => 0.0, 'va' => 0.0, 'geral' => 0.0];
foreach ($itens as $item) {
    $totais['dias'] += (int)$item['qtd_dias'];
    $totais['qtd_vt'] += (int)($item['qtd_vt'] ?? 0);
    $totais['dias_va'] += (int)($item['qtd_dias_va'] ?? $item['qtd_dias']);
    $totais['vt'] += (float)$item['total_vt'];
    $totais['va'] += (float)$item['total_va'];
    $totais['geral'] += (float)$item['total_geral'];
}

$podeEditar = $acertoAtual && ($acertoAtual['status'] ?? '') === 'ABERTO';

if ($acertoAtual && in_array(($_GET['recibo'] ?? ''), ['empresa', 'colaboradores'], true)) {
    $tipoRecibo = (string)$_GET['recibo'];
    if ($tipoRecibo === 'colaboradores' && !$podeVerDetalhesBeneficios) {
        http_response_code(403);
        exit('Somente usuarios MASTER ou GERENTE podem emitir recibos detalhados por colaborador.');
    }
    imprimirRecibosVales($tipoRecibo, $acertoAtual, $itens, $totais, nomeEmpresaVales($pdo_master, $empresaId));
}

require '../../layout/header.php';
?>

<style>
    .vales-grid { min-width: 1320px; }
    .vales-dia { width: 68px; text-align: center; font-size: .72rem; padding: .25rem !important; }
    .vales-dia input { width: 17px; height: 17px; }
    .vales-turnos { display: grid; grid-template-columns: 1fr 1fr; gap: 2px; justify-items: center; align-items: center; }
    .vales-turnos span { display: block; font-size: .62rem; color: #6b7280; font-weight: 700; }
    .vales-dia-bloqueado { background: #f8d7da !important; color: #842029; }
    .vales-sticky {
        position: sticky;
        left: 0;
        z-index: 2;
        background: #fff;
        box-shadow: 1px 0 0 rgba(0,0,0,.08);
    }
    .vales-param-grid {
        display: grid;
        grid-template-columns: minmax(210px, 1fr) 90px 130px 90px 130px 85px;
        gap: .5rem;
        align-items: center;
    }
    @media (max-width: 991.98px) {
        .vales-param-grid { grid-template-columns: 1fr 1fr; }
    }
</style>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-warning mb-3">Colaboradores</span>
                <h1 class="h3 fw-bold mb-2">Vales Diarios</h1>
                <p class="text-muted mb-0">Controle de vale transporte e vale alimentacao por acertos parciais dentro da competencia.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_colaboradores.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagemOk): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensagemOk) ?></div>
<?php endif; ?>
<?php if ($mensagemErro): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div>
<?php endif; ?>

<section class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-sm-5 col-md-3">
                <label class="form-label">Competencia</label>
                <input type="month" name="referencia" value="<?= htmlspecialchars($referencia) ?>" class="form-control">
            </div>
            <?php if ($acertoAtual): ?>
                <input type="hidden" name="acerto_id" value="<?= (int)$acertoAtual['id'] ?>">
            <?php endif; ?>
            <div class="col-sm-auto">
                <button class="btn btn-primary">Filtrar</button>
            </div>
        </form>
    </div>
</section>

<section class="card shadow-sm mb-4">
    <div class="card-header"><strong>Novo acerto</strong></div>
    <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="acao" value="criar_acerto">
            <input type="hidden" name="referencia" value="<?= htmlspecialchars($referencia) ?>">
            <div class="col-md-2">
                <label class="form-label">Data inicial</label>
                <input type="date" name="data_ini" class="form-control" value="<?= htmlspecialchars($inicioMes) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Data final</label>
                <input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars(date('Y-m-d', min(strtotime($fimMes), strtotime($inicioMes . ' +14 days')))) ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label">Observacao</label>
                <input type="text" name="observacao" class="form-control" placeholder="Ex.: 1a quinzena">
            </div>
            <div class="col-md-3">
                <button class="btn btn-success w-100">Criar acerto</button>
            </div>
        </form>
    </div>
</section>

<section class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="small text-muted">Acerto selecionado</div>
            <div class="h5 fw-bold mb-0"><?= $acertoAtual ? '#' . (int)$acertoAtual['id'] : '-' ?></div>
            <div class="small text-muted"><?= $acertoAtual ? dataVales($acertoAtual['data_ini']) . ' a ' . dataVales($acertoAtual['data_fim']) : '' ?></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="small text-muted">Status</div>
            <span class="badge <?= !$acertoAtual ? 'bg-secondary' : (($acertoAtual['status'] ?? '') === 'PAGO' ? 'bg-success' : (($acertoAtual['status'] ?? '') === 'FECHADO' ? 'bg-primary' : 'bg-warning text-dark')) ?>">
                <?= htmlspecialchars((string)($acertoAtual['status'] ?? 'NAO GERADO')) ?>
            </span>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="small text-muted">Dias com marcacao</div>
            <div class="h4 fw-bold mb-0"><?= number_format($totais['dias'], 0, ',', '.') ?></div>
            <?php if ($podeVerDetalhesBeneficios): ?>
                <div class="small text-muted">VA <?= number_format($totais['dias_va'], 0, ',', '.') ?> dia(s)</div>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="small text-muted">Total do acerto</div>
            <div class="h4 fw-bold mb-0"><?= moedaVales($totais['geral']) ?></div>
            <?php if ($podeVerDetalhesBeneficios): ?>
                <div class="small text-muted">VT <?= number_format($totais['qtd_vt'], 0, ',', '.') ?> un. / <?= moedaVales($totais['vt']) ?> | VA <?= moedaVales($totais['va']) ?></div>
            <?php endif; ?>
        </div></div>
    </div>
</section>

<?php if ($acertoAtual): ?>
    <section class="card shadow-sm mb-4">
        <div class="card-body d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center">
            <div>
                <strong>Recibos do acerto #<?= (int)$acertoAtual['id'] ?></strong>
                <div class="text-muted small">Emita o recibo consolidado da empresa e os recibos individuais dos colaboradores.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a target="_blank" class="btn btn-outline-danger" href="vales_diarios.php?referencia=<?= urlencode($referencia) ?>&acerto_id=<?= (int)$acertoAtual['id'] ?>&recibo=empresa">Recibo empresa</a>
                <?php if ($podeVerDetalhesBeneficios): ?>
                    <a target="_blank" class="btn btn-danger" href="vales_diarios.php?referencia=<?= urlencode($referencia) ?>&acerto_id=<?= (int)$acertoAtual['id'] ?>&recibo=colaboradores">Recibos colaboradores</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="card shadow-sm mb-4">
    <div class="card-header"><strong>Acertos da competencia</strong></div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-bordered align-middle">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Periodo</th>
                    <th>Status</th>
                    <th>Observacao</th>
                    <th>Dias</th>
                    <th>Total</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($acertos as $acerto): ?>
                    <tr class="<?= (int)$acerto['id'] === (int)$acertoId ? 'table-primary' : '' ?>">
                        <td>#<?= (int)$acerto['id'] ?></td>
                        <td><?= dataVales($acerto['data_ini']) ?> a <?= dataVales($acerto['data_fim']) ?></td>
                        <td><?= htmlspecialchars((string)$acerto['status']) ?></td>
                        <td><?= htmlspecialchars((string)($acerto['observacao'] ?? '')) ?></td>
                        <td><?= number_format((int)$acerto['total_dias'], 0, ',', '.') ?></td>
                        <td><?= moedaVales($acerto['total_geral']) ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="vales_diarios.php?referencia=<?= urlencode($referencia) ?>&acerto_id=<?= (int)$acerto['id'] ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$acertos): ?>
                    <tr><td colspan="7" class="text-center text-muted">Nenhum acerto criado nesta competencia.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($podeVerDetalhesBeneficios): ?>
    <section class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Parametros por colaborador</strong>
            <span class="text-muted small">Valores usados ao criar novos acertos</span>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="acao" value="salvar_parametros">
                <input type="hidden" name="referencia" value="<?= htmlspecialchars($referencia) ?>">
                <?php if ($acertoAtual): ?><input type="hidden" name="acerto_id" value="<?= (int)$acertoAtual['id'] ?>"><?php endif; ?>
                <div class="d-none d-lg-grid vales-param-grid fw-bold small text-muted mb-2">
                    <div>Colaborador</div>
                    <div>Recebe VT</div>
                    <div>Valor unit. VT</div>
                    <div>Recebe VA</div>
                    <div>Valor VA</div>
                    <div>Ativo</div>
                </div>
                <?php foreach ($funcionarios as $funcionario): ?>
                    <?php
                        $fid = (int)$funcionario['FUNCCONTADOR'];
                        $param = $parametros[$fid] ?? [];
                        $valorVtUnitario = (float)($param['valor_vt_unitario'] ?? 0);
                        if ($valorVtUnitario <= 0 && (float)($param['valor_vt'] ?? 0) > 0) {
                            $valorVtUnitario = (float)$param['valor_vt'];
                        }
                    ?>
                    <input type="hidden" name="funcionario_id[]" value="<?= $fid ?>">
                    <div class="vales-param-grid border rounded-2 p-2 mb-2">
                        <div class="fw-semibold"><?= htmlspecialchars((string)$funcionario['NOMEFUNC']) ?></div>
                        <label class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="recebe_vt[<?= $fid ?>]" <?= ($param['recebe_vt'] ?? 'N') === 'S' ? 'checked' : '' ?>>
                            <span class="form-check-label">VT</span>
                        </label>
                        <input type="text" name="valor_vt_unitario[<?= $fid ?>]" class="form-control form-control-sm" inputmode="decimal" value="<?= valorInputVales($valorVtUnitario) ?>">
                        <label class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="recebe_va[<?= $fid ?>]" <?= ($param['recebe_va'] ?? 'N') === 'S' ? 'checked' : '' ?>>
                            <span class="form-check-label">VA</span>
                        </label>
                        <input type="text" name="valor_va[<?= $fid ?>]" class="form-control form-control-sm" inputmode="decimal" value="<?= valorInputVales($param['valor_va'] ?? 0) ?>">
                        <label class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="beneficio_ativo[<?= $fid ?>]" <?= ($param['ativo'] ?? 'S') === 'S' ? 'checked' : '' ?>>
                            <span class="form-check-label">Ativo</span>
                        </label>
                    </div>
                <?php endforeach; ?>
                <button class="btn btn-primary">Salvar parametros</button>
            </form>
        </div>
    </section>
<?php endif; ?>

<section class="card shadow-sm">
    <div class="card-header d-flex flex-column flex-lg-row gap-2 justify-content-between align-items-lg-center">
        <strong>Escala do acerto selecionado</strong>
        <?php if ($acertoAtual): ?>
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($podeEditar): ?>
                    <form method="post">
                        <input type="hidden" name="referencia" value="<?= htmlspecialchars($referencia) ?>">
                        <input type="hidden" name="acerto_id" value="<?= (int)$acertoAtual['id'] ?>">
                        <input type="hidden" name="acao" value="atualizar_colaboradores">
                        <button class="btn btn-outline-secondary btn-sm">Atualizar colaboradores</button>
                    </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Confirmar alteracao de status do acerto?');">
                    <input type="hidden" name="referencia" value="<?= htmlspecialchars($referencia) ?>">
                    <input type="hidden" name="acerto_id" value="<?= (int)$acertoAtual['id'] ?>">
                    <input type="hidden" name="acao" value="<?= $podeEditar ? 'fechar' : (($acertoAtual['status'] ?? '') === 'FECHADO' ? 'pagar' : 'reabrir') ?>">
                    <button class="btn btn-outline-primary btn-sm">
                        <?= $podeEditar ? 'Fechar acerto' : (($acertoAtual['status'] ?? '') === 'FECHADO' ? 'Marcar pago' : 'Reabrir') ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (!$acertoAtual): ?>
            <div class="alert alert-info mb-0">Crie ou abra um acerto para marcar os dias escalados.</div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="acao" value="salvar_dias">
                <input type="hidden" name="referencia" value="<?= htmlspecialchars($referencia) ?>">
                <input type="hidden" name="acerto_id" value="<?= (int)$acertoAtual['id'] ?>">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle vales-grid">
                        <thead class="table-dark">
                            <tr>
                                <th class="vales-sticky" style="width:230px;">Colaborador</th>
                                <?php foreach ($periodoDias as $diaInfo): ?>
                                    <th class="vales-dia"><?= htmlspecialchars($diaInfo['label']) ?><br><span class="fw-normal"><?= htmlspecialchars($diaInfo['semana']) ?></span></th>
                                <?php endforeach; ?>
                                <th style="width:70px;">Dias</th>
                                <?php if ($podeVerDetalhesBeneficios): ?>
                                    <th style="width:90px;">VTs</th>
                                    <th style="width:105px;">VT</th>
                                    <th style="width:90px;">Dias VA</th>
                                    <th style="width:105px;">VA</th>
                                <?php endif; ?>
                                <th style="width:115px;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($itens as $item): ?>
                                <?php
                                    $itemId = (int)$item['id'];
                                    $funcionarioId = (int)$item['funcionario_id'];
                                    $escalaItem = escalaVales($item['dias_json']);
                                    $bloqueados = $bloqueiosPorFuncionario[$funcionarioId] ?? [];
                                ?>
                                <tr>
                                    <td class="vales-sticky">
                                        <input type="hidden" name="item_id[]" value="<?= $itemId ?>">
                                        <div class="fw-semibold"><?= htmlspecialchars((string)$item['nome_funcionario']) ?></div>
                                        <div class="small text-muted">ID <?= $funcionarioId ?></div>
                                    </td>
                                    <?php foreach ($periodoDias as $diaInfo): ?>
                                        <?php
                                            $dia = (int)$diaInfo['dia'];
                                            $bloqueado = isset($bloqueados[$dia]);
                                        ?>
                                        <td class="vales-dia <?= $bloqueado ? 'vales-dia-bloqueado' : '' ?>" title="<?= $bloqueado ? 'Ja selecionado no acerto #' . (int)$bloqueados[$dia]['acerto_id'] . ' (' . htmlspecialchars((string)$bloqueados[$dia]['status']) . ')' : '' ?>">
                                            <div class="vales-turnos">
                                                <label>
                                                    <span>I</span>
                                                    <input type="checkbox" name="ida[<?= $itemId ?>][]" value="<?= $dia ?>" <?= !empty($escalaItem[$dia]['ida']) ? 'checked' : '' ?> <?= (!$podeEditar || $bloqueado) ? 'disabled' : '' ?>>
                                                </label>
                                                <label>
                                                    <span>V</span>
                                                    <input type="checkbox" name="volta[<?= $itemId ?>][]" value="<?= $dia ?>" <?= !empty($escalaItem[$dia]['volta']) ? 'checked' : '' ?> <?= (!$podeEditar || $bloqueado) ? 'disabled' : '' ?>>
                                                </label>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-center fw-bold"><?= (int)$item['qtd_dias'] ?></td>
                                    <?php if ($podeVerDetalhesBeneficios): ?>
                                        <td class="text-center fw-bold"><?= (int)($item['qtd_vt'] ?? 0) ?></td>
                                        <td>
                                            <div class="small text-muted">Unit. <?= moedaVales($item['valor_vt']) ?></div>
                                            <div class="fw-semibold"><?= moedaVales($item['total_vt']) ?></div>
                                        </td>
                                        <td class="text-center fw-bold"><?= (int)($item['qtd_dias_va'] ?? $item['qtd_dias']) ?></td>
                                        <td>
                                            <div class="small text-muted">Dia <?= moedaVales($item['valor_va']) ?></div>
                                            <div class="fw-semibold"><?= moedaVales($item['total_va']) ?></div>
                                        </td>
                                    <?php endif; ?>
                                    <td class="fw-bold"><?= moedaVales($item['total_geral']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="<?= count($periodoDias) + 1 ?>" class="text-end">Totais</th>
                                <th><?= number_format($totais['dias'], 0, ',', '.') ?></th>
                                <?php if ($podeVerDetalhesBeneficios): ?>
                                    <th><?= number_format($totais['qtd_vt'], 0, ',', '.') ?></th>
                                    <th><?= moedaVales($totais['vt']) ?></th>
                                    <th><?= number_format($totais['dias_va'], 0, ',', '.') ?></th>
                                    <th><?= moedaVales($totais['va']) ?></th>
                                <?php endif; ?>
                                <th><?= moedaVales($totais['geral']) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php if ($podeEditar): ?>
                    <button class="btn btn-primary"><?= $podeVerDetalhesBeneficios ? 'Salvar escala e valores' : 'Salvar escala' ?></button>
                <?php else: ?>
                    <div class="alert alert-secondary mb-0">Acerto <?= htmlspecialchars(strtolower((string)$acertoAtual['status'])) ?>. Reabra para alterar.</div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
