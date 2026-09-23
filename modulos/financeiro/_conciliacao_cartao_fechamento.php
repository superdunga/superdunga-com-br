<?php

function cccGarantirFechamentos(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_fechamentos (
        fatura_id INT NOT NULL PRIMARY KEY,
        empresa_id INT NOT NULL,
        data_pagamento DATE NOT NULL,
        conta_pagamento INT NOT NULL,
        total_cps DECIMAL(15,2) NOT NULL,
        total_pagamento DECIMAL(15,2) NOT NULL,
        total_ajuste DECIMAL(15,2) NOT NULL,
        mov_credito_39 INT NOT NULL,
        mov_debito_pagamento INT NOT NULL,
        mov_ajuste_39 INT NULL,
        tipoes_ajuste INT NULL,
        usuario_id INT NULL,
        pago_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ajustado_em DATETIME NULL,
        INDEX idx_cc_fechamento_empresa (empresa_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_baixas (
        fatura_id INT NOT NULL,
        empresa_id INT NOT NULL,
        cpcontador INT NOT NULL,
        movcontador INT NOT NULL,
        valor DECIMAL(15,2) NOT NULL,
        PRIMARY KEY (fatura_id,cpcontador),
        UNIQUE KEY uq_cc_baixa_mov (movcontador)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function cccContaAtiva(PDO $pdo, int $empresa, int $conta): bool
{
    $s=$pdo->prepare("SELECT 1 FROM armazem_bnc002 WHERE EMPRESA=? AND CBCONTADOR=? AND COALESCE(CONTABLOQUEADA,'N')<>'S' AND COALESCE(excluido_firebird,'N')<>'S'");
    $s->execute([$empresa,$conta]);
    return (bool)$s->fetchColumn();
}

function cccContaCartao(PDO $pdo, int $empresa): bool
{
    $s=$pdo->prepare("SELECT DESCABREV FROM armazem_bnc002 WHERE EMPRESA=? AND CBCONTADOR=39 AND COALESCE(CONTABLOQUEADA,'N')<>'S' AND COALESCE(excluido_firebird,'N')<>'S'");
    $s->execute([$empresa]);
    return strpos(strtoupper((string)$s->fetchColumn()),'CART')!==false;
}

function cccInserirMovimentoFatura(PDO $pdo,int $empresa,int $conta,int $tipoes,string $mov,string $data,string $historico,int $faturaId,int $usuario,int $centavos,string $origem,int $documentoOrigem=0,int $fornecedor=0): int
{
    if($centavos<=0)throw new RuntimeException('Valor de movimento invalido.');
    $s=$pdo->prepare("INSERT INTO armazem_bnc001 (EMPRESA,MOVCONTADOR,DTMOV,NUMDOC,TIPOMOV,CBCONTADOR,TIPOES,FCONTADOR,HISTMOV,VALORMOV,TIPODOCORIGEM,NUMDOCORIGEM,REGSTAMP,USERBNCLANC,CONTRAPARTIDA,ORIGEMCPART,DTLANC,DTPROCESSADO,deletado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'N',0,NOW(),NOW(),'N')");
    for($tentativa=0;$tentativa<3;$tentativa++){
        $id=proximoMovcontadorCartao($pdo);
        try{
            $s->execute([$empresa,$id,$data,'FATURA-'.$faturaId,$mov,$conta,$tipoes,$fornecedor?:null,mb_substr($historico,0,255),$centavos/100,$origem,$documentoOrigem?:$faturaId,date('Y-m-d H:i:s'),$usuario?:null]);
            return $id;
        }catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1062 || $tentativa===2)throw $e;}
    }
    throw new RuntimeException('Nao foi possivel reservar o numero do movimento.');
}

function cccBaixarCpsFatura(PDO $pdo,int $empresa,int $faturaId,int $usuario,string $data): void
{
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$data) || date('Y-m-d',strtotime($data))!==$data)throw new RuntimeException('Informe uma data de baixa valida.');
    if(!cccContaCartao($pdo,$empresa))throw new RuntimeException('A conta 39 de cartoes nao esta ativa para esta empresa.');
    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare("SELECT * FROM financeiro_cc_faturas WHERE id=? AND empresa_id=? FOR UPDATE");$s->execute([$faturaId,$empresa]);$f=$s->fetch(PDO::FETCH_ASSOC);
        if(!$f || $f['status']!=='IMPORTADA')throw new RuntimeException('Fatura nao encontrada ou CPs ja baixados.');
        $s=$pdo->prepare("SELECT 1 FROM financeiro_cc_fechamentos WHERE fatura_id=?");$s->execute([$faturaId]);if($s->fetchColumn())throw new RuntimeException('Esta fatura ja possui pagamento registrado.');
        $s=$pdo->prepare("SELECT 1 FROM financeiro_cc_baixas WHERE fatura_id=? LIMIT 1");$s->execute([$faturaId]);if($s->fetchColumn())throw new RuntimeException('Esta fatura ja possui CPs baixados.');
        $s=$pdo->prepare("SELECT i.id,i.valor,i.cpcontador,cp.STATUS,cp.DTPAGTO,cp.VLRPAGO,cp.VLRRESTANTE,cp.VLRPARCELA,cp.TIPOES,cp.FCONTADOR,cp.excluido_firebird,t.TIPOMOV,t.CONTRAP_TIPOES,t.CONTRAP_TIPOMOV,t.CONTRAP_CBCONTADOR,t.REGDISAB,t.excluido_firebird AS tipo_excluido,xt.TIPOMOV AS investimento_tipomov,xt.REGDISAB AS investimento_desabilitado,xt.excluido_firebird AS investimento_excluido,v.crcontador,cr.STATUS AS cr_status,cr.DTPAGTO AS cr_pagto,cr.VLRPAGO AS cr_pago,cr.VLRRESTANTE AS cr_restante,cr.TIPOES AS cr_tipoes,cr.excluido_firebird AS cr_excluido,ct.TIPOMOV AS cr_tipomov,ct.CONTRAP_TIPOES AS cr_contrap,ct.REGDISAB AS cr_tipo_desabilitado,ct.excluido_firebird AS cr_tipo_excluido FROM financeiro_cc_itens i LEFT JOIN armazem_cp001 cp ON cp.EMPRESA=i.empresa_id AND cp.CPCONTADOR=i.cpcontador LEFT JOIN armazem_bnc005 t ON t.EMPRESA=cp.EMPRESA AND t.ESCONTADOR=cp.TIPOES LEFT JOIN armazem_bnc005 xt ON xt.EMPRESA=t.EMPRESA AND xt.ESCONTADOR=t.CONTRAP_TIPOES LEFT JOIN movimentacao_baixa_cp_cr_vinculos v ON v.empresa_id=i.empresa_id AND v.cpcontador=i.cpcontador AND v.status='ATIVO' LEFT JOIN armazem_cr001 cr ON cr.EMPRESA=v.empresa_id AND cr.CRCONTADOR=v.crcontador LEFT JOIN armazem_bnc005 ct ON ct.EMPRESA=cr.EMPRESA AND ct.ESCONTADOR=cr.TIPOES WHERE i.fatura_id=? AND i.empresa_id=? AND i.natureza='D' AND i.status<>'ESTORNADO' ORDER BY i.id FOR UPDATE");
        $s->execute([$faturaId,$empresa]);$itens=$s->fetchAll(PDO::FETCH_ASSOC);
        if(!$itens)throw new RuntimeException('A fatura nao possui compras para baixar.');
        $s=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN natureza='C' AND status<>'ESTORNADO' THEN valor ELSE 0 END),0) AS devolucoes, COALESCE(SUM(CASE WHEN natureza='P' THEN valor ELSE 0 END),0) AS pagamentos, COALESCE(SUM(CASE WHEN natureza='D' AND status='ESTORNADO' THEN valor ELSE 0 END),0) AS estornos_debito, COALESCE(SUM(CASE WHEN natureza='C' AND status='ESTORNADO' THEN valor ELSE 0 END),0) AS estornos_credito FROM financeiro_cc_itens WHERE fatura_id=? AND empresa_id=?");
        $s->execute([$faturaId,$empresa]);$creditos=$s->fetch(PDO::FETCH_ASSOC);
        $estornado=(int)round((float)$creditos['estornos_debito']*100);
        if($estornado!==(int)round((float)$creditos['estornos_credito']*100) || (int)round((float)$creditos['devolucoes']*100)+$estornado!==(int)round((float)$f['total_creditos']*100))throw new RuntimeException('Os estornos e creditos da fatura nao fecham. Confira a composicao antes de encerrar.');
        $total=0;$baixas=[];
        foreach($itens as $i){
            $valor=(int)round((float)$i['valor']*100);$restante=(int)round((float)$i['VLRRESTANTE']*100);
            if(!$i['cpcontador'] || $i['STATUS']!=='AB' || $i['DTPAGTO'] || (float)$i['VLRPAGO']>0 || ($i['excluido_firebird']??'N')==='S' || $restante!==$valor)throw new RuntimeException('O item #'.$i['id'].' nao possui CP aberto pelo valor integral.');
            if($i['TIPOMOV']!=='D' || (int)$i['TIPOES']<=0 || ($i['REGDISAB']??'N')==='S' || ($i['tipo_excluido']??'N')==='S')throw new RuntimeException('O TIPOES do CP #'.$i['cpcontador'].' nao permite baixa na conta 39.');
            $modo='comum';
            if($i['crcontador']){
                if((int)($i['CONTRAP_TIPOES']??0)<=0 || $i['cr_status']!=='AB' || $i['cr_pagto'] || (float)$i['cr_pago']>0 || (int)round((float)$i['cr_restante']*100)!==$valor || ($i['cr_excluido']??'N')==='S' || $i['cr_tipomov']!=='C' || (int)$i['cr_tipoes']!==(int)$i['CONTRAP_TIPOES'] || (int)$i['cr_contrap']!==(int)$i['TIPOES'] || ($i['cr_tipo_desabilitado']??'N')==='S' || ($i['cr_tipo_excluido']??'N')==='S')throw new RuntimeException('O par CP/CR do CP #'.$i['cpcontador'].' nao esta integro e em aberto.');
                $modo='par_cp_cr';
            }elseif((int)($i['CONTRAP_TIPOES']??0)>0){
                $contaInvestimento=(int)($i['CONTRAP_CBCONTADOR']??0);
                $movInvestimento=strtoupper(trim((string)($i['CONTRAP_TIPOMOV']?:'C')));
                if($contaInvestimento<=0 || $contaInvestimento===39 || !cccContaAtiva($pdo,$empresa,$contaInvestimento) || !in_array($movInvestimento,['C','D'],true) || $i['investimento_tipomov']!==$movInvestimento || ($i['investimento_desabilitado']??'N')==='S' || ($i['investimento_excluido']??'N')==='S')throw new RuntimeException('O CP #'.$i['cpcontador'].' exige conta e TIPOES de investimento/contrapartida validos.');
                $modo='investimento';
            }
            $i['modo_baixa']=$modo;
            $total+=$valor;$baixas[]=[$i,$valor];
        }
        $credito=(int)round((float)$creditos['devolucoes']*100);$pagamento=(int)round((float)$f['total_fatura']*100);
        if($pagamento<=0 || $total-$credito!==$pagamento || $total+$estornado!==(int)round((float)$f['total_debitos']*100))throw new RuntimeException('A soma dos CPs e estornos nao fecha com o total liquido da fatura. Confira creditos e itens antes de baixar.');
        $historico='FATURA '.substr($f['competencia'],5,2).'/'.substr($f['competencia'],0,4);
        $up=$pdo->prepare("UPDATE armazem_cp001 SET STATUS='QT',DTPAGTO=?,VLRPAGO=?,VLRRESTANTE=0,CBCONTADOR=39,USERALT=?,DTALT=NOW(),REGSTAMP=NOW() WHERE EMPRESA=? AND CPCONTADOR=? AND STATUS<>'QT' AND DTPAGTO IS NULL AND COALESCE(VLRPAGO,0)=0");
        $log=$pdo->prepare("INSERT INTO financeiro_cc_baixas(fatura_id,empresa_id,cpcontador,movcontador,valor) VALUES(?,?,?,?,?)");
        foreach($baixas as [$i,$valor]){
            $descricaoBaixa='BAIXA CP '.$i['cpcontador'].' - '.$historico;
            $mov=cccInserirMovimentoFatura($pdo,$empresa,39,(int)$i['TIPOES'],'D',$data,$descricaoBaixa,$faturaId,$usuario,$valor,'CP001',(int)$i['cpcontador'],(int)$i['FCONTADOR']);
            if($i['modo_baixa']==='investimento'){
                $contrap=cccInserirMovimentoFatura($pdo,$empresa,(int)$i['CONTRAP_CBCONTADOR'],(int)$i['CONTRAP_TIPOES'],strtoupper(trim((string)($i['CONTRAP_TIPOMOV']?:'C'))),$data,'CONTRAPARTIDA - '.$descricaoBaixa,$faturaId,$usuario,$valor,'CP001',(int)$i['cpcontador'],(int)$i['FCONTADOR']);
                $pdo->prepare("UPDATE armazem_bnc001 SET CONTRAPARTIDA='S' WHERE EMPRESA=? AND MOVCONTADOR=?")->execute([$empresa,$mov]);
                $pdo->prepare("UPDATE armazem_bnc001 SET ORIGEMCPART=? WHERE EMPRESA=? AND MOVCONTADOR=?")->execute([$mov,$empresa,$contrap]);
            }
            $up->execute([$data,$valor/100,$usuario?:null,$empresa,(int)$i['cpcontador']]);if($up->rowCount()!==1)throw new RuntimeException('CP #'.$i['cpcontador'].' foi alterado durante o fechamento.');
            $log->execute([$faturaId,$empresa,(int)$i['cpcontador'],$mov,$valor/100]);
        }
        $pdo->prepare("UPDATE financeiro_cc_faturas SET status='ENCERRADA' WHERE id=? AND empresa_id=? AND status='IMPORTADA'")->execute([$faturaId,$empresa]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function cccAjustarCreditoFatura(PDO $pdo,int $empresa,int $faturaId,int $usuario,int $tipoes): void
{
    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare("SELECT f.status,f.competencia,x.total_ajuste,x.data_pagamento,x.mov_ajuste_39 FROM financeiro_cc_faturas f JOIN financeiro_cc_fechamentos x ON x.fatura_id=f.id AND x.empresa_id=f.empresa_id WHERE f.id=? AND f.empresa_id=? FOR UPDATE");
        $s->execute([$faturaId,$empresa]);$f=$s->fetch(PDO::FETCH_ASSOC);
        if(!$f || $f['status']!=='AJUSTE_PENDENTE' || $f['mov_ajuste_39'])throw new RuntimeException('Esta fatura nao possui ajuste de credito pendente.');
        $s=$pdo->prepare("SELECT TIPOMOV,CONTRAP_TIPOES FROM armazem_bnc005 WHERE EMPRESA=? AND ESCONTADOR=? AND COALESCE(REGDISAB,'N')<>'S' AND COALESCE(excluido_firebird,'N')<>'S'");$s->execute([$empresa,$tipoes]);$t=$s->fetch(PDO::FETCH_ASSOC);
        if(!$t || $t['TIPOMOV']!=='C' || (int)($t['CONTRAP_TIPOES']??0)>0)throw new RuntimeException('Selecione um TIPOES de credito sem contrapartida automatica para a devolucao.');
        $valor=(int)round((float)$f['total_ajuste']*100);
        $mov=cccInserirMovimentoFatura($pdo,$empresa,39,$tipoes,'C',$f['data_pagamento'],'DEVOLUCAO FATURA '.substr($f['competencia'],5,2).'/'.substr($f['competencia'],0,4),$faturaId,$usuario,$valor,'FATURA_CC_AJUSTE');
        $pdo->prepare("UPDATE financeiro_cc_fechamentos SET mov_ajuste_39=?,tipoes_ajuste=?,ajustado_em=NOW() WHERE fatura_id=?")->execute([$mov,$tipoes,$faturaId]);
        $pdo->prepare("UPDATE financeiro_cc_faturas SET status='ENCERRADA' WHERE id=? AND empresa_id=?")->execute([$faturaId,$empresa]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
