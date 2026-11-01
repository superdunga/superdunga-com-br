<?php

require_once __DIR__ . '/importacao_recebimentos.php';
require_once __DIR__ . '/cielo_extrato.php';

function cieloCaixaGarantirControle(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_caixa_cielo_controle (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            data_caixa DATE NOT NULL,
            pix_ethicos INT NULL,
            pix_vrs INT NULL,
            exigir_ethicos CHAR(1) NULL,
            exigir_vrs CHAR(1) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'AGUARDANDO_PIX',
            detalhes VARCHAR(500) NULL,
            primeira_verificacao_em DATETIME NULL,
            ultima_verificacao_em DATETIME NULL,
            enviado_em DATETIME NULL,
            UNIQUE KEY uq_whatsapp_caixa_cielo_dia (empresa_id, data_caixa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function cieloCaixaConsultarArquivos(array $config, array $regra, string $dataProcessamento): array
{
    $resultado = [
        '03_disponivel' => false,
        '03_registros' => 0,
        '16_disponivel' => false,
        '16_registros' => 0,
    ];

    foreach (cieloGerarLinks($config, $dataProcessamento, $dataProcessamento) as $link) {
        $arquivo = cieloLerArquivo(cieloHttpGet($link), $regra);
        $tipo = (string)($arquivo['cabecalho']['tipo_arquivo'] ?? '');
        if ($tipo !== '03' && $tipo !== '16') {
            continue;
        }

        $resultado[$tipo . '_disponivel'] = true;
        $resultado[$tipo . '_registros'] += count($arquivo['registros'] ?? []);
    }

    return $resultado;
}

function cieloCaixaAtualizarControle(PDO $pdo, int $controleId, array $campos): void
{
    if (!$campos) {
        return;
    }

    $permitidos = [
        'pix_ethicos', 'pix_vrs', 'exigir_ethicos', 'exigir_vrs', 'status',
        'detalhes', 'primeira_verificacao_em', 'ultima_verificacao_em', 'enviado_em',
    ];
    $partes = [];
    $valores = [];
    foreach ($campos as $campo => $valor) {
        if (!in_array($campo, $permitidos, true)) {
            continue;
        }
        $partes[] = $campo . ' = ?';
        $valores[] = $valor;
    }
    if (!$partes) {
        return;
    }

    $valores[] = $controleId;
    $stmt = $pdo->prepare('UPDATE whatsapp_caixa_cielo_controle SET ' . implode(', ', $partes) . ' WHERE id = ?');
    $stmt->execute($valores);
}

function cieloCaixaSincronizarConta(PDO $pdo, int $empresaId, string $tipo, array $fallback, array $config, string $data): array
{
    $regra = buscarRegraImportacao($pdo, $empresaId, $tipo, $fallback);
    if (!$regra) {
        throw new RuntimeException('Regra de importacao Cielo nao encontrada: ' . $tipo . '.');
    }

    return cieloSincronizarRecebimentos($pdo, $empresaId, $regra, $config, $data, $data);
}

function cieloCaixaExecutarMonitor(PDO $pdo, ?DateTimeImmutable $agora = null): array
{
    date_default_timezone_set('America/Sao_Paulo');
    $agora = $agora ?: new DateTimeImmutable('now');
    $empresaId = 1;
    $dataProcessamento = $agora->format('Y-m-d');
    $dataCaixa = $agora->modify('-1 day')->format('Y-m-d');
    $nomeLock = 'superdunga_cielo_caixa_anterior';

    $stmtLock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stmtLock->execute([$nomeLock]);
    if ((int)$stmtLock->fetchColumn() !== 1) {
        return ['status' => 'EM_EXECUCAO', 'data_caixa' => $dataCaixa];
    }

    try {
        cieloCaixaGarantirControle($pdo);
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO whatsapp_caixa_cielo_controle (empresa_id, data_caixa, status)
            VALUES (?, ?, 'AGUARDANDO_PIX')
        ");
        $stmt->execute([$empresaId, $dataCaixa]);

        $stmt = $pdo->prepare('SELECT * FROM whatsapp_caixa_cielo_controle WHERE empresa_id = ? AND data_caixa = ? LIMIT 1');
        $stmt->execute([$empresaId, $dataCaixa]);
        $controle = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$controle) {
            throw new RuntimeException('Nao foi possivel iniciar o controle diario da Cielo.');
        }

        if (in_array((string)$controle['status'], ['ENVIADO', 'SEM_PIX'], true)) {
            return ['status' => $controle['status'], 'data_caixa' => $dataCaixa];
        }

        if (!empty($controle['ultima_verificacao_em'])) {
            $ultima = new DateTimeImmutable((string)$controle['ultima_verificacao_em']);
            if (($agora->getTimestamp() - $ultima->getTimestamp()) < 10 * 60) {
                return ['status' => 'AGUARDANDO_INTERVALO', 'data_caixa' => $dataCaixa];
            }
        }

        cieloCaixaAtualizarControle($pdo, (int)$controle['id'], [
            'ultima_verificacao_em' => $agora->format('Y-m-d H:i:s'),
            'primeira_verificacao_em' => $controle['primeira_verificacao_em'] ?: $agora->format('Y-m-d H:i:s'),
        ]);

        $configEthicos = cieloConfiguracaoEthicos();
        $configVrs = cieloConfiguracaoVrs();
        $regraEthicos = buscarRegraImportacao($pdo, $empresaId, 'cielo_api_outros', regraImportacaoCieloOutrosArmazem());
        $regraVrs = buscarRegraImportacao($pdo, $empresaId, 'cielo_api_comercial', regraImportacaoCieloComercialArmazem());
        if (!$regraEthicos || !$regraVrs) {
            throw new RuntimeException('As duas regras de importacao Cielo precisam estar cadastradas.');
        }

        $exigirEthicos = $controle['exigir_ethicos'];
        $exigirVrs = $controle['exigir_vrs'];
        $arquivosEthicos = null;
        $arquivosVrs = null;

        if ($exigirEthicos === null || $exigirVrs === null) {
            $arquivosEthicos = cieloCaixaConsultarArquivos($configEthicos, $regraEthicos, $dataProcessamento);
            $arquivosVrs = cieloCaixaConsultarArquivos($configVrs, $regraVrs, $dataProcessamento);

            if (!$arquivosEthicos['16_disponivel'] || !$arquivosVrs['16_disponivel']) {
                cieloCaixaAtualizarControle($pdo, (int)$controle['id'], [
                    'status' => 'AGUARDANDO_PIX',
                    'detalhes' => 'Aguardando CIELO16 das duas contas.',
                ]);
                return ['status' => 'AGUARDANDO_PIX', 'data_caixa' => $dataCaixa];
            }

            $pixEthicos = (int)$arquivosEthicos['16_registros'];
            $pixVrs = (int)$arquivosVrs['16_registros'];
            $exigirEthicos = $pixEthicos > 0 ? 'S' : 'N';
            $exigirVrs = $pixVrs > 0 ? 'S' : 'N';

            if ($exigirEthicos === 'N' && $exigirVrs === 'N') {
                cieloCaixaAtualizarControle($pdo, (int)$controle['id'], [
                    'pix_ethicos' => $pixEthicos,
                    'pix_vrs' => $pixVrs,
                    'exigir_ethicos' => 'N',
                    'exigir_vrs' => 'N',
                    'status' => 'SEM_PIX',
                    'detalhes' => 'Nenhuma das contas Cielo possui PIX no dia.',
                ]);
                return ['status' => 'SEM_PIX', 'data_caixa' => $dataCaixa];
            }

            cieloCaixaAtualizarControle($pdo, (int)$controle['id'], [
                'pix_ethicos' => $pixEthicos,
                'pix_vrs' => $pixVrs,
                'exigir_ethicos' => $exigirEthicos,
                'exigir_vrs' => $exigirVrs,
                'status' => 'AGUARDANDO_CARTOES',
                'detalhes' => 'PIX identificado; aguardando CIELO03 das contas utilizadas.',
            ]);
        }

        if ($exigirEthicos === 'S') {
            $arquivosEthicos = $arquivosEthicos ?: cieloCaixaConsultarArquivos($configEthicos, $regraEthicos, $dataProcessamento);
            if (!$arquivosEthicos['03_disponivel']) {
                return ['status' => 'AGUARDANDO_CIELO03_ETHICOS', 'data_caixa' => $dataCaixa];
            }
        }
        if ($exigirVrs === 'S') {
            $arquivosVrs = $arquivosVrs ?: cieloCaixaConsultarArquivos($configVrs, $regraVrs, $dataProcessamento);
            if (!$arquivosVrs['03_disponivel']) {
                return ['status' => 'AGUARDANDO_CIELO03_VRS', 'data_caixa' => $dataCaixa];
            }
        }

        if ($exigirEthicos === 'S') {
            cieloCaixaSincronizarConta($pdo, $empresaId, 'cielo_api_outros', regraImportacaoCieloOutrosArmazem(), $configEthicos, $dataProcessamento);
        }
        if ($exigirVrs === 'S') {
            cieloCaixaSincronizarConta($pdo, $empresaId, 'cielo_api_comercial', regraImportacaoCieloComercialArmazem(), $configVrs, $dataProcessamento);
        }

        $stmt = $pdo->prepare("SELECT * FROM whatsapp_rotinas WHERE empresa_id = ? AND codigo = 'caixa_dia_anterior' LIMIT 1");
        $stmt->execute([$empresaId]);
        $rotina = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rotina) {
            throw new RuntimeException('Rotina Caixa do Dia Anterior nao encontrada.');
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM whatsapp_envios
            WHERE empresa_id = ? AND rotina_id = ? AND status = 'OK'
              AND enviado_em >= ? AND enviado_em < DATE_ADD(?, INTERVAL 1 DAY)
        ");
        $stmt->execute([$empresaId, (int)$rotina['id'], $dataProcessamento, $dataProcessamento]);
        if ((int)$stmt->fetchColumn() > 0) {
            cieloCaixaAtualizarControle($pdo, (int)$controle['id'], [
                'status' => 'ENVIADO',
                'detalhes' => 'Mensagem ja enviada anteriormente.',
                'enviado_em' => $agora->format('Y-m-d H:i:s'),
            ]);
            return ['status' => 'ENVIADO', 'data_caixa' => $dataCaixa, 'ja_enviado' => true];
        }

        $mensagem = whatsappMensagemCaixaDiaAnterior($pdo, $agora->setTime(12, 0), $empresaId);
        $envio = whatsappEnviarRotina($pdo, $rotina, $mensagem, null, null);
        if ((int)($envio['ok'] ?? 0) <= 0 || (int)($envio['falha'] ?? 0) > 0) {
            throw new RuntimeException('A mensagem nao foi entregue a todos os destinatarios.');
        }

        cieloCaixaAtualizarControle($pdo, (int)$controle['id'], [
            'status' => 'ENVIADO',
            'detalhes' => 'Arquivos Cielo importados e mensagem enviada.',
            'enviado_em' => $agora->format('Y-m-d H:i:s'),
        ]);

        return [
            'status' => 'ENVIADO',
            'data_caixa' => $dataCaixa,
            'pix_ethicos' => $controle['pix_ethicos'],
            'pix_vrs' => $controle['pix_vrs'],
            'envio' => $envio,
        ];
    } catch (Throwable $e) {
        if (isset($controle['id'])) {
            cieloCaixaAtualizarControle($pdo, (int)$controle['id'], [
                'status' => 'ERRO',
                'detalhes' => substr($e->getMessage(), 0, 500),
            ]);
        }
        return ['status' => 'ERRO', 'data_caixa' => $dataCaixa, 'erro' => $e->getMessage()];
    } finally {
        $stmtUnlock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmtUnlock->execute([$nomeLock]);
    }
}
