<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/whatsapp_lib.php';

exigirNivel('MASTER');
whatsappEnsureTables($pdo_master);

if (empty($_SESSION['csrf_whatsapp'])) {
    $_SESSION['csrf_whatsapp'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_whatsapp'];
$alerta = null;
$erro = null;

function postValue(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function requireCsrf(string $csrf): void
{
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        throw new Exception('Sessao expirada. Recarregue a pagina e tente novamente.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf($csrf);
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'salvar_config') {
            $stmt = $pdo_master->prepare("
                INSERT INTO whatsapp_config (id, nome, token, api_base_url, ativo)
                VALUES (1, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    nome = VALUES(nome),
                    token = VALUES(token),
                    api_base_url = VALUES(api_base_url),
                    ativo = VALUES(ativo)
            ");
            $stmt->execute([
                postValue('nome', 'Principal'),
                postValue('token'),
                postValue('api_base_url', 'https://api-whatsapp.wascript.com.br/api/enviar-texto'),
                postValue('ativo', 'S') === 'S' ? 'S' : 'N',
            ]);
            $alerta = 'Configuracao salva.';
        }

        if ($acao === 'salvar_destinatario') {
            $id = (int)($_POST['id'] ?? 0);
            $dados = [
                postValue('nome_destinatario'),
                postValue('tipo') === 'GRUPO' ? 'GRUPO' : 'PESSOA',
                postValue('numero'),
                postValue('ativo_destinatario', 'S') === 'S' ? 'S' : 'N',
            ];

            if ($dados[0] === '' || $dados[2] === '') {
                throw new Exception('Informe nome e numero do destinatario.');
            }

            if ($id > 0) {
                $stmt = $pdo_master->prepare("UPDATE whatsapp_destinatarios SET nome = ?, tipo = ?, numero = ?, ativo = ? WHERE id = ?");
                $dados[] = $id;
                $stmt->execute($dados);
                $alerta = 'Destinatario atualizado.';
            } else {
                $stmt = $pdo_master->prepare("INSERT INTO whatsapp_destinatarios (nome, tipo, numero, ativo) VALUES (?, ?, ?, ?)");
                $stmt->execute($dados);
                $alerta = 'Destinatario cadastrado.';
            }
        }

        if ($acao === 'salvar_mensagem') {
            $id = (int)($_POST['id'] ?? 0);
            $titulo = postValue('titulo');
            $conteudo = trim((string)($_POST['conteudo'] ?? ''));
            $ativo = postValue('ativo_mensagem', 'S') === 'S' ? 'S' : 'N';

            if ($titulo === '' || $conteudo === '') {
                throw new Exception('Informe titulo e conteudo da mensagem.');
            }

            if ($id > 0) {
                $stmt = $pdo_master->prepare("UPDATE whatsapp_mensagens SET titulo = ?, conteudo = ?, ativo = ? WHERE id = ?");
                $stmt->execute([$titulo, $conteudo, $ativo, $id]);
                $alerta = 'Mensagem atualizada.';
            } else {
                $stmt = $pdo_master->prepare("INSERT INTO whatsapp_mensagens (titulo, conteudo, ativo) VALUES (?, ?, ?)");
                $stmt->execute([$titulo, $conteudo, $ativo]);
                $alerta = 'Mensagem cadastrada.';
            }
        }

        if ($acao === 'enviar_mensagem') {
            $mensagemId = (int)($_POST['mensagem_id'] ?? 0);
            $destinatariosIds = array_map('intval', $_POST['destinatarios'] ?? []);

            if ($mensagemId <= 0 || empty($destinatariosIds)) {
                throw new Exception('Selecione uma mensagem e pelo menos um destinatario.');
            }

            $config = whatsappConfig($pdo_master);
            if (!$config || $config['ativo'] !== 'S') {
                throw new Exception('A configuracao do WhatsApp esta inativa.');
            }

            $stmt = $pdo_master->prepare("SELECT * FROM whatsapp_mensagens WHERE id = ? AND ativo = 'S'");
            $stmt->execute([$mensagemId]);
            $mensagem = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mensagem) {
                throw new Exception('Mensagem ativa nao encontrada.');
            }

            $placeholders = implode(',', array_fill(0, count($destinatariosIds), '?'));
            $stmt = $pdo_master->prepare("SELECT * FROM whatsapp_destinatarios WHERE ativo = 'S' AND id IN ($placeholders)");
            $stmt->execute($destinatariosIds);
            $destinatarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $ok = 0;
            $falha = 0;
            foreach ($destinatarios as $destinatario) {
                $resultado = whatsappSend($pdo_master, $config, $destinatario, $mensagem['conteudo'], $mensagemId, $_SESSION['usuario_id'] ?? null);
                if ($resultado['status'] === 'OK') {
                    $ok++;
                } else {
                    $falha++;
                }
            }

            $alerta = "Envio concluido: {$ok} OK, {$falha} erro(s).";
        }

        if ($acao === 'salvar_rotina') {
            $id = (int)($_POST['id'] ?? 0);
            $codigo = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', postValue('codigo')));
            $nome = postValue('nome_rotina');
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            $mensagemId = (int)($_POST['mensagem_id'] ?? 0);
            $mensagemId = $mensagemId > 0 ? $mensagemId : null;
            $ativo = postValue('ativo_rotina', 'S') === 'S' ? 'S' : 'N';
            $duplicidade = postValue('evitar_duplicidade_diaria', 'N') === 'S' ? 'S' : 'N';
            $destinatariosIds = array_values(array_unique(array_map('intval', $_POST['rotina_destinatarios'] ?? [])));

            if ($codigo === '' || $nome === '') {
                throw new Exception('Informe codigo e nome da rotina.');
            }

            if ($id > 0) {
                $stmt = $pdo_master->prepare("
                    UPDATE whatsapp_rotinas
                    SET codigo = ?, nome = ?, descricao = ?, mensagem_id = ?, ativo = ?, evitar_duplicidade_diaria = ?
                    WHERE id = ?
                ");
                $stmt->execute([$codigo, $nome, $descricao, $mensagemId, $ativo, $duplicidade, $id]);
                $rotinaId = $id;
                $alerta = 'Rotina atualizada.';
            } else {
                $stmt = $pdo_master->prepare("
                    INSERT INTO whatsapp_rotinas
                        (codigo, nome, descricao, mensagem_id, ativo, evitar_duplicidade_diaria)
                    VALUES
                        (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$codigo, $nome, $descricao, $mensagemId, $ativo, $duplicidade]);
                $rotinaId = (int)$pdo_master->lastInsertId();
                $alerta = 'Rotina cadastrada.';
            }

            $stmt = $pdo_master->prepare("DELETE FROM whatsapp_rotina_destinatarios WHERE rotina_id = ?");
            $stmt->execute([$rotinaId]);

            if (!empty($destinatariosIds)) {
                $stmt = $pdo_master->prepare("INSERT IGNORE INTO whatsapp_rotina_destinatarios (rotina_id, destinatario_id) VALUES (?, ?)");
                foreach ($destinatariosIds as $destinatarioId) {
                    if ($destinatarioId > 0) {
                        $stmt->execute([$rotinaId, $destinatarioId]);
                    }
                }
            }
        }

        if ($acao === 'enviar_rotina') {
            $rotinaId = (int)($_POST['rotina_id'] ?? 0);
            $stmt = $pdo_master->prepare("SELECT * FROM whatsapp_rotinas WHERE id = ?");
            $stmt->execute([$rotinaId]);
            $rotina = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rotina) {
                throw new Exception('Rotina nao encontrada.');
            }

            if ($rotina['codigo'] === 'resumo_diario') {
                $mensagem = whatsappMensagemResumoDiario($pdo_master, 1);
                $mensagemId = null;
            } else {
                $mensagemId = (int)($rotina['mensagem_id'] ?? 0);
                if ($mensagemId <= 0) {
                    throw new Exception('Esta rotina nao possui mensagem vinculada.');
                }
                $stmt = $pdo_master->prepare("SELECT * FROM whatsapp_mensagens WHERE id = ? AND ativo = 'S'");
                $stmt->execute([$mensagemId]);
                $mensagemRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$mensagemRow) {
                    throw new Exception('Mensagem ativa da rotina nao encontrada.');
                }
                $mensagem = $mensagemRow['conteudo'];
            }

            $resultado = whatsappEnviarRotina($pdo_master, $rotina, $mensagem, $mensagemId, $_SESSION['usuario_id'] ?? null);
            $alerta = "Rotina enviada: {$resultado['ok']} OK, {$resultado['falha']} erro(s).";
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

$config = whatsappConfig($pdo_master);
$destinatarios = $pdo_master->query("SELECT * FROM whatsapp_destinatarios ORDER BY ativo DESC, tipo, nome")->fetchAll(PDO::FETCH_ASSOC);
$mensagens = $pdo_master->query("SELECT * FROM whatsapp_mensagens ORDER BY ativo DESC, titulo")->fetchAll(PDO::FETCH_ASSOC);
$rotinas = $pdo_master->query("
    SELECT r.*, m.titulo AS mensagem_titulo
    FROM whatsapp_rotinas r
    LEFT JOIN whatsapp_mensagens m ON m.id = r.mensagem_id
    ORDER BY r.ativo DESC, r.nome
")->fetchAll(PDO::FETCH_ASSOC);
$rotinaDestinatarios = [];
foreach ($pdo_master->query("SELECT rotina_id, destinatario_id FROM whatsapp_rotina_destinatarios") as $rd) {
    $rotinaDestinatarios[(int)$rd['rotina_id']][] = (int)$rd['destinatario_id'];
}
$historico = $pdo_master->query("
    SELECT e.*, m.titulo, r.nome AS rotina_nome
    FROM whatsapp_envios e
    LEFT JOIN whatsapp_mensagens m ON m.id = e.mensagem_id
    LEFT JOIN whatsapp_rotinas r ON r.id = e.rotina_id
    ORDER BY e.enviado_em DESC, e.id DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/../../layout/header.php';
?>

<div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
    <div>
        <span class="badge text-bg-success mb-2">WhatsApp</span>
        <h1 class="h3 fw-bold mb-1">Mensagens Integradas</h1>
        <p class="text-muted mb-0">Configure a API, cadastre destinatarios, envie mensagens e acompanhe o historico.</p>
    </div>
    <a href="../../index.php" class="btn btn-outline-secondary">Voltar ao painel</a>
</div>

<?php if ($alerta): ?>
    <div class="alert alert-success"><?= htmlspecialchars($alerta) ?></div>
<?php endif; ?>

<?php if ($erro): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-xl-5">
        <div class="card shadow-sm h-100">
            <div class="card-header">
                <h2 class="h5 mb-0">Configuracao da API</h2>
            </div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="acao" value="salvar_config">

                    <div class="col-md-6">
                        <label class="form-label">Nome</label>
                        <input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($config['nome'] ?? 'Principal') ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select name="ativo" class="form-select">
                            <option value="S" <?= (($config['ativo'] ?? 'S') === 'S') ? 'selected' : '' ?>>Ativo</option>
                            <option value="N" <?= (($config['ativo'] ?? 'S') === 'N') ? 'selected' : '' ?>>Inativo</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Token</label>
                        <input type="password" name="token" class="form-control" value="<?= htmlspecialchars($config['token'] ?? '') ?>" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label">URL base da API</label>
                        <input type="url" name="api_base_url" class="form-control" value="<?= htmlspecialchars($config['api_base_url'] ?? 'https://api-whatsapp.wascript.com.br/api/enviar-texto') ?>" required>
                    </div>

                    <div class="col-12">
                        <button class="btn btn-primary w-100">Salvar configuracao</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card shadow-sm h-100">
            <div class="card-header">
                <h2 class="h5 mb-0">Destinatarios</h2>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2 mb-3">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="acao" value="salvar_destinatario">
                    <div class="col-md-4">
                        <input type="text" name="nome_destinatario" class="form-control" placeholder="Nome" required>
                    </div>
                    <div class="col-md-3">
                        <select name="tipo" class="form-select">
                            <option value="PESSOA">Pessoa</option>
                            <option value="GRUPO">Grupo</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="numero" class="form-control" placeholder="Numero ou ID do grupo" required>
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-success w-100">Adicionar</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Tipo</th>
                                <th>Numero/ID</th>
                                <th>Status</th>
                                <th class="text-center">Acoes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($destinatarios)): ?>
                                <tr><td colspan="5" class="text-muted text-center">Nenhum destinatario cadastrado.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($destinatarios as $d): ?>
                                <tr>
                                    <td><?= htmlspecialchars($d['nome']) ?></td>
                                    <td><?= htmlspecialchars($d['tipo']) ?></td>
                                    <td><?= htmlspecialchars($d['numero']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $d['ativo'] === 'S' ? 'success' : 'secondary' ?>">
                                            <?= $d['ativo'] === 'S' ? 'Ativo' : 'Inativo' ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#destinatario<?= (int)$d['id'] ?>">
                                            Editar
                                        </button>
                                    </td>
                                </tr>
                                <tr class="collapse" id="destinatario<?= (int)$d['id'] ?>">
                                    <td colspan="5">
                                        <form method="post" class="row g-2">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="acao" value="salvar_destinatario">
                                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                            <div class="col-md-4">
                                                <input type="text" name="nome_destinatario" class="form-control" value="<?= htmlspecialchars($d['nome']) ?>" required>
                                            </div>
                                            <div class="col-md-2">
                                                <select name="tipo" class="form-select">
                                                    <option value="PESSOA" <?= $d['tipo'] === 'PESSOA' ? 'selected' : '' ?>>Pessoa</option>
                                                    <option value="GRUPO" <?= $d['tipo'] === 'GRUPO' ? 'selected' : '' ?>>Grupo</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <input type="text" name="numero" class="form-control" value="<?= htmlspecialchars($d['numero']) ?>" required>
                                            </div>
                                            <div class="col-md-2">
                                                <select name="ativo_destinatario" class="form-select">
                                                    <option value="S" <?= $d['ativo'] === 'S' ? 'selected' : '' ?>>Ativo</option>
                                                    <option value="N" <?= $d['ativo'] === 'N' ? 'selected' : '' ?>>Inativo</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1">
                                                <button class="btn btn-primary w-100">OK</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h2 class="h5 mb-1">Rotinas de Envio</h2>
            <p class="text-muted mb-0 small">Controle quais destinatarios recebem cada rotina e dispare envios manuais quando precisar.</p>
        </div>
    </div>
    <div class="card-body">
        <form method="post" class="row g-2 mb-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="acao" value="salvar_rotina">

            <div class="col-md-2">
                <label class="form-label">Codigo</label>
                <input type="text" name="codigo" class="form-control" placeholder="nova_rotina" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Nome</label>
                <input type="text" name="nome_rotina" class="form-control" placeholder="Nome da rotina" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Mensagem</label>
                <select name="mensagem_id" class="form-select">
                    <option value="">Gerada pelo sistema / sem mensagem fixa</option>
                    <?php foreach ($mensagens as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['titulo']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="ativo_rotina" class="form-select">
                    <option value="S">Ativa</option>
                    <option value="N">Inativa</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Duplicidade</label>
                <select name="evitar_duplicidade_diaria" class="form-select">
                    <option value="N">Permitir</option>
                    <option value="S">1 por dia</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Descricao</label>
                <input type="text" name="descricao" class="form-control" placeholder="Quando e para que essa rotina deve ser usada">
            </div>
            <div class="col-12">
                <label class="form-label">Destinatarios da rotina</label>
                <div class="row g-2">
                    <?php foreach ($destinatarios as $d): ?>
                        <?php if ($d['ativo'] !== 'S') { continue; } ?>
                        <div class="col-md-4 col-xl-3">
                            <label class="border rounded p-2 d-flex gap-2 h-100">
                                <input type="checkbox" name="rotina_destinatarios[]" value="<?= (int)$d['id'] ?>">
                                <span>
                                    <strong><?= htmlspecialchars($d['nome']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($d['tipo']) ?></small>
                                </span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary w-100">Cadastrar rotina</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th>Rotina</th>
                        <th>Mensagem</th>
                        <th>Destinatarios</th>
                        <th>Status</th>
                        <th>Ultima execucao</th>
                        <th class="text-center">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rotinas)): ?>
                        <tr><td colspan="6" class="text-muted text-center">Nenhuma rotina cadastrada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rotinas as $r): ?>
                        <?php $selecionados = $rotinaDestinatarios[(int)$r['id']] ?? []; ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($r['nome']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($r['codigo']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($r['mensagem_titulo'] ?? ($r['codigo'] === 'resumo_diario' ? 'Gerada pelo sistema' : 'Sem mensagem')) ?></td>
                            <td><?= count($selecionados) ?> vinculado(s)</td>
                            <td>
                                <span class="badge bg-<?= $r['ativo'] === 'S' ? 'success' : 'secondary' ?>">
                                    <?= $r['ativo'] === 'S' ? 'Ativa' : 'Inativa' ?>
                                </span>
                            </td>
                            <td><?= $r['ultima_execucao'] ? date('d/m/Y H:i', strtotime($r['ultima_execucao'])) : '-' ?></td>
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center">
                                    <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#rotina<?= (int)$r['id'] ?>">Editar</button>
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="acao" value="enviar_rotina">
                                        <input type="hidden" name="rotina_id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-success" onclick="return confirm('Enviar esta rotina agora?')">Enviar agora</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <tr class="collapse" id="rotina<?= (int)$r['id'] ?>">
                            <td colspan="6">
                                <form method="post" class="row g-2">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="acao" value="salvar_rotina">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <div class="col-md-2">
                                        <input type="text" name="codigo" class="form-control" value="<?= htmlspecialchars($r['codigo']) ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" name="nome_rotina" class="form-control" value="<?= htmlspecialchars($r['nome']) ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <select name="mensagem_id" class="form-select">
                                            <option value="">Gerada pelo sistema / sem mensagem fixa</option>
                                            <?php foreach ($mensagens as $m): ?>
                                                <option value="<?= (int)$m['id'] ?>" <?= (int)($r['mensagem_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($m['titulo']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <select name="ativo_rotina" class="form-select">
                                            <option value="S" <?= $r['ativo'] === 'S' ? 'selected' : '' ?>>Ativa</option>
                                            <option value="N" <?= $r['ativo'] === 'N' ? 'selected' : '' ?>>Inativa</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <select name="evitar_duplicidade_diaria" class="form-select">
                                            <option value="N" <?= $r['evitar_duplicidade_diaria'] === 'N' ? 'selected' : '' ?>>Permitir duplicidade</option>
                                            <option value="S" <?= $r['evitar_duplicidade_diaria'] === 'S' ? 'selected' : '' ?>>1 por dia</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <input type="text" name="descricao" class="form-control" value="<?= htmlspecialchars($r['descricao'] ?? '') ?>">
                                    </div>
                                    <div class="col-12">
                                        <div class="row g-2">
                                            <?php foreach ($destinatarios as $d): ?>
                                                <?php if ($d['ativo'] !== 'S') { continue; } ?>
                                                <div class="col-md-4 col-xl-3">
                                                    <label class="border rounded p-2 d-flex gap-2 h-100">
                                                        <input type="checkbox" name="rotina_destinatarios[]" value="<?= (int)$d['id'] ?>" <?= in_array((int)$d['id'], $selecionados, true) ? 'checked' : '' ?>>
                                                        <span>
                                                            <strong><?= htmlspecialchars($d['nome']) ?></strong><br>
                                                            <small class="text-muted"><?= htmlspecialchars($d['tipo']) ?> - <?= htmlspecialchars($d['numero']) ?></small>
                                                        </span>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <button class="btn btn-primary w-100">Salvar rotina</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-5">
        <div class="card shadow-sm h-100">
            <div class="card-header">
                <h2 class="h5 mb-0">Cadastrar Mensagem</h2>
            </div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="acao" value="salvar_mensagem">
                    <div class="col-12">
                        <label class="form-label">Titulo</label>
                        <input type="text" name="titulo" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Mensagem</label>
                        <textarea name="conteudo" class="form-control" rows="7" required></textarea>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Status</label>
                        <select name="ativo_mensagem" class="form-select">
                            <option value="S">Ativa</option>
                            <option value="N">Inativa</option>
                        </select>
                    </div>
                    <div class="col-md-7 d-flex align-items-end">
                        <button class="btn btn-primary w-100">Salvar mensagem</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card shadow-sm h-100">
            <div class="card-header">
                <h2 class="h5 mb-0">Envio Manual</h2>
            </div>
            <div class="card-body">
                <?php if (empty($mensagens)): ?>
                    <div class="alert alert-info mb-0">Cadastre uma mensagem para habilitar o envio manual.</div>
                <?php else: ?>
                    <div class="accordion" id="mensagensAccordion">
                        <?php foreach ($mensagens as $m): ?>
                            <div class="accordion-item">
                                <h3 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#mensagem<?= (int)$m['id'] ?>">
                                        <?= htmlspecialchars($m['titulo']) ?>
                                        <span class="badge ms-2 bg-<?= $m['ativo'] === 'S' ? 'success' : 'secondary' ?>">
                                            <?= $m['ativo'] === 'S' ? 'Ativa' : 'Inativa' ?>
                                        </span>
                                    </button>
                                </h3>
                                <div id="mensagem<?= (int)$m['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#mensagensAccordion">
                                    <div class="accordion-body">
                                        <div class="border rounded p-3 bg-light mb-3" style="white-space: pre-wrap;"><?= htmlspecialchars($m['conteudo']) ?></div>

                                        <form method="post" class="row g-2 mb-3">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="acao" value="salvar_mensagem">
                                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                            <div class="col-md-8">
                                                <input type="text" name="titulo" class="form-control" value="<?= htmlspecialchars($m['titulo']) ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <select name="ativo_mensagem" class="form-select">
                                                    <option value="S" <?= $m['ativo'] === 'S' ? 'selected' : '' ?>>Ativa</option>
                                                    <option value="N" <?= $m['ativo'] === 'N' ? 'selected' : '' ?>>Inativa</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <textarea name="conteudo" class="form-control" rows="4" required><?= htmlspecialchars($m['conteudo']) ?></textarea>
                                            </div>
                                            <div class="col-12">
                                                <button class="btn btn-outline-primary w-100">Salvar alteracoes da mensagem</button>
                                            </div>
                                        </form>

                                        <?php if ($m['ativo'] !== 'S'): ?>
                                            <div class="alert alert-warning mb-0">Mensagem inativa.</div>
                                        <?php else: ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="acao" value="enviar_mensagem">
                                                <input type="hidden" name="mensagem_id" value="<?= (int)$m['id'] ?>">

                                                <div class="row g-2 mb-3">
                                                    <?php foreach ($destinatarios as $d): ?>
                                                        <?php if ($d['ativo'] !== 'S') { continue; } ?>
                                                        <div class="col-md-6">
                                                            <label class="border rounded p-2 d-flex gap-2 h-100">
                                                                <input type="checkbox" name="destinatarios[]" value="<?= (int)$d['id'] ?>">
                                                                <span>
                                                                    <strong><?= htmlspecialchars($d['nome']) ?></strong><br>
                                                                    <small class="text-muted"><?= htmlspecialchars($d['tipo']) ?> - <?= htmlspecialchars($d['numero']) ?></small>
                                                                </span>
                                                            </label>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>

                                                <button class="btn btn-success w-100" onclick="return confirm('Enviar esta mensagem para os destinatarios selecionados?')">
                                                    Enviar mensagem
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header">
        <h2 class="h5 mb-0">Historico de Envios</h2>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Rotina</th>
                        <th>Mensagem</th>
                        <th>Destino</th>
                        <th>Status</th>
                        <th>Resposta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historico)): ?>
                        <tr><td colspan="6" class="text-muted text-center">Nenhum envio registrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($historico as $h): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($h['enviado_em'])) ?></td>
                            <td><?= htmlspecialchars($h['rotina_nome'] ?? 'Manual') ?></td>
                            <td><?= htmlspecialchars($h['titulo'] ?? 'Mensagem avulsa') ?></td>
                            <td>
                                <?= htmlspecialchars($h['destino_nome']) ?><br>
                                <small class="text-muted"><?= htmlspecialchars($h['destino_numero']) ?></small>
                            </td>
                            <td>
                                <span class="badge bg-<?= $h['status'] === 'OK' ? 'success' : 'danger' ?>">
                                    <?= htmlspecialchars($h['status']) ?>
                                </span>
                            </td>
                            <td class="small">
                                <?= htmlspecialchars($h['erro'] ?: ($h['resposta_api'] ?? '')) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../layout/footer.php'; ?>
