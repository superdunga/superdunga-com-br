<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../whatsapp/whatsapp_lib.php';
require __DIR__ . '/whatsapp_operacional_lib.php';

exigirNivel('MASTER');
whatsappEnsureTables($pdo_master);
whatsappOperacionalEnsureTables($pdo_master);
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
if (empty($_SESSION['csrf_whatsapp_operacional'])) {
    $_SESSION['csrf_whatsapp_operacional'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_whatsapp_operacional'];
$alerta = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new Exception('Sessao expirada. Recarregue a pagina.');
        }
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'salvar_config') {
            $nome = trim((string)($_POST['nome'] ?? 'Operacional'));
            $url = rtrim(trim((string)($_POST['evolution_api_base_url'] ?? '')), '/');
            $token = trim((string)($_POST['evolution_token'] ?? ''));
            $instancia = trim((string)($_POST['instancia'] ?? ''));
            if ($nome === '' || $url === '' || $token === '' || $instancia === '') {
                throw new Exception('Preencha todos os dados da instancia operacional.');
            }
            $stmt = $pdo_master->prepare("
                INSERT INTO whatsapp_operacional_config (empresa_id, nome, evolution_token, evolution_api_base_url, instancia, ativo)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE nome=VALUES(nome), evolution_token=VALUES(evolution_token),
                    evolution_api_base_url=VALUES(evolution_api_base_url), instancia=VALUES(instancia), ativo=VALUES(ativo)
            ");
            $stmt->execute([$empresaId, $nome, $token, $url, $instancia, ($_POST['ativo'] ?? 'S') === 'S' ? 'S' : 'N']);
            $alerta = 'Configuracao operacional salva.';
        } elseif ($acao === 'testar') {
            $resultado = whatsappOperacionalTestar([
                'evolution_api_base_url' => $_POST['evolution_api_base_url'] ?? '',
                'evolution_token' => $_POST['evolution_token'] ?? '',
                'instancia' => $_POST['instancia'] ?? '',
            ]);
            if (!$resultado['conectado']) {
                throw new Exception('Evolution acessivel, mas a instancia esta ' . $resultado['estado'] . '.');
            }
            $alerta = 'Instancia operacional conectada.';
        } elseif ($acao === 'salvar_destinatario') {
            $id = (int)($_POST['id'] ?? 0);
            $nome = trim((string)($_POST['destino_nome'] ?? ''));
            $numero = trim((string)($_POST['destino_numero'] ?? ''));
            $tipo = ($_POST['destino_tipo'] ?? '') === 'GRUPO' ? 'GRUPO' : 'PESSOA';
            if ($nome === '' || $numero === '') {
                throw new Exception('Informe o nome e o numero/ID do destinatario.');
            }
            if ($id > 0) {
                $stmt = $pdo_master->prepare("UPDATE whatsapp_operacional_destinatarios SET nome=?, tipo=?, numero=?, ativo=? WHERE id=? AND empresa_id=?");
                $stmt->execute([$nome, $tipo, $numero, ($_POST['destino_ativo'] ?? 'S') === 'S' ? 'S' : 'N', $id, $empresaId]);
            } else {
                $stmt = $pdo_master->prepare("INSERT INTO whatsapp_operacional_destinatarios (empresa_id,nome,tipo,numero,ativo) VALUES (?,?,?,?,?)");
                $stmt->execute([$empresaId, $nome, $tipo, $numero, 'S']);
            }
            $alerta = 'Destinatario salvo.';
        } elseif ($acao === 'excluir_destinatario') {
            $stmt = $pdo_master->prepare("DELETE FROM whatsapp_operacional_destinatarios WHERE id=? AND empresa_id=?");
            $stmt->execute([(int)($_POST['id'] ?? 0), $empresaId]);
            $alerta = 'Destinatario removido.';
        } elseif ($acao === 'enviar') {
            $configEnvio = whatsappOperacionalConfig($pdo_master, $empresaId);
            if (!$configEnvio) {
                throw new Exception('Configure a instancia operacional antes de enviar.');
            }
            $stmt = $pdo_master->prepare("SELECT * FROM whatsapp_operacional_destinatarios WHERE id=? AND empresa_id=? AND ativo='S'");
            $stmt->execute([(int)($_POST['destinatario_id'] ?? 0), $empresaId]);
            $destinatario = $stmt->fetch(PDO::FETCH_ASSOC);
            $mensagem = trim((string)($_POST['mensagem'] ?? ''));
            if (!$destinatario || $mensagem === '') {
                throw new Exception('Selecione um destinatario ativo e informe a mensagem.');
            }
            $resultado = whatsappOperacionalEnviar($pdo_master, $configEnvio, $destinatario, $mensagem, $_SESSION['usuario_id'] ?? null);
            if (!$resultado['ok']) {
                throw new Exception('Envio recusado: ' . $resultado['erro']);
            }
            $alerta = 'Mensagem operacional enviada.';
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$config = whatsappOperacionalConfig($pdo_master, $empresaId);
$stmt = $pdo_master->prepare("SELECT * FROM whatsapp_operacional_destinatarios WHERE empresa_id=? ORDER BY ativo DESC, tipo, nome");
$stmt->execute([$empresaId]);
$destinatarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo_master->prepare("SELECT * FROM whatsapp_operacional_envios WHERE empresa_id=? ORDER BY enviado_em DESC,id DESC LIMIT 50");
$stmt->execute([$empresaId]);
$historico = $stmt->fetchAll(PDO::FETCH_ASSOC);
require __DIR__ . '/../../layout/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h1 class="h3 fw-bold mb-1">WhatsApp Operacional</h1>
        <p class="text-muted mb-0">Instancia exclusiva da empresa atual para atendimento e comunicacoes operacionais.</p>
    </div>
    <a href="../whatsapp/index.php" class="btn btn-outline-secondary">Abrir gerencial</a>
</div>
<?php if ($alerta): ?><div class="alert alert-success"><?= htmlspecialchars($alerta) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-xl-5">
        <div class="card shadow-sm h-100">
            <div class="card-header"><h2 class="h5 mb-0">Instancia da empresa</h2></div>
            <div class="card-body">
                <?php if (!$config): ?><div class="alert alert-warning">Esta empresa ainda nao possui instancia operacional configurada.</div><?php endif; ?>
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <div class="col-md-8"><label class="form-label">Nome</label><input name="nome" class="form-control" value="<?= htmlspecialchars($config['nome'] ?? 'Operacional') ?>" required></div>
                    <div class="col-md-4"><label class="form-label">Status</label><select name="ativo" class="form-select"><option value="S" <?= ($config['ativo'] ?? 'S') === 'S' ? 'selected' : '' ?>>Ativo</option><option value="N" <?= ($config['ativo'] ?? '') === 'N' ? 'selected' : '' ?>>Inativo</option></select></div>
                    <div class="col-12"><label class="form-label">URL da Evolution API</label><input type="url" name="evolution_api_base_url" class="form-control" value="<?= htmlspecialchars($config['evolution_api_base_url'] ?? '') ?>" required></div>
                    <div class="col-12"><label class="form-label">API Key da instancia</label><input name="evolution_token" class="form-control font-monospace" value="<?= htmlspecialchars($config['evolution_token'] ?? '') ?>" required></div>
                    <div class="col-12"><label class="form-label">Nome da instancia</label><input name="instancia" class="form-control font-monospace" value="<?= htmlspecialchars($config['instancia'] ?? '') ?>" required></div>
                    <div class="col-12 d-flex gap-2"><button name="acao" value="salvar_config" class="btn btn-primary flex-fill">Salvar configuracao</button><button name="acao" value="testar" class="btn btn-outline-success flex-fill">Testar conexao</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card shadow-sm h-100">
            <div class="card-header"><h2 class="h5 mb-0">Destinatarios operacionais</h2></div>
            <div class="card-body">
                <form method="post" class="row g-2 mb-3">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="salvar_destinatario">
                    <input type="hidden" name="id" id="wo-dest-id" value="0">
                    <input type="hidden" name="destino_ativo" id="wo-dest-ativo" value="S">
                    <div class="col-md-4"><input name="destino_nome" id="wo-dest-nome" class="form-control" placeholder="Nome" required></div>
                    <div class="col-md-3"><select name="destino_tipo" id="wo-dest-tipo" class="form-select"><option value="PESSOA">Pessoa</option><option value="GRUPO">Grupo</option></select></div>
                    <div class="col-md-3"><input name="destino_numero" id="wo-dest-numero" class="form-control" placeholder="Numero ou ID" required></div>
                    <div class="col-md-2"><button class="btn btn-success w-100">Adicionar</button></div>
                </form>
                <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Nome</th><th>Tipo</th><th>Numero/ID</th><th>Status</th><th></th></tr></thead><tbody>
                <?php foreach ($destinatarios as $d): ?><tr><td><?= htmlspecialchars($d['nome']) ?></td><td><?= htmlspecialchars($d['tipo']) ?></td><td class="font-monospace"><?= htmlspecialchars($d['numero']) ?></td><td><span class="badge <?= $d['ativo']==='S'?'text-bg-success':'text-bg-secondary' ?>"><?= $d['ativo']==='S'?'Ativo':'Inativo' ?></span></td><td class="text-end"><div class="d-flex justify-content-end gap-1"><button type="button" class="btn btn-sm btn-outline-primary wo-editar" data-id="<?= (int)$d['id'] ?>" data-nome="<?= htmlspecialchars($d['nome']) ?>" data-tipo="<?= htmlspecialchars($d['tipo']) ?>" data-numero="<?= htmlspecialchars($d['numero']) ?>" data-ativo="<?= htmlspecialchars($d['ativo']) ?>">Editar</button><form method="post" onsubmit="return confirm('Remover este destinatario?')"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="excluir_destinatario"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-outline-danger" title="Excluir">Excluir</button></form></div></td></tr><?php endforeach; ?>
                <?php if (!$destinatarios): ?><tr><td colspan="5" class="text-center text-muted py-3">Nenhum destinatario operacional cadastrado.</td></tr><?php endif; ?>
                </tbody></table></div>
                <script>
                document.querySelectorAll('.wo-editar').forEach(function (botao) {
                    botao.addEventListener('click', function () {
                        document.getElementById('wo-dest-id').value = botao.dataset.id;
                        document.getElementById('wo-dest-nome').value = botao.dataset.nome;
                        document.getElementById('wo-dest-tipo').value = botao.dataset.tipo;
                        document.getElementById('wo-dest-numero').value = botao.dataset.numero;
                        document.getElementById('wo-dest-ativo').value = botao.dataset.ativo;
                        document.getElementById('wo-dest-nome').focus();
                    });
                });
                </script>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3"><div class="card-header"><h2 class="h5 mb-0">Enviar mensagem operacional</h2></div><div class="card-body">
    <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="enviar">
        <div class="col-lg-4"><label class="form-label">Destinatario</label><select name="destinatario_id" class="form-select" required><option value="">Selecione...</option><?php foreach($destinatarios as $d): if($d['ativo']!=='S') continue; ?><option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['nome']) ?></option><?php endforeach; ?></select></div>
        <div class="col-lg-6"><label class="form-label">Mensagem</label><textarea name="mensagem" class="form-control" rows="3" required></textarea></div>
        <div class="col-lg-2 d-flex align-items-end"><button class="btn btn-success w-100">Enviar</button></div>
    </form>
</div></div>

<div class="card shadow-sm"><div class="card-header"><h2 class="h5 mb-0">Historico operacional</h2></div><div class="table-responsive"><table class="table table-striped table-sm mb-0"><thead><tr><th>Data</th><th>Destino</th><th>Mensagem</th><th>Status</th><th>Erro</th></tr></thead><tbody>
<?php foreach($historico as $h): ?><tr><td class="text-nowrap"><?= htmlspecialchars(date('d/m/Y H:i',strtotime($h['enviado_em']))) ?></td><td><?= htmlspecialchars($h['destino_nome']) ?></td><td><?= nl2br(htmlspecialchars($h['mensagem'])) ?></td><td><span class="badge <?= $h['status']==='OK'?'text-bg-success':'text-bg-danger' ?>"><?= htmlspecialchars($h['status']) ?></span></td><td><?= htmlspecialchars($h['erro'] ?? '') ?></td></tr><?php endforeach; ?>
<?php if(!$historico): ?><tr><td colspan="5" class="text-center text-muted py-3">Nenhum envio operacional registrado.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php require __DIR__ . '/../../layout/footer.php'; ?>
