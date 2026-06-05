import requests
import datetime


def registrar_log(tabela, registros, status, mensagem):
    try:
        url = "https://superdunga.com.br/modulos/tesouraria/log_sync.php"

        payload = {
            "tabela": tabela,
            "registros": registros,
            "status": status,
            "mensagem": mensagem
        }

        requests.post(url, json=payload, timeout=10)

    except Exception as e:
        print("Erro ao registrar log:", str(e))


def processar_tabela(nome, url_api, tabela_php):
    try:
        print(f"\nProcessando {nome}...")

        url_ultima = f"https://www.superdunga.com.br/modulos/tesouraria/ultimo_regstamp.php?tabela={tabela_php}"

        resp_ultima = requests.get(url_ultima, timeout=60)
        resp_ultima.raise_for_status()

        ultima_regstamp = resp_ultima.json().get("ultima_regstamp", "1900-01-01 00:00:00")

        print(f"Ultima REGSTAMP: {ultima_regstamp}")

        resposta = requests.get(
            url_api,
            params={"ultima_regstamp": ultima_regstamp},
            timeout=300
        )
        resposta.raise_for_status()

        dados = resposta.json()

        if isinstance(dados, dict) and dados.get("erro"):
            raise Exception(dados["erro"])

        print(f"Registros encontrados: {len(dados)}")

        if len(dados) > 0:
            url_php = f"https://www.superdunga.com.br/modulos/tesouraria/receber_firebird.php?tabela={tabela_php}"

            if tabela_php == "est007":
                tamanho_lote = 10000
                total_enviado = 0

                for i in range(0, len(dados), tamanho_lote):
                    lote = dados[i:i + tamanho_lote]

                    print(f"Enviando lote {i // tamanho_lote + 1} com {len(lote)} registros...")

                    envio = requests.post(url_php, json=lote, timeout=600)
                    envio.raise_for_status()

                    resposta_texto = envio.text

                    print("Resposta do servidor:")
                    print(resposta_texto)

                    total_enviado += len(lote)

                registrar_log(nome, total_enviado, "OK", f"Lotes enviados com sucesso: {total_enviado}")

            else:
                envio = requests.post(url_php, json=dados, timeout=600)
                envio.raise_for_status()

                resposta_texto = envio.text

                print("Resposta do servidor:")
                print(resposta_texto)

                registrar_log(nome, len(dados), "OK", resposta_texto)

        else:
            print("Nenhum registro novo ou alterado.")
            registrar_log(nome, 0, "OK", "Sem novos dados")

    except Exception as e:
        print(f"Erro em {nome}:", str(e))
        registrar_log(nome, 0, "ERRO", str(e))


def enviar_cr001_ativos():
    try:
        print("\nVerificando CR001 excluidos no Firebird...")

        fim = datetime.datetime.now()
        inicio = fim - datetime.timedelta(days=60)

        inicio_str = inicio.strftime("%Y-%m-%d 00:00:00")
        fim_str = fim.strftime("%Y-%m-%d 23:59:59")

        url_api = "http://127.0.0.1:5000/dados/cr001_ativos"

        resposta = requests.get(
            url_api,
            params={
                "inicio": inicio_str,
                "fim": fim_str
            },
            timeout=300
        )
        resposta.raise_for_status()

        ids = resposta.json()

        if not isinstance(ids, list):
            raise Exception("Resposta invalida da API cr001_ativos")

        print(f"CR001 ativos no Firebird no periodo: {len(ids)}")

        url_php = "https://www.superdunga.com.br/modulos/tesouraria/receber_firebird.php"

        envio = requests.post(
            url_php,
            params={
                "tabela": "cr001_ativos",
                "inicio": inicio_str,
                "fim": fim_str
            },
            json=ids,
            timeout=600
        )
        envio.raise_for_status()

        print("Resposta do servidor CR001 ativos:")
        print(envio.text)

        registrar_log("CR001_ATIVOS", len(ids), "OK", envio.text)

    except Exception as e:
        print("Erro ao verificar CR001 excluidos:", str(e))
        registrar_log("CR001_ATIVOS", 0, "ERRO", str(e))


print("INICIANDO ENVIO FIREBIRD PARA MYSQL")

try:
    print("\nProcessando BNC001...")

    url_ultima = "https://www.superdunga.com.br/modulos/tesouraria/ultimo_regstamp_bnc001.php"
    resp_ultima = requests.get(url_ultima, timeout=60)
    resp_ultima.raise_for_status()

    ultima_regstamp = resp_ultima.json().get("ultima_regstamp", "1900-01-01 00:00:00")

    print(f"Ultima REGSTAMP encontrada: {ultima_regstamp}")
    print("Buscando dados incrementais da API...")

    url_api = "http://127.0.0.1:5000/dados/bnc001"
    resposta = requests.get(
        url_api,
        params={"ultima_regstamp": ultima_regstamp},
        timeout=300
    )
    resposta.raise_for_status()

    dados = resposta.json()

    if isinstance(dados, dict) and dados.get("erro"):
        raise Exception(dados["erro"])

    print(f"Registros encontrados: {len(dados)}")

    if len(dados) > 0:
        url_php = "https://www.superdunga.com.br/modulos/tesouraria/receber_firebird.php?tabela=bnc001"

        envio = requests.post(url_php, json=dados, timeout=600)
        envio.raise_for_status()

        resposta_texto = envio.text

        print("Resposta do servidor incremental:")
        print(resposta_texto)

        registrar_log("BNC001", len(dados), "OK", resposta_texto)

    else:
        print("Nenhum registro novo ou alterado.")
        registrar_log("BNC001", 0, "OK", "Sem novos dados")

    if datetime.datetime.now().hour == 3:

        print("\nBuscando todos MOVCONTADOR para detectar deletados...")

        url_ids = "http://127.0.0.1:5000/dados/bnc001_ids"
        resp_ids = requests.get(url_ids, timeout=300)
        resp_ids.raise_for_status()

        ids = resp_ids.json()

        if not isinstance(ids, list):
            raise Exception("Erro ao obter lista de IDs")

        print(f"Total IDs atuais no Firebird: {len(ids)}")

        url_php_ids = "https://www.superdunga.com.br/modulos/tesouraria/receber_ids_bnc001.php?token=123456"

        envio_ids = requests.post(url_php_ids, json=ids, timeout=600)
        envio_ids.raise_for_status()

        print("Resposta do servidor deletados:")
        print(envio_ids.text)

        registrar_log("BNC001_IDS", len(ids), "OK", "Lista de IDs enviada")

    processar_tabela(
        "CR001",
        "http://127.0.0.1:5000/dados/cr001",
        "cr001"
    )

    enviar_cr001_ativos()

    processar_tabela(
        "CR002",
        "http://127.0.0.1:5000/dados/cr002",
        "cr002"
    )

    processar_tabela(
        "ZCONFIG005",
        "http://127.0.0.1:5000/dados/zconfig005",
        "zconfig005"
    )

    processar_tabela(
        "EST007",
        "http://127.0.0.1:5000/dados/est007",
        "est007"
    )

except Exception as e:
    print("Erro geral:", str(e))
    registrar_log("GERAL", 0, "ERRO", str(e))

print("\nFINALIZADO")
