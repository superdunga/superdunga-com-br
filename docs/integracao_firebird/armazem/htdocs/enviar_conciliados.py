import requests


TOKEN_SITE = "123456"
BASE_SITE = "https://www.superdunga.com.br"
API_LOCAL = "http://127.0.0.1:5000"
EMPRESA_SUPERDUNGA = 1
EMPRESA_FIREBIRD = 1
TAMANHO_LOTE = 500


def buscar_conciliados():
    registros = []
    offset = 0
    while True:
        resposta = requests.get(
            f"{BASE_SITE}/modulos/tesouraria/listar_conciliados_firebird.php",
            params={"token": TOKEN_SITE, "empresa": EMPRESA_SUPERDUNGA, "limit": TAMANHO_LOTE, "offset": offset},
            timeout=120,
        )
        resposta.raise_for_status()
        lote = resposta.json().get("registros", [])
        registros.extend(lote)
        if len(lote) < TAMANHO_LOTE:
            return registros
        offset += TAMANHO_LOTE


def confirmar_resultados(resultados):
    if not resultados:
        return
    resposta = requests.post(
        f"{BASE_SITE}/modulos/fechamentodecaixa/marcar_enviado.php",
        json={"empresa": EMPRESA_SUPERDUNGA, "resultados": resultados},
        timeout=120,
    )
    resposta.raise_for_status()
    print("Confirmacao no SuperDunga:", resposta.json())


def enviar_conciliados(registros):
    for inicio in range(0, len(registros), TAMANHO_LOTE):
        lote = registros[inicio:inicio + TAMANHO_LOTE]
        envio = []
        for registro in lote:
            item = dict(registro)
            item["FIREBIRD_EMPRESA"] = EMPRESA_FIREBIRD
            envio.append(item)

        resposta = requests.post(f"{API_LOCAL}/update/cr001", json=envio, timeout=300)
        resposta.raise_for_status()
        retorno = resposta.json()
        if retorno.get("erro"):
            raise Exception(retorno["erro"])
        resultados = retorno.get("resultados", [])
        if len(resultados) != len(lote):
            raise Exception(f"Firebird confirmou {len(resultados)} de {len(lote)} registros")
        confirmar_resultados(resultados)


def processar_desvinculos():
    resposta = requests.get(
        f"{BASE_SITE}/modulos/tesouraria/listar_desvinculos_firebird.php",
        params={"token": TOKEN_SITE, "empresa": EMPRESA_SUPERDUNGA, "limit": TAMANHO_LOTE},
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
        item["FIREBIRD_EMPRESA"] = EMPRESA_FIREBIRD
        envio.append(item)
    retorno = requests.post(f"{API_LOCAL}/update/cr001", json=envio, timeout=300)
    retorno.raise_for_status()
    resultados = retorno.json().get("resultados", [])
    confirmacao = requests.post(
        f"{BASE_SITE}/modulos/tesouraria/marcar_desvinculos_firebird.php",
        json={"token": TOKEN_SITE, "empresa": EMPRESA_SUPERDUNGA, "resultados": resultados},
        timeout=120,
    )
    confirmacao.raise_for_status()
    return len(resultados)


print("INICIANDO ENVIO DE CONCILIADOS PARA FIREBIRD ARMAZEM")
try:
    conciliados = buscar_conciliados()
    print(f"Registros pendentes: {len(conciliados)}")
    enviar_conciliados(conciliados)
    print(f"Desvinculos processados: {processar_desvinculos()}")
    print("FINALIZADO")
except Exception as erro:
    print("ERRO:", str(erro))
    raise
