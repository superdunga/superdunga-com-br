<?php

if (defined('SISTEMA_MODULOS_CONFIG_CARREGADO')) {
    return;
}

define('SISTEMA_MODULOS_CONFIG_CARREGADO', true);

function sistemaModulosPadrao(): array
{
    return [
        ['codigo' => 'tesouraria_movimentacao', 'grupo' => 'Tesouraria', 'nome' => 'Movimentacao', 'url' => 'modulos/tesouraria/menu_movimentacao.php', 'ordem' => 10],
        ['codigo' => 'tesouraria_extrato', 'grupo' => 'Tesouraria', 'nome' => 'Extrato', 'url' => 'modulos/tesouraria/extrato.php', 'ordem' => 20],
        ['codigo' => 'tesouraria_inventario', 'grupo' => 'Tesouraria', 'nome' => 'Inventario Fisico', 'url' => 'modulos/tesouraria/inventario.php', 'ordem' => 30],
        ['codigo' => 'tesouraria_inventarios', 'grupo' => 'Tesouraria', 'nome' => 'Historico de Inventarios', 'url' => 'modulos/tesouraria/inventarios.php', 'ordem' => 40],
        ['codigo' => 'tesouraria_conciliar', 'grupo' => 'Tesouraria', 'nome' => 'Conciliar Tesouraria', 'url' => 'modulos/tesouraria/conciliar.php', 'ordem' => 50],
        ['codigo' => 'sincronizacao', 'grupo' => 'Tesouraria', 'nome' => 'Sincronizar Dados', 'url' => 'modulos/tesouraria/sincronizacao.php', 'ordem' => 60],

        ['codigo' => 'fechamento_caixa', 'grupo' => 'Fechamento', 'nome' => 'Fechamento de Caixa', 'url' => 'modulos/fechamentodecaixa/fechamento_caixa.php', 'ordem' => 110],
        ['codigo' => 'fechamento_dinheiro', 'grupo' => 'Fechamento', 'nome' => 'Conciliacao de Dinheiro', 'url' => 'modulos/fechamentodecaixa/conciliacao_dinheiro.php', 'ordem' => 120],
        ['codigo' => 'fechamento_importar_recebimentos', 'grupo' => 'Fechamento', 'nome' => 'Recebimentos', 'url' => 'modulos/fechamentodecaixa/menu_recebimentos.php', 'ordem' => 130],
        ['codigo' => 'fechamento_resumo_prazo', 'grupo' => 'Fechamento', 'nome' => 'Resumo Vendas a Prazo', 'url' => 'modulos/fechamentodecaixa/resumo_prazo.php', 'ordem' => 140],

        ['codigo' => 'auditoria_compras', 'grupo' => 'Auditoria', 'nome' => 'Compras', 'url' => 'modulos/auditoria/listar.php', 'ordem' => 210],
        ['codigo' => 'auditoria_itens_fora_padrao', 'grupo' => 'Auditoria', 'nome' => 'Itens fora do padrao', 'url' => 'modulos/auditoria/itens_fora_padrao.php', 'ordem' => 220],

        ['codigo' => 'financeiro', 'grupo' => 'Financeiro', 'nome' => 'Financeiro', 'url' => 'modulos/financeiro/menu_financeiro.php', 'ordem' => 260],
        ['codigo' => 'financeiro_contas_receber', 'grupo' => 'Financeiro', 'nome' => 'Contas a Receber - Clientes', 'url' => 'modulos/financeiro/contas_receber_clientes.php', 'ordem' => 270],

        ['codigo' => 'estoque', 'grupo' => 'Estoque', 'nome' => 'Estoque', 'url' => 'modulos/estoque/menu_estoque.php', 'ordem' => 290],
        ['codigo' => 'estoque_posicao', 'grupo' => 'Estoque', 'nome' => 'Posicao de Estoque', 'url' => 'modulos/estoque/posicao_estoque.php', 'ordem' => 300],

        ['codigo' => 'gestao', 'grupo' => 'Gestao', 'nome' => 'Gestao', 'url' => 'modulos/gestao/menu_gestao.php', 'ordem' => 305],
        ['codigo' => 'gestao_dre', 'grupo' => 'Gestao', 'nome' => 'DRE', 'url' => 'modulos/gestao/dre.php', 'ordem' => 306],

        ['codigo' => 'whatsapp', 'grupo' => 'Administracao', 'nome' => 'Mensagens WhatsApp', 'url' => 'modulos/whatsapp/index.php', 'ordem' => 310],
        ['codigo' => 'usuarios', 'grupo' => 'Administracao', 'nome' => 'Gerenciar Usuarios', 'url' => 'modulos/usuarios/listar.php', 'ordem' => 320],
        ['codigo' => 'usuarios_permissoes', 'grupo' => 'Administracao', 'nome' => 'Permissoes por Perfil', 'url' => 'modulos/usuarios/permissoes.php', 'ordem' => 325],
        ['codigo' => 'usuarios_permissoes_usuario', 'grupo' => 'Administracao', 'nome' => 'Permissoes por Usuario', 'url' => 'modulos/usuarios/permissoes_usuario.php', 'ordem' => 326],
        ['codigo' => 'empresas', 'grupo' => 'Administracao', 'nome' => 'Gerenciar Empresas', 'url' => 'modulos/empresas/listar.php', 'ordem' => 330],
        ['codigo' => 'empresas_modulos', 'grupo' => 'Administracao', 'nome' => 'Modulos da Empresa', 'url' => 'modulos/empresas/modulos.php', 'ordem' => 340],
    ];
}

