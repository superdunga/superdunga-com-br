<?php
require_once __DIR__ . '/_cartao_credito_lib.php';

function cccGarantirTabelas(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_cartoes (id INT AUTO_INCREMENT PRIMARY KEY,empresa_id INT NOT NULL,nome VARCHAR(120) NOT NULL,nome_norm VARCHAR(120) NOT NULL,fornecedor_fcontador INT NOT NULL,dia_vencimento TINYINT UNSIGNED NOT NULL,ativo CHAR(1) NOT NULL DEFAULT 'S',usuario_id INT NULL,criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_cc_cartao (empresa_id,nome_norm)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_faturas (id INT AUTO_INCREMENT PRIMARY KEY,empresa_id INT NOT NULL,cartao_id INT NOT NULL,competencia CHAR(7) NOT NULL,vencimento DATE NOT NULL,nome_arquivo VARCHAR(255),hash_arquivo CHAR(64) NOT NULL,total_debitos DECIMAL(15,2) NOT NULL,total_creditos DECIMAL(15,2) NOT NULL,total_fatura DECIMAL(15,2) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'IMPORTADA',usuario_id INT NULL,importado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_cc_hash (empresa_id,hash_arquivo),UNIQUE KEY uq_cc_comp (empresa_id,cartao_id,competencia)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS financeiro_cc_itens (id INT AUTO_INCREMENT PRIMARY KEY,fatura_id INT NOT NULL,empresa_id INT NOT NULL,data_compra DATE NOT NULL,descricao VARCHAR(255) NOT NULL,categoria VARCHAR(120),tipo_lancamento VARCHAR(120),valor DECIMAL(15,2) NOT NULL,natureza CHAR(1) NOT NULL,hash_item CHAR(64) NOT NULL,cpcontador INT NULL,status VARCHAR(20) NOT NULL DEFAULT 'PENDENTE',usuario_id INT NULL,conciliado_em DATETIME NULL,UNIQUE KEY uq_cc_item (fatura_id,hash_item),UNIQUE KEY uq_cc_cp (empresa_id,cpcontador),INDEX idx_cc_status (fatura_id,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function cccVencimento(string $competencia, int $dia): string
{
    $ultimo=(int)date('t',strtotime($competencia.'-01'));
    return $competencia.'-'.str_pad((string)min($dia,$ultimo),2,'0',STR_PAD_LEFT);
}

function lerCsvConciliacaoCartao(string $arquivo): array
{
    $linhas=lerCsvFaturaCartao($arquivo);foreach($linhas as &$l){$d=normalizarDescricaoCartao($l['descricao']);if(str_contains($d,'PAGTO DEBITO AUTOMATICO')||str_contains($d,'PAGAMENTO DE FATURA')||str_contains($d,'PAGAMENTO FATURA'))$l['natureza']='P';}unset($l);return $linhas;
}

function cccCandidatos(PDO $pdo, array $item, string $vencimento): array
{
    $sql="SELECT cp.CPCONTADOR,cp.DTCOMPRA,cp.DTVENC,cp.TITULO,COALESCE(NULLIF(cp.VLRRESTANTE,0),cp.VLRPARCELA,cp.VALORCOMPRA) valor,COALESCE(NULLIF(f.APELIDO,''),f.NOME) fornecedor FROM armazem_cp001 cp LEFT JOIN armazem_cp003 f ON f.EMPRESA=cp.EMPRESA AND f.FCONTADOR=cp.FCONTADOR WHERE cp.EMPRESA=? AND cp.STATUS<>'QT' AND cp.DTPAGTO IS NULL AND COALESCE(cp.VLRPAGO,0)=0 AND COALESCE(cp.excluido_firebird,'N')<>'S' AND ABS(COALESCE(NULLIF(cp.VLRRESTANTE,0),cp.VLRPARCELA,cp.VALORCOMPRA)-?)<0.01 AND (DATE(cp.DTCOMPRA) BETWEEN DATE_SUB(?,INTERVAL 45 DAY) AND DATE_ADD(?,INTERVAL 45 DAY) OR DATE(cp.DTVENC) BETWEEN DATE_SUB(?,INTERVAL 45 DAY) AND DATE_ADD(?,INTERVAL 45 DAY)) AND NOT EXISTS(SELECT 1 FROM financeiro_cc_itens x WHERE x.empresa_id=cp.EMPRESA AND x.cpcontador=cp.CPCONTADOR AND x.id<>?) ORDER BY LEAST(ABS(DATEDIFF(DATE(cp.DTCOMPRA),?)),ABS(DATEDIFF(DATE(cp.DTVENC),?))),cp.CPCONTADOR LIMIT 12";
    $s=$pdo->prepare($sql);$s->execute([(int)$item['empresa_id'],(float)$item['valor'],$item['data_compra'],$item['data_compra'],$vencimento,$vencimento,(int)$item['id'],$item['data_compra'],$vencimento]);return $s->fetchAll(PDO::FETCH_ASSOC);
}

function cccCandidatosSeguros(array $item,array $candidatos): array
{
    $d=normalizarDescricaoCartao($item['descricao']);$p=array_filter(preg_split('/\s+/',$d)?:[],static fn($v)=>mb_strlen($v)>=4&&!in_array($v,['COMPRA','CARTAO','PARCELA'],true));
    return array_values(array_filter($candidatos,static function($c)use($p){$a=normalizarDescricaoCartao(($c['TITULO']??'').' '.($c['fornecedor']??''));foreach($p as $v)if(str_contains($a,$v))return true;return false;}));
}

function cccVincular(PDO $pdo,int $empresaId,int $itemId,int $cp,int $usuarioId): void
{
    $s=$pdo->prepare("SELECT i.valor,cp.CPCONTADOR FROM financeiro_cc_itens i JOIN armazem_cp001 cp ON cp.EMPRESA=i.empresa_id AND cp.CPCONTADOR=? AND cp.STATUS<>'QT' AND cp.DTPAGTO IS NULL AND COALESCE(cp.VLRPAGO,0)=0 AND COALESCE(cp.excluido_firebird,'N')<>'S' AND ABS(COALESCE(NULLIF(cp.VLRRESTANTE,0),cp.VLRPARCELA,cp.VALORCOMPRA)-i.valor)<0.01 WHERE i.id=? AND i.empresa_id=? AND i.natureza='D' AND i.cpcontador IS NULL");$s->execute([$cp,$itemId,$empresaId]);if(!$s->fetch())throw new RuntimeException('CP invalido, pago ou com valor diferente.');
    $s=$pdo->prepare("SELECT COUNT(*) FROM financeiro_cc_itens WHERE empresa_id=? AND cpcontador=?");$s->execute([$empresaId,$cp]);if((int)$s->fetchColumn())throw new RuntimeException('CP ja conciliado nesta rotina.');
    $pdo->prepare("UPDATE financeiro_cc_itens SET cpcontador=?,status='CONCILIADO',usuario_id=?,conciliado_em=NOW() WHERE id=?")->execute([$cp,$usuarioId?:null,$itemId]);
}

function cccGerarCp(PDO $pdo,array $item,array $fatura,int $usuarioId): int
{
    $chave='CC-FATURA:'.(int)$item['empresa_id'].':'.(int)$item['id'];$s=$pdo->prepare("SELECT CPCONTADOR FROM armazem_cp001 WHERE EMPRESA=? AND CHAVEINTEGRACAO=?");$s->execute([(int)$item['empresa_id'],$chave]);if($id=(int)$s->fetchColumn())return $id;
    $s=$pdo->prepare("SELECT TIPOES FROM armazem_cp003 WHERE EMPRESA=? AND FCONTADOR=? AND COALESCE(excluido_firebird,'N')<>'S'");$s->execute([(int)$item['empresa_id'],(int)$fatura['fornecedor_fcontador']]);$tipoes=(int)$s->fetchColumn();if(!$tipoes)throw new RuntimeException('O fornecedor do cartao precisa ter TIPOES configurado.');
    $cp=proximoCpcontadorCartao($pdo,(int)$item['empresa_id']);$titulo=mb_substr($item['descricao'],0,255);$obs=mb_substr('Fatura '.$fatura['cartao_nome'].' '.$fatura['competencia'].' | '.$item['descricao'],0,500);
    $sql="INSERT INTO armazem_cp001 (EMPRESA,CPCONTADOR,DTCOMPRA,NUMPARCELA,TITULO,VALORCOMPRA,FCONTADOR,OBSERVACAO,DTEMISSAO,VLRPARCELA,PARCELA,DTVENC,VLRRESTANTE,VLRPAGO,STATUS,TIPODOCORIGEM,NUMDOCORIGEM,CONTROLE,TIPOCP,TIPOES,REGSTAMP,REGIMPORT,USERLANC,DTLANC,USERALT,DTALT,CHAVEINTEGRACAO,financeiro_verificado,excluido_firebird) VALUES (?,?,?,1,?,?,?,?,?,?,?,'1/1',?,?,0,'AB','CARTAO',?,'CONCILIACAO_CARTAO','CP',?,NOW(),'S',?,NOW(),?,NOW(),?,'N','N')";
    $pdo->prepare($sql)->execute([(int)$item['empresa_id'],$cp,$item['data_compra'],$titulo,(float)$item['valor'],(int)$fatura['fornecedor_fcontador'],$obs,$item['data_compra'],(float)$item['valor'],$fatura['vencimento'],(float)$item['valor'],'FATURA-'.$item['fatura_id'],$tipoes,$usuarioId?:null,$usuarioId?:null,$chave]);return $cp;
}
