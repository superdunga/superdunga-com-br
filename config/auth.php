<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function appBaseUrl(): string
{
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $modulosPos = strpos($scriptDir, '/modulos');
    $base = $modulosPos !== false ? substr($scriptDir, 0, $modulosPos) : $scriptDir;
    return rtrim($base, '/');
}

if (!isset($_SESSION['usuario_id'])) {
    $base = appBaseUrl();
    header("Location: " . ($base ?: '') . "/login.php");
    exit;
}

function redirecionarPendenciasOperador(): void
{
    if (($_SESSION['nivel'] ?? '') !== 'OPERADOR') {
        return;
    }

    $hoje = date('Y-m-d');
    if (($_SESSION['operador_pendencias_liberado_data'] ?? '') === $hoje) {
        return;
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $permitidos = [
        '/modulos/operador/pendencias.php',
        '/modulos/tesouraria/conciliar.php',
        '/modulos/fechamentodecaixa/conciliacao_dinheiro_divergentes.php',
        '/modulos/fechamentodecaixa/extrato_caixa.php',
        '/modulos/fechamentodecaixa/validar_cm.php',
        '/modulos/fechamentodecaixa/resumo_prazo.php',
        '/modulos/fechamentodecaixa/diagnostico_divergencia.php',
        '/modulos/auditoria/itens_fora_padrao.php',
        '/logout.php',
        '/login.php',
    ];

    foreach ($permitidos as $permitido) {
        if (substr($script, -strlen($permitido)) === $permitido) {
            return;
        }
    }

    $base = appBaseUrl();
    header("Location: " . ($base ?: '') . "/modulos/operador/pendencias.php");
    exit;
}

redirecionarPendenciasOperador();

function caminhoAppAtual(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = appBaseUrl();

    if ($base !== '' && strpos($script, $base . '/') === 0) {
        $script = substr($script, strlen($base) + 1);
    } else {
        $script = ltrim($script, '/');
    }

    return trim($script, '/');
}

function renderizarAcessoNegadoModulo(string $mensagem = 'Seu usuario nao possui permissao para acessar esta rotina.'): void
{
    global $pdo_master;

    http_response_code(403);

    $base = appBaseUrl();
    $painelUrl = ($base ?: '') . '/index.php';

    if (!isset($pdo_master)) {
        require_once __DIR__ . '/conexao.php';
    }

    require __DIR__ . '/../layout/header.php';
    ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <h1 class="h4 fw-bold mb-2">Acesso negado</h1>
            <p class="text-muted mb-4"><?= htmlspecialchars($mensagem) ?></p>
            <a href="<?= htmlspecialchars($painelUrl) ?>" class="btn btn-primary">Voltar ao painel</a>
        </div>
    </div>
    <?php
    require __DIR__ . '/../layout/footer.php';
    exit;
}

function validarPermissaoModuloAtual(): void
{
    global $pdo_master;

    $caminho = caminhoAppAtual();
    if ($caminho === '' || in_array($caminho, ['index.php', 'logout.php'], true)) {
        return;
    }

    if (!isset($pdo_master)) {
        require_once __DIR__ . '/conexao.php';
    }
    require_once __DIR__ . '/modulos.php';
    garantirTabelasModulos($pdo_master);

    $pdo = $pdo_master;
    $empresaId = (int)($_SESSION['empresa_id'] ?? 0);
    $perfil = $_SESSION['nivel'] ?? '';

    $menusPorGrupo = [
        'modulos/tesouraria/menu_tesouraria.php' => 'Tesouraria',
        'modulos/fechamentodecaixa/menu_fechamento.php' => 'Fechamento',
        'modulos/auditoria/menu_auditoria.php' => 'Auditoria',
        'modulos/financeiro/menu_financeiro.php' => 'Financeiro',
        'modulos/estoque/menu_estoque.php' => 'Estoque',
    ];

    if (isset($menusPorGrupo[$caminho])) {
        if (!grupoPermitido($pdo, $empresaId, $menusPorGrupo[$caminho], $perfil)) {
            renderizarAcessoNegadoModulo();
        }
        return;
    }

    $aliases = [
        'modulos/tesouraria/download.php' => 'tesouraria_extrato',
        'modulos/tesouraria/menu_movimentacao.php' => 'tesouraria_movimentacao',
        'modulos/tesouraria/editar_movimentacao.php' => 'tesouraria_extrato',
        'modulos/tesouraria/inventario_resultado.php' => 'tesouraria_inventario',
        'modulos/fechamentodecaixa/importar_recebimentos.php' => 'fechamento_importar_recebimentos',
        'modulos/fechamentodecaixa/extrato_caixa.php' => 'fechamento_caixa',
        'modulos/fechamentodecaixa/detalhar_fechamento.php' => 'fechamento_caixa',
        'modulos/fechamentodecaixa/validar_cm.php' => 'fechamento_importar_recebimentos',
        'modulos/fechamentodecaixa/conciliar_recebimentos.php' => 'fechamento_importar_recebimentos',
        'modulos/fechamentodecaixa/conciliar_manual.php' => 'fechamento_importar_recebimentos',
        'modulos/fechamentodecaixa/conciliar_exec.php' => 'fechamento_importar_recebimentos',
        'modulos/fechamentodecaixa/conciliar_auto.php' => 'fechamento_importar_recebimentos',
        'modulos/fechamentodecaixa/diagnostico_divergencia.php' => 'fechamento_resumo_prazo',
        'modulos/fechamentodecaixa/conciliacao_dinheiro_divergentes.php' => 'fechamento_dinheiro',
        'modulos/financeiro/contas_receber.php' => 'financeiro',
        'modulos/financeiro/contas_receber_clientes.php' => 'financeiro_contas_receber',
        'modulos/financeiro/contas_pagar.php' => 'financeiro',
        'modulos/financeiro/contas.php' => 'financeiro',
        'modulos/estoque/posicao_estoque.php' => 'estoque_posicao',
    ];

    $codigoModulo = $aliases[$caminho] ?? null;

    if ($codigoModulo === null) {
        $stmtModulo = $pdo->prepare("
            SELECT codigo
            FROM sistema_modulos
            WHERE url = ?
              AND ativo = 'S'
            LIMIT 1
        ");
        $stmtModulo->execute([$caminho]);
        $codigoModulo = $stmtModulo->fetchColumn() ?: null;
    }

    if ($codigoModulo === null) {
        return;
    }

    if (!moduloPermitido($pdo, $empresaId, $codigoModulo, $perfil)) {
        renderizarAcessoNegadoModulo();
    }
}

validarPermissaoModuloAtual();

/* Hierarquia oficial do sistema */
function hierarquia() {
    return [
        'CONSULTA'   => 1,
        'OPERADOR'   => 2,
        'SUPERVISOR' => 3,
        'GERENTE'    => 4,
        'ADMIN'      => 5,
        'MASTER'     => 6
    ];
}

/* Exige nível mínimo para acessar a página */
function exigirNivel($nivelPermitido) {

    $hierarquia = hierarquia();

    if (
        !isset($_SESSION['nivel']) ||
        !isset($hierarquia[$_SESSION['nivel']]) ||
        !isset($hierarquia[$nivelPermitido]) ||
        $hierarquia[$_SESSION['nivel']] < $hierarquia[$nivelPermitido]
    ) {
        die("Acesso negado.");
    }
}

/* Apenas verifica se possui nível mínimo (não mata a página) */
function temNivel($nivelMinimo) {

    $hierarquia = hierarquia();

    if (
        !isset($_SESSION['nivel']) ||
        !isset($hierarquia[$_SESSION['nivel']]) ||
        !isset($hierarquia[$nivelMinimo])
    ) {
        return false;
    }

    return $hierarquia[$_SESSION['nivel']] >= $hierarquia[$nivelMinimo];
}

/* Impede criação de usuário com nível superior ao seu */
function podeCriarNivel($nivelCriado) {

    $hierarquia = hierarquia();

    if (
        !isset($_SESSION['nivel']) ||
        !isset($hierarquia[$_SESSION['nivel']]) ||
        !isset($hierarquia[$nivelCriado])
    ) {
        return false;
    }

    return $hierarquia[$_SESSION['nivel']] > $hierarquia[$nivelCriado];
}
