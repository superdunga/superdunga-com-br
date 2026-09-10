<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/auth.php';
require __DIR__ . '/../whatsapp/whatsapp_lib.php';
require __DIR__ . '/whatsapp_operacional_lib.php';

exigirNivel('MASTER');
$paginaOperacional = $paginaOperacional ?? 'painel';
$paginasOperacionais = ['painel','rotinas','fechamento','mensagem','envios','historico','configuracoes'];
if (!in_array($paginaOperacional, $paginasOperacionais, true)) {
    $paginaOperacional = 'painel';
}
$secaoSolicitada = (string)($_GET['secao'] ?? 'execucao');
$secaoRotina = in_array($secaoSolicitada, ['execucao','fila','historico','configuracao'], true) ? $secaoSolicitada : 'execucao';
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
            $configExistente = whatsappOperacionalConfig($pdo_master, $empresaId);
            if ($token === '' && $configExistente) {
                $token = (string)$configExistente['evolution_token'];
            }
            $webhookToken = (string)($configExistente['webhook_token'] ?? '');
            if ($webhookToken === '') {
                $webhookToken = bin2hex(random_bytes(24));
            }
            if ($nome === '' || $url === '' || $token === '' || $instancia === '') {
                throw new Exception('Preencha todos os dados da instancia operacional.');
            }
            $stmt = $pdo_master->prepare("
                INSERT INTO whatsapp_operacional_config (empresa_id, nome, evolution_token, evolution_api_base_url, instancia, webhook_token, ativo)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE nome=VALUES(nome), evolution_token=VALUES(evolution_token),
                    evolution_api_base_url=VALUES(evolution_api_base_url), instancia=VALUES(instancia), ativo=VALUES(ativo)
            ");
            $stmt->execute([$empresaId, $nome, $token, $url, $instancia, $webhookToken, ($_POST['ativo'] ?? 'S') === 'S' ? 'S' : 'N']);
            $alerta = 'Configuracao operacional salva.';
        } elseif ($acao === 'salvar_automacao') {
            $diaUtil = max(1, min(10, (int)($_POST['fechamento_dia_util'] ?? 3)));
            $horario = trim((string)($_POST['fechamento_horario'] ?? '10:00'));
            $intervalo = max(10, min(3600, (int)($_POST['fechamento_intervalo_segundos'] ?? 30)));
            $proximaData = trim((string)($_POST['fechamento_proxima_data'] ?? ''));
            if (!preg_match('/^\d{2}:\d{2}$/', $horario)) {
                throw new Exception('Informe um horario valido para o fechamento.');
            }
            if ($proximaData !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $proximaData)) {
                throw new Exception('Informe uma data excepcional valida.');
            }
            $stmt = $pdo_master->prepare("UPDATE whatsapp_operacional_config SET fechamento_ativo=?,fechamento_dia_util=?,fechamento_horario=?,fechamento_intervalo_segundos=?,fechamento_proxima_data=? WHERE empresa_id=?");
            $stmt->execute([($_POST['fechamento_ativo'] ?? 'N') === 'S' ? 'S' : 'N',$diaUtil,$horario . ':00',$intervalo,$proximaData ?: null,$empresaId]);
            if ($stmt->rowCount() === 0 && !whatsappOperacionalConfig($pdo_master, $empresaId)) {
                throw new Exception('Salve primeiro a configuracao da instancia operacional.');
            }
            $alerta = 'Automacao do fechamento mensal salva.';
        } elseif ($acao === 'reprocessar_erros') {
            $rotinaFila = trim((string)($_POST['rotina_codigo'] ?? ''));
            $sql = "UPDATE whatsapp_operacional_fila SET status='AGUARDANDO',tentativas=0,proxima_tentativa=NOW(),erro=NULL WHERE empresa_id=? AND status='ERRO'";
            $paramsFila = [$empresaId];
            if ($rotinaFila !== '') { $sql .= ' AND rotina_codigo=?'; $paramsFila[] = $rotinaFila; }
            $stmt = $pdo_master->prepare($sql);
            $stmt->execute($paramsFila);
            $alerta = $stmt->rowCount() . ' envio(s) preparado(s) para nova tentativa.';
        } elseif ($acao === 'cancelar_fila') {
            $rotinaFila = trim((string)($_POST['rotina_codigo'] ?? ''));
            $sql = "UPDATE whatsapp_operacional_fila SET status='CANCELADO',erro='Cancelado pelo operador' WHERE empresa_id=? AND status IN ('AGUARDANDO','ERRO')";
            $paramsFila = [$empresaId];
            if ($rotinaFila !== '') { $sql .= ' AND rotina_codigo=?'; $paramsFila[] = $rotinaFila; }
            $stmt = $pdo_master->prepare($sql);
            $stmt->execute($paramsFila);
            $alerta = $stmt->rowCount() . ' envio(s) pendente(s) cancelado(s).';
        } elseif ($acao === 'preparar_fila_agora') {
            $configAtual = whatsappOperacionalConfig($pdo_master, $empresaId);
            if (!$configAtual) {
                throw new Exception('Configure primeiro a instancia operacional.');
            }
            if (!whatsappOperacionalSincronizacaoAtualizada($pdo_master, $empresaId)) {
                throw new Exception('Aguarde uma sincronizacao CR001 bem-sucedida antes de preparar a fila.');
            }
            $competenciaAtual = (new DateTimeImmutable('first day of previous month'))->format('Y-m-01');
            $criados = whatsappOperacionalPrepararFila($pdo_master, $configAtual, $competenciaAtual, new DateTimeImmutable('now'));
            $pdo_master->prepare("UPDATE whatsapp_operacional_config SET fechamento_ultima_competencia=? WHERE empresa_id=?")->execute([$competenciaAtual,$empresaId]);
            $alerta = 'Fila da competencia anterior preparada: ' . $criados . ' novo(s) cliente(s).';
        } elseif ($acao === 'testar') {
            $configExistente = whatsappOperacionalConfig($pdo_master, $empresaId);
            $tokenTeste = trim((string)($_POST['evolution_token'] ?? ''));
            if ($tokenTeste === '' && $configExistente) {
                $tokenTeste = (string)$configExistente['evolution_token'];
            }
            $resultado = whatsappOperacionalTestar([
                'evolution_api_base_url' => $_POST['evolution_api_base_url'] ?? '',
                'evolution_token' => $tokenTeste,
                'instancia' => $_POST['instancia'] ?? '',
            ]);
            if (!$resultado['conectado']) {
                throw new Exception('Evolution acessivel, mas a instancia esta ' . $resultado['estado'] . '.');
            }
            $alerta = 'Instancia operacional conectada.';
        } elseif ($acao === 'configurar_webhook') {
            $configWebhook = whatsappOperacionalConfig($pdo_master, $empresaId);
            if (!$configWebhook || empty($configWebhook['webhook_token'])) {
                throw new Exception('Salve primeiro a configuracao da instancia operacional.');
            }
            $resultado = whatsappOperacionalConfigurarWebhook($configWebhook);
            if (!$resultado['ok']) {
                throw new Exception('Nao foi possivel configurar o webhook: ' . $resultado['erro']);
            }
            $pdo_master->prepare("UPDATE whatsapp_operacional_config SET webhook_configurado_em=NOW() WHERE empresa_id=?")->execute([$empresaId]);
            $alerta = 'Webhook de confirmacao configurado na Evolution.';
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
        } elseif ($acao === 'baixar_previas') {
            $fim = trim((string)($_POST['data_fim'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) {
                throw new Exception('Informe uma data limite valida.');
            }
            $selecionados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['clientes_fechamento'] ?? [])))));
            if (empty($selecionados)) {
                throw new Exception('Selecione pelo menos um cliente para gerar a previa.');
            }
            $clientesPeriodo = whatsappOperacionalClientesFechamentoAte($pdo_master, $empresaId, $fim);
            $clientesPorId = [];
            foreach ($clientesPeriodo as $clientePeriodo) {
                $clientesPorId[(int)$clientePeriodo['CLICONTADOR']] = $clientePeriodo;
            }
            $empresaNome = whatsappNomeEmpresa($pdo_master, $empresaId);
            $diretorio = dirname(__DIR__, 2) . '/storage/whatsapp_fechamentos_operacionais/previas/' . bin2hex(random_bytes(8));
            if (!mkdir($diretorio, 0775, true) && !is_dir($diretorio)) {
                throw new Exception('Nao foi possivel preparar os arquivos de previa.');
            }
            $arquivosPrevias = [];
            foreach ($selecionados as $clicontador) {
                $cliente = $clientesPorId[$clicontador] ?? null;
                if (!$cliente) {
                    continue;
                }
                $titulos = whatsappOperacionalTitulosAte($pdo_master, $empresaId, $clicontador, $fim);
                if (empty($titulos)) {
                    continue;
                }
                $nomeArquivo = 'fechamento_' . $clicontador . '_ate_' . str_replace('-', '', $fim) . '.pdf';
                $arquivo = $diretorio . '/' . $nomeArquivo;
                whatsappOperacionalGerarPdf($arquivo, $empresaNome, $cliente, 'ATE', $fim, $titulos);
                $arquivosPrevias[$nomeArquivo] = $arquivo;
            }
            if (empty($arquivosPrevias)) {
                @rmdir($diretorio);
                throw new Exception('Nenhum PDF foi gerado para os clientes selecionados.');
            }
            if (count($arquivosPrevias) === 1) {
                reset($arquivosPrevias);
                $nomeDownload = (string)key($arquivosPrevias);
                $arquivoDownload = $arquivosPrevias[$nomeDownload];
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $nomeDownload . '"');
                header('Content-Length: ' . filesize($arquivoDownload));
                readfile($arquivoDownload);
                @unlink($arquivoDownload);
                @rmdir($diretorio);
                exit;
            }
            if (!class_exists('ZipArchive')) {
                foreach ($arquivosPrevias as $arquivo) {
                    @unlink($arquivo);
                }
                @rmdir($diretorio);
                throw new Exception('O servidor nao possui suporte para compactar multiplas previas.');
            }
            $nomeDownload = 'previas_fechamentos_ate_' . str_replace('-', '', $fim) . '.zip';
            $arquivoDownload = $diretorio . '/' . $nomeDownload;
            $zip = new ZipArchive();
            if ($zip->open($arquivoDownload, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new Exception('Nao foi possivel criar o arquivo com as previas.');
            }
            foreach ($arquivosPrevias as $nomeArquivo => $arquivo) {
                $zip->addFile($arquivo, $nomeArquivo);
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $nomeDownload . '"');
            header('Content-Length: ' . filesize($arquivoDownload));
            readfile($arquivoDownload);
            foreach ($arquivosPrevias as $arquivo) {
                @unlink($arquivo);
            }
            @unlink($arquivoDownload);
            @rmdir($diretorio);
            exit;
        } elseif ($acao === 'enviar_fechamentos') {
            $fim = trim((string)($_POST['data_fim'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) {
                throw new Exception('Informe uma data limite valida.');
            }
            if (!whatsappOperacionalSincronizacaoAtualizada($pdo_master, $empresaId)) {
                throw new Exception('Envio bloqueado: a sincronizacao CR001 nao foi atualizada nos ultimos 30 minutos.');
            }
            $inicio = $fim;
            $selecionados = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['clientes_fechamento'] ?? [])))));
            if (empty($selecionados)) {
                throw new Exception('Selecione pelo menos um cliente para envio.');
            }
            $configEnvio = whatsappOperacionalConfig($pdo_master, $empresaId);
            if (!$configEnvio || ($configEnvio['ativo'] ?? 'N') !== 'S') {
                throw new Exception('A instancia operacional desta empresa nao esta configurada e ativa.');
            }
            $clientesPeriodo = whatsappOperacionalClientesFechamentoAte($pdo_master, $empresaId, $fim);
            $clientesPorId = [];
            foreach ($clientesPeriodo as $clientePeriodo) {
                $clientesPorId[(int)$clientePeriodo['CLICONTADOR']] = $clientePeriodo;
            }
            $empresaNome = whatsappNomeEmpresa($pdo_master, $empresaId);
            $forcarReenvio = ($_POST['forcar_reenvio'] ?? 'N') === 'S';
            $ok = 0; $falha = 0; $ignorados = 0;
            foreach ($selecionados as $clicontador) {
                $cliente = $clientesPorId[$clicontador] ?? null;
                if (!$cliente || trim((string)$cliente['CELULAR']) === '') {
                    $ignorados++;
                    continue;
                }
                if (!$forcarReenvio && !empty($cliente['ultimo_envio'])) {
                    $ignorados++;
                    continue;
                }
                $titulos = whatsappOperacionalTitulosAte($pdo_master, $empresaId, $clicontador, $fim);
                if (empty($titulos)) {
                    $ignorados++;
                    continue;
                }
                $diretorio = dirname(__DIR__, 2) . '/storage/whatsapp_fechamentos_operacionais/empresa_' . $empresaId . '/' . str_replace('-', '_', $fim);
                $nomeArquivo = 'fechamento_' . $clicontador . '_ate_' . str_replace('-', '', $fim) . '.pdf';
                $arquivo = $diretorio . '/' . $nomeArquivo;
                whatsappOperacionalGerarPdf($arquivo, $empresaNome, $cliente, 'ATE', $fim, $titulos);
                $resultado = whatsappOperacionalEnviarDocumento($configEnvio, (string)$cliente['CELULAR'], $arquivo, $nomeArquivo, 'Relacao de compras em aberto ate ' . date('d/m/Y', strtotime($fim)));
                $stmtRegistro = $pdo_master->prepare("
                    INSERT INTO whatsapp_operacional_fechamentos
                        (empresa_id,clicontador,data_inicio,data_fim,quantidade_titulos,valor_aberto,arquivo,status,resposta_api,erro,usuario_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmtRegistro->execute([$empresaId,$clicontador,$inicio,$fim,count($titulos),array_sum(array_map(function($t){return (float)$t['VLRRESTANTE'];},$titulos)),$nomeArquivo,$resultado['ok']?'OK':'ERRO',$resultado['resposta'],$resultado['erro'],$_SESSION['usuario_id'] ?? null]);
                if ($resultado['ok']) { $ok++; } else { $falha++; }
            }
            $alerta = "Fechamentos processados: {$ok} enviado(s), {$falha} falha(s) e {$ignorados} ignorado(s).";
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$config = whatsappOperacionalConfig($pdo_master, $empresaId);
$sincronizacaoFechamentoAtualizada = whatsappOperacionalSincronizacaoAtualizada($pdo_master, $empresaId);
$competenciaAutomacao = (new DateTimeImmutable('first day of previous month'))->format('Y-m-01');
if (!empty($config['fechamento_ultima_competencia']) && (string)$config['fechamento_ultima_competencia'] >= $competenciaAutomacao) {
    $competenciaAutomacao = (new DateTimeImmutable('first day of this month'))->format('Y-m-01');
}
$dataLimiteAutomacao = (new DateTimeImmutable($competenciaAutomacao))->modify('last day of this month')->format('Y-m-d');
$proximoEnvioAutomacao = null;
if ($config) {
    $proximoEnvioAutomacao = whatsappOperacionalDataEnvio($pdo_master,$empresaId,$competenciaAutomacao,(int)($config['fechamento_dia_util'] ?? 3),(string)($config['fechamento_horario'] ?? '10:00:00'),$config['fechamento_proxima_data'] ?? null);
}
$sqlFila = "SELECT q.* FROM whatsapp_operacional_fila q WHERE q.empresa_id=?";
$paramsFila = [$empresaId];
if ($paginaOperacional === 'fechamento') { $sqlFila .= " AND q.rotina_codigo='fechamento_mensal_clientes'"; }
$filtroRotina = trim((string)($_GET['rotina'] ?? ''));
$filtroStatus = trim((string)($_GET['status'] ?? ''));
$filtroReferencia = trim((string)($_GET['referencia'] ?? ''));
if ($paginaOperacional === 'envios') {
    if ($filtroRotina !== '') { $sqlFila .= ' AND q.rotina_codigo=?'; $paramsFila[] = $filtroRotina; }
    if ($filtroStatus !== '') { $sqlFila .= ' AND q.status=?'; $paramsFila[] = $filtroStatus; }
    if ($filtroReferencia !== '') { $sqlFila .= ' AND q.referencia LIKE ?'; $paramsFila[] = '%' . $filtroReferencia . '%'; }
}
$sqlFila .= ' ORDER BY q.id DESC LIMIT 100';
$stmt = $pdo_master->prepare($sqlFila);
$stmt->execute($paramsFila);
$filaOperacional = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo_master->prepare("SELECT * FROM whatsapp_operacional_destinatarios WHERE empresa_id=? ORDER BY ativo DESC, tipo, nome");
$stmt->execute([$empresaId]);
$destinatarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo_master->prepare("SELECT * FROM whatsapp_operacional_envios WHERE empresa_id=? ORDER BY enviado_em DESC,id DESC LIMIT 50");
$stmt->execute([$empresaId]);
$historico = $stmt->fetchAll(PDO::FETCH_ASSOC);
$mesAnterior = new DateTime('first day of previous month');
$fimFechamento = trim((string)($_GET['data_fim'] ?? $mesAnterior->format('Y-m-t')));
$clientesFechamento = [];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fimFechamento)) {
    $clientesFechamento = whatsappOperacionalClientesFechamentoAte($pdo_master, $empresaId, $fimFechamento);
}
$stmt = $pdo_master->prepare("
    SELECT f.*, COALESCE(NULLIF(c.NOME,''),NULLIF(c.APELIDO,''),CONCAT('Cliente ',f.clicontador)) AS nome_cliente
    FROM whatsapp_operacional_fechamentos f
    LEFT JOIN armazem_cr002 c ON c.EMPRESA=f.empresa_id AND c.CLICONTADOR=f.clicontador
    WHERE f.empresa_id=? ORDER BY f.enviado_em DESC,f.id DESC LIMIT 50
");
$stmt->execute([$empresaId]);
$historicoFechamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$resumoFila = ['AGUARDANDO'=>0,'PROCESSANDO'=>0,'ACEITO'=>0,'ENTREGUE'=>0,'ERRO'=>0,'IGNORADO'=>0,'CANCELADO'=>0];
$stmt = $pdo_master->prepare("SELECT status,COUNT(*) quantidade FROM whatsapp_operacional_fila WHERE empresa_id=? AND referencia=(SELECT MAX(referencia) FROM whatsapp_operacional_fila WHERE empresa_id=?) GROUP BY status");
$stmt->execute([$empresaId,$empresaId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $itemResumo) {
    $resumoFila[(string)$itemResumo['status']] = (int)$itemResumo['quantidade'];
}
$stmt = $pdo_master->prepare("SELECT COUNT(*) total,SUM(CASE WHEN COALESCE(NULLIF(TRIM(c.CELULAR),''),'')='' THEN 1 ELSE 0 END) sem_celular FROM financeiro_clientes_whatsapp w LEFT JOIN armazem_cr002 c ON c.EMPRESA=w.empresa_id AND c.CLICONTADOR=w.clicontador WHERE w.empresa_id=? AND w.ativo_whatsapp='S'");
$stmt->execute([$empresaId]);
$resumoClientesFechamento = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'sem_celular'=>0];
$totalClientesFechamento = (int)$resumoClientesFechamento['total'];
$semCelularFechamento = (int)$resumoClientesFechamento['sem_celular'];
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

<?php if ($paginaOperacional === 'fechamento'): ?>
<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
    <div><div class="small text-muted"><a href="rotinas.php" class="text-decoration-none">Rotinas</a> / Fechamento mensal de clientes</div><h2 class="h4 mb-0">Fechamento mensal de clientes</h2></div>
    <a href="rotinas.php" class="btn btn-outline-secondary btn-sm">Voltar para rotinas</a>
</div>
<ul class="nav nav-tabs flex-nowrap overflow-auto mb-3">
    <li class="nav-item"><a class="nav-link text-nowrap <?= $secaoRotina==='execucao'?'active':'' ?>" href="fechamento_mensal.php?secao=execucao">Execucao</a></li>
    <li class="nav-item"><a class="nav-link text-nowrap <?= $secaoRotina==='fila'?'active':'' ?>" href="fechamento_mensal.php?secao=fila">Fila</a></li>
    <li class="nav-item"><a class="nav-link text-nowrap <?= $secaoRotina==='historico'?'active':'' ?>" href="fechamento_mensal.php?secao=historico">Historico</a></li>
    <li class="nav-item"><a class="nav-link text-nowrap <?= $secaoRotina==='configuracao'?'active':'' ?>" href="fechamento_mensal.php?secao=configuracao">Configuracao</a></li>
</ul>
<?php elseif ($paginaOperacional !== 'painel'): ?>
<div class="mb-3"><a href="index.php" class="btn btn-outline-secondary btn-sm">Voltar ao painel</a></div>
<?php endif; ?>

<?php if ($paginaOperacional === 'painel'): ?>
<div class="row g-3 mb-3">
    <div class="col-xl-3 col-md-6"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Proximo envio</div><div class="fs-5 fw-semibold mt-1"><?= ($config['fechamento_ativo']??'N')!=='S'?'Automacao inativa':($proximoEnvioAutomacao?htmlspecialchars($proximoEnvioAutomacao->format('d/m/Y H:i')):'Nao configurado') ?></div><div class="small text-muted">Competencia <?= htmlspecialchars(date('m/Y',strtotime($competenciaAutomacao))) ?></div></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Clientes aptos</div><div class="display-6 fw-semibold"><?= max(0,$totalClientesFechamento-$semCelularFechamento) ?></div><div class="small text-muted"><?= $totalClientesFechamento ?> marcado(s)</div></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Sem celular</div><div class="display-6 fw-semibold <?= $semCelularFechamento?'text-warning':'' ?>"><?= $semCelularFechamento ?></div></div></div></div>
    <div class="col-xl-3 col-md-6"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Aguardando na fila</div><div class="display-6 fw-semibold"><?= (int)$resumoFila['AGUARDANDO']+(int)$resumoFila['PROCESSANDO'] ?></div><div class="small <?= $resumoFila['ERRO']?'text-danger':'text-muted' ?>"><?= (int)$resumoFila['ERRO'] ?> erro(s)</div></div></div></div>
</div>
<div class="card shadow-sm mb-3"><div class="card-header"><h2 class="h5 mb-0">Situacao operacional</h2></div><div class="card-body"><div class="row g-3"><div class="col-md-4"><div class="fw-semibold">Instancia</div><div class="text-muted"><?= htmlspecialchars($config['instancia']??'Nao configurada') ?></div></div><div class="col-md-4"><div class="fw-semibold">Automacao mensal</div><div class="text-muted"><?= ($config['fechamento_ativo']??'N')==='S'?'Ativa':'Inativa' ?></div></div><div class="col-md-4"><div class="fw-semibold">Sincronizacao CR001</div><div class="<?= $sincronizacaoFechamentoAtualizada?'text-success':'text-warning' ?>"><?= $sincronizacaoFechamentoAtualizada?'Atualizada':'Aguardando atualizacao' ?></div></div></div><div class="mt-3"><a href="fechamento_mensal.php" class="btn btn-primary">Abrir fechamento mensal</a></div></div></div>
<div class="row g-3"><div class="col-xl-3 col-md-6"><div class="card shadow-sm h-100"><div class="card-body"><h2 class="h5">Mensagem avulsa</h2><p class="text-muted">Envie uma comunicacao operacional fora das rotinas.</p><a href="mensagem_avulsa.php" class="btn btn-outline-primary">Nova mensagem</a></div></div></div><div class="col-xl-3 col-md-6"><div class="card shadow-sm h-100"><div class="card-body"><h2 class="h5">Fila operacional</h2><p class="text-muted">Acompanhe os itens de todas as rotinas.</p><a href="envios.php" class="btn btn-outline-primary">Abrir fila</a></div></div></div><div class="col-xl-3 col-md-6"><div class="card shadow-sm h-100"><div class="card-body"><h2 class="h5">Historico</h2><p class="text-muted">Consulte rotinas e mensagens processadas.</p><a href="historico.php" class="btn btn-outline-primary">Abrir historico</a></div></div></div><div class="col-xl-3 col-md-6"><div class="card shadow-sm h-100"><div class="card-body"><h2 class="h5">Configuracoes gerais</h2><p class="text-muted">Instancia Evolution e destinatarios.</p><a href="configuracoes.php" class="btn btn-outline-primary">Abrir configuracoes</a></div></div></div></div>
<?php endif; ?>

<?php if ($paginaOperacional === 'configuracoes'): ?>
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
                    <div class="col-12"><label class="form-label">API Key da instancia</label><input type="password" name="evolution_token" class="form-control font-monospace" value="" placeholder="<?= $config ? 'Configurada - preencha somente para substituir' : 'Informe a API Key' ?>" <?= $config ? '' : 'required' ?> autocomplete="new-password"><div class="form-text">A chave salva nunca e exibida nesta tela.</div></div>
                    <div class="col-12"><label class="form-label">Nome da instancia</label><input name="instancia" class="form-control font-monospace" value="<?= htmlspecialchars($config['instancia'] ?? '') ?>" required></div>
                    <div class="col-12 d-flex gap-2"><button name="acao" value="salvar_config" class="btn btn-primary flex-fill">Salvar configuracao</button><button name="acao" value="testar" class="btn btn-outline-success flex-fill">Testar conexao</button></div>
                </form>
                <?php if ($config && !empty($config['webhook_token'])): ?>
                <div class="border-top mt-4 pt-3">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2"><div><div class="fw-semibold">Confirmacao de entrega</div><div class="small text-muted"><?= !empty($config['webhook_configurado_em']) ? 'Configurada em '.htmlspecialchars(date('d/m/Y H:i',strtotime($config['webhook_configurado_em']))) : 'Ainda nao configurada na Evolution' ?></div></div><span class="badge <?= !empty($config['webhook_configurado_em'])?'text-bg-success':'text-bg-secondary' ?>"><?= !empty($config['webhook_configurado_em'])?'Ativa':'Pendente' ?></span></div>
                    <div class="small text-muted mb-2">Recebe os eventos de entrega e leitura da instancia atual.</div>
                    <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><button name="acao" value="configurar_webhook" class="btn btn-outline-primary w-100">Configurar confirmacao na Evolution</button></form>
                </div>
                <?php endif; ?>
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
<?php endif; ?>

<?php if ($paginaOperacional === 'mensagem'): ?>
<div class="card shadow-sm mb-3"><div class="card-header"><h2 class="h5 mb-0">Enviar mensagem operacional</h2></div><div class="card-body">
    <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="enviar">
        <div class="col-lg-4"><label class="form-label">Destinatario</label><select name="destinatario_id" class="form-select" required><option value="">Selecione...</option><?php foreach($destinatarios as $d): if($d['ativo']!=='S') continue; ?><option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['nome']) ?></option><?php endforeach; ?></select></div>
        <div class="col-lg-6"><label class="form-label">Mensagem</label><textarea name="mensagem" class="form-control" rows="3" required></textarea></div>
        <div class="col-lg-2 d-flex align-items-end"><button class="btn btn-success w-100">Enviar</button></div>
    </form>
