<?php

function sistemaModulosPadrao(): array
{
    return [
        ['codigo' => 'tesouraria_movimentacao', 'grupo' => 'Tesouraria', 'nome' => 'Movimentacao', 'url' => 'modulos/tesouraria/movimentar.php', 'ordem' => 10],
        ['codigo' => 'tesouraria_extrato', 'grupo' => 'Tesouraria', 'nome' => 'Extrato', 'url' => 'modulos/tesouraria/extrato.php', 'ordem' => 20],
        ['codigo' => 'tesouraria_inventario', 'grupo' => 'Tesouraria', 'nome' => 'Inventario Fisico', 'url' => 'modulos/tesouraria/inventario.php', 'ordem' => 30],
        ['codigo' => 'tesouraria_inventarios', 'grupo' => 'Tesouraria', 'nome' => 'Historico de Inventarios', 'url' => 'modulos/tesouraria/inventarios.php', 'ordem' => 40],
        ['codigo' => 'tesouraria_conciliar', 'grupo' => 'Tesouraria', 'nome' => 'Conciliar Tesouraria', 'url' => 'modulos/tesouraria/conciliar.php', 'ordem' => 50],
        ['codigo' => 'sincronizacao', 'grupo' => 'Tesouraria', 'nome' => 'Sincronizar Dados', 'url' => 'modulos/tesouraria/sincronizacao.php', 'ordem' => 60],

        ['codigo' => 'fechamento_caixa', 'grupo' => 'Fechamento', 'nome' => 'Fechamento de Caixa', 'url' => 'modulos/fechamentodecaixa/fechamento_caixa.php', 'ordem' => 110],
        ['codigo' => 'fechamento_dinheiro', 'grupo' => 'Fechamento', 'nome' => 'Conciliacao de Dinheiro', 'url' => 'modulos/fechamentodecaixa/conciliacao_dinheiro.php', 'ordem' => 120],
        ['codigo' => 'fechamento_importar_recebimentos', 'grupo' => 'Fechamento', 'nome' => 'Importar Recebimentos', 'url' => 'modulos/fechamentodecaixa/importar_recebimentos.php', 'ordem' => 130],
        ['codigo' => 'fechamento_resumo_prazo', 'grupo' => 'Fechamento', 'nome' => 'Resumo Vendas a Prazo', 'url' => 'modulos/fechamentodecaixa/resumo_prazo.php', 'ordem' => 140],

        ['codigo' => 'auditoria_compras', 'grupo' => 'Auditoria', 'nome' => 'Compras', 'url' => 'modulos/auditoria/listar.php', 'ordem' => 210],
        ['codigo' => 'auditoria_itens_fora_padrao', 'grupo' => 'Auditoria', 'nome' => 'Itens fora do padrao', 'url' => 'modulos/auditoria/itens_fora_padrao.php', 'ordem' => 220],

        ['codigo' => 'whatsapp', 'grupo' => 'Administracao', 'nome' => 'Mensagens WhatsApp', 'url' => 'modulos/whatsapp/index.php', 'ordem' => 310],
        ['codigo' => 'usuarios', 'grupo' => 'Administracao', 'nome' => 'Gerenciar Usuarios', 'url' => 'modulos/usuarios/listar.php', 'ordem' => 320],
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

    foreach (sistemaModulosPadrao() as $modulo) {
        $stmt->execute([
            $modulo['codigo'],
            $modulo['grupo'],
            $modulo['nome'],
            $modulo['url'],
            $modulo['ordem'],
        ]);
    }
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

    if (($_SESSION['nivel'] ?? '') === 'MASTER' && in_array($codigo, ['empresas', 'empresas_modulos'], true)) {
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

function filtrarOpcoesPorModulo(PDO $pdo, int $empresaId, array $opcoes): array
{
    return array_values(array_filter($opcoes, function (array $opcao) use ($pdo, $empresaId): bool {
        $codigo = $opcao['modulo'] ?? '';
        return $codigo === '' || moduloEmpresaPermitido($pdo, $empresaId, $codigo);
    }));
}
