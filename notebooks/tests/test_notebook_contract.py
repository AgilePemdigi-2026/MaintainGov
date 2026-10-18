from pathlib import Path
import ast
import io
import tokenize
import unittest

import nbformat

PATH = Path(__file__).resolve().parents[1] / 'MaintainGov_UC01_End_to_End_Colab.ipynb'
NB = nbformat.read(PATH, 4)
CODES = [cell for cell in NB.cells if cell.cell_type == 'code']


class NotebookContract(unittest.TestCase):
    def test_notebook_schema_and_python_syntax(self):
        nbformat.validate(NB)
        for cell in CODES:
            ast.parse(cell.source.removeprefix('%%maintain_gov\n'))

    def test_no_python_comments(self):
        for cell in CODES:
            source = cell.source.removeprefix('%%maintain_gov\n')
            comments = [token for token in tokenize.generate_tokens(io.StringIO(source).readline) if token.type == tokenize.COMMENT]
            self.assertFalse(comments)

    def test_only_isolated_worker_imports_scientific_packages(self):
        forbidden = {'numpy', 'pandas', 'scipy', 'sklearn', 'matplotlib', 'seaborn'}
        for cell in CODES:
            if cell.metadata.get('maintain_gov_execution') == 'host':
                for node in ast.walk(ast.parse(cell.source)):
                    if isinstance(node, ast.Import):
                        self.assertFalse({alias.name.split('.')[0] for alias in node.names} & forbidden)
                    if isinstance(node, ast.ImportFrom):
                        self.assertNotIn((node.module or '').split('.')[0], forbidden)

    def test_automatic_upload_and_single_bundle_download(self):
        host = '\n'.join(cell.source for cell in CODES if cell.metadata.get('maintain_gov_execution') == 'host')
        self.assertIn('files.upload()', host)
        self.assertEqual(host.count('files.download('), 1)
        self.assertIn('MaintainGov_hasil_lengkap.zip', host)
        self.assertNotIn('FORCE_REINSTALL', host)
        self.assertNotIn('Restart session', host)

    def test_all_analysis_cells_use_worker(self):
        analysis = [cell for cell in CODES if cell.metadata.get('maintain_gov_execution') == 'worker']
        self.assertGreater(len(analysis), 30)
        self.assertTrue(all(cell.source.startswith('%%maintain_gov\n') for cell in analysis))


if __name__ == '__main__':
    unittest.main()