</div></div>
<?php endif; ?>

<?php if ($paginaOperacional === 'fechamento' && $secaoRotina === 'configuracao'): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2 class="h5 mb-0">Automacao do fechamento mensal</h2>
        <span class="badge <?= ($config['fechamento_ativo'] ?? 'N')==='S'?'text-bg-success':'text-bg-secondary' ?>"><?= ($config['fechamento_ativo'] ?? 'N')==='S'?'Ativa':'Inativa' ?></span>
    </div>
    <div class="card-body">
        <?php if ($proximoEnvioAutomacao): ?>
        <div class="alert alert-info py-2">Competencia <strong><?= htmlspecialchars(date('m/Y',strtotime($competenciaAutomacao))) ?></strong>: compras em aberto ate <strong><?= htmlspecialchars(date('d/m/Y',strtotime($dataLimiteAutomacao))) ?></strong>. Proximo envio em <strong><?= htmlspecialchars($proximoEnvioAutomacao->format('d/m/Y H:i')) ?></strong>.</div>
        <?php endif; ?>
        <?php if (!$sincronizacaoFechamentoAtualizada): ?><div class="alert alert-warning py-2">A automacao aguardara uma sincronizacao CR001 concluida nos ultimos 30 minutos antes de gerar ou enviar PDFs.</div><?php endif; ?>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="salvar_automacao">
            <div class="col-lg-2 col-md-4"><label class="form-label">Status</label><select name="fechamento_ativo" class="form-select"><option value="S" <?= ($config['fechamento_ativo']??'N')==='S'?'selected':'' ?>>Ativa</option><option value="N" <?= ($config['fechamento_ativo']??'N')!=='S'?'selected':'' ?>>Inativa</option></select></div>
            <div class="col-lg-2 col-md-4"><label class="form-label">Dia util</label><input type="number" name="fechamento_dia_util" min="1" max="10" class="form-control" value="<?= (int)($config['fechamento_dia_util']??3) ?>" required></div>
            <div class="col-lg-2 col-md-4"><label class="form-label">Horario</label><input type="time" name="fechamento_horario" class="form-control" value="<?= htmlspecialchars(substr((string)($config['fechamento_horario']??'10:00'),0,5)) ?>" required></div>
            <div class="col-lg-2 col-md-4"><label class="form-label">Intervalo (seg.)</label><input type="number" name="fechamento_intervalo_segundos" min="10" max="3600" class="form-control" value="<?= (int)($config['fechamento_intervalo_segundos']??30) ?>" required></div>
            <div class="col-lg-2 col-md-4"><label class="form-label">Excecao proximo envio</label><input type="date" name="fechamento_proxima_data" class="form-control" value="<?= htmlspecialchars((string)($config['fechamento_proxima_data']??'')) ?>"></div>
            <div class="col-lg-2 col-md-4"><button class="btn btn-primary w-100">Salvar automacao</button></div>
        </form>
        <div class="small text-muted mt-2">Sao considerados somente titulos CMCONTADOR 9 ainda abertos no momento de cada envio. O <a href="../desconto_cheques/feriados.php">calendario de feriados</a> cadastrado no sistema participa do calculo do dia util.</div>
    </div>
