# -*- coding: utf-8 -*-
"""
Atualiza EST004.EMB_QTDE no Firebird a partir dos recebimentos de mercadorias
finalizados no SuperDunga.

Exemplo:
python atualizar_embalagens_firebird.py --empresa 4 --fdb "C:\\Adm_EmporioDunga\\Data\\ESTOQUE.FDB"
"""

import argparse
from decimal import Decimal, InvalidOperation

import fdb
import requests


def decimal4(valor):
    try:
        return Decimal(str(valor)).quantize(Decimal("0.0001"))
    except (InvalidOperation, TypeError):
        return Decimal("0.0000")


def conectar_firebird(host, fdb_path, usuario, senha):
    return fdb.connect(
        dsn=f"{host}:{fdb_path}",
        user=usuario,
        password=senha,
        charset="UTF8",
    )


def buscar_pendencias(base_url, token, empresa, limit):
    url = f"{base_url.rstrip('/')}/modulos/rotinas_operacionais/listar_embalagens_firebird.php"
    resposta = requests.get(
        url,
        params={"token": token, "empresa": empresa, "limit": limit},
        timeout=60,
    )
    resposta.raise_for_status()
    dados = resposta.json()
    if dados.get("status") != "ok":
        raise RuntimeError(dados.get("erro") or "Resposta invalida ao buscar pendencias")
    return dados.get("registros") or []


def marcar_resultados(base_url, token, resultados):
    if not resultados:
        return {"status": "ok", "atualizados": 0, "erros": 0}

    url = f"{base_url.rstrip('/')}/modulos/rotinas_operacionais/marcar_embalagens_firebird.php"
    resposta = requests.post(
        url,
        params={"token": token},
        json={"registros": resultados},
        timeout=60,
    )
    resposta.raise_for_status()
    return resposta.json()


def atualizar_firebird(con, empresa, registros):
    cursor = con.cursor()
    resultados = []

    for item in registros:
        item_id = int(item.get("id") or 0)
        codproduto = str(item.get("codproduto") or "").strip()
        qtd_nova = decimal4(item.get("emb_qtde_nova"))

        if item_id <= 0 or not codproduto or qtd_nova <= 0:
            resultados.append({
                "id": item_id,
                "status": "erro",
                "erro": "Registro sem ID, produto ou quantidade valida.",
            })
            continue

        try:
            cursor.execute(
                """
                SELECT EMB_QTDE
                FROM EST004
                WHERE EMPRESA = ?
                  AND CODPRODUTO = ?
                """,
                (empresa, codproduto),
            )
            row = cursor.fetchone()
            if not row:
                resultados.append({
                    "id": item_id,
                    "status": "erro",
                    "erro": f"Produto {codproduto} nao encontrado no Firebird.",
                })
                continue

            qtd_atual = decimal4(row[0])
            if qtd_atual == qtd_nova:
                resultados.append({"id": item_id, "status": "ok"})
                continue

            cursor.execute(
                """
                UPDATE EST004
                SET EMB_QTDE = ?
                WHERE EMPRESA = ?
                  AND CODPRODUTO = ?
                """,
                (qtd_nova, empresa, codproduto),
            )
            resultados.append({"id": item_id, "status": "ok"})
        except Exception as exc:
            resultados.append({
                "id": item_id,
                "status": "erro",
                "erro": str(exc)[:255],
            })

    con.commit()
    return resultados


def main():
    parser = argparse.ArgumentParser(description="Atualiza EMB_QTDE no Firebird pelo SuperDunga.")
    parser.add_argument("--empresa", type=int, required=True)
    parser.add_argument("--fdb", required=True, help="Caminho do arquivo ESTOQUE.FDB no servidor Firebird")
    parser.add_argument("--host", default="localhost")
    parser.add_argument("--usuario", default="SYSDBA")
    parser.add_argument("--senha", default="masterkey")
    parser.add_argument("--base-url", default="https://www.superdunga.com.br")
    parser.add_argument("--token", default="123456")
    parser.add_argument("--limit", type=int, default=200)
    args = parser.parse_args()

    print(f"Buscando pendencias de embalagem da empresa {args.empresa}...")
    pendencias = buscar_pendencias(args.base_url, args.token, args.empresa, args.limit)
    print(f"Pendencias encontradas: {len(pendencias)}")

    if not pendencias:
        return

    con = conectar_firebird(args.host, args.fdb, args.usuario, args.senha)
    try:
        resultados = atualizar_firebird(con, args.empresa, pendencias)
    finally:
        con.close()

    retorno = marcar_resultados(args.base_url, args.token, resultados)
    ok = sum(1 for r in resultados if r.get("status") == "ok")
    erros = len(resultados) - ok
    print(f"Processados: {len(resultados)} | OK: {ok} | Erros: {erros}")
    print(f"Retorno SuperDunga: {retorno}")


if __name__ == "__main__":
    main()
