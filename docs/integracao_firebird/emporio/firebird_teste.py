# -*- coding: utf-8 -*-

print("ARQUIVO CORRETO CARREGADO")

from flask import Flask, jsonify, request
import fdb

app = Flask(__name__)


def conectar():
    host = 'localhost'
    banco = r'C:\Adm_EmporioDunga\Data\ESTOQUE.FDB'
    usuario = 'SYSDBA'
    senha = 'masterkey'

    return fdb.connect(
        dsn=f"{host}:{banco}",
        user=usuario,
        password=senha,
        charset='UTF8'
    )


def tratar_valor(valor):
    if hasattr(valor, 'strftime'):
        return valor.strftime('%Y-%m-%d %H:%M:%S')

    elif str(type(valor)).find("Decimal") != -1:
        return float(valor)

    elif isinstance(valor, bytes):
        try:
            return valor.decode('utf-8', errors='ignore')
        except:
            return str(valor)

    return valor


def buscar_ativos(tabela, colunas, order_by=None, limit=None, offset=0):
    con = conectar()
    cursor = con.cursor()

    colunas_sql = ", ".join(colunas)
    order_sql = order_by or colunas_sql
    rows_sql = ""

    if limit is not None:
        limit = max(1, int(limit))
        offset = max(0, int(offset))
        inicio = offset + 1
        fim = offset + limit
        rows_sql = f" ROWS {inicio} TO {fim}"

    cursor.execute(f"""
        SELECT {colunas_sql}
        FROM {tabela}
        ORDER BY {order_sql}
        {rows_sql}
    """)

    dados = []
    for row in cursor.fetchall():
        if len(colunas) == 1:
            dados.append(tratar_valor(row[0]))
        else:
            registro = {}
            for i, coluna in enumerate(colunas):
                registro[coluna] = tratar_valor(row[i])
            dados.append(registro)

    con.close()
    return dados


def buscar_por_regstamp(tabela, ultima_regstamp, order_by="REGSTAMP", empresa=None):
    con = conectar()
    cursor = con.cursor()

    filtro_empresa = ""
    parametros = [ultima_regstamp]
    if empresa is not None:
        filtro_empresa = " AND EMPRESA = ?"
        parametros.append(int(empresa))

    cursor.execute(f"""
        SELECT *
        FROM {tabela}
        WHERE REGSTAMP > ?
        {filtro_empresa}
        ORDER BY {order_by}
    """, tuple(parametros))

    colunas = [desc[0] for desc in cursor.description]
    dados = []

    for row in cursor.fetchall():
        registro = {}
        for i, valor in enumerate(row):
            registro[colunas[i]] = tratar_valor(valor)
        dados.append(registro)

    con.close()
    return dados


@app.route("/dados/bnc001", methods=["GET"])
def dados_bnc001():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM BNC001
            WHERE DTMOV >= '2025-01-01'
              AND REGSTAMP > '{ultima_regstamp}'
            ORDER BY REGSTAMP, EMPRESA, MOVCONTADOR
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route('/dados/bnc001_ids', methods=['GET'])
def bnc001_ids():
    try:
        con = conectar()
        cursor = con.cursor()

        cursor.execute("SELECT MOVCONTADOR FROM BNC001")
        dados = [row[0] for row in cursor.fetchall()]

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({'erro': str(e)}), 500


@app.route("/dados/bnc005_ativos", methods=["GET"])
def dados_bnc005_ativos():
    try:
        return jsonify(buscar_ativos("BNC005", ["ESCONTADOR"]))
    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/bnc002_ativos", methods=["GET"])
def dados_bnc002_ativos():
    try:
        return jsonify(buscar_ativos("BNC002", ["CBCONTADOR"]))
    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/bnc005", methods=["GET"])
def dados_bnc005():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM BNC005
            WHERE REGSTAMP > '{ultima_regstamp}'
            ORDER BY REGSTAMP, EMPRESA, ESCONTADOR
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/bnc002", methods=["GET"])
def dados_bnc002():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM BNC002
            WHERE REGSTAMP > '{ultima_regstamp}'
            ORDER BY REGSTAMP, EMPRESA, CBCONTADOR
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/cp001_ativos", methods=["GET"])
def dados_cp001_ativos():
    try:
        return jsonify(buscar_ativos("CP001", ["CPCONTADOR"]))
    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/cp003_ativos", methods=["GET"])
def dados_cp003_ativos():
    try:
        return jsonify(buscar_ativos("CP003", ["FCONTADOR"]))
    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/cp004_ativos", methods=["GET"])
def dados_cp004_ativos():
    try:
        return jsonify(buscar_ativos("CP004", ["QTCPCONTADOR"]))
    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/cr002_ativos", methods=["GET"])
def dados_cr002_ativos():
    try:
        return jsonify(buscar_ativos("CR002", ["CLICONTADOR"]))
    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/rep001", methods=["GET"])
