<?php

function mbaGarantirVinculosCpCr(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS movimentacao_baixa_cp_cr_vinculos (
        empresa_id INT NOT NULL,
        cpcontador INT NOT NULL,
        crcontador INT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'ATIVO',
        usuario_id INT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        excluido_por INT NULL,
        excluido_em DATETIME NULL,
        PRIMARY KEY (empresa_id,cpcontador),
        UNIQUE KEY uq_mba_vinculo_cr (empresa_id,crcontador)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function mbaVinculoCpCr(PDO $pdo,int $empresa,string $lado,int $contador): ?array
{
    $coluna=$lado==='CP'?'cpcontador':'crcontador';
    $s=$pdo->prepare("SELECT * FROM movimentacao_baixa_cp_cr_vinculos WHERE empresa_id=? AND $coluna=? AND status='ATIVO'");
    $s->execute([$empresa,$contador]);
    return $s->fetch(PDO::FETCH_ASSOC)?:null;
}

function mbaTipoesParCpCr(PDO $pdo,int $empresa): array
{
    $s=$pdo->prepare("SELECT ESCONTADOR,TIPOMOV,CONTRAP_TIPOES,UPPER(REPLACE(TRIM(DESCES),' ','')) AS nome FROM armazem_bnc005 WHERE EMPRESA=? AND COALESCE(REGDISAB,'N')<>'S' AND COALESCE(excluido_firebird,'N')<>'S' AND UPPER(REPLACE(TRIM(DESCES),' ','')) IN ('DCONTRAPARTIDAPAGAR','CCONTRAPARTIDARECEBER')");
    $s->execute([$empresa]);$tipos=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $tipo){
        if(isset($tipos[$tipo['nome']]))throw new RuntimeException('Ha TIPOES de contrapartida duplicados.');
        $tipos[$tipo['nome']]=$tipo;
    }
    $cp=$tipos['DCONTRAPARTIDAPAGAR']??null;$cr=$tipos['CCONTRAPARTIDARECEBER']??null;
    if(!$cp || !$cr || $cp['TIPOMOV']!=='D' || $cr['TIPOMOV']!=='C' || (int)$cp['CONTRAP_TIPOES']!==(int)$cr['ESCONTADOR'] || (int)$cr['CONTRAP_TIPOES']!==(int)$cp['ESCONTADOR'])throw new RuntimeException('Configure os TIPOES D CONTRAPARTIDA PAGAR e C CONTRA PARTIDA RECEBER como contrapartidas reciprocas.');
    return ['cp'=>(int)$cp['ESCONTADOR'],'cr'=>(int)$cr['ESCONTADOR']];
}

function mbaCriarCrDosCp(PDO $pdo,int $empresa,int $usuario,array $cpIds,int $cliente): array
{
    $tipoes=mbaTipoesParCpCr($pdo,$empresa)['cr'];
    $s=$pdo->prepare("SELECT 1 FROM armazem_cr002 WHERE EMPRESA=? AND CLICONTADOR=? AND COALESCE(excluido_firebird,'N')<>'S'");
    $s->execute([$empresa,$cliente]);if(!$s->fetchColumn())throw new RuntimeException('Selecione um cliente valido para o recebimento.');
    $s=$pdo->prepare("SELECT 1 FROM armazem_bnc005 WHERE EMPRESA=? AND ESCONTADOR=? AND TIPOMOV='C' AND COALESCE(REGDISAB,'N')<>'S' AND COALESCE(excluido_firebird,'N')<>'S'");
    $s->execute([$empresa,$tipoes]);if(!$s->fetchColumn())throw new RuntimeException('Selecione um TIPOES de credito valido para o CR.');
    $select=$pdo->prepare("SELECT * FROM armazem_cp001 WHERE EMPRESA=? AND CPCONTADOR=? AND STATUS='AB' AND COALESCE(excluido_firebird,'N')<>'S'");
    $proximo=$pdo->prepare("SELECT COALESCE(MAX(CRCONTADOR),0)+1 FROM armazem_cr001 WHERE EMPRESA=?");
    $ins=$pdo->prepare("INSERT INTO armazem_cr001 (EMPRESA,CRCONTADOR,DTVENDA,NUMPARCELA,TITULO,VALORVENDA,CLICONTADOR,OBSERVACAO,DTEMISSAO,VLRPARCELA,PARCELA,DTVENC,VLRRESTANTE,VLRPAGO,STATUS,TIPODOCORIGEM,NUMDOCORIGEM,CONTROLE,TIPOCR,TIPOES,NOTAFISCAL,REGSTAMP,USERLANC,DTLANC,USERALT,DTALT,CHAVEINTEGRACAO,financeiro_verificado,excluido_firebird) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,'AB','SUPERDUNGA',?,'MOVIMENTACAO_BAIXA','CR',?,?,NOW(),?,NOW(),?,NOW(),?,'N','N')");
    $link=$pdo->prepare("INSERT INTO movimentacao_baixa_cp_cr_vinculos(empresa_id,cpcontador,crcontador,usuario_id) VALUES(?,?,?,?)");
    $crIds=[];
    foreach($cpIds as $cpId){
        $select->execute([$empresa,(int)$cpId]);$cp=$select->fetch(PDO::FETCH_ASSOC);
        if(!$cp || mbaVinculoCpCr($pdo,$empresa,'CP',(int)$cpId))throw new RuntimeException('CP nao disponivel para gerar o recebimento.');
        $proximo->execute([$empresa]);$crId=(int)$proximo->fetchColumn();
        $obs=trim((string)($cp['OBSERVACAO']??''));$obs=mb_substr(($obs!==''?$obs.' | ':'').'Reembolso do CP #'.$cpId,0,500);
        $ins->execute([$empresa,$crId,$cp['DTCOMPRA'],(int)$cp['NUMPARCELA'],$cp['TITULO'],$cp['VALORCOMPRA'],$cliente,$obs,$cp['DTEMISSAO'],$cp['VLRPARCELA'],$cp['PARCELA'],$cp['DTVENC'],$cp['VLRPARCELA'],(int)$cpId,$tipoes,$cp['NOTAFISCAL'],$usuario?:null,$usuario?:null,'MOVBAIXA-CR-'.$empresa.'-'.$crId]);
        $link->execute([$empresa,(int)$cpId,$crId,$usuario?:null]);$crIds[]=$crId;
    }
    return $crIds;
}

function mbaExcluirParCpCr(PDO $pdo,int $empresa,int $usuario,string $lado,int $contador): array
{
    $pdo->beginTransaction();
    try{
        $coluna=$lado==='CP'?'cpcontador':'crcontador';
        $s=$pdo->prepare("SELECT * FROM movimentacao_baixa_cp_cr_vinculos WHERE empresa_id=? AND $coluna=? AND status='ATIVO' FOR UPDATE");
        $s->execute([$empresa,$contador]);$v=$s->fetch(PDO::FETCH_ASSOC);
        if(!$v)throw new RuntimeException('Par CP/CR ativo nao encontrado.');
        foreach([['armazem_cp001','CPCONTADOR','CP',$v['cpcontador']],['armazem_cr001','CRCONTADOR','CR',$v['crcontador']]] as [$tabela,$chave,$tipo,$id]){
            $s=$pdo->prepare("SELECT STATUS,DTPAGTO,VLRPAGO,VLRRESTANTE,VLRPARCELA,TIPODOCORIGEM,CONTROLE,excluido_firebird FROM $tabela WHERE EMPRESA=? AND $chave=? FOR UPDATE");
            $s->execute([$empresa,$id]);$titulo=$s->fetch(PDO::FETCH_ASSOC);
            if(!$titulo || $titulo['STATUS']!=='AB' || $titulo['DTPAGTO'] || (float)$titulo['VLRPAGO']>0 || abs((float)$titulo['VLRRESTANTE']-(float)$titulo['VLRPARCELA'])>0.009 || $titulo['TIPODOCORIGEM']!=='SUPERDUNGA' || $titulo['CONTROLE']!=='MOVIMENTACAO_BAIXA' || $titulo['excluido_firebird']==='S')throw new RuntimeException('O par possui titulo alterado, quitado ou excluido. Desfaca as baixas antes da exclusao.');
            $s=$pdo->prepare("SELECT COUNT(*) FROM armazem_bnc001 WHERE EMPRESA=? AND TIPODOCORIGEM=? AND NUMDOCORIGEM=? AND COALESCE(deletado,'N')<>'S'");
            $s->execute([$empresa,$tipo.'001',(string)$id]);if((int)$s->fetchColumn()>0)throw new RuntimeException('O par possui movimento ativo no BNC001. Desfaca a baixa e a conciliacao bancaria antes de excluir.');
            if(mbaAcertoFechadoPorTitulo($pdo,$empresa,$tipo,(int)$id)!==null)throw new RuntimeException('O par esta vinculado a acerto Cliente x Fornecedor.');
        }
        $s=$pdo->prepare("SELECT COUNT(*) FROM financeiro_cc_itens WHERE empresa_id=? AND cpcontador=?");$s->execute([$empresa,$v['cpcontador']]);if((int)$s->fetchColumn()>0)throw new RuntimeException('O CP esta vinculado a uma fatura de cartao.');
        foreach([['armazem_cp001','CPCONTADOR',$v['cpcontador']],['armazem_cr001','CRCONTADOR',$v['crcontador']]] as [$tabela,$chave,$id]){
            $s=$pdo->prepare("UPDATE $tabela SET excluido_firebird='S',data_exclusao_firebird=NOW(),motivo_sync='EXCLUIDO_PAR_MOVIMENTACAO_BAIXA',USERALT=?,DTALT=NOW(),REGSTAMP=NOW() WHERE EMPRESA=? AND $chave=? AND COALESCE(excluido_firebird,'N')<>'S'");
            $s->execute([$usuario?:null,$empresa,$id]);if($s->rowCount()!==1)throw new RuntimeException('Nao foi possivel excluir os dois titulos.');
        }
        $pdo->prepare("UPDATE movimentacao_baixa_cp_cr_vinculos SET status='EXCLUIDO',excluido_por=?,excluido_em=NOW() WHERE empresa_id=? AND cpcontador=? AND status='ATIVO'")->execute([$usuario?:null,$empresa,$v['cpcontador']]);
        $pdo->commit();return [(int)$v['cpcontador'],(int)$v['crcontador']];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
