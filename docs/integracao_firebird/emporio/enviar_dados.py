import requests
import datetime
import uuid
import sys


MODO = (sys.argv[1] if len(sys.argv) > 1 else "rapido").lower()
EXECUTAR_COMPLETO = MODO in ["completo", "full", "diario"]
EMPRESA_DESTINO = 4
FIREBIRD_EMPRESA_ORIGEM = 1
BASE_SITE = "https://www.superdunga.com.br"


def params_site(params=None):
    base = {"empresa": EMPRESA_DESTINO}
    if params:
        base.update(params)
    return base


def aplicar_empresa(registro):
    if isinstance(registro, dict):
        empresa_origem = registro.get("EMPRESA")
        if empresa_origem not in (None, ""):
            try:
                if int(empresa_origem) != FIREBIRD_EMPRESA_ORIGEM:
                    return None
            except (TypeError, ValueError):
                return None

        ajustado = dict(registro)
        ajustado["EMPRESA"] = EMPRESA_DESTINO
        for chave, valor in list(ajustado.items()):
            if isinstance(valor, float) and valor.is_integer():
                ajustado[chave] = int(valor)
        return ajustado
    return registro


def aplicar_empresa_lista(dados):
    if isinstance(dados, list):
        return [item for item in (aplicar_empresa(item) for item in dados) if item is not None]
    return dados


def registrar_log(tabela, registros, status, mensagem):
    try:
        url = "https://superdunga.com.br/modulos/tesouraria/log_sync.php"

        payload = {
            "empresa": EMPRESA_DESTINO,
            "tabela": tabela,
            "registros": registros,
            "status": status,
            "mensagem": mensagem
        }

        requests.post(url, json=payload, timeout=10)

    except Exception as e:
        print("Erro ao registrar log:", str(e))


def processar_tabela(nome, url_api, tabela_php, forcar_completo=False):
    try:
        print(f"\nProcessando {nome}...")

        url_ultima = f"{BASE_SITE}/modulos/tesouraria/ultimo_regstamp.php"

        if forcar_completo:
            ultima_regstamp = "1900-01-01 00:00:00"
        else:
            resp_ultima = requests.get(url_ultima, params=params_site({"tabela": tabela_php}), timeout=60)
            resp_ultima.raise_for_status()
            ultima_regstamp = resp_ultima.json().get("ultima_regstamp", "1900-01-01 00:00:00")

        print(f"Ultima REGSTAMP: {ultima_regstamp}")
        if forcar_completo:
            print("Carga completa forcada para manter o espelho igual ao Firebird.")

        params_api = {"ultima_regstamp": ultima_regstamp}
        if tabela_php in ["rep001", "func001"]:
            params_api["empresa"] = FIREBIRD_EMPRESA_ORIGEM

        resposta = requests.get(
            url_api,
            params=params_api,
            timeout=300
        )
        resposta.raise_for_status()

        dados = resposta.json()

        if isinstance(dados, dict) and dados.get("erro"):
            raise Exception(dados["erro"])

        print(f"Registros encontrados: {len(dados)}")

        if len(dados) > 0:
            url_php = f"{BASE_SITE}/modulos/tesouraria/receber_firebird.php"

            if tabela_php == "est007":
                tamanho_lote = 10000
                total_enviado = 0

                for i in range(0, len(dados), tamanho_lote):
                    lote = dados[i:i + tamanho_lote]

                    print(f"Enviando lote {i // tamanho_lote + 1} com {len(lote)} registros...")

                    envio = requests.post(url_php, params=params_site({"tabela": tabela_php}), json=aplicar_empresa_lista(lote), timeout=600)
                    envio.raise_for_status()

                    resposta_texto = envio.text

                    print("Resposta do servidor:")
                    print(resposta_texto)

                    total_enviado += len(lote)

                registrar_log(nome, total_enviado, "OK", f"Lotes enviados com sucesso: {total_enviado}")

            else:
                envio = requests.post(url_php, params=params_site({"tabela": tabela_php}), json=aplicar_empresa_lista(dados), timeout=600)
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


