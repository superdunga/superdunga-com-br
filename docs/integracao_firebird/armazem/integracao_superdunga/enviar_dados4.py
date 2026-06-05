import requests
import datetime
import uuid


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


def processar_tabela_paginada(nome, url_api, tabela_php, tamanho_lote=1000):
    try:
        print(f"\nProcessando {nome} em lotes...")

        url_ultima = f"https://www.superdunga.com.br/modulos/tesouraria/ultimo_regstamp.php?tabela={tabela_php}"

        resp_ultima = requests.get(url_ultima, timeout=60)
        resp_ultima.raise_for_status()

        ultima_regstamp = resp_ultima.json().get("ultima_regstamp", "1900-01-01 00:00:00")

        print(f"Ultima REGSTAMP: {ultima_regstamp}")

        url_php = f"https://www.superdunga.com.br/modulos/tesouraria/receber_firebird.php?tabela={tabela_php}"
        offset = 0
        total_enviado = 0
        lote_numero = 1

        while True:
            resposta = requests.get(
                url_api,
                params={
                    "ultima_regstamp": ultima_regstamp,
                    "limit": tamanho_lote,
                    "offset": offset
                },
                timeout=300
            )
            resposta.raise_for_status()

            dados = resposta.json()

            if isinstance(dados, dict) and dados.get("erro"):
                raise Exception(dados["erro"])

            if not isinstance(dados, list):
                raise Exception(f"Resposta invalida da API {tabela_php}")

            if len(dados) == 0:
                break

            print(f"Enviando {nome} lote {lote_numero} com {len(dados)} registros...")

            envio = requests.post(url_php, json=dados, timeout=600)
            envio.raise_for_status()

            print("Resposta do servidor:")
            print(envio.text)

            total_enviado += len(dados)
            offset += tamanho_lote
            lote_numero += 1

        if total_enviado > 0:
            registrar_log(nome, total_enviado, "OK", f"Lotes enviados com sucesso: {total_enviado}")
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


def enviar_ativos(nome, url_api, tabela_php):
    try:
        print(f"\nVerificando {nome} excluidos no Firebird...")

        resposta = requests.get(url_api, timeout=300)
        resposta.raise_for_status()

        ids = resposta.json()

        if not isinstance(ids, list):
            raise Exception(f"Resposta invalida da API {tabela_php}")

        print(f"{nome} ativos no Firebird: {len(ids)}")

        url_php = "https://www.superdunga.com.br/modulos/tesouraria/receber_firebird.php"

        envio = requests.post(
            url_php,
            params={"tabela": tabela_php},
            json=ids,
            timeout=600
        )
        envio.raise_for_status()

        print(f"Resposta do servidor {nome} ativos:")
        print(envio.text)

        registrar_log(nome, len(ids), "OK", envio.text)

    except Exception as e:
        print(f"Erro ao verificar {nome} excluidos:", str(e))
        registrar_log(nome, 0, "ERRO", str(e))