</div>
<?php endif; ?>

<?php if ($paginaOperacional === 'envios' || ($paginaOperacional === 'fechamento' && $secaoRotina === 'fila')): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2"><h2 class="h5 mb-0"><?= $paginaOperacional==='fechamento'?'Fila do fechamento mensal':'Fila operacional' ?></h2><div class="d-flex gap-2"><?php if($paginaOperacional==='fechamento'): ?><form method="post" onsubmit="return confirm('Preparar agora a fila da competencia anterior?')"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="preparar_fila_agora"><button class="btn btn-sm btn-outline-primary">Preparar fila agora</button></form><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="reprocessar_erros"><?php if($paginaOperacional==='fechamento'): ?><input type="hidden" name="rotina_codigo" value="fechamento_mensal_clientes"><?php endif; ?><button class="btn btn-sm btn-outline-warning">Reprocessar erros</button></form><form method="post" onsubmit="return confirm('Cancelar todos os envios ainda pendentes?')"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="acao" value="cancelar_fila"><?php if($paginaOperacional==='fechamento'): ?><input type="hidden" name="rotina_codigo" value="fechamento_mensal_clientes"><?php endif; ?><button class="btn btn-sm btn-outline-danger">Cancelar pendentes</button></form></div></div>
    <?php if ($paginaOperacional === 'envios'): ?><div class="card-body border-bottom"><form method="get" class="row g-2 align-items-end"><div class="col-lg-4"><label class="form-label">Rotina</label><select name="rotina" class="form-select"><option value="">Todas</option><option value="fechamento_mensal_clientes" <?= $filtroRotina==='fechamento_mensal_clientes'?'selected':'' ?>>Fechamento mensal de clientes</option></select></div><div class="col-lg-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">Todos</option><?php foreach(array_keys($resumoFila) as $statusFiltro): ?><option value="<?= htmlspecialchars($statusFiltro) ?>" <?= $filtroStatus===$statusFiltro?'selected':'' ?>><?= htmlspecialchars($statusFiltro) ?></option><?php endforeach; ?></select></div><div class="col-lg-3"><label class="form-label">Referencia</label><input name="referencia" class="form-control" value="<?= htmlspecialchars($filtroReferencia) ?>" placeholder="AAAA-MM"></div><div class="col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-fill">Filtrar</button><a href="envios.php" class="btn btn-outline-secondary">Limpar</a></div></form></div><?php endif; ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><?php if($paginaOperacional!=='fechamento'): ?><th>Rotina</th><?php endif; ?><th>Referencia</th><th>Destinatario</th><th>Telefone</th><th>Status</th><th>Tentativas</th><th class="text-end">Itens</th><th class="text-end">Valor</th><th>Aceito/entregue</th><th>Erro</th></tr></thead><tbody>
    <?php foreach($filaOperacional as $f): ?><tr><?php if($paginaOperacional!=='fechamento'): ?><td><?= htmlspecialchars($f['rotina_nome']) ?></td><?php endif; ?><td><?= htmlspecialchars($f['referencia']) ?></td><td><?= htmlspecialchars($f['destinatario_nome']) ?></td><td><?= htmlspecialchars($f['telefone'] ?: '-') ?></td><td><span class="badge <?= in_array($f['status'],['ACEITO','ENTREGUE'],true)?'text-bg-success':($f['status']==='ERRO'?'text-bg-danger':($f['status']==='AGUARDANDO'?'text-bg-primary':'text-bg-secondary')) ?>"><?= htmlspecialchars($f['status']) ?></span></td><td><?= (int)$f['tentativas'] ?>/3</td><td class="text-end"><?= (int)$f['quantidade_itens'] ?></td><td class="text-end">R$ <?= number_format((float)$f['valor'],2,',','.') ?></td><td><?= $f['entregue_em']?htmlspecialchars(date('d/m/Y H:i',strtotime($f['entregue_em']))):($f['aceito_em']?htmlspecialchars(date('d/m/Y H:i',strtotime($f['aceito_em']))):'-') ?></td><td><?= htmlspecialchars((string)($f['erro']??'')) ?></td></tr><?php endforeach; ?>
    <?php if(!$filaOperacional): ?><tr><td colspan="<?= $paginaOperacional==='fechamento'?9:10 ?>" class="text-center text-muted py-3">Nenhum item na fila.</td></tr><?php endif; ?></tbody></table></div>
