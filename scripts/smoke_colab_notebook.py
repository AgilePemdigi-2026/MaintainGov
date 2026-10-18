"""Execute MaintainGov's self-contained notebook in a fresh, explicit kernel.

Use an isolated environment with the notebook's pinned packages and
nbformat/nbclient/nbconvert/ipykernel. Outputs and input data stay in ignored tmp/.
The optional Colab shim tests Python upload/download branches, not Google's UI.
"""
from __future__ import annotations

import argparse
import asyncio
import hashlib
import json
import os
from pathlib import Path
import platform
import sys
import time

import nbformat
from nbclient import NotebookClient
from nbconvert import HTMLExporter


def main():
    if sys.platform == 'win32':
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--zip', required=True, type=Path)
    parser.add_argument('--run-dir', required=True, type=Path)
    parser.add_argument('--colab-shim', action='store_true')
    parser.add_argument('--human', action='store_true')
    parser.add_argument('--annotations', type=Path)
    parser.add_argument('--test-iterations', type=int)
    parser.add_argument('--test-bootstraps', type=int)
    parser.add_argument('--save-executed', action='store_true')
    parser.add_argument('--worker-python', type=Path)
    parser.add_argument('--stale-host-numpy', action='store_true')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    source = root / 'notebooks/MaintainGov_UC01_End_to_End_Colab.ipynb'
    run_dir = args.run_dir.resolve()
    if not args.zip.is_file():
        parser.error('ZIP not found')
    if (run_dir / 'artifacts').exists():
        parser.error('Use a new run directory; do not mix old and new evidence.')
    if args.save_executed and (args.human or args.test_iterations or args.test_bootstraps):
        parser.error('Only a full default real-data run may replace the delivered notebook.')
    run_dir.mkdir(parents=True, exist_ok=True)
    os.environ['PYTHONIOENCODING'] = 'utf-8'
    os.environ['MPLBACKEND'] = 'module://matplotlib_inline.backend_inline'
    os.environ['MAINTAINGOV_OUTPUT'] = str(run_dir / 'artifacts')
    os.environ.pop('MAINTAINGOV_ANNOTATIONS', None)
    os.environ.pop('MAINTAINGOV_WORKER_PYTHON', None)
    if args.worker_python:
        os.environ['MAINTAINGOV_WORKER_PYTHON'] = str(args.worker_python.resolve())
    if args.annotations:
        os.environ['MAINTAINGOV_ANNOTATIONS'] = str(args.annotations.resolve())
    if args.colab_shim:
        os.environ.pop('MAINTAINGOV_ZIP', None)
    else:
        os.environ['MAINTAINGOV_ZIP'] = str(args.zip.resolve())

    # Private kernelspec prevents accidental execution with another Python.
    kernel_name = 'maintain_gov_smoke'
    kernel_root = run_dir / 'jupyter' / 'kernels' / kernel_name
    kernel_root.mkdir(parents=True, exist_ok=True)
    (kernel_root / 'kernel.json').write_text(json.dumps({
        'argv': [sys.executable, '-m', 'ipykernel_launcher', '-f', '{connection_file}'],
        'display_name': f'MaintainGov smoke {platform.python_version()}',
        'language': 'python'
    }), encoding='utf-8')
    os.environ['JUPYTER_PATH'] = str(run_dir / 'jupyter') + os.pathsep + os.environ.get('JUPYTER_PATH', '')
    nb = nbformat.read(source, 4)
    original_ids = {cell.id for cell in nb.cells}
    for cell in nb.cells:
        if cell.cell_type == 'code':
            cell.outputs = []
            cell.execution_count = None
            if 'SEED = 42' in cell.source and 'CV_FOLDS = 3' in cell.source:
                if args.human:
                    cell.source = cell.source.replace('LABEL_MODE = "rating_proxy"', 'LABEL_MODE = "human"')
                if args.test_iterations:
                    cell.source = cell.source.replace('SEARCH_ITER = 12', f'SEARCH_ITER = {args.test_iterations}')
                if args.test_bootstraps:
                    cell.source = cell.source.replace('BOOTSTRAPS = 300', f'BOOTSTRAPS = {args.test_bootstraps}')

    # Inject only test infrastructure; no source model code or labels are supplied.
    shim = ''
    if args.colab_shim:
        shim = f'''
import types
google = sys.modules.get("google")
if google is None:
    google = types.ModuleType("google")
    google.__path__ = []
    sys.modules["google"] = google
colab = types.ModuleType("google.colab")
files = types.ModuleType("google.colab.files")
_smoke_uploads, _smoke_downloads = [], []
def _smoke_upload():
    path = Path({str(args.zip.resolve())!r})
    _smoke_uploads.append(path.name)
    return {{path.name: path.read_bytes()}}
def _smoke_download(path):
    assert Path(path).is_file()
    _smoke_downloads.append(Path(path).name)
files.upload, files.download = _smoke_upload, _smoke_download
colab.files = files
google.colab = colab
sys.modules["google.colab"] = colab
sys.modules["google.colab.files"] = files
'''
    if args.stale_host_numpy:
        shim += '''
import types
_stale_numpy = __import__("numpy")
_stale_numpy.__version__ = "STALE_HOST_SENTINEL"
sys.modules["numpy"] = _stale_numpy
'''
    harness = nbformat.v4.new_code_cell(
        'import sys, json\nfrom pathlib import Path\n'
        f'assert sys.executable == {sys.executable!r}\n' + shim)
    nb.cells.insert(0, harness)
    worker_check = f'''assert all(validation_checks.values())
assert all(runtime_smoke_checks.values())
assert all(artifact_smoke_checks.values())
assert all(zip_smoke_checks.values())
Path({str(run_dir / 'kernel_check.json')!r}).write_text(json.dumps({{
    "python": platform.python_version(), "executable": sys.executable,
    "runtime_checks": runtime_smoke_checks, "validation_checks": validation_checks,
    "artifact_checks": artifact_smoke_checks, "archive_checks": zip_smoke_checks,
    "usable_rows": len(df), "model_rows": len(model_data), "mode": LABEL_MODE,
    "search_iterations": SEARCH_ITER, "cv_folds": n_folds, "bootstraps": BOOTSTRAPS
}}), encoding="utf-8")
'''
    nb.cells.insert(len(nb.cells) - 1, nbformat.v4.new_code_cell('%%maintain_gov\n' + worker_check))
    tail_source = 'assert _mg_complete_zip.is_file()\n'
    if args.stale_host_numpy:
        tail_source += 'assert sys.modules["numpy"] is _stale_numpy\n'
    if args.colab_shim:
        tail_source += 'assert len(_smoke_uploads) == 1 and len(_smoke_downloads) == 1\n'
    tail_source += f'''_kernel_report_path = Path({str(run_dir / 'kernel_check.json')!r})
_kernel_report = json.loads(_kernel_report_path.read_text(encoding="utf-8"))
_kernel_report["colab_upload_calls"] = len(globals().get("_smoke_uploads", []))
_kernel_report["colab_download_calls"] = len(globals().get("_smoke_downloads", []))
_kernel_report_path.write_text(json.dumps(_kernel_report), encoding="utf-8")
'''
    nb.cells.append(nbformat.v4.new_code_cell(tail_source))
    timings = []
    started = time.perf_counter()
    cell_started = started

    def on_start(cell, cell_index, **kwargs):
        nonlocal cell_started
        cell_started = time.perf_counter()

    def on_done(cell, cell_index, **kwargs):
        if cell.cell_type == 'code':
            timings.append({'cell_id': cell.id, 'index': cell_index, 'seconds': round(time.perf_counter() - cell_started, 3)})
            print(f'Cell {cell_index + 1}/{len(nb.cells)}; elapsed {time.perf_counter() - started:.1f}s', flush=True)

    client = NotebookClient(nb, timeout=1800, kernel_name=kernel_name,
        resources={'metadata': {'path': str(run_dir)}},
        on_cell_start=on_start, on_cell_executed=on_done)
    try:
        client.execute()
    except Exception:
        nbformat.write(nb, run_dir / 'failed.ipynb')
        raise
    nb.cells = [cell for cell in nb.cells if cell.id in original_ids]
    nbformat.validate(nb)
    code_cells = [cell for cell in nb.cells if cell.cell_type == 'code']
    errors = [out for cell in code_cells for out in cell.outputs if out.output_type == 'error']
    assert not errors and all(cell.execution_count is not None for cell in code_cells)
    executed_path = run_dir / 'executed.ipynb'
    nbformat.write(nb, executed_path)
    if args.save_executed:
        nbformat.write(nb, source)
    exporter = HTMLExporter(exclude_input_prompt=True, exclude_output_prompt=True)
    html, _ = exporter.from_notebook_node(nb)
    (run_dir / 'preview.html').write_text(html, encoding='utf-8')
    kernel = json.loads((run_dir / 'kernel_check.json').read_text(encoding='utf-8'))
    figures = list((run_dir / 'artifacts/evidence_aggregates/figures').glob('*.png'))
    assert len(figures) >= 15
    result = {
        'python': platform.python_version(), 'platform': platform.platform(),
        'source_zip_sha256': hashlib.sha256(args.zip.read_bytes()).hexdigest(),
        'executed_notebook_sha256': hashlib.sha256(executed_path.read_bytes()).hexdigest(),
        'total_cells': len(nb.cells), 'executed_code_cells': len(code_cells),
        'errors': len(errors), 'figure_count': len(figures),
        'seconds': round(time.perf_counter() - started, 2),
        'colab_ui_exercised': False, 'colab_python_branch_shim': args.colab_shim,
        'stale_host_numpy_simulated': args.stale_host_numpy,
        'kernel': kernel, 'cell_timings': timings,
    }
    (run_dir / 'smoke_result.json').write_text(json.dumps(result, indent=2), encoding='utf-8')
    print(f'PASS: {len(code_cells)} code cells; {len(figures)} figures; {result["seconds"]}s; Python {platform.python_version()}.', flush=True)


if __name__ == '__main__':
    main()
