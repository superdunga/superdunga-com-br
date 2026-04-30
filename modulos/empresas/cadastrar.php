<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

/* Apenas MASTER pode acessar */
exigirNivel('MASTER');

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $razao_social   = trim($_POST['razao_social'] ?? '');
    $nome_fantasia  = trim($_POST['nome_fantasia'] ?? '');
    $cnpj           = trim($_POST['cnpj'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $telefone       = trim($_POST['telefone'] ?? '');

    if (empty($razao_social) || empty($nome_fantasia)) {
        $erro = "Razão Social e Nome Fantasia são obrigatórios.";
    } else {

        /* Verificar duplicidade de CNPJ */
        if (!empty($cnpj)) {
            $stmt = $pdo_master->prepare("SELECT id FROM empresas WHERE cnpj = ?");
            $stmt->execute([$cnpj]);

            if ($stmt->fetch()) {
                $erro = "Já existe uma empresa com esse CNPJ.";
            }
        }

        if (!$erro) {

            /* INSERT COM AUDITORIA (VERSÃO CORRIGIDA) */
            $stmt = $pdo_master->prepare("
                INSERT INTO empresas 
                (
                    razao_social,
                    nome_fantasia,
                    cnpj,
                    email,
                    telefone,
                    status,
                    created_by,
                    created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $razao_social,
                $nome_fantasia,
                $cnpj,
                $email,
                $telefone,
                'ATIVA',
                $_SESSION['usuario_id']
            ]);

            $sucesso = "Empresa cadastrada com sucesso.";

            /* Limpar campos após cadastro */
            $razao_social = $nome_fantasia = $cnpj = $email = $telefone = '';
        }
    }
}

require __DIR__ . '/../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-header">
        <h5 class="mb-0">Cadastrar Nova Empresa</h5>
    </div>

    <div class="card-body">

        <?php if ($erro): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="alert alert-success"><?= htmlspecialchars($sucesso) ?></div>
        <?php endif; ?>

        <form method="POST">

            <div class="mb-3">
                <label class="form-label">Razão Social *</label>
                <input type="text" name="razao_social" class="form-control"
                       value="<?= htmlspecialchars($razao_social ?? '') ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Nome Fantasia *</label>
                <input type="text" name="nome_fantasia" class="form-control"
                       value="<?= htmlspecialchars($nome_fantasia ?? '') ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">CNPJ</label>
                <input type="text" name="cnpj" class="form-control"
                       value="<?= htmlspecialchars($cnpj ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($email ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Telefone</label>
                <input type="text" name="telefone" class="form-control"
                       value="<?= htmlspecialchars($telefone ?? '') ?>">
            </div>

            <button type="submit" class="btn btn-success">
                Salvar Empresa
            </button>

            <a href="listar.php" class="btn btn-secondary">
                Voltar
            </a>

        </form>

    </div>
</div>

<?php require __DIR__ . '/../../layout/footer.php'; ?>