</div>
<?php endif; ?>

<?php if ($paginaOperacional === 'fechamento' && $secaoRotina === 'execucao'): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2"><h2 class="h5 mb-0">Fechamento mensal dos clientes</h2><a href="../financeiro/clientes_fechamento_mensal.php" class="btn btn-sm btn-outline-primary">Administrar clientes</a></div>
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-8"><label class="form-label">Compras realizadas ate</label><input type="date" name="data_fim" class="form-control" value="<?= htmlspecialchars($fimFechamento) ?>" required></div>
            <div class="col-md-4"><button class="btn btn-primary w-100">Carregar clientes</button></div>
        </form>
        <form method="post" onsubmit="return this.dataset.acao !== 'enviar_fechamentos' || confirm('Enviar os PDFs aos clientes selecionados pela instancia operacional?')">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="data_fim" value="<?= htmlspecialchars($fimFechamento) ?>">
            <div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr><th style="width:42px"><input type="checkbox" id="wo-fech-todos" title="Selecionar todos aptos"></th><th>Cliente</th><th>Celular</th><th class="text-end">Titulos</th><th class="text-end">Em aberto</th><th>Situacao</th></tr></thead><tbody>
            <?php foreach($clientesFechamento as $c): $semCelular=trim((string)$c['CELULAR'])===''; $jaEnviado=!empty($c['ultimo_envio']); ?><tr><td><input type="checkbox" class="wo-fech-check" name="clientes_fechamento[]" value="<?= (int)$c['CLICONTADOR'] ?>" <?= $semCelular||$jaEnviado?'disabled':'' ?>></td><td><div class="fw-semibold"><?= htmlspecialchars($c['nome_cliente']) ?></div><div class="small text-muted">Cod. <?= (int)$c['CLICONTADOR'] ?></div></td><td><?= htmlspecialchars($c['CELULAR'] ?: 'Sem celular') ?></td><td class="text-end"><?= (int)$c['quantidade_titulos'] ?></td><td class="text-end fw-semibold">R$ <?= number_format((float)$c['valor_aberto'],2,',','.') ?></td><td><?php if($semCelular): ?><span class="badge text-bg-warning">Sem celular</span><?php elseif($jaEnviado): ?><span class="badge text-bg-success">Enviado <?= htmlspecialchars(date('d/m/Y H:i',strtotime($c['ultimo_envio']))) ?></span><?php else: ?><span class="badge text-bg-primary">Pronto</span><?php endif; ?></td></tr><?php endforeach; ?>
            <?php if(!$clientesFechamento): ?><tr><td colspan="6" class="text-center text-muted py-4">Nenhum cliente marcado possui compras em aberto ate a data limite.</td></tr><?php endif; ?>
            </tbody></table></div>
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="forcar_reenvio" value="S" id="forcar-reenvio"><label class="form-check-label" for="forcar-reenvio">Permitir reenvio da mesma data limite</label></div><div class="d-flex gap-2"><button type="submit" name="acao" value="baixar_previas" class="btn btn-outline-primary" onclick="this.form.dataset.acao='baixar_previas'" <?= !$clientesFechamento?'disabled':'' ?>>Baixar previas</button><button type="submit" name="acao" value="enviar_fechamentos" class="btn btn-success" onclick="this.form.dataset.acao='enviar_fechamentos'" <?= !$clientesFechamento?'disabled':'' ?>>Enviar PDFs selecionados</button></div></div>
        </form>
    </div>
