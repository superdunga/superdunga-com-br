<?php

function whatsappOperacionalEnsureTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_operacional_config (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL DEFAULT 'Operacional',
            evolution_token VARCHAR(255) NOT NULL DEFAULT '',
            evolution_api_base_url VARCHAR(255) NOT NULL DEFAULT '',
            instancia VARCHAR(120) NOT NULL DEFAULT '',
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_whatsapp_operacional_empresa (empresa_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_operacional_destinatarios (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            nome VARCHAR(120) NOT NULL,
            tipo ENUM('PESSOA','GRUPO') NOT NULL DEFAULT 'PESSOA',
            numero VARCHAR(80) NOT NULL,
            ativo CHAR(1) NOT NULL DEFAULT 'S',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_wo_dest_empresa (empresa_id, ativo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_operacional_envios (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            destinatario_id INT UNSIGNED NULL,
            destino_nome VARCHAR(120) NOT NULL,
            destino_numero VARCHAR(80) NOT NULL,
            mensagem TEXT NOT NULL,
            status ENUM('OK','ERRO') NOT NULL,
            resposta_api TEXT NULL,
            erro TEXT NULL,
            usuario_id INT NULL,
            enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wo_envios_empresa (empresa_id, enviado_em),
            INDEX idx_wo_envios_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS whatsapp_operacional_fechamentos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            empresa_id INT NOT NULL,
            clicontador INT NOT NULL,
            data_inicio DATE NOT NULL,
            data_fim DATE NOT NULL,
            quantidade_titulos INT NOT NULL DEFAULT 0,
            valor_aberto DECIMAL(15,2) NOT NULL DEFAULT 0,
            arquivo VARCHAR(255) NULL,
            status ENUM('OK','ERRO') NOT NULL,
            resposta_api TEXT NULL,
            erro TEXT NULL,
            usuario_id INT NULL,
            enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wo_fech_empresa_periodo (empresa_id, data_inicio, data_fim),
            INDEX idx_wo_fech_cliente (empresa_id, clicontador, enviado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        INSERT IGNORE INTO whatsapp_operacional_config
            (empresa_id, nome, evolution_token, evolution_api_base_url, instancia, ativo)
        SELECT 1, 'Operacional - Empresa 1', evolution_token, evolution_api_base_url, instancia, ativo
        FROM whatsapp_config
        WHERE empresa_id = 1
          AND instancia = 'Superdunga_Armazem'
        LIMIT 1
    ");
}

function whatsappOperacionalConfig(PDO $pdo, int $empresaId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_operacional_config WHERE empresa_id = ? LIMIT 1");
    $stmt->execute([$empresaId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function whatsappOperacionalTestar(array $config): array
{
    $url = rtrim(trim((string)($config['evolution_api_base_url'] ?? '')), '/');
    $token = trim((string)($config['evolution_token'] ?? ''));
    $instancia = trim((string)($config['instancia'] ?? ''));
    if ($url === '' || $token === '' || $instancia === '') {
        throw new Exception('Informe URL, API Key e instancia da Evolution.');
    }

    $ch = curl_init($url . '/instance/connectionState/' . rawurlencode($instancia));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['apikey: ' . $token],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resposta = curl_exec($ch);
    $erro = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resposta === false || $http < 200 || $http >= 300) {
        throw new Exception($resposta === false ? 'Falha de conexao: ' . $erro : 'Evolution respondeu HTTP ' . $http . '.');
    }
    $json = json_decode($resposta, true);
    $estado = strtolower((string)($json['instance']['state'] ?? $json['state'] ?? ''));
    return ['conectado' => in_array($estado, ['open', 'connected'], true), 'estado' => $estado ?: 'desconhecido'];
}

function whatsappOperacionalEnviar(PDO $pdo, array $config, array $destinatario, string $mensagem, ?int $usuarioId): array
{
    if (($config['ativo'] ?? 'N') !== 'S') {
        throw new Exception('A instancia operacional desta empresa esta inativa.');
    }
    $url = rtrim(trim((string)$config['evolution_api_base_url']), '/');
    $token = trim((string)$config['evolution_token']);
    $instancia = trim((string)$config['instancia']);
    $numero = trim((string)$destinatario['numero']);
    if ($url === '' || $token === '' || $instancia === '') {
        throw new Exception('A configuracao operacional esta incompleta.');
    }
    if (($destinatario['tipo'] ?? 'PESSOA') === 'GRUPO') {
        $numero = preg_replace('/\D+/', '', preg_replace('/@g\.us$/i', '', $numero)) . '@g.us';
    }

    $ch = curl_init($url . '/message/sendText/' . rawurlencode($instancia));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['number' => $numero, 'text' => $mensagem], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . $token],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resposta = curl_exec($ch);
    $erroCurl = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = $resposta !== false && $http >= 200 && $http < 300;
    $erro = $ok ? null : ($resposta === false ? $erroCurl : 'HTTP ' . $http . ' - ' . $resposta);

    $stmt = $pdo->prepare("
        INSERT INTO whatsapp_operacional_envios
            (empresa_id, destinatario_id, destino_nome, destino_numero, mensagem, status, resposta_api, erro, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        (int)$destinatario['empresa_id'], (int)$destinatario['id'], $destinatario['nome'], $destinatario['numero'],
        $mensagem, $ok ? 'OK' : 'ERRO', $resposta === false ? null : $resposta, $erro, $usuarioId,
    ]);
    return ['ok' => $ok, 'erro' => $erro];
}

function whatsappOperacionalClientesFechamento(PDO $pdo, int $empresaId, string $inicio, string $fim): array
{
    $stmt = $pdo->prepare("
        SELECT c.CLICONTADOR, COALESCE(NULLIF(c.NOME,''),NULLIF(c.APELIDO,''),CONCAT('Cliente ',c.CLICONTADOR)) AS nome_cliente,
               c.CELULAR, COUNT(cr.CRCONTADOR) AS quantidade_titulos, COALESCE(SUM(cr.VLRRESTANTE),0) AS valor_aberto,
               MAX(ok.enviado_em) AS ultimo_envio
        FROM armazem_cr002 c
        INNER JOIN financeiro_clientes_whatsapp w ON w.empresa_id=c.EMPRESA AND w.clicontador=c.CLICONTADOR AND w.ativo_whatsapp='S'
        INNER JOIN armazem_cr001 cr ON cr.EMPRESA=c.EMPRESA AND cr.CLICONTADOR=c.CLICONTADOR
        LEFT JOIN armazem_est007 v ON v.EMPRESA=cr.EMPRESA AND v.VENDACONTADOR=cr.NUMDOCORIGEM
        LEFT JOIN (
            SELECT empresa_id,clicontador,data_inicio,data_fim,MAX(enviado_em) AS enviado_em
            FROM whatsapp_operacional_fechamentos
            WHERE status='OK'
            GROUP BY empresa_id,clicontador,data_inicio,data_fim
        ) ok ON ok.empresa_id=c.EMPRESA AND ok.clicontador=c.CLICONTADOR
             AND ok.data_inicio=? AND ok.data_fim=?
        WHERE c.EMPRESA=?
          AND DATE(COALESCE(v.DTVENDA,cr.DTEMISSAO)) BETWEEN ? AND ?
          AND (cr.STATUS IS NULL OR cr.STATUS<>'QT')
          AND COALESCE(cr.VLRRESTANTE,0)>0
          AND COALESCE(cr.excluido_firebird,'N')<>'S'
        GROUP BY c.CLICONTADOR,nome_cliente,c.CELULAR
        ORDER BY nome_cliente,c.CLICONTADOR
    ");
    $stmt->execute([$inicio, $fim, $empresaId, $inicio, $fim]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function whatsappOperacionalTitulosCliente(PDO $pdo, int $empresaId, int $clicontador, string $inicio, string $fim): array
{
    $stmt = $pdo->prepare("
        SELECT cr.CRCONTADOR, cr.CLICONTADOR,
               COALESCE(NULLIF(cli.NOME,''),NULLIF(cli.APELIDO,''),CONCAT('Cliente ',cr.CLICONTADOR)) AS nome_cliente,
               cli.CELULAR, cr.DTVENC, cr.DTEMISSAO, cr.DTPAGTO, cr.VLRPARCELA, cr.VLRPAGO, cr.VLRRESTANTE,
               COALESCE(NULLIF(cr.STATUS,''),'AB') AS STATUS
        FROM armazem_cr001 cr
        INNER JOIN armazem_cr002 cli ON cli.EMPRESA=cr.EMPRESA AND cli.CLICONTADOR=cr.CLICONTADOR
        LEFT JOIN armazem_est007 v ON v.EMPRESA=cr.EMPRESA AND v.VENDACONTADOR=cr.NUMDOCORIGEM
        WHERE cr.EMPRESA=? AND cr.CLICONTADOR=?
          AND DATE(COALESCE(v.DTVENDA,cr.DTEMISSAO)) BETWEEN ? AND ?
          AND (cr.STATUS IS NULL OR cr.STATUS<>'QT')
          AND COALESCE(cr.VLRRESTANTE,0)>0
          AND COALESCE(cr.excluido_firebird,'N')<>'S'
        ORDER BY cr.DTVENC,cr.CRCONTADOR
    ");
    $stmt->execute([$empresaId, $clicontador, $inicio, $fim]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function whatsappOperacionalPdfTexto(string $texto, int $limite = 0): string
{
    $texto = preg_replace('/\s+/', ' ', trim($texto));
    if ($limite > 0 && strlen($texto) > $limite) {
        $texto = substr($texto, 0, max(0, $limite - 3)) . '...';
    }
    $convertido = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto);
    $texto = $convertido === false ? $texto : $convertido;
    return str_replace(['\\','(',')'], ['\\\\','\(','\)'], $texto);
}

function whatsappOperacionalPdfTextoCmd(float $x, float $y, int $tamanho, string $texto, bool $negrito = false): string
{
    return 'BT /' . ($negrito ? 'F2' : 'F1') . ' ' . $tamanho . ' Tf 1 0 0 1 ' . number_format($x,2,'.','') . ' ' . number_format($y,2,'.','') . ' Tm (' . whatsappOperacionalPdfTexto($texto) . ") Tj ET\n";
}

function whatsappOperacionalGerarPdf(string $arquivo, string $empresa, array $cliente, string $inicio, string $fim, array $titulos): void
{
    $largura=842; $altura=595; $margem=28; $linhaAltura=16; $topoTabela=485; $porPagina=27;
    $colunas=[
        ['CR',45],['Cod.',45],['Cliente',245],['Venc.',62],['Emissao',62],['Pgto.',62],['Valor',72],['Pago',72],['Restante',72],['Status',45],
    ];
    $paginas=[]; $totalPaginas=max(1,(int)ceil(count($titulos)/$porPagina));
    for($pagina=0;$pagina<$totalPaginas;$pagina++){
        $conteudo="0.05 0.20 0.45 rg\n0 517 842 78 re f\n1 1 1 rg\n";
        $conteudo.=whatsappOperacionalPdfTextoCmd($margem,565,17,'Contas a Receber - Clientes - Analitico',true);
        $conteudo.=whatsappOperacionalPdfTextoCmd($margem,547,8,'Empresa: '.$empresa);
        $conteudo.=whatsappOperacionalPdfTextoCmd($margem,536,8,'Cliente: '.$cliente['nome_cliente'].' | Codigo: '.(int)$cliente['CLICONTADOR'].' | Celular: '.($cliente['CELULAR'] ?: 'Nao informado'));
        $conteudo.=whatsappOperacionalPdfTextoCmd($margem,525,8,'Intervalo de compras: '.date('d/m/Y',strtotime($inicio)).' a '.date('d/m/Y',strtotime($fim)));
        $conteudo.="0.88 0.93 0.98 rg\n28 481 786 18 re f\n0 g\n";
        $x=$margem+3;
        foreach($colunas as $coluna){$conteudo.=whatsappOperacionalPdfTextoCmd($x,$topoTabela+2,8,$coluna[0],true);$x+=$coluna[1];}
        $y=$topoTabela-16;
        foreach(array_slice($titulos,$pagina*$porPagina,$porPagina) as $t){
            $valores=[(string)$t['CRCONTADOR'],(string)$t['CLICONTADOR'],$t['nome_cliente'],date('d/m/Y',strtotime($t['DTVENC'])),date('d/m/Y',strtotime($t['DTEMISSAO'])),$t['DTPAGTO']?date('d/m/Y',strtotime($t['DTPAGTO'])):'','R$ '.number_format((float)$t['VLRPARCELA'],2,',','.'),'R$ '.number_format((float)$t['VLRPAGO'],2,',','.'),'R$ '.number_format((float)$t['VLRRESTANTE'],2,',','.'),$t['STATUS']];
            $x=$margem+3;
            foreach($colunas as $i=>$coluna){$limite=$i===2?38:16;$conteudo.=whatsappOperacionalPdfTextoCmd($x,$y,8,whatsappOperacionalPdfTexto((string)$valores[$i],$limite));$x+=$coluna[1];}
            $y-=$linhaAltura;
        }
        if($pagina===$totalPaginas-1){
            $total=array_sum(array_map(function($t){return (float)$t['VLRRESTANTE'];},$titulos));
            $conteudo.=whatsappOperacionalPdfTextoCmd(600,35,9,'Total em aberto: R$ '.number_format($total,2,',','.'),true);
        }
        $conteudo.=whatsappOperacionalPdfTextoCmd($margem,22,8,'Gerado em '.date('d/m/Y H:i').' - Pagina '.($pagina+1).' de '.$totalPaginas);
        $paginas[]=$conteudo;
    }
    $objetos=[1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
    $kids=[];$id=5;
    foreach($paginas as $conteudo){$paginaId=$id++;$streamId=$id++;$kids[]=$paginaId.' 0 R';$objetos[$paginaId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$streamId.' 0 R >>';$objetos[$streamId]='<< /Length '.strlen($conteudo).">>\nstream\n".$conteudo."endstream";}
    $objetos[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';ksort($objetos);
    $pdf="%PDF-1.4\n";$offsets=[0];foreach($objetos as $oid=>$obj){$offsets[$oid]=strlen($pdf);$pdf.=$oid." 0 obj\n".$obj."\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objetos));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++){$pdf.=str_pad((string)($offsets[$i]??0),10,'0',STR_PAD_LEFT)." 00000 n \n";}$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    if(!is_dir(dirname($arquivo))){mkdir(dirname($arquivo),0775,true);}file_put_contents($arquivo,$pdf);
}

function whatsappOperacionalEnviarDocumento(array $config, string $numero, string $arquivo, string $nomeArquivo, string $legenda): array
{
    $numero=preg_replace('/\D+/','',$numero);
    if(strlen($numero)===10||strlen($numero)===11){$numero='55'.$numero;}
    $url=rtrim(trim((string)$config['evolution_api_base_url']),'/').'/message/sendMedia/'.rawurlencode($config['instancia']);
    $payload=['number'=>$numero,'mediatype'=>'document','mimetype'=>'application/pdf','caption'=>$legenda,'media'=>'data:application/pdf;base64,'.base64_encode((string)file_get_contents($arquivo)),'fileName'=>$nomeArquivo];
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json','apikey: '.$config['evolution_token']],CURLOPT_TIMEOUT=>60]);$resposta=curl_exec($ch);$erroCurl=curl_error($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $ok=$resposta!==false&&$http>=200&&$http<300;return ['ok'=>$ok,'resposta'=>$resposta===false?null:$resposta,'erro'=>$ok?null:($resposta===false?$erroCurl:'HTTP '.$http.' - '.$resposta)];
}
