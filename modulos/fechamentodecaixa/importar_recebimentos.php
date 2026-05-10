<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';
require '../../layout/header.php';

$empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
$regras = listarRegrasImportacaoRecebimentos($pdo_master, $empresa_id);

$regrasPorGrupo = [];
foreach ($regras as $regra) {
    $grupo = trim($regra['grupo'] ?? '') ?: 'Recebimentos';
    $regrasPorGrupo[$grupo][] = $regra;
}
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-7">
                <span class="badge text-bg-warning mb-3">Recebimentos</span>
                <h1 class="h3 fw-bold mb-2">Importacao de Recebimentos</h1>
                <p class="text-muted mb-0">Selecione o tipo de arquivo para fazer upload e validar a importacao.</p>
            </div>
            <div class="col-lg-5">
                <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                    <a href="menu_recebimentos.php" class="btn btn-outline-secondary">Voltar para Recebimentos</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if (empty($regrasPorGrupo)): ?>
    <div class="alert alert-warning shadow-sm">
        Nenhuma regra de importacao cadastrada para esta empresa. Cadastre as regras antes de importar arquivos para evitar CM ou estabelecimento incorreto.
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($regrasPorGrupo as $grupo => $lista): ?>
            <div class="col-lg-6">
                <div class="card h-100 shadow-sm">
                    <div class="card-header">
                        <h2 class="h5 mb-0"><?= htmlspecialchars($grupo) ?></h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php foreach ($lista as $item): ?>
                                <div class="col-md-6 col-lg-12">
                                    <div class="border p-3 rounded h-100">
                                        <h3 class="h6 fw-bold mb-1"><?= htmlspecialchars($item['nome']) ?></h3>
                                        <p class="text-muted small mb-3"><?= htmlspecialchars($item['descricao']) ?></p>
                                        <a href="<?= htmlspecialchars(urlRegraImportacao($item)) ?>" class="btn <?= htmlspecialchars($item['botao']) ?> w-100">Abrir Importacao</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require '../../layout/footer.php'; ?>