def dados_rep001():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)
        empresa = request.args.get("empresa", default=None, type=int)
        return jsonify(buscar_por_regstamp("REP001", ultima_regstamp, "REGSTAMP, EMPRESA", empresa))
    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/func001", methods=["GET"])
def dados_func001():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)
        empresa = request.args.get("empresa", default=None, type=int)
        return jsonify(buscar_por_regstamp("FUNC001", ultima_regstamp, "REGSTAMP, EMPRESA", empresa))
    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/cp001", methods=["GET"])
def dados_cp001():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM CP001
            WHERE REGSTAMP > '{ultima_regstamp}'
            ORDER BY REGSTAMP, EMPRESA, CPCONTADOR
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/cp003", methods=["GET"])
def dados_cp003():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM CP003
            WHERE REGSTAMP > '{ultima_regstamp}'
            ORDER BY REGSTAMP, EMPRESA, FCONTADOR
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/cp004", methods=["GET"])
def dados_cp004():
    try:
        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM CP004
            ORDER BY EMPRESA, QTCPCONTADOR
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/est004_ativos", methods=["GET"])
def dados_est004_ativos():
    try:
        return jsonify(buscar_ativos("EST004", ["CODPRODUTO"]))
    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/est005_ativos", methods=["GET"])
def dados_est005_ativos():
    try:
        return jsonify(buscar_ativos("EST005", ["COMPRACONTADOR"]))
    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/est006_ativos", methods=["GET"])
def dados_est006_ativos():
    try:
        return jsonify(buscar_ativos("EST006", ["ITEMCOMPRACONTADOR", "COMPRACONTA"]))
    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/zconfig005_ativos", methods=["GET"])
def dados_zconfig005_ativos():
    try:
        con = conectar()
        cursor = con.cursor()

        cursor.execute("""
            SELECT CODUSER
            FROM ZCONFIG005
            WHERE DESATIVADO = '0'
            ORDER BY CODUSER
        """)

        dados = [row[0] for row in cursor.fetchall()]

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/est005", methods=["GET"])
def dados_est005():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM EST005
            WHERE REGSTAMP > '{ultima_regstamp}'
            ORDER BY REGSTAMP, EMPRESA, COMPRACONTADOR
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/est006", methods=["GET"])
def dados_est006():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM EST006
            WHERE REGSTAMP > '{ultima_regstamp}'
            ORDER BY REGSTAMP, EMPRESA, COMPRACONTA
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/est008_ativos", methods=["GET"])
def dados_est008_ativos():
    try:
        limit = request.args.get("limit", default=1000, type=int)
        offset = request.args.get("offset", default=0, type=int)
        item_inicio = request.args.get("item_inicio", default=55908, type=int)

        limit = max(1, int(limit))
        offset = max(0, int(offset))
        inicio = offset + 1
        fim = offset + limit

        con = conectar()
        cursor = con.cursor()

        cursor.execute(f"""
            SELECT EMPRESA, ITEMVENDACONTADOR, VENDACONTA, PRODUTO
            FROM EST008
            WHERE ITEMVENDACONTADOR >= ?
            ORDER BY EMPRESA, ITEMVENDACONTADOR, VENDACONTA, PRODUTO
            ROWS {inicio} TO {fim}
        """, (item_inicio,))

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)
    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/est007_ativos", methods=["GET"])
