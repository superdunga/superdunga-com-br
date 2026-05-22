<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';
require_once '../../config/inter_pix.php';
require '../../layout/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$empresa_id = (int)$_SESSION['empresa_id'];
$regraImportacao = buscarRegraImportacao($pdo_master, $empresa_id, 'granito_pix_comercial', []);
$mensagens = [];

if (!$regraImportacao) {
    echo "<div class='alert alert-warning'>Nenhuma regra de importacao Granito PIX Comercial cadastrada para esta empresa.</div>";
    require '../../layout/footer.php';
    exit;
}

function granitoPixValor($valor) {
    $valor = trim((string)$valor);
    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }
    return (float)$valor;
}

function granitoPixDataHora($valor) {
    $dt = DateTime::createFromFormat('d/m/Y - H:i:s', trim((string)$valor));
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

function granitoPixData($valor) {
    $dt = DateTime::createFromFormat('d/m/Y', trim((string)$valor));
    return $dt ? $dt->format('Y-m-d') : null;
}

function granitoPixNormalizarCabecalho($valor) {
    $valor = strtolower(trim((string)$valor));
    $mapa = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
        'é' => 'e', 'ê' => 'e',
        'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u',
        'ç' => 'c',
    ];
    return strtr($valor, $mapa);
}

function granitoPixCampo(array $dados, array $cabecalho, array $nomes): string {
    foreach ($nomes as $nome) {
        $indice = array_search($nome, $cabecalho, true);
        if ($indice !== false && isset($dados[$indice])) {
            return trim((string)$dados[$indice]);
        }
    }
    return '';
}

function pastaInterPixEmpresa(int $empresaId): string
{
    $base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inter_pix';
    $pasta = $base . DIRECTORY_SEPARATOR . 'empresa_' . $empresaId;

    if (!is_dir($base)) {
        mkdir($base, 0755, true);
    }

    $htaccess = $base . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
    }

    if (!is_dir($pasta)) {
        mkdir($pasta, 0755, true);
    }

    return $pasta;
}

function nomeSeguroInterPix(string $nome): string
{
    $nome = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($nome));
    return trim($nome, '._') ?: ('arquivo_' . date('YmdHis'));
}

function salvarUploadInterPix(array $arquivo, int $empresaId, string $prefixo): ?string
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erro ao receber arquivo ' . $prefixo . '.');
    }

    $extensao = strtolower(pathinfo((string)$arquivo['name'], PATHINFO_EXTENSION));
    $permitidas = ['crt', 'pem', 'cer', 'key', 'p12', 'pfx'];
    if (!in_array($extensao, $permitidas, true)) {
        throw new RuntimeException('Arquivo ' . $prefixo . ' invalido. Use crt, pem, cer, key, p12 ou pfx.');
    }

    $pasta = pastaInterPixEmpresa($empresaId);
    $destino = $pasta . DIRECTORY_SEPARATOR . $prefixo . '_' . date('YmdHis') . '_' . nomeSeguroInterPix((string)$arquivo['name']);

    if (!move_uploaded_file((string)$arquivo['tmp_name'], $destino)) {
        throw new RuntimeException('Nao foi possivel salvar arquivo ' . $prefixo . '.');
    }

    return $destino;
}

