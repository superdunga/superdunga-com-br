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

function garantirTabelaTaxasAdquirentes(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fechamento_adquirente_taxas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            adquirente VARCHAR(40) NOT NULL,
            grupo VARCHAR(40) NOT NULL,
            tipo_operacao VARCHAR(20) NOT NULL,
            bandeira VARCHAR(40) NOT NULL DEFAULT 'TODAS',
            parcelas_de INT NOT NULL DEFAULT 1,
            parcelas_ate INT NOT NULL DEFAULT 1,
            taxa_percentual DECIMAL(9,4) NOT NULL DEFAULT 0,
            tolerancia_percentual DECIMAL(9,4) NOT NULL DEFAULT 0.0500,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_adquirente_taxas_empresa (empresa_id, adquirente, grupo, tipo_operacao, bandeira, ativo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function identificadorGranitoPos(string $idGranitoOuTransacao): string
{
    return 'GRANITO_POS_' . trim($idGranitoOuTransacao);
}

function identificadorGranitoPix(string $idTransacao): string
{
    return 'GRANITO_PIX_' . trim($idTransacao);
}

function garantirCamposGranitoRecebimentos(PDO $pdo): void
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'armazem_conciliacao_recebimentos'
          AND COLUMN_NAME IN ('id_granito', 'id_transacao')
    ");
    $stmt->execute();
    $existentes = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');

    if (!in_array('id_granito', $existentes, true)) {
        $pdo->exec("
            ALTER TABLE armazem_conciliacao_recebimentos
            ADD COLUMN id_granito VARCHAR(50) NULL AFTER numero_estabelecimento
        ");
    }

    if (!in_array('id_transacao', $existentes, true)) {
        $pdo->exec("
            ALTER TABLE armazem_conciliacao_recebimentos
            ADD COLUMN id_transacao VARCHAR(50) NULL AFTER id_granito
        ");
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'armazem_conciliacao_recebimentos'
          AND index_name = 'idx_conc_rec_granito_ids'
    ");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("
            ALTER TABLE armazem_conciliacao_recebimentos
            ADD INDEX idx_conc_rec_granito_ids (empresa_id, id_granito, id_transacao)
        ");
    }
}

function garantirTabelaGranitoAgendaTaxas(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fechamento_granito_agenda_taxas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            identificador VARCHAR(120) NOT NULL,
            data_venda DATETIME NULL,
            data_pagamento DATE NULL,
            valor_bruto DECIMAL(15,4) NOT NULL DEFAULT 0,
            valor_desconto DECIMAL(15,4) NOT NULL DEFAULT 0,
            valor_liquido DECIMAL(15,4) NOT NULL DEFAULT 0,
            parcela INT NOT NULL DEFAULT 1,
            total_parcelas INT NOT NULL DEFAULT 1,
            status VARCHAR(40) NULL,
            bandeira VARCHAR(80) NULL,
            tipo_operacao CHAR(1) NOT NULL,
            arquivo_origem VARCHAR(255) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY ux_granito_agenda_empresa_identificador_parcela (empresa_id, identificador, parcela),
            INDEX idx_granito_agenda_empresa_tipo (empresa_id, tipo_operacao, bandeira),
            INDEX idx_granito_agenda_pagamento (empresa_id, data_pagamento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'fechamento_granito_agenda_taxas'
          AND index_name = 'ux_granito_agenda_empresa_identificador'
    ");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() > 0) {
        $pdo->exec("ALTER TABLE fechamento_granito_agenda_taxas DROP INDEX ux_granito_agenda_empresa_identificador");
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'fechamento_granito_agenda_taxas'
          AND index_name = 'ux_granito_agenda_empresa_identificador_parcela'
    ");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("
            ALTER TABLE fechamento_granito_agenda_taxas
            ADD UNIQUE KEY ux_granito_agenda_empresa_identificador_parcela (empresa_id, identificador, parcela)
        ");
    }
}

function salvarGranitoAgendaTaxa(
    PDO $pdo,
    int $empresaId,
    string $identificador,
    ?string $dataVenda,
    ?string $dataPagamento,
    float $valorBruto,
    float $valorDesconto,
    float $valorLiquido,
    int $parcela,
    int $totalParcelas,
    string $status,
    string $bandeira,
    string $tipoOperacao,
    string $arquivoOrigem
): void {
    garantirTabelaGranitoAgendaTaxas($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO fechamento_granito_agenda_taxas (
            empresa_id, identificador, data_venda, data_pagamento,
            valor_bruto, valor_desconto, valor_liquido,
            parcela, total_parcelas, status, bandeira, tipo_operacao, arquivo_origem
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            data_venda = VALUES(data_venda),
            data_pagamento = VALUES(data_pagamento),
            valor_bruto = VALUES(valor_bruto),
            valor_desconto = VALUES(valor_desconto),
            valor_liquido = VALUES(valor_liquido),
            parcela = VALUES(parcela),
            total_parcelas = VALUES(total_parcelas),
            status = VALUES(status),
            bandeira = VALUES(bandeira),
            tipo_operacao = VALUES(tipo_operacao),
            arquivo_origem = VALUES(arquivo_origem)
    ");
    $stmt->execute([
        $empresaId,
        $identificador,
        $dataVenda,
        $dataPagamento,
        $valorBruto,
        $valorDesconto,
        $valorLiquido,
        max(1, $parcela),
        max(1, $totalParcelas),
        $status,
        $bandeira,
        $tipoOperacao,
        $arquivoOrigem,
    ]);
}

function aplicarGranitoAgendaTaxaNoRecebimento(PDO $pdo, int $empresaId, string $identificador): int
{
    garantirTabelaGranitoAgendaTaxas($pdo);
    garantirCamposGranitoRecebimentos($pdo);

    $stmt = $pdo->prepare("
        UPDATE armazem_conciliacao_recebimentos r
        INNER JOIN (
            SELECT
                empresa_id,
                identificador,
                MAX(data_pagamento) AS data_pagamento,
                SUM(valor_desconto) AS valor_desconto,
                SUM(valor_liquido) AS valor_liquido,
                MIN(parcela) AS parcela,
                MAX(total_parcelas) AS total_parcelas,
                MAX(status) AS status,
                MAX(bandeira) AS bandeira,
                MAX(tipo_operacao) AS tipo_operacao,
                MAX(arquivo_origem) AS arquivo_origem
            FROM fechamento_granito_agenda_taxas
            WHERE empresa_id = ?
              AND identificador = ?
            GROUP BY empresa_id, identificador
        ) a ON a.empresa_id = r.empresa_id
            AND a.identificador = r.identificador
        SET r.data_prevista = a.data_pagamento,
            r.data_recebimento = a.data_pagamento,
            r.valor_desconto = a.valor_desconto,
            r.valor_liquido = a.valor_liquido,
            r.parcela = a.parcela,
            r.total_parcelas = a.total_parcelas,
            r.status = a.status,
            r.bandeira = COALESCE(NULLIF(a.bandeira, ''), r.bandeira),
            r.tipo_operacao = a.tipo_operacao,
            r.id_granito = SUBSTRING(a.identificador, 13),
            r.id_transacao = SUBSTRING(a.identificador, 13),
            r.arquivo_origem = CASE
                WHEN r.arquivo_origem LIKE CONCAT('%', a.arquivo_origem, '%') THEN r.arquivo_origem
                ELSE LEFT(CONCAT(COALESCE(NULLIF(r.arquivo_origem, ''), ''), ' | agenda: ', a.arquivo_origem), 255)
            END
        WHERE r.empresa_id = ?
          AND r.identificador = ?
    ");
    $stmt->execute([$empresaId, $identificador, $empresaId, $identificador]);

    return $stmt->rowCount();
}
