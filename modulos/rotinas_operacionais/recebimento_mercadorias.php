<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$mensagemOk = '';
$mensagemErro = '';

function garantirTabelasRecebimentoMercadorias(PDO $pdo): void
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
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finalizado_em DATETIME NULL,
            INDEX idx_receb_merc_empresa_status (empresa_id, status, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recebimento_mercadorias_documentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recebimento_id INT NOT NULL,
            arquivo VARCHAR(255) NOT NULL,
            nome_original VARCHAR(255) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_receb_merc_doc_recebimento (recebimento_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

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

function numeroDecimalRecebimento(string $valor): float
{
    $valor = trim(str_replace(',', '.', $valor));
    if ($valor === '' || !is_numeric($valor)) {
        return 0.0;
    }
    return round((float)$valor, 4);
}

function formatarQtdRecebimento($valor): string
{
    $numero = (float)$valor;
    $texto = number_format($numero, 4, ',', '.');
    return rtrim(rtrim($texto, '0'), ',');
}

function formatarTextoExcelRecebimento($valor): string
{
    $texto = trim((string)$valor);
    if ($texto === '') {
        return '';
    }

    return '="' . str_replace('"', '""', $texto) . '"';
}

function pastaUploadRecebimento(): string
{
    $pasta = __DIR__ . '/../../uploads/recebimento_mercadorias';
    if (!is_dir($pasta)) {
        mkdir($pasta, 0775, true);
    }
    return $pasta;
}

function salvarImagemRecebimento(array $arquivo, string $prefixo): ?string
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha ao receber a imagem enviada.');
    }

    $tmp = (string)$arquivo['tmp_name'];
    $mime = mime_content_type($tmp) ?: '';
    $extensoes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensoes[$mime])) {
        throw new RuntimeException('Envie apenas imagens do documento, produto ou comprovante de finalizacao.');
    }

    $nome = $prefixo . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extensoes[$mime];
    $destino = pastaUploadRecebimento() . '/' . $nome;

    if (!move_uploaded_file($tmp, $destino)) {
        throw new RuntimeException('Nao foi possivel salvar a imagem enviada.');
    }

    return 'uploads/recebimento_mercadorias/' . $nome;
}

function salvarMultiplosDocumentos(PDO $pdo, int $recebimentoId, array $arquivos): int
{
    $total = 0;
    $nomes = $arquivos['name'] ?? [];
    foreach ($nomes as $i => $nomeOriginal) {
        $arquivo = [
            'name' => $nomeOriginal,
            'type' => $arquivos['type'][$i] ?? '',
            'tmp_name' => $arquivos['tmp_name'][$i] ?? '',
            'error' => $arquivos['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $arquivos['size'][$i] ?? 0,
        ];

        $caminho = salvarImagemRecebimento($arquivo, 'documento_' . $recebimentoId);
        if ($caminho === null) {
            continue;
        }

        $stmt = $pdo->prepare("
            INSERT INTO recebimento_mercadorias_documentos (recebimento_id, arquivo, nome_original)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$recebimentoId, $caminho, mb_substr((string)$nomeOriginal, 0, 255)]);
        $total++;
    }

    return $total;
}

function buscarProdutoRecebimento(PDO $pdo, int $empresaId, string $codigo): ?array
{
    $codigo = trim($codigo);
    if ($codigo === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT CODPRODUTO, DESCPRODUTO, UNIDADE, EMB_QTDE, REFERENCIA, REFERENCIA2, REFERENCIA4
        FROM armazem_est004
        WHERE EMPRESA = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
          AND (
              CODPRODUTO = ?
              OR REFERENCIA = ?
              OR REFERENCIA2 = ?
              OR REFERENCIA4 = ?
          )
        ORDER BY
            CASE WHEN CODPRODUTO = ? THEN 0 ELSE 1 END,
            DESCPRODUTO
        LIMIT 1
    ");
    $stmt->execute([$empresaId, $codigo, $codigo, $codigo, $codigo, $codigo]);
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);

    return $produto ?: null;
}

