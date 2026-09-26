"""Read-only EST007 and EST026 API for an auxiliary Firebird server."""

import os
import base64
import sys
import argparse
import uuid
from datetime import date, datetime, time
from decimal import Decimal

from firebird.driver import connect, driver_config
from flask import Flask, jsonify, request

app = Flask(__name__)
TABLES = {
    "est007_auxiliar": ("EST007", "VENDACONTADOR"),
    "est026_auxiliar": ("EST026", "NFCONTADOR"),
}
MAPPINGS = {"armazem": [(1, 1)], "emporio": [(1, 4), (6, 5)]}


def firebird_connection():
    database = os.environ.get("AUXILIAR_FIREBIRD_DSN")
    user = os.environ.get("AUXILIAR_FIREBIRD_USER")
    password = os.environ.get("AUXILIAR_FIREBIRD_PASSWORD")
    client = os.environ.get("AUXILIAR_FIREBIRD_CLIENT")
    if not all((database, user, password, client)):
        raise RuntimeError("Conexao Firebird nao configurada")
    driver_config.fb_client_library.value = client
    driver_config.server_defaults.host.value = "localhost"
    driver_config.db_defaults.charset.value = "UTF8"
    return connect(database, user=user, password=password)


def allowed_companies():
    source = os.environ.get("AUXILIAR_SOURCE", "").lower()
    return {"armazem": {1}, "emporio": {1, 6}}.get(source, set())


def convert(value):
    if hasattr(value, "read"):
        value = value.read()
    if isinstance(value, (datetime, date, time)):
        return value.isoformat(sep=" ") if isinstance(value, datetime) else value.isoformat()
    if isinstance(value, Decimal):
        return str(value)
    if isinstance(value, bytes):
        return "base64:" + base64.b64encode(value).decode("ascii")
    return value


def mysql_type(field_type, subtype, length, precision, scale, char_length):
    if field_type in (7, 8, 16) and subtype in (1, 2):
        digits = min(38, precision or 18)
        return f"DECIMAL({digits},{min(digits, abs(scale or 0))})"
    return {
        7: "SMALLINT", 8: "INT", 16: "BIGINT", 10: "DOUBLE", 27: "DOUBLE",
        12: "DATE", 13: "TIME", 35: "DATETIME", 23: "TINYINT",
        14: "LONGTEXT", 37: "LONGTEXT",
        261: "LONGTEXT",
    }.get(field_type, "LONGTEXT")


@app.get("/schema/<table>")
def auxiliary_schema(table):
    if table not in TABLES:
        return jsonify(erro="Tabela invalida"), 404
    source_table, _ = TABLES[table]
    connection = None
    try:
        connection = firebird_connection()
        cursor = connection.cursor()
        cursor.execute("""
            SELECT TRIM(rf.RDB$FIELD_NAME), f.RDB$FIELD_TYPE, f.RDB$FIELD_SUB_TYPE,
                   f.RDB$FIELD_LENGTH, f.RDB$FIELD_PRECISION, f.RDB$FIELD_SCALE,
                   f.RDB$CHARACTER_LENGTH
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            WHERE rf.RDB$RELATION_NAME = ?
            ORDER BY rf.RDB$FIELD_POSITION
        """, (source_table,))
        fields = [
            {"name": name.strip(), "type": mysql_type(field_type, subtype, length, precision, scale, char_length)}
            for name, field_type, subtype, length, precision, scale, char_length in cursor.fetchall()
        ]
        return jsonify(fields=fields)
    except Exception:
        app.logger.exception("Falha na leitura do esquema %s", source_table)
        return jsonify(erro="Falha na leitura do esquema Firebird"), 500
    finally:
        if connection is not None:
            connection.close()