function garantirTabelasModulos(PDO $pdo): void
{
    static $executado = false;
    if ($executado) {
        return;
    }
    $executado = true;

    $modulosPadrao = sistemaModulosPadrao();
    $codigosPadrao = array_column($modulosPadrao, 'codigo');
    $tabelas = ['sistema_modulos', 'empresa_modulos', 'perfil_modulos', 'usuario_modulos'];

    $placeholdersTabelas = implode(',', array_fill(0, count($tabelas), '?'));
    $stmtTabelas = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ({$placeholdersTabelas})
    ");
    $stmtTabelas->execute($tabelas);

    if ((int)$stmtTabelas->fetchColumn() === count($tabelas)) {
        $placeholdersCodigos = implode(',', array_fill(0, count($codigosPadrao), '?'));
        $stmtModulos = $pdo->prepare("
            SELECT COUNT(*)
            FROM sistema_modulos
            WHERE codigo IN ({$placeholdersCodigos})
              AND ativo = 'S'
        ");
        $stmtModulos->execute($codigosPadrao);

        if ((int)$stmtModulos->fetchColumn() === count($codigosPadrao)) {
            $stmtEmpresasSemModulo = $pdo->query("
                SELECT COUNT(*)
                FROM empresas e
                CROSS JOIN sistema_modulos sm
                WHERE sm.ativo = 'S'
                  AND EXISTS (
                      SELECT 1
                      FROM empresa_modulos em_existente
                      WHERE em_existente.empresa_id = e.id
                  )
                  AND NOT EXISTS (
                      SELECT 1
                      FROM empresa_modulos em
                      WHERE em.empresa_id = e.id
                        AND em.modulo_id = sm.id
                  )
            ");

            if ((int)$stmtEmpresasSemModulo->fetchColumn() === 0) {
                return;
            }
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sistema_modulos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(80) NOT NULL UNIQUE,
            grupo VARCHAR(80) NOT NULL,
            nome VARCHAR(120) NOT NULL,
            url VARCHAR(255) NULL,
            ordem INT NOT NULL DEFAULT 0,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sistema_modulos_grupo (grupo, ordem)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS empresa_modulos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            modulo_id INT NOT NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            atualizado_por INT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_empresa_modulo (empresa_id, modulo_id),
            INDEX idx_empresa_modulos_empresa (empresa_id),
            CONSTRAINT fk_empresa_modulos_modulo
                FOREIGN KEY (modulo_id) REFERENCES sistema_modulos(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS perfil_modulos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            perfil VARCHAR(30) NOT NULL,
            modulo_id INT NOT NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            atualizado_por INT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_perfil_modulo (perfil, modulo_id),
            INDEX idx_perfil_modulos_perfil (perfil),
            CONSTRAINT fk_perfil_modulos_modulo
                FOREIGN KEY (modulo_id) REFERENCES sistema_modulos(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuario_modulos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            modulo_id INT NOT NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            atualizado_por INT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_usuario_modulo (usuario_id, modulo_id),
            INDEX idx_usuario_modulos_usuario (usuario_id),
            CONSTRAINT fk_usuario_modulos_modulo
                FOREIGN KEY (modulo_id) REFERENCES sistema_modulos(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->prepare("
        INSERT INTO sistema_modulos (codigo, grupo, nome, url, ordem, ativo)
        VALUES (?, ?, ?, ?, ?, 'S')
        ON DUPLICATE KEY UPDATE
            grupo = VALUES(grupo),
            nome = VALUES(nome),
            url = VALUES(url),
            ordem = VALUES(ordem),
            ativo = 'S'
    ");

    foreach ($modulosPadrao as $modulo) {
        $stmt->execute([
            $modulo['codigo'],
            $modulo['grupo'],
            $modulo['nome'],
            $modulo['url'],
            $modulo['ordem'],
        ]);
    }

    $pdo->exec("
        INSERT INTO empresa_modulos (empresa_id, modulo_id, ativo)
        SELECT e.id, sm.id, 'S'
        FROM empresas e
        CROSS JOIN sistema_modulos sm
        WHERE sm.ativo = 'S'
          AND EXISTS (
              SELECT 1
              FROM empresa_modulos em_existente
              WHERE em_existente.empresa_id = e.id
          )
          AND NOT EXISTS (
              SELECT 1
              FROM empresa_modulos em
              WHERE em.empresa_id = e.id
                AND em.modulo_id = sm.id
          )
    ");
}

function empresaTemConfiguracaoModulos(PDO $pdo, int $empresaId): bool
{
    garantirTabelasModulos($pdo);

    static $cache = [];
    if (array_key_exists($empresaId, $cache)) {
        return $cache[$empresaId];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM empresa_modulos WHERE empresa_id = ?");
    $stmt->execute([$empresaId]);
    $cache[$empresaId] = (int)$stmt->fetchColumn() > 0;

    return $cache[$empresaId];
}

function moduloEmpresaPermitido(PDO $pdo, int $empresaId, string $codigo): bool
{
    garantirTabelasModulos($pdo);

    if (($_SESSION['nivel'] ?? '') === 'MASTER' && in_array($codigo, ['usuarios', 'usuarios_permissoes', 'usuarios_permissoes_usuario', 'empresas', 'empresas_modulos'], true)) {
        return true;
    }

    if (!empresaTemConfiguracaoModulos($pdo, $empresaId)) {
        return true;
    }

    static $cachePermissoes = [];
    if (!isset($cachePermissoes[$empresaId])) {
        $stmtPermissoes = $pdo->prepare("
            SELECT sm.codigo, em.ativo
            FROM sistema_modulos sm
            INNER JOIN empresa_modulos em
                ON em.modulo_id = sm.id
            WHERE sm.ativo = 'S'
              AND em.empresa_id = ?
        ");
        $stmtPermissoes->execute([$empresaId]);
        $cachePermissoes[$empresaId] = $stmtPermissoes->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    return ($cachePermissoes[$empresaId][$codigo] ?? 'N') === 'S';
}

function perfisSistema(): array
{
    return ['MASTER', 'ADMIN', 'OPERADOR'];
}

function perfilTemConfiguracaoModulos(PDO $pdo, string $perfil): bool
{
    garantirTabelasModulos($pdo);

    $perfil = strtoupper(trim($perfil));
    if ($perfil === '' || $perfil === 'MASTER') {
        return true;
    }

    static $cache = [];
    if (array_key_exists($perfil, $cache)) {
        return $cache[$perfil];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM perfil_modulos WHERE perfil = ?");
    $stmt->execute([$perfil]);
    $cache[$perfil] = (int)$stmt->fetchColumn() > 0;

    return $cache[$perfil];
}

function moduloPerfilPermitido(PDO $pdo, string $perfil, string $codigo): bool
{
    garantirTabelasModulos($pdo);

    $perfil = strtoupper(trim($perfil));

    if ($perfil === 'MASTER') {
        return true;
    }

    if ($perfil === '') {
        return false;
    }

    if (!perfilTemConfiguracaoModulos($pdo, $perfil)) {
        return true;
    }

    static $cachePermissoes = [];
    if (!isset($cachePermissoes[$perfil])) {
        $stmtPermissoes = $pdo->prepare("
            SELECT sm.codigo, pm.ativo
            FROM sistema_modulos sm
            INNER JOIN perfil_modulos pm
                ON pm.modulo_id = sm.id
            WHERE sm.ativo = 'S'
              AND pm.perfil = ?
        ");
        $stmtPermissoes->execute([$perfil]);
        $cachePermissoes[$perfil] = $stmtPermissoes->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    return ($cachePermissoes[$perfil][$codigo] ?? 'N') === 'S';
}

function usuarioTemConfiguracaoModulos(PDO $pdo, int $usuarioId): bool
{
    garantirTabelasModulos($pdo);

    if ($usuarioId <= 0) {
        return false;
    }

    static $cache = [];
    if (array_key_exists($usuarioId, $cache)) {
        return $cache[$usuarioId];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuario_modulos WHERE usuario_id = ?");
    $stmt->execute([$usuarioId]);
    $cache[$usuarioId] = (int)$stmt->fetchColumn() > 0;

    return $cache[$usuarioId];
}

function moduloUsuarioPermitido(PDO $pdo, int $usuarioId, string $codigo): ?bool
{
    garantirTabelasModulos($pdo);

    if ($usuarioId <= 0 || !usuarioTemConfiguracaoModulos($pdo, $usuarioId)) {
        return null;
    }

    static $cachePermissoes = [];
    if (!isset($cachePermissoes[$usuarioId])) {
        $stmtPermissoes = $pdo->prepare("
            SELECT sm.codigo, um.ativo
            FROM sistema_modulos sm
            INNER JOIN usuario_modulos um
                ON um.modulo_id = sm.id
            WHERE sm.ativo = 'S'
              AND um.usuario_id = ?
        ");
        $stmtPermissoes->execute([$usuarioId]);
        $cachePermissoes[$usuarioId] = $stmtPermissoes->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    return ($cachePermissoes[$usuarioId][$codigo] ?? 'N') === 'S';
}

function moduloPermitido(PDO $pdo, int $empresaId, string $codigo, ?string $perfil = null): bool
{
    $perfil = $perfil ?? ($_SESSION['nivel'] ?? '');

    if (!moduloEmpresaPermitido($pdo, $empresaId, $codigo)) {
        return false;
    }

    if ($perfil === 'MASTER') {
        return true;
    }

    $permissaoUsuario = moduloUsuarioPermitido($pdo, (int)($_SESSION['usuario_id'] ?? 0), $codigo);
    if ($permissaoUsuario !== null) {
        return $permissaoUsuario;
    }

    return moduloPerfilPermitido($pdo, $perfil, $codigo);
}

function empresaTemModuloDoGrupo(PDO $pdo, int $empresaId, string $grupo): bool
{
    garantirTabelasModulos($pdo);

    if (!empresaTemConfiguracaoModulos($pdo, $empresaId)) {
        return true;
    }

    static $cacheGrupos = [];
    $cacheKey = $empresaId . '|' . $grupo;
    if (array_key_exists($cacheKey, $cacheGrupos)) {
        return $cacheGrupos[$cacheKey];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM sistema_modulos sm
        INNER JOIN empresa_modulos em
            ON em.modulo_id = sm.id
        WHERE sm.grupo = ?
          AND sm.ativo = 'S'
          AND em.empresa_id = ?
          AND em.ativo = 'S'
    ");
    $stmt->execute([$grupo, $empresaId]);

    $cacheGrupos[$cacheKey] = (int)$stmt->fetchColumn() > 0;

    return $cacheGrupos[$cacheKey];
}

function grupoPermitido(PDO $pdo, int $empresaId, string $grupo, ?string $perfil = null): bool
{
    garantirTabelasModulos($pdo);

    $perfil = $perfil ?? ($_SESSION['nivel'] ?? '');

    if ($perfil === 'MASTER') {
        return empresaTemModuloDoGrupo($pdo, $empresaId, $grupo);
    }

    $modulosGrupo = array_filter(sistemaModulosPadrao(), function (array $modulo) use ($grupo): bool {
        return ($modulo['grupo'] ?? '') === $grupo;
    });

    foreach ($modulosGrupo as $modulo) {
        if (moduloPermitido($pdo, $empresaId, $modulo['codigo'], $perfil)) {
            return true;
        }
    }

    return false;
}

function filtrarOpcoesPorModulo(PDO $pdo, int $empresaId, array $opcoes): array
{
    return array_values(array_filter($opcoes, function (array $opcao) use ($pdo, $empresaId): bool {
        $codigo = $opcao['modulo'] ?? '';
        return $codigo === '' || moduloPermitido($pdo, $empresaId, $codigo);
    }));
}
