# -*- coding: utf-8 -*-
"""
Rotina isolada para atualizar somente PVENDA1 e PVENDA2 da armazem_est004
da empresa 4 com os valores atuais da EST004 do Firebird do Emporio.

Esta rotina NAO faz parte da sincronizacao normal e NAO deve ser colocada no
Agendador. Use apenas sob demanda.

Modo recomendado: gerar SQL para conferencia/importacao:
python rotina_isolada_atualizar_precos_empresa4.py --gerar-sql C:\\temp\\precos_empresa4.sql

Modo direto MySQL, se o servidor permitir conexao remota e pymysql estiver instalado:
python rotina_isolada_atualizar_precos_empresa4.py --executar --mysql-host HOST --mysql-db DB --mysql-user USER --mysql-password SENHA
"""

import argparse
from decimal import Decimal, InvalidOperation

import fdb


def decimal_or_none(valor):
    if valor is None:
        return None
    try:
        return Decimal(str(valor))
    except (InvalidOperation, TypeError, ValueError):
        return None


def sql_decimal(valor):
    valor_decimal = decimal_or_none(valor)
    if valor_decimal is None:
        return "NULL"
    return str(valor_decimal)


def sql_string(valor):
    texto = str(valor or "")
    return "'" + texto.replace("'", "''") + "'"


def conectar_firebird(host, fdb_path, usuario, senha):
    return fdb.connect(
        dsn=f"{host}:{fdb_path}",
        user=usuario,
        password=senha,
        charset="UTF8",
    )


def buscar_precos_firebird(con, firebird_empresa, codproduto=None):
    cursor = con.cursor()
    where_codigo = ""
    params = [firebird_empresa]

    if codproduto:
        where_codigo = " AND CODPRODUTO = ?"
        params.append(codproduto)

    cursor.execute(
        f"""
        SELECT CODPRODUTO, PVENDA1, PVENDA2
        FROM EST004
        WHERE EMPRESA = ?
          AND CODPRODUTO IS NOT NULL
          {where_codigo}
        ORDER BY CODPRODUTO
        """,
        tuple(params),
    )

    registros = []
    for codproduto_fb, pvenda1, pvenda2 in cursor.fetchall():
        codproduto_texto = str(codproduto_fb or "").strip()
        if not codproduto_texto:
            continue
        registros.append({
            "CODPRODUTO": codproduto_texto,
            "PVENDA1": decimal_or_none(pvenda1),
            "PVENDA2": decimal_or_none(pvenda2),
        })
    return registros


def gerar_sql(registros, empresa_destino):
    linhas = [
        "-- Atualizacao isolada de PVENDA1/PVENDA2 da armazem_est004",
        f"-- Empresa destino SuperDunga: {empresa_destino}",
        "START TRANSACTION;",
    ]

    for item in registros:
        linhas.append(
            "UPDATE armazem_est004 "
            f"SET PVENDA1 = {sql_decimal(item['PVENDA1'])}, "
            f"PVENDA2 = {sql_decimal(item['PVENDA2'])} "
            f"WHERE EMPRESA = {int(empresa_destino)} "
            f"AND CODPRODUTO = {sql_string(item['CODPRODUTO'])};"
        )

    linhas.append("COMMIT;")
    return "\n".join(linhas) + "\n"


def executar_mysql(registros, empresa_destino, args):
    try:
        import pymysql
    except ImportError as exc:
        raise RuntimeError("pymysql nao esta instalado. Use --gerar-sql ou instale pymysql.") from exc

    con = pymysql.connect(
        host=args.mysql_host,
        port=args.mysql_port,
        user=args.mysql_user,
        password=args.mysql_password,
        database=args.mysql_db,
        charset="utf8mb4",
        autocommit=False,
    )

    atualizados = 0
    try:
        with con.cursor() as cursor:
            for item in registros:
                cursor.execute(
                    """
                    UPDATE armazem_est004
                    SET PVENDA1 = %s,
                        PVENDA2 = %s
                    WHERE EMPRESA = %s
                      AND CODPRODUTO = %s
                    """,
                    (
                        item["PVENDA1"],
                        item["PVENDA2"],
                        empresa_destino,
                        item["CODPRODUTO"],
                    ),
                )
                atualizados += cursor.rowcount
        con.commit()
    except Exception:
        con.rollback()
        raise
    finally:
        con.close()

    return atualizados


def main():
    parser = argparse.ArgumentParser(description="Atualiza somente PVENDA1/PVENDA2 da empresa 4.")
    parser.add_argument("--empresa-destino", type=int, default=4)
    parser.add_argument("--firebird-empresa", type=int, default=1)
    parser.add_argument("--fdb", default=r"C:\Adm_EmporioDunga\Data\ESTOQUE.FDB")
    parser.add_argument("--host", default="localhost")
    parser.add_argument("--usuario", default="SYSDBA")
    parser.add_argument("--senha", default="masterkey")
    parser.add_argument("--codproduto", default=None, help="Opcional. Exemplo: 000819")
    parser.add_argument("--gerar-sql", default=None, help="Caminho do arquivo SQL a gerar")
    parser.add_argument("--executar", action="store_true", help="Executa direto no MySQL")
    parser.add_argument("--mysql-host", default=None)
    parser.add_argument("--mysql-port", type=int, default=3306)
    parser.add_argument("--mysql-db", default=None)
    parser.add_argument("--mysql-user", default=None)
    parser.add_argument("--mysql-password", default=None)
    args = parser.parse_args()

    if not args.gerar_sql and not args.executar:
        raise RuntimeError("Informe --gerar-sql CAMINHO ou --executar.")

    if args.executar:
        faltando = [
            nome for nome, valor in [
                ("--mysql-host", args.mysql_host),
                ("--mysql-db", args.mysql_db),
                ("--mysql-user", args.mysql_user),
                ("--mysql-password", args.mysql_password),
            ]
            if not valor
        ]
        if faltando:
            raise RuntimeError("Para --executar informe: " + ", ".join(faltando))

    print("Lendo precos atuais no Firebird...")
    print(f"Firebird empresa: {args.firebird_empresa}")
    print(f"SuperDunga empresa destino: {args.empresa_destino}")

    con_fb = conectar_firebird(args.host, args.fdb, args.usuario, args.senha)
    try:
        registros = buscar_precos_firebird(con_fb, args.firebird_empresa, args.codproduto)
    finally:
        con_fb.close()

    print(f"Produtos encontrados no Firebird: {len(registros)}")
    if not registros:
        return

    if args.gerar_sql:
        conteudo = gerar_sql(registros, args.empresa_destino)
        with open(args.gerar_sql, "w", encoding="utf-8") as arquivo:
            arquivo.write(conteudo)
        print(f"SQL gerado em: {args.gerar_sql}")

    if args.executar:
        atualizados = executar_mysql(registros, args.empresa_destino, args)
        print(f"Linhas atualizadas no MySQL: {atualizados}")

    print("Finalizado.")


if __name__ == "__main__":
    main()
