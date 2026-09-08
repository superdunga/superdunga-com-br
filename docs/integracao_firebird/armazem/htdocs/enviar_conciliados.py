import requests

# API PHP (origem)
url_php = "https://www.superdunga.com.br/modulos/fechamentodecaixa/retornar_conciliacao.php"

# API FIREBIRD (destino)
url_firebird = "http://127.0.0.1:5000/update/cr001"

# API MARCAR ENVIADO
url_marcar = "https://www.superdunga.com.br/modulos/fechamentodecaixa/marcar_enviado.php"
url_listar_desvinculos = "https://www.superdunga.com.br/modulos/tesouraria/listar_desvinculos_firebird.php"
url_marcar_desvinculos = "https://www.superdunga.com.br/modulos/tesouraria/marcar_desvinculos_firebird.php"
token = "123456"

print("Buscando dados conciliados...")

try:
    response = requests.get(url_php)
    dados = response.json()

    print(f"Registros encontrados: {len(dados)}")

    if dados:
        envio = []

        for item in dados:
            envio.append({
                "CRCONTADOR": int(item["CRCONTADOR"]),
                "CHAVEINTEGRACAO": int(item["recebimento_id"]),
                "CMCONTADOR": int(item["CMCONTADOR"]),
                "DTVENC": None
            })

        print("Enviando para Firebird...")
        resposta = requests.post(url_firebird, json=envio, timeout=300)
        resposta.raise_for_status()
        print("Resposta da API:")
        print(resposta.text)

        print("Marcando registros como enviados...")
        resposta2 = requests.post(url_marcar, json=dados, timeout=120)
        resposta2.raise_for_status()
        print("Resposta do MySQL:")
        print(resposta2.text)
    else:
        print("Nenhum vinculo novo para enviar.")

    pendentes = requests.get(
        url_listar_desvinculos,
        params={"token": token, "empresa": 1, "limit": 500},
        timeout=120,
    )
    pendentes.raise_for_status()
    desvinculos = pendentes.json().get("registros", [])

    if desvinculos:
        envio_desvinculos = []
        for item in desvinculos:
            registro = dict(item)
            registro["OPERACAO"] = "DESVINCULAR"
            registro["FIREBIRD_EMPRESA"] = 1
            envio_desvinculos.append(registro)

        retorno = requests.post(url_firebird, json=envio_desvinculos, timeout=300)
        retorno.raise_for_status()
        resultados = retorno.json().get("resultados", [])
        confirmacao = requests.post(
            url_marcar_desvinculos,
            json={"token": token, "empresa": 1, "resultados": resultados},
            timeout=120,
        )
        confirmacao.raise_for_status()
        print(f"Desvinculos processados: {len(resultados)}")

except Exception as e:
    print("Erro:", str(e))
