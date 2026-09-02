# -*- coding: utf-8 -*-
"""Sincroniza os saldos oficiais calculados pelas procedures do Firebird."""

import argparse

import requests


def main():
    parser = argparse.ArgumentParser(description="Sincroniza estoque geral, reservado e disponivel.")
    parser.add_argument("--empresa", type=int, required=True, help="Empresa no SuperDunga")
    parser.add_argument("--firebird-empresa", type=int, required=True, help="Empresa no Firebird local")
    parser.add_argument("--base-url", default="https://www.superdunga.com.br")
    parser.add_argument("--api-url", default="http://127.0.0.1:5000")
    parser.add_argument("--token", default="123456")
    parser.add_argument("--tamanho-lote", type=int, default=100)
    args = parser.parse_args()

    if (args.firebird_empresa, args.empresa) != (1, 1):
        raise RuntimeError("Mapeamento invalido para o servidor Firebird da empresa 1.")

    offset = 0
    total = 0
    lote_numero = 1

    while True:
        resposta = requests.get(
            f"{args.api_url.rstrip('/')}/dados/estoque_calculado",
            params={
                "empresa": args.firebird_empresa,
                "limit": args.tamanho_lote,
                "offset": offset,
            },
            timeout=600,
        )
        resposta.raise_for_status()
        registros = resposta.json()
        if isinstance(registros, dict) and registros.get("erro"):
            raise RuntimeError(registros["erro"])
        if not isinstance(registros, list):
            raise RuntimeError("Resposta invalida da API Firebird.")
        if not registros:
            break

        for registro in registros:
            registro["EMPRESA"] = args.empresa

        envio = requests.post(
            f"{args.base_url.rstrip('/')}/modulos/estoque/receber_saldos_firebird.php",
            params={"token": args.token, "empresa": args.empresa},
            json=registros,
            timeout=300,
        )
        envio.raise_for_status()
        retorno = envio.json()
        if retorno.get("erro"):
            raise RuntimeError(retorno["erro"])

        print(
            f"Lote {lote_numero}: retornados {len(registros)} | "
            f"atualizados {int(retorno.get('processados') or 0)}"
        )
        total += len(registros)
        offset += len(registros)
        lote_numero += 1
        if len(registros) < args.tamanho_lote:
            break

    print(
        f"Estoque calculado finalizado: Firebird {args.firebird_empresa} -> "
        f"SuperDunga {args.empresa} | {total} produtos"
    )


if __name__ == "__main__":
    main()
