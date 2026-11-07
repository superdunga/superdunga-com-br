<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';
require_once '../../config/pagseguro_ofx.php';
require '../../layout/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$empresa_id = (int)$_SESSION['empresa_id'];
$fallback = regraImportacaoFallbackPorOrigem('PAGSEGURO_PIX');
$regraImportacao = buscarRegraImportacao($pdo_master, $empresa_id, 'pagseguro_pix', $fallback);

if (!$regraImportacao) {
    echo "<div class='alert alert-warning'>Nenhuma regra de importacao PagSeguro PIX cadastrada para esta empresa.</div>";
    require '../../layout/footer.php';
    exit;
}

function pagseguroNormalizarCabecalho($valor) {
    $valor = preg_replace('/^\xEF\xBB\xBF/', '', (string)$valor);
    $valor = strtoupper(trim($valor));
    $convertido = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
    if ($convertido !== false) {
        $valor = $convertido;
    }
    return preg_replace('/\s+/', ' ', $valor);
}

function pagseguroTratarValor($valor) {
    $valor = str_replace(['R$', ' ', '.'], '', $valor);
    $valor = str_replace(',', '.', $valor);
    return (float)$valor;
}

function pagseguroValorValido($valor) {
    $valor = trim(str_replace('R$', '', (string)$valor));
    $valor = str_replace(' ', '', $valor);
    return preg_match('/^-?(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,2})?$/', $valor) === 1;
}

function pagseguroDataValida($data) {
    $dataObj = DateTime::createFromFormat('!d/m/Y', trim((string)$data));
    $erros = DateTime::getLastErrors();
    return $dataObj !== false
        && ($erros === false || ((int)$erros['warning_count'] === 0 && (int)$erros['error_count'] === 0))
        && $dataObj->format('d/m/Y') === trim((string)$data);
}

