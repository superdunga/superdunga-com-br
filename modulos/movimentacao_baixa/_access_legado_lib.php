<?php

function garantirTabelaMovBaixaLancamentosAccess(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mov_baixa_lancamentos_access (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            arquivo_nome VARCHAR(255) NULL,
            arquivo_hash CHAR(64) NULL,
            linha_origem INT NOT NULL DEFAULT 0,
            codigo_origem VARCHAR(60) NOT NULL,
            lancamento_origem VARCHAR(60) NULL,
            cod_empresa_origem VARCHAR(120) NULL,
            cod_conta_origem VARCHAR(120) NULL,
            cod_centro_custo_origem VARCHAR(120) NULL,
            historico_centro_custo_origem VARCHAR(255) NULL,
            cod_favorecido_origem VARCHAR(180) NULL,
            bandeira_origem VARCHAR(120) NULL,
            data_pagamento_origem DATE NULL,
            data_emissao_origem DATE NULL,
            cod_historico_origem VARCHAR(180) NULL,
            documento_origem VARCHAR(180) NULL,
            cheque_origem VARCHAR(80) NULL,
            nota_fiscal_origem VARCHAR(80) NULL,
            valor_origem DECIMAL(15,2) NOT NULL DEFAULT 0,
            debito_origem DECIMAL(15,2) NOT NULL DEFAULT 0,
            credito_origem DECIMAL(15,2) NOT NULL DEFAULT 0,
            referencia_origem VARCHAR(20) NULL,
            conferido_origem CHAR(1) NOT NULL DEFAULT 'N',
            conferido2_origem CHAR(1) NOT NULL DEFAULT 'N',
            observacao_origem TEXT NULL,
            funcionario_origem VARCHAR(180) NULL,
            comp_origem VARCHAR(80) NULL,
            banco_origem VARCHAR(80) NULL,
            agencia_origem VARCHAR(80) NULL,
            c1_origem VARCHAR(80) NULL,
            n_conta_origem VARCHAR(80) NULL,
            c2_origem VARCHAR(80) NULL,
            serie_origem VARCHAR(80) NULL,
            cheque_n_origem VARCHAR(80) NULL,
            c3_origem VARCHAR(80) NULL,
            correntista_origem VARCHAR(180) NULL,
            cpf_cnpj_origem VARCHAR(30) NULL,
            juros_origem DECIMAL(15,2) NOT NULL DEFAULT 0,
            data_digitacao_origem DATE NULL,
            data_bom_para_origem DATE NULL,
            ndias_origem INT NULL,
            taxa_origem DECIMAL(10,4) NOT NULL DEFAULT 0,
            contra_partida_origem VARCHAR(120) NULL,
            status_origem VARCHAR(40) NULL,
            foto_origem VARCHAR(255) NULL,
            local_ticket_origem VARCHAR(180) NULL,
            pessoa_ticket_origem VARCHAR(180) NULL,
            movcontador_origem INT NULL,
            chave_comparacao CHAR(64) NULL,
            status_controle VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
            resultado_comparacao VARCHAR(40) NOT NULL DEFAULT 'NAO_COMPARADO',
            enviado_superdunga CHAR(1) NOT NULL DEFAULT 'N',
            enviado_em DATETIME NULL,
            enviado_por INT NULL,
            tabela_destino VARCHAR(60) NULL,
            id_destino INT NULL,
            movcontador_destino INT NULL,
            crcontador_destino INT NULL,
            cpcontador_destino INT NULL,
            comparado_em DATETIME NULL,
            comparado_por INT NULL,
            observacao_controle TEXT NULL,
            dados_originais LONGTEXT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mbla_empresa_arquivo_linha (empresa_id, arquivo_hash, linha_origem),
            INDEX idx_mbla_empresa_codigo (empresa_id, codigo_origem),
            INDEX idx_mbla_empresa_status (empresa_id, status_controle),
            INDEX idx_mbla_empresa_enviado (empresa_id, enviado_superdunga),
            INDEX idx_mbla_conta_data (empresa_id, cod_conta_origem, data_emissao_origem),
            INDEX idx_mbla_comparacao (empresa_id, chave_comparacao),
            INDEX idx_mbla_destino (empresa_id, tabela_destino, id_destino)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $pdo->exec("ALTER TABLE mov_baixa_lancamentos_access ADD COLUMN linha_origem INT NOT NULL DEFAULT 0 AFTER arquivo_hash");
    } catch (Throwable $e) {
        // Coluna ja existente em bases atualizadas.
    }

    try {
        $pdo->exec("ALTER TABLE mov_baixa_lancamentos_access DROP INDEX uniq_mbla_empresa_codigo");
    } catch (Throwable $e) {
        // Indice antigo inexistente em bases atualizadas.
    }

    try {
        $pdo->exec("ALTER TABLE mov_baixa_lancamentos_access ADD UNIQUE KEY uniq_mbla_empresa_arquivo_linha (empresa_id, arquivo_hash, linha_origem)");
    } catch (Throwable $e) {
        // Indice ja existente em bases atualizadas.
    }

    try {
        $pdo->exec("ALTER TABLE mov_baixa_lancamentos_access ADD INDEX idx_mbla_empresa_codigo (empresa_id, codigo_origem)");
    } catch (Throwable $e) {
        // Indice ja existente em bases atualizadas.
    }
}

function garantirTabelaMovBaixaContasAccess(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mov_baixa_contas_access (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            codigo_origem VARCHAR(60) NOT NULL,
            cod_conta_origem VARCHAR(60) NOT NULL,
            descricao_conta VARCHAR(255) NULL,
            movimento CHAR(1) NOT NULL DEFAULT 'N',
            tipo_conta VARCHAR(80) NULL,
            banco_bnc002 INT NULL,
            agencia VARCHAR(80) NULL,
            numero_conta VARCHAR(80) NULL,
            dados_originais LONGTEXT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mbca_empresa_codconta (empresa_id, cod_conta_origem),
            INDEX idx_mbca_empresa_banco (empresa_id, banco_bnc002)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $pdo->exec("ALTER TABLE mov_baixa_contas_access ADD COLUMN banco_bnc002 INT NULL AFTER tipo_conta");
    } catch (Throwable $e) {
        // Coluna ja existente em bases atualizadas.
    }
}
