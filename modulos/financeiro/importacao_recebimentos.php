<?php
require '../../config/auth.php';
require '../../config/conexao.php';
require_once '../../config/importacao_recebimentos.php';

$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$ehMaster = strtoupper((string)($_SESSION['nivel'] ?? '')) === 'MASTER';
$mensagem = null;
$erro = null;

if (!$ehMaster) {
    renderizarAcessoNegadoModulo('Somente usuario master pode acessar a importacao de recebimentos do financeiro.');
    exit;
}

garantirTabelaTaxasAdquirentes($pdo_master);
garantirTabelaAgendaAdquirentes($pdo_master);

function garantirTabelaHistoricosExtratoRecebimentos(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_recebimentos_extrato_historicos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            cbcontador INT NOT NULL,
            adquirente VARCHAR(40) NOT NULL,
            grupo VARCHAR(40) NOT NULL,
            tipo_operacao CHAR(1) NOT NULL,
            historico_padrao VARCHAR(190) NOT NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            usuario_id INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_fin_rec_ext_hist_empresa (empresa_id, cbcontador, adquirente, grupo, tipo_operacao, ativo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

garantirTabelaHistoricosExtratoRecebimentos($pdo_master);

function origemAdquirenteSql(): string
{
    return "
        CASE
            WHEN origem LIKE 'GRANITO%' THEN 'GRANITO'
            WHEN origem LIKE 'SIPAG%' THEN 'SIPAG'
            WHEN origem LIKE 'PAGSEGURO%' THEN 'PAGSEGURO'
            ELSE origem
        END
    ";
}

function origemGrupoSql(): string
{
    return "
        CASE
            WHEN origem LIKE '%COMERCIAL%' THEN 'COMERCIAL'
            WHEN origem LIKE '%OUTROS%' THEN 'OUTROS'
            ELSE 'GERAL'
        END
    ";
}

function rotuloTipoOperacao(string $tipo): string
{
    return match (strtoupper($tipo)) {
        'D' => 'DEBITO',
        'C' => 'CREDITO',
        'P' => 'PIX',
        default => strtoupper($tipo),
    };
}

function numeroTransacaoRecebimento(array $transacao): string
{
    $identificador = (string)($transacao['identificador'] ?? '');
    $identificador = preg_replace('/^(GRANITO_POS_|GRANITO_PIX_|SIPAG_POS_|SIPAG_PIX_|PAGSEGURO_PIX_)/', '', $identificador);
    return $identificador !== '' ? $identificador : ('#' . (int)$transacao['id']);
}

function contasConferenciaRecebimentos(): array
{
    return [
        1 => [
            ['adquirente' => 'SIPAG', 'grupo' => 'OUTROS', 'cbcontador' => 18, 'descricao' => 'Sipag Outros'],
            ['adquirente' => 'SIPAG', 'grupo' => 'COMERCIAL', 'cbcontador' => 22, 'descricao' => 'Sipag Comercial'],
            ['adquirente' => 'PAGSEGURO', 'grupo' => 'GERAL', 'cbcontador' => 15, 'descricao' => 'PagSeguro'],
        ],
        4 => [
            ['adquirente' => 'GRANITO', 'grupo' => 'COMERCIAL', 'cbcontador' => 39, 'descricao' => 'Granito Comercial'],
            ['adquirente' => 'GRANITO', 'grupo' => 'OUTROS', 'cbcontador' => 40, 'descricao' => 'Granito Outros'],
        ],
    ];
}

function redirecionarImportacaoRecebimentos(array $extras = []): void
{
    $params = array_merge($_GET, $extras);
    unset($params['editar_historico']);
    header('Location: importacao_recebimentos.php?' . http_build_query($params));
    exit;
}

$adquirentes = ['GRANITO', 'SIPAG', 'PAGSEGURO'];
$grupos = ['COMERCIAL', 'OUTROS', 'GERAL'];
$tipos = ['DEBITO', 'CREDITO', 'PIX'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    try {
        if ($acao === 'salvar_historico_extrato') {
            $idHistorico = (int)($_POST['id'] ?? 0);
            $cbcontadorHistorico = (int)($_POST['cbcontador'] ?? 0);
            $adquirenteHistorico = strtoupper(trim((string)($_POST['adquirente'] ?? '')));
            $grupoHistorico = strtoupper(trim((string)($_POST['grupo'] ?? '')));
            $tipoHistorico = strtoupper(trim((string)($_POST['tipo_operacao'] ?? '')));
            $historicoPadrao = trim((string)($_POST['historico_padrao'] ?? ''));

            if ($cbcontadorHistorico <= 0) {
                throw new RuntimeException('Informe a conta do extrato.');
            }
            if (!in_array($adquirenteHistorico, $adquirentes, true)) {
                throw new RuntimeException('Informe uma adquirente valida.');
            }
            if (!in_array($grupoHistorico, $grupos, true)) {
                throw new RuntimeException('Informe um grupo valido.');
            }
            if (!in_array($tipoHistorico, ['D', 'C', 'P'], true)) {
                throw new RuntimeException('Informe um tipo valido.');
            }
            if ($historicoPadrao === '') {
                throw new RuntimeException('Informe o historico do extrato.');
            }

            if ($idHistorico > 0) {
                $stmtSalvarHistorico = $pdo_master->prepare("
                    UPDATE financeiro_recebimentos_extrato_historicos
                    SET cbcontador = ?, adquirente = ?, grupo = ?, tipo_operacao = ?, historico_padrao = ?, usuario_id = ?
                    WHERE id = ? AND empresa_id = ?
                ");
                $stmtSalvarHistorico->execute([
                    $cbcontadorHistorico,
                    $adquirenteHistorico,
                    $grupoHistorico,
                    $tipoHistorico,
                    $historicoPadrao,
                    (int)($_SESSION['usuario_id'] ?? 0),
                    $idHistorico,
                    $empresaId,
                ]);
            } else {
                $stmtSalvarHistorico = $pdo_master->prepare("
                    INSERT INTO financeiro_recebimentos_extrato_historicos
                        (empresa_id, cbcontador, adquirente, grupo, tipo_operacao, historico_padrao, usuario_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtSalvarHistorico->execute([
                    $empresaId,
                    $cbcontadorHistorico,
                    $adquirenteHistorico,
                    $grupoHistorico,
                    $tipoHistorico,
                    $historicoPadrao,
                    (int)($_SESSION['usuario_id'] ?? 0),
                ]);
            }

            redirecionarImportacaoRecebimentos(['ok_historico' => 1]);
        }

        if ($acao === 'inativar_historico_extrato') {
            $idHistorico = (int)($_POST['id'] ?? 0);
            if ($idHistorico <= 0) {
                throw new RuntimeException('Regra invalida.');
            }

            $stmtInativarHistorico = $pdo_master->prepare("
                UPDATE financeiro_recebimentos_extrato_historicos
                SET ativo = 'N', usuario_id = ?
                WHERE id = ? AND empresa_id = ?
            ");
            $stmtInativarHistorico->execute([(int)($_SESSION['usuario_id'] ?? 0), $idHistorico, $empresaId]);
            redirecionarImportacaoRecebimentos(['ok_historico' => 1]);
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$stmtTaxas = $pdo_master->prepare("
    SELECT *
    FROM fechamento_adquirente_taxas
    WHERE empresa_id = ? AND ativo = 'S'
    ORDER BY adquirente, grupo, tipo_operacao, bandeira, parcelas_de
");
$stmtTaxas->execute([$empresaId]);
$taxasAtivas = $stmtTaxas->fetchAll(PDO::FETCH_ASSOC);

$filtroDataIni = $_GET['data_ini'] ?? '';
$filtroDataFim = $_GET['data_fim'] ?? '';
$filtroVencIni = $_GET['venc_ini'] ?? date('Y-m-01');
$filtroVencFim = $_GET['venc_fim'] ?? date('Y-m-t');
$filtroAdquirente = strtoupper(trim((string)($_GET['adquirente'] ?? '')));
$filtroGrupo = strtoupper(trim((string)($_GET['grupo'] ?? '')));
$filtroTipo = strtoupper(trim((string)($_GET['tipo'] ?? '')));
$filtroBandeira = strtoupper(trim((string)($_GET['bandeira'] ?? '')));
$filtroSituacao = $_GET['situacao'] ?? 'todos';

if (isset($_GET['ok_historico'])) {
    $mensagem = 'Regra de historico do extrato salva com sucesso.';
}

$where = ["empresa_id = ?"];
$params = [$empresaId];

if ($filtroDataIni !== '') {
    $where[] = "DATE(data_venda) >= ?";
    $params[] = $filtroDataIni;
}

if ($filtroDataFim !== '') {
    $where[] = "DATE(data_venda) <= ?";
    $params[] = $filtroDataFim;
}

if ($filtroVencIni !== '') {
    $where[] = "DATE(COALESCE(data_recebimento, data_prevista, data_venda)) >= ?";
    $params[] = $filtroVencIni;
}

if ($filtroVencFim !== '') {
    $where[] = "DATE(COALESCE(data_recebimento, data_prevista, data_venda)) <= ?";
    $params[] = $filtroVencFim;
}

if ($filtroAdquirente !== '') {
    $where[] = origemAdquirenteSql() . " = ?";
    $params[] = $filtroAdquirente;
}

if ($filtroGrupo !== '') {
    $where[] = origemGrupoSql() . " = ?";
    $params[] = $filtroGrupo;
}

if ($filtroTipo !== '') {
    $where[] = "tipo_operacao = ?";
    $params[] = $filtroTipo;
}

if ($filtroBandeira !== '') {
    $where[] = "UPPER(COALESCE(NULLIF(bandeira, ''), 'TODAS')) = ?";
    $params[] = $filtroBandeira;
}

$whereAgenda = ["empresa_id = ?"];
$paramsAgenda = [$empresaId];

if ($filtroDataIni !== '') {
    $whereAgenda[] = "DATE(data_transacao) >= ?";
    $paramsAgenda[] = $filtroDataIni;
}

if ($filtroDataFim !== '') {
    $whereAgenda[] = "DATE(data_transacao) <= ?";
    $paramsAgenda[] = $filtroDataFim;
}

if ($filtroVencIni !== '') {
    $whereAgenda[] = "DATE(data_pagamento) >= ?";
    $paramsAgenda[] = $filtroVencIni;
}

if ($filtroVencFim !== '') {
    $whereAgenda[] = "DATE(data_pagamento) <= ?";
    $paramsAgenda[] = $filtroVencFim;
}

if ($filtroAdquirente !== '') {
    $whereAgenda[] = "adquirente = ?";
    $paramsAgenda[] = $filtroAdquirente;
}

if ($filtroGrupo !== '') {
    $whereAgenda[] = "grupo = ?";
    $paramsAgenda[] = $filtroGrupo;
}

if ($filtroTipo !== '') {
    $whereAgenda[] = "tipo_operacao = ?";
    $paramsAgenda[] = $filtroTipo;
}

if ($filtroBandeira !== '') {
    $whereAgenda[] = "UPPER(COALESCE(NULLIF(bandeira, ''), 'TODAS')) = ?";
    $paramsAgenda[] = $filtroBandeira;
}

$stmtBandeiras = $pdo_master->prepare("
    SELECT DISTINCT bandeira
    FROM (
        SELECT UPPER(COALESCE(NULLIF(bandeira, ''), 'TODAS')) AS bandeira
        FROM armazem_conciliacao_recebimentos
        WHERE empresa_id = ?
        UNION
        SELECT UPPER(COALESCE(NULLIF(bandeira, ''), 'TODAS')) AS bandeira
        FROM fechamento_adquirente_agenda
        WHERE empresa_id = ?
    ) base
    ORDER BY bandeira
");
$stmtBandeiras->execute([$empresaId, $empresaId]);
$bandeiras = array_values(array_filter(array_column($stmtBandeiras->fetchAll(PDO::FETCH_ASSOC), 'bandeira')));

$stmtContasExtrato = $pdo_master->prepare("
    SELECT CBCONTADOR, TRIM(COALESCE(NULLIF(TITULAR, ''), NULLIF(DESCABREV, ''), CONCAT('Conta ', CBCONTADOR))) AS nome
    FROM armazem_bnc002
    WHERE EMPRESA = ?
      AND CLASSIFICACAO IN (1, 2)
    ORDER BY nome, CBCONTADOR
");
$stmtContasExtrato->execute([$empresaId]);
$contasExtrato = $stmtContasExtrato->fetchAll(PDO::FETCH_ASSOC);

$historicoEditar = null;
$editarHistoricoId = (int)($_GET['editar_historico'] ?? 0);
if ($editarHistoricoId > 0) {
    $stmtHistoricoEditar = $pdo_master->prepare("
        SELECT *
        FROM financeiro_recebimentos_extrato_historicos
        WHERE id = ? AND empresa_id = ? AND ativo = 'S'
    ");
    $stmtHistoricoEditar->execute([$editarHistoricoId, $empresaId]);
    $historicoEditar = $stmtHistoricoEditar->fetch(PDO::FETCH_ASSOC) ?: null;
}

$stmtHistoricosExtrato = $pdo_master->prepare("
    SELECT h.*, TRIM(COALESCE(NULLIF(c.TITULAR, ''), NULLIF(c.DESCABREV, ''), CONCAT('Conta ', h.cbcontador))) AS conta_nome
    FROM financeiro_recebimentos_extrato_historicos h
    LEFT JOIN armazem_bnc002 c ON c.EMPRESA = h.empresa_id AND c.CBCONTADOR = h.cbcontador
    WHERE h.empresa_id = ? AND h.ativo = 'S'
    ORDER BY h.adquirente, h.grupo, h.tipo_operacao, h.cbcontador, h.historico_padrao
");
$stmtHistoricosExtrato->execute([$empresaId]);
$historicosExtrato = $stmtHistoricosExtrato->fetchAll(PDO::FETCH_ASSOC);

$historicosExtratoPorChave = [];
foreach ($historicosExtrato as $historicoRegra) {
    $chaveHistorico = implode('|', [
        (int)$historicoRegra['cbcontador'],
        (string)$historicoRegra['adquirente'],
        (string)$historicoRegra['grupo'],
        (string)$historicoRegra['tipo_operacao'],
    ]);
    $historicosExtratoPorChave[$chaveHistorico][] = $historicoRegra;
}

$sqlTransacoes = "
    SELECT
        id,
        data_venda,
        data_prevista,
        data_recebimento,
        arquivo_origem,
        origem,
        " . origemAdquirenteSql() . " AS adquirente,
        " . origemGrupoSql() . " AS grupo,
        tipo_operacao,
        COALESCE(NULLIF(bandeira, ''), 'TODAS') AS bandeira,
        COALESCE(NULLIF(total_parcelas, 0), 1) AS parcelas,
        COALESCE(valor_bruto, 0) AS total_bruto,
        COALESCE(valor_desconto, 0) AS total_taxa,
        COALESCE(valor_liquido, 0) AS total_liquido,
        identificador,
        descricao,
        pagador,
        criado_em AS importado_em,
        (
            SELECT COUNT(*)
            FROM fechamento_granito_agenda_taxas ag
            WHERE ag.empresa_id = armazem_conciliacao_recebimentos.empresa_id
              AND ag.identificador = armazem_conciliacao_recebimentos.identificador
        ) AS agenda_qtd
    FROM armazem_conciliacao_recebimentos
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        COALESCE(data_recebimento, data_prevista, data_venda) IS NULL,
        COALESCE(data_recebimento, data_prevista, data_venda) ASC,
        data_venda ASC,
        id ASC
";

$stmtTransacoes = $pdo_master->prepare($sqlTransacoes);
$stmtTransacoes->execute($params);
$transacoes = $stmtTransacoes->fetchAll(PDO::FETCH_ASSOC);

$sqlAgenda = "
    SELECT
        id,
        adquirente,
        grupo,
        arquivo_origem,
        id_transacao,
        identificador_recebivel,
        data_transacao,
        data_pagamento,
        tipo_operacao,
        categoria,
        descricao_original,
        status,
        parcela,
        total_parcelas,
        COALESCE(NULLIF(bandeira, ''), 'TODAS') AS bandeira,
        valor_bruto,
        valor_taxa,
        valor_antecipacao,
        valor_liquido
    FROM fechamento_adquirente_agenda
    WHERE " . implode(' AND ', $whereAgenda) . "
    ORDER BY
        data_pagamento IS NULL,
        data_pagamento ASC,
        data_transacao ASC,
        id ASC
";
$stmtAgenda = $pdo_master->prepare($sqlAgenda);
$stmtAgenda->execute($paramsAgenda);
$agendaAdquirente = $stmtAgenda->fetchAll(PDO::FETCH_ASSOC);

function buscarTaxaEsperada(array $taxasAtivas, array $relatorio): ?array
{
    $candidatas = [];
    foreach ($taxasAtivas as $taxa) {
        if ($taxa['adquirente'] !== $relatorio['adquirente']) {
            continue;
        }
        if ($taxa['grupo'] !== $relatorio['grupo']) {
            continue;
        }
        if ($taxa['tipo_operacao'] !== rotuloTipoOperacao((string)$relatorio['tipo_operacao'])) {
            continue;
        }
        if ((int)$relatorio['parcelas'] < (int)$taxa['parcelas_de'] || (int)$relatorio['parcelas'] > (int)$taxa['parcelas_ate']) {
            continue;
        }
        if ($taxa['bandeira'] !== 'TODAS' && strtoupper((string)$taxa['bandeira']) !== strtoupper((string)$relatorio['bandeira'])) {
            continue;
        }
        $candidatas[] = $taxa;
    }

    usort($candidatas, static function ($a, $b) {
        $aEspecifica = $a['bandeira'] === 'TODAS' ? 0 : 1;
        $bEspecifica = $b['bandeira'] === 'TODAS' ? 0 : 1;
        return $bEspecifica <=> $aEspecifica;
    });

    return $candidatas[0] ?? null;
}

function relatorioDemonstraTaxa(array $relatorio): bool
{
    if (strtoupper((string)($relatorio['tipo_operacao'] ?? '')) === 'P') {
        return true;
    }

    return ((int)($relatorio['agenda_qtd'] ?? 0) > 0) || abs((float)$relatorio['total_taxa']) > 0.0001;
}

function situacaoTaxaRelatorio(array $relatorio, array $taxasAtivas): array
{
    $totalBruto = (float)$relatorio['total_bruto'];
    $totalTaxa = (float)$relatorio['total_taxa'];
    $taxaMedia = $totalBruto > 0 ? ($totalTaxa / $totalBruto) * 100 : 0;
    $taxaEsperada = buscarTaxaEsperada($taxasAtivas, $relatorio);

    if (!relatorioDemonstraTaxa($relatorio)) {
        return ['classe' => 'secondary', 'texto' => 'Sem taxa demonstrada', 'taxa_media' => $taxaMedia, 'esperada' => $taxaEsperada];
    }

    if (!$taxaEsperada) {
        return ['classe' => 'secondary', 'texto' => 'Sem taxa cadastrada', 'taxa_media' => $taxaMedia, 'esperada' => null];
    }

    $esperada = (float)$taxaEsperada['taxa_percentual'];
    $tolerancia = (float)$taxaEsperada['tolerancia_percentual'];
    $divergente = abs($taxaMedia - $esperada) > $tolerancia;

    return [
        'classe' => $divergente ? 'danger' : 'success',
        'texto' => $divergente ? 'Divergente' : 'OK',
        'taxa_media' => $taxaMedia,
        'esperada' => $taxaEsperada,
    ];
}

$transacoes = array_values(array_filter($transacoes, function ($transacao) use ($taxasAtivas, $filtroSituacao) {
    if ($filtroSituacao === 'todos') {
        return true;
    }

    $situacao = situacaoTaxaRelatorio($transacao, $taxasAtivas);
    return match ($filtroSituacao) {
        'divergentes' => $situacao['texto'] === 'Divergente',
        'sem_taxa' => $situacao['texto'] === 'Sem taxa cadastrada',
        'sem_taxa_demonstrada' => $situacao['texto'] === 'Sem taxa demonstrada',
        'ok' => $situacao['texto'] === 'OK',
        default => true,
    };
}));

$totaisTransacoes = [
    'qtd' => count($transacoes),
    'bruto' => 0.0,
    'taxa' => 0.0,
    'taxa_esperada' => 0.0,
    'liquido' => 0.0,
    'diferenca_valor' => 0.0,
];

$totaisAgenda = [
    'qtd' => count($agendaAdquirente),
    'bruto' => 0.0,
    'taxa' => 0.0,
    'antecipacao' => 0.0,
    'liquido' => 0.0,
];

foreach ($agendaAdquirente as $agendaResumo) {
    $totaisAgenda['bruto'] += (float)$agendaResumo['valor_bruto'];
    $totaisAgenda['taxa'] += (float)$agendaResumo['valor_taxa'];
    $totaisAgenda['antecipacao'] += (float)$agendaResumo['valor_antecipacao'];
    $totaisAgenda['liquido'] += (float)$agendaResumo['valor_liquido'];
}

foreach ($transacoes as $transacaoResumo) {
    $situacaoResumo = situacaoTaxaRelatorio($transacaoResumo, $taxasAtivas);
    $totaisTransacoes['bruto'] += (float)$transacaoResumo['total_bruto'];
    $totaisTransacoes['taxa'] += (float)$transacaoResumo['total_taxa'];
    $totaisTransacoes['liquido'] += (float)$transacaoResumo['total_liquido'];

    if ($situacaoResumo['esperada']) {
        $taxaEsperadaResumo = (float)$situacaoResumo['esperada']['taxa_percentual'];
        $valorEsperadoResumo = ((float)$transacaoResumo['total_bruto']) * ($taxaEsperadaResumo / 100);
        $totaisTransacoes['taxa_esperada'] += $valorEsperadoResumo;

        if (relatorioDemonstraTaxa($transacaoResumo)) {
            $taxaAplicadaResumo = (float)$situacaoResumo['taxa_media'];
            $totaisTransacoes['diferenca_valor'] += ((float)$transacaoResumo['total_bruto']) * (($taxaAplicadaResumo - $taxaEsperadaResumo) / 100);
        }
    }
}

$conferenciasExtrato = [];
$mapasConferencia = contasConferenciaRecebimentos()[$empresaId] ?? [];
if (!empty($mapasConferencia)) {
    $dataConferenciaIni = $filtroVencIni !== '' ? $filtroVencIni : $filtroDataIni;
    $dataConferenciaFim = $filtroVencFim !== '' ? $filtroVencFim : $filtroDataFim;

    foreach ($mapasConferencia as $mapaConferencia) {
        if ($filtroAdquirente !== '' && $filtroAdquirente !== $mapaConferencia['adquirente']) {
            continue;
        }
        if ($filtroGrupo !== '' && $filtroGrupo !== $mapaConferencia['grupo']) {
            continue;
        }

        $whereRecebimentosConf = [
            "empresa_id = ?",
            origemAdquirenteSql() . " = ?",
            origemGrupoSql() . " = ?",
        ];
        $paramsRecebimentosConf = [
            $empresaId,
            $mapaConferencia['adquirente'],
            $mapaConferencia['grupo'],
        ];

        if ($dataConferenciaIni !== '') {
            $whereRecebimentosConf[] = "DATE(COALESCE(data_recebimento, data_prevista, data_venda)) >= ?";
            $paramsRecebimentosConf[] = $dataConferenciaIni;
        }
        if ($dataConferenciaFim !== '') {
            $whereRecebimentosConf[] = "DATE(COALESCE(data_recebimento, data_prevista, data_venda)) <= ?";
            $paramsRecebimentosConf[] = $dataConferenciaFim;
        }
        if ($filtroTipo !== '') {
            $whereRecebimentosConf[] = "tipo_operacao = ?";
            $paramsRecebimentosConf[] = $filtroTipo;
        }
        if ($filtroBandeira !== '') {
            $whereRecebimentosConf[] = "UPPER(COALESCE(NULLIF(bandeira, ''), 'TODAS')) = ?";
            $paramsRecebimentosConf[] = $filtroBandeira;
        }

        $stmtRecebimentosConf = $pdo_master->prepare("
            SELECT
                COUNT(*) AS qtd,
                COALESCE(SUM(valor_liquido), 0) AS total_liquido
            FROM armazem_conciliacao_recebimentos
            WHERE " . implode(' AND ', $whereRecebimentosConf) . "
        ");
        $stmtRecebimentosConf->execute($paramsRecebimentosConf);
        $recebimentosConf = $stmtRecebimentosConf->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total_liquido' => 0];

        $whereExtratoConf = [
            "empresa_id = ?",
            "cbcontador = ?",
            "tipo = 'C'",
        ];
        $paramsExtratoConf = [
            $empresaId,
            (int)$mapaConferencia['cbcontador'],
        ];

        $tiposRegraConferencia = $filtroTipo !== '' ? [$filtroTipo] : ['D', 'C', 'P'];
        $regrasHistoricoConf = [];
        foreach ($tiposRegraConferencia as $tipoRegraConferencia) {
            $chaveRegraHistorico = implode('|', [
                (int)$mapaConferencia['cbcontador'],
                $mapaConferencia['adquirente'],
                $mapaConferencia['grupo'],
                $tipoRegraConferencia,
            ]);
            foreach ($historicosExtratoPorChave[$chaveRegraHistorico] ?? [] as $regraHistoricoExtrato) {
                $regrasHistoricoConf[] = $regraHistoricoExtrato;
            }
        }

        if ($dataConferenciaIni !== '') {
            $whereExtratoConf[] = "DATE(data_movimento) >= ?";
            $paramsExtratoConf[] = $dataConferenciaIni;
        }
        if ($dataConferenciaFim !== '') {
            $whereExtratoConf[] = "DATE(data_movimento) <= ?";
            $paramsExtratoConf[] = $dataConferenciaFim;
        }

        if (!empty($regrasHistoricoConf)) {
            $whereHistoricosConf = [];
            foreach ($regrasHistoricoConf as $regraHistoricoConf) {
                $whereHistoricosConf[] = "UPPER(COALESCE(historico, '')) LIKE ?";
                $paramsExtratoConf[] = '%' . strtoupper((string)$regraHistoricoConf['historico_padrao']) . '%';
            }
            $whereExtratoConf[] = '(' . implode(' OR ', $whereHistoricosConf) . ')';

            $stmtExtratoConf = $pdo_master->prepare("
                SELECT
                    COUNT(*) AS qtd,
                    COALESCE(SUM(valor), 0) AS total_extrato
                FROM financeiro_extrato_bancario
                WHERE " . implode(' AND ', $whereExtratoConf) . "
            ");
            $stmtExtratoConf->execute($paramsExtratoConf);
            $extratoConf = $stmtExtratoConf->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total_extrato' => 0];
        } else {
            $extratoConf = ['qtd' => 0, 'total_extrato' => 0];
        }

        $totalRecebimentos = (float)$recebimentosConf['total_liquido'];
        $totalExtrato = (float)$extratoConf['total_extrato'];
        $diferenca = $totalExtrato - $totalRecebimentos;

        $conferenciasExtrato[] = [
            'descricao' => $mapaConferencia['descricao'],
            'adquirente' => $mapaConferencia['adquirente'],
            'grupo' => $mapaConferencia['grupo'],
            'cbcontador' => (int)$mapaConferencia['cbcontador'],
            'qtd_recebimentos' => (int)$recebimentosConf['qtd'],
            'total_recebimentos' => $totalRecebimentos,
            'qtd_extrato' => (int)$extratoConf['qtd'],
            'total_extrato' => $totalExtrato,
            'diferenca' => $diferenca,
            'ok' => abs($diferenca) < 0.01,
            'qtd_regras' => count($regrasHistoricoConf),
            'historicos' => implode(', ', array_map(static fn($regra) => (string)$regra['historico_padrao'], $regrasHistoricoConf)),
        ];
    }
}

require '../../layout/header.php';
?>

<section class="mb-4">
    <div class="p-4 p-lg-5 bg-white border rounded-2 shadow-sm">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <span class="badge text-bg-warning mb-3">Recebimentos</span>
                <h1 class="h3 fw-bold mb-2">Relatorio dos Recebimentos</h1>
                <p class="text-muted mb-0">Acompanhe as transacoes importadas e a conferencia das taxas aplicadas pelas adquirentes.</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="menu_financeiro.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>
</section>

<?php if ($mensagem): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensagem) ?></div>
<?php endif; ?>

<?php if ($erro): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<section>
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                <div>
                    <h2 class="h5 mb-1">Relatorio dos Recebimentos</h2>
                    <div class="text-muted small">Transacoes importadas com comparacao entre taxa acordada e taxa aplicada pela adquirente.</div>
                </div>
                <div class="text-muted small align-self-lg-center">Na Granito, a taxa de POS vem da agenda; o arquivo de transacoes fica como base operacional.</div>
            </div>
        </div>
        <div class="card-body border-bottom">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">Data inicial</label>
                    <input type="date" name="data_ini" value="<?= htmlspecialchars($filtroDataIni) ?>" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data final</label>
                    <input type="date" name="data_fim" value="<?= htmlspecialchars($filtroDataFim) ?>" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Venc./Pagto. inicial</label>
                    <input type="date" name="venc_ini" value="<?= htmlspecialchars($filtroVencIni) ?>" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Venc./Pagto. final</label>
                    <input type="date" name="venc_fim" value="<?= htmlspecialchars($filtroVencFim) ?>" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Adquirente</label>
                    <select name="adquirente" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($adquirentes as $adquirente): ?>
                            <option value="<?= $adquirente ?>" <?= $filtroAdquirente === $adquirente ? 'selected' : '' ?>><?= $adquirente ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Grupo</label>
                    <select name="grupo" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($grupos as $grupo): ?>
                            <option value="<?= $grupo ?>" <?= $filtroGrupo === $grupo ? 'selected' : '' ?>><?= $grupo ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select">
                        <option value="">Todos</option>
                        <option value="D" <?= $filtroTipo === 'D' ? 'selected' : '' ?>>Debito</option>
                        <option value="C" <?= $filtroTipo === 'C' ? 'selected' : '' ?>>Credito</option>
                        <option value="P" <?= $filtroTipo === 'P' ? 'selected' : '' ?>>Pix</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Bandeira</label>
                    <select name="bandeira" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($bandeiras as $bandeira): ?>
                            <option value="<?= htmlspecialchars($bandeira) ?>" <?= $filtroBandeira === $bandeira ? 'selected' : '' ?>><?= htmlspecialchars($bandeira) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Situacao taxa</label>
                    <select name="situacao" class="form-select">
                        <option value="todos" <?= $filtroSituacao === 'todos' ? 'selected' : '' ?>>Todas</option>
                        <option value="divergentes" <?= $filtroSituacao === 'divergentes' ? 'selected' : '' ?>>Divergentes</option>
                        <option value="ok" <?= $filtroSituacao === 'ok' ? 'selected' : '' ?>>OK</option>
                        <option value="sem_taxa" <?= $filtroSituacao === 'sem_taxa' ? 'selected' : '' ?>>Sem taxa cadastrada</option>
                        <option value="sem_taxa_demonstrada" <?= $filtroSituacao === 'sem_taxa_demonstrada' ? 'selected' : '' ?>>Sem taxa demonstrada</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>
        </div>
        <div class="card-body border-bottom bg-light-subtle">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                <div>
                    <h3 class="h6 fw-bold mb-1">Historicos do extrato por adquirente</h3>
                    <div class="text-muted small">
                        Cadastre quais textos do extrato bancario pertencem a cada adquirente, grupo e tipo. A conferencia usa somente esses historicos.
                    </div>
                </div>
                <?php if ($historicoEditar): ?>
                    <div class="align-self-lg-center">
                        <a href="importacao_recebimentos.php?<?= htmlspecialchars(http_build_query(array_diff_key($_GET, ['editar_historico' => true]))) ?>" class="btn btn-sm btn-outline-secondary">Cancelar edicao</a>
                    </div>
                <?php endif; ?>
            </div>

            <form method="post" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="acao" value="salvar_historico_extrato">
                <input type="hidden" name="id" value="<?= (int)($historicoEditar['id'] ?? 0) ?>">
                <div class="col-md-3">
                    <label class="form-label">Conta do extrato</label>
                    <select name="cbcontador" class="form-select" required>
                        <option value="">Selecione</option>
                        <?php foreach ($contasExtrato as $contaExtrato): ?>
                            <?php $contaValor = (int)$contaExtrato['CBCONTADOR']; ?>
                            <option value="<?= $contaValor ?>" <?= (int)($historicoEditar['cbcontador'] ?? 0) === $contaValor ? 'selected' : '' ?>>
                                <?= $contaValor ?> - <?= htmlspecialchars((string)$contaExtrato['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Adquirente</label>
                    <select name="adquirente" class="form-select" required>
                        <?php foreach ($adquirentes as $adquirenteOpcao): ?>
                            <option value="<?= $adquirenteOpcao ?>" <?= (string)($historicoEditar['adquirente'] ?? '') === $adquirenteOpcao ? 'selected' : '' ?>><?= $adquirenteOpcao ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Grupo</label>
                    <select name="grupo" class="form-select" required>
                        <?php foreach ($grupos as $grupoOpcao): ?>
                            <option value="<?= $grupoOpcao ?>" <?= (string)($historicoEditar['grupo'] ?? '') === $grupoOpcao ? 'selected' : '' ?>><?= $grupoOpcao ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tipo</label>
                    <?php $tipoHistoricoAtual = (string)($historicoEditar['tipo_operacao'] ?? 'P'); ?>
                    <select name="tipo_operacao" class="form-select" required>
                        <option value="D" <?= $tipoHistoricoAtual === 'D' ? 'selected' : '' ?>>Debito</option>
                        <option value="C" <?= $tipoHistoricoAtual === 'C' ? 'selected' : '' ?>>Credito</option>
                        <option value="P" <?= $tipoHistoricoAtual === 'P' ? 'selected' : '' ?>>Pix</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Historico contem</label>
                    <input type="text" name="historico_padrao" value="<?= htmlspecialchars((string)($historicoEditar['historico_padrao'] ?? '')) ?>" class="form-control" placeholder="Ex.: SIPAG, PAGSEGURO, GRANITO" required>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100"><?= $historicoEditar ? 'Salvar alteracao' : 'Adicionar regra' ?></button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Conta</th>
                            <th>Adquirente</th>
                            <th>Grupo</th>
                            <th>Tipo</th>
                            <th>Historico contem</th>
                            <th class="text-end">Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historicosExtrato as $historicoRegra): ?>
                            <tr>
                                <td><?= (int)$historicoRegra['cbcontador'] ?> - <?= htmlspecialchars((string)$historicoRegra['conta_nome']) ?></td>
                                <td><?= htmlspecialchars((string)$historicoRegra['adquirente']) ?></td>
                                <td><?= htmlspecialchars((string)$historicoRegra['grupo']) ?></td>
                                <td><?= htmlspecialchars(rotuloTipoOperacao((string)$historicoRegra['tipo_operacao'])) ?></td>
                                <td><?= htmlspecialchars((string)$historicoRegra['historico_padrao']) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="importacao_recebimentos.php?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['editar_historico' => (int)$historicoRegra['id']]))) ?>">Editar</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Inativar esta regra de historico?')">
                                        <input type="hidden" name="acao" value="inativar_historico_extrato">
                                        <input type="hidden" name="id" value="<?= (int)$historicoRegra['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Inativar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($historicosExtrato)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">Nenhum historico cadastrado. A conferencia com extrato nao soma creditos sem regra.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-body border-bottom">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Transacoes filtradas</div>
                        <div class="h5 mb-0"><?= number_format($totaisTransacoes['qtd'], 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Valor bruto</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisTransacoes['bruto'], 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Taxa aplicada demonstrada</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisTransacoes['taxa'], 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Taxa acordada estimada</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisTransacoes['taxa_esperada'], 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Diferenca demonstrada</div>
                        <div class="h5 mb-0 <?= abs($totaisTransacoes['diferenca_valor']) > 0.009 ? 'text-danger' : 'text-success' ?>">
                            R$ <?= number_format($totaisTransacoes['diferenca_valor'], 2, ',', '.') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php if (!empty($conferenciasExtrato)): ?>
            <div class="card-body border-bottom">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2 mb-3">
                    <div>
                        <h3 class="h6 fw-bold mb-1">Conferencia com extratos bancarios</h3>
                        <div class="text-muted small">
                            Compara o valor liquido dos recebimentos com os creditos importados no extrato das contas configuradas.
                        </div>
                    </div>
                    <div class="text-muted small align-self-lg-center">
                        Periodo: <?= htmlspecialchars($dataConferenciaIni ?: '-') ?> ate <?= htmlspecialchars($dataConferenciaFim ?: '-') ?>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Adquirente</th>
                                <th>Grupo</th>
                                <th>Conta extrato</th>
                                <th class="text-end">Qtd. receb.</th>
                                <th class="text-end">Recebimentos liquido</th>
                                <th class="text-end">Qtd. extrato</th>
                                <th class="text-end">Extrato credito</th>
                                <th class="text-end">Diferenca</th>
                                <th>Historicos usados</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conferenciasExtrato as $confExtrato): ?>
                                <tr>
                                    <td><?= htmlspecialchars($confExtrato['adquirente']) ?></td>
                                    <td><?= htmlspecialchars($confExtrato['grupo']) ?></td>
                                    <td><?= (int)$confExtrato['cbcontador'] ?> - <?= htmlspecialchars($confExtrato['descricao']) ?></td>
                                    <td class="text-end"><?= number_format((int)$confExtrato['qtd_recebimentos'], 0, ',', '.') ?></td>
                                    <td class="text-end">R$ <?= number_format((float)$confExtrato['total_recebimentos'], 2, ',', '.') ?></td>
                                    <td class="text-end"><?= number_format((int)$confExtrato['qtd_extrato'], 0, ',', '.') ?></td>
                                    <td class="text-end">R$ <?= number_format((float)$confExtrato['total_extrato'], 2, ',', '.') ?></td>
                                    <td class="text-end <?= $confExtrato['ok'] ? 'text-success' : 'text-danger fw-semibold' ?>">
                                        R$ <?= number_format((float)$confExtrato['diferenca'], 2, ',', '.') ?>
                                    </td>
                                    <td class="small">
                                        <?= (int)$confExtrato['qtd_regras'] > 0 ? htmlspecialchars((string)$confExtrato['historicos']) : '<span class="text-muted">Sem regra</span>' ?>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-<?= (int)$confExtrato['qtd_regras'] === 0 ? 'secondary' : ($confExtrato['ok'] ? 'success' : 'danger') ?>">
                                            <?= (int)$confExtrato['qtd_regras'] === 0 ? 'Sem regra' : ($confExtrato['ok'] ? 'OK' : 'Divergente') ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Venc./Pagto.</th>
                        <th>Data</th>
                        <th>Transacao</th>
                        <th>Adq.</th>
                        <th>Grupo</th>
                        <th>Tipo</th>
                        <th>Bandeira</th>
                        <th>Parc.</th>
                        <th class="text-end">Bruto</th>
                        <th class="text-end">Taxa aplicada R$</th>
                        <th class="text-end">Taxa acordada R$</th>
                        <th class="text-end">Aplicada</th>
                        <th class="text-end">Acordada</th>
                        <th class="text-end">Dif. %</th>
                        <th class="text-end">Dif. R$</th>
                        <th>Sit.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transacoes as $transacao): ?>
                        <?php
                            $situacao = situacaoTaxaRelatorio($transacao, $taxasAtivas);
                            $taxaAplicada = (float)$situacao['taxa_media'];
                            $taxaAcordada = $situacao['esperada'] ? (float)$situacao['esperada']['taxa_percentual'] : null;
                            $taxaDemonstrada = relatorioDemonstraTaxa($transacao);
                            $valorTaxaAcordada = $taxaAcordada !== null ? ((float)$transacao['total_bruto']) * ($taxaAcordada / 100) : null;
                            $difPercentual = ($taxaAcordada !== null && $taxaDemonstrada) ? $taxaAplicada - $taxaAcordada : null;
                            $difValor = $difPercentual !== null ? ((float)$transacao['total_bruto']) * ($difPercentual / 100) : null;
                            $dataPagamento = $transacao['data_recebimento'] ?: ($transacao['data_prevista'] ?: $transacao['data_venda']);
                        ?>
                        <tr>
                            <td class="small"><?= $dataPagamento ? date('d/m/Y', strtotime($dataPagamento)) : '<span class="text-muted">Sem agenda</span>' ?></td>
                            <td class="small"><?= date('d/m/Y H:i', strtotime($transacao['data_venda'])) ?></td>
                            <td class="small"><?= htmlspecialchars(numeroTransacaoRecebimento($transacao)) ?></td>
                            <td><?= htmlspecialchars($transacao['adquirente']) ?></td>
                            <td><?= htmlspecialchars($transacao['grupo']) ?></td>
                            <td><?= htmlspecialchars(rotuloTipoOperacao((string)$transacao['tipo_operacao'])) ?></td>
                            <td><?= htmlspecialchars($transacao['bandeira']) ?></td>
                            <td><?= (int)$transacao['parcelas'] ?></td>
                            <td class="text-end"><?= number_format((float)$transacao['total_bruto'], 2, ',', '.') ?></td>
                            <td class="text-end"><?= $taxaDemonstrada ? number_format((float)$transacao['total_taxa'], 2, ',', '.') : '<span class="text-muted">Sem agenda</span>' ?></td>
                            <td class="text-end"><?= $valorTaxaAcordada !== null ? number_format($valorTaxaAcordada, 2, ',', '.') : '-' ?></td>
                            <td class="text-end"><?= $taxaDemonstrada ? number_format($taxaAplicada, 4, ',', '.') . '%' : '<span class="text-muted">Sem agenda</span>' ?></td>
                            <td class="text-end">
                                <?= $taxaAcordada !== null ? number_format($taxaAcordada, 4, ',', '.') . '%' : '-' ?>
                            </td>
                            <td class="text-end <?= $difPercentual !== null && abs($difPercentual) > 0.0001 ? 'text-danger' : '' ?>">
                                <?= $difPercentual !== null ? number_format($difPercentual, 4, ',', '.') . '%' : '-' ?>
                            </td>
                            <td class="text-end <?= $difValor !== null && abs($difValor) > 0.009 ? 'text-danger' : '' ?>">
                                <?= $difValor !== null ? number_format($difValor, 2, ',', '.') : '-' ?>
                            </td>
                            <td><span class="badge text-bg-<?= htmlspecialchars($situacao['classe']) ?>"><?= htmlspecialchars($situacao['texto']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($transacoes)): ?>
                        <tr><td colspan="16" class="text-center text-muted py-4">Nenhuma transacao encontrada para os filtros.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="mt-4">
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                <div>
                    <h2 class="h5 mb-1">Agenda da Adquirente</h2>
                    <div class="text-muted small">Linhas completas dos arquivos de agenda importados, incluindo vendas, Pix, taxas, aluguel POS e ajustes.</div>
                </div>
                <div class="text-muted small align-self-lg-center">Este bloco nao gera recebivel automaticamente para itens financeiros como aluguel de POS.</div>
            </div>
        </div>
        <div class="card-body border-bottom">
            <div class="row g-3">
                <div class="col-md-2">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Linhas da agenda</div>
                        <div class="h5 mb-0"><?= number_format($totaisAgenda['qtd'], 0, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Bruto</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisAgenda['bruto'], 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Taxa</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisAgenda['taxa'], 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Antecipacao</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisAgenda['antecipacao'], 2, ',', '.') ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded-2 p-3 h-100">
                        <div class="text-muted small">Liquido</div>
                        <div class="h5 mb-0">R$ <?= number_format($totaisAgenda['liquido'], 2, ',', '.') ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Venc./Pagto.</th>
                        <th>Data</th>
                        <th>Transacao</th>
                        <th>Adq.</th>
                        <th>Grupo</th>
                        <th>Tipo</th>
                        <th>Categoria</th>
                        <th>Bandeira</th>
                        <th>Parc.</th>
                        <th class="text-end">Bruto</th>
                        <th class="text-end">Taxa</th>
                        <th class="text-end">Antecip.</th>
                        <th class="text-end">Liquido</th>
                        <th>Status</th>
                        <th>Descricao</th>
                        <th>Arquivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agendaAdquirente as $agenda): ?>
                        <tr>
                            <td class="small"><?= $agenda['data_pagamento'] ? date('d/m/Y', strtotime($agenda['data_pagamento'])) : '-' ?></td>
                            <td class="small"><?= $agenda['data_transacao'] ? date('d/m/Y H:i', strtotime($agenda['data_transacao'])) : '-' ?></td>
                            <td class="small"><?= htmlspecialchars($agenda['id_transacao']) ?></td>
                            <td><?= htmlspecialchars($agenda['adquirente']) ?></td>
                            <td><?= htmlspecialchars($agenda['grupo']) ?></td>
                            <td><?= htmlspecialchars(rotuloTipoOperacao((string)$agenda['tipo_operacao'])) ?></td>
                            <td><span class="badge text-bg-secondary"><?= htmlspecialchars($agenda['categoria']) ?></span></td>
                            <td><?= htmlspecialchars($agenda['bandeira']) ?></td>
                            <td><?= (int)$agenda['parcela'] ?>/<?= (int)$agenda['total_parcelas'] ?></td>
                            <td class="text-end"><?= number_format((float)$agenda['valor_bruto'], 2, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$agenda['valor_taxa'], 2, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$agenda['valor_antecipacao'], 2, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)$agenda['valor_liquido'], 2, ',', '.') ?></td>
                            <td><?= htmlspecialchars((string)$agenda['status']) ?></td>
                            <td class="small"><?= htmlspecialchars((string)$agenda['descricao_original']) ?></td>
                            <td class="small"><?= htmlspecialchars(basename((string)$agenda['arquivo_origem'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($agendaAdquirente)): ?>
                        <tr><td colspan="16" class="text-center text-muted py-4">Nenhuma linha de agenda encontrada para os filtros.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require '../../layout/footer.php'; ?>
