"""Export the internal API contract from the runtime models, or check for drift."""
from pathlib import Path
import argparse
import json
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "services/analysis"))
from app.schemas import AnalyzeRequest, AnalyzeResponse


def contracts():
    for name, model, mode in [("request", AnalyzeRequest, "validation"), ("response", AnalyzeResponse, "serialization")]:
        schema = model.model_json_schema(mode=mode)
        schema["$schema"] = "https://json-schema.org/draft/2020-12/schema"
        yield ROOT / f"contracts/analysis-{name}.schema.json", json.dumps(schema, indent=2, ensure_ascii=False) + "\n"


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    for path, content in contracts():
        if args.check:
            if path.read_text(encoding="utf-8") != content:
                raise SystemExit(f"Contract drift: {path.relative_to(ROOT)}; run scripts/export_analysis_contracts.py")
        else:
            path.write_text(content, encoding="utf-8")
    print("Analysis contracts match runtime models." if args.check else "Analysis contracts exported.")
