<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';

/* Apenas ADMIN ou superior podem acessar */
exigirNivel('ADMIN');

$erro = '';

/* Carrega empresas se for MASTER */
if ($_SESSION['nivel'] === 'MASTER') {
    $empresas = $pdo_master
        ->query("SELECT id, nome_fantasia FROM empresas ORDER BY nome_fantasia")
        ->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nome  = trim($_POST['nome'] ?? '');
    $login = trim($_POST['login'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $nivel = $_POST['nivel'] ?? '';

    $empresa_id = $_SESSION['nivel'] === 'MASTER'
        ? (int)($_POST['empresa_id'] ?? 0)
        : $_SESSION['empresa_id'];

    if ($nome === '' || $login === '' || $senha === '' || $nivel === '') {
        $erro = "Preencha todos os campos.";
    } else {

        /* Impedir criação de nível igual ou superior */
        if (!podeCriarNivel($nivel)) {
            $erro = "Você não pode criar usuário com nível igual ou superior ao seu.";
        }

        if (empty($erro)) {

            $chk = $pdo_master->prepare(
                "SELECT id FROM usuarios WHERE empresa_id = ? AND login = ?"
            );
            $chk->execute([$empresa_id, $login]);

            if ($chk->fetch()) {
                $erro = "Já existe um usuário com esse login nessa empresa.";
            } else {

                $senha_hash = password_hash($senha, PASSWORD_DEFAULT);

                /* INSERT COM AUDITORIA */
                $stmt = $pdo_master->prepare("
                    INSERT INTO usuarios 
                    (empresa_id, nome, login, senha, nivel, status, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, 'ATIVO', ?, NOW())
                ");

                $stmt->execute([
                    $empresa_id,
                    $nome,
                    $login,
                    $senha_hash,
                    $nivel,
                    $_SESSION['usuario_id']
                ]);

                header("Location: listar.php");
                exit;
            }
        }
    }
}

require __DIR__ . '/../../layout/header.php';
?>

<div class="card shadow-sm">
    <div class="card-header">
        <h5 class="mb-0">Cadastrar Usuário</h5>
    </div>

    <div class="card-body">

        <?php if (!empty($erro)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <form method="POST">

            <?php if ($_SESSION['nivel'] === 'MASTER'): ?>
                <div class="mb-3">
                    <label class="form-label">Empresa</label>
                    <select name="empresa_id" class="form-select" required>
                        <option value="">Selecione</option>
                        <?php foreach ($empresas as $e): ?>
                            <option value="<?= $e['id'] ?>">
                                <?= htmlspecialchars($e['nome_fantasia']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label">Nome</label>
                <input type="text" name="nome" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Login</label>
                <input type="text" name="login" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Senha</label>
                <input type="password" name="senha" class="form-control" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Nível</label>
                <select name="nivel" class="form-select" required>
                    <option value="">Selecione</option>

                    <?php if ($_SESSION['nivel'] === 'MASTER'): ?>
                        <option value="MASTER">MASTER</option>
                        <option value="ADMIN">ADMIN</option>
                        <option value="GERENTE">GERENTE</option>
                        <option value="SUPERVISOR">SUPERVISOR</option>
                        <option value="OPERADOR">OPERADOR</option>
                        <option value="CONFERENTE">CONFERENTE</option>
                        <option value="CONSULTA">CONSULTA</option>

                    <?php elseif ($_SESSION['nivel'] === 'ADMIN'): ?>
                        <option value="GERENTE">GERENTE</option>
                        <option value="SUPERVISOR">SUPERVISOR</option>
                        <option value="OPERADOR">OPERADOR</option>
                        <option value="CONFERENTE">CONFERENTE</option>
                        <option value="CONSULTA">CONSULTA</option>
                    <?php endif; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-success">Salvar</button>
            <a href="listar.php" class="btn btn-secondary">Voltar</a>

        </form>

    </div>
</div>

<?php require __DIR__ . '/../../layout/footer.php'; ?>
