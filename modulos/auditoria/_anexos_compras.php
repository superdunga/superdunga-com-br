<?php

function auditoriaGarantirTabelaAnexosCompras(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auditoria_compra_anexos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            empresa_id INT NOT NULL,
            compra_contador BIGINT NOT NULL,
            arquivo VARCHAR(255) NOT NULL,
            nome_original VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            tamanho BIGINT UNSIGNED NOT NULL DEFAULT 0,
            usuario_id INT NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            excluido_em DATETIME NULL,
            excluido_por INT NULL,
            PRIMARY KEY (id),
            KEY idx_auditoria_compra_anexos_compra (empresa_id, compra_contador, excluido_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function auditoriaPastaAnexosCompras(int $empresaId): string
{
    $pasta = __DIR__ . '/../../uploads/auditoria_compras/' . $empresaId . '/' . date('Ym');
    if (!is_dir($pasta) && !mkdir($pasta, 0775, true) && !is_dir($pasta)) {
        throw new RuntimeException('Nao foi possivel preparar a pasta das fotos.');
    }

    return $pasta;
}

function auditoriaNormalizarArquivos(array $arquivos): array
{
    $normalizados = [];
    $nomes = isset($arquivos['name']) && is_array($arquivos['name']) ? $arquivos['name'] : [];

    foreach ($nomes as $indice => $nome) {
        $erro = (int)($arquivos['error'][$indice] ?? UPLOAD_ERR_NO_FILE);
        if ($erro === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $normalizados[] = [
            'name' => (string)$nome,
            'tmp_name' => (string)($arquivos['tmp_name'][$indice] ?? ''),
            'error' => $erro,
            'size' => (int)($arquivos['size'][$indice] ?? 0),
        ];
    }

    return $normalizados;
}

function auditoriaSalvarFotosCompra(
    PDO $pdo,
    int $empresaId,
    int $compraContador,
    int $usuarioId,
    array $arquivos
): int {
    $fotos = auditoriaNormalizarArquivos($arquivos);
    if (empty($fotos)) {
        throw new RuntimeException('Selecione ao menos uma foto da nota.');
    }
    if (count($fotos) > 12) {
        throw new RuntimeException('Envie no maximo 12 fotos por vez.');
    }

    $stmtCompra = $pdo->prepare("
        SELECT 1
        FROM armazem_est005
        WHERE EMPRESA = ?
          AND COMPRACONTADOR = ?
          AND COALESCE(excluido_firebird, 'N') <> 'S'
        LIMIT 1
    ");
    $stmtCompra->execute([$empresaId, $compraContador]);
    if (!$stmtCompra->fetchColumn()) {
        throw new RuntimeException('A compra informada nao pertence a empresa atual.');
    }

    $tiposPermitidos = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $salvos = [];
    $pasta = auditoriaPastaAnexosCompras($empresaId);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO auditoria_compra_anexos
                (empresa_id, compra_contador, arquivo, nome_original, mime_type, tamanho, usuario_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($fotos as $foto) {
            if ($foto['error'] !== UPLOAD_ERR_OK || $foto['tmp_name'] === '' || !is_uploaded_file($foto['tmp_name'])) {
                throw new RuntimeException('Uma das fotos nao foi recebida corretamente.');
            }
            if ($foto['size'] <= 0 || $foto['size'] > 15 * 1024 * 1024) {
                throw new RuntimeException('Cada foto deve ter no maximo 15 MB.');
            }

            $mime = function_exists('mime_content_type') ? (string)mime_content_type($foto['tmp_name']) : '';
            $dimensoes = @getimagesize($foto['tmp_name']);
            if (!isset($tiposPermitidos[$mime]) || $dimensoes === false) {
                throw new RuntimeException('Envie somente fotos JPG, PNG ou WEBP.');
            }

            $nomeArquivo = 'compra_' . $compraContador . '_' . date('YmdHis') . '_'
                . bin2hex(random_bytes(6)) . '.' . $tiposPermitidos[$mime];
            $destino = $pasta . DIRECTORY_SEPARATOR . $nomeArquivo;

            if (!move_uploaded_file($foto['tmp_name'], $destino)) {
                throw new RuntimeException('Nao foi possivel salvar uma das fotos.');
            }
            $salvos[] = $destino;

            $arquivoRelativo = 'uploads/auditoria_compras/' . $empresaId . '/' . date('Ym') . '/' . $nomeArquivo;
            $nomeOriginal = function_exists('mb_substr')
                ? mb_substr(basename($foto['name']), 0, 255)
                : substr(basename($foto['name']), 0, 255);
            $stmt->execute([
                $empresaId,
                $compraContador,
                $arquivoRelativo,
                $nomeOriginal,
                $mime,
                $foto['size'],
                $usuarioId,
            ]);
        }

        $pdo->commit();
        return count($salvos);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($salvos as $arquivoSalvo) {
            if (is_file($arquivoSalvo)) {
                @unlink($arquivoSalvo);
            }
        }
        throw $e;
    }
}

function auditoriaExcluirFotoCompra(PDO $pdo, int $empresaId, int $anexoId, int $usuarioId): bool
{
    $stmt = $pdo->prepare("
        UPDATE auditoria_compra_anexos
        SET excluido_em = NOW(), excluido_por = ?
        WHERE id = ?
          AND empresa_id = ?
          AND excluido_em IS NULL
    ");
    $stmt->execute([$usuarioId, $anexoId, $empresaId]);

    return $stmt->rowCount() === 1;
}