function obterRecebimento(PDO $pdo, int $empresaId, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM recebimento_mercadorias
        WHERE id = ?
          AND empresa_id = ?
        LIMIT 1
    ");
    $stmt->execute([$id, $empresaId]);
    $recebimento = $stmt->fetch(PDO::FETCH_ASSOC);

    return $recebimento ?: null;
}

function contarDocumentos(PDO $pdo, int $recebimentoId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM recebimento_mercadorias_documentos WHERE recebimento_id = ?");
    $stmt->execute([$recebimentoId]);
    return (int)$stmt->fetchColumn();
}

function contarItens(PDO $pdo, int $recebimentoId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM recebimento_mercadorias_itens WHERE recebimento_id = ?");
    $stmt->execute([$recebimentoId]);
    return (int)$stmt->fetchColumn();
}

function exportarCsvRecebimentoMercadorias(int $recebimentoId, array $itens): void
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
            $produtoEncontrado ? formatarTextoExcelRecebimento($item['codproduto'] ?? '') : '',
            formatarTextoExcelRecebimento($item['codigo_barras'] ?? ''),
            $produtoEncontrado ? (string)($item['descproduto'] ?? '') : 'CADASTRAR PRODUTO',
            formatarQtdRecebimento($item['quantidade_por_caixa'] ?? 0),
            formatarQtdRecebimento($item['quantidade_caixas'] ?? 0),
            formatarQtdRecebimento($item['quantidade_total'] ?? 0),
        ], ';');
    }

    fclose($saida);
    exit;
}

garantirTabelasRecebimentoMercadorias($pdo_master);