def processar_tabela_paginada(nome, url_api, tabela_php, tamanho_lote=1000, params_api=None):
    try:
        print(f"\nProcessando {nome} em lotes...")
        params_api = params_api or {}

        url_ultima = f"{BASE_SITE}/modulos/tesouraria/ultimo_regstamp.php"

        resp_ultima = requests.get(url_ultima, params=params_site({"tabela": tabela_php}), timeout=60)
        resp_ultima.raise_for_status()

        ultima_regstamp = resp_ultima.json().get("ultima_regstamp", "1900-01-01 00:00:00")

        print(f"Ultima REGSTAMP: {ultima_regstamp}")

        url_php = f"{BASE_SITE}/modulos/tesouraria/receber_firebird.php"
        offset = 0
        total_enviado = 0
        lote_numero = 1

        while True:
            resposta = requests.get(
                url_api,
                params={
                    "ultima_regstamp": ultima_regstamp,
                    "limit": tamanho_lote,
                    "offset": offset,
                    **params_api
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

            envio = requests.post(url_php, params=params_site({"tabela": tabela_php}), json=aplicar_empresa_lista(dados), timeout=600)
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

        url_php = f"{BASE_SITE}/modulos/tesouraria/receber_firebird.php"

        envio = requests.post(
            url_php,
            params=params_site({
                "tabela": "cr001_ativos",
                "inicio": inicio_str,
                "fim": fim_str
            }),
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


def enviar_ativos(nome, url_api, tabela_php, tamanho_lote=1000, params_api=None, params_php=None):
    try:
        print(f"\nVerificando {nome} excluidos no Firebird...")
        params_api = params_api or {}
        params_php = params_php or {}

        resposta = requests.get(url_api, params=params_api, timeout=300)
        resposta.raise_for_status()

        ids = resposta.json()

        if not isinstance(ids, list):
            raise Exception(f"Resposta invalida da API {tabela_php}")

        print(f"{nome} ativos no Firebird: {len(ids)}")

        url_php = f"{BASE_SITE}/modulos/tesouraria/receber_firebird.php"
        sync_id = f"{tabela_php}_{datetime.datetime.now().strftime('%Y%m%d%H%M%S')}_{uuid.uuid4().hex[:8]}"
        total_enviado = 0
        lote_numero = 1

        for i in range(0, len(ids), tamanho_lote):
            lote = ids[i:i + tamanho_lote]

            envio = requests.post(
                url_php,
                params=params_site({
                    "tabela": tabela_php,
                    "sync_id": sync_id,
                    "iniciar": "1" if lote_numero == 1 else "0",
                    **params_php
                }),
                json=aplicar_empresa_lista(lote),
                timeout=600
            )
            envio.raise_for_status()

            print(f"Resposta do servidor {nome} lote {lote_numero}:")
            print(envio.text)

            total_enviado += len(lote)
            lote_numero += 1

        envio_final = requests.post(
            url_php,
            params=params_site({
                "tabela": tabela_php,
                "sync_id": sync_id,
                "finalizar": "1",
                "confirmar_vazio": "1" if len(ids) == 0 else "0",
                **params_php
            }),
            json=[],
            timeout=600
        )
        envio_final.raise_for_status()

        print(f"Resposta do servidor {nome} ativos:")
        print(envio_final.text)

        registrar_log(nome, total_enviado, "OK", envio_final.text)

    except Exception as e:
        print(f"Erro ao verificar {nome} excluidos:", str(e))
        registrar_log(nome, 0, "ERRO", str(e))


def verificar_est008_ativos_lotes(tamanho_lote=1000):
    try:
        print("\nVerificando EST008 excluidos no Firebird em lotes...")

        url_api = "http://127.0.0.1:5000/dados/est008_ativos"
        url_php = f"{BASE_SITE}/modulos/tesouraria/receber_firebird.php"
        sync_id = f"est008_{datetime.datetime.now().strftime('%Y%m%d%H%M%S')}_{uuid.uuid4().hex[:8]}"
        item_inicio = 55908
        offset = 0
        total = 0
        lote_numero = 1

        while True:
            resposta = requests.get(
                url_api,
                params={
                    "limit": tamanho_lote,
                    "offset": offset,
                    "item_inicio": item_inicio
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
                params=params_site({
                    "tabela": "est008_ativos",
                    "sync_id": sync_id,
                    "iniciar": "1" if lote_numero == 1 else "0"
                }),
                json=aplicar_empresa_lista(ids),
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
            params=params_site({
                "tabela": "est008_ativos",
                "sync_id": sync_id,
                "finalizar": "1",
                "confirmar_vazio": "1" if total == 0 else "0"
            }),
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


def periodo_ultimos_dias(dias):
    fim = datetime.datetime.now()
    inicio = fim - datetime.timedelta(days=dias)

    return {
        "inicio": inicio.strftime("%Y-%m-%d 00:00:00"),
        "fim": fim.strftime("%Y-%m-%d 23:59:59")
    }


def verificar_tabelas_ativos():
    est007_periodo = periodo_ultimos_dias(60)

    tabelas_ativos = [
        {
            "nome": "BNC002_ATIVOS",
            "url_api": "http://127.0.0.1:5000/dados/bnc002_ativos",
            "tabela_php": "bnc002_ativos"
        },
        {
            "nome": "BNC005_ATIVOS",
            "url_api": "http://127.0.0.1:5000/dados/bnc005_ativos",
            "tabela_php": "bnc005_ativos"
        },
        {
            "nome": "CP001_ATIVOS",
            "url_api": "http://127.0.0.1:5000/dados/cp001_ativos",
            "tabela_php": "cp001_ativos"
        },
        {
            "nome": "CP003_ATIVOS",
            "url_api": "http://127.0.0.1:5000/dados/cp003_ativos",
            "tabela_php": "cp003_ativos"
        },
        {
            "nome": "CP004_ATIVOS",
            "url_api": "http://127.0.0.1:5000/dados/cp004_ativos",
            "tabela_php": "cp004_ativos"
        },
        {
            "nome": "EST004_ATIVOS",
            "url_api": "http://127.0.0.1:5000/dados/est004_ativos",
            "tabela_php": "est004_ativos"
        },
        {
            "nome": "EST005_ATIVOS",
            "url_api": "http://127.0.0.1:5000/dados/est005_ativos",
            "tabela_php": "est005_ativos"
        },
        {
            "nome": "EST006_ATIVOS",
            "url_api": "http://127.0.0.1:5000/dados/est006_ativos",
            "tabela_php": "est006_ativos"
        },
        {
            "nome": "CR002_ATIVOS",
            "url_api": "http://127.0.0.1:5000/dados/cr002_ativos",
            "tabela_php": "cr002_ativos"
        },
        {
            "nome": "ZCONFIG005_ATIVOS",
            "url_api": "http://127.0.0.1:5000/dados/zconfig005_ativos",
            "tabela_php": "zconfig005_ativos"
        },
        {
            "nome": "EST007_ATIVOS",
            "url_api": "http://127.0.0.1:5000/dados/est007_ativos",
            "tabela_php": "est007_ativos",
            "params_api": est007_periodo,
            "params_php": est007_periodo
        }
    ]

    for config in tabelas_ativos:
        enviar_ativos(
            config["nome"],
            config["url_api"],
            config["tabela_php"],
            params_api=config.get("params_api"),
            params_php=config.get("params_php")
        )

    verificar_est008_ativos_lotes(5000)


print("INICIANDO ENVIO FIREBIRD PARA MYSQL")
print(f"Modo de sincronizacao: {MODO}")

try:
    print("\nProcessando BNC001...")

    url_ultima = f"{BASE_SITE}/modulos/tesouraria/ultimo_regstamp_bnc001.php"
    resp_ultima = requests.get(url_ultima, params=params_site(), timeout=60)
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
        url_php = f"{BASE_SITE}/modulos/tesouraria/receber_firebird.php"
        tamanho_lote_bnc001 = 5000
        total_enviado_bnc001 = 0

        for i in range(0, len(dados), tamanho_lote_bnc001):
            lote = dados[i:i + tamanho_lote_bnc001]

            print(f"Enviando BNC001 lote {i // tamanho_lote_bnc001 + 1} com {len(lote)} registros...")

            envio = requests.post(
                url_php,
                params=params_site({"tabela": "bnc001"}),
                json=aplicar_empresa_lista(lote),
                timeout=600
            )
            envio.raise_for_status()

            resposta_texto = envio.text

            print("Resposta do servidor incremental:")
            print(resposta_texto)

            total_enviado_bnc001 += len(lote)

        registrar_log("BNC001", total_enviado_bnc001, "OK", f"Lotes enviados com sucesso: {total_enviado_bnc001}")

    else:
        print("Nenhum registro novo ou alterado.")
        registrar_log("BNC001", 0, "OK", "Sem novos dados")

    if EXECUTAR_COMPLETO:
        try:
            print("\nBuscando todos MOVCONTADOR para detectar deletados...")

            url_ids = "http://127.0.0.1:5000/dados/bnc001_ids"
            resp_ids = requests.get(url_ids, timeout=300)
            resp_ids.raise_for_status()

            ids = resp_ids.json()

            if not isinstance(ids, list):
                raise Exception("Erro ao obter lista de IDs")

            print(f"Total IDs atuais no Firebird: {len(ids)}")

            url_php_ids = f"{BASE_SITE}/modulos/tesouraria/receber_ids_bnc001.php"

            envio_ids = requests.post(url_php_ids, params=params_site({"token": "123456"}), json=ids, timeout=600)
            envio_ids.raise_for_status()

            print("Resposta do servidor deletados:")
            print(envio_ids.text)

            registrar_log("BNC001_IDS", len(ids), "OK", "Lista de IDs enviada")
        except Exception as e:
            print("Erro ao verificar BNC001 excluidos:", str(e))
            registrar_log("BNC001_IDS", 0, "ERRO", str(e))
    else:
        print("\nModo rapido: pulando verificacao de excluidos BNC001.")

    processar_tabela(
        "BNC002",
        "http://127.0.0.1:5000/dados/bnc002",
        "bnc002"
    )

    processar_tabela(
        "BNC005",
        "http://127.0.0.1:5000/dados/bnc005",
        "bnc005"
    )

    processar_tabela(
        "CR001",
        "http://127.0.0.1:5000/dados/cr001",
        "cr001"
    )

    if EXECUTAR_COMPLETO:
        enviar_cr001_ativos()
    else:
        print("Modo rapido: pulando verificacao de excluidos CR001.")

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

    if EXECUTAR_COMPLETO:
        processar_tabela(
            "CP004",
            "http://127.0.0.1:5000/dados/cp004",
            "cp004"
        )
    else:
        print("Modo rapido: pulando CP004, pois esta tabela nao possui REGSTAMP confiavel.")

    processar_tabela(
        "ZCONFIG005",
        "http://127.0.0.1:5000/dados/zconfig005",
        "zconfig005"
    )

    processar_tabela(
        "REP001",
        "http://127.0.0.1:5000/dados/rep001",
        "rep001",
        forcar_completo=True
    )

    processar_tabela(
        "FUNC001",
        "http://127.0.0.1:5000/dados/func001",
        "func001",
        forcar_completo=True
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
        5000,
        params_api={"item_inicio": 55908}
    )

    if EXECUTAR_COMPLETO:
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

        verificar_tabelas_ativos()
    else:
        print("\nModo rapido: pulando verificacao de excluidos das tabelas grandes.")
        print("Modo rapido: pulando EST004/EST005/EST006.")

except Exception as e:
    print("Erro geral:", str(e))
    registrar_log("GERAL", 0, "ERRO", str(e))

print("\nFINALIZADO")
