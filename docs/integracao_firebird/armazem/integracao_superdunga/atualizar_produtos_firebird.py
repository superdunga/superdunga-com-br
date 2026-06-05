# -*- coding: utf-8 -*-
"""
Envia cadastros leves de estoque alterados no Firebird para o SuperDunga.

Uso Emporio:
python atualizar_produtos_firebird.py --empresa 4 --firebird-empresa 1

Uso Armazem:
python atualizar_produtos_firebird.py --empresa 1 --firebird-empresa 1
"""

import argparse
from datetime import datetime, timedelta

import requests


TABELAS_PADRAO = ["est004", "est005", "est006"]


def buscar_ultima_regstamp(base_url, empresa, tabela):
    url = f"{base_url.rstrip('/')}/modulos/tesouraria/ultimo_regstamp.php"
    resposta = requests.get(
        url,
        params={"tabela": tabela, "empresa": empresa},
        timeout=60,
    )
    resposta.raise_for_status()
    dados = resposta.json()
    if dados.get("erro"):
        raise RuntimeError(dados["erro"])
    return dados.get("ultima_regstamp") or "1900-01-01 00:00:00"


def aplicar_janela(ultima_regstamp, janela_dias):
    if janela_dias <= 0:
        return ultima_regstamp

    try:
        data = datetime.strptime(ultima_regstamp[:19], "%Y-%m-%d %H:%M:%S")
    except ValueError:
        return ultima_regstamp

    return (data - timedelta(days=janela_dias)).strftime("%Y-%m-%d %H:%M:%S")


def buscar_registros(api_url, tabela, ultima_regstamp):
    url = f"{api_url.rstrip('/')}/dados/{tabela}"
    resposta = requests.get(
        url,
        params={"ultima_regstamp": ultima_regstamp},
        timeout=300,
    )
    resposta.raise_for_status()
    dados = resposta.json()
    if isinstance(dados, dict) and dados.get("erro"):
        raise RuntimeError(dados["erro"])
    if not isinstance(dados, list):
        raise RuntimeError(f"Resposta invalida da API Firebird para {tabela.upper()}.")
    return dados


def preparar_registros(dados, empresa_destino, firebird_empresa):
    registros = []

    for item in dados:
        if not isinstance(item, dict):
            continue

        try:
            empresa_item = int(item.get("EMPRESA") or 0)
        except (TypeError, ValueError):
            empresa_item = 0

        if firebird_empresa and empresa_item != firebird_empresa:
            continue

        item["EMPRESA"] = empresa_destino
        registros.append(item)

    return registros


def enviar_lotes(base_url, empresa, tabela, registros, tamanho_lote):
    if not registros:
        return 0

    total = 0
    url = f"{base_url.rstrip('/')}/modulos/tesouraria/receber_firebird.php"

    for inicio in range(0, len(registros), tamanho_lote):
        lote = registros[inicio:inicio + tamanho_lote]
        resposta = requests.post(
            url,
            params={"empresa": empresa, "tabela": tabela},
            json=lote,
            timeout=300,
        )
        resposta.raise_for_status()
        retorno = resposta.json()
        if retorno.get("erro"):
            raise RuntimeError(retorno["erro"])
        processados = int(retorno.get("processados") or 0)
        total += processados
        print(f"{tabela.upper()} lote {inicio // tamanho_lote + 1}: enviados {len(lote)} | processados {processados}")

    return total


def processar_tabela(args, tabela, firebird_empresa):
    ultima_regstamp = buscar_ultima_regstamp(args.base_url, args.empresa, tabela)
    regstamp_consulta = aplicar_janela(ultima_regstamp, args.janela_dias)

    print("")
    print(f"Processando {tabela.upper()}...")
    print(f"Ultima REGSTAMP no SuperDunga: {ultima_regstamp}")
    print(f"Consultando Firebird a partir de: {regstamp_consulta}")

    dados = buscar_registros(args.api_url, tabela, regstamp_consulta)
    registros = preparar_registros(dados, args.empresa, firebird_empresa)

    print(f"Registros retornados pela API: {len(dados)}")
    print(f"Registros preparados para envio: {len(registros)}")

    processados = enviar_lotes(args.base_url, args.empresa, tabela, registros, args.tamanho_lote)
    print(f"{tabela.upper()} finalizada. Processados no SuperDunga: {processados}")
    return processados


def main():
    parser = argparse.ArgumentParser(description="Atualiza EST004/EST005/EST006 no SuperDunga pelo Firebird local.")
    parser.add_argument("--empresa", type=int, required=True, help="Empresa no SuperDunga")
    parser.add_argument("--firebird-empresa", type=int, default=None, help="Empresa dentro do Firebird local")
    parser.add_argument("--base-url", default="https://www.superdunga.com.br")
    parser.add_argument("--api-url", default="http://127.0.0.1:5000")
    parser.add_argument("--janela-dias", type=int, default=1, help="Reprocessa uma janela para evitar perda por horario")
    parser.add_argument("--tamanho-lote", type=int, default=500)
    parser.add_argument("--tabelas", default=",".join(TABELAS_PADRAO), help="Tabelas separadas por virgula")
    args = parser.parse_args()

    firebird_empresa = args.firebird_empresa or args.empresa
    tabelas = [t.strip().lower() for t in args.tabelas.split(",") if t.strip()]
    tabelas_invalidas = [t for t in tabelas if t not in TABELAS_PADRAO]
    if tabelas_invalidas:
        raise RuntimeError(f"Tabelas invalidas: {', '.join(tabelas_invalidas)}")

    print(f"Atualizando estoque leve da empresa {args.empresa}...")
    print(f"Empresa Firebird usada: {firebird_empresa}")
    print(f"Tabelas: {', '.join(t.upper() for t in tabelas)}")

    totais = {}
    for tabela in tabelas:
        totais[tabela] = processar_tabela(args, tabela, firebird_empresa)

    resumo = " | ".join(f"{t.upper()}: {qtd}" for t, qtd in totais.items())
    print("")
    print(f"Finalizado. {resumo}")


if __name__ == "__main__":
    main()