def verificar_est008_ativos_lotes(tamanho_lote=1000):
    try:
        print("\nVerificando EST008 excluidos no Firebird em lotes...")

        url_api = "http://127.0.0.1:5000/dados/est008_ativos"
        url_php = "https://www.superdunga.com.br/modulos/tesouraria/receber_firebird.php"
        sync_id = f"est008_{datetime.datetime.now().strftime('%Y%m%d%H%M%S')}_{uuid.uuid4().hex[:8]}"
        offset = 0
        total = 0
        lote_numero = 1

        while True:
            resposta = requests.get(
                url_api,
                params={
                    "limit": tamanho_lote,
                    "offset": offset
                },
                timeout=300
            )
            resposta.raise_for_status()

            ids = resposta.json()

            if not isinstance(ids, list):
                raise Exception("Resposta invalida da API est008_ativos")

            if len(ids) == 0:
                break

            print(f"EST008 lote {lote_numero}: {len(ids)} registros")

            envio = requests.post(
                url_php,
                params={
                    "tabela": "est008_ativos",
                    "sync_id": sync_id,
                    "iniciar": "1" if lote_numero == 1 else "0"
                },
                json=ids,
                timeout=600
            )
            envio.raise_for_status()

            print("Resposta do servidor EST008 lote:")
            print(envio.text)

            total += len(ids)
            offset += tamanho_lote
            lote_numero += 1

        envio_final = requests.post(
            url_php,
            params={
                "tabela": "est008_ativos",
                "sync_id": sync_id,
                "finalizar": "1",
                "confirmar_vazio": "1" if total == 0 else "0"
            },
            json=[],
            timeout=600
        )
        envio_final.raise_for_status()

        print("Resposta final do servidor EST008 ativos:")
        print(envio_final.text)

        print(f"EST008 ativos no Firebird: {total}")
        registrar_log("EST008_ATIVOS", total, "OK", envio_final.text)

    except Exception as e:
        print("Erro ao verificar EST008 ativos em lotes:", str(e))
        registrar_log("EST008_ATIVOS", 0, "ERRO", str(e))


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

    enviar_ativos(
        "BNC005_ATIVOS",
        "http://127.0.0.1:5000/dados/bnc005_ativos",
        "bnc005_ativos"
    )

    enviar_ativos(
        "CP001_ATIVOS",
        "http://127.0.0.1:5000/dados/cp001_ativos",
        "cp001_ativos"
    )

    enviar_ativos(
        "CP003_ATIVOS",
        "http://127.0.0.1:5000/dados/cp003_ativos",
        "cp003_ativos"
    )

    enviar_ativos(
        "CP004_ATIVOS",
        "http://127.0.0.1:5000/dados/cp004_ativos",
        "cp004_ativos"
    )

    enviar_ativos(
        "EST004_ATIVOS",
        "http://127.0.0.1:5000/dados/est004_ativos",
        "est004_ativos"
    )

    enviar_ativos(
        "EST005_ATIVOS",
        "http://127.0.0.1:5000/dados/est005_ativos",
        "est005_ativos"
    )

    enviar_ativos(
        "EST006_ATIVOS",
        "http://127.0.0.1:5000/dados/est006_ativos",
        "est006_ativos"
    )

    enviar_ativos(
        "CR002_ATIVOS",
        "http://127.0.0.1:5000/dados/cr002_ativos",
        "cr002_ativos"
    )

    enviar_ativos(
        "ZCONFIG005_ATIVOS",
        "http://127.0.0.1:5000/dados/zconfig005_ativos",
        "zconfig005_ativos"
    )

    enviar_ativos(
        "EST007_ATIVOS",
        "http://127.0.0.1:5000/dados/est007_ativos",
        "est007_ativos"
    )

    verificar_est008_ativos_lotes(1000)

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
        "CP001",
        "http://127.0.0.1:5000/dados/cp001",
        "cp001"
    )

    processar_tabela(
        "CP003",
        "http://127.0.0.1:5000/dados/cp003",
        "cp003"
    )

    processar_tabela(
        "CP004",
        "http://127.0.0.1:5000/dados/cp004",
        "cp004"
    )

    processar_tabela(
        "ZCONFIG005",
        "http://127.0.0.1:5000/dados/zconfig005",
        "zconfig005"
    )

    processar_tabela(
        "EST004",
        "http://127.0.0.1:5000/dados/est004",
        "est004"
    )

    processar_tabela(
        "EST005",
        "http://127.0.0.1:5000/dados/est005",
        "est005"
    )

    processar_tabela(
        "EST006",
        "http://127.0.0.1:5000/dados/est006",
        "est006"
    )

    processar_tabela(
        "EST007",
        "http://127.0.0.1:5000/dados/est007",
        "est007"
    )

    processar_tabela_paginada(
        "EST008",
        "http://127.0.0.1:5000/dados/est008",
        "est008",
        1000
    )

except Exception as e:
    print("Erro geral:", str(e))
    registrar_log("GERAL", 0, "ERRO", str(e))

print("\nFINALIZADO")