</div>
<script>document.getElementById('wo-fech-todos').addEventListener('change',function(){document.querySelectorAll('.wo-fech-check:not(:disabled)').forEach(function(c){c.checked=document.getElementById('wo-fech-todos').checked;});});document.getElementById('forcar-reenvio').addEventListener('change',function(){document.querySelectorAll('.wo-fech-check').forEach(function(c){if(c.closest('tr').querySelector('.text-bg-success')){c.disabled=!document.getElementById('forcar-reenvio').checked;}});});</script>
<?php endif; ?>

<?php if ($paginaOperacional === 'historico' || ($paginaOperacional === 'fechamento' && $secaoRotina === 'historico')): ?>
<div class="card shadow-sm mb-3"><div class="card-header"><h2 class="h5 mb-0"><?= $paginaOperacional==='fechamento'?'Historico da rotina':'Historico das rotinas' ?></h2></div><div class="table-responsive"><table class="table table-striped table-sm mb-0"><thead><tr><?php if($paginaOperacional!=='fechamento'): ?><th>Rotina</th><?php endif; ?><th>Referencia</th><th>Destinatario</th><th>Status</th><th class="text-end">Itens</th><th class="text-end">Valor</th><th>Processado em</th><th>Erro</th></tr></thead><tbody>
<?php $temHistoricoFila=false; foreach($filaOperacional as $f): if(!in_array($f['status'],['ACEITO','ENTREGUE','ERRO','IGNORADO','CANCELADO'],true)) continue; $temHistoricoFila=true; ?><tr><?php if($paginaOperacional!=='fechamento'): ?><td><?= htmlspecialchars($f['rotina_nome']) ?></td><?php endif; ?><td><?= htmlspecialchars($f['referencia']) ?></td><td><?= htmlspecialchars($f['destinatario_nome']) ?></td><td><span class="badge <?= in_array($f['status'],['ACEITO','ENTREGUE'],true)?'text-bg-success':($f['status']==='ERRO'?'text-bg-danger':'text-bg-secondary') ?>"><?= htmlspecialchars($f['status']) ?></span></td><td class="text-end"><?= (int)$f['quantidade_itens'] ?></td><td class="text-end">R$ <?= number_format((float)$f['valor'],2,',','.') ?></td><td><?= htmlspecialchars(date('d/m/Y H:i',strtotime($f['atualizado_em'] ?: $f['criado_em']))) ?></td><td><?= htmlspecialchars((string)($f['erro']??'')) ?></td></tr><?php endforeach; ?>
<?php if(!$temHistoricoFila): ?><tr><td colspan="<?= $paginaOperacional==='fechamento'?7:8 ?>" class="text-center text-muted py-3">Nenhum processamento concluido.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>

