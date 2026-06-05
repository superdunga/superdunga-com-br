import requests


EMPRESA_DESTINO = 1
BASE_SITE = "https://www.superdunga.com.br"
ULTIMA_REGSTAMP = "2026-05-13 16:50:29"
TAMANHO_LOTE = 5000


def params_site(params=None):
    base = {"empresa": EMPRESA_DESTINO}
    if params:
        base.update(params)
    return base


def registrar_log(tabela, registros, status, mensagem):
    try:
        requests.post(
            f"{BASE_SITE}/modulos/tesouraria/log_sync.php",
            json={
                "empresa": EMPRESA_DESTINO,
                "tabela": tabela,
                "registros": registros,
                "status": status,
                "mensagem": mensagem,
            },
            timeout=10,
        )
    except Exception as e:
        print("Erro ao registrar log:", str(e))


try:
    print("RECUPERANDO BNC001 ARMAZEM")
    print("Empresa:", EMPRESA_DESTINO)
    print("Buscando REGSTAMP maior que:", ULTIMA_REGSTAMP)

    resposta = requests.get(
        "http://127.0.0.1:5000/dados/bnc001",
        params={"ultima_regstamp": ULTIMA_REGSTAMP},
        timeout=300,
    )
    resposta.raise_for_status()

    dados = resposta.json()

    if isinstance(dados, dict) and dados.get("erro"):
        raise Exception(dados["erro"])

    print("Registros encontrados:", len(dados))

    if not dados:
        registrar_log("BNC001_RECUPERACAO", 0, "OK", "Sem dados para recuperar")
        print("Nada para enviar.")
        raise SystemExit

    total_enviado = 0
    url_php = f"{BASE_SITE}/modulos/tesouraria/receber_firebird.php"

    for i in range(0, len(dados), TAMANHO_LOTE):
        lote = dados[i:i + TAMANHO_LOTE]
        print(f"Enviando lote {i // TAMANHO_LOTE + 1} com {len(lote)} registros...")

        envio = requests.post(
            url_php,
            params=params_site({"tabela": "bnc001"}),
            json=lote,
            timeout=600,
        )
        envio.raise_for_status()

        print("Resposta do servidor:")
        print(envio.text)

        total_enviado += len(lote)

    registrar_log(
        "BNC001_RECUPERACAO",
        total_enviado,
        "OK",
        f"Recuperacao enviada desde {ULTIMA_REGSTAMP}: {total_enviado}",
    )
    print("Finalizado. Total enviado:", total_enviado)

except Exception as e:
    print("Erro na recuperacao BNC001:", str(e))
    registrar_log("BNC001_RECUPERACAO", 0, "ERRO", str(e))
