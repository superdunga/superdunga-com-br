<?php
require '../../config/auth.php';
require '../../config/conexao.php';

$empresa_id = (int)$_SESSION['empresa_id'];
$usuario_id = (int)($_SESSION['usuario_id'] ?? 0);
$isMaster = (($_SESSION['nivel'] ?? '') === 'MASTER');

function garantirTabelaCaixasFinalizados(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fechamento_caixas_finalizados (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            data_operacional DATE NOT NULL,
            cbcontador INT NOT NULL,
            usuario_id INT NULL,
            finalizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_caixa_finalizado (empresa_id, data_operacional, cbcontador),
            INDEX idx_caixa_finalizado_data (empresa_id, data_operacional)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

garantirTabelaCaixasFinalizados($pdo_master);

function moedaCaixaExport(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function dataHoraCaixaExport(?string $valor): string
{
    if (empty($valor)) {
        return '';
    }

    return date('d/m/Y H:i', strtotime($valor));
}

function valorLancamentoCaixaExport(array $movimento): float
{
    if (array_key_exists('VALORMOV', $movimento)) {
        return abs((float)($movimento['VALORMOV'] ?? 0));
    }

    if (($movimento['tipo_operacao'] ?? '') === 'D') {
        $valor = (float)($movimento['valor_entregue'] ?? 0);
        return $valor > 0 ? $valor : abs((float)($movimento['valor_operacao'] ?? 0));
    }

    $valor = (float)($movimento['valor_troco'] ?? 0);
    return $valor > 0 ? $valor : abs((float)($movimento['valor_operacao'] ?? 0));
}

function dataMovimentoCaixaExport(array $movimento): string
{
    return dataHoraCaixaExport($movimento['DTLANC'] ?? $movimento['data_mov'] ?? '');
}

function historicoMovimentoCaixaExport(array $movimento): string
{
    return (string)($movimento['HISTMOV'] ?? $movimento['observacao'] ?? '');
}

function buscarDadosExportacaoCaixa(PDO $pdo, int $empresaId, string $dataOperacional, int $cbcontador): array
{
    $inicio = $dataOperacional . ' 07:00:00';
    $fim = date('Y-m-d 03:00:00', strtotime($dataOperacional . ' +1 day'));

    $stmtOperador = $pdo->prepare("
        SELECT COALESCE(MIN(NULLIF(NOMEUSER, '')), CONCAT('Caixa ', ?)) AS operador
        FROM armazem_zconfig005
        WHERE EMPRESA = ?
          AND CODCX = ?
          AND COALESCE(DESATIVADO, 'N') <> 'S'
    ");
    $stmtOperador->execute([$cbcontador, $empresaId, $cbcontador]);
    $operador = (string)($stmtOperador->fetchColumn() ?: ('Caixa ' . $cbcontador));

    $stmtSaldo = $pdo->prepare("
        SELECT
            COALESCE(SUM(
                CASE
                    WHEN TIPOMOV = 'C' THEN VALORMOV
                    WHEN TIPOMOV = 'D' THEN -VALORMOV
                    ELSE 0
                END
            ), 0) AS diferenca_dinheiro
        FROM armazem_bnc001
        WHERE EMPRESA = ?
          AND CBCONTADOR = ?
          AND DTLANC BETWEEN ? AND ?
          AND COALESCE(deletado, 'N') <> 'S'
    ");
    $stmtSaldo->execute([$empresaId, $cbcontador, $inicio, $fim]);
    $diferencaDinheiro = (float)$stmtSaldo->fetchColumn();
    if (abs($diferencaDinheiro) <= 0.01) {
        $diferencaDinheiro = 0.0;
    }

    $stmtAbertura = $pdo->prepare("
        SELECT MOVCONTADOR, VALORMOV, DTLANC, HISTMOV
        FROM armazem_bnc001
        WHERE EMPRESA = ?
          AND CBCONTADOR = ?
          AND DTLANC BETWEEN ? AND ?
          AND COALESCE(deletado, 'N') <> 'S'
          AND UPPER(TRIM(HISTMOV)) LIKE 'ABERTURA%'
        ORDER BY DTLANC ASC, MOVCONTADOR ASC
        LIMIT 1
    ");
    $stmtAbertura->execute([$empresaId, $cbcontador, $inicio, $fim]);
    $abertura = $stmtAbertura->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtSangrias = $pdo->prepare("
        SELECT MOVCONTADOR, VALORMOV, DTLANC, HISTMOV
        FROM armazem_bnc001
        WHERE EMPRESA = ?
          AND CBCONTADOR = ?
          AND DTLANC BETWEEN ? AND ?
          AND COALESCE(deletado, 'N') <> 'S'
          AND UPPER(TRIM(HISTMOV)) LIKE 'SANGRIA%'
        ORDER BY DTLANC ASC, MOVCONTADOR ASC
    ");
    $stmtSangrias->execute([$empresaId, $cbcontador, $inicio, $fim]);
    $sangrias = $stmtSangrias->fetchAll(PDO::FETCH_ASSOC);

    $stmtFechamento = $pdo->prepare("
        SELECT MOVCONTADOR, VALORMOV, DTLANC, HISTMOV
        FROM armazem_bnc001
        WHERE EMPRESA = ?
          AND CBCONTADOR = ?
          AND DTLANC BETWEEN ? AND ?
          AND COALESCE(deletado, 'N') <> 'S'
          AND UPPER(TRIM(HISTMOV)) LIKE 'FECHAMENTO%'
        ORDER BY DTLANC DESC, MOVCONTADOR DESC
        LIMIT 1
    ");
    $stmtFechamento->execute([$empresaId, $cbcontador, $inicio, $fim]);
    $fechamento = $stmtFechamento->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmtCr = $pdo->prepare("
        SELECT
            c.CRCONTADOR,
            c.NUMDOCORIGEM,
            c.DTLANC,
            c.VLRPARCELA,
            c.CMCONTADOR,
            COALESCE(NULLIF(cli.NOME, ''), NULLIF(cli.APELIDO, ''), '') AS cliente_nome
        FROM armazem_cr001 c
        INNER JOIN armazem_zconfig005 z
            ON z.EMPRESA = c.EMPRESA
           AND z.CODUSER = c.USERLANC
           AND z.CODCX = ?
        LEFT JOIN armazem_cr002 cli
            ON cli.EMPRESA = c.EMPRESA
           AND cli.CLICONTADOR = c.CLICONTADOR
        WHERE c.DTLANC BETWEEN ? AND ?
          AND c.EMPRESA = ?
          AND c.CMCONTADOR <> 9
          AND c.recebimento_id IS NULL
          AND COALESCE(c.STATUS, '') <> 'QT'
          AND COALESCE(c.excluido_firebird, 'N') = 'N'
        ORDER BY c.DTLANC ASC, c.CRCONTADOR ASC
    ");
    $stmtCr->execute([$cbcontador, $inicio, $fim, $empresaId]);
    $crPendentes = $stmtCr->fetchAll(PDO::FETCH_ASSOC);

    $vendasIds = [];
    foreach ($crPendentes as $cr) {
        $vendaId = (int)($cr['NUMDOCORIGEM'] ?? 0);
        if ($vendaId > 0) {
            $vendasIds[$vendaId] = true;
        }
    }

    $itensPorVenda = [];
    if (!empty($vendasIds)) {
        $idsVenda = array_keys($vendasIds);
        $placeholders = implode(',', array_fill(0, count($idsVenda), '?'));
        $stmtItens = $pdo->prepare("
            SELECT
                i.ITEMVENDACONTADOR,
                i.VENDACONTA,
                i.PRODUTO,
                i.QTDE,
                i.VALOR,
                i.TOTPROD,
                p.CODPRODUTO,
                p.DESCPRODUTO,
                p.UNIDADE
            FROM armazem_est008 i
            LEFT JOIN armazem_est004 p
                ON p.EMPRESA = i.EMPRESA
               AND p.CONTAPRODUTO = i.PRODUTO
            WHERE i.EMPRESA = ?
              AND i.ITEMVENDACONTADOR IN ($placeholders)
              AND COALESCE(i.CANCELADO, 'N') <> 'S'
            ORDER BY i.ITEMVENDACONTADOR ASC, i.VENDACONTA ASC
        ");
        $stmtItens->execute(array_merge([$empresaId], $idsVenda));
        while ($item = $stmtItens->fetch(PDO::FETCH_ASSOC)) {
            $itensPorVenda[(int)$item['ITEMVENDACONTADOR']][] = $item;
        }
    }

    $stmtRecebiveis = $pdo->prepare("
        SELECT
            r.id,
            r.data_venda,
            r.valor_bruto,
            r.CMCONTADOR,
            COALESCE(NULLIF(r.pagador, ''), NULLIF(r.origem, ''), 'Recebivel') AS pagador,
            r.descricao,
            r.identificador
        FROM armazem_conciliacao_recebimentos r
        WHERE r.data_venda BETWEEN ? AND ?
          AND r.empresa_id = ?
          AND r.CRCONTADOR IS NULL
          AND NOT EXISTS (
              SELECT 1
              FROM armazem_cr001 c
              WHERE c.recebimento_id = r.id
                AND c.EMPRESA = ?
                AND COALESCE(c.STATUS, '') <> 'QT'
                AND COALESCE(c.excluido_firebird, 'N') = 'N'
          )
        ORDER BY r.data_venda ASC, r.id ASC
    ");
    $stmtRecebiveis->execute([$inicio, $fim, $empresaId, $empresaId]);
    $recebiveisPendentes = $stmtRecebiveis->fetchAll(PDO::FETCH_ASSOC);

    $totalCr = array_sum(array_map(static function ($cr) {
        return (float)($cr['VLRPARCELA'] ?? 0);
    }, $crPendentes));
    $totalRecebiveis = array_sum(array_map(static function ($rec) {
        return (float)($rec['valor_bruto'] ?? 0);
    }, $recebiveisPendentes));

    return [
        'data_operacional' => $dataOperacional,
        'inicio' => $inicio,
        'fim' => $fim,
        'cbcontador' => $cbcontador,
        'operador' => $operador,
        'abertura' => $abertura,
        'sangrias' => $sangrias,
        'fechamento' => $fechamento,
        'diferenca_dinheiro' => $diferencaDinheiro,
        'cr_pendentes' => $crPendentes,
        'itens_por_venda' => $itensPorVenda,
        'recebiveis_pendentes' => $recebiveisPendentes,
        'total_cr' => (float)$totalCr,
        'total_recebiveis' => (float)$totalRecebiveis,
        'diferenca_final' => $diferencaDinheiro + (float)$totalCr - (float)$totalRecebiveis,
    ];
}

function exportarConferenciaCaixaXls(array $dados): void
{
    $nomeArquivo = 'conferencia_caixa_' . $dados['data_operacional'] . '_caixa_' . $dados['cbcontador'] . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo "\xEF\xBB\xBF";
    ?>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #999; padding: 5px; vertical-align: top; }
            th { background: #17336f; color: #fff; }
            .titulo { background: #d9e6ff; font-weight: bold; font-size: 16px; }
            .subtitulo { background: #e9ecef; font-weight: bold; }
            .numero { text-align: right; }
        </style>
    </head>
    <body>
        <table>
            <tr><td colspan="6" class="titulo">Conferencia do Caixa</td></tr>
            <tr><td>Data do caixa</td><td colspan="5"><?= date('d/m/Y', strtotime($dados['data_operacional'])) ?></td></tr>
            <tr><td>Operador</td><td colspan="5"><?= htmlspecialchars($dados['operador']) ?></td></tr>
            <tr><td>Caixa</td><td colspan="5"><?= (int)$dados['cbcontador'] ?></td></tr>
            <tr><td>Periodo</td><td colspan="5"><?= dataHoraCaixaExport($dados['inicio']) ?> ate <?= dataHoraCaixaExport($dados['fim']) ?></td></tr>
            <tr><td>Valor de Abertura</td><td colspan="5" class="numero"><?= moedaCaixaExport((float)($dados['abertura']['VALORMOV'] ?? 0)) ?></td></tr>
            <?php foreach (($dados['sangrias'] ?? []) as $sangria): ?>
                <tr>
                    <td>Sangria</td>
                    <td colspan="4"><?= htmlspecialchars(dataMovimentoCaixaExport($sangria) . ' - ' . historicoMovimentoCaixaExport($sangria)) ?></td>
                    <td class="numero"><?= moedaCaixaExport(valorLancamentoCaixaExport($sangria)) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr><td>Valor de Fechamento</td><td colspan="5" class="numero"><?= moedaCaixaExport((float)($dados['fechamento']['VALORMOV'] ?? 0)) ?></td></tr>
            <tr><td>Diferenca no dinheiro</td><td colspan="5" class="numero"><?= moedaCaixaExport((float)$dados['diferenca_dinheiro']) ?></td></tr>
            <tr><td>Passou no caixa e nao recebeu (CR001 PEND)</td><td colspan="5" class="numero"><?= count($dados['cr_pendentes']) ?> | <?= moedaCaixaExport((float)$dados['total_cr']) ?></td></tr>
            <tr><td>Recebeu e nao passou no caixa (RECEBIVEIS PEN)</td><td colspan="5" class="numero"><?= count($dados['recebiveis_pendentes']) ?> | <?= moedaCaixaExport((float)$dados['total_recebiveis']) ?></td></tr>
            <tr><td>DIF. FINAL</td><td colspan="5" class="numero"><?= moedaCaixaExport((float)$dados['diferenca_final']) ?></td></tr>
        </table>

        <br>
        <table>
            <tr><td colspan="6" class="subtitulo">Passou no caixa e nao recebeu (CR001 PEND)</td></tr>
            <tr>
                <th>Venda</th>
                <th>CR001</th>
                <th>Data/Hora venda</th>
                <th>CM</th>
                <th>Cliente</th>
                <th>Valor venda</th>
            </tr>
            <?php if (empty($dados['cr_pendentes'])): ?>
                <tr><td colspan="6">Nenhum registro</td></tr>
            <?php endif; ?>
            <?php foreach ($dados['cr_pendentes'] as $cr): ?>
                <?php $vendaId = (int)($cr['NUMDOCORIGEM'] ?? 0); ?>
                <tr>
                    <td><?= $vendaId ?: '' ?></td>
                    <td><?= (int)$cr['CRCONTADOR'] ?></td>
                    <td><?= dataHoraCaixaExport($cr['DTLANC'] ?? '') ?></td>
                    <td><?= htmlspecialchars((string)($cr['CMCONTADOR'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string)($cr['cliente_nome'] ?? '')) ?></td>
                    <td class="numero"><?= moedaCaixaExport((float)($cr['VLRPARCELA'] ?? 0)) ?></td>
                </tr>
                <tr>
                    <td></td>
                    <td colspan="5">
                        <table>
                            <tr>
                                <th>Item</th>
                                <th>Produto</th>
                                <th>Descricao</th>
                                <th>Qtde</th>
                                <th>Unitario</th>
                                <th>Total</th>
                            </tr>
                            <?php $itens = $dados['itens_por_venda'][$vendaId] ?? []; ?>
                            <?php if (empty($itens)): ?>
                                <tr><td colspan="6">Nenhum item encontrado para esta venda.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($itens as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)($item['VENDACONTA'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($item['CODPRODUTO'] ?? $item['PRODUTO'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($item['DESCPRODUTO'] ?? '')) ?></td>
                                    <td class="numero"><?= number_format((float)($item['QTDE'] ?? 0), 3, ',', '.') ?> <?= htmlspecialchars((string)($item['UNIDADE'] ?? '')) ?></td>
                                    <td class="numero"><?= moedaCaixaExport((float)($item['VALOR'] ?? 0)) ?></td>
                                    <td class="numero"><?= moedaCaixaExport((float)($item['TOTPROD'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <br>
        <table>
            <tr><td colspan="5" class="subtitulo">Recebeu e nao passou no caixa (RECEBIVEIS PEN)</td></tr>
            <tr>
                <th>ID</th>
                <th>Data/Hora</th>
                <th>CM</th>
                <th>Pagador</th>
                <th>Valor</th>
            </tr>
            <?php if (empty($dados['recebiveis_pendentes'])): ?>
                <tr><td colspan="5">Nenhum registro</td></tr>
            <?php endif; ?>
            <?php foreach ($dados['recebiveis_pendentes'] as $recebivel): ?>
                <tr>
                    <td><?= (int)$recebivel['id'] ?></td>
                    <td><?= dataHoraCaixaExport($recebivel['data_venda'] ?? '') ?></td>
                    <td><?= htmlspecialchars((string)($recebivel['CMCONTADOR'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string)($recebivel['pagador'] ?? '')) ?></td>
                    <td class="numero"><?= moedaCaixaExport((float)($recebivel['valor_bruto'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </body>
    </html>
    <?php
    exit;
}

function textoPdfCaixa($valor, int $limite = 0): string
{
    $texto = preg_replace('/\s+/', ' ', trim((string)$valor));
    if ($limite > 0) {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($texto, 'UTF-8') > $limite) {
                $texto = mb_substr($texto, 0, max(0, $limite - 3), 'UTF-8') . '...';
            }
        } elseif (strlen($texto) > $limite) {
            $texto = substr($texto, 0, max(0, $limite - 3)) . '...';
        }
    }

    $convertido = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto);
    return $convertido === false ? $texto : $convertido;
}

function escaparPdfCaixa(string $texto): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

function comandoTextoPdfCaixa(float $x, float $y, int $tamanho, string $texto, bool $negrito = false): string
{
    $fonte = $negrito ? 'F2' : 'F1';
    return "BT /{$fonte} {$tamanho} Tf 1 0 0 1 " . number_format($x, 2, '.', '') . ' ' . number_format($y, 2, '.', '') . ' Tm (' . escaparPdfCaixa($texto) . ") Tj ET\n";
}

function exportarConferenciaCaixaPdf(array $dados): void
{
    $largura = 595;
    $altura = 842;
    $margem = 34;
    $yInicial = $altura - 32;
    $yMinimo = 34;
    $paginas = [];
    $conteudo = '';
    $y = $yInicial;
    $larguraUtil = $largura - ($margem * 2);

    $novaPagina = static function () use (&$paginas, &$conteudo, &$y, $yInicial): void {
        if ($conteudo !== '') {
            $paginas[] = $conteudo;
        }
        $conteudo = '';
        $y = $yInicial;
    };

    $garantirEspaco = static function (int $alturaNecessaria = 20) use (&$y, $yMinimo, $novaPagina): void {
        if (($y - $alturaNecessaria) < $yMinimo) {
            $novaPagina();
        }
    };

    $linha = static function (string $texto = '', bool $negrito = false, int $tamanho = 9, int $recuo = 0) use (&$conteudo, &$y, $margem, $yMinimo, $novaPagina): void {
        if ($y < $yMinimo) {
            $novaPagina();
        }
        $conteudo .= comandoTextoPdfCaixa($margem + $recuo, $y, $tamanho, textoPdfCaixa($texto, 120), $negrito);
        $y -= ($tamanho >= 12 ? 17 : 13);
    };

    $secao = static function (string $titulo) use (&$conteudo, &$y, $margem, $larguraUtil, $garantirEspaco): void {
        $garantirEspaco(28);
        $conteudo .= "0.91 0.94 0.98 rg {$margem} " . ($y - 4) . " {$larguraUtil} 18 re f\n0 g\n";
        $conteudo .= comandoTextoPdfCaixa($margem + 8, $y + 1, 9, textoPdfCaixa($titulo, 80), true);
        $y -= 24;
    };

    $campo = static function (string $rotulo, string $valor, int $recuo = 0) use (&$conteudo, &$y, $margem, $linha): void {
        $conteudo .= comandoTextoPdfCaixa($margem + $recuo, $y, 8, textoPdfCaixa($rotulo, 36), true);
        $conteudo .= comandoTextoPdfCaixa($margem + $recuo + 150, $y, 8, textoPdfCaixa($valor, 70));
        $y -= 13;
    };

    $aberturaValor = valorLancamentoCaixaExport($dados['abertura']);
    $fechamentoValor = valorLancamentoCaixaExport($dados['fechamento']);
    $aberturaInfo = !empty($dados['abertura'])
        ? dataMovimentoCaixaExport($dados['abertura']) . ' - ' . historicoMovimentoCaixaExport($dados['abertura'])
        : 'Nao localizado no periodo';
    $fechamentoInfo = !empty($dados['fechamento'])
        ? dataMovimentoCaixaExport($dados['fechamento']) . ' - ' . historicoMovimentoCaixaExport($dados['fechamento'])
        : 'Nao localizado no periodo';

    $conteudo .= "0.06 0.18 0.42 rg 0 " . ($altura - 86) . " {$largura} 86 re f\n1 g\n";
    $conteudo .= comandoTextoPdfCaixa($margem, $altura - 35, 16, textoPdfCaixa('CONFERENCIA DETALHADA DO CAIXA'), true);
    $conteudo .= comandoTextoPdfCaixa($margem, $altura - 56, 9, textoPdfCaixa('Data do caixa: ' . date('d/m/Y', strtotime($dados['data_operacional'])) . '   |   Caixa: ' . (int)$dados['cbcontador'] . '   |   Operador: ' . $dados['operador']));
    $conteudo .= comandoTextoPdfCaixa($margem, $altura - 72, 8, textoPdfCaixa('Periodo operacional: ' . dataHoraCaixaExport($dados['inicio']) . ' ate ' . dataHoraCaixaExport($dados['fim'])));
    $conteudo .= "0 g\n";
    $y = $altura - 112;

    $secao('RESUMO DO CAIXA');
    $campo('Valor de Abertura', moedaCaixaExport($aberturaValor));
    $campo('Lancamento da Abertura', $aberturaInfo);
    if (!empty($dados['sangrias'])) {
        foreach ($dados['sangrias'] as $indiceSangria => $sangria) {
            $sangriaInfo = dataMovimentoCaixaExport($sangria) .
                ' - ' . moedaCaixaExport(valorLancamentoCaixaExport($sangria)) .
                ' - ' . historicoMovimentoCaixaExport($sangria);
            $campo('Sangria ' . ($indiceSangria + 1), $sangriaInfo);
        }
    } else {
        $campo('Sangrias', 'Nenhum registro no periodo');
    }
    $campo('Valor de Fechamento', moedaCaixaExport($fechamentoValor));
    $campo('Lancamento do Fechamento', $fechamentoInfo);
    $campo('Diferenca no dinheiro', moedaCaixaExport((float)$dados['diferenca_dinheiro']));
    $campo('CR001 PEND.', count($dados['cr_pendentes']) . ' registro(s) | ' . moedaCaixaExport((float)$dados['total_cr']));
    $campo('RECEBIVEIS PEN.', count($dados['recebiveis_pendentes']) . ' registro(s) | ' . moedaCaixaExport((float)$dados['total_recebiveis']));
    $campo('DIF. FINAL', moedaCaixaExport((float)$dados['diferenca_final']));
    $y -= 6;

    $secao('PASSOU NO CAIXA E NAO RECEBEU (CR001 PEND)');
    if (empty($dados['cr_pendentes'])) {
        $linha('Nenhum registro.');
    }
    foreach ($dados['cr_pendentes'] as $cr) {
        $vendaId = (int)($cr['NUMDOCORIGEM'] ?? 0);
        $garantirEspaco(90);
        $conteudo .= "0.97 0.97 0.97 rg {$margem} " . ($y - 6) . " {$larguraUtil} 20 re f\n0 g\n";
        $linha(
            'CUPOM VENDA ' . ($vendaId ?: '-') .
            '  |  CR001 ' . (int)$cr['CRCONTADOR'] .
            '  |  ' . dataHoraCaixaExport($cr['DTLANC'] ?? '') .
            '  |  ' . moedaCaixaExport((float)($cr['VLRPARCELA'] ?? 0)),
            true,
            9,
            8
        );
        $linha('Cliente: ' . (string)($cr['cliente_nome'] ?? '') . '  |  CM: ' . (string)($cr['CMCONTADOR'] ?? ''), false, 8, 8);

        $itens = $dados['itens_por_venda'][$vendaId] ?? [];
        if (empty($itens)) {
            $linha('Itens: nenhum item encontrado para esta venda.', false, 8, 14);
        } else {
            $linha('Codigo      Descricao                                      Qtde       Total', true, 8, 14);
            foreach ($itens as $item) {
                $produto = (string)($item['CODPRODUTO'] ?? $item['PRODUTO'] ?? '');
                $descricao = (string)($item['DESCPRODUTO'] ?? '');
                $qtde = number_format((float)($item['QTDE'] ?? 0), 3, ',', '.');
                $total = moedaCaixaExport((float)($item['TOTPROD'] ?? 0));
                $linha(str_pad($produto, 11) . ' ' . $descricao . ' | Qtde: ' . $qtde . ' | Total: ' . $total, false, 8, 14);
            }
        }
        $linha();
    }

    $secao('RECEBEU E NAO PASSOU NO CAIXA (RECEBIVEIS PEN)');
    if (empty($dados['recebiveis_pendentes'])) {
        $linha('Nenhum registro.');
    }
    foreach ($dados['recebiveis_pendentes'] as $recebivel) {
        $garantirEspaco(42);
        $conteudo .= "0.98 0.98 0.98 rg {$margem} " . ($y - 6) . " {$larguraUtil} 18 re f\n0 g\n";
        $linha(
            'ID: ' . (int)$recebivel['id'] .
            '  |  ' . dataHoraCaixaExport($recebivel['data_venda'] ?? '') .
            '  |  CM ' . (string)($recebivel['CMCONTADOR'] ?? '') .
            '  |  ' . moedaCaixaExport((float)($recebivel['valor_bruto'] ?? 0)),
            true,
            9,
            8
        );
        $linha('Pagador: ' . (string)($recebivel['pagador'] ?? ''), false, 8, 14);
        $linha();
    }

    if ($conteudo !== '') {
        $paginas[] = $conteudo;
    }

    $totalPaginas = max(1, count($paginas));
    foreach ($paginas as $indice => $paginaConteudo) {
        $paginas[$indice] = $paginaConteudo . comandoTextoPdfCaixa($margem, 18, 8, textoPdfCaixa('Gerado em ' . date('d/m/Y H:i') . ' - Pagina ' . ($indice + 1) . ' de ' . $totalPaginas));
    }

    $objetos = [
        1 => "<< /Type /Catalog /Pages 2 0 R >>",
        2 => '',
        3 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
        4 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>",
    ];
    $idsPaginas = [];
    $proximoId = 5;

    foreach ($paginas as $paginaConteudo) {
        $conteudoId = $proximoId++;
        $paginaId = $proximoId++;
        $objetos[$conteudoId] = "<< /Length " . strlen($paginaConteudo) . " >>\nstream\n{$paginaConteudo}endstream";
        $objetos[$paginaId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$largura} {$altura}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$conteudoId} 0 R >>";
        $idsPaginas[] = $paginaId;
    }

    $objetos[2] = "<< /Type /Pages /Kids [" . implode(' ', array_map(static function ($id) {
        return "{$id} 0 R";
    }, $idsPaginas)) . "] /Count " . count($idsPaginas) . " >>";
    ksort($objetos);

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objetos as $id => $objeto) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$objeto}\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objetos) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objetos); $i++) {
        $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objetos) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

    $nomeArquivo = 'conferencia_caixa_' . $dados['data_operacional'] . '_caixa_' . $dados['cbcontador'] . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if (($_GET['exportar'] ?? '') === 'caixa') {
    $dataExport = (string)($_GET['data'] ?? '');
    $caixaExport = (int)($_GET['caixa'] ?? 0);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataExport) || $caixaExport <= 0) {
        http_response_code(400);
        exit('Parametros invalidos para exportacao.');
    }

    exportarConferenciaCaixaPdf(buscarDadosExportacaoCaixa($pdo_master, $empresa_id, $dataExport, $caixaExport));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $dataPost = $_POST['data_operacional'] ?? '';
    $caixaPost = (int)($_POST['cbcontador'] ?? 0);

    if (in_array($acao, ['finalizar_caixa', 'reabrir_caixa', 'finalizar_marcados'], true) && !$isMaster) {
        http_response_code(403);
        exit('Acesso negado.');
    }

    if ($acao === 'finalizar_marcados') {
        $marcados = $_POST['caixas'] ?? [];
        if (!is_array($marcados)) {
            $marcados = [];
        }

        $stmtFinalizarMarcado = $pdo_master->prepare("
            INSERT INTO fechamento_caixas_finalizados
                (empresa_id, data_operacional, cbcontador, usuario_id, finalizado_em)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                usuario_id = VALUES(usuario_id),
                finalizado_em = NOW()
        ");

        foreach ($marcados as $marcado) {
            [$dataMarcada, $caixaMarcado] = array_pad(explode('|', (string)$marcado, 2), 2, '');
            $caixaMarcado = (int)$caixaMarcado;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataMarcada) || $caixaMarcado <= 0 || $dataMarcada === date('Y-m-d')) {
                continue;
            }

            $stmtFinalizarMarcado->execute([$empresa_id, $dataMarcada, $caixaMarcado, $usuario_id ?: null]);
        }
    }

    if ($dataPost !== '' && $caixaPost > 0) {
        if ($acao === 'finalizar_caixa') {
            $stmtFinalizar = $pdo_master->prepare("
                INSERT INTO fechamento_caixas_finalizados
                    (empresa_id, data_operacional, cbcontador, usuario_id, finalizado_em)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    usuario_id = VALUES(usuario_id),
                    finalizado_em = NOW()
            ");
            $stmtFinalizar->execute([$empresa_id, $dataPost, $caixaPost, $usuario_id ?: null]);
        } elseif ($acao === 'reabrir_caixa') {
            $stmtReabrir = $pdo_master->prepare("
                DELETE FROM fechamento_caixas_finalizados
                WHERE empresa_id = ?
                  AND data_operacional = ?
                  AND cbcontador = ?
            ");
            $stmtReabrir->execute([$empresa_id, $dataPost, $caixaPost]);
        }
    }

    $queryRedirect = http_build_query([
        'mes' => $_POST['mes'] ?? date('Y-m'),
        'finalizado' => $_POST['finalizado'] ?? 'todos',
    ]);
    header('Location: conciliacao_dinheiro.php?' . $queryRedirect);
    exit;
}

$mes = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $mes = date('Y-m');
}

$filtroFinalizado = $_GET['finalizado'] ?? 'todos';
if (!in_array($filtroFinalizado, ['todos', 'finalizado', 'nao_finalizado'], true)) {
    $filtroFinalizado = 'todos';
}

$inicio = $mes . '-01 07:00:00';
$fim = date('Y-m-d 03:00:00', strtotime($mes . '-01 +1 month'));
if ($mes === date('Y-m')) {
    $fim = date('Y-m-d H:i:s');
}

$whereFinalizado = '';
if ($filtroFinalizado === 'finalizado') {
    $whereFinalizado = ' AND f.id IS NOT NULL';
} elseif ($filtroFinalizado === 'nao_finalizado') {
    $whereFinalizado = ' AND f.id IS NULL';
}

$sql = "
SELECT
    DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)) AS data_operacional,
    b.CBCONTADOR,
    SUM(
        CASE
            WHEN b.TIPOMOV = 'C' THEN b.VALORMOV
            WHEN b.TIPOMOV = 'D' THEN -b.VALORMOV
            ELSE 0
        END
    ) AS saldo_final,
    f.finalizado_em
FROM armazem_bnc001 b
INNER JOIN (
    SELECT DISTINCT CODCX
    FROM armazem_zconfig005
    WHERE CODCX IS NOT NULL
      AND EMPRESA = ?
) z ON z.CODCX = b.CBCONTADOR
LEFT JOIN fechamento_caixas_finalizados f
    ON f.empresa_id = b.EMPRESA
   AND f.data_operacional = DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR))
   AND f.cbcontador = b.CBCONTADOR
WHERE b.DTLANC BETWEEN ? AND ?
  AND b.EMPRESA = ?
  AND COALESCE(b.deletado, 'N') <> 'S'
  $whereFinalizado
GROUP BY
    DATE(DATE_SUB(b.DTLANC, INTERVAL 7 HOUR)),
    b.CBCONTADOR,
    f.finalizado_em
ORDER BY
    data_operacional DESC,
    b.CBCONTADOR
";

$stmt = $pdo_master->prepare($sql);
$stmt->execute([$empresa_id, $inicio, $fim, $empresa_id]);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmtPrazoCaixa = $pdo_master->prepare("
    SELECT
        DATE(DATE_SUB(c.DTLANC, INTERVAL 7 HOUR)) AS data_operacional,
        z.CODCX AS cbcontador,
        COUNT(*) AS qtd_cr_pendente,
        COALESCE(SUM(c.VLRPARCELA), 0) AS total_cr_pendente
    FROM armazem_cr001 c
    INNER JOIN armazem_zconfig005 z
        ON z.EMPRESA = c.EMPRESA
       AND z.CODUSER = c.USERLANC
       AND z.CODCX IS NOT NULL
    WHERE c.DTLANC BETWEEN ? AND ?
      AND c.EMPRESA = ?
      AND c.CMCONTADOR <> 9
      AND c.recebimento_id IS NULL
      AND COALESCE(c.STATUS, '') <> 'QT'
      AND COALESCE(c.excluido_firebird, 'N') = 'N'
    GROUP BY DATE(DATE_SUB(c.DTLANC, INTERVAL 7 HOUR)), z.CODCX
");
$stmtPrazoCaixa->execute([$inicio, $fim, $empresa_id]);
$prazoPorCaixa = [];
foreach ($stmtPrazoCaixa->fetchAll(PDO::FETCH_ASSOC) as $prazo) {
    $chavePrazo = $prazo['data_operacional'] . '|' . $prazo['cbcontador'];
    $prazoPorCaixa[$chavePrazo] = [
        'qtd' => (int)$prazo['qtd_cr_pendente'],
        'total' => (float)$prazo['total_cr_pendente'],
    ];
}

$stmtRecebiveisSemCaixa = $pdo_master->prepare("
    SELECT
        DATE(DATE_SUB(r.data_venda, INTERVAL 7 HOUR)) AS data_operacional,
        COUNT(*) AS qtd_recebivel_pendente,
        COALESCE(SUM(r.valor_bruto), 0) AS total_recebivel_pendente
    FROM armazem_conciliacao_recebimentos r
    WHERE r.data_venda BETWEEN ? AND ?
      AND r.empresa_id = ?
      AND r.CRCONTADOR IS NULL
      AND NOT EXISTS (
          SELECT 1
          FROM armazem_cr001 c
          WHERE c.recebimento_id = r.id
            AND c.EMPRESA = ?
            AND COALESCE(c.STATUS, '') <> 'QT'
            AND COALESCE(c.excluido_firebird, 'N') = 'N'
      )
    GROUP BY DATE(DATE_SUB(r.data_venda, INTERVAL 7 HOUR))
");
$stmtRecebiveisSemCaixa->execute([$inicio, $fim, $empresa_id, $empresa_id]);
$recebiveisSemCaixaPorDia = [];
foreach ($stmtRecebiveisSemCaixa->fetchAll(PDO::FETCH_ASSOC) as $recebivelPendente) {
    $recebiveisSemCaixaPorDia[$recebivelPendente['data_operacional']] = [
        'qtd' => (int)$recebivelPendente['qtd_recebivel_pendente'],
        'total' => (float)$recebivelPendente['total_recebivel_pendente'],
    ];
}

require '../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Conciliacao de Dinheiro (Caixas validos)</h5>

        <div class="d-flex gap-2 flex-wrap">
            <a href="menu_fechamento.php" class="btn btn-secondary">Voltar</a>
            <a href="resumo_prazo.php?mes=<?= urlencode($mes) ?>" class="btn btn-outline-primary">Resumo a Prazo</a>

            <form method="GET" class="d-flex gap-2">
                <input type="month" name="mes" value="<?= htmlspecialchars($mes) ?>" class="form-control me-2">
                <select name="finalizado" class="form-select">
                    <option value="todos" <?= $filtroFinalizado === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="nao_finalizado" <?= $filtroFinalizado === 'nao_finalizado' ? 'selected' : '' ?>>Nao finalizados</option>
                    <option value="finalizado" <?= $filtroFinalizado === 'finalizado' ? 'selected' : '' ?>>Finalizados</option>
                </select>
                <button class="btn btn-primary">Filtrar</button>
            </form>
        </div>
    </div>

    <div class="card-body table-responsive">
        <?php if ($isMaster && count($resultados) > 0): ?>
            <form method="POST" id="form-finalizar-marcados" class="d-flex gap-2 align-items-center mb-3">
                <input type="hidden" name="acao" value="finalizar_marcados">
                <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                <input type="hidden" name="finalizado" value="<?= htmlspecialchars($filtroFinalizado) ?>">
                <button class="btn btn-success btn-sm" onclick="return confirm('Finalizar todos os caixas marcados?')">Finalizar marcados</button>
                <span class="text-muted small">Marque os caixas desejados na tabela.</span>
            </form>
        <?php endif; ?>

        <table class="table table-sm table-bordered text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <?php if ($isMaster): ?>
                        <th style="width: 42px;">
                            <input type="checkbox" id="marcar-todos-caixas" title="Marcar todos">
                        </th>
                    <?php endif; ?>
                    <th>Data</th>
                    <th>Caixa</th>
                    <th>Dif. Dinheiro</th>
                    <th>CR001 pend.</th>
                    <th>Recebiveis pend.</th>
                    <th>Dif. Final</th>
                    <th>Status</th>
                    <th>Acao</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($resultados) === 0): ?>
                    <tr>
                        <td colspan="<?= $isMaster ? 9 : 8 ?>" class="text-muted">Nenhum registro encontrado</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($resultados as $r): ?>
                        <?php
                            $saldo = (float)$r['saldo_final'];
                            $dataOp = $r['data_operacional'];
                            $hoje = date('Y-m-d');
                            $finalizado = !empty($r['finalizado_em']);
                            $chavePrazo = $dataOp . '|' . $r['CBCONTADOR'];
                            $prazoCaixa = $prazoPorCaixa[$chavePrazo] ?? ['qtd' => 0, 'total' => 0.0];
                            $recebiveisDia = $recebiveisSemCaixaPorDia[$dataOp] ?? ['qtd' => 0, 'total' => 0.0];
                            $saldoCalculado = abs($saldo) <= 0.01 ? 0.0 : $saldo;
                            $cr001Pendente = (float)$prazoCaixa['total'];
                            $diferencaRecebiveis = (float)$recebiveisDia['total'];
                            $diferencaFinal = $saldoCalculado + $cr001Pendente - $diferencaRecebiveis;

                            if ($finalizado) {
                                $status = 'FINALIZADO';
                                $classe = 'success';
                            } elseif ($dataOp === $hoje) {
                                $status = 'EM ABERTO';
                                $classe = 'warning';
                            } elseif (abs($saldoCalculado) <= 0.01 && abs($cr001Pendente) <= 0.01 && abs($diferencaRecebiveis) <= 0.01) {
                                $status = 'OK';
                                $classe = 'success';
                            } else {
                                $status = 'DIVERGENTE';
                                $classe = 'danger';
                            }
                        ?>
                        <tr>
                            <?php if ($isMaster): ?>
                                <td>
                                    <?php if (!$finalizado && $dataOp !== $hoje): ?>
                                        <input
                                            type="checkbox"
                                            class="form-check-input caixa-finalizar-check"
                                            name="caixas[]"
                                            value="<?= htmlspecialchars($dataOp . '|' . (string)$r['CBCONTADOR']) ?>"
                                            form="form-finalizar-marcados"
                                        >
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><?= date('d/m/Y', strtotime($dataOp)) ?></td>
                            <td><?= htmlspecialchars((string)$r['CBCONTADOR']) ?></td>
                            <td class="fw-bold text-<?= $classe ?>">
                                R$ <?= number_format($saldo, 2, ',', '.') ?>
                            </td>
                            <td>
                                <?= (int)$prazoCaixa['qtd'] ?> |
                                R$ <?= number_format($cr001Pendente, 2, ',', '.') ?>
                            </td>
                            <td class="fw-bold <?= abs($diferencaRecebiveis) <= 0.01 ? 'text-success' : 'text-danger' ?>">
                                <?= (int)$recebiveisDia['qtd'] ?> |
                                R$ <?= number_format($diferencaRecebiveis, 2, ',', '.') ?>
                            </td>
                            <td class="fw-bold <?= abs($diferencaFinal) <= 0.01 ? 'text-success' : 'text-danger' ?>">
                                R$ <?= number_format($diferencaFinal, 2, ',', '.') ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $classe ?>"><?= $status ?></span>
                            </td>
                            <td>
                                <div class="d-flex justify-content-center gap-1">
                                    <a href="extrato_caixa.php?data=<?= urlencode($dataOp) ?>&caixa=<?= urlencode((string)$r['CBCONTADOR']) ?>" class="btn btn-sm btn-outline-dark">
                                        Ver
                                    </a>
                                    <a
                                        href="conciliacao_dinheiro.php?exportar=caixa&data=<?= urlencode($dataOp) ?>&caixa=<?= urlencode((string)$r['CBCONTADOR']) ?>"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        Exportar PDF
                                    </a>

                                    <?php if ($isMaster && $finalizado): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="acao" value="reabrir_caixa">
                                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                                            <input type="hidden" name="finalizado" value="<?= htmlspecialchars($filtroFinalizado) ?>">
                                            <input type="hidden" name="data_operacional" value="<?= htmlspecialchars($dataOp) ?>">
                                            <input type="hidden" name="cbcontador" value="<?= (int)$r['CBCONTADOR'] ?>">
                                            <button class="btn btn-sm btn-outline-secondary" onclick="return confirm('Reabrir este caixa?')">Reabrir</button>
                                        </form>
                                    <?php elseif ($isMaster && $dataOp !== $hoje): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="acao" value="finalizar_caixa">
                                            <input type="hidden" name="mes" value="<?= htmlspecialchars($mes) ?>">
                                            <input type="hidden" name="finalizado" value="<?= htmlspecialchars($filtroFinalizado) ?>">
                                            <input type="hidden" name="data_operacional" value="<?= htmlspecialchars($dataOp) ?>">
                                            <input type="hidden" name="cbcontador" value="<?= (int)$r['CBCONTADOR'] ?>">
                                            <button class="btn btn-sm btn-success" onclick="return confirm('Marcar este caixa como finalizado?')">Finalizar</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
            $diasComRecebiveisSemCaixa = array_filter($recebiveisSemCaixaPorDia, static function (array $item): bool {
                return (int)$item['qtd'] > 0;
            });
        ?>
        <?php if (!empty($diasComRecebiveisSemCaixa)): ?>
            <div class="alert alert-warning mt-3 mb-0">
                <strong>Recebiveis sem CR001 nao separados por caixa:</strong>
                <?php foreach ($diasComRecebiveisSemCaixa as $dataPendencia => $pendencia): ?>
                    <span class="d-inline-block me-3">
                        <?= date('d/m/Y', strtotime($dataPendencia)) ?>:
                        <?= (int)$pendencia['qtd'] ?> |
                        R$ <?= number_format((float)$pendencia['total'], 2, ',', '.') ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var marcarTodos = document.getElementById('marcar-todos-caixas');
    if (!marcarTodos) {
        return;
    }

    marcarTodos.addEventListener('change', function () {
        document.querySelectorAll('.caixa-finalizar-check').forEach(function (checkbox) {
            checkbox.checked = marcarTodos.checked;
        });
    });
});
</script>

<?php require '../../layout/footer.php'; ?>
