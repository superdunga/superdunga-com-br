<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/modulos.php';
require_once __DIR__ . '/_lib.php';

garantirTabelasUnimed($pdo_master);

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$beneficiarioId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($beneficiarioId <= 0) {
    header('Location: cadastro.php');
    exit;
}

$mensagemErro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $unidade = preg_replace('/\D/', '', (string)($_POST['unidade_unimed'] ?? ''));
    $contrato = preg_replace('/\D/', '', (string)($_POST['contrato_unimed'] ?? ''));
    $familia = preg_replace('/\D/', '', (string)($_POST['familia'] ?? ''));
    $dependente = preg_replace('/\D/', '', (string)($_POST['dependente'] ?? ''));
    $tipo = strtoupper(trim((string)($_POST['tipo'] ?? '')));
    $nome = trim((string)($_POST['nome'] ?? ''));
    $responsavelPagamentoId = (int)($_POST['responsavel_pagamento_id'] ?? 0);
    $telefoneWhatsapp = trim((string)($_POST['telefone_whatsapp'] ?? ''));
    $contratoVenda = trim((string)($_POST['contrato_venda'] ?? ''));
    $plano = trim((string)($_POST['plano'] ?? ''));
    $statusOperacao = strtoupper(trim((string)($_POST['status_operacao'] ?? 'A')));
    $ativo = ($_POST['ativo'] ?? 'S') === 'N' ? 'N' : 'S';

    $unidade = str_pad(substr($unidade, 0, 4), 4, '0', STR_PAD_LEFT);
    $contrato = str_pad(substr($contrato, 0, 4), 4, '0', STR_PAD_LEFT);
    $familia = str_pad(substr($familia, 0, 6), 6, '0', STR_PAD_LEFT);
    $dependente = str_pad(substr($dependente, 0, 2), 2, '0', STR_PAD_LEFT);
    $telefoneWhatsapp = preg_replace('/[^\d()+\-\s]/', '', $telefoneWhatsapp);
    $telefoneWhatsapp = preg_replace('/\s+/', ' ', $telefoneWhatsapp);

    if (!in_array($tipo, ['TITULAR', 'DEPENDENTE'], true)) {
        $mensagemErro = 'Informe um tipo valido.';
    } elseif ($nome === '') {
        $mensagemErro = 'Informe o nome do beneficiario.';
    } elseif (!in_array($statusOperacao, ['A', 'I', 'E', 'R', 'IE'], true)) {
        $mensagemErro = 'Informe um status de operacao valido.';
    } elseif ($responsavelPagamentoId <= 0) {
        $mensagemErro = 'Informe o responsavel por pagamento.';
    } else {
        $codigoCompleto = codigoUnimed($unidade, $contrato, $familia, $dependente);

        $stmtCodigo = $pdo_master->prepare("
            SELECT id
            FROM unimed_beneficiarios
            WHERE empresa_id = ?
              AND codigo_completo = ?
              AND id <> ?
            LIMIT 1
        ");
        $stmtCodigo->execute([$empresaId, $codigoCompleto, $beneficiarioId]);

        if ($stmtCodigo->fetchColumn()) {
            $mensagemErro = 'Ja existe outro beneficiario com este codigo.';
        } else {
            $stmtResponsavel = $pdo_master->prepare("
                SELECT id
                FROM unimed_beneficiarios
                WHERE id = ?
                  AND empresa_id = ?
                  AND ativo = 'S'
                LIMIT 1
            ");
            $stmtResponsavel->execute([$responsavelPagamentoId, $empresaId]);

            if (!$stmtResponsavel->fetchColumn()) {
                $mensagemErro = 'Responsavel por pagamento nao encontrado ou inativo.';
            } else {
            $stmtSalvar = $pdo_master->prepare("
                UPDATE unimed_beneficiarios
                SET
                    codigo_completo = ?,
                    unidade_unimed = ?,
                    contrato_unimed = ?,
                    familia = ?,
                    dependente = ?,
                    tipo = ?,
                    nome = ?,
                    responsavel_pagamento_id = ?,
                    telefone_whatsapp = NULLIF(?, ''),
                    contrato_venda = NULLIF(?, ''),
                    plano = NULLIF(?, ''),
                    status_operacao = ?,
                    ativo = ?
                WHERE id = ?
                  AND empresa_id = ?
            ");
            $stmtSalvar->execute([
                $codigoCompleto,
                $unidade,
                $contrato,
                $familia,
                $dependente,
                $tipo,
                $nome,
                $responsavelPagamentoId,
                $telefoneWhatsapp,
                $contratoVenda,
                $plano,
                $statusOperacao,
                $ativo,
                $beneficiarioId,
                $empresaId,
            ]);

            header('Location: beneficiario.php?id=' . $beneficiarioId . '&ok=1');
            exit;
            }
        }
    }
}

$stmt = $pdo_master->prepare("
    SELECT *
    FROM unimed_beneficiarios
    WHERE id = ?
      AND empresa_id = ?
    LIMIT 1
");
$stmt->execute([$beneficiarioId, $empresaId]);
$beneficiario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$beneficiario) {
    require '../../layout/header.php';
    echo '<div class="alert alert-danger">Beneficiario nao encontrado.</div>';
    require '../../layout/footer.php';
    exit;
}

$stmtResponsaveis = $pdo_master->prepare("
    SELECT id, codigo_completo, nome, familia, dependente
    FROM unimed_beneficiarios
    WHERE empresa_id = ?
      AND ativo = 'S'
    ORDER BY nome, familia, dependente
");
$stmtResponsaveis->execute([$empresaId]);
$responsaveisPagamento = $stmtResponsaveis->fetchAll(PDO::FETCH_ASSOC);

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-success mb-3">Unimed</span>
                <h1 class="h3 fw-bold mb-2">Editar Usuario</h1>
                <p class="text-muted mb-0">Atualize os dados cadastrais do beneficiario e controle se ele esta ativo no sistema.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="cadastro.php" class="btn btn-outline-secondary">Voltar ao cadastro</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagemErro !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mensagemErro) ?></div>
<?php endif; ?>

<?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">Cadastro atualizado com sucesso.</div>
<?php endif; ?>

<form method="post" class="card shadow-sm">
    <div class="card-header">
        <h2 class="h6 mb-0"><?= htmlspecialchars($beneficiario['codigo_completo']) ?> - <?= htmlspecialchars($beneficiario['nome']) ?></h2>
    </div>
    <div class="card-body">
        <input type="hidden" name="id" value="<?= (int)$beneficiario['id'] ?>">

        <div class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Unidade</label>
                <input type="text" name="unidade_unimed" class="form-control" maxlength="4" value="<?= htmlspecialchars($beneficiario['unidade_unimed']) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Contrato</label>
                <input type="text" name="contrato_unimed" class="form-control" maxlength="4" value="<?= htmlspecialchars($beneficiario['contrato_unimed']) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Familia</label>
                <input type="text" name="familia" class="form-control" maxlength="6" value="<?= htmlspecialchars($beneficiario['familia']) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Dependente</label>
                <input type="text" name="dependente" class="form-control" maxlength="2" value="<?= htmlspecialchars($beneficiario['dependente']) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Tipo</label>
                <select name="tipo" class="form-select">
                    <option value="TITULAR" <?= $beneficiario['tipo'] === 'TITULAR' ? 'selected' : '' ?>>Titular</option>
                    <option value="DEPENDENTE" <?= $beneficiario['tipo'] === 'DEPENDENTE' ? 'selected' : '' ?>>Dependente</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Ativo</label>
                <select name="ativo" class="form-select">
                    <option value="S" <?= $beneficiario['ativo'] === 'S' ? 'selected' : '' ?>>Sim</option>
                    <option value="N" <?= $beneficiario['ativo'] === 'N' ? 'selected' : '' ?>>Nao</option>
                </select>
            </div>

            <div class="col-md-8">
                <label class="form-label">Nome</label>
                <input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($beneficiario['nome']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Telefone WhatsApp</label>
                <input type="tel" name="telefone_whatsapp" class="form-control" value="<?= htmlspecialchars((string)$beneficiario['telefone_whatsapp']) ?>" placeholder="(00) 00000-0000">
            </div>

            <div class="col-md-6">
                <label class="form-label">Responsavel por pagamento</label>
                <select name="responsavel_pagamento_id" class="form-select" required>
                    <option value="">Selecione</option>
                    <?php foreach ($responsaveisPagamento as $responsavel): ?>
                        <option value="<?= (int)$responsavel['id'] ?>" <?= (int)($beneficiario['responsavel_pagamento_id'] ?? 0) === (int)$responsavel['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($responsavel['nome']) ?> - <?= htmlspecialchars($responsavel['codigo_completo']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">O responsavel deve existir no cadastro da Unimed e estar ativo.</div>
            </div>

            <div class="col-md-3">
                <label class="form-label">Status operacao Unimed</label>
                <select name="status_operacao" class="form-select">
                    <?php foreach (['A' => 'A - Ativo', 'I' => 'I - Inclusao', 'E' => 'E - Exclusao', 'R' => 'R - Reinclusao', 'IE' => 'IE - Incluido e excluido'] as $valor => $label): ?>
                        <option value="<?= htmlspecialchars($valor) ?>" <?= $beneficiario['status_operacao'] === $valor ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Codigo atual</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($beneficiario['codigo_completo']) ?>" disabled>
            </div>
            <div class="col-md-12">
                <label class="form-label">Contrato venda</label>
                <input type="text" name="contrato_venda" class="form-control" value="<?= htmlspecialchars((string)$beneficiario['contrato_venda']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Plano</label>
                <input type="text" name="plano" class="form-control" value="<?= htmlspecialchars((string)$beneficiario['plano']) ?>">
            </div>
        </div>
    </div>
    <div class="card-footer bg-white d-flex justify-content-end gap-2">
        <a href="cadastro.php" class="btn btn-outline-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">Salvar cadastro</button>
    </div>
</form>

<?php require '../../layout/footer.php'; ?>
