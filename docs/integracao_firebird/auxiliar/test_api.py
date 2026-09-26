"""Focused route tests without a Firebird connection."""

import os
import unittest
from unittest.mock import MagicMock, patch

import est007_api


class AuxiliaryApiTests(unittest.TestCase):
    def setUp(self):
        self.source = patch.dict(os.environ, {"AUXILIAR_SOURCE": "armazem"})
        self.source.start()
        self.addCleanup(self.source.stop)
        self.client = est007_api.app.test_client()

    def test_only_known_tables_and_companies(self):
        self.assertEqual(self.client.get("/schema/est999_auxiliar").status_code, 404)
        self.assertEqual(self.client.get("/dados/est026_auxiliar?empresa=6").status_code, 400)
        self.assertEqual(self.client.get("/contagem/est026_auxiliar?empresa=6").status_code, 400)

    @patch.object(est007_api, "firebird_connection")
    def test_est026_schema(self, connect):
        cursor = MagicMock()
        cursor.fetchall.return_value = [
            ("EMPRESA", 8, 0, 4, None, 0, None),
            ("NFCONTADOR", 8, 0, 4, None, 0, None),
        ]
        connect.return_value.cursor.return_value = cursor
        response = self.client.get("/schema/est026_auxiliar")
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json["fields"], [
            {"name": "EMPRESA", "type": "INT"},
            {"name": "NFCONTADOR", "type": "INT"},
        ])
        self.assertEqual(cursor.execute.call_args.args[1], ("EST026",))

    @patch.object(est007_api, "firebird_connection")
    def test_est026_count_and_pagination(self, connect):
        cursor = MagicMock()
        connect.return_value.cursor.return_value = cursor
        cursor.fetchone.return_value = (14,)
        response = self.client.get("/contagem/est026_auxiliar?empresa=1")
        self.assertEqual(response.json, {"count": 14})
        self.assertEqual(cursor.execute.call_args.args[1], (1,))

        cursor.description = [("EMPRESA",), ("NFCONTADOR",), ("NUMDOC",)]
        cursor.fetchall.return_value = [(1, 12, "123")]
        response = self.client.get("/dados/est026_auxiliar?empresa=1&apos=10&limite=1")
        self.assertEqual(response.json["rows"], [
            {"EMPRESA": 1, "NFCONTADOR": 12, "NUMDOC": "123"}
        ])
        self.assertIn("NFCONTADOR > ?", cursor.execute.call_args.args[0])
        self.assertEqual(cursor.execute.call_args.args[1], (1, 10))

    @patch.dict(os.environ, {"AUXILIAR_SYNC_TOKEN": "test-token"})
    @patch("requests.Session")
    @patch.object(est007_api.app, "test_client")
    def test_sync_uses_local_client_without_http_server(self, client_factory, session_factory):
        local = client_factory.return_value
        responses = {
            "/schema/est026_auxiliar": {"fields": [
                {"name": "EMPRESA", "type": "INT"},
                {"name": "NFCONTADOR", "type": "INT"},
            ]},
            "/contagem/est026_auxiliar": {"count": 1},
        }

        def get(path, query_string=None):
            body = responses.get(path)
            if path == "/dados/est026_auxiliar":
                body = {"rows": [] if query_string["apos"] else [{"EMPRESA": 1, "NFCONTADOR": 1}]}
            response = MagicMock(status_code=200)
            response.get_json.return_value = body
            return response

        local.get.side_effect = get
        remote = session_factory.return_value
        remote.post.return_value.json.side_effect = [
            {"status": "ok"}, {"status": "ok"}, {"status": "ok"},
            {"status": "ok", "received": 1, "marked_absent": 0},
        ]
        est007_api.sync_auxiliary("est026_auxiliar", 1, 50)
        remote.get.assert_not_called()
        self.assertEqual(remote.post.call_count, 4)


if __name__ == "__main__":
    unittest.main()
