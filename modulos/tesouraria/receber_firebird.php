<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../../config/conexao.php';

header('Content-Type: application/json');

/* =========================
   PARÂMETRO TABELA
========================= */
$tabela = $_GET['tabela'] ?? 'bnc001';

$tabelas_permitidas = ['bnc001', 'cr001', 'cr002', 'est007', 'zconfig005'];

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

if (count($dados) === 0) {
    echo json_encode([
        "status" => "ok",
        "processados" => 0
    ]);
    exit;
}

$processados = 0;

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

    $sql = "
        INSERT INTO armazem_cr001 (
            EMPRESA, CRCONTADOR, DTVENDA, NUMPARCELA, NOTAFISCAL,
            TITULO, VALORVENDA, CLICONTADOR, CMCONTADOR,
            OBSERVACAO, NUMCH, DTEMISSAO, VLRPARCELA, PARCELA,
            DTVENC, VLRRESTANTE, VLRPAGO, DTPAGTO, STATUS,
            TIPODOCORIGEM, NUMDOCORIGEM, TIPOCR,
            REGSTAMP, USERLANC, TIPOES, DTLANC, CHAVEINTEGRACAO
        ) VALUES (
            :EMPRESA, :CRCONTADOR, :DTVENDA, :NUMPARCELA, :NOTAFISCAL,
            :TITULO, :VALORVENDA, :CLICONTADOR, :CMCONTADOR,
            :OBSERVACAO, :NUMCH, :DTEMISSAO, :VLRPARCELA, :PARCELA,
            :DTVENC, :VLRRESTANTE, :VLRPAGO, :DTPAGTO, :STATUS,
            :TIPODOCORIGEM, :NUMDOCORIGEM, :TIPOCR,
            :REGSTAMP, :USERLANC, :TIPOES, :DTLANC, :CHAVEINTEGRACAO
        )
        ON DUPLICATE KEY UPDATE
            VALORVENDA = VALUES(VALORVENDA),
            VLRPARCELA = VALUES(VLRPARCELA),
            VLRPAGO = VALUES(VLRPAGO),
            VLRRESTANTE = VALUES(VLRRESTANTE),
            STATUS = VALUES(STATUS),
            REGSTAMP = VALUES(REGSTAMP)
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

echo json_encode([
    "status" => "ok",
    "processados" => $processados
]);