@app.get("/dados/<table>")
def auxiliary_rows(table):
    if table not in TABLES:
        return jsonify(erro="Tabela invalida"), 404
    source_table, key = TABLES[table]
    try:
        company = int(request.args.get("empresa", "0"))
        after = int(request.args.get("apos", "0"))
        limit = int(request.args.get("limite", "500"))
    except ValueError:
        return jsonify(erro="Parametros invalidos"), 400
    if company not in allowed_companies() or after < 0 or not 1 <= limit <= 1000:
        return jsonify(erro="Empresa ou paginacao invalida"), 400
    connection = None
    try:
        connection = firebird_connection()
        cursor = connection.cursor()
        cursor.execute(
            f"SELECT FIRST {limit} * FROM {source_table} "
            f"WHERE EMPRESA = ? AND {key} > ? ORDER BY {key}",
            (company, after),
        )
        columns = [column[0] for column in cursor.description]
        rows = [dict(zip(columns, map(convert, row))) for row in cursor.fetchall()]
        return jsonify(rows=rows)
    except Exception:
        app.logger.exception("Falha na leitura de %s", source_table)
        return jsonify(erro="Falha na leitura do Firebird"), 500
    finally:
        if connection is not None:
            connection.close()


@app.get("/contagem/<table>")
def auxiliary_count(table):
    if table not in TABLES:
        return jsonify(erro="Tabela invalida"), 404
    source_table, _ = TABLES[table]
    try:
        company = int(request.args.get("empresa", "0"))
    except ValueError:
        return jsonify(erro="Empresa invalida"), 400
    if company not in allowed_companies():
        return jsonify(erro="Empresa invalida"), 400
    connection = None
    try:
        connection = firebird_connection()
        cursor = connection.cursor()
        cursor.execute(f"SELECT COUNT(*) FROM {source_table} WHERE EMPRESA = ?", (company,))
        return jsonify(count=int(cursor.fetchone()[0]))
    except Exception:
        app.logger.exception("Falha na contagem de %s", source_table)
        return jsonify(erro="Falha na contagem do Firebird"), 500
    finally:
        if connection is not None:
            connection.close()


def inspect_est026():
    connection = firebird_connection()
    try:
        cursor = connection.cursor()
        cursor.execute(
            "SELECT TRIM(RDB$FIELD_NAME) FROM RDB$RELATION_FIELDS "
            "WHERE RDB$RELATION_NAME = 'EST026' ORDER BY RDB$FIELD_POSITION"
        )
        columns = [row[0].strip() for row in cursor.fetchall()]
        print("CAMPOS:", len(columns), ", ".join(columns))
        if not columns:
            return
        cursor.execute(
            "SELECT TRIM(s.RDB$FIELD_NAME) FROM RDB$RELATION_CONSTRAINTS c "
            "JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = c.RDB$INDEX_NAME "
            "WHERE c.RDB$RELATION_NAME = 'EST026' "
            "AND c.RDB$CONSTRAINT_TYPE = 'PRIMARY KEY' "
            "ORDER BY s.RDB$FIELD_POSITION"
        )
        print("CHAVE PRIMARIA:", [row[0].strip() for row in cursor.fetchall()])
        if "EMPRESA" in columns:
            cursor.execute("SELECT EMPRESA, COUNT(*) FROM EST026 GROUP BY EMPRESA ORDER BY EMPRESA")
            print("REGISTROS POR EMPRESA:", cursor.fetchall())
        else:
            cursor.execute("SELECT COUNT(*) FROM EST026")
            print("REGISTROS:", cursor.fetchone()[0])
    finally:
        connection.close()


