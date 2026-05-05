<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/conexao.php';

header('Content-Type: application/json');

/* =========================
   PARÂMETRO TABELA
========================= */
$tabela = $_GET['tabela'] ?? 'bnc001';

$tabelas_permitidas = [
    'bnc001',
    'cr001',
    'cr001_ativos',
    'cr002',
    'cp001',
    'cp003',
    'cp004',
    'est007',
    'est004',
    'est008',
    'zconfig005',
    'bnc005_ativos',
    'cp001_ativos',
    'cp003_ativos',
    'cp004_ativos',
    'est004_ativos',
    'est005_ativos',
    'est006_ativos',
];

if (!in_array($tabela, $tabelas_permitidas)) {
    echo json_encode(["erro" => "Tabela inválida"]);
    exit;
}

/* =========================
   RECEBER JSON
========================= */
$input = file_get_contents("php://input");

if (!$input) {
    echo json_encode(["erro" => "Nenhum dado recebido"]);
    exit;
}

$dados = json_decode($input, true);

if (!is_array($dados)) {
    echo json_encode(["erro" => "JSON invalido"]);
    exit;
}

if (count($dados) === 0 && substr($tabela, -7) !== '_ativos') {
    echo json_encode([
        "status" => "ok",
        "processados" => 0
    ]);
    exit;
}

$processados = 0;

function garantirControleExclusaoCR001(PDO $pdo): void
{
    $colunas = [
        'excluido_firebird' => "ALTER TABLE armazem_cr001 ADD excluido_firebird CHAR(1) NOT NULL DEFAULT 'N'",
        'data_exclusao_firebird' => "ALTER TABLE armazem_cr001 ADD data_exclusao_firebird DATETIME NULL",
        'motivo_sync' => "ALTER TABLE armazem_cr001 ADD motivo_sync VARCHAR(100) NULL",
        'ultima_presenca_firebird' => "ALTER TABLE armazem_cr001 ADD ultima_presenca_firebird DATETIME NULL",
    ];

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'armazem_cr001'
          AND COLUMN_NAME = ?
    ");

    foreach ($colunas as $coluna => $sql) {
        $stmt->execute([$coluna]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'armazem_cr001'
          AND INDEX_NAME = 'idx_cr001_excluido_dtlanc'
    ");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE armazem_cr001 ADD INDEX idx_cr001_excluido_dtlanc (excluido_firebird, DTLANC)");
    }
}

