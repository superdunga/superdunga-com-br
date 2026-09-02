import requests


MAPEAMENTOS = [
    {"firebird": 1, "superdunga": 4, "nome": "EMPORIO DUNGA"},
    {"firebird": 6, "superdunga": 5, "nome": "CMX"},
]
TOKEN_SITE = "123456"
BASE_SITE = "https://www.superdunga.com.br"
API_LOCAL = "http://127.0.0.1:5000"
TAMANHO_LOTE = 500


def buscar_lote(empresa_destino, offset):
    url = f"{BASE_SITE}/modulos/tesouraria/listar_conciliados_firebird.php"
    resposta = requests.get(
        url,
        params={
            "token": TOKEN_SITE,
            "empresa": empresa_destino,
            "limit": TAMANHO_LOTE,
            "offset": offset,
        },
        timeout=120,
    )
    resposta.raise_for_status()

    dados = resposta.json()
    if isinstance(dados, dict) and dados.get("erro"):
        raise Exception(dados["erro"])

    registros = dados.get("registros", [])
    if not isinstance(registros, list):
        raise Exception("Resposta invalida do site")

    return registros


def enviar_para_firebird(registros, firebird_empresa):
    if not registros:
        return 0

    url = f"{API_LOCAL}/update/cr001"
    registros_firebird = []
    for registro in registros:
        ajustado = dict(registro)
        ajustado["FIREBIRD_EMPRESA"] = firebird_empresa
        registros_firebird.append(ajustado)

    resposta = requests.post(url, json=registros_firebird, timeout=300)
    resposta.raise_for_status()

    dados = resposta.json()
    if isinstance(dados, dict) and dados.get("erro"):
        raise Exception(dados["erro"])

    print("Resposta API local:", dados)
    return int(dados.get("atualizados", 0))


print("INICIANDO ENVIO DE CONCILIADOS PARA FIREBIRD EMPORIO/CMX")

for mapeamento in MAPEAMENTOS:
    print("")
    print(
        f"Processando {mapeamento['nome']}: SuperDunga empresa "
        f"{mapeamento['superdunga']} -> Firebird empresa {mapeamento['firebird']}"
    )
    offset = 0
    total_lidos = 0
    total_atualizados = 0
    lote_numero = 1

    while True:
        lote = buscar_lote(mapeamento["superdunga"], offset)
        if not lote:
            break

        print(f"Lote {lote_numero}: {len(lote)} registros")
        atualizados = enviar_para_firebird(lote, mapeamento["firebird"])

        total_lidos += len(lote)
        total_atualizados += atualizados
        offset += TAMANHO_LOTE
        lote_numero += 1

    print(f"Total lidos no site: {total_lidos}")
    print(f"Total atualizados no Firebird: {total_atualizados}")

print("FINALIZADO")
