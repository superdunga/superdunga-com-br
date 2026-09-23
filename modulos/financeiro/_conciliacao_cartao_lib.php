<?php
require_once __DIR__ . '/_cartao_credito_lib.php';

function cccGarantirTabelas(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_cartoes (id INT AUTO_INCREMENT PRIMARY KEY,empresa_id INT NOT NULL,nome VARCHAR(120) NOT NULL,nome_norm VARCHAR(120) NOT NULL,fornecedor_fcontador INT NOT NULL,dia_vencimento TINYINT UNSIGNED NOT NULL,ativo CHAR(1) NOT NULL DEFAULT 'S',usuario_id INT NULL,criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_cc_cartao (empresa_id,nome_norm)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_faturas (id INT AUTO_INCREMENT PRIMARY KEY,empresa_id INT NOT NULL,cartao_id INT NOT NULL,competencia CHAR(7) NOT NULL,vencimento DATE NOT NULL,nome_arquivo VARCHAR(255),hash_arquivo CHAR(64) NOT NULL,total_debitos DECIMAL(15,2) NOT NULL,total_creditos DECIMAL(15,2) NOT NULL,total_fatura DECIMAL(15,2) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'IMPORTADA',usuario_id INT NULL,importado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_cc_hash (empresa_id,hash_arquivo),UNIQUE KEY uq_cc_comp (empresa_id,cartao_id,competencia)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_itens (id INT AUTO_INCREMENT PRIMARY KEY,fatura_id INT NOT NULL,empresa_id INT NOT NULL,data_compra DATE NOT NULL,descricao VARCHAR(255) NOT NULL,categoria VARCHAR(120),tipo_lancamento VARCHAR(120),valor DECIMAL(15,2) NOT NULL,natureza CHAR(1) NOT NULL,hash_item CHAR(64) NOT NULL,cpcontador INT NULL,status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',usuario_id INT NULL,conciliado_em DATETIME NULL,UNIQUE KEY uq_cc_item (fatura_id,hash_item),UNIQUE KEY uq_cc_cp (empresa_id,cpcontador),INDEX idx_cc_status (fatura_id,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_estornos (fatura_id INT NOT NULL,empresa_id INT NOT NULL,debito_id INT NOT NULL,credito_id INT NOT NULL,valor DECIMAL(15,2) NOT NULL,usuario_id INT NULL,criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (debito_id),UNIQUE KEY uq_cc_estorno_credito (credito_id),INDEX idx_cc_estorno_fatura (fatura_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function cccVencimento(string $competencia, int $dia): string
{
    $ultimo=(int)date('t',strtotime($competencia.'-01'));
    return $competencia.'-'.str_pad((string)min($dia,$ultimo),2,'0',STR_PAD_LEFT);
}

function lerCsvConciliacaoCartao(string $arquivo): array
{
    $linhas=lerCsvFaturaCartao($arquivo);foreach($linhas as &$l){$d=normalizarDescricaoCartao($l['descricao']);if(strpos($d,'PAGTO DEBITO AUTOMATICO')!==false||strpos($d,'PAGAMENTO DE FATURA')!==false||strpos($d,'PAGAMENTO FATURA')!==false)$l['natureza']='P';}unset($l);return $linhas;
}

function lerPdfMercadoPagoPayload(string $json, string $arquivo, string $competencia, string $vencimentoEsperado): array
{
    $dados = json_decode($json, true);
    if (!is_array($dados) || ($dados['origem'] ?? '') !== 'MERCADO_PAGO_PDF') {
        throw new RuntimeException('Dados da fatura Mercado Pago ausentes ou invalidos.');
    }
    if (!hash_equals(hash_file('sha256', $arquivo), (string)($dados['hash_arquivo'] ?? ''))) {
        throw new RuntimeException('O PDF enviado nao corresponde aos dados extraidos.');
    }
    if (($dados['competencia'] ?? '') !== $competencia || ($dados['vencimento'] ?? '') !== $vencimentoEsperado) {
        throw new RuntimeException('Competencia ou vencimento do PDF diverge do cadastro do cartao.');
    }

    $linhas = [];
    foreach (($dados['itens'] ?? []) as $item) {
        $data = trim((string)($item['data_compra'] ?? ''));
        $descricao = trim((string)($item['descricao'] ?? ''));
        $natureza = strtoupper(trim((string)($item['natureza'] ?? '')));
        $valor = round((float)($item['valor'] ?? 0), 2);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $descricao === '' || !in_array($natureza, ['D', 'C', 'P', 'S'], true) || $valor < 0) {
            throw new RuntimeException('O PDF contem um lancamento invalido.');
        }
        if ($valor == 0.0) {
            continue;
        }
        $linhas[] = [
            'data_compra' => $data,
            'descricao' => mb_substr($descricao, 0, 255),
            'categoria' => mb_substr(trim((string)($item['categoria'] ?? '')), 0, 120),
            'tipo_lancamento' => mb_substr(trim((string)($item['tipo_lancamento'] ?? '')), 0, 120),
            'valor' => $valor,
            'natureza' => $natureza,
        ];
    }
    if (!$linhas) {
        throw new RuntimeException('Nenhum lancamento foi identificado no PDF.');
    }

    $debitos = $creditos = $saldo = $compras = $encargos = 0.0;
    foreach ($linhas as $linha) {
        if ($linha['natureza'] === 'D') {
            $debitos += $linha['valor'];
            if ($linha['categoria'] === 'COMPRAS') $compras += $linha['valor'];
            if ($linha['categoria'] === 'ENCARGOS') $encargos += $linha['valor'];
        }
        if (in_array($linha['natureza'], ['C', 'P'], true)) $creditos += $linha['valor'];
        if ($linha['natureza'] === 'S') $saldo += $linha['valor'];
    }
    $resumo = is_array($dados['resumo'] ?? null) ? $dados['resumo'] : [];
    $esperados = [
        [$saldo, (float)($resumo['saldo_anterior'] ?? 0), 'saldo anterior'],
        [$compras, (float)($resumo['consumos'] ?? 0), 'total de compras'],
        [$encargos, (float)($resumo['tarifas'] ?? 0) + (float)($resumo['juros'] ?? 0), 'encargos'],
        [$creditos, (float)($resumo['creditos'] ?? 0), 'pagamentos e creditos'],
    ];
    foreach ($esperados as $validacao) {
        if (abs(round($validacao[0], 2) - round($validacao[1], 2)) >= 0.02) {
            throw new RuntimeException('Os itens nao fecham com o ' . $validacao[2] . ' informado no PDF.');
        }
    }
    $total = round((float)($dados['total'] ?? 0), 2);
    if (abs(round($saldo + $debitos - $creditos, 2) - $total) >= 0.02) {
        throw new RuntimeException('A composicao dos lancamentos nao fecha com o total do PDF.');
    }
    return ['linhas' => $linhas, 'debitos' => $debitos, 'creditos' => $creditos, 'total' => $total];
}

function cccCandidatos(PDO $pdo, array $item, string $vencimento): array
{
    $sql="SELECT cp.CPCONTADOR,cp.DTCOMPRA,cp.DTVENC,cp.TITULO,COALESCE(NULLIF(cp.VLRRESTANTE,0),cp.VLRPARCELA,cp.VALORCOMPRA) valor,COALESCE(NULLIF(f.APELIDO,''),f.NOME) fornecedor FROM armazem_cp001 cp LEFT JOIN armazem_cp003 f ON f.EMPRESA=cp.EMPRESA AND f.FCONTADOR=cp.FCONTADOR WHERE cp.EMPRESA=? AND cp.STATUS<>'QT' AND cp.DTPAGTO IS NULL AND COALESCE(cp.VLRPAGO,0)=0 AND COALESCE(cp.excluido_firebird,'N')<>'S' AND ABS(COALESCE(NULLIF(cp.VLRRESTANTE,0),cp.VLRPARCELA,cp.VALORCOMPRA)-?)<0.01 AND (DATE(cp.DTCOMPRA) BETWEEN DATE_SUB(?,INTERVAL 45 DAY) AND DATE_ADD(?,INTERVAL 45 DAY) OR DATE(cp.DTVENC) BETWEEN DATE_SUB(?,INTERVAL 45 DAY) AND DATE_ADD(?,INTERVAL 45 DAY)) AND NOT EXISTS(SELECT 1 FROM financeiro_cc_itens x WHERE x.empresa_id=cp.EMPRESA AND x.cpcontador=cp.CPCONTADOR AND x.id<>?) ORDER BY (DATE(cp.DTVENC)=?) DESC,LEAST(ABS(DATEDIFF(DATE(cp.DTCOMPRA),?)),ABS(DATEDIFF(DATE(cp.DTVENC),?))),cp.CPCONTADOR LIMIT 12";
    $s=$pdo->prepare($sql);$s->execute([(int)$item['empresa_id'],(float)$item['valor'],$item['data_compra'],$item['data_compra'],$vencimento,$vencimento,(int)$item['id'],$vencimento,$item['data_compra'],$vencimento]);return $s->fetchAll(PDO::FETCH_ASSOC);
}

function cccCandidatosSeguros(array $item,array $candidatos): array
{
    $d=normalizarDescricaoCartao($item['descricao']);$p=array_filter(preg_split('/\s+/',$d)?:[],static function($v){return mb_strlen($v)>=4&&!in_array($v,['COMPRA','CARTAO','PARCELA'],true);});
    return array_values(array_filter($candidatos,static function($c)use($p){$a=normalizarDescricaoCartao(($c['TITULO']??'').' '.($c['fornecedor']??''));foreach($p as $v)if(strpos($a,$v)!==false)return true;return false;}));
}

function cccContarValoresPendentes(array $itens): array
{
    $contagens=[];
    foreach($itens as $item){
        if($item['natureza']!=='D'||$item['cpcontador'])continue;
        $valor=number_format((float)$item['valor'],2,'.','');
        $contagens[$valor]=($contagens[$valor]??0)+1;
    }
    return $contagens;
}

function cccCandidatoAutomatico(array $item,array $candidatos,array $contagens,string $vencimento): ?array
{
    $valor=number_format((float)$item['valor'],2,'.','');
    if(($contagens[$valor]??0)!==1)return null;
    $vencimentoExato=array_values(array_filter($candidatos,static function($cp)use($vencimento){return substr((string)$cp['DTVENC'],0,10)===$vencimento;}));
    $possiveis=$vencimentoExato?:$candidatos;
    if(count($possiveis)!==1)return null;
    return count(cccCandidatosSeguros($item,$possiveis))===1?$possiveis[0]:null;
}

function cccVincular(PDO $pdo,int $empresaId,int $itemId,int $cp,int $usuarioId): void
{
    $s=$pdo->prepare("SELECT i.valor,cp.CPCONTADOR FROM financeiro_cc_itens i JOIN financeiro_cc_faturas fat ON fat.id=i.fatura_id AND fat.empresa_id=i.empresa_id AND fat.status='IMPORTADA' JOIN armazem_cp001 cp ON cp.EMPRESA=i.empresa_id AND cp.CPCONTADOR=? AND cp.STATUS<>'QT' AND cp.DTPAGTO IS NULL AND COALESCE(cp.VLRPAGO,0)=0 AND COALESCE(cp.excluido_firebird,'N')<>'S' AND ABS(COALESCE(NULLIF(cp.VLRRESTANTE,0),cp.VLRPARCELA,cp.VALORCOMPRA)-i.valor)<0.01 WHERE i.id=? AND i.empresa_id=? AND i.natureza='D' AND i.status<>'ESTORNADO' AND i.cpcontador IS NULL");$s->execute([$cp,$itemId,$empresaId]);if(!$s->fetch())throw new RuntimeException('Fatura encerrada ou CP invalido, pago ou com valor diferente.');
    $s=$pdo->prepare("SELECT COUNT(*) FROM financeiro_cc_itens WHERE empresa_id=? AND cpcontador=?");$s->execute([$empresaId,$cp]);if((int)$s->fetchColumn())throw new RuntimeException('CP ja conciliado nesta rotina.');
    $pdo->prepare("UPDATE financeiro_cc_itens SET cpcontador=?,status='CONCILIADO',usuario_id=?,conciliado_em=NOW() WHERE id=?")->execute([$cp,$usuarioId?:null,$itemId]);
}

function cccDesfazerVinculo(PDO $pdo,int $empresa,int $faturaId,int $itemId,int $usuario): string
{
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare("SELECT i.cpcontador,i.status AS item_status,f.status AS fatura_status FROM financeiro_cc_itens i JOIN financeiro_cc_faturas f ON f.id=i.fatura_id AND f.empresa_id=i.empresa_id WHERE i.id=? AND i.fatura_id=? AND i.empresa_id=? AND i.natureza='D' FOR UPDATE");
        $s->execute([$itemId,$faturaId,$empresa]);$item=$s->fetch(PDO::FETCH_ASSOC);
        if(!$item || $item['fatura_status']!=='IMPORTADA' || !in_array($item['item_status'],['CONCILIADO','GERADO'],true) || !(int)$item['cpcontador'])throw new RuntimeException('Somente matches de faturas abertas podem ser desfeitos.');
        $s=$pdo->prepare("SELECT 1 FROM financeiro_cc_creditos_esperados WHERE debito_id=?");$s->execute([$itemId]);if($s->fetchColumn())throw new RuntimeException('Cancele primeiro a espera pelo credito desta compra.');
        $cpId=(int)$item['cpcontador'];
        $s=$pdo->prepare("SELECT STATUS,DTPAGTO,VLRPAGO,CHAVEINTEGRACAO,excluido_firebird FROM armazem_cp001 WHERE EMPRESA=? AND CPCONTADOR=? FOR UPDATE");$s->execute([$empresa,$cpId]);$cp=$s->fetch(PDO::FETCH_ASSOC);
        if(!$cp || $cp['STATUS']!=='AB' || $cp['DTPAGTO'] || (float)$cp['VLRPAGO']>0 || ($cp['excluido_firebird']??'N')==='S')throw new RuntimeException('O CP ja foi alterado ou baixado. O match nao pode ser desfeito.');
        $gerado=$item['item_status']==='GERADO';
        if($gerado){
            if((string)$cp['CHAVEINTEGRACAO']!=='CC-FATURA:'.$empresa.':'.$itemId)throw new RuntimeException('O CP nao foi criado por este item da fatura.');
            $s=$pdo->prepare("SELECT 1 FROM financeiro_cc_baixas WHERE empresa_id=? AND cpcontador=? LIMIT 1");$s->execute([$empresa,$cpId]);if($s->fetchColumn())throw new RuntimeException('CP com baixa registrada nao pode ser cancelado.');
            $s=$pdo->prepare("SELECT 1 FROM movimentacao_baixa_cp_cr_vinculos WHERE empresa_id=? AND cpcontador=? AND status='ATIVO' LIMIT 1");$s->execute([$empresa,$cpId]);if($s->fetchColumn())throw new RuntimeException('CP com contas a receber vinculado nao pode ser cancelado.');
            $s=$pdo->prepare("SELECT 1 FROM armazem_bnc001 WHERE EMPRESA=? AND TIPODOCORIGEM='CP001' AND NUMDOCORIGEM=? AND COALESCE(deletado,'N')<>'S' LIMIT 1");$s->execute([$empresa,$cpId]);if($s->fetchColumn())throw new RuntimeException('CP com movimento bancario nao pode ser cancelado.');
            $s=$pdo->prepare("UPDATE armazem_cp001 SET excluido_firebird='S',data_exclusao_firebird=NOW(),motivo_sync='CANCELADO_CONCILIACAO_CARTAO',USERALT=?,DTALT=NOW(),REGSTAMP=NOW() WHERE EMPRESA=? AND CPCONTADOR=? AND STATUS='AB' AND DTPAGTO IS NULL AND COALESCE(VLRPAGO,0)=0 AND COALESCE(excluido_firebird,'N')<>'S'");
            $s->execute([$usuario?:null,$empresa,$cpId]);if($s->rowCount()!==1)throw new RuntimeException('Nao foi possivel cancelar o CP gerado.');
        }
        $s=$pdo->prepare("UPDATE financeiro_cc_itens SET cpcontador=NULL,status='PENDENTE',usuario_id=?,conciliado_em=NULL WHERE id=? AND fatura_id=? AND empresa_id=? AND cpcontador=?");
        $s->execute([$usuario?:null,$itemId,$faturaId,$empresa,$cpId]);if($s->rowCount()!==1)throw new RuntimeException('Nao foi possivel desfazer o vinculo.');
        $pdo->prepare("INSERT INTO financeiro_cc_match_correcoes (empresa_id,fatura_id,item_id,cpcontador,cp_cancelado,usuario_id) VALUES (?,?,?,?,?,?)")->execute([$empresa,$faturaId,$itemId,$cpId,$gerado?'S':'N',$usuario?:null]);
        $pdo->commit();return $gerado?'CP gerado cancelado e match desfeito.':'Match desfeito. O CP original continua aberto.';
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function cccGerarCp(PDO $pdo,array $item,array $fatura,int $usuarioId,int $tipoes): int
{
    $chave='CC-FATURA:'.(int)$item['empresa_id'].':'.(int)$item['id'];$s=$pdo->prepare("SELECT CPCONTADOR FROM armazem_cp001 WHERE EMPRESA=? AND CHAVEINTEGRACAO=? AND COALESCE(excluido_firebird,'N')<>'S'");$s->execute([(int)$item['empresa_id'],$chave]);if($id=(int)$s->fetchColumn())return $id;
    $cp=proximoCpcontadorCartao($pdo,(int)$item['empresa_id']);$titulo=mb_substr($item['descricao'],0,255);$obs=mb_substr('Fatura '.$fatura['cartao_nome'].' '.$fatura['competencia'].' | item #'.$item['id'],0,500);
    $sql="INSERT INTO armazem_cp001 (EMPRESA,CPCONTADOR,DTCOMPRA,NUMPARCELA,TITULO,VALORCOMPRA,FCONTADOR,OBSERVACAO,DTEMISSAO,VLRPARCELA,PARCELA,DTVENC,VLRRESTANTE,VLRPAGO,STATUS,TIPODOCORIGEM,NUMDOCORIGEM,CONTROLE,TIPOCP,TIPOES,REGSTAMP,REGIMPORT,USERLANC,DTLANC,USERALT,DTALT,CHAVEINTEGRACAO,financeiro_verificado,excluido_firebird) VALUES (:empresa,:cp,:compra,1,:titulo,:valor,:fornecedor,:observacao,:emissao,:parcela_valor,'1/1',:vencimento,:restante,0,'AB','CARTAO',:documento,'CONCILIACAO_CARTAO','CP',:tipoes,NOW(),'S',:userlanc,NOW(),:useralt,NOW(),:chave,'N','N')";
    $pdo->prepare($sql)->execute(['empresa'=>(int)$item['empresa_id'],'cp'=>$cp,'compra'=>$item['data_compra'],'titulo'=>$titulo,'valor'=>(float)$item['valor'],'fornecedor'=>(int)$fatura['fornecedor_fcontador'],'observacao'=>$obs,'emissao'=>$item['data_compra'],'parcela_valor'=>(float)$item['valor'],'vencimento'=>$fatura['vencimento'],'restante'=>(float)$item['valor'],'documento'=>'FATURA-'.$item['fatura_id'],'tipoes'=>$tipoes,'userlanc'=>$usuarioId?:null,'useralt'=>$usuarioId?:null,'chave'=>$chave]);return $cp;
}