if (($_GET['ajax'] ?? '') === 'produto') {
    header('Content-Type: application/json; charset=utf-8');
    $codigoAjax = trim((string)($_GET['codigo'] ?? ''));
    $produtoAjax = buscarProdutoRecebimento($pdo_master, $empresaId, $codigoAjax);
    echo json_encode([
        'encontrado' => $produtoAjax !== null,
        'produto' => $produtoAjax,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $recebimentoIdPost = (int)($_POST['recebimento_id'] ?? 0);

    try {
        if ($acao === 'novo') {
            $stmt = $pdo_master->prepare("
                INSERT INTO recebimento_mercadorias (empresa_id, usuario_id, status)
                VALUES (?, ?, 'em_andamento')
            ");
            $stmt->execute([$empresaId, $usuarioId]);
            header('Location: recebimento_mercadorias.php?id=' . (int)$pdo_master->lastInsertId());
            exit;
        }

        $recebimentoPost = obterRecebimento($pdo_master, $empresaId, $recebimentoIdPost);
        if (!$recebimentoPost) {
            throw new RuntimeException('Recebimento nao encontrado.');
        }
        if (($recebimentoPost['status'] ?? '') === 'finalizado') {
            throw new RuntimeException('Este recebimento ja foi finalizado.');
        }

        if ($acao === 'documentos') {
            $totalDocs = isset($_FILES['documentos']) ? salvarMultiplosDocumentos($pdo_master, $recebimentoIdPost, $_FILES['documentos']) : 0;
            if ($totalDocs <= 0) {
                throw new RuntimeException('Envie ao menos uma foto do documento.');
            }
            header('Location: recebimento_mercadorias.php?id=' . $recebimentoIdPost . '&ok=documentos');
            exit;
        }

        if ($acao === 'item') {
            $codigo = trim((string)($_POST['codigo_barras'] ?? ''));
            $qtdPorCaixa = numeroDecimalRecebimento((string)($_POST['quantidade_por_caixa'] ?? '0'));
            $qtdCaixas = numeroDecimalRecebimento((string)($_POST['quantidade_caixas'] ?? '0'));
            $descricaoInformada = trim((string)($_POST['descricao_informada'] ?? ''));

            if ($codigo === '') {
                throw new RuntimeException('Informe ou leia o codigo de barras.');
            }
            if ($qtdPorCaixa <= 0 || $qtdCaixas <= 0) {
                throw new RuntimeException('Informe quantidade por caixa e quantidade de caixas recebidas.');
            }

            $produto = buscarProdutoRecebimento($pdo_master, $empresaId, $codigo);
            $fotoProduto = null;
            if ($produto === null) {
                $fotoProduto = isset($_FILES['foto_produto']) ? salvarImagemRecebimento($_FILES['foto_produto'], 'produto_' . $recebimentoIdPost) : null;
                if ($fotoProduto === null) {
                    throw new RuntimeException('Produto nao cadastrado. Tire uma foto do produto antes de adicionar.');
                }
            }

            $ordem = contarItens($pdo_master, $recebimentoIdPost) + 1;
            $quantidadeTotal = round($qtdPorCaixa * $qtdCaixas, 4);
            $stmt = $pdo_master->prepare("
                INSERT INTO recebimento_mercadorias_itens (
                    recebimento_id, ordem, codigo_barras, codproduto, descproduto, produto_encontrado,
                    quantidade_por_caixa, quantidade_caixas, quantidade_total, foto_produto,
                    descricao_informada, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $recebimentoIdPost,
                $ordem,
                $codigo,
                $produto['CODPRODUTO'] ?? null,
                $produto['DESCPRODUTO'] ?? null,
                $produto ? 'S' : 'N',
                $qtdPorCaixa,
                $qtdCaixas,
                $quantidadeTotal,
                $fotoProduto,
                $descricaoInformada !== '' ? mb_substr($descricaoInformada, 0, 255) : null,
                $produto ? 'pendente' : 'produto_nao_cadastrado',
            ]);

            header('Location: recebimento_mercadorias.php?id=' . $recebimentoIdPost . '&ok=item');
            exit;
        }

        if ($acao === 'finalizar') {
            if (contarDocumentos($pdo_master, $recebimentoIdPost) <= 0) {
                throw new RuntimeException('Inclua ao menos uma foto do documento antes de finalizar.');
            }
            if (contarItens($pdo_master, $recebimentoIdPost) <= 0) {
                throw new RuntimeException('Inclua ao menos um item antes de finalizar.');
            }

            $comprovanteFinalizacao = null;
            if (isset($_FILES['selfie'])) {
                $comprovanteFinalizacao = salvarImagemRecebimento($_FILES['selfie'], 'selfie_' . $recebimentoIdPost);
            }
            if ($comprovanteFinalizacao === null && isset($_FILES['canhoto_assinado'])) {
                $comprovanteFinalizacao = salvarImagemRecebimento($_FILES['canhoto_assinado'], 'canhoto_' . $recebimentoIdPost);
            }
            if ($comprovanteFinalizacao === null) {
                throw new RuntimeException('Envie a selfie de quem recebeu ou a foto do canhoto/documento assinado.');
            }

            $stmt = $pdo_master->prepare("
                UPDATE recebimento_mercadorias
                SET status = 'finalizado',
                    selfie_arquivo = ?,
                    finalizado_em = NOW()
                WHERE id = ?
                  AND empresa_id = ?
                  AND status <> 'finalizado'
            ");
            $stmt->execute([$comprovanteFinalizacao, $recebimentoIdPost, $empresaId]);

            header('Location: recebimento_mercadorias.php?id=' . $recebimentoIdPost . '&ok=finalizado');
            exit;
        }
    } catch (Throwable $e) {
        $mensagemErro = $e->getMessage();
    }
}

$recebimentoId = (int)($_GET['id'] ?? 0);
$recebimento = $recebimentoId > 0 ? obterRecebimento($pdo_master, $empresaId, $recebimentoId) : null;
$usuarioMasterRecebimento = ($_SESSION['nivel'] ?? '') === 'MASTER';

if (($_GET['ok'] ?? '') === 'documentos') {
    $mensagemOk = 'Foto(s) do documento salva(s).';
} elseif (($_GET['ok'] ?? '') === 'item') {
    $mensagemOk = 'Item adicionado ao recebimento.';
} elseif (($_GET['ok'] ?? '') === 'finalizado') {
    $mensagemOk = 'Recebimento finalizado com comprovante de quem recebeu.';
}
if (($_GET['erro'] ?? '') === 'csv_pendente') {
    $mensagemErro = 'A exportacao CSV fica disponivel somente apos finalizar o recebimento.';
}

$documentos = [];
$itens = [];
$recebimentosRecentes = [];

if ($recebimento) {
    $stmtDocs = $pdo_master->prepare("
        SELECT *
        FROM recebimento_mercadorias_documentos
        WHERE recebimento_id = ?
        ORDER BY id
    ");
    $stmtDocs->execute([$recebimentoId]);
    $documentos = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

    $stmtItens = $pdo_master->prepare("
        SELECT *
        FROM recebimento_mercadorias_itens
        WHERE recebimento_id = ?
        ORDER BY ordem, id
    ");
    $stmtItens->execute([$recebimentoId]);
    $itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

    if (($_GET['exportar'] ?? '') === 'csv') {
        if (($recebimento['status'] ?? '') !== 'finalizado') {
            header('Location: recebimento_mercadorias.php?id=' . $recebimentoId . '&erro=csv_pendente');
            exit;
        }
        exportarCsvRecebimentoMercadorias($recebimentoId, $itens);
    }

    if (($recebimento['status'] ?? '') === 'finalizado' && !$usuarioMasterRecebimento) {
        renderizarAcessoNegadoModulo('Somente usuario MASTER pode abrir recebimentos de mercadorias finalizados.');
    }
} else {
    $stmtRecentes = $pdo_master->prepare("
        SELECT r.*,
               COUNT(DISTINCT d.id) AS total_documentos,
               COUNT(DISTINCT i.id) AS total_itens
        FROM recebimento_mercadorias r
        LEFT JOIN recebimento_mercadorias_documentos d ON d.recebimento_id = r.id
        LEFT JOIN recebimento_mercadorias_itens i ON i.recebimento_id = r.id
        WHERE r.empresa_id = ?
        GROUP BY r.id
        ORDER BY r.criado_em DESC
        LIMIT 20
    ");
    $stmtRecentes->execute([$empresaId]);
    $recebimentosRecentes = $stmtRecentes->fetchAll(PDO::FETCH_ASSOC);
}

require '../../layout/header.php';
?>

<style>
    .recebimento-shell { max-width: 980px; margin: 0 auto; }
    .mobile-step { border-left: 4px solid #198754; }
    .scanner-box {
        display: none;
        background: #111827;
        border-radius: .5rem;
        overflow: hidden;
    }
    .scanner-box video {
        width: 100%;
        min-height: 240px;
        object-fit: cover;
    }
    .item-card {
        border: 1px solid #d9e2ef;
        border-radius: .5rem;
        background: #fff;
    }
    .produto-alerta-nao-cadastrado {
        background: #fff7ed;
        border: 2px solid #f97316;
        border-radius: .75rem;
        padding: 1rem;
    }
    .produto-alerta-nao-cadastrado .alerta-icone {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #f97316;
        color: #fff;
        font-weight: 800;
        flex: 0 0 auto;
    }
    .item-order {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        background: #164194;
        color: #fff;
        flex: 0 0 auto;
    }
    .thumb-doc {
        width: 74px;
        height: 74px;
        object-fit: cover;
        border-radius: .35rem;
        border: 1px solid #d9e2ef;
    }
    @media (max-width: 576px) {
        .recebimento-shell { margin: 0 -.25rem; }
        .card-body { padding: 1rem; }
        .btn-lg-mobile { padding: .9rem 1rem; font-size: 1.05rem; }
    }
</style>

<div class="recebimento-shell">
    <section class="mb-4">
        <div class="p-4 bg-white border rounded-2 shadow-sm">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                <div>
                    <span class="badge text-bg-success mb-2">Rotinas Operacionais</span>
                    <h1 class="h4 fw-bold mb-1">Recebimento de Mercadorias</h1>
                    <p class="text-muted mb-0">Fotografe o documento, leia os produtos e finalize com a identificacao de quem recebeu.</p>
                </div>
                <div class="d-flex gap-2 align-items-start">
                    <a href="menu_rotinas_operacionais.php" class="btn btn-outline-secondary">Voltar</a>
                    <?php if ($recebimento && ($recebimento['status'] ?? '') === 'finalizado'): ?>
                        <a href="recebimento_mercadorias.php?id=<?= (int)$recebimento['id'] ?>&exportar=csv" class="btn btn-outline-success">Exportar CSV</a>
                    <?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="acao" value="novo">
                        <button type="submit" class="btn btn-success">Novo recebimento</button>
                    </form>
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

    <?php if (!$recebimento): ?>
        <section class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3">Recebimentos recentes</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-primary">
                            <tr>
                                <th>ID</th>
                                <th>Inicio</th>
                                <th>Status</th>
                                <th>Docs</th>
                                <th>Itens</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recebimentosRecentes as $recente): ?>
                                <tr>
                                    <td>#<?= (int)$recente['id'] ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($recente['criado_em'])) ?></td>
                                    <td>
                                        <span class="badge <?= $recente['status'] === 'finalizado' ? 'text-bg-success' : 'text-bg-warning' ?>">
                                            <?= htmlspecialchars($recente['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= (int)$recente['total_documentos'] ?></td>
                                    <td><?= (int)$recente['total_itens'] ?></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                            <?php if (($recente['status'] ?? '') !== 'finalizado' || $usuarioMasterRecebimento): ?>
                                                <a href="recebimento_mercadorias.php?id=<?= (int)$recente['id'] ?>" class="btn btn-sm btn-primary">Abrir</a>
                                            <?php endif; ?>
                                            <?php if (($recente['status'] ?? '') === 'finalizado'): ?>
                                                <a href="recebimento_mercadorias.php?id=<?= (int)$recente['id'] ?>&exportar=csv" class="btn btn-sm btn-outline-success">CSV</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recebimentosRecentes)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">Nenhum recebimento registrado ainda.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php else: ?>
        <?php $finalizado = ($recebimento['status'] ?? '') === 'finalizado'; ?>

        <section class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
                    <div>
                        <h2 class="h5 fw-bold mb-1">Recebimento #<?= (int)$recebimento['id'] ?></h2>
                        <div class="text-muted small">Iniciado em <?= date('d/m/Y H:i', strtotime($recebimento['criado_em'])) ?></div>
                    </div>
                    <div>
                        <span class="badge <?= $finalizado ? 'text-bg-success' : 'text-bg-warning' ?> fs-6">
                            <?= $finalizado ? 'Finalizado' : 'Em andamento' ?>
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <section class="card shadow-sm mb-3 mobile-step">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3">1. Foto do documento</h2>
                <?php if (!$finalizado): ?>
                    <form method="POST" enctype="multipart/form-data" class="mb-3">
                        <input type="hidden" name="acao" value="documentos">
                        <input type="hidden" name="recebimento_id" value="<?= (int)$recebimento['id'] ?>">
                        <input type="file" name="documentos[]" accept="image/*" capture="environment" multiple class="form-control mb-2" required>
                        <button type="submit" class="btn btn-success btn-lg-mobile w-100">Salvar foto(s) do documento</button>
                    </form>
                <?php endif; ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($documentos as $doc): ?>
                        <a href="../../<?= htmlspecialchars($doc['arquivo']) ?>" target="_blank">
                            <img src="../../<?= htmlspecialchars($doc['arquivo']) ?>" class="thumb-doc" alt="Documento">
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($documentos)): ?>
                        <div class="text-muted small">Nenhuma foto de documento salva.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="card shadow-sm mb-3 mobile-step">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3">2. Itens recebidos</h2>
                <?php if (!$finalizado): ?>
                    <div class="scanner-box mb-3" id="scannerBox">
                        <video id="scannerVideo" playsinline muted></video>
                    </div>
                    <div class="d-flex gap-2 mb-3">
                        <button type="button" class="btn btn-outline-primary flex-fill" id="btnScanner">Ler com camera</button>
                        <button type="button" class="btn btn-outline-secondary" id="btnPararScanner">Parar</button>
                    </div>
                    <form method="POST" enctype="multipart/form-data" id="formItem" class="row g-3 mb-4">
                        <input type="hidden" name="acao" value="item">
                        <input type="hidden" name="recebimento_id" value="<?= (int)$recebimento['id'] ?>">
                        <div class="col-12">
                            <label class="form-label">Codigo de barras / QR Code</label>
                            <input type="text" name="codigo_barras" id="codigoBarras" class="form-control form-control-lg" inputmode="numeric" autocomplete="off" required>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-light border mb-0" id="produtoPreview">Leia ou digite um codigo para consultar o produto.</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Qtd por caixa</label>
                            <input type="number" name="quantidade_por_caixa" id="qtdPorCaixa" class="form-control form-control-lg" min="0" step="0.0001" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Qtd caixas</label>
                            <input type="number" name="quantidade_caixas" id="qtdCaixas" class="form-control form-control-lg" min="0" step="0.0001" required>
                        </div>
                        <div class="col-12">
                            <div class="p-3 rounded-2 bg-light border fw-bold">Total recebido: <span id="qtdTotal">0</span></div>
                        </div>
                        <div class="col-12 d-none" id="blocoProdutoNaoEncontrado">
                            <div class="produto-alerta-nao-cadastrado">
                                <div class="d-flex gap-3 align-items-start mb-3">
                                    <div class="alerta-icone">!</div>
                                    <div>
                                        <div class="fw-bold fs-5 text-warning-emphasis">Produto nao cadastrado</div>
                                        <div class="small text-muted">Tire uma foto do produto para o cadastro ser avaliado depois. A descricao nao e obrigatoria.</div>
                                    </div>
                                </div>
                            <label class="form-label fw-semibold">Foto obrigatoria do produto</label>
                            <input type="file" name="foto_produto" id="fotoProduto" accept="image/*" capture="environment" class="form-control">
                                <label class="form-label mt-3">Observacao opcional</label>
                                <input type="text" name="descricao_informada" class="form-control" maxlength="255" placeholder="Opcional">
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-lg-mobile w-100">Adicionar item</button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="d-flex flex-column gap-2">
                    <?php foreach ($itens as $item): ?>
                        <div class="item-card p-3">
                            <div class="d-flex gap-3">
                                <div class="item-order">#<?= (int)$item['ordem'] ?></div>
                                <div class="flex-grow-1">
                                    <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                                        <?php if (($item['produto_encontrado'] ?? 'N') === 'S'): ?>
                                            <span class="badge text-bg-success">Produto encontrado</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-warning">Produto nao cadastrado</span>
                                        <?php endif; ?>
                                        <span class="badge text-bg-light border text-dark"><?= date('H:i', strtotime($item['criado_em'])) ?></span>
                                    </div>
                                    <div class="fw-bold"><?= htmlspecialchars($item['descproduto'] ?: ($item['descricao_informada'] ?: 'Produto nao cadastrado - foto anexada')) ?></div>
                                    <div class="text-muted small">Codigo: <?= htmlspecialchars($item['codigo_barras']) ?></div>
                                    <?php if (!empty($item['codproduto'])): ?>
                                        <div class="text-muted small">CODPRODUTO: <?= htmlspecialchars($item['codproduto']) ?></div>
                                    <?php endif; ?>
                                    <div class="row g-2 mt-2">
                                        <div class="col-4">
                                            <div class="bg-light border rounded-2 p-2 text-center">
                                                <div class="small text-muted">Por caixa</div>
                                                <div class="fw-bold"><?= formatarQtdRecebimento($item['quantidade_por_caixa']) ?></div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="bg-light border rounded-2 p-2 text-center">
                                                <div class="small text-muted">Caixas</div>
                                                <div class="fw-bold"><?= formatarQtdRecebimento($item['quantidade_caixas']) ?></div>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="bg-success-subtle border border-success rounded-2 p-2 text-center">
                                                <div class="small text-muted">Total</div>
                                                <div class="fw-bold"><?= formatarQtdRecebimento($item['quantidade_total']) ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($item['foto_produto'])): ?>
                                        <a href="../../<?= htmlspecialchars($item['foto_produto']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary mt-2">Ver foto do produto</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($itens)): ?>
                        <div class="text-muted small">Nenhum item adicionado ainda.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="card shadow-sm mb-4 mobile-step">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3">3. Finalizar</h2>
                <?php if ($finalizado): ?>
                    <div class="alert alert-success mb-3">Recebimento finalizado em <?= date('d/m/Y H:i', strtotime($recebimento['finalizado_em'])) ?>.</div>
                    <?php if (!empty($recebimento['selfie_arquivo'])): ?>
                        <a href="../../<?= htmlspecialchars($recebimento['selfie_arquivo']) ?>" target="_blank" class="btn btn-outline-secondary">Ver comprovante</a>
                    <?php endif; ?>
                <?php else: ?>
                    <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Finalizar recebimento com a identificacao enviada?');">
                        <input type="hidden" name="acao" value="finalizar">
                        <input type="hidden" name="recebimento_id" value="<?= (int)$recebimento['id'] ?>">
                        <div class="alert alert-light border mb-3">
                            Envie <strong>uma</strong> das opcoes abaixo para identificar quem recebeu.
                        </div>
                        <label class="form-label">Selfie de quem recebeu</label>
                        <input type="file" name="selfie" accept="image/*" capture="user" class="form-control mb-3">
                        <div class="text-center text-muted fw-semibold mb-3">ou</div>
                        <label class="form-label">Foto do canhoto/documento assinado por quem recebeu</label>
                        <input type="file" name="canhoto_assinado" accept="image/*" capture="environment" class="form-control mb-3">
                        <button type="submit" class="btn btn-success btn-lg-mobile w-100">Finalizar recebimento</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<script>
(function () {
    const codigoInput = document.getElementById('codigoBarras');
    const preview = document.getElementById('produtoPreview');
    const blocoNaoEncontrado = document.getElementById('blocoProdutoNaoEncontrado');
    const fotoProduto = document.getElementById('fotoProduto');
    const qtdPorCaixa = document.getElementById('qtdPorCaixa');
    const qtdCaixas = document.getElementById('qtdCaixas');
    const qtdTotal = document.getElementById('qtdTotal');
    const scannerBox = document.getElementById('scannerBox');
    const scannerVideo = document.getElementById('scannerVideo');
    const btnScanner = document.getElementById('btnScanner');
    const btnPararScanner = document.getElementById('btnPararScanner');
    let scannerStream = null;
    let scannerTimer = null;

    function atualizarTotal() {
        if (!qtdPorCaixa || !qtdCaixas || !qtdTotal) return;
        const porCaixa = parseFloat((qtdPorCaixa.value || '0').replace(',', '.')) || 0;
        const caixas = parseFloat((qtdCaixas.value || '0').replace(',', '.')) || 0;
        qtdTotal.textContent = (porCaixa * caixas).toLocaleString('pt-BR', { maximumFractionDigits: 4 });
    }

    async function consultarProduto() {
        if (!codigoInput || !preview) return;
        const codigo = codigoInput.value.trim();
        if (!codigo) {
            preview.textContent = 'Leia ou digite um codigo para consultar o produto.';
            return;
        }

        preview.className = 'alert alert-light border mb-0';
        preview.textContent = 'Consultando produto...';
        try {
            const resposta = await fetch('recebimento_mercadorias.php?ajax=produto&codigo=' + encodeURIComponent(codigo));
            const dados = await resposta.json();
            if (dados.encontrado && dados.produto) {
                const embQtde = parseFloat(String(dados.produto.EMB_QTDE || '0').replace(',', '.')) || 0;
                preview.className = 'alert alert-success border border-success mb-0';
                preview.innerHTML = '<strong>Produto encontrado</strong><br>' +
                    (dados.produto.CODPRODUTO || '') + ' - ' + (dados.produto.DESCPRODUTO || '') +
                    (embQtde > 0 ? '<br>Qtd por caixa cadastrada: <strong>' + embQtde.toLocaleString('pt-BR', { maximumFractionDigits: 4 }) + '</strong>' : '');
                if (embQtde > 0 && qtdPorCaixa && (qtdPorCaixa.value === '' || parseFloat(qtdPorCaixa.value || '0') === 0)) {
                    qtdPorCaixa.value = String(embQtde).replace('.', ',');
                    atualizarTotal();
                }
                blocoNaoEncontrado?.classList.add('d-none');
                if (fotoProduto) fotoProduto.required = false;
            } else {
                preview.className = 'alert alert-warning border border-warning border-2 mb-0';
                preview.innerHTML = '<strong>ATENCAO: produto nao cadastrado</strong><br>O item sera salvo como novo produto pendente. A foto do produto e obrigatoria; descricao e opcional.';
                blocoNaoEncontrado?.classList.remove('d-none');
                if (fotoProduto) fotoProduto.required = true;
            }
        } catch (e) {
            preview.className = 'alert alert-danger mb-0';
            preview.textContent = 'Nao foi possivel consultar o produto agora.';
        }
    }

    async function pararScanner() {
        if (scannerTimer) {
            clearInterval(scannerTimer);
            scannerTimer = null;
        }
        if (scannerStream) {
            scannerStream.getTracks().forEach(track => track.stop());
            scannerStream = null;
        }
        if (scannerBox) scannerBox.style.display = 'none';
    }

    async function iniciarScanner() {
        if (!('BarcodeDetector' in window)) {
            alert('Este navegador nao suporta leitura automatica pela camera. Use o campo manual.');
            codigoInput?.focus();
            return;
        }

        await pararScanner();
        scannerStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' },
            audio: false
        });
        scannerVideo.srcObject = scannerStream;
        scannerBox.style.display = 'block';
        await scannerVideo.play();

        let detector;
        try {
            detector = new BarcodeDetector({
                formats: ['ean_13', 'ean_8', 'code_128', 'qr_code']
            });
        } catch (e) {
            await pararScanner();
            alert('Este navegador nao conseguiu iniciar a leitura automatica. Use o campo manual.');
            codigoInput?.focus();
            return;
        }

        scannerTimer = setInterval(async () => {
            try {
                const codigos = await detector.detect(scannerVideo);
                if (codigos.length > 0) {
                    codigoInput.value = codigos[0].rawValue || '';
                    await pararScanner();
                    consultarProduto();
                }
            } catch (e) {
                await pararScanner();
            }
        }, 700);
    }

    codigoInput?.addEventListener('change', consultarProduto);
    codigoInput?.addEventListener('blur', consultarProduto);
    qtdPorCaixa?.addEventListener('input', atualizarTotal);
    qtdCaixas?.addEventListener('input', atualizarTotal);
    btnScanner?.addEventListener('click', iniciarScanner);
    btnPararScanner?.addEventListener('click', pararScanner);
    atualizarTotal();
})();
</script>

<?php require '../../layout/footer.php'; ?>
