"""PYTHONPATH=runtimes/functions/python python3 -m unittest discover -s runtimes/functions/tests"""

import pathlib
import tempfile
import unittest
from unittest import mock

from kiln_fn import install


def block(*deps: str) -> str:
    return "# /// script\n# dependencies = [" + ", ".join(f'"{d}"' for d in deps) + "]\n# ///\n"


class DependenciesTest(unittest.TestCase):
    def test_blocks_of_every_file_are_merged(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            app = pathlib.Path(tmp)
            (app / "lib").mkdir()
            (app / ".venv" / "x").mkdir(parents=True)
            (app / "main.py").write_text(block("fastapi", "httpx") + "import lib.db\n")
            (app / "lib" / "db.py").write_text(block("psycopg[binary]", "httpx"))
            (app / "helpers.py").write_text("x = 1\n")
            (app / ".venv" / "x" / "ignored.py").write_text(block("never"))
            with mock.patch.object(install, "APP", app):
                self.assertEqual(install.all_dependencies(app / "main.py"), ["fastapi", "httpx", "psycopg[binary]"])

    def test_a_bad_block_names_its_file(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            app = pathlib.Path(tmp)
            (app / "main.py").write_text("")
            (app / "jobs.py").write_text("# /// script\n# dependencies = 1\n# ///\n")
            with mock.patch.object(install, "APP", app), mock.patch.object(install, "fail", side_effect=SystemExit) as fail:
                with self.assertRaises(SystemExit):
                    install.all_dependencies(app / "main.py")
                self.assertIn("jobs.py", fail.call_args[0][0])


if __name__ == "__main__":
    unittest.main()