<?php if ($paginaOperacional === 'historico'): ?>
<div class="card shadow-sm mb-3"><div class="card-header"><h2 class="h5 mb-0">Envios manuais de PDFs</h2></div><div class="table-responsive"><table class="table table-striped table-sm mb-0"><thead><tr><th>Envio</th><th>Cliente</th><th>Intervalo</th><th class="text-end">Titulos</th><th class="text-end">Em aberto</th><th>Status</th><th>Erro</th></tr></thead><tbody>
<?php foreach($historicoFechamentos as $h): ?><tr><td class="text-nowrap"><?= htmlspecialchars(date('d/m/Y H:i',strtotime($h['enviado_em']))) ?></td><td><?= htmlspecialchars($h['nome_cliente']) ?></td><td><?= htmlspecialchars(date('d/m/Y',strtotime($h['data_inicio'])).' a '.date('d/m/Y',strtotime($h['data_fim']))) ?></td><td class="text-end"><?= (int)$h['quantidade_titulos'] ?></td><td class="text-end">R$ <?= number_format((float)$h['valor_aberto'],2,',','.') ?></td><td><span class="badge <?= $h['status']==='OK'?'text-bg-success':'text-bg-danger' ?>"><?= htmlspecialchars($h['status']) ?></span></td><td><?= htmlspecialchars($h['erro'] ?? '') ?></td></tr><?php endforeach; ?>
<?php if(!$historicoFechamentos): ?><tr><td colspan="7" class="text-center text-muted py-3">Nenhum fechamento operacional enviado.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>

