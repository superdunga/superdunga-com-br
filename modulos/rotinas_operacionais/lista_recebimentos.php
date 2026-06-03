<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$mensagemOk = '';
$mensagemErro = '';

function garantirEstruturaListaRecebimentos(PDO $pdo): void
{
    static $executado = false;
    if ($executado) {
        return;
    }
    $executado = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recebimento_mercadorias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            usuario_id INT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'em_andamento',
            selfie_arquivo VARCHAR(255) NULL,
            id_firebird INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finalizado_em DATETIME NULL,
            INDEX idx_receb_merc_empresa_status (empresa_id, status, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmtColuna = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'recebimento_mercadorias'
          AND COLUMN_NAME = 'id_firebird'
    ");
    $stmtColuna->execute();
    if ((int)$stmtColuna->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE recebimento_mercadorias ADD COLUMN id_firebird INT NULL AFTER selfie_arquivo");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recebimento_mercadorias_itens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recebimento_id INT NOT NULL,
            ordem INT NOT NULL,
            codigo_barras VARCHAR(120) NOT NULL,
            codproduto VARCHAR(50) NULL,
            descproduto VARCHAR(255) NULL,
            produto_encontrado CHAR(1) NOT NULL DEFAULT 'N',
            quantidade_por_caixa DECIMAL(15,4) NOT NULL DEFAULT 0,
            quantidade_caixas DECIMAL(15,4) NOT NULL DEFAULT 0,
            quantidade_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            foto_produto VARCHAR(255) NULL,
            descricao_informada VARCHAR(255) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pendente',
            enviado_firebird CHAR(1) NOT NULL DEFAULT 'N',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_receb_merc_item_recebimento (recebimento_id, ordem),
            INDEX idx_receb_merc_item_codigo (codigo_barras)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function formatarQtdListaRecebimento($valor): string
{
    $numero = (float)$valor;
    $texto = number_format($numero, 4, ',', '.');
    return rtrim(rtrim($texto, '0'), ',');
}

function formatarTextoExcelListaRecebimento($valor): string
{
    $texto = trim((string)$valor);
    if ($texto === '') {
        return '';
    }

    return '="' . str_replace('"', '""', $texto) . '"';
}

function buscarItensListaRecebimento(PDO $pdo, int $recebimentoId): array
{
    $stmt = $pdo->prepare("
        SELECT codproduto, codigo_barras, descproduto, produto_encontrado,
               quantidade_por_caixa, quantidade_caixas, quantidade_total
        FROM recebimento_mercadorias_itens
        WHERE recebimento_id = ?
        ORDER BY ordem, id
    ");
    $stmt->execute([$recebimentoId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function exportarCsvListaRecebimento(int $recebimentoId, array $itens): void
{
    $nomeArquivo = 'recebimento_mercadorias_' . $recebimentoId . '_' . date('Ymd_His') . '.csv';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');

    echo "sep=;\n";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, [
        'CODIGO',
        'CODIGO DE BARRAS',
        'DESCRICAO',
        'QTD POR CAIXA',
        'QTD RECEBIDA',
        'QTD TOTAL',
    ], ';');

    foreach ($itens as $item) {
        $produtoEncontrado = ($item['produto_encontrado'] ?? 'N') === 'S';

        fputcsv($saida, [
            $produtoEncontrado ? formatarTextoExcelListaRecebimento($item['codproduto'] ?? '') : '',
            formatarTextoExcelListaRecebimento($item['codigo_barras'] ?? ''),
            $produtoEncontrado ? (string)($item['descproduto'] ?? '') : 'CADASTRAR PRODUTO',
            formatarQtdListaRecebimento($item['quantidade_por_caixa'] ?? 0),
            formatarQtdListaRecebimento($item['quantidade_caixas'] ?? 0),
            formatarQtdListaRecebimento($item['quantidade_total'] ?? 0),
        ], ';');
    }

    fclose($saida);
    exit;
}

garantirEstruturaListaRecebimentos($pdo_master);

if (($_GET['exportar'] ?? '') === 'csv') {
    $recebimentoIdExportar = (int)($_GET['id'] ?? 0);
    $stmtExportar = $pdo_master->prepare("
        SELECT id
        FROM recebimento_mercadorias
        WHERE id = ?
          AND empresa_id = ?
          AND status = 'finalizado'
        LIMIT 1
    ");
    $stmtExportar->execute([$recebimentoIdExportar, $empresaId]);

    if ($stmtExportar->fetchColumn()) {
        exportarCsvListaRecebimento($recebimentoIdExportar, buscarItensListaRecebimento($pdo_master, $recebimentoIdExportar));
    }

    header('Location: lista_recebimentos.php?erro=csv');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_firebird') {
    $recebimentoIdPost = (int)($_POST['recebimento_id'] ?? 0);
    $idFirebirdTexto = trim((string)($_POST['id_firebird'] ?? ''));

    if ($idFirebirdTexto === '') {
        header('Location: lista_recebimentos.php?erro=firebird_vazio');
        exit;
    }

    if ($idFirebirdTexto !== '' && !ctype_digit($idFirebirdTexto)) {
        header('Location: lista_recebimentos.php?erro=firebird_invalido');
        exit;
    }

    $idFirebird = (int)$idFirebirdTexto;

    $stmtAtualizar = $pdo_master->prepare("
        UPDATE recebimento_mercadorias
        SET id_firebird = ?
        WHERE id = ?
          AND empresa_id = ?
          AND status = 'finalizado'
    ");
    $stmtAtualizar->execute([$idFirebird, $recebimentoIdPost, $empresaId]);

    header('Location: lista_recebimentos.php?ok=firebird');
    exit;
}

if (($_GET['ok'] ?? '') === 'firebird') {
    $mensagemOk = 'ID do recebimento no Firebird salvo.';
}

if (($_GET['erro'] ?? '') === 'firebird_invalido') {
    $mensagemErro = 'Informe um ID do Firebird numerico.';
} elseif (($_GET['erro'] ?? '') === 'firebird_vazio') {
    $mensagemErro = 'Informe o ID do recebimento no Firebird antes de gravar.';
} elseif (($_GET['erro'] ?? '') === 'csv') {
    $mensagemErro = 'Nao foi possivel gerar o CSV deste recebimento.';
}

$stmtRecebimentos = $pdo_master->prepare("
    SELECT r.id,
           r.criado_em,
           r.finalizado_em,
           r.id_firebird,
           u.nome AS usuario_nome,
           COUNT(i.id) AS total_itens,
           COALESCE(SUM(i.quantidade_total), 0) AS total_quantidade
    FROM recebimento_mercadorias r
    LEFT JOIN usuarios u ON u.id = r.usuario_id
    LEFT JOIN recebimento_mercadorias_itens i ON i.recebimento_id = r.id
    WHERE r.empresa_id = ?
      AND r.status = 'finalizado'
    GROUP BY r.id, r.criado_em, r.finalizado_em, r.id_firebird, u.nome
    ORDER BY r.finalizado_em DESC, r.id DESC
");
$stmtRecebimentos->execute([$empresaId]);
$recebimentos = $stmtRecebimentos->fetchAll(PDO::FETCH_ASSOC);

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 bg-white border rounded-2 shadow-sm">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div>
                <span class="badge text-bg-primary mb-2">Rotinas Operacionais</span>
                <h1 class="h4 fw-bold mb-1">Lista dos Recebimentos</h1>
                <p class="text-muted mb-0">Recebimentos finalizados, CSV para entrada e controle do ID gerado no Firebird.</p>
            </div>
            <div class="d-flex align-items-start gap-2">
                <a href="menu_rotinas_operacionais.php" class="btn btn-outline-secondary">Voltar</a>
                <a href="recebimento_mercadorias.php" class="btn btn-success">Novo recebimento</a>
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

<section class="bg-white border rounded-2 shadow-sm overflow-hidden">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Finalizado em</th>
                    <th>Usuario</th>
                    <th class="text-end">Itens</th>
                    <th class="text-end">Qtd total</th>
                    <th style="min-width: 260px;">ID recebimento Firebird</th>
                    <th class="text-end">CSV</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recebimentos as $recebimento): ?>
                    <tr>
                        <td class="fw-semibold">#<?= (int)$recebimento['id'] ?></td>
                        <td><?= $recebimento['finalizado_em'] ? date('d/m/Y H:i', strtotime($recebimento['finalizado_em'])) : '-' ?></td>
                        <td><?= htmlspecialchars($recebimento['usuario_nome'] ?? '-') ?></td>
                        <td class="text-end"><?= (int)$recebimento['total_itens'] ?></td>
                        <td class="text-end"><?= htmlspecialchars(formatarQtdListaRecebimento($recebimento['total_quantidade'] ?? 0)) ?></td>
                        <td>
                            <form method="POST" class="d-flex gap-2">
                                <input type="hidden" name="acao" value="salvar_firebird">
                                <input type="hidden" name="recebimento_id" value="<?= (int)$recebimento['id'] ?>">
                                <input
                                    type="number"
                                    name="id_firebird"
                                    class="form-control form-control-sm"
                                    min="0"
                                    step="1"
                                    inputmode="numeric"
                                    value="<?= htmlspecialchars((string)($recebimento['id_firebird'] ?? '')) ?>"
                                    placeholder="ID no Firebird"
                                    required
                                >
                                <button type="submit" class="btn btn-sm btn-primary">Gravar</button>
                            </form>
                        </td>
                        <td class="text-end">
                            <a
                                href="lista_recebimentos.php?id=<?= (int)$recebimento['id'] ?>&exportar=csv"
                                class="btn btn-sm btn-outline-success"
                            >Baixar CSV</a>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($recebimentos)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Nenhum recebimento finalizado encontrado.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
