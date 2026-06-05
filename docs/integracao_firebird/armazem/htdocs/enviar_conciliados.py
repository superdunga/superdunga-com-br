import requests

# API PHP (origem)
url_php = "https://www.superdunga.com.br/modulos/fechamentodecaixa/retornar_conciliacao.php"

# API FIREBIRD (destino)
url_firebird = "http://127.0.0.1:5000/update/cr001"

# API MARCAR ENVIADO
url_marcar = "https://www.superdunga.com.br/modulos/fechamentodecaixa/marcar_enviado.php"

print("Buscando dados conciliados...")

try:
    response = requests.get(url_php)
    dados = response.json()

    print(f"Registros encontrados: {len(dados)}")

    if not dados:
        print("Nada para enviar.")
        exit()

    envio = []

    for item in dados:
        envio.append({
            "CRCONTADOR": int(item["CRCONTADOR"]),
            "CHAVEINTEGRACAO": int(item["recebimento_id"]),
            "CMCONTADOR": int(item["CMCONTADOR"]),
            "DTVENC": None
        })

    print("Enviando para Firebird...")

    resposta = requests.post(url_firebird, json=envio)

    print("Resposta da API:")
    print(resposta.text)

    print("Marcando registros como enviados...")

    resposta2 = requests.post(url_marcar, json=dados)

    print("Resposta do MySQL:")
    print(resposta2.text)

except Exception as e:
    print("Erro:", str(e))