def dados_est007_ativos():
    try:
        inicio = request.args.get("inicio", type=str)
        fim = request.args.get("fim", type=str)

        if not inicio or not fim:
            return jsonify({"erro": "Informe inicio e fim"}), 400

        con = conectar()
        cursor = con.cursor()

        cursor.execute("""
            SELECT VENDACONTADOR
            FROM EST007
            WHERE DTEMISSAO BETWEEN ? AND ?
            ORDER BY VENDACONTADOR
        """, (inicio, fim))

        dados = [row[0] for row in cursor.fetchall()]

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/est004", methods=["GET"])
def dados_est004():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM EST004
            WHERE REGSTAMP > '{ultima_regstamp}'
            ORDER BY REGSTAMP, EMPRESA, CODPRODUTO
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/est008", methods=["GET"])
def dados_est008():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)
        limit = request.args.get("limit", default=1000, type=int)
        offset = request.args.get("offset", default=0, type=int)
        item_inicio = request.args.get("item_inicio", default=55908, type=int)

        limit = max(1, int(limit))
        offset = max(0, int(offset))
        inicio = offset + 1
        fim = offset + limit

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM EST008
            WHERE REGSTAMP > '{ultima_regstamp}'
              AND ITEMVENDACONTADOR >= {int(item_inicio)}
            ORDER BY REGSTAMP, EMPRESA, VENDACONTA, ITEMVENDACONTADOR
            ROWS {inicio} TO {fim}
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/cr001", methods=["GET"])
def dados_cr001():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM CR001
            WHERE REGSTAMP > '{ultima_regstamp}'
            ORDER BY REGSTAMP
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/cr001_ativos", methods=["GET"])
def dados_cr001_ativos():
    try:
        inicio = request.args.get("inicio", type=str)
        fim = request.args.get("fim", type=str)

        if not inicio or not fim:
            return jsonify({"erro": "Informe inicio e fim"}), 400

        con = conectar()
        cursor = con.cursor()

        sql = """
            SELECT CRCONTADOR
            FROM CR001
            WHERE DTLANC BETWEEN ? AND ?
            ORDER BY CRCONTADOR
        """

        cursor.execute(sql, (inicio, fim))
        dados = [row[0] for row in cursor.fetchall()]

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/cr002", methods=["GET"])
def dados_cr002():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM CR002
            WHERE REGSTAMP > '{ultima_regstamp}'
            ORDER BY REGSTAMP
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/zconfig005", methods=["GET"])
def dados_zconfig005():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT EMPRESA, CODUSER, NOMEUSER, DESATIVADO, CODCX, REGSTAMP
            FROM ZCONFIG005
            WHERE DESATIVADO = '0'
              AND REGSTAMP > '{ultima_regstamp}'
            ORDER BY REGSTAMP
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route("/dados/est007", methods=["GET"])
def dados_est007():
    try:
        ultima_regstamp = request.args.get("ultima_regstamp", default="1900-01-01 00:00:00", type=str)

        con = conectar()
        cursor = con.cursor()

        sql = f"""
            SELECT *
            FROM EST007
            WHERE CAST(DTEMISSAO AS DATE) >= DATE '2025-01-01'
              AND REGSTAMP > '{ultima_regstamp}'
            ORDER BY REGSTAMP
        """

        cursor.execute(sql)

        colunas = [desc[0] for desc in cursor.description]
        dados = []

        for row in cursor.fetchall():
            registro = {}
            for i, valor in enumerate(row):
                registro[colunas[i]] = tratar_valor(valor)
            dados.append(registro)

        con.close()
        return jsonify(dados)

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


@app.route('/update/cr001', methods=['POST'])
def update_cr001():
    try:
        dados = request.get_json()

        if not dados:
            return jsonify({"erro": "JSON vazio"}), 400

        if isinstance(dados, list):

            con = conectar()
            cursor = con.cursor()

            atualizados = 0

            for item in dados:

                crcontador = item.get("CRCONTADOR")
                chave = item.get("CHAVEINTEGRACAO")
                cm = item.get("CMCONTADOR")
                dtvenc = item.get("DTVENC")

                if not crcontador:
                    continue

                cursor.execute("""
                    SELECT CHAVEINTEGRACAO, DTVENC
                    FROM CR001
                    WHERE CRCONTADOR = ?
                """, (crcontador,))
                atual = cursor.fetchone()

                if not atual:
                    continue

                chave_atual, _ = atual

                campos = []
                valores = []

                if chave is not None and chave_atual is None:
                    campos.append("CHAVEINTEGRACAO = ?")
                    valores.append(chave)

                if cm is not None:
                    campos.append("CMCONTADOR = ?")
                    valores.append(cm)

                if dtvenc not in (None, ""):
                    campos.append("DTVENC = ?")
                    valores.append(dtvenc)

                if not campos:
                    continue

                valores.append(crcontador)

                sql = f"UPDATE CR001 SET {', '.join(campos)} WHERE CRCONTADOR = ?"
                cursor.execute(sql, tuple(valores))

                atualizados += 1

            con.commit()
            con.close()

            return jsonify({
                "status": "ok",
                "atualizados": atualizados
            })

        crcontador = dados.get("CRCONTADOR")
        campo = dados.get("campo")
        valor = dados.get("valor")

        if not crcontador or not campo:
            return jsonify({"erro": "Dados incompletos"}), 400

        campos_permitidos = ["CMCONTADOR", "DTVENC"]

        if campo not in campos_permitidos:
            return jsonify({"erro": "Campo nao permitido"}), 403

        con = conectar()
        cursor = con.cursor()

        cursor.execute(f"SELECT {campo} FROM CR001 WHERE CRCONTADOR = ?", (crcontador,))
        antigo = cursor.fetchone()

        if not antigo:
            return jsonify({"erro": "Registro nao encontrado"}), 404

        sql = f"UPDATE CR001 SET {campo} = ? WHERE CRCONTADOR = ?"
        cursor.execute(sql, (valor, crcontador))

        con.commit()
        con.close()

        return jsonify({
            "status": "ok",
            "modo": "manual"
        })

    except Exception as e:
        return jsonify({"erro": str(e)}), 500


if __name__ == "__main__":
    from waitress import serve
    print("Iniciando servidor producao waitress...")
    serve(app, host="0.0.0.0", port=5000)
