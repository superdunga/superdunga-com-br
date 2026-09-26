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


if __name__ == "__main__":
    unittest.main()
