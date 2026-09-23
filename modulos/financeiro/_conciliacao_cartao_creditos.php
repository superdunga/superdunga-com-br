<?php

function cccGarantirCreditos(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_creditos_esperados (
        id INT AUTO_INCREMENT PRIMARY KEY, empresa_id INT NOT NULL, cartao_id INT NOT NULL,
        debito_id INT NOT NULL, valor DECIMAL(15,2) NOT NULL,
        usuario_id INT NULL, criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cc_credito_debito (debito_id),
        INDEX idx_cc_credito_pendente (empresa_id,cartao_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_creditos_aplicados (
        id INT AUTO_INCREMENT PRIMARY KEY, esperado_id INT NOT NULL, credito_id INT NOT NULL,
        empresa_id INT NOT NULL, valor DECIMAL(15,2) NOT NULL,
        tipoes_credito INT NOT NULL, tipoes_reversao INT NULL,
        mov_credito_39 INT NOT NULL, mov_reversao INT NULL,
        usuario_id INT NULL, aplicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cc_credito_aplicado (credito_id),
        UNIQUE KEY uq_cc_credito_mov (mov_credito_39),
        INDEX idx_cc_credito_esperado (esperado_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_creditos_avulsos (
        credito_id INT NOT NULL PRIMARY KEY, empresa_id INT NOT NULL,
        valor DECIMAL(15,2) NOT NULL, tipoes_credito INT NOT NULL,
        mov_credito_39 INT NOT NULL, usuario_id INT NULL,
        aplicado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cc_avulso_mov (mov_credito_39)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_creditos_correcoes (
        id INT AUTO_INCREMENT PRIMARY KEY, empresa_id INT NOT NULL,
        credito_id INT NOT NULL, classificacao VARCHAR(20) NOT NULL,
        referencia_id INT NULL, mov_credito_39 INT NOT NULL, mov_reversao INT NULL,
        usuario_id INT NULL, corrigido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cc_correcao_credito (empresa_id,credito_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_match_correcoes (
        id INT AUTO_INCREMENT PRIMARY KEY, empresa_id INT NOT NULL,
        fatura_id INT NOT NULL, item_id INT NOT NULL, cpcontador INT NOT NULL,
        cp_cancelado CHAR(1) NOT NULL, usuario_id INT NULL,
        corrigido_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cc_match_correcao (empresa_id,fatura_id,item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function cccDesfazerClassificacaoCredito(PDO $pdo,int $empresa,int $faturaId,int $creditoId,int $usuario): void
{
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare("SELECT f.status FROM financeiro_cc_itens i JOIN financeiro_cc_faturas f ON f.id=i.fatura_id AND f.empresa_id=i.empresa_id WHERE i.id=? AND i.fatura_id=? AND i.empresa_id=? AND i.natureza='C' FOR UPDATE");
        $s->execute([$creditoId,$faturaId,$empresa]);if($s->fetchColumn()!=='IMPORTADA')throw new RuntimeException('Somente creditos de faturas abertas podem ser reclassificados.');
        $s=$pdo->prepare("SELECT id,esperado_id,mov_credito_39,mov_reversao FROM financeiro_cc_creditos_aplicados WHERE credito_id=? AND empresa_id=? FOR UPDATE");$s->execute([$creditoId,$empresa]);$aplicado=$s->fetch(PDO::FETCH_ASSOC);
        $s=$pdo->prepare("SELECT credito_id,mov_credito_39 FROM financeiro_cc_creditos_avulsos WHERE credito_id=? AND empresa_id=? FOR UPDATE");$s->execute([$creditoId,$empresa]);$avulso=$s->fetch(PDO::FETCH_ASSOC);
        if((bool)$aplicado===(bool)$avulso)throw new RuntimeException('Classificacao do credito ausente ou inconsistente.');
        $movimentos=array_filter([(int)($aplicado['mov_credito_39']??$avulso['mov_credito_39']),(int)($aplicado['mov_reversao']??0)]);
        foreach($movimentos as $mov){
            $s=$pdo->prepare("SELECT 1 FROM financeiro_extrato_bancario WHERE bnc001_movcontador=? LIMIT 1");$s->execute([$mov]);
            if($s->fetchColumn())throw new RuntimeException('O movimento #'.$mov.' ja foi conciliado com extrato. Desfaca essa conciliacao antes.');
            $s=$pdo->prepare("SELECT 1 FROM armazem_bnc001 WHERE EMPRESA=? AND MOVCONTADOR=? AND COALESCE(deletado,'N')<>'S' FOR UPDATE");$s->execute([$empresa,$mov]);
            if(!$s->fetchColumn())throw new RuntimeException('Movimento #'.$mov.' nao esta ativo.');
        }
        $pdo->prepare("INSERT INTO financeiro_cc_creditos_correcoes (empresa_id,credito_id,classificacao,referencia_id,mov_credito_39,mov_reversao,usuario_id) VALUES (?,?,?,?,?,?,?)")->execute([$empresa,$creditoId,$aplicado?'ESTORNO':'AVULSO',$aplicado?(int)$aplicado['esperado_id']:null,(int)($aplicado['mov_credito_39']??$avulso['mov_credito_39']),$aplicado['mov_reversao']??null,$usuario?:null]);
        $up=$pdo->prepare("UPDATE armazem_bnc001 SET deletado='S',motivo_sync='CORRECAO_CREDITO_FATURA',REGSTAMP=NOW() WHERE EMPRESA=? AND MOVCONTADOR=? AND COALESCE(deletado,'N')<>'S'");
        foreach($movimentos as $mov){$up->execute([$empresa,$mov]);if($up->rowCount()!==1)throw new RuntimeException('Falha ao cancelar movimento #'.$mov.'.');}
        if($aplicado)$pdo->prepare("DELETE FROM financeiro_cc_creditos_aplicados WHERE id=? AND empresa_id=?")->execute([(int)$aplicado['id'],$empresa]);
        else $pdo->prepare("DELETE FROM financeiro_cc_creditos_avulsos WHERE credito_id=? AND empresa_id=?")->execute([$creditoId,$empresa]);
        $pdo->commit();
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function cccValidarTipoCredito(PDO $pdo,int $empresa,int $tipoes): void
{
    $s=$pdo->prepare("SELECT TIPOMOV,CONTRAP_TIPOES FROM armazem_bnc005 WHERE EMPRESA=? AND ESCONTADOR=? AND COALESCE(REGDISAB,'N')<>'S' AND COALESCE(excluido_firebird,'N')<>'S'");
    $s->execute([$empresa,$tipoes]);$tipo=$s->fetch(PDO::FETCH_ASSOC);
    if(!$tipo || $tipo['TIPOMOV']!=='C' || (int)($tipo['CONTRAP_TIPOES']??0)>0)throw new RuntimeException('Selecione um TIPOES de credito sem contrapartida automatica.');
}

function cccAplicarCreditoAvulso(PDO $pdo,int $empresa,int $faturaId,int $creditoId,int $tipoes,int $usuario): void
{
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare("SELECT i.valor,i.data_compra,i.descricao,i.status AS item_status,i.natureza,f.status AS fatura_status FROM financeiro_cc_itens i JOIN financeiro_cc_faturas f ON f.id=i.fatura_id AND f.empresa_id=i.empresa_id WHERE i.id=? AND i.fatura_id=? AND i.empresa_id=? FOR UPDATE");
        $s->execute([$creditoId,$faturaId,$empresa]);$i=$s->fetch(PDO::FETCH_ASSOC);
        if(!$i || $i['natureza']!=='C' || $i['item_status']==='ESTORNADO' || $i['fatura_status']!=='IMPORTADA')throw new RuntimeException('Selecione um credito de fatura em andamento.');
        $s=$pdo->prepare("SELECT 1 FROM financeiro_cc_creditos_aplicados WHERE credito_id=?");$s->execute([$creditoId]);if($s->fetchColumn())throw new RuntimeException('Credito ja vinculado a uma compra.');
        $s=$pdo->prepare("SELECT 1 FROM financeiro_cc_creditos_avulsos WHERE credito_id=?");$s->execute([$creditoId]);if($s->fetchColumn())throw new RuntimeException('Credito avulso ja contabilizado.');
        if(!cccContaCartao($pdo,$empresa))throw new RuntimeException('Conta 39 de cartoes indisponivel.');
        cccValidarTipoCredito($pdo,$empresa,$tipoes);
        $valor=(int)round((float)$i['valor']*100);
        $mov=cccInserirMovimentoFatura($pdo,$empresa,39,$tipoes,'C',$i['data_compra'],'CREDITO AVULSO FATURA - '.mb_substr($i['descricao'],0,170),$faturaId,$usuario,$valor,'CREDITO_CC_AVULSO',$creditoId);
        $pdo->prepare("INSERT INTO financeiro_cc_creditos_avulsos (credito_id,empresa_id,valor,tipoes_credito,mov_credito_39,usuario_id) VALUES (?,?,?,?,?,?)")->execute([$creditoId,$empresa,$valor/100,$tipoes,$mov,$usuario?:null]);
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function cccMarcarCreditoEsperado(PDO $pdo, int $empresa, int $faturaId, int $debitoId, int $usuario): void
{
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare("SELECT f.cartao_id,f.status,i.natureza,i.status AS item_status,i.valor,i.cpcontador FROM financeiro_cc_faturas f JOIN financeiro_cc_itens i ON i.fatura_id=f.id AND i.empresa_id=f.empresa_id WHERE f.id=? AND f.empresa_id=? AND i.id=? FOR UPDATE");
        $s->execute([$faturaId,$empresa,$debitoId]);$i=$s->fetch(PDO::FETCH_ASSOC);
        if(!$i || !in_array($i['status'],['IMPORTADA','ENCERRADA'],true) || $i['natureza']!=='D' || $i['item_status']==='ESTORNADO' || !$i['cpcontador'])throw new RuntimeException('Selecione uma compra conciliada com CP nesta fatura.');
        $s=$pdo->prepare("SELECT 1 FROM financeiro_cc_estornos WHERE debito_id=?");$s->execute([$debitoId]);if($s->fetchColumn())throw new RuntimeException('Esta compra ja foi estornada na propria fatura.');
        $s=$pdo->prepare("INSERT INTO financeiro_cc_creditos_esperados (empresa_id,cartao_id,debito_id,valor,usuario_id) VALUES (?,?,?,?,?)");
        $s->execute([$empresa,(int)$i['cartao_id'],$debitoId,$i['valor'],$usuario?:null]);
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function cccCancelarCreditoEsperado(PDO $pdo,int $empresa,int $faturaId,int $esperadoId): void
{
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare("SELECT e.id FROM financeiro_cc_creditos_esperados e JOIN financeiro_cc_itens d ON d.id=e.debito_id AND d.empresa_id=e.empresa_id WHERE e.id=? AND e.empresa_id=? AND d.fatura_id=? FOR UPDATE");
        $s->execute([$esperadoId,$empresa,$faturaId]);if(!$s->fetchColumn())throw new RuntimeException('Credito esperado nao encontrado.');
        $s=$pdo->prepare("SELECT 1 FROM financeiro_cc_creditos_aplicados WHERE esperado_id=? LIMIT 1");$s->execute([$esperadoId]);if($s->fetchColumn())throw new RuntimeException('Nao e possivel cancelar um credito ja aplicado.');
        $pdo->prepare("DELETE FROM financeiro_cc_creditos_esperados WHERE id=? AND empresa_id=?")->execute([$esperadoId,$empresa]);
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function cccAplicarCreditoPosterior(PDO $pdo,int $empresa,int $faturaId,int $esperadoId,int $creditoId,int $tipoesCredito,int $tipoesReversao,int $usuario): void
{
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare("SELECT e.*,d.fatura_id AS fatura_origem,d.cpcontador,d.descricao,d.valor AS valor_debito,fo.competencia AS competencia_origem,fo.status AS status_origem,fc.id AS fatura_credito,fc.competencia AS competencia_credito,fc.status AS status_credito,c.natureza,c.status AS item_status,c.valor AS valor_credito,c.data_compra FROM financeiro_cc_creditos_esperados e JOIN financeiro_cc_itens d ON d.id=e.debito_id AND d.empresa_id=e.empresa_id JOIN financeiro_cc_faturas fo ON fo.id=d.fatura_id AND fo.cartao_id=e.cartao_id JOIN financeiro_cc_faturas fc ON fc.id=? AND fc.empresa_id=e.empresa_id AND fc.cartao_id=e.cartao_id JOIN financeiro_cc_itens c ON c.id=? AND c.fatura_id=fc.id AND c.empresa_id=e.empresa_id WHERE e.id=? AND e.empresa_id=? FOR UPDATE");
        $s->execute([$faturaId,$creditoId,$esperadoId,$empresa]);$x=$s->fetch(PDO::FETCH_ASSOC);
        if(!$x || $x['status_origem']!=='ENCERRADA' || $x['status_credito']!=='IMPORTADA' || $x['competencia_credito']<=$x['competencia_origem'] || $x['natureza']!=='C' || $x['item_status']==='ESTORNADO' || !$x['cpcontador'])throw new RuntimeException('Credito e compra devem ser do mesmo cartao, em faturas diferentes, com a compra ja baixada.');
        $s=$pdo->prepare("SELECT 1 FROM financeiro_cc_creditos_aplicados WHERE credito_id=?");$s->execute([$creditoId]);if($s->fetchColumn())throw new RuntimeException('Este credito ja foi aplicado.');
        $s=$pdo->prepare("SELECT 1 FROM financeiro_cc_creditos_avulsos WHERE credito_id=?");$s->execute([$creditoId]);if($s->fetchColumn())throw new RuntimeException('Este credito ja foi classificado como avulso.');
        $s=$pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM financeiro_cc_creditos_aplicados WHERE esperado_id=?");$s->execute([$esperadoId]);$aplicado=(int)round((float)$s->fetchColumn()*100);
        $valor=(int)round((float)$x['valor_credito']*100);$restante=(int)round((float)$x['valor']*100)-$aplicado;
        if($valor<=0 || $restante<$valor)throw new RuntimeException('O credito ultrapassa o saldo esperado. Confira a compra e os creditos ja aplicados.');
        cccValidarTipoCredito($pdo,$empresa,$tipoesCredito);
        $s=$pdo->prepare("SELECT t.CONTRAP_CBCONTADOR,t.CONTRAP_TIPOES,t.CONTRAP_TIPOMOV,xt.TIPOMOV,cp.FCONTADOR FROM armazem_cp001 cp JOIN armazem_bnc005 t ON t.EMPRESA=cp.EMPRESA AND t.ESCONTADOR=cp.TIPOES LEFT JOIN armazem_bnc005 xt ON xt.EMPRESA=t.EMPRESA AND xt.ESCONTADOR=t.CONTRAP_TIPOES WHERE cp.EMPRESA=? AND cp.CPCONTADOR=?");$s->execute([$empresa,(int)$x['cpcontador']]);$cp=$s->fetch(PDO::FETCH_ASSOC);
        if(!$cp)throw new RuntimeException('CP original nao encontrado.');
        $investimento=(int)($cp['CONTRAP_TIPOES']??0)>0 && (int)($cp['CONTRAP_CBCONTADOR']??0)>0;
        if($investimento){
            $s=$pdo->prepare("SELECT movcontador FROM financeiro_cc_baixas WHERE fatura_id=? AND empresa_id=? AND cpcontador=?");$s->execute([(int)$x['fatura_origem'],$empresa,(int)$x['cpcontador']]);$movOrigem=(int)$s->fetchColumn();
            $s=$pdo->prepare("SELECT MOVCONTADOR FROM armazem_bnc001 WHERE EMPRESA=? AND ORIGEMCPART=? AND CBCONTADOR=? AND TIPOMOV='C' AND TIPODOCORIGEM='CP001' AND NUMDOCORIGEM=? LIMIT 1");$s->execute([$empresa,$movOrigem,(int)$cp['CONTRAP_CBCONTADOR'],(int)$x['cpcontador']]);$movInvestimento=(int)$s->fetchColumn();
            if(!$movInvestimento || !cccContaAtiva($pdo,$empresa,(int)$cp['CONTRAP_CBCONTADOR']))throw new RuntimeException('Contrapartida original do investimento nao localizada.');
            $s=$pdo->prepare("SELECT TIPOMOV,CONTRAP_TIPOES FROM armazem_bnc005 WHERE EMPRESA=? AND ESCONTADOR=? AND COALESCE(REGDISAB,'N')<>'S' AND COALESCE(excluido_firebird,'N')<>'S'");$s->execute([$empresa,$tipoesReversao]);$reverso=$s->fetch(PDO::FETCH_ASSOC);
            if(!$reverso || $reverso['TIPOMOV']!=='D' || (int)($reverso['CONTRAP_TIPOES']??0)>0)throw new RuntimeException('Informe um TIPOES de debito sem contrapartida automatica para reverter o investimento.');
        } elseif($tipoesReversao>0)throw new RuntimeException('TIPOES de reversao so se aplica a investimento.');
        $historico='DEVOLUCAO CARTAO - CP '.$x['cpcontador'].' - '.mb_substr($x['descricao'],0,140);
        $mov=cccInserirMovimentoFatura($pdo,$empresa,39,$tipoesCredito,'C',$x['data_compra'],$historico,$faturaId,$usuario,$valor,'CREDITO_CC',$creditoId,(int)$cp['FCONTADOR']);
        $reversao=null;
        if($investimento){
            $reversao=cccInserirMovimentoFatura($pdo,$empresa,(int)$cp['CONTRAP_CBCONTADOR'],$tipoesReversao,'D',$x['data_compra'],'REVERSAO INVESTIMENTO - '.$historico,$faturaId,$usuario,$valor,'CREDITO_CC',$creditoId,(int)$cp['FCONTADOR']);
            $pdo->prepare("UPDATE armazem_bnc001 SET CONTRAPARTIDA='S' WHERE EMPRESA=? AND MOVCONTADOR=?")->execute([$empresa,$mov]);
            $pdo->prepare("UPDATE armazem_bnc001 SET ORIGEMCPART=? WHERE EMPRESA=? AND MOVCONTADOR=?")->execute([$mov,$empresa,$reversao]);
        }
        $pdo->prepare("INSERT INTO financeiro_cc_creditos_aplicados (esperado_id,credito_id,empresa_id,valor,tipoes_credito,tipoes_reversao,mov_credito_39,mov_reversao,usuario_id) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$esperadoId,$creditoId,$empresa,$valor/100,$tipoesCredito,$investimento?$tipoesReversao:null,$mov,$reversao,$usuario?:null]);
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
