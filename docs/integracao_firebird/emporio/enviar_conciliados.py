import requests


EMPRESA_DESTINO = 4
TOKEN_SITE = "123456"
BASE_SITE = "https://www.superdunga.com.br"
API_LOCAL = "http://127.0.0.1:5000"
TAMANHO_LOTE = 500


def buscar_lote(offset):
    url = f"{BASE_SITE}/modulos/tesouraria/listar_conciliados_firebird.php"
    resposta = requests.get(
        url,
        params={
            "token": TOKEN_SITE,
            "empresa": EMPRESA_DESTINO,
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


def enviar_para_firebird(registros):
    if not registros:
        return 0

    url = f"{API_LOCAL}/update/cr001"
    resposta = requests.post(url, json=registros, timeout=300)
    resposta.raise_for_status()

    dados = resposta.json()
    if isinstance(dados, dict) and dados.get("erro"):
        raise Exception(dados["erro"])

    print("Resposta API local:", dados)
    return int(dados.get("atualizados", 0))


print("INICIANDO ENVIO DE CONCILIADOS PARA FIREBIRD EMPORIO")

offset = 0
total_lidos = 0
total_atualizados = 0
lote_numero = 1

while True:
    lote = buscar_lote(offset)
    if not lote:
        break

    print(f"Lote {lote_numero}: {len(lote)} registros")
    atualizados = enviar_para_firebird(lote)

    total_lidos += len(lote)
    total_atualizados += atualizados
    offset += TAMANHO_LOTE
    lote_numero += 1

print(f"Total lidos no site: {total_lidos}")
print(f"Total atualizados no Firebird: {total_atualizados}")
print("FINALIZADO")
