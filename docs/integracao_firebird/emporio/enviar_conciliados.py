import requests


MAPEAMENTOS = [
    {"firebird": 1, "superdunga": 4, "nome": "EMPORIO DUNGA"},
    {"firebird": 6, "superdunga": 5, "nome": "CMX"},
]
TOKEN_SITE = "123456"
BASE_SITE = "https://www.superdunga.com.br"
API_LOCAL = "http://127.0.0.1:5000"
TAMANHO_LOTE = 500


def buscar_conciliados(empresa):
    registros = []
    offset = 0
    while True:
        resposta = requests.get(
            f"{BASE_SITE}/modulos/tesouraria/listar_conciliados_firebird.php",
            params={"token": TOKEN_SITE, "empresa": empresa, "limit": TAMANHO_LOTE, "offset": offset},
            timeout=120,
        )
        resposta.raise_for_status()
        lote = resposta.json().get("registros", [])
        registros.extend(lote)
        if len(lote) < TAMANHO_LOTE:
            return registros
        offset += TAMANHO_LOTE


def confirmar_resultados(empresa, resultados):
    if not resultados:
        return
    resposta = requests.post(
        f"{BASE_SITE}/modulos/fechamentodecaixa/marcar_enviado.php",
        json={"empresa": empresa, "resultados": resultados},
        timeout=120,
    )
    resposta.raise_for_status()
    print("Confirmacao no SuperDunga:", resposta.json())


def enviar_conciliados(registros, empresa_superdunga, empresa_firebird):
    total = 0
    for inicio in range(0, len(registros), TAMANHO_LOTE):
        lote = registros[inicio:inicio + TAMANHO_LOTE]
        envio = []
        for registro in lote:
            item = dict(registro)
            item["FIREBIRD_EMPRESA"] = empresa_firebird
            envio.append(item)
        resposta = requests.post(f"{API_LOCAL}/update/cr001", json=envio, timeout=300)
        resposta.raise_for_status()
        retorno = resposta.json()
        if retorno.get("erro"):
            raise Exception(retorno["erro"])
        resultados = retorno.get("resultados", [])
        if len(resultados) != len(lote):
            raise Exception(f"Firebird confirmou {len(resultados)} de {len(lote)} registros")
        confirmar_resultados(empresa_superdunga, resultados)
        total += sum(1 for item in resultados if item.get("status") == "SINCRONIZADO")
    return total


def processar_desvinculos(empresa_superdunga, empresa_firebird):
    resposta = requests.get(
        f"{BASE_SITE}/modulos/tesouraria/listar_desvinculos_firebird.php",
        params={"token": TOKEN_SITE, "empresa": empresa_superdunga, "limit": TAMANHO_LOTE},
        timeout=120,
    )
    resposta.raise_for_status()
    registros = resposta.json().get("registros", [])
    if not registros:
        return 0
    envio = []
    for registro in registros:
        item = dict(registro)
        item["OPERACAO"] = "DESVINCULAR"
        item["FIREBIRD_EMPRESA"] = empresa_firebird
        envio.append(item)
    resposta = requests.post(f"{API_LOCAL}/update/cr001", json=envio, timeout=300)
    resposta.raise_for_status()
    resultados = resposta.json().get("resultados", [])
    confirmacao = requests.post(
        f"{BASE_SITE}/modulos/tesouraria/marcar_desvinculos_firebird.php",
        json={"token": TOKEN_SITE, "empresa": empresa_superdunga, "resultados": resultados},
        timeout=120,
    )
    confirmacao.raise_for_status()
    return len(resultados)


print("INICIANDO ENVIO DE CONCILIADOS PARA FIREBIRD EMPORIO/CMX")
for mapeamento in MAPEAMENTOS:
    print("")
    print(
        f"Processando {mapeamento['nome']}: SuperDunga empresa "
        f"{mapeamento['superdunga']} -> Firebird empresa {mapeamento['firebird']}"
    )
    conciliados = buscar_conciliados(mapeamento["superdunga"])
    print(f"Registros pendentes: {len(conciliados)}")
    total = enviar_conciliados(conciliados, mapeamento["superdunga"], mapeamento["firebird"])
    print(f"Registros confirmados: {total}")
    desvinculos = processar_desvinculos(mapeamento["superdunga"], mapeamento["firebird"])
    print(f"Desvinculos processados: {desvinculos}")
print("FINALIZADO")