function pagseguroCodigoValido($codigo) {
    return preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', trim((string)$codigo)) === 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo'])) {
    $arquivo = $_FILES['arquivo']['tmp_name'];
    $nomeArquivo = $_FILES['arquivo']['name'];

    $extensao = strtolower(pathinfo($nomeArquivo, PATHINFO_EXTENSION));
    if (!in_array($extensao, ['csv', 'ofx'], true)) {
        echo "<div class='alert alert-danger'>Arquivo inválido: selecione um arquivo CSV ou OFX.</div>";
        require '../../layout/footer.php';
        exit;
    }

    if (!file_exists($arquivo)) {
        echo "<div class='alert alert-danger'>Arquivo não encontrado.</div>";
    } else {
        $registros = [];
        $errosArquivo = [];

        if ($extensao === 'ofx') {
            [$registros, $errosArquivo] = pagseguroLerOfx($arquivo);
        } else {
            $handle = fopen($arquivo, 'r');
            if (!$handle) {
                echo "<div class='alert alert-danger'>Erro ao abrir arquivo.</div>";
                require '../../layout/footer.php';
                exit;
            }

            $cabecalhoEsperado = [
                'CODIGO DA TRANSACAO',
                'DATA',
                'TIPO',
                'DESCRICAO',
                'VALOR',
            ];
            $cabecalho = fgetcsv($handle, 0, ';');
            $cabecalhoNormalizado = is_array($cabecalho)
                ? array_map('pagseguroNormalizarCabecalho', $cabecalho)
                : [];
            $linha = 1;

            if ($cabecalhoNormalizado !== $cabecalhoEsperado) {
                $errosArquivo[] = 'Cabeçalho incompatível. Esperado: ' . implode(';', $cabecalhoEsperado) . '.';
            }

            while (($dados = fgetcsv($handle, 0, ';')) !== false) {
                $linha++;
                if (count(array_filter($dados, static function ($valor) { return trim((string)$valor) !== ''; })) === 0) {
                    continue;
                }

                if (count($dados) !== 5) {
                    $errosArquivo[] = "Linha {$linha}: quantidade de colunas inválida; esperado 5.";
                    continue;
                }

                $codigo = trim((string)$dados[0]);
                $data = trim((string)$dados[1]);
                $tipo = trim((string)$dados[2]);
                $descricao = trim((string)$dados[3]);
                $valor = trim((string)$dados[4]);
                $errosLinha = [];

                if (!pagseguroCodigoValido($codigo)) {
                    $errosLinha[] = 'código da transação inválido';
                }
                if (!pagseguroDataValida($data)) {
                    $errosLinha[] = 'data inválida';
                }
                if (!in_array(strtolower($tipo), ['pix recebido', 'pix enviado'], true)) {
                    $errosLinha[] = 'tipo deve ser Pix recebido ou Pix enviado';
                }
                if ($descricao === '') {
                    $errosLinha[] = 'descrição não informada';
                }
                if (!pagseguroValorValido($valor)) {
                    $errosLinha[] = 'valor inválido';
                }

                if ($errosLinha) {
                    $errosArquivo[] = "Linha {$linha}: " . implode(', ', $errosLinha) . '.';
                    continue;
                }

                $registros[] = [
                    'codigo' => $codigo,
                    'data' => $data,
                    'tipo' => strtolower($tipo),
                    'descricao' => $descricao,
                    'valor' => pagseguroTratarValor($valor),
                ];
            }

            fclose($handle);

            if (!$registros && !$errosArquivo) {
                $errosArquivo[] = 'O arquivo não possui registros.';
            }
        }

        if ($errosArquivo) {
            echo "<div class='alert alert-danger'><strong>Arquivo não importado.</strong><ul class='mb-0 mt-2'>";
            foreach ($errosArquivo as $erroArquivo) {
                echo '<li>' . htmlspecialchars($erroArquivo) . '</li>';
            }
            echo '</ul></div>';
            require '../../layout/footer.php';
            exit;
        }

        $importados = 0;
            $atualizados = 0;
            $ambiguos = 0;
            $duplicados = 0;
            $ignorados = 0;

            $checkIdentificador = $pdo_master->prepare("
                SELECT id
                FROM armazem_conciliacao_recebimentos
                WHERE empresa_id = ?
                  AND identificador = ?
                LIMIT 1
            ");
            $checkExtratoExistente = $pdo_master->prepare("
                SELECT id
                FROM armazem_conciliacao_recebimentos
                WHERE empresa_id = ?
                  AND origem = 'EXTRATO_BANCARIO'
                  AND DATE(data_venda) = ?
                  AND ABS(valor_bruto - ?) < 0.005
                  AND LOWER(TRIM(COALESCE(pagador, ''))) = LOWER(TRIM(?))
                ORDER BY id
                LIMIT 2
            ");
            $atualizarRecebivelExtrato = $pdo_master->prepare("
                UPDATE armazem_conciliacao_recebimentos
                SET origem = ?,
                    data_venda = ?,
                    data_prevista = ?,
                    valor_bruto = ?,
                    valor_desconto = 0,
                    valor_liquido = ?,
                    identificador = ?,
                    descricao = 'PAGSEGURO PIX',
                    pagador = ?,
                    status = 'LIQUIDADA',
                    arquivo_origem = ?,
                    CMCONTADOR = ?,
                    tipo_operacao = 'P',
                    nsu_transacao = ?,
                    numero_estabelecimento = ''
                WHERE id = ?
                  AND empresa_id = ?
                  AND origem = 'EXTRATO_BANCARIO'
            ");
            $inserirRecebivel = $pdo_master->prepare("
                INSERT IGNORE INTO armazem_conciliacao_recebimentos (
                    empresa_id, origem, data_venda, data_prevista, valor_bruto,
                    valor_desconto, valor_liquido, identificador, descricao, pagador,
                    parcela, total_parcelas, status, arquivo_origem, CMCONTADOR,
                    tipo_operacao, nsu_transacao, numero_estabelecimento
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            try {
                $pdo_master->beginTransaction();
                foreach ($registros as $registro) {
                    if ($registro['tipo'] !== 'pix recebido' || $registro['valor'] <= 0) {
                        $ignorados++;
                        continue;
                    }

                    $codigo = $registro['codigo'];
                    $data = $registro['data'];
                    $descricao = $registro['descricao'];
                    $valor_float = $registro['valor'];
                    if (!empty($registro['data_hora'])) {
                        $dataObj = DateTime::createFromFormat('!Y-m-d H:i:s', $registro['data_hora']);
                    } else {
                        $dataObj = DateTime::createFromFormat('!d/m/Y', $data);
                        $dataObj->setTime(12, 0, 0);
                    }
                    $data_formatada = $dataObj->format('Y-m-d H:i:s');
                    $data_prevista = substr($data_formatada, 0, 10);
                    $identificador = $codigo;

                    $checkIdentificador->execute([$empresa_id, $identificador]);
                    if ($checkIdentificador->fetchColumn()) {
                        $duplicados++;
                        continue;
                    }

                    $dataComparacao = $dataObj->format('Y-m-d');
                    $checkExtratoExistente->execute([$empresa_id, $dataComparacao, $valor_float, $descricao]);
                    $recebiveisExtrato = $checkExtratoExistente->fetchAll(PDO::FETCH_COLUMN);
                    if (count($recebiveisExtrato) > 1) {
                        $ambiguos++;
                        continue;
                    }
                    if (count($recebiveisExtrato) === 1) {
                        $atualizarRecebivelExtrato->execute([
                            $regraImportacao['origem'],
                            $data_formatada,
                            $data_prevista,
                            $valor_float,
                            $valor_float,
                            $identificador,
                            $descricao,
                            $nomeArquivo,
                            (int)$regraImportacao['cm_pix'],
                            $codigo,
                            (int)$recebiveisExtrato[0],
                            $empresa_id,
                        ]);
                        $atualizados += $atualizarRecebivelExtrato->rowCount();
                        continue;
                    }

                    $inserirRecebivel->execute([
                        $empresa_id, $regraImportacao['origem'], $data_formatada, $data_prevista,
                        $valor_float, 0, $valor_float, $identificador, 'PAGSEGURO PIX',
                        $descricao, 1, 1, 'LIQUIDADA', $nomeArquivo,
                        (int)$regraImportacao['cm_pix'], 'P', $codigo, ''
                    ]);
                    if ($inserirRecebivel->rowCount() > 0) {
                        $importados++;
                    } else {
                        $duplicados++;
                    }
                }
                $pdo_master->commit();
            } catch (Throwable $e) {
                if ($pdo_master->inTransaction()) {
                    $pdo_master->rollBack();
                }
                throw $e;
            }

            echo "<div class='alert alert-success'>
                    Importação concluída! Registros importados: <strong>{$importados}</strong>.
                    Recebíveis do extrato atualizados: <strong>{$atualizados}</strong>.
                    Duplicados ignorados: <strong>{$duplicados}</strong>.
                    Saídas ignoradas: <strong>{$ignorados}</strong>.
                  </div>";
            if ($ambiguos > 0) {
                echo "<div class='alert alert-warning'>
                        Registros não importados por possuírem mais de um recebível do extrato compatível: <strong>{$ambiguos}</strong>.
                      </div>";
            }
    }
}
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5>Importar <?= htmlspecialchars($regraImportacao['nome']) ?></h5>
        <a href="importar_recebimentos.php" class="btn btn-secondary btn-sm">← Voltar</a>
    </div>

    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?php if ((int)($regraImportacao['id'] ?? 0) > 0): ?>
                <input type="hidden" name="regra_id" value="<?= (int)$regraImportacao['id'] ?>">
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label">Selecione o arquivo CSV ou OFX</label>
                <input type="file" name="arquivo" class="form-control" accept=".csv,.ofx" required>
            </div>

            <button type="submit" class="btn btn-primary">
                Importar Arquivo
            </button>
        </form>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
