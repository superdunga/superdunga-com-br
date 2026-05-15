<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/whatsapp_lib.php';

exigirNivel('MASTER');
whatsappEnsureTables($pdo_master);
$empresaId = (int)($_SESSION['empresa_id'] ?? 1);

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

function formatarAgendaWhatsapp(array $agendamento, array $nomesDias): string
{
    $periodicidade = (string)($agendamento['periodicidade'] ?? '');
    $horario = !empty($agendamento['horario']) ? substr((string)$agendamento['horario'], 0, 5) : '--:--';

    if ($periodicidade === 'SEMANAL') {
        $diasAgendamento = array_filter(array_map('intval', explode(',', (string)($agendamento['dias_semana'] ?? ''))));
        $diasTexto = [];
        foreach ($diasAgendamento as $dia) {
            if (isset($nomesDias[$dia])) {
                $diasTexto[] = $nomesDias[$dia];
            }
        }

        return 'Semanal' . (!empty($diasTexto) ? ' ' . implode(', ', $diasTexto) : '') . ' as ' . $horario;
    }

    if ($periodicidade === 'MENSAL') {
        $diaMes = !empty($agendamento['dia_mes']) ? ' dia ' . (int)$agendamento['dia_mes'] : '';
        return 'Mensal' . $diaMes . ' as ' . $horario;
    }

    return 'Diario as ' . $horario;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf($csrf);
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'salvar_config') {
            $stmt = $pdo_master->prepare("
                INSERT INTO whatsapp_config (id, empresa_id, nome, token, api_base_url, ativo)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    empresa_id = VALUES(empresa_id),
                    nome = VALUES(nome),
                    token = VALUES(token),
                    api_base_url = VALUES(api_base_url),
                    ativo = VALUES(ativo)
            ");
            $stmt->execute([
                $empresaId,
                $empresaId,
                postValue('nome', 'Principal'),
                postValue('token'),
                postValue('api_base_url', 'https://api-whatsapp.wascript.com.br/api/enviar-texto'),
                postValue('ativo', 'S') === 'S' ? 'S' : 'N',
            ]);

            $stmtToken = $pdo_master->prepare("SELECT agendamento_token FROM whatsapp_config WHERE empresa_id = ? LIMIT 1");
            $stmtToken->execute([$empresaId]);
            if (trim((string)$stmtToken->fetchColumn()) === '') {
                $tokenAgenda = function_exists('random_bytes') ? bin2hex(random_bytes(24)) : md5(uniqid('', true));
                $stmtToken = $pdo_master->prepare("UPDATE whatsapp_config SET agendamento_token = ? WHERE empresa_id = ?");
                $stmtToken->execute([$tokenAgenda, $empresaId]);
            }

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
                $stmt = $pdo_master->prepare("UPDATE whatsapp_destinatarios SET nome = ?, tipo = ?, numero = ?, ativo = ? WHERE id = ? AND empresa_id = ?");
                $dados[] = $id;
                $dados[] = $empresaId;
                $stmt->execute($dados);
                $alerta = 'Destinatario atualizado.';
            } else {
                $stmt = $pdo_master->prepare("INSERT INTO whatsapp_destinatarios (empresa_id, nome, tipo, numero, ativo) VALUES (?, ?, ?, ?, ?)");
                array_unshift($dados, $empresaId);
                $stmt->execute($dados);
                $alerta = 'Destinatario cadastrado.';
            }
        }

        if ($acao === 'salvar_mensagem') {
            $id = (int)($_POST['id'] ?? 0);
            $categoria = postValue('categoria', 'Geral');
            $titulo = postValue('titulo');
            $descricao = postValue('descricao');
            $conteudo = trim((string)($_POST['conteudo'] ?? ''));
            $ativo = postValue('ativo_mensagem', 'S') === 'S' ? 'S' : 'N';

            if ($titulo === '' || $conteudo === '') {
                throw new Exception('Informe titulo e conteudo da mensagem.');
            }

            if ($id > 0) {
                $stmt = $pdo_master->prepare("UPDATE whatsapp_mensagens SET categoria = ?, titulo = ?, descricao = ?, conteudo = ?, ativo = ? WHERE id = ? AND empresa_id = ?");
                $stmt->execute([$categoria, $titulo, $descricao, $conteudo, $ativo, $id, $empresaId]);
                $alerta = 'Mensagem atualizada.';
            } else {
                $stmt = $pdo_master->prepare("INSERT INTO whatsapp_mensagens (empresa_id, categoria, titulo, descricao, conteudo, ativo) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$empresaId, $categoria, $titulo, $descricao, $conteudo, $ativo]);
                $alerta = 'Mensagem cadastrada.';
            }
        }

        if ($acao === 'enviar_mensagem') {
            $mensagemId = (int)($_POST['mensagem_id'] ?? 0);
            $destinatariosIds = array_map('intval', $_POST['destinatarios'] ?? []);

            if ($mensagemId <= 0 || empty($destinatariosIds)) {
                throw new Exception('Selecione uma mensagem e pelo menos um destinatario.');
            }

            $config = whatsappConfig($pdo_master, $empresaId);
            if (!$config || $config['ativo'] !== 'S') {
                throw new Exception('A configuracao do WhatsApp esta inativa.');
            }

            $stmt = $pdo_master->prepare("SELECT * FROM whatsapp_mensagens WHERE id = ? AND empresa_id = ? AND ativo = 'S'");
            $stmt->execute([$mensagemId, $empresaId]);
            $mensagem = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mensagem) {
                throw new Exception('Mensagem ativa nao encontrada.');
            }

            $placeholders = implode(',', array_fill(0, count($destinatariosIds), '?'));
            $stmt = $pdo_master->prepare("SELECT * FROM whatsapp_destinatarios WHERE empresa_id = ? AND ativo = 'S' AND id IN ($placeholders)");
            $stmt->execute(array_merge([$empresaId], $destinatariosIds));
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
            $origemMensagem = postValue('origem_mensagem', 'TEXTO') === 'SISTEMA' ? 'SISTEMA' : 'TEXTO';
            $geradorSistema = postValue('gerador_sistema');
            $mensagemId = (int)($_POST['mensagem_id'] ?? 0);
            $mensagemId = $mensagemId > 0 ? $mensagemId : null;
            if ($origemMensagem === 'SISTEMA') {
                $mensagemId = null;
                if (!array_key_exists($geradorSistema, whatsappGeradoresSistema())) {
                    throw new Exception('Selecione um gerador de mensagem do sistema.');
                }
            } else {
                $geradorSistema = null;
            }
            $ativo = postValue('ativo_rotina', 'S') === 'S' ? 'S' : 'N';
            $duplicidade = postValue('evitar_duplicidade_diaria', 'N') === 'S' ? 'S' : 'N';
            $periodicidade = postValue('periodicidade', 'MANUAL');
            if (!in_array($periodicidade, ['MANUAL', 'DIARIO', 'SEMANAL', 'MENSAL'], true)) {
                $periodicidade = 'MANUAL';
            }
            $horario = postValue('horario');
            $horario = $horario !== '' ? $horario : null;
            $diasSemana = array_values(array_intersect(array_map('intval', $_POST['dias_semana'] ?? []), [1, 2, 3, 4, 5, 6, 7]));
            $diasSemanaSql = !empty($diasSemana) ? implode(',', $diasSemana) : null;
            $diaMes = (int)($_POST['dia_mes'] ?? 0);
            $diaMes = $diaMes > 0 ? max(1, min(31, $diaMes)) : null;
            $destinatariosIds = array_values(array_unique(array_map('intval', $_POST['rotina_destinatarios'] ?? [])));

            if ($codigo === '' || $nome === '') {
                throw new Exception('Informe codigo e nome da rotina.');
            }

            if ($id > 0) {
                $stmt = $pdo_master->prepare("
                    UPDATE whatsapp_rotinas
                    SET codigo = ?, nome = ?, descricao = ?, mensagem_id = ?, origem_mensagem = ?, gerador_sistema = ?, ativo = ?, evitar_duplicidade_diaria = ?,
                        periodicidade = ?, horario = ?, dias_semana = ?, dia_mes = ?
                    WHERE id = ? AND empresa_id = ?
                ");
                $stmt->execute([$codigo, $nome, $descricao, $mensagemId, $origemMensagem, $geradorSistema, $ativo, $duplicidade, $periodicidade, $horario, $diasSemanaSql, $diaMes, $id, $empresaId]);
                $rotinaId = $id;
                $alerta = 'Rotina atualizada.';
            } else {
                $stmt = $pdo_master->prepare("
                    INSERT INTO whatsapp_rotinas
                        (empresa_id, codigo, nome, descricao, mensagem_id, origem_mensagem, gerador_sistema, ativo, evitar_duplicidade_diaria, periodicidade, horario, dias_semana, dia_mes)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$empresaId, $codigo, $nome, $descricao, $mensagemId, $origemMensagem, $geradorSistema, $ativo, $duplicidade, $periodicidade, $horario, $diasSemanaSql, $diaMes]);
                $rotinaId = (int)$pdo_master->lastInsertId();
                $alerta = 'Rotina cadastrada.';
            }

            whatsappAtualizarProximaExecucao($pdo_master, $rotinaId);

            $stmt = $pdo_master->prepare("DELETE rd FROM whatsapp_rotina_destinatarios rd INNER JOIN whatsapp_rotinas r ON r.id = rd.rotina_id WHERE rd.rotina_id = ? AND r.empresa_id = ?");
            $stmt->execute([$rotinaId, $empresaId]);

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
            $stmt = $pdo_master->prepare("SELECT * FROM whatsapp_rotinas WHERE id = ? AND empresa_id = ?");
            $stmt->execute([$rotinaId, $empresaId]);
            $rotina = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rotina) {
                throw new Exception('Rotina nao encontrada.');
            }

            list($mensagem, $mensagemId) = whatsappMensagemRotina($pdo_master, $rotina);

            $resultado = whatsappEnviarRotina($pdo_master, $rotina, $mensagem, $mensagemId, $_SESSION['usuario_id'] ?? null);
            $alerta = "Rotina enviada: {$resultado['ok']} OK, {$resultado['falha']} erro(s).";
        }

        if ($acao === 'salvar_agendamento') {
            $rotinaId = (int)($_POST['rotina_id'] ?? 0);
            $periodicidade = postValue('periodicidade_agendamento', 'DIARIO');
            if (!in_array($periodicidade, ['DIARIO', 'SEMANAL', 'MENSAL'], true)) {
                $periodicidade = 'DIARIO';
            }
            $horario = postValue('horario_agendamento');
            if ($rotinaId <= 0 || $horario === '') {
                throw new Exception('Informe rotina e horario do agendamento.');
            }
            $diasSemana = array_values(array_intersect(array_map('intval', $_POST['dias_semana_agendamento'] ?? []), [1, 2, 3, 4, 5, 6, 7]));
            $diasSemanaSql = !empty($diasSemana) ? implode(',', $diasSemana) : null;
            $diaMes = (int)($_POST['dia_mes_agendamento'] ?? 0);
            $diaMes = $diaMes > 0 ? max(1, min(31, $diaMes)) : null;

            $stmt = $pdo_master->prepare("
                INSERT INTO whatsapp_rotina_agendamentos
                    (rotina_id, periodicidade, horario, dias_semana, dia_mes, ativo, proxima_execucao)
                SELECT id, ?, ?, ?, ?, 'S', NULL
                FROM whatsapp_rotinas
                WHERE id = ?
                  AND empresa_id = ?
            ");
            $stmt->execute([$periodicidade, $horario, $diasSemanaSql, $diaMes, $rotinaId, $empresaId]);
            whatsappAtualizarProximaAgendamento($pdo_master, (int)$pdo_master->lastInsertId());
            $alerta = 'Agendamento cadastrado.';
        }

        if ($acao === 'excluir_agendamento') {
            $agendamentoId = (int)($_POST['agendamento_id'] ?? 0);
            if ($agendamentoId <= 0) {
                throw new Exception('Agendamento nao informado.');
            }
            $stmt = $pdo_master->prepare("
                DELETE a
                FROM whatsapp_rotina_agendamentos a
                INNER JOIN whatsapp_rotinas r ON r.id = a.rotina_id
                WHERE a.id = ? AND r.empresa_id = ?
            ");
            $stmt->execute([$agendamentoId, $empresaId]);
            $alerta = 'Agendamento removido.';
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

$config = whatsappConfig($pdo_master, $empresaId);
$geradoresSistema = whatsappGeradoresSistema();
$stmt = $pdo_master->prepare("SELECT * FROM whatsapp_destinatarios WHERE empresa_id = ? ORDER BY ativo DESC, tipo, nome");
$stmt->execute([$empresaId]);
$destinatarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo_master->prepare("SELECT * FROM whatsapp_mensagens WHERE empresa_id = ? ORDER BY ativo DESC, categoria, titulo");
$stmt->execute([$empresaId]);
$mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo_master->prepare("
    SELECT r.*, m.titulo AS mensagem_titulo, m.categoria AS mensagem_categoria, m.descricao AS mensagem_descricao
    FROM whatsapp_rotinas r
    LEFT JOIN whatsapp_mensagens m ON m.id = r.mensagem_id
       AND m.empresa_id = r.empresa_id
    WHERE r.empresa_id = ?
    ORDER BY r.ativo DESC, r.nome
");
$stmt->execute([$empresaId]);
$rotinas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$rotinaDestinatarios = [];
$stmt = $pdo_master->prepare("
    SELECT rd.rotina_id, rd.destinatario_id
    FROM whatsapp_rotina_destinatarios rd
    INNER JOIN whatsapp_rotinas r ON r.id = rd.rotina_id
    WHERE r.empresa_id = ?
");
$stmt->execute([$empresaId]);
foreach ($stmt as $rd) {
    $rotinaDestinatarios[(int)$rd['rotina_id']][] = (int)$rd['destinatario_id'];
}
$agendamentosRotina = [];
$stmt = $pdo_master->prepare("
    SELECT a.*
    FROM whatsapp_rotina_agendamentos a
    INNER JOIN whatsapp_rotinas r ON r.id = a.rotina_id
    WHERE r.empresa_id = ?
    ORDER BY a.rotina_id, a.ativo DESC, a.proxima_execucao IS NULL, a.proxima_execucao, a.horario
");
$stmt->execute([$empresaId]);
foreach ($stmt as $ag) {
    $agendamentosRotina[(int)$ag['rotina_id']][] = $ag;
}
$stmt = $pdo_master->prepare("
    SELECT e.*, m.titulo, r.nome AS rotina_nome
    FROM whatsapp_envios e
    LEFT JOIN whatsapp_mensagens m ON m.id = e.mensagem_id AND m.empresa_id = e.empresa_id
    LEFT JOIN whatsapp_rotinas r ON r.id = e.rotina_id AND r.empresa_id = e.empresa_id
    WHERE e.empresa_id = ?
    ORDER BY e.enviado_em DESC, e.id DESC
    LIMIT 50
");
$stmt->execute([$empresaId]);
$historico = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cronUrl = '';
if (!empty($config['agendamento_token'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'www.superdunga.com.br';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $cronUrl = $scheme . '://' . $host . $basePath . '/executar_agendamentos.php?token=' . urlencode($config['agendamento_token']);
}

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

<?php if ($cronUrl): ?>
    <div class="alert alert-info">
        <div class="fw-semibold mb-1">URL do agendador interno</div>
        <div class="small mb-2">
            Este token e gerado pelo SuperDunga para o cron executar as mensagens agendadas. Ele nao e o token da API Waseller.
        </div>
        <code class="d-block text-break"><?= htmlspecialchars($cronUrl) ?></code>
    </div>
<?php endif; ?>

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
                        <label class="form-label">Token da API Waseller</label>
                        <input type="text" name="token" class="form-control font-monospace" value="<?= htmlspecialchars($config['token'] ?? '') ?>" required>
                        <div class="form-text">
                            Use aqui o token gerado no painel da Waseller para envio das mensagens.
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">URL base da API Waseller</label>
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
                <label class="form-label">Origem</label>
                <select name="origem_mensagem" class="form-select">
                    <option value="TEXTO">Texto cadastrado</option>
                    <option value="SISTEMA">Gerada pelo sistema</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Mensagem cadastrada</label>
                <select name="mensagem_id" class="form-select">
                    <option value="">Sem mensagem fixa</option>
                    <?php foreach ($mensagens as $m): ?>
                        <option value="<?= (int)$m['id'] ?>">
                            <?= htmlspecialchars(($m['categoria'] ?? 'Geral') . ' - ' . $m['titulo'] . (!empty($m['descricao']) ? ' (' . $m['descricao'] . ')' : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Gerador do sistema</label>
                <select name="gerador_sistema" class="form-select">
                    <option value="">Nao usar</option>
                    <?php foreach ($geradoresSistema as $codigoGerador => $gerador): ?>
                        <option value="<?= htmlspecialchars($codigoGerador) ?>">
                            <?= htmlspecialchars($gerador['nome'] . ' - ' . $gerador['arquivo']) ?>
                        </option>
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
            <div class="col-md-3">
                <label class="form-label">Periodicidade</label>
                <select name="periodicidade" class="form-select">
                    <option value="MANUAL">Manual</option>
                    <option value="DIARIO">Diaria</option>
                    <option value="SEMANAL">Semanal</option>
                    <option value="MENSAL">Mensal</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Horario</label>
                <input type="time" name="horario" class="form-control" value="08:00">
            </div>
            <div class="col-md-2">
                <label class="form-label">Dia do mes</label>
                <input type="number" name="dia_mes" class="form-control" min="1" max="31" placeholder="1-31">
            </div>
            <div class="col-md-5">
                <label class="form-label">Dias da semana</label>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sab', 7 => 'Dom'] as $dia => $nomeDia): ?>
                        <label class="border rounded px-2 py-1">
                            <input type="checkbox" name="dias_semana[]" value="<?= $dia ?>">
                            <?= $nomeDia ?>
                        </label>
                    <?php endforeach; ?>
                </div>
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
                        <th>Agenda</th>
                        <th>Proximo envio</th>
                        <th>Status</th>
                        <th>Ultima execucao</th>
                        <th class="text-center">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rotinas)): ?>
                        <tr><td colspan="8" class="text-muted text-center">Nenhuma rotina cadastrada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rotinas as $r): ?>
                        <?php $selecionados = $rotinaDestinatarios[(int)$r['id']] ?? []; ?>
                        <?php
                            $diasSelecionados = array_filter(array_map('intval', explode(',', (string)($r['dias_semana'] ?? ''))));
                            $nomesDias = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sab', 7 => 'Dom'];
                            $agendamentos = $agendamentosRotina[(int)$r['id']] ?? [];
                            $agendamentosAtivos = array_values(array_filter($agendamentos, function ($ag) {
                                return ($ag['ativo'] ?? 'N') === 'S';
                            }));
                            $partesAgenda = [];
                            foreach ($agendamentosAtivos as $agendamentoAtivo) {
                                $partesAgenda[] = formatarAgendaWhatsapp($agendamentoAtivo, $nomesDias);
                            }
                            $agenda = empty($partesAgenda)
                                ? 'Sem agendamento ativo'
                                : count($partesAgenda) . ' agendamento(s): ' . implode('; ', $partesAgenda);
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($r['nome']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($r['codigo']) ?></small>
                            </td>
                            <td>
                                <?php if (($r['origem_mensagem'] ?? 'TEXTO') === 'SISTEMA'): ?>
                                    <?php $gerador = $geradoresSistema[$r['gerador_sistema'] ?? ''] ?? null; ?>
                                    <?php if ($gerador): ?>
                                        <strong><?= htmlspecialchars($gerador['nome']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($gerador['descricao']) ?></small>
                                        <br><code><?= htmlspecialchars($gerador['arquivo']) ?></code>
                                    <?php else: ?>
                                        <span class="text-danger">Gerador nao encontrado</span>
                                    <?php endif; ?>
                                <?php elseif (!empty($r['mensagem_titulo'])): ?>
                                    <strong><?= htmlspecialchars(($r['mensagem_categoria'] ?? 'Geral') . ' - ' . $r['mensagem_titulo']) ?></strong>
                                    <?php if (!empty($r['mensagem_descricao'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($r['mensagem_descricao']) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($r['codigo'] === 'resumo_diario' ? 'Gerada pelo sistema' : 'Sem mensagem') ?>
                                <?php endif; ?>
                            </td>
                            <td><?= count($selecionados) ?> vinculado(s)</td>
                            <td><?= htmlspecialchars($agenda) ?></td>
                            <td>
                                <?php if (empty($agendamentos)): ?>
                                    -
                                <?php else: ?>
                                    <?php
                                        $proximos = array_values(array_filter(array_column($agendamentosAtivos, 'proxima_execucao')));
                                        sort($proximos);
                                    ?>
                                    <?= !empty($proximos) ? date('d/m/Y H:i', strtotime($proximos[0])) : '-' ?>
                                <?php endif; ?>
                            </td>
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
                            <td colspan="8">
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
                                        <select name="origem_mensagem" class="form-select">
                                            <option value="TEXTO" <?= ($r['origem_mensagem'] ?? 'TEXTO') === 'TEXTO' ? 'selected' : '' ?>>Texto cadastrado</option>
                                            <option value="SISTEMA" <?= ($r['origem_mensagem'] ?? 'TEXTO') === 'SISTEMA' ? 'selected' : '' ?>>Gerada pelo sistema</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select name="mensagem_id" class="form-select">
                                            <option value="">Sem mensagem fixa</option>
                                            <?php foreach ($mensagens as $m): ?>
                                                <option value="<?= (int)$m['id'] ?>" <?= (int)($r['mensagem_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars(($m['categoria'] ?? 'Geral') . ' - ' . $m['titulo'] . (!empty($m['descricao']) ? ' (' . $m['descricao'] . ')' : '')) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <select name="gerador_sistema" class="form-select">
                                            <option value="">Nao usar</option>
                                            <?php foreach ($geradoresSistema as $codigoGerador => $gerador): ?>
                                                <option value="<?= htmlspecialchars($codigoGerador) ?>" <?= ($r['gerador_sistema'] ?? '') === $codigoGerador ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($gerador['nome'] . ' - ' . $gerador['arquivo']) ?>
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
                                    <div class="col-md-3">
                                        <select name="periodicidade" class="form-select">
                                            <option value="MANUAL" <?= $r['periodicidade'] === 'MANUAL' ? 'selected' : '' ?>>Manual</option>
                                            <option value="DIARIO" <?= $r['periodicidade'] === 'DIARIO' ? 'selected' : '' ?>>Diaria</option>
                                            <option value="SEMANAL" <?= $r['periodicidade'] === 'SEMANAL' ? 'selected' : '' ?>>Semanal</option>
                                            <option value="MENSAL" <?= $r['periodicidade'] === 'MENSAL' ? 'selected' : '' ?>>Mensal</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="time" name="horario" class="form-control" value="<?= htmlspecialchars(substr((string)($r['horario'] ?? ''), 0, 5)) ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" name="dia_mes" class="form-control" min="1" max="31" value="<?= htmlspecialchars((string)($r['dia_mes'] ?? '')) ?>" placeholder="Dia do mes">
                                    </div>
                                    <div class="col-md-5">
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sab', 7 => 'Dom'] as $dia => $nomeDia): ?>
                                                <label class="border rounded px-2 py-1">
                                                    <input type="checkbox" name="dias_semana[]" value="<?= $dia ?>" <?= in_array($dia, $diasSelecionados, true) ? 'checked' : '' ?>>
                                                    <?= $nomeDia ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
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

                                <div class="border rounded p-3 mt-3">
                                    <h3 class="h6 fw-bold mb-3">Agendamentos desta rotina</h3>

                                    <form method="post" class="row g-2 mb-3">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                        <input type="hidden" name="acao" value="salvar_agendamento">
                                        <input type="hidden" name="rotina_id" value="<?= (int)$r['id'] ?>">

                                        <div class="col-md-3">
                                            <select name="periodicidade_agendamento" class="form-select">
                                                <option value="DIARIO">Diario</option>
                                                <option value="SEMANAL">Semanal</option>
                                                <option value="MENSAL">Mensal</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="time" name="horario_agendamento" class="form-control" required>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="number" name="dia_mes_agendamento" class="form-control" min="1" max="31" placeholder="Dia do mes">
                                        </div>
                                        <div class="col-md-5">
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sab', 7 => 'Dom'] as $dia => $nomeDia): ?>
                                                    <label class="border rounded px-2 py-1">
                                                        <input type="checkbox" name="dias_semana_agendamento[]" value="<?= $dia ?>">
                                                        <?= $nomeDia ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-outline-primary w-100">Adicionar agendamento</button>
                                        </div>
                                    </form>

                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Periodicidade</th>
                                                    <th>Horario</th>
                                                    <th>Dias</th>
                                                    <th>Proximo envio</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($agendamentos)): ?>
                                                    <tr><td colspan="5" class="text-muted text-center">Nenhum agendamento cadastrado.</td></tr>
                                                <?php endif; ?>
                                                <?php foreach ($agendamentos as $ag): ?>
                                                    <?php
                                                        $diasAg = array_filter(array_map('intval', explode(',', (string)($ag['dias_semana'] ?? ''))));
                                                        $diasTexto = [];
                                                        foreach ($diasAg as $diaAg) {
                                                            if (isset($nomesDias[$diaAg])) {
                                                                $diasTexto[] = $nomesDias[$diaAg];
                                                            }
                                                        }
                                                        if ($ag['periodicidade'] === 'MENSAL' && !empty($ag['dia_mes'])) {
                                                            $diasTexto[] = 'Dia ' . (int)$ag['dia_mes'];
                                                        }
                                                    ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($ag['periodicidade']) ?></td>
                                                        <td><?= htmlspecialchars(substr((string)$ag['horario'], 0, 5)) ?></td>
                                                        <td><?= htmlspecialchars(!empty($diasTexto) ? implode(', ', $diasTexto) : '-') ?></td>
                                                        <td><?= $ag['proxima_execucao'] ? date('d/m/Y H:i', strtotime($ag['proxima_execucao'])) : '-' ?></td>
                                                        <td class="text-end">
                                                            <form method="post" onsubmit="return confirm('Remover este agendamento?')">
                                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                                                <input type="hidden" name="acao" value="excluir_agendamento">
                                                                <input type="hidden" name="agendamento_id" value="<?= (int)$ag['id'] ?>">
                                                                <button class="btn btn-sm btn-outline-danger">Remover</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
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
                        <label class="form-label">Categoria</label>
                        <input type="text" name="categoria" class="form-control" value="Geral" placeholder="Ex: Caixa, Vendas, Financeiro" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Titulo</label>
                        <input type="text" name="titulo" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Descricao curta</label>
                        <input type="text" name="descricao" class="form-control" placeholder="Ex: Alerta de divergencia acima do limite">
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
                                        <?= htmlspecialchars(($m['categoria'] ?? 'Geral') . ' - ' . $m['titulo']) ?>
                                        <span class="badge ms-2 bg-<?= $m['ativo'] === 'S' ? 'success' : 'secondary' ?>">
                                            <?= $m['ativo'] === 'S' ? 'Ativa' : 'Inativa' ?>
                                        </span>
                                    </button>
                                </h3>
                                <div id="mensagem<?= (int)$m['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#mensagensAccordion">
                                    <div class="accordion-body">
                                        <?php if (!empty($m['descricao'])): ?>
                                            <div class="text-muted small mb-2"><?= htmlspecialchars($m['descricao']) ?></div>
                                        <?php endif; ?>
                                        <div class="border rounded p-3 bg-light mb-3" style="white-space: pre-wrap;"><?= htmlspecialchars($m['conteudo']) ?></div>

                                        <form method="post" class="row g-2 mb-3">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="acao" value="salvar_mensagem">
                                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                            <div class="col-md-4">
                                                <input type="text" name="categoria" class="form-control" value="<?= htmlspecialchars($m['categoria'] ?? 'Geral') ?>" required>
                                            </div>
                                            <div class="col-md-8">
                                                <input type="text" name="titulo" class="form-control" value="<?= htmlspecialchars($m['titulo']) ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <select name="ativo_mensagem" class="form-select">
                                                    <option value="S" <?= $m['ativo'] === 'S' ? 'selected' : '' ?>>Ativa</option>
                                                    <option value="N" <?= $m['ativo'] === 'N' ? 'selected' : '' ?>>Inativa</option>
                                                </select>
                                            </div>
                                            <div class="col-md-8">
                                                <input type="text" name="descricao" class="form-control" value="<?= htmlspecialchars($m['descricao'] ?? '') ?>" placeholder="Descricao curta">
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
