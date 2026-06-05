import requests

url = "https://www.superdunga.com.br/modulos/fechamentodecaixa/retornar_conciliacao.php"

print("Buscando dados do PHP...")

try:
    response = requests.get(url)
    dados = response.json()

    print(f"Registros recebidos: {len(dados)}")

    # mostra apenas os 3 primeiros
    for item in dados[:3]:
        print(item)

except Exception as e:
    print("Erro:", str(e))