function salvarZipInterPix(array $arquivo, int $empresaId): array
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [];
    }

    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erro ao receber ZIP do Inter.');
    }

    if (strtolower(pathinfo((string)$arquivo['name'], PATHINFO_EXTENSION)) !== 'zip') {
        throw new RuntimeException('O pacote do Inter precisa ser um arquivo .zip.');
    }

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Extensao ZipArchive nao esta habilitada no PHP. Envie certificado e chave separadamente.');
    }

    $pasta = pastaInterPixEmpresa($empresaId);
    $resultado = [];
    $zip = new ZipArchive();
    if ($zip->open((string)$arquivo['tmp_name']) !== true) {
        throw new RuntimeException('Nao foi possivel abrir o ZIP do Inter.');
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nome = $zip->getNameIndex($i);
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (!in_array($ext, ['crt', 'pem', 'cer', 'key', 'p12', 'pfx'], true)) {
            continue;
        }

        $conteudo = $zip->getFromIndex($i);
        if ($conteudo === false || $conteudo === '') {
            continue;
        }

        $prefixo = in_array($ext, ['key'], true) ? 'key' : 'cert';
        $destino = $pasta . DIRECTORY_SEPARATOR . $prefixo . '_' . date('YmdHis') . '_' . nomeSeguroInterPix($nome);
        file_put_contents($destino, $conteudo);

        if ($prefixo === 'key') {
            $resultado['key_path'] = $destino;
        } else {
            $resultado['cert_path'] = $destino;
        }
    }

    $zip->close();

    if (empty($resultado['cert_path'])) {
        throw new RuntimeException('Nenhum certificado foi encontrado dentro do ZIP do Inter.');
    }

    return $resultado;
}