def sync_auxiliary(table, selected_company, page_size):
    import requests
    from requests.adapters import HTTPAdapter
    from urllib3.util.retry import Retry

    source = os.environ.get("AUXILIAR_SOURCE", "").lower()
    mappings = [pair for pair in MAPPINGS.get(source, []) if selected_company in (None, pair[0])]
    if not mappings:
        raise RuntimeError("Empresa nao mapeada para este servidor auxiliar")
    token = os.environ.get("AUXILIAR_SYNC_TOKEN")
    if not token:
        raise RuntimeError("AUXILIAR_SYNC_TOKEN nao configurado")
    receiver = os.environ.get(
        "AUXILIAR_RECEIVER",
        "https://www.superdunga.com.br/modulos/tesouraria/receber_auxiliar.php",
    )
    if not receiver.startswith("https://"):
        raise RuntimeError("O receptor deve usar HTTPS")
    session = requests.Session()
    session.headers.update({"X-Sync-Token": token})
    retry = Retry(
        total=3, backoff_factor=2, status_forcelist=[429, 500, 502, 503, 504],
        allowed_methods=["GET", "POST"],
    )
    session.mount("https://", HTTPAdapter(max_retries=retry))
    session.mount("http://", HTTPAdapter(max_retries=retry))
    local_client = app.test_client()

    def local_get(path, **params):
        response = local_client.get(path, query_string=params)
        if response.status_code != 200:
            raise RuntimeError(f"Falha na leitura local de {path}: HTTP {response.status_code}: {response.get_json()}")
        return response.get_json()

    schema = local_get(f"/schema/{table}")["fields"]
    key_column = TABLES[table][1]
    if not {"EMPRESA", key_column}.issubset({field["name"] for field in schema}):
        raise RuntimeError(f"{table} sem EMPRESA ou {key_column}")

    for firebird_company, company in mappings:
        source_count = int(local_get(f"/contagem/{table}", empresa=firebird_company)["count"])
        if source_count == 0:
            raise RuntimeError(f"{table} vazia na empresa {firebird_company}; snapshot cancelado")
        sync_id = str(uuid.uuid4())
        common = {
            "source": source, "firebird_company": firebird_company,
            "company": company, "table": table, "sync_id": sync_id,
        }

        def send(action, **extra):
            response = session.post(receiver, json={**common, "action": action, **extra}, timeout=180)
            response.raise_for_status()
            body = response.json()
            if body.get("status") != "ok":
                raise RuntimeError(body.get("erro", "Resposta inesperada do receptor"))
            return body

        send("ping")
        send("start", schema=schema)
        after = 0
        total = 0
        while True:
            rows = local_get(
                f"/dados/{table}", empresa=firebird_company, apos=after, limite=page_size
            )["rows"]
            if not rows:
                break
            keys = [int(row[key_column]) for row in rows]
            if keys != sorted(set(keys)) or keys[0] <= after:
                raise RuntimeError(f"Paginacao de {table} nao esta estritamente crescente")
            send("batch", rows=rows)
            after = keys[-1]
            total += len(rows)
            print(f"{table}: Firebird {firebird_company} -> SuperDunga {company}: {total}", flush=True)
        if total != source_count:
            raise RuntimeError(f"Snapshot incompleto: Firebird={source_count}, enviados={total}")
        final_source_count = int(local_get(f"/contagem/{table}", empresa=firebird_company)["count"])
        if final_source_count != source_count:
            raise RuntimeError(
                f"{table} mudou durante a leitura: inicio={source_count}, fim={final_source_count}"
            )
        result = send("finish", expected=total)
        print(f"{table} finalizada: {result['received']} registros, {result['marked_absent']} ausentes", flush=True)


if __name__ == "__main__":
    if not allowed_companies():
        raise SystemExit("Defina AUXILIAR_SOURCE=armazem ou emporio")
    parser = argparse.ArgumentParser()
    parser.add_argument("--inspect-est026", action="store_true")
    parser.add_argument("--sync-table", choices=TABLES)
    parser.add_argument("--company", type=int)
    parser.add_argument("--page-size", type=int, default=100)
    args = parser.parse_args()
    if not 1 <= args.page_size <= 1000:
        parser.error("--page-size deve estar entre 1 e 1000")
    if args.inspect_est026 and args.sync_table:
        parser.error("Escolha apenas inspecao ou sincronizacao")
    if args.inspect_est026:
        inspect_est026()
    elif args.sync_table:
        sync_auxiliary(args.sync_table, args.company, args.page_size)
    elif not sys.argv[1:]:
        app.run(host="127.0.0.1", port=int(os.environ.get("AUXILIAR_API_PORT", "5001")))
    else:
        parser.error("Informe --inspect-est026 ou --sync-table")
