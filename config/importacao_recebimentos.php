<?php

if (defined('IMPORTACAO_RECEBIMENTOS_CONFIG_CARREGADO')) {
    return;
}

define('IMPORTACAO_RECEBIMENTOS_CONFIG_CARREGADO', true);

function garantirTabelaRegrasImportacao(PDO $pdo): void
{
    static $executado = false;
    if ($executado) {
        return;
    }
    $executado = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fechamento_importacao_regras (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            nome VARCHAR(120) NOT NULL,
            grupo VARCHAR(80) NOT NULL DEFAULT 'Recebimentos',
            tipo VARCHAR(40) NOT NULL,
            origem VARCHAR(80) NOT NULL,
            arquivo_php VARCHAR(120) NOT NULL,
            estabelecimento VARCHAR(80) NULL,
            cm_debito INT NULL,
            cm_credito INT NULL,
            cm_pix INT NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            ordem INT NOT NULL DEFAULT 0,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_importacao_regras_empresa (empresa_id, ativo, ordem),
            INDEX idx_importacao_regras_tipo (tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'armazem_conciliacao_recebimentos'
          AND index_name = 'ux_conc_rec_empresa_identificador'
    ");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("
            ALTER TABLE armazem_conciliacao_recebimentos
            ADD UNIQUE KEY ux_conc_rec_empresa_identificador (empresa_id, identificador)
        ");
    }
}

function regrasImportacaoFallbackArmazem(): array
{
    return [
        [
            'id' => 0,
            'empresa_id' => 1,
            'nome' => 'SIPAG POS',
            'descricao' => 'Debito/Credito - D+1 CMCONTADOR 3 | D+30 CMCONTADOR 2',
            'grupo' => 'Comercial',
            'tipo' => 'sipag_pos',
            'origem' => 'SIPAG_POS_COMERCIAL',
            'arquivo_php' => 'importar_sipag_pos_comercial.php',
            'estabelecimento' => 'CB-111737460001',
            'cm_debito' => 3,
            'cm_credito' => 2,
            'cm_pix' => null,
            'botao' => 'btn-primary',
        ],
        [
            'id' => 0,
            'empresa_id' => 1,
            'nome' => 'SIPAG PIX',
            'descricao' => 'Recebimentos PIX comercial - CMCONTADOR 12',
            'grupo' => 'Comercial',
            'tipo' => 'sipag_pix',
            'origem' => 'SIPAG_PIX_COMERCIAL',
            'arquivo_php' => 'importar_sipag_pix_comercial.php',
            'estabelecimento' => 'CB-111737460001',
            'cm_debito' => null,
            'cm_credito' => null,
            'cm_pix' => 12,
            'botao' => 'btn-primary',
        ],
        [
            'id' => 0,
            'empresa_id' => 1,
            'nome' => 'SIPAG POS',
            'descricao' => 'Debito/Credito - D+1 CMCONTADOR 6 | D+30 CMCONTADOR 14',
            'grupo' => 'Outros',
            'tipo' => 'sipag_pos',
            'origem' => 'SIPAG_POS_OUTROS',
            'arquivo_php' => 'importar_sipag_pos_outros.php',
            'estabelecimento' => 'CB-110487250001',
            'cm_debito' => 6,
            'cm_credito' => 14,
            'cm_pix' => null,
            'botao' => 'btn-success',
        ],
        [
            'id' => 0,
            'empresa_id' => 1,
            'nome' => 'SIPAG PIX',
            'descricao' => 'Recebimentos PIX outros - CMCONTADOR 7',
            'grupo' => 'Outros',
            'tipo' => 'sipag_pix',
            'origem' => 'SIPAG_PIX_OUTROS',
            'arquivo_php' => 'importar_sipag_pix_outros.php',
            'estabelecimento' => 'CB-110487250001',
            'cm_debito' => null,
            'cm_credito' => null,
            'cm_pix' => 7,
            'botao' => 'btn-success',
        ],
        [
            'id' => 0,
            'empresa_id' => 1,
            'nome' => 'PAGSEGURO PIX',
            'descricao' => 'Importacao PagSeguro PIX - CMCONTADOR 7',
            'grupo' => 'Outros',
            'tipo' => 'pagseguro_pix',
            'origem' => 'PAGSEGURO_PIX',
            'arquivo_php' => 'importar_pagseguro.php',
            'estabelecimento' => null,
            'cm_debito' => null,
            'cm_credito' => null,
            'cm_pix' => 7,
            'botao' => 'btn-success',
        ],
    ];
}

function listarRegrasImportacaoRecebimentos(PDO $pdo, int $empresaId): array
{
    garantirTabelaRegrasImportacao($pdo);

    $stmt = $pdo->prepare("
        SELECT *
        FROM fechamento_importacao_regras
        WHERE empresa_id = ?
          AND ativo = 'S'
        ORDER BY grupo, ordem, nome
    ");
    $stmt->execute([$empresaId]);
    $regras = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($regras)) {
        foreach ($regras as &$regra) {
            $regra['descricao'] = descricaoRegraImportacao($regra);
            $regra['botao'] = ($regra['grupo'] ?? '') === 'Comercial' ? 'btn-primary' : 'btn-success';
        }
        unset($regra);
        return $regras;
    }

    return $empresaId === 1 ? regrasImportacaoFallbackArmazem() : [];
}

function buscarRegraImportacao(PDO $pdo, int $empresaId, string $tipoEsperado, array $fallback): ?array
{
    garantirTabelaRegrasImportacao($pdo);

    $regraId = (int)($_GET['regra_id'] ?? $_POST['regra_id'] ?? 0);

    if ($regraId > 0) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM fechamento_importacao_regras
            WHERE id = ?
              AND empresa_id = ?
              AND tipo = ?
              AND ativo = 'S'
            LIMIT 1
        ");
        $stmt->execute([$regraId, $empresaId, $tipoEsperado]);
        $regra = $stmt->fetch(PDO::FETCH_ASSOC);
        return $regra ?: null;
    }

    if ($empresaId !== 1) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM fechamento_importacao_regras
            WHERE empresa_id = ?
              AND tipo = ?
              AND ativo = 'S'
            ORDER BY ordem, id
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $tipoEsperado]);
        $regra = $stmt->fetch(PDO::FETCH_ASSOC);
        return $regra ?: null;
    }

    return $empresaId === 1 ? $fallback : null;
}

function descricaoRegraImportacao(array $regra): string
{
    $tipo = $regra['tipo'] ?? '';

    if (in_array($tipo, ['sipag_pos', 'granito_pos_comercial'], true)) {
        return 'Debito CMCONTADOR ' . (int)$regra['cm_debito'] . ' | Credito CMCONTADOR ' . (int)$regra['cm_credito'];
    }

    return 'CMCONTADOR ' . (int)$regra['cm_pix'];
}

function urlRegraImportacao(array $regra): string
{
    $url = $regra['arquivo_php'];
    if ((int)($regra['id'] ?? 0) > 0) {
        $url .= '?regra_id=' . (int)$regra['id'];
    }
    return $url;
}