garantirTabelaInterPix($pdo_master);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_inter_pix') {
    $configAtual = buscarConfigInterPix($pdo_master, $empresa_id) ?: [];
    $certPath = trim((string)($_POST['cert_path'] ?? ($configAtual['cert_path'] ?? '')));
    $keyPath = trim((string)($_POST['key_path'] ?? ($configAtual['key_path'] ?? '')));

    $dadosConfig = [
        'ambiente' => in_array(($_POST['ambiente'] ?? ''), ['producao', 'sandbox'], true) ? $_POST['ambiente'] : 'producao',
        'client_id' => trim((string)($_POST['client_id'] ?? '')),
        'client_secret' => trim((string)($_POST['client_secret'] ?? '')),
        'conta_corrente' => trim((string)($_POST['conta_corrente'] ?? '')),
        'cert_path' => $certPath,
        'key_path' => $keyPath,
        'cert_password' => trim((string)($_POST['cert_password'] ?? '')),
        'ativo' => ($_POST['ativo'] ?? 'S') === 'S' ? 'S' : 'N',
    ];

    try {
        if (isset($_FILES['pacote_inter'])) {
            $arquivosZip = salvarZipInterPix($_FILES['pacote_inter'], $empresa_id);
            $dadosConfig = array_merge($dadosConfig, $arquivosZip);
        }

        if (isset($_FILES['certificado_inter'])) {
            $certUpload = salvarUploadInterPix($_FILES['certificado_inter'], $empresa_id, 'cert');
            if ($certUpload !== null) {
                $dadosConfig['cert_path'] = $certUpload;
            }
        }

        if (isset($_FILES['chave_inter'])) {
            $keyUpload = salvarUploadInterPix($_FILES['chave_inter'], $empresa_id, 'key');
            if ($keyUpload !== null) {
                $dadosConfig['key_path'] = $keyUpload;
            }
        }

        salvarConfigInterPix($pdo_master, $empresa_id, $dadosConfig);
        $mensagens[] = ['tipo' => 'success', 'texto' => 'Configuracao da API Inter Pix salva com sucesso.'];
    } catch (Throwable $e) {
        $mensagens[] = ['tipo' => 'danger', 'texto' => 'Erro ao salvar configuracao Inter Pix: ' . $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'testar_inter_pix') {
    try {
        $configTeste = buscarConfigInterPix($pdo_master, $empresa_id);
        if (!$configTeste || ($configTeste['ativo'] ?? 'N') !== 'S') {
            throw new RuntimeException('Configuracao da API Inter Pix nao cadastrada ou inativa.');
        }

        obterTokenInterPix($pdo_master, $empresa_id, $configTeste);
        $mensagens[] = ['tipo' => 'success', 'texto' => 'Conexao com Banco Inter validada com sucesso. Token OAuth obtido.'];
    } catch (Throwable $e) {
        $mensagens[] = ['tipo' => 'danger', 'texto' => 'Erro no teste da API Inter Pix: ' . $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'consultar_inter_pix') {
    $dataIniInter = $_POST['data_ini_inter'] ?? date('Y-m-d');
    $dataFimInter = $_POST['data_fim_inter'] ?? date('Y-m-d');

    try {
        $resultadoInter = consultarInterPix($pdo_master, $empresa_id, $regraImportacao, $dataIniInter, $dataFimInter);
        $mensagens[] = [
            'tipo' => 'success',
            'texto' => 'Consulta Inter Pix concluida. Lidos: ' . (int)$resultadoInter['lidos']
                . ' | Importados: ' . (int)$resultadoInter['importados']
                . ' | Atualizados: ' . (int)$resultadoInter['atualizados']
                . ' | Ignorados: ' . (int)$resultadoInter['ignorados'],
        ];
    } catch (Throwable $e) {
        $mensagens[] = ['tipo' => 'danger', 'texto' => 'Erro na consulta Inter Pix: ' . $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'upload_csv' && isset($_FILES['arquivo'])) {
    $arquivo = $_FILES['arquivo']['tmp_name'];
    $nomeArquivo = $_FILES['arquivo']['name'];

    if (!file_exists($arquivo)) {
        $mensagens[] = ['tipo' => 'danger', 'texto' => 'Arquivo nao encontrado.'];
    } else {
        $handle = fopen($arquivo, 'r');

        if (!$handle) {
            $mensagens[] = ['tipo' => 'danger', 'texto' => 'Erro ao abrir arquivo.'];
        } else {
            $linha = 0;
            $importados = 0;
            $cabecalho = [];

            while (($dados = fgetcsv($handle, 0, ';')) !== false) {
                $linha++;
                if ($linha === 1) {
                    $cabecalho = array_map('granitoPixNormalizarCabecalho', $dados);
                    continue;
                }
                if (count($dados) < 11) continue;

                $idTransacao = trim($dados[0] ?? '');
                $dataVenda = granitoPixDataHora($dados[1] ?? '');
                $tipo = trim($dados[2] ?? '');
                $status = trim($dados[3] ?? '');
                $parcelaTexto = trim($dados[4] ?? '1/1');
                $bandeira = trim($dados[5] ?? '');
                $valorBruto = granitoPixValor($dados[6] ?? 0);
                $valorTaxa = granitoPixValor($dados[7] ?? 0);
                $valorAntecipacao = granitoPixValor($dados[8] ?? 0);
                $valorLiquido = granitoPixValor($dados[9] ?? 0);
                $dataPagamento = granitoPixData($dados[10] ?? '');
                $pagador = granitoPixCampo($dados, $cabecalho, [
                    'cliente',
                    'nome cliente',
                    'nome do cliente',
                    'nome',
                    'nome completo',
                    'pagador',
                    'nome pagador',
                    'nome do pagador',
                    'remetente',
                    'nome remetente',
                    'nome do remetente',
                    'remetente pix',
                    'nome remetente pix',
                    'nome do remetente pix',
                    'comprador',
                    'nome comprador',
                    'nome do comprador',
                    'titular',
                    'nome titular',
                    'nome do titular',
                    'razao social',
                    'razao social pagador',
                    'cpf/cnpj pagador',
                    'documento pagador',
                ]);

                if ($idTransacao === '' || $dataVenda === null || strcasecmp($tipo, 'Pagamento Instantâneo') !== 0 || strcasecmp($status, 'Pago') !== 0 || $valorBruto <= 0) {
                    continue;
                }

                [$parcela, $totalParcelas] = array_pad(array_map('intval', explode('/', $parcelaTexto)), 2, 1);
                $origem = $regraImportacao['origem'];
                $identificador = 'GRANITO_PIX_' . $idTransacao;

                $check = $pdo_master->prepare("SELECT id FROM armazem_conciliacao_recebimentos WHERE empresa_id = ? AND identificador = ?");
                $check->execute([$empresa_id, $identificador]);
                $registroExistente = $check->fetch(PDO::FETCH_ASSOC);
                if ($registroExistente) {
                    if ($pagador !== '') {
                        $updatePagador = $pdo_master->prepare("
                            UPDATE armazem_conciliacao_recebimentos
                            SET pagador = ?
                            WHERE id = ?
                              AND (pagador IS NULL OR pagador = '' OR pagador = 'GRANITO PIX')
                        ");
                        $updatePagador->execute([$pagador, $registroExistente['id']]);
                    }
                    continue;
                }

                $stmt = $pdo_master->prepare("
                    INSERT INTO armazem_conciliacao_recebimentos (
                        empresa_id, origem, data_venda, data_prevista, data_recebimento,
                        valor_bruto, valor_desconto, valor_liquido, identificador, descricao,
                        pagador, parcela, total_parcelas, status, arquivo_origem, CMCONTADOR,
                        tipo_operacao, bandeira, nsu_transacao, numero_estabelecimento
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PIX - GRANITO', ?,
                        ?, ?, ?, ?, ?, 'P', ?, ?, ''
                    )
                ");
                $stmt->execute([
                    $empresa_id,
                    $origem,
                    $dataVenda,
                    $dataPagamento,
                    $dataPagamento,
                    $valorBruto,
                    $valorTaxa + $valorAntecipacao,
                    $valorLiquido,
                    $identificador,
                    $pagador !== '' ? $pagador : 'GRANITO PIX',
                    $parcela ?: 1,
                    $totalParcelas ?: 1,
                    $status,
                    $nomeArquivo,
                    (int)$regraImportacao['cm_pix'],
                    $bandeira,
                    $idTransacao,
                ]);

                $importados++;
            }

            fclose($handle);
            $mensagens[] = ['tipo' => 'success', 'texto' => "Importacao concluida! Registros importados: {$importados}"];
        }
    }
}

$configInterPix = buscarConfigInterPix($pdo_master, $empresa_id) ?: [
    'ambiente' => 'producao',
    'client_id' => '',
    'conta_corrente' => '',
    'cert_path' => '',
    'key_path' => '',
    'ativo' => 'S',
];
?>

<?php foreach ($mensagens as $mensagem): ?>
    <div class="alert alert-<?= htmlspecialchars($mensagem['tipo']) ?>">
        <?= htmlspecialchars($mensagem['texto']) ?>
    </div>
<?php endforeach; ?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5>Importar <?= htmlspecialchars($regraImportacao['nome']) ?></h5>
        <a href="importar_recebimentos.php" class="btn btn-secondary btn-sm">Voltar</a>
    </div>

    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="acao" value="upload_csv">
            <input type="hidden" name="regra_id" value="<?= (int)$regraImportacao['id'] ?>">
            <div class="mb-3">
                <label class="form-label">Selecione o arquivo CSV de agenda Granito</label>
                <input type="file" name="arquivo" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Importar Arquivo</button>
        </form>
    </div>
</div>

<div class="card shadow-sm mt-3">
    <div class="card-header">
        <h5 class="mb-0">Consulta automatica Banco Inter Pix</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info small">
            Esta consulta usa o endpoint Pix do Banco Inter e alimenta esta mesma regra de importacao
            com CMCONTADOR <?= (int)$regraImportacao['cm_pix'] ?>. O identificador gravado sera o EndToEndId do Pix.
        </div>

        <form method="POST" class="row g-3 mb-4">
            <input type="hidden" name="acao" value="consultar_inter_pix">
            <input type="hidden" name="regra_id" value="<?= (int)$regraImportacao['id'] ?>">
            <div class="col-md-4">
                <label class="form-label">Data inicial</label>
                <input type="date" name="data_ini_inter" value="<?= htmlspecialchars($_POST['data_ini_inter'] ?? date('Y-m-d')) ?>" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Data final</label>
                <input type="date" name="data_fim_inter" value="<?= htmlspecialchars($_POST['data_fim_inter'] ?? date('Y-m-d')) ?>" class="form-control" required>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Consultar e importar Pix</button>
            </div>
        </form>

        <div class="border rounded p-3 bg-light">
            <h6 class="fw-bold mb-3">Configuracao da API Inter</h6>
            <form method="POST" enctype="multipart/form-data" class="row g-3">
                <input type="hidden" name="acao" value="salvar_inter_pix">
                <input type="hidden" name="regra_id" value="<?= (int)$regraImportacao['id'] ?>">
                <div class="col-md-3">
                    <label class="form-label">Ambiente</label>
                    <select name="ambiente" class="form-select">
                        <option value="producao" <?= ($configInterPix['ambiente'] ?? '') === 'producao' ? 'selected' : '' ?>>Producao</option>
                        <option value="sandbox" <?= ($configInterPix['ambiente'] ?? '') === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Conta corrente</label>
                    <input type="text" name="conta_corrente" value="<?= htmlspecialchars($configInterPix['conta_corrente'] ?? '') ?>" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Ativo</label>
                    <select name="ativo" class="form-select">
                        <option value="S" <?= ($configInterPix['ativo'] ?? 'S') === 'S' ? 'selected' : '' ?>>Sim</option>
                        <option value="N" <?= ($configInterPix['ativo'] ?? 'S') === 'N' ? 'selected' : '' ?>>Nao</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Client ID</label>
                    <input type="text" name="client_id" value="<?= htmlspecialchars($configInterPix['client_id'] ?? '') ?>" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Client Secret</label>
                    <input type="password" name="client_secret" value="" class="form-control" placeholder="<?= !empty($configInterPix['client_secret']) ? 'Preenchido - informe apenas para alterar' : '' ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Senha do certificado</label>
                    <input type="password" name="cert_password" value="" class="form-control" placeholder="<?= !empty($configInterPix['cert_password']) ? 'Preenchida - informe apenas para alterar' : '' ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Pacote do Inter (.zip)</label>
                    <input type="file" name="pacote_inter" class="form-control" accept=".zip">
                    <div class="form-text">Use o ZIP baixado em "Download chave e certificado".</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Certificado separado</label>
                    <input type="file" name="certificado_inter" class="form-control" accept=".crt,.pem,.cer,.p12,.pfx">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Chave privada separada</label>
                    <input type="file" name="chave_inter" class="form-control" accept=".key,.pem">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Caminho do certificado salvo</label>
                    <input type="text" name="cert_path" value="<?= htmlspecialchars($configInterPix['cert_path'] ?? '') ?>" class="form-control" placeholder="/home/usuario/certs/inter.crt">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Caminho da chave privada salva</label>
                    <input type="text" name="key_path" value="<?= htmlspecialchars($configInterPix['key_path'] ?? '') ?>" class="form-control" placeholder="/home/usuario/certs/inter.key">
                </div>
                <div class="col-12">
                    <div class="small text-muted">
                        Para PFX/P12, informe apenas certificado e senha. Para PEM/CRT/CER, informe tambem a chave privada. Os caminhos sao preenchidos automaticamente quando os arquivos sao enviados por upload.
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-primary w-100">Salvar configuracao</button>
                </div>
            </form>
            <form method="POST" class="mt-3">
                <input type="hidden" name="acao" value="testar_inter_pix">
                <input type="hidden" name="regra_id" value="<?= (int)$regraImportacao['id'] ?>">
                <button type="submit" class="btn btn-outline-success">Testar conexao Inter</button>
            </form>
        </div>
    </div>
</div>

<?php require '../../layout/footer.php'; ?>
