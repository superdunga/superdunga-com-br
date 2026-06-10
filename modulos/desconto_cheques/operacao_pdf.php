<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

garantirTabelasDescontoCheques($pdo_master);

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$operacaoId = (int)($_GET['id'] ?? 0);

$stmtOperacao = $pdo_master->prepare("
    SELECT
        o.*,
        c.nome AS cliente_nome,
        c.celular,
        c.taxa_desconto,
        c.usa_adicional_prazo,
        c.limite_credito
    FROM desconto_cheques_operacoes o
    INNER JOIN desconto_cheques_clientes c ON c.id = o.cliente_id
    WHERE o.id = ?
      AND o.empresa_id = ?
    LIMIT 1
");
$stmtOperacao->execute([$operacaoId, $empresaId]);
$operacao = $stmtOperacao->fetch(PDO::FETCH_ASSOC);

if (!$operacao) {
    http_response_code(404);
    echo 'Operacao nao encontrada.';
    exit;
}

$stmtDocs = $pdo_master->prepare("
    SELECT *
    FROM desconto_cheques_documentos
    WHERE operacao_id = ?
    ORDER BY data_vencimento, id
");
$stmtDocs->execute([$operacaoId]);
$documentos = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

$valorLiquidoTitulos = 0.0;
foreach ($documentos as $documento) {
    $valorLiquidoTitulos += (float)$documento['valor_liquido'];
}

$nomeArquivo = 'desconto_cheques_operacao_' . $operacaoId . '.pdf';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Operacao de Desconto #<?= (int)$operacaoId ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #111827;
            margin: 0;
            background: #e5e7eb;
        }

        .page {
            width: 190mm;
            min-height: 277mm;
            margin: 12mm auto;
            background: #fff;
            box-shadow: 0 8px 30px rgba(15, 23, 42, .16);
        }

        .topo {
            background: #163272;
            color: #fff;
            padding: 18mm 16mm 10mm;
        }

        .topo h1 {
            margin: 0 0 5mm;
            font-size: 21px;
            letter-spacing: .3px;
        }

        .topo .meta {
            font-size: 12px;
            line-height: 1.55;
        }

        .content {
            padding: 12mm 16mm 16mm;
        }

        .section-title {
            background: #e8eef8;
            color: #0f2d68;
            font-weight: bold;
            padding: 7px 9px;
            margin: 0 0 8px;
            font-size: 13px;
            text-transform: uppercase;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            margin-bottom: 14px;
        }

        .box {
            border: 1px solid #d9e2ef;
            padding: 9px;
            border-radius: 4px;
        }

        .box .label {
            font-size: 10px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 3px;
        }

        .box .value {
            font-size: 14px;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 11px;
        }

        th, td {
            border: 1px solid #d9e2ef;
            padding: 6px;
            vertical-align: top;
        }

        th {
            background: #163272;
            color: #fff;
            text-align: left;
        }

        .text-end {
            text-align: right;
        }

        .total-row td {
            font-weight: bold;
            background: #f8fafc;
        }

        .final {
            border: 2px solid #163272;
            padding: 11px;
            text-align: right;
            font-size: 17px;
            font-weight: bold;
            color: #0f2d68;
        }

        .no-print {
            text-align: center;
            margin: 14px 0;
        }

        .no-print button {
            background: #163272;
            color: #fff;
            border: 0;
            border-radius: 4px;
            padding: 10px 16px;
            cursor: pointer;
        }

        @media print {
            body {
                background: #fff;
            }

            .page {
                margin: 0;
                width: auto;
                min-height: auto;
                box-shadow: none;
            }

            .no-print {
                display: none;
            }

            @page {
                size: A4;
                margin: 0;
            }
        }
    </style>
</head>
<body>
<div class="no-print">
    <button onclick="window.print()">Salvar em PDF</button>
</div>

<main class="page">
    <header class="topo">
        <h1>OPERACAO DE DESCONTO DE CHEQUES #<?= (int)$operacaoId ?></h1>
        <div class="meta">
            Cliente: <?= htmlspecialchars($operacao['cliente_nome']) ?><br>
            Data de referencia: <?= dataBRDC($operacao['data_referencia']) ?> | Status: <?= htmlspecialchars($operacao['status']) ?><br>
            Taxa mensal cadastrada: <?= percentualDC($operacao['taxa_desconto']) ?> | Adicional de prazo: <?= $operacao['usa_adicional_prazo'] === 'S' ? 'Sim' : 'Nao' ?>
        </div>
    </header>

    <section class="content">
        <div class="section-title">Resumo da operacao</div>
        <div class="grid">
            <div class="box"><div class="label">Valor bruto</div><div class="value"><?= moedaDC($operacao['valor_bruto']) ?></div></div>
            <div class="box"><div class="label">Desconto dos titulos</div><div class="value"><?= moedaDC($operacao['valor_desconto']) ?></div></div>
            <div class="box"><div class="label">Liquido dos titulos</div><div class="value"><?= moedaDC($valorLiquidoTitulos) ?></div></div>
            <?php if ((float)$operacao['valor_taxas_tarifas'] > 0): ?>
                <div class="box"><div class="label">Taxas/tarifas</div><div class="value"><?= moedaDC($operacao['valor_taxas_tarifas']) ?></div></div>
            <?php endif; ?>
            <?php if ((float)$operacao['valor_descontar'] > 0): ?>
                <div class="box"><div class="label">Valores a descontar</div><div class="value"><?= moedaDC($operacao['valor_descontar']) ?></div></div>
            <?php endif; ?>
        </div>

        <div class="section-title">Titulos</div>
        <table>
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Vencimento</th>
                    <th>Compensacao</th>
                    <th class="text-end">Dias</th>
                    <th class="text-end">Valor</th>
                    <th class="text-end">Taxa total</th>
                    <th class="text-end">Desconto</th>
                    <th class="text-end">Liquido</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($documentos as $doc): ?>
                    <tr>
                        <td><?= htmlspecialchars($doc['tipo_documento']) ?> <?= htmlspecialchars((string)$doc['numero_documento']) ?></td>
                        <td><?= dataBRDC($doc['data_vencimento']) ?></td>
                        <td><?= dataBRDC($doc['data_compensacao']) ?></td>
                        <td class="text-end"><?= (int)$doc['prazo_dias'] ?></td>
                        <td class="text-end"><?= moedaDC($doc['valor']) ?></td>
                        <td class="text-end"><?= percentualDC(taxaTotalDocumentoDC($doc)) ?></td>
                        <td class="text-end"><?= moedaDC($doc['desconto_valor']) ?></td>
                        <td class="text-end"><?= moedaDC($doc['valor_liquido']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="4">Total</td>
                    <td class="text-end"><?= moedaDC($operacao['valor_bruto']) ?></td>
                    <td></td>
                    <td class="text-end"><?= moedaDC($operacao['valor_desconto']) ?></td>
                    <td class="text-end"><?= moedaDC($valorLiquidoTitulos) ?></td>
                </tr>
            </tbody>
        </table>

        <?php if ((float)$operacao['valor_taxas_tarifas'] > 0 || (float)$operacao['valor_descontar'] > 0): ?>
            <div class="section-title">Ajustes da operacao</div>
            <table>
                <tbody>
                    <?php if ((float)$operacao['valor_taxas_tarifas'] > 0): ?>
                        <tr>
                            <td>Taxas/tarifas</td>
                            <td><?= htmlspecialchars((string)$operacao['historico_taxas_tarifas']) ?></td>
                            <td class="text-end"><?= moedaDC($operacao['valor_taxas_tarifas']) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ((float)$operacao['valor_descontar'] > 0): ?>
                        <tr>
                            <td>Valores a descontar</td>
                            <td><?= htmlspecialchars((string)$operacao['historico_descontar']) ?></td>
                            <td class="text-end"><?= moedaDC($operacao['valor_descontar']) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="final">Valor liquido da operacao: <?= moedaDC($operacao['valor_liquido']) ?></div>
    </section>
</main>

<script>
document.title = <?= json_encode($nomeArquivo) ?>;
</script>
</body>
</html>