function garantirControleExclusaoTabela(PDO $pdo, string $nomeTabela, string $colunaChave): void
{
    $colunas = [
        'excluido_firebird' => "ALTER TABLE `$nomeTabela` ADD excluido_firebird CHAR(1) NOT NULL DEFAULT 'N'",
        'data_exclusao_firebird' => "ALTER TABLE `$nomeTabela` ADD data_exclusao_firebird DATETIME NULL",
        'motivo_sync' => "ALTER TABLE `$nomeTabela` ADD motivo_sync VARCHAR(100) NULL",
        'ultima_presenca_firebird' => "ALTER TABLE `$nomeTabela` ADD ultima_presenca_firebird DATETIME NULL",
    ];

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");

    foreach ($colunas as $coluna => $sql) {
        $stmt->execute([$nomeTabela, $coluna]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }

    $nomeIndice = 'idx_' . preg_replace('/^armazem_/', '', $nomeTabela) . '_excluido_firebird';
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $stmt->execute([$nomeTabela, $nomeIndice]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$nomeTabela` ADD INDEX `$nomeIndice` (excluido_firebird, `$colunaChave`)");
    }
}

function processarAtivosFirebird(PDO $pdo, array $dados, array $config): void
{
    $nomeTabela = $config['tabela_mysql'];
    $colunaChave = $config['coluna_chave'];
    $tabelaFirebird = $config['nome_firebird'];
    $registros = $dados['registros'] ?? $dados['ativos'] ?? $dados;

    if (!is_array($registros)) {
        echo json_encode(["erro" => "Lista de ativos invalida."]);
        exit;
    }

    $ids = [];
    foreach ($registros as $item) {
        $id = is_array($item) ? ($item[$colunaChave] ?? null) : $item;
        if ($id !== null && $id !== '') {
            $ids[(string)$id] = true;
        }
    }

    if (empty($ids) && ($_GET['confirmar_vazio'] ?? '') !== '1') {
        echo json_encode([
            "erro" => "Lista de ativos vazia. Para marcar todos como excluidos, envie confirmar_vazio=1."
        ]);
        exit;
    }

    garantirControleExclusaoTabela($pdo, $nomeTabela, $colunaChave);

    $pdo->beginTransaction();

    try {
        $pdo->exec("
            CREATE TEMPORARY TABLE tmp_firebird_ativos_sync (
                id VARCHAR(100) NOT NULL PRIMARY KEY
            ) ENGINE=InnoDB
        ");

        if (!empty($ids)) {
            $stmtTmp = $pdo->prepare("INSERT IGNORE INTO tmp_firebird_ativos_sync (id) VALUES (?)");
            foreach (array_keys($ids) as $id) {
                $stmtTmp->execute([$id]);
            }
        }

        $stmtReativar = $pdo->prepare("
            UPDATE `$nomeTabela` m
            INNER JOIN tmp_firebird_ativos_sync t
                ON CAST(m.`$colunaChave` AS CHAR) = t.id
            SET m.excluido_firebird = 'N',
                m.data_exclusao_firebird = NULL,
                m.motivo_sync = NULL,
                m.ultima_presenca_firebird = NOW()
        ");
        $stmtReativar->execute();
        $reativados = $stmtReativar->rowCount();

        $stmtExcluir = $pdo->prepare("
            UPDATE `$nomeTabela` m
            LEFT JOIN tmp_firebird_ativos_sync t
                ON CAST(m.`$colunaChave` AS CHAR) = t.id
            SET m.excluido_firebird = 'S',
                m.data_exclusao_firebird = NOW(),
                m.motivo_sync = ?
            WHERE t.id IS NULL
              AND COALESCE(m.excluido_firebird, 'N') <> 'S'
        ");
        $stmtExcluir->execute(["Nao encontrado na foto $tabelaFirebird do Firebird"]);
        $marcadosExcluidos = $stmtExcluir->rowCount();

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo json_encode([
        "status" => "ok",
        "tabela" => $nomeTabela,
        "chave" => $colunaChave,
        "processados" => count($ids),
        "reativados" => $reativados,
        "marcados_excluidos" => $marcadosExcluidos
    ]);
    exit;
}

function processarTabelaFirebirdGenerica(PDO $pdo, array $dados, string $nomeTabela, array $chavesObrigatorias, array $colunasIgnoradas = []): void
{
    $colunasIgnoradas = array_merge($colunasIgnoradas, [
        'excluido_firebird',
        'data_exclusao_firebird',
        'motivo_sync',
        'ultima_presenca_firebird',
    ]);

    $stmtColunas = $pdo->prepare("
        SELECT COLUMN_NAME, DATA_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION
    ");
    $stmtColunas->execute([$nomeTabela]);

    $colunas = [];
    $tiposColunas = [];
    foreach ($stmtColunas->fetchAll(PDO::FETCH_ASSOC) as $colunaInfo) {
        $coluna = $colunaInfo['COLUMN_NAME'];
        if (in_array($coluna, $colunasIgnoradas, true)) {
            continue;
        }

        $colunas[] = $coluna;
        $tiposColunas[$coluna] = strtolower((string)$colunaInfo['DATA_TYPE']);
    }

    if (empty($colunas)) {
        echo json_encode(["erro" => "Tabela sem colunas mapeadas: $nomeTabela"]);
        exit;
    }

    $colunasSql = implode(', ', array_map(function ($coluna) {
        return "`$coluna`";
    }, $colunas));

    $valoresSql = implode(', ', array_map(function ($coluna) {
        return ":$coluna";
    }, $colunas));

    $atualizacoes = [];
    foreach ($colunas as $coluna) {
        if (in_array($coluna, $chavesObrigatorias, true)) {
            continue;
        }
        $atualizacoes[] = "`$coluna` = VALUES(`$coluna`)";
    }

    $sql = "
        INSERT INTO `$nomeTabela` ($colunasSql)
        VALUES ($valoresSql)
        ON DUPLICATE KEY UPDATE
            " . implode(",\n            ", $atualizacoes) . "
    ";

    $stmt = $pdo->prepare($sql);
    $processadosLocal = 0;

    $pdo->beginTransaction();

    try {
        foreach ($dados as $d) {
            $temChaves = true;
            foreach ($chavesObrigatorias as $chave) {
                if (!isset($d[$chave]) || $d[$chave] === '') {
                    $temChaves = false;
                    break;
                }
            }

            if (!$temChaves) {
                continue;
            }

            $params = [];
            foreach ($colunas as $coluna) {
                $valor = $d[$coluna] ?? null;

                if (in_array($tiposColunas[$coluna] ?? '', ['date', 'datetime', 'timestamp', 'time'], true)) {
                    $valorTexto = trim((string)$valor);
                    if ($valor === null || $valorTexto === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $valorTexto)) {
                        $valor = null;
                    }
                }

                $params[":$coluna"] = $valor;
            }

            $stmt->execute($params);
            $processadosLocal++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo json_encode([
        "status" => "ok",
        "processados" => $processadosLocal
    ]);
    exit;
}

$configAtivosFirebird = [
    'bnc005_ativos' => ['tabela_mysql' => 'armazem_bnc005', 'coluna_chave' => 'ESCONTADOR', 'nome_firebird' => 'BNC005'],
    'cp001_ativos' => ['tabela_mysql' => 'armazem_cp001', 'coluna_chave' => 'CPCONTADOR', 'nome_firebird' => 'CP001'],
    'cp003_ativos' => ['tabela_mysql' => 'armazem_cp003', 'coluna_chave' => 'FCONTADOR', 'nome_firebird' => 'CP003'],
    'cp004_ativos' => ['tabela_mysql' => 'armazem_cp004', 'coluna_chave' => 'QTCPCONTADOR', 'nome_firebird' => 'CP004'],
    'est004_ativos' => ['tabela_mysql' => 'armazem_est004', 'coluna_chave' => 'CODPRODUTO', 'nome_firebird' => 'EST004'],
    'est005_ativos' => ['tabela_mysql' => 'armazem_est005', 'coluna_chave' => 'COMPRACONTADOR', 'nome_firebird' => 'EST005'],
    'est006_ativos' => ['tabela_mysql' => 'armazem_est006', 'coluna_chave' => 'COMPRACONTA', 'nome_firebird' => 'EST006'],
];

if (isset($configAtivosFirebird[$tabela])) {
    processarAtivosFirebird($pdo_master, $dados, $configAtivosFirebird[$tabela]);
}

$configTabelasGenericas = [
    'cp001' => ['tabela_mysql' => 'armazem_cp001', 'chaves' => ['CPCONTADOR']],
    'cp003' => ['tabela_mysql' => 'armazem_cp003', 'chaves' => ['FCONTADOR']],
    'cp004' => ['tabela_mysql' => 'armazem_cp004', 'chaves' => ['QTCPCONTADOR']],
];

if (isset($configTabelasGenericas[$tabela])) {
    processarTabelaFirebirdGenerica(
        $pdo_master,
        $dados,
        $configTabelasGenericas[$tabela]['tabela_mysql'],
        $configTabelasGenericas[$tabela]['chaves']
    );
}

/* =====================================================
   BNC001
===================================================== */
if ($tabela === 'bnc001') {

    $sql = "
        INSERT INTO armazem_bnc001 (
            EMPRESA, MOVCONTADOR, DTMOV, NUMDOC, TIPOMOV, PAGTOEM,
            CBCONTADOR, TIPOES, CLICONTADOR, FCONTADOR, HISTMOV,
            VALORMOV, TIPODOCORIGEM, NUMDOCORIGEM, CLASSIFICACAO,
            NUMCONTROLE, REGSTAMP, USERBNCLANC, NUMPARC,
            CONTRAPARTIDA, ORIGEMCPART, USERBNCALT,
            DTALT, DTLANC, DTPROCESSADO, CMCONTADOR, CNPJADM
        ) VALUES (
            :EMPRESA, :MOVCONTADOR, :DTMOV, :NUMDOC, :TIPOMOV, :PAGTOEM,
            :CBCONTADOR, :TIPOES, :CLICONTADOR, :FCONTADOR, :HISTMOV,
            :VALORMOV, :TIPODOCORIGEM, :NUMDOCORIGEM, :CLASSIFICACAO,
            :NUMCONTROLE, :REGSTAMP, :USERBNCLANC, :NUMPARC,
            :CONTRAPARTIDA, :ORIGEMCPART, :USERBNCALT,
            :DTALT, :DTLANC, :DTPROCESSADO, :CMCONTADOR, :CNPJADM
        )
        ON DUPLICATE KEY UPDATE
            DTMOV = VALUES(DTMOV),
            NUMDOC = VALUES(NUMDOC),
            TIPOMOV = VALUES(TIPOMOV),
            PAGTOEM = VALUES(PAGTOEM),
            CBCONTADOR = VALUES(CBCONTADOR),
            TIPOES = VALUES(TIPOES),
            CLICONTADOR = VALUES(CLICONTADOR),
            FCONTADOR = VALUES(FCONTADOR),
            HISTMOV = VALUES(HISTMOV),
            VALORMOV = VALUES(VALORMOV),
            TIPODOCORIGEM = VALUES(TIPODOCORIGEM),
            NUMDOCORIGEM = VALUES(NUMDOCORIGEM),
            CLASSIFICACAO = VALUES(CLASSIFICACAO),
            NUMCONTROLE = VALUES(NUMCONTROLE),
            REGSTAMP = VALUES(REGSTAMP),
            USERBNCLANC = VALUES(USERBNCLANC),
            NUMPARC = VALUES(NUMPARC),
            CONTRAPARTIDA = VALUES(CONTRAPARTIDA),
            ORIGEMCPART = VALUES(ORIGEMCPART),
            USERBNCALT = VALUES(USERBNCALT),
            DTALT = VALUES(DTALT),
            DTLANC = VALUES(DTLANC),
            DTPROCESSADO = VALUES(DTPROCESSADO),
            CMCONTADOR = VALUES(CMCONTADOR),
            CNPJADM = VALUES(CNPJADM)
    ";

    $stmt = $pdo_master->prepare($sql);

    foreach ($dados as $d) {

        if (empty($d['EMPRESA']) || empty($d['MOVCONTADOR'])) {
            continue;
        }

        $stmt->execute([
            ':EMPRESA' => $d['EMPRESA'] ?? null,
            ':MOVCONTADOR' => $d['MOVCONTADOR'] ?? null,
            ':DTMOV' => $d['DTMOV'] ?? null,
            ':NUMDOC' => $d['NUMDOC'] ?? null,
            ':TIPOMOV' => $d['TIPOMOV'] ?? null,
            ':PAGTOEM' => $d['PAGTOEM'] ?? null,
            ':CBCONTADOR' => $d['CBCONTADOR'] ?? null,
            ':TIPOES' => $d['TIPOES'] ?? null,
            ':CLICONTADOR' => $d['CLICONTADOR'] ?? null,
            ':FCONTADOR' => $d['FCONTADOR'] ?? null,
            ':HISTMOV' => $d['HISTMOV'] ?? null,
            ':VALORMOV' => $d['VALORMOV'] ?? null,
            ':TIPODOCORIGEM' => $d['TIPODOCORIGEM'] ?? null,
            ':NUMDOCORIGEM' => $d['NUMDOCORIGEM'] ?? null,
            ':CLASSIFICACAO' => $d['CLASSIFICACAO'] ?? null,
            ':NUMCONTROLE' => $d['NUMCONTROLE'] ?? null,
            ':REGSTAMP' => $d['REGSTAMP'] ?? null,
            ':USERBNCLANC' => $d['USERBNCLANC'] ?? null,
            ':NUMPARC' => $d['NUMPARC'] ?? null,
            ':CONTRAPARTIDA' => $d['CONTRAPARTIDA'] ?? null,
            ':ORIGEMCPART' => $d['ORIGEMCPART'] ?? null,
            ':USERBNCALT' => $d['USERBNCALT'] ?? null,
            ':DTALT' => $d['DTALT'] ?? null,
            ':DTLANC' => $d['DTLANC'] ?? null,
            ':DTPROCESSADO' => $d['DTPROCESSADO'] ?? null,
            ':CMCONTADOR' => $d['CMCONTADOR'] ?? null,
            ':CNPJADM' => $d['CNPJADM'] ?? null
        ]);

        $processados++;
    }
}

/* =====================================================
   CR001
===================================================== */
elseif ($tabela === 'cr001') {

    garantirControleExclusaoCR001($pdo_master);

    $sql = "
        INSERT INTO armazem_cr001 (
            EMPRESA, CRCONTADOR, DTVENDA, NUMPARCELA, NOTAFISCAL,
            TITULO, VALORVENDA, CLICONTADOR, CMCONTADOR,
            OBSERVACAO, NUMCH, DTEMISSAO, VLRPARCELA, PARCELA,
            DTVENC, VLRRESTANTE, VLRPAGO, DTPAGTO, STATUS,
            TIPODOCORIGEM, NUMDOCORIGEM, TIPOCR,
            REGSTAMP, USERLANC, TIPOES, DTLANC, CHAVEINTEGRACAO,
            excluido_firebird, data_exclusao_firebird, motivo_sync, ultima_presenca_firebird
        ) VALUES (
            :EMPRESA, :CRCONTADOR, :DTVENDA, :NUMPARCELA, :NOTAFISCAL,
            :TITULO, :VALORVENDA, :CLICONTADOR, :CMCONTADOR,
            :OBSERVACAO, :NUMCH, :DTEMISSAO, :VLRPARCELA, :PARCELA,
            :DTVENC, :VLRRESTANTE, :VLRPAGO, :DTPAGTO, :STATUS,
            :TIPODOCORIGEM, :NUMDOCORIGEM, :TIPOCR,
            :REGSTAMP, :USERLANC, :TIPOES, :DTLANC, :CHAVEINTEGRACAO,
            'N', NULL, NULL, NOW()
        )
        ON DUPLICATE KEY UPDATE
            VALORVENDA = VALUES(VALORVENDA),
            VLRPARCELA = VALUES(VLRPARCELA),
            VLRPAGO = VALUES(VLRPAGO),
            VLRRESTANTE = VALUES(VLRRESTANTE),
            STATUS = VALUES(STATUS),
            REGSTAMP = VALUES(REGSTAMP),
            excluido_firebird = 'N',
            data_exclusao_firebird = NULL,
            motivo_sync = NULL,
            ultima_presenca_firebird = NOW()
    ";

    $stmt = $pdo_master->prepare($sql);

    foreach ($dados as $d) {

        if (empty($d['CRCONTADOR'])) {
            continue;
        }

        $stmt->execute([
            ':EMPRESA' => $d['EMPRESA'] ?? null,
            ':CRCONTADOR' => $d['CRCONTADOR'] ?? null,
            ':DTVENDA' => $d['DTVENDA'] ?? null,
            ':NUMPARCELA' => $d['NUMPARCELA'] ?? null,
            ':NOTAFISCAL' => $d['NOTAFISCAL'] ?? null,
            ':TITULO' => $d['TITULO'] ?? null,
            ':VALORVENDA' => $d['VALORVENDA'] ?? null,
            ':CLICONTADOR' => $d['CLICONTADOR'] ?? null,
            ':CMCONTADOR' => $d['CMCONTADOR'] ?? null,
            ':OBSERVACAO' => $d['OBSERVACAO'] ?? null,
            ':NUMCH' => $d['NUMCH'] ?? null,
            ':DTEMISSAO' => $d['DTEMISSAO'] ?? null,
            ':VLRPARCELA' => $d['VLRPARCELA'] ?? null,
            ':PARCELA' => $d['PARCELA'] ?? null,
            ':DTVENC' => $d['DTVENC'] ?? null,
            ':VLRRESTANTE' => $d['VLRRESTANTE'] ?? null,
            ':VLRPAGO' => $d['VLRPAGO'] ?? null,
            ':DTPAGTO' => $d['DTPAGTO'] ?? null,
            ':STATUS' => $d['STATUS'] ?? null,
            ':TIPODOCORIGEM' => $d['TIPODOCORIGEM'] ?? null,
            ':NUMDOCORIGEM' => $d['NUMDOCORIGEM'] ?? null,
            ':TIPOCR' => $d['TIPOCR'] ?? null,
            ':REGSTAMP' => $d['REGSTAMP'] ?? null,
            ':USERLANC' => $d['USERLANC'] ?? null,
            ':TIPOES' => $d['TIPOES'] ?? null,
            ':DTLANC' => $d['DTLANC'] ?? null,
            ':CHAVEINTEGRACAO' => $d['CHAVEINTEGRACAO'] ?? null
        ]);

        $processados++;
    }
}

/* =====================================================
   CR001 ATIVOS - FOTO PARA DETECTAR EXCLUSOES
===================================================== */
elseif ($tabela === 'cr001_ativos') {

    garantirControleExclusaoCR001($pdo_master);

    $inicio = $_GET['inicio'] ?? ($dados['inicio'] ?? null);
    $fim = $_GET['fim'] ?? ($dados['fim'] ?? null);
    $registros = $dados['registros'] ?? $dados['ativos'] ?? $dados;

    if (!$inicio || !$fim || !is_array($registros)) {
        echo json_encode([
            "erro" => "Informe inicio, fim e a lista de CRCONTADOR ativos."
        ]);
        exit;
    }

    $ids = [];
    foreach ($registros as $item) {
        $crcontador = is_array($item) ? ($item['CRCONTADOR'] ?? null) : $item;
        if (!empty($crcontador)) {
            $ids[(int)$crcontador] = true;
        }
    }

    if (empty($ids) && ($_GET['confirmar_vazio'] ?? '') !== '1') {
        echo json_encode([
            "erro" => "Lista de CRCONTADOR ativos vazia. Para marcar todos do periodo como excluidos, envie confirmar_vazio=1."
        ]);
        exit;
    }

    $pdo_master->beginTransaction();

    $pdo_master->exec("
        CREATE TEMPORARY TABLE tmp_cr001_ativos_sync (
            CRCONTADOR INT NOT NULL PRIMARY KEY
        ) ENGINE=Memory
    ");

    if (!empty($ids)) {
        $stmtTmp = $pdo_master->prepare("INSERT IGNORE INTO tmp_cr001_ativos_sync (CRCONTADOR) VALUES (?)");
        foreach (array_keys($ids) as $crcontador) {
            $stmtTmp->execute([$crcontador]);
        }
    }

    $stmtReativar = $pdo_master->prepare("
        UPDATE armazem_cr001 c
        INNER JOIN tmp_cr001_ativos_sync t
            ON t.CRCONTADOR = c.CRCONTADOR
        SET c.excluido_firebird = 'N',
            c.data_exclusao_firebird = NULL,
            c.motivo_sync = NULL,
            c.ultima_presenca_firebird = NOW()
        WHERE c.DTLANC BETWEEN ? AND ?
    ");
    $stmtReativar->execute([$inicio, $fim]);
    $reativados = $stmtReativar->rowCount();

    $stmtExcluir = $pdo_master->prepare("
        UPDATE armazem_cr001 c
        LEFT JOIN tmp_cr001_ativos_sync t
            ON t.CRCONTADOR = c.CRCONTADOR
        SET c.excluido_firebird = 'S',
            c.data_exclusao_firebird = NOW(),
            c.motivo_sync = 'Nao encontrado na foto CR001 do Firebird'
        WHERE c.DTLANC BETWEEN ? AND ?
          AND t.CRCONTADOR IS NULL
          AND COALESCE(c.excluido_firebird, 'N') <> 'S'
    ");
    $stmtExcluir->execute([$inicio, $fim]);
    $marcadosExcluidos = $stmtExcluir->rowCount();

    $pdo_master->commit();

    echo json_encode([
        "status" => "ok",
        "processados" => count($ids),
        "reativados" => $reativados,
        "marcados_excluidos" => $marcadosExcluidos,
        "inicio" => $inicio,
        "fim" => $fim
    ]);
    exit;
}

/* =====================================================
   CR002
===================================================== */
elseif ($tabela === 'cr002') {

    $sql = "
        INSERT INTO armazem_cr002 (
            EMPRESA, CLICONTADOR, NOME, APELIDO, TIPOCLIENTE,
            CGC, CPF, DTCADASTRO, CELULAR, BLOQUEADO,
            RAMOATIVIDADE, DTNASC, LIMITECREDITO, CONDPAGTO,
            SEXO, CODCLIFORN, REGSTAMP, INATIVO,
            REGIMPORT, DTALTERACAO, CONVENIO, USERALTERA
        ) VALUES (
            :EMPRESA, :CLICONTADOR, :NOME, :APELIDO, :TIPOCLIENTE,
            :CGC, :CPF, :DTCADASTRO, :CELULAR, :BLOQUEADO,
            :RAMOATIVIDADE, :DTNASC, :LIMITECREDITO, :CONDPAGTO,
            :SEXO, :CODCLIFORN, :REGSTAMP, :INATIVO,
            :REGIMPORT, :DTALTERACAO, :CONVENIO, :USERALTERA
        )
        ON DUPLICATE KEY UPDATE
            NOME = VALUES(NOME),
            APELIDO = VALUES(APELIDO),
            CELULAR = VALUES(CELULAR),
            REGSTAMP = VALUES(REGSTAMP)
    ";

    $stmt = $pdo_master->prepare($sql);

    foreach ($dados as $d) {

        if (empty($d['CLICONTADOR'])) {
            continue;
        }

        $stmt->execute([
            ':EMPRESA' => $d['EMPRESA'] ?? null,
            ':CLICONTADOR' => $d['CLICONTADOR'] ?? null,
            ':NOME' => $d['NOME'] ?? null,
            ':APELIDO' => $d['APELIDO'] ?? null,
            ':TIPOCLIENTE' => $d['TIPOCLIENTE'] ?? null,
            ':CGC' => $d['CGC'] ?? null,
            ':CPF' => $d['CPF'] ?? null,
            ':DTCADASTRO' => $d['DTCADASTRO'] ?? null,
            ':CELULAR' => $d['CELULAR'] ?? null,
            ':BLOQUEADO' => $d['BLOQUEADO'] ?? null,
            ':RAMOATIVIDADE' => $d['RAMOATIVIDADE'] ?? null,
            ':DTNASC' => $d['DTNASC'] ?? null,
            ':LIMITECREDITO' => $d['LIMITECREDITO'] ?? null,
            ':CONDPAGTO' => $d['CONDPAGTO'] ?? null,
            ':SEXO' => $d['SEXO'] ?? null,
            ':CODCLIFORN' => $d['CODCLIFORN'] ?? null,
            ':REGSTAMP' => $d['REGSTAMP'] ?? null,
            ':INATIVO' => $d['INATIVO'] ?? null,
            ':REGIMPORT' => $d['REGIMPORT'] ?? null,
            ':DTALTERACAO' => $d['DTALTERACAO'] ?? null,
            ':CONVENIO' => $d['CONVENIO'] ?? null,
            ':USERALTERA' => $d['USERALTERA'] ?? null
        ]);

        $processados++;
    }
}

/* =====================================================
   ZCONFIG005
===================================================== */
elseif ($tabela === 'zconfig005') {

    $sql = "
        INSERT INTO armazem_zconfig005 (
            EMPRESA, CODUSER, NOMEUSER, DESATIVADO, CODCX, REGSTAMP
        ) VALUES (
            :EMPRESA, :CODUSER, :NOMEUSER, :DESATIVADO, :CODCX, :REGSTAMP
        )
        ON DUPLICATE KEY UPDATE
            NOMEUSER = VALUES(NOMEUSER),
            DESATIVADO = VALUES(DESATIVADO),
            CODCX = VALUES(CODCX),
            REGSTAMP = VALUES(REGSTAMP)
    ";

    $stmt = $pdo_master->prepare($sql);

    foreach ($dados as $d) {

        if (empty($d['CODUSER'])) {
            continue;
        }

        $stmt->execute([
            ':EMPRESA' => $d['EMPRESA'] ?? null,
            ':CODUSER' => $d['CODUSER'] ?? null,
            ':NOMEUSER' => $d['NOMEUSER'] ?? null,
            ':DESATIVADO' => $d['DESATIVADO'] ?? null,
            ':CODCX' => $d['CODCX'] ?? null,
            ':REGSTAMP' => $d['REGSTAMP'] ?? null
        ]);

        $processados++;
    }
}

/* =====================================================
   EST004 - PRODUTOS
===================================================== */
elseif ($tabela === 'est004') {

    $sql = "
        INSERT INTO armazem_est004 (
            EMPRESA, CODPRODUTO, CONTAPRODUTO, DESCPRODUTO,
            UNIDADE, REFERENCIA, ESPECIFICACAO, GRUPO, SUBGRUPO,
            PRECOCUSTO, CUSTOMEDIO, PRECOVENDA, PRECOPRAZO1,
            ESTINICIAL, ESTOQUE, ESTMINIMO, ESTMAXIMO,
            PRECOCOMPRA, MEDIACOMPRA, MEDIACUSTO, PRECOFINAL,
            MEDIAFINAL, MLUCRO1, PVENDA1, MLUCRO2, PVENDA2,
            DTULTCOMPRA, DOCCOMPRA, ULTFORNECEDOR, DTULTVENDA,
            DOCVENDA, ULTCLIENTE, MARCA, EMB_QTDE,
            ULTQTDECOMPRADA, INATIVO, NOMEREGISTRA, REFERENCIA2,
            REGSTAMP, CLASSIFICACAO, TIPOAPARELHO, VENDA_AVULSA,
            NOMEFISCAL, PRODUTOFINAL, DTPREVISTA, DTULTALTERACAO,
            REFERENCIA4, USERLANC, DTLANC, USERALT, DTALT,
            PVENDA1ANT, USERALTPRECO
        ) VALUES (
            :EMPRESA, :CODPRODUTO, :CONTAPRODUTO, :DESCPRODUTO,
            :UNIDADE, :REFERENCIA, :ESPECIFICACAO, :GRUPO, :SUBGRUPO,
            :PRECOCUSTO, :CUSTOMEDIO, :PRECOVENDA, :PRECOPRAZO1,
            :ESTINICIAL, :ESTOQUE, :ESTMINIMO, :ESTMAXIMO,
            :PRECOCOMPRA, :MEDIACOMPRA, :MEDIACUSTO, :PRECOFINAL,
            :MEDIAFINAL, :MLUCRO1, :PVENDA1, :MLUCRO2, :PVENDA2,
            :DTULTCOMPRA, :DOCCOMPRA, :ULTFORNECEDOR, :DTULTVENDA,
            :DOCVENDA, :ULTCLIENTE, :MARCA, :EMB_QTDE,
            :ULTQTDECOMPRADA, :INATIVO, :NOMEREGISTRA, :REFERENCIA2,
            :REGSTAMP, :CLASSIFICACAO, :TIPOAPARELHO, :VENDA_AVULSA,
            :NOMEFISCAL, :PRODUTOFINAL, :DTPREVISTA, :DTULTALTERACAO,
            :REFERENCIA4, :USERLANC, :DTLANC, :USERALT, :DTALT,
            :PVENDA1ANT, :USERALTPRECO
        )
        ON DUPLICATE KEY UPDATE
            CONTAPRODUTO = VALUES(CONTAPRODUTO),
            DESCPRODUTO = VALUES(DESCPRODUTO),
            UNIDADE = VALUES(UNIDADE),
            REFERENCIA = VALUES(REFERENCIA),
            GRUPO = VALUES(GRUPO),
            SUBGRUPO = VALUES(SUBGRUPO),
            PRECOVENDA = VALUES(PRECOVENDA),
            ESTOQUE = VALUES(ESTOQUE),
            INATIVO = VALUES(INATIVO),
            NOMEFISCAL = VALUES(NOMEFISCAL),
            REGSTAMP = VALUES(REGSTAMP),
            USERALT = VALUES(USERALT),
            DTALT = VALUES(DTALT)
    ";

    $stmt = $pdo_master->prepare($sql);
    $pdo_master->beginTransaction();

    foreach ($dados as $d) {
        if (empty($d['CODPRODUTO'])) {
            continue;
        }

        $stmt->execute([
            ':EMPRESA' => $d['EMPRESA'] ?? null,
            ':CODPRODUTO' => $d['CODPRODUTO'] ?? null,
            ':CONTAPRODUTO' => $d['CONTAPRODUTO'] ?? null,
            ':DESCPRODUTO' => $d['DESCPRODUTO'] ?? null,
            ':UNIDADE' => $d['UNIDADE'] ?? null,
            ':REFERENCIA' => $d['REFERENCIA'] ?? null,
            ':ESPECIFICACAO' => $d['ESPECIFICACAO'] ?? null,
            ':GRUPO' => $d['GRUPO'] ?? null,
            ':SUBGRUPO' => $d['SUBGRUPO'] ?? null,
            ':PRECOCUSTO' => $d['PRECOCUSTO'] ?? null,
            ':CUSTOMEDIO' => $d['CUSTOMEDIO'] ?? null,
            ':PRECOVENDA' => $d['PRECOVENDA'] ?? null,
            ':PRECOPRAZO1' => $d['PRECOPRAZO1'] ?? null,
            ':ESTINICIAL' => $d['ESTINICIAL'] ?? null,
            ':ESTOQUE' => $d['ESTOQUE'] ?? null,
            ':ESTMINIMO' => $d['ESTMINIMO'] ?? null,
            ':ESTMAXIMO' => $d['ESTMAXIMO'] ?? null,
            ':PRECOCOMPRA' => $d['PRECOCOMPRA'] ?? null,
            ':MEDIACOMPRA' => $d['MEDIACOMPRA'] ?? null,
            ':MEDIACUSTO' => $d['MEDIACUSTO'] ?? null,
            ':PRECOFINAL' => $d['PRECOFINAL'] ?? null,
            ':MEDIAFINAL' => $d['MEDIAFINAL'] ?? null,
            ':MLUCRO1' => $d['MLUCRO1'] ?? null,
            ':PVENDA1' => $d['PVENDA1'] ?? null,
            ':MLUCRO2' => $d['MLUCRO2'] ?? null,
            ':PVENDA2' => $d['PVENDA2'] ?? null,
            ':DTULTCOMPRA' => $d['DTULTCOMPRA'] ?? null,
            ':DOCCOMPRA' => $d['DOCCOMPRA'] ?? null,
            ':ULTFORNECEDOR' => $d['ULTFORNECEDOR'] ?? null,
            ':DTULTVENDA' => $d['DTULTVENDA'] ?? null,
            ':DOCVENDA' => $d['DOCVENDA'] ?? null,
            ':ULTCLIENTE' => $d['ULTCLIENTE'] ?? null,
            ':MARCA' => $d['MARCA'] ?? null,
            ':EMB_QTDE' => $d['EMB_QTDE'] ?? null,
            ':ULTQTDECOMPRADA' => $d['ULTQTDECOMPRADA'] ?? null,
            ':INATIVO' => $d['INATIVO'] ?? null,
            ':NOMEREGISTRA' => $d['NOMEREGISTRA'] ?? null,
            ':REFERENCIA2' => $d['REFERENCIA2'] ?? null,
            ':REGSTAMP' => $d['REGSTAMP'] ?? null,
            ':CLASSIFICACAO' => $d['CLASSIFICACAO'] ?? null,
            ':TIPOAPARELHO' => $d['TIPOAPARELHO'] ?? null,
            ':VENDA_AVULSA' => $d['VENDA_AVULSA'] ?? null,
            ':NOMEFISCAL' => $d['NOMEFISCAL'] ?? null,
            ':PRODUTOFINAL' => $d['PRODUTOFINAL'] ?? null,
            ':DTPREVISTA' => $d['DTPREVISTA'] ?? null,
            ':DTULTALTERACAO' => $d['DTULTALTERACAO'] ?? null,
            ':REFERENCIA4' => $d['REFERENCIA4'] ?? null,
            ':USERLANC' => $d['USERLANC'] ?? null,
            ':DTLANC' => $d['DTLANC'] ?? null,
            ':USERALT' => $d['USERALT'] ?? null,
            ':DTALT' => $d['DTALT'] ?? null,
            ':PVENDA1ANT' => $d['PVENDA1ANT'] ?? null,
            ':USERALTPRECO' => $d['USERALTPRECO'] ?? null,
        ]);

        $processados++;
    }

    $pdo_master->commit();
}

/* =====================================================
   EST007
===================================================== */
elseif ($tabela === 'est007') {

    $sql = "
        INSERT INTO armazem_est007 (
            EMPRESA, VENDACONTADOR, DTEMISSAO, DTVENDA,
            NUMDOC, TIPOPAGTO, CLIENTE, VENDEDOR,
            OBSERVACAO, TOTVENDA, TOTACRESCIMO, TOTDESCONTO,
            TOTGERAL, TOTIPI, TOTCUSTO, QTDEVENDA,
            CONDPAGTO, TOTPRODVENDA, NUMPARCELAS,
            CMCONTADOR, QTDEVENDA2, TIPOVENDA,
            CANCELADO, DTCANCELADO, FINANCGERADO,
            NUMCUPOM, REGSTAMP, USERDIGITA,
            DTENTREGAPED, USERLANC, USERALTERA,
            DTLANC, DTALT, MOVIMENTAESTOQUE,
            DEVOLUCAO, TIPOES, HRVENDA
        ) VALUES (
            :EMPRESA, :VENDACONTADOR, :DTEMISSAO, :DTVENDA,
            :NUMDOC, :TIPOPAGTO, :CLIENTE, :VENDEDOR,
            :OBSERVACAO, :TOTVENDA, :TOTACRESCIMO, :TOTDESCONTO,
            :TOTGERAL, :TOTIPI, :TOTCUSTO, :QTDEVENDA,
            :CONDPAGTO, :TOTPRODVENDA, :NUMPARCELAS,
            :CMCONTADOR, :QTDEVENDA2, :TIPOVENDA,
            :CANCELADO, :DTCANCELADO, :FINANCGERADO,
            :NUMCUPOM, :REGSTAMP, :USERDIGITA,
            :DTENTREGAPED, :USERLANC, :USERALTERA,
            :DTLANC, :DTALT, :MOVIMENTAESTOQUE,
            :DEVOLUCAO, :TIPOES, :HRVENDA
        )
        ON DUPLICATE KEY UPDATE
            TOTGERAL = VALUES(TOTGERAL),
            CMCONTADOR = VALUES(CMCONTADOR),
            CANCELADO = VALUES(CANCELADO),
            REGSTAMP = VALUES(REGSTAMP)
    ";

    $stmt = $pdo_master->prepare($sql);

    $pdo_master->beginTransaction();

    foreach ($dados as $d) {

        if (empty($d['VENDACONTADOR'])) {
            continue;
        }

        $stmt->execute([
            ':EMPRESA' => $d['EMPRESA'] ?? null,
            ':VENDACONTADOR' => $d['VENDACONTADOR'] ?? null,
            ':DTEMISSAO' => $d['DTEMISSAO'] ?? null,
            ':DTVENDA' => $d['DTVENDA'] ?? null,
            ':NUMDOC' => $d['NUMDOC'] ?? null,
            ':TIPOPAGTO' => $d['TIPOPAGTO'] ?? null,
            ':CLIENTE' => $d['CLIENTE'] ?? null,
            ':VENDEDOR' => $d['VENDEDOR'] ?? null,
            ':OBSERVACAO' => $d['OBSERVACAO'] ?? null,
            ':TOTVENDA' => $d['TOTVENDA'] ?? null,
            ':TOTACRESCIMO' => $d['TOTACRESCIMO'] ?? null,
            ':TOTDESCONTO' => $d['TOTDESCONTO'] ?? null,
            ':TOTGERAL' => $d['TOTGERAL'] ?? null,
            ':TOTIPI' => $d['TOTIPI'] ?? null,
            ':TOTCUSTO' => $d['TOTCUSTO'] ?? null,
            ':QTDEVENDA' => $d['QTDEVENDA'] ?? null,
            ':CONDPAGTO' => null,
            ':TOTPRODVENDA' => $d['TOTPRODVENDA'] ?? null,
            ':NUMPARCELAS' => $d['NUMPARCELAS'] ?? null,
            ':CMCONTADOR' => $d['CMCONTADOR'] ?? null,
            ':QTDEVENDA2' => $d['QTDEVENDA2'] ?? null,
            ':TIPOVENDA' => $d['TIPOVENDA'] ?? null,
            ':CANCELADO' => $d['CANCELADO'] ?? null,
            ':DTCANCELADO' => $d['DTCANCELADO'] ?? null,
            ':FINANCGERADO' => $d['FINANCEGERADO'] ?? null,
            ':NUMCUPOM' => $d['NUMCUPOM'] ?? null,
            ':REGSTAMP' => $d['REGSTAMP'] ?? null,
            ':USERDIGITA' => $d['USERDIGITA'] ?? null,
            ':DTENTREGAPED' => $d['DTENTREGAPED'] ?? null,
            ':USERLANC' => $d['USERLANC'] ?? null,
            ':USERALTERA' => $d['USERALTERA'] ?? null,
            ':DTLANC' => $d['DTLANC'] ?? null,
            ':DTALT' => $d['DTALT'] ?? null,
            ':MOVIMENTAESTOQUE' => $d['MOVIMENTAESTOQUE'] ?? null,
            ':DEVOLUCAO' => $d['DEVOLUCAO'] ?? null,
            ':TIPOES' => $d['TIPOES'] ?? null,
            ':HRVENDA' => $d['HRVENDA'] ?? null
        ]);

        $processados++;
    }

    $pdo_master->commit();
}

/* =====================================================
   EST008 - ITENS DA VENDA
===================================================== */
elseif ($tabela === 'est008') {

    $stmtIndice = $pdo_master->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'armazem_est008'
          AND INDEX_NAME = 'uniq_est008_item_venda'
    ");
    $stmtIndice->execute();
    if ((int)$stmtIndice->fetchColumn() === 0) {
        $pdo_master->exec("
            ALTER TABLE armazem_est008
            ADD UNIQUE KEY uniq_est008_item_venda
                (EMPRESA, ITEMVENDACONTADOR, VENDACONTA, PRODUTO)
        ");
    }

    $sql = "
        INSERT INTO armazem_est008 (
            EMPRESA, ITEMVENDACONTADOR, VENDACONTA, PRODUTO,
            QTDE, VALOR, TOTPROD, TIPOVENDA, PESOTROCA,
            PCUSTO, REL1, CFOPVENDA, TOTCUSTO, CANCELADO,
            CLASSIFICACAO, REGSTAMP, CODVENDITEM, PRODPROMO,
            USERLANC, USERALTERA, QTDECX, PESOCX, PRECODIA,
            SOMARTOTVENDA, MOVESTOQUE, TABELAPRECO,
            SITUACAOENTREGA, VLRRATEIODESCONTO
        ) VALUES (
            :EMPRESA, :ITEMVENDACONTADOR, :VENDACONTA, :PRODUTO,
            :QTDE, :VALOR, :TOTPROD, :TIPOVENDA, :PESOTROCA,
            :PCUSTO, :REL1, :CFOPVENDA, :TOTCUSTO, :CANCELADO,
            :CLASSIFICACAO, :REGSTAMP, :CODVENDITEM, :PRODPROMO,
            :USERLANC, :USERALTERA, :QTDECX, :PESOCX, :PRECODIA,
            :SOMARTOTVENDA, :MOVESTOQUE, :TABELAPRECO,
            :SITUACAOENTREGA, :VLRRATEIODESCONTO
        )
        ON DUPLICATE KEY UPDATE
            QTDE = VALUES(QTDE),
            VALOR = VALUES(VALOR),
            TOTPROD = VALUES(TOTPROD),
            CANCELADO = VALUES(CANCELADO),
            CLASSIFICACAO = VALUES(CLASSIFICACAO),
            REGSTAMP = VALUES(REGSTAMP),
            USERALTERA = VALUES(USERALTERA),
            QTDECX = VALUES(QTDECX),
            PESOCX = VALUES(PESOCX),
            PRECODIA = VALUES(PRECODIA),
            SOMARTOTVENDA = VALUES(SOMARTOTVENDA),
            MOVESTOQUE = VALUES(MOVESTOQUE),
            SITUACAOENTREGA = VALUES(SITUACAOENTREGA),
            VLRRATEIODESCONTO = VALUES(VLRRATEIODESCONTO)
    ";

    $stmt = $pdo_master->prepare($sql);
    $pdo_master->beginTransaction();

    foreach ($dados as $d) {
        if (empty($d['ITEMVENDACONTADOR']) || empty($d['VENDACONTA']) || empty($d['PRODUTO'])) {
            continue;
        }

        $stmt->execute([
            ':EMPRESA' => $d['EMPRESA'] ?? null,
            ':ITEMVENDACONTADOR' => $d['ITEMVENDACONTADOR'] ?? null,
            ':VENDACONTA' => $d['VENDACONTA'] ?? null,
            ':PRODUTO' => $d['PRODUTO'] ?? null,
            ':QTDE' => $d['QTDE'] ?? null,
            ':VALOR' => $d['VALOR'] ?? null,
            ':TOTPROD' => $d['TOTPROD'] ?? null,
            ':TIPOVENDA' => $d['TIPOVENDA'] ?? null,
            ':PESOTROCA' => $d['PESOTROCA'] ?? null,
            ':PCUSTO' => $d['PCUSTO'] ?? null,
            ':REL1' => $d['REL1'] ?? null,
            ':CFOPVENDA' => $d['CFOPVENDA'] ?? null,
            ':TOTCUSTO' => $d['TOTCUSTO'] ?? null,
            ':CANCELADO' => $d['CANCELADO'] ?? null,
            ':CLASSIFICACAO' => $d['CLASSIFICACAO'] ?? null,
            ':REGSTAMP' => $d['REGSTAMP'] ?? null,
            ':CODVENDITEM' => $d['CODVENDITEM'] ?? null,
            ':PRODPROMO' => $d['PRODPROMO'] ?? null,
            ':USERLANC' => $d['USERLANC'] ?? null,
            ':USERALTERA' => $d['USERALTERA'] ?? null,
            ':QTDECX' => $d['QTDECX'] ?? null,
            ':PESOCX' => $d['PESOCX'] ?? null,
            ':PRECODIA' => $d['PRECODIA'] ?? null,
            ':SOMARTOTVENDA' => $d['SOMARTOTVENDA'] ?? null,
            ':MOVESTOQUE' => $d['MOVESTOQUE'] ?? null,
            ':TABELAPRECO' => $d['TABELAPRECO'] ?? null,
            ':SITUACAOENTREGA' => $d['SITUACAOENTREGA'] ?? null,
            ':VLRRATEIODESCONTO' => $d['VLRRATEIODESCONTO'] ?? null,
        ]);

        $processados++;
    }

    $pdo_master->commit();
}

echo json_encode([
    "status" => "ok",
    "processados" => $processados
]);