<?php if ($paginaOperacional === 'historico'): ?>
<div class="card shadow-sm"><div class="card-header"><h2 class="h5 mb-0">Mensagens avulsas</h2></div><div class="table-responsive"><table class="table table-striped table-sm mb-0"><thead><tr><th>Data</th><th>Destino</th><th>Mensagem</th><th>Status</th><th>Erro</th></tr></thead><tbody>
<?php foreach($historico as $h): ?><tr><td class="text-nowrap"><?= htmlspecialchars(date('d/m/Y H:i',strtotime($h['enviado_em']))) ?></td><td><?= htmlspecialchars($h['destino_nome']) ?></td><td><?= nl2br(htmlspecialchars($h['mensagem'])) ?></td><td><span class="badge <?= $h['status']==='OK'?'text-bg-success':'text-bg-danger' ?>"><?= htmlspecialchars($h['status']) ?></span></td><td><?= htmlspecialchars($h['erro'] ?? '') ?></td></tr><?php endforeach; ?>
<?php if(!$historico): ?><tr><td colspan="5" class="text-center text-muted py-3">Nenhum envio operacional registrado.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endif; ?>

<?php if ($paginaOperacional === 'rotinas'): ?>
<div class="card shadow-sm"><div class="card-header"><h2 class="h5 mb-0">Rotinas operacionais</h2></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Rotina</th><th>Proxima execucao</th><th>Status</th><th class="text-end">Acao</th></tr></thead><tbody><tr><td><div class="fw-semibold">Fechamento mensal de clientes</div><div class="small text-muted">Envia a relacao de compras em aberto ate o encerramento da competencia.</div></td><td><?= $proximoEnvioAutomacao?htmlspecialchars($proximoEnvioAutomacao->format('d/m/Y H:i')):'Nao configurada' ?></td><td><span class="badge <?= ($config['fechamento_ativo']??'N')==='S'?'text-bg-success':'text-bg-secondary' ?>"><?= ($config['fechamento_ativo']??'N')==='S'?'Ativa':'Inativa' ?></span></td><td class="text-end"><a href="fechamento_mensal.php" class="btn btn-sm btn-outline-primary">Abrir</a></td></tr></tbody></table></div></div>
<?php endif; ?>
<?php require __DIR__ . '/../../layout/footer.php'; ?>
