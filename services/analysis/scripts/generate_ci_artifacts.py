"""Generate minimal model artifacts for CI testing.

This script creates a small, deterministic sentiment model and the
required JSON artifacts so that the FastAPI test suite can run in CI
without needing the real (private) Colab-trained model.

The model is intentionally trained on a small, curated dataset that
covers the exact phrases used in the test suite, ensuring test
assertions (e.g. "negative" for login complaints, "positive" for
praise) pass reliably.

Usage:
    python scripts/generate_ci_artifacts.py [--output-dir artifacts]
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

import joblib
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import SGDClassifier
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import StandardScaler

# ---------------------------------------------------------------------------
# Ensure we can import the project's own preprocessing module
# ---------------------------------------------------------------------------
PROJECT_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(PROJECT_ROOT))

from uc01.maintain_gov_preprocess import NUMERIC_COLUMNS, feature_frame

# ---------------------------------------------------------------------------
# Minimal training corpus — covers test-suite assertions
# ---------------------------------------------------------------------------
SLANG_MAP: dict[str, str] = {
    "gak": "tidak",
    "ga": "tidak",
    "tdk": "tidak",
    "gk": "tidak",
    "ngga": "tidak",
    "nggak": "tidak",
    "gabisa": "tidak bisa",
    "gatau": "tidak tahu",
    "bgt": "banget",
    "bgtt": "banget",
    "emg": "memang",
    "emng": "memang",
    "lg": "lagi",
    "yg": "yang",
    "dg": "dengan",
    "dgn": "dengan",
    "utk": "untuk",
    "krn": "karena",
    "tp": "tapi",
    "org": "orang",
    "blm": "belum",
    "sm": "sama",
    "bkn": "bukan",
    "jgn": "jangan",
    "bs": "bisa",
    "hrs": "harus",
    "msh": "masih",
    "sdh": "sudah",
    "udh": "sudah",
    "udah": "sudah",
    "aja": "saja",
    "doang": "saja",
    "banget": "sekali",
    "bener": "benar",
    "kayak": "seperti",
    "gimana": "bagaimana",
    "gitu": "begitu",
    "nih": "ini",
    "dong": "",
    "sih": "",
    "deh": "",
    "lah": "",
    "kok": "",
}

TRAINING_DATA: list[tuple[str, str]] = [
    # --- negative ---
    ("Tidak bisa login setelah update", "negative"),
    ("Tidak bisa login sejak pembaruan aplikasi", "negative"),
    ("Gak bisa login sejak pembaruan", "negative"),
    ("Aplikasi sering crash dan error", "negative"),
    ("Lambat sekali loading datanya", "negative"),
    ("Gagal upload dokumen berkali kali", "negative"),
    ("Fitur pencarian tidak berfungsi", "negative"),
    ("Selalu error saat buka halaman utama", "negative"),
    ("Login gagal terus menerus sangat menjengkelkan", "negative"),
    ("Data saya hilang setelah pembaruan", "negative"),
    ("Tidak responsif di handphone", "negative"),
    ("Tombol tidak bisa diklik error terus", "negative"),
    ("Pelayanan buruk sekali", "negative"),
    ("Sangat mengecewakan tidak bisa dipakai", "negative"),
    ("Aplikasi sangat lambat tidak bisa digunakan", "negative"),
    # --- positive ---
    ("Aplikasi bagus sekali", "positive"),
    ("Aplikasi tidak lambat dan bagus", "positive"),
    ("Sangat membantu terima kasih", "positive"),
    ("Fitur baru sangat berguna", "positive"),
    ("Proses cepat dan mudah digunakan", "positive"),
    ("Tampilan bersih dan modern", "positive"),
    ("Semua fitur berjalan dengan baik", "positive"),
    ("Pelayanan sangat memuaskan", "positive"),
    ("Mantap aplikasinya bermanfaat", "positive"),
    ("Bagus dan cepat prosesnya", "positive"),
    ("Sangat puas dengan layanan ini", "positive"),
    ("Keren banget fiturnya lengkap", "positive"),
    ("Terima kasih aplikasi ini sangat membantu", "positive"),
    ("Luar biasa sangat memuaskan", "positive"),
    ("Terbaik mudah dan cepat", "positive"),
    # --- neutral ---
    ("Mohon tambah fitur cetak dokumen", "neutral"),
    ("Biasa saja tidak ada masalah", "neutral"),
    ("Sudah cukup untuk kebutuhan saya", "neutral"),
    ("Tidak ada keluhan khusus", "neutral"),
    ("Fitur standar cukup lengkap", "neutral"),
    ("Lumayan bisa dipakai", "neutral"),
    ("Perlu ditambahkan fitur export data", "neutral"),
    ("Cukup oke lah untuk saat ini", "neutral"),
    ("Tolong tambahkan notifikasi email", "neutral"),
    ("Belum ada fitur laporan bulanan", "neutral"),
]

DOMAIN_RULES: dict = {
    "status": "draft_pending_team_review",
    "issues": [
        {
            "key": "login_issue",
            "title": "Masalah login setelah pembaruan",
            "category": "bug",
            "severity": "high",
            "module": "auth",
            "feature": "login",
            "recommendation": "Periksa kompatibilitas sesi setelah update.",
            "phrases": [
                "tidak bisa login",
                "gagal login",
                "login gagal",
                "login error",
            ],
        },
        {
            "key": "performance_issue",
            "title": "Performa aplikasi lambat",
            "category": "performance",
            "severity": "medium",
            "module": "core",
            "feature": "loading",
            "recommendation": "Optimalkan query dan caching.",
            "phrases": [
                "lambat sekali",
                "sangat lambat",
                "loading lama",
                "tidak responsif",
            ],
        },
        {
            "key": "crash_issue",
            "title": "Aplikasi crash atau error",
            "category": "bug",
            "severity": "high",
            "module": "core",
            "feature": "stability",
            "recommendation": "Periksa error handling dan logging.",
            "phrases": [
                "sering crash",
                "selalu error",
                "error terus",
            ],
        },
        {
            "key": "upload_issue",
            "title": "Gagal upload dokumen",
            "category": "bug",
            "severity": "medium",
            "module": "document",
            "feature": "upload",
            "recommendation": "Periksa batas ukuran file dan format yang didukung.",
            "phrases": [
                "gagal upload",
                "tidak bisa upload",
                "upload error",
            ],
        },
        {
            "key": "feature_request",
            "title": "Permintaan fitur baru",
            "category": "enhancement",
            "severity": "low",
            "module": None,
            "feature": None,
            "recommendation": "Evaluasi permintaan fitur pada sprint berikutnya.",
            "phrases": [
                "mohon tambah",
                "tolong tambahkan",
                "perlu ditambahkan",
            ],
        },
    ],
}


def build_model(slang: dict[str, str]) -> Pipeline:
    """Build and train a minimal sentiment pipeline matching the Colab architecture."""
    texts = [t for t, _ in TRAINING_DATA]
    labels = [l for _, l in TRAINING_DATA]

    features = feature_frame(texts, slang)

    feature_union = ColumnTransformer(
        [
            (
                "tfidf",
                TfidfVectorizer(
                    ngram_range=(1, 2),
                    min_df=1,
                    max_features=3000,
                    sublinear_tf=True,
                ),
                "clean_text",
            ),
            ("numeric", StandardScaler(), NUMERIC_COLUMNS),
        ],
        sparse_threshold=1.0,
        remainder="drop",
    )

    pipeline = Pipeline(
        [
            ("features", feature_union),
            (
                "classifier",
                SGDClassifier(
                    loss="modified_huber",
                    max_iter=2000,
                    random_state=42,
                    class_weight="balanced",
                ),
            ),
        ]
    )

    # Train multiple passes for stability on small data
    for _ in range(3):
        pipeline.fit(features, labels)

    return pipeline


def main() -> None:
    parser = argparse.ArgumentParser(description="Generate CI model artifacts")
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=PROJECT_ROOT / "artifacts",
        help="Directory to write artifacts to",
    )
    args = parser.parse_args()
    output_dir: Path = args.output_dir
    output_dir.mkdir(parents=True, exist_ok=True)

    print(f"Generating CI artifacts in {output_dir} ...")

    # 1. slang_map.json
    slang_path = output_dir / "slang_map.json"
    slang_path.write_text(json.dumps(SLANG_MAP, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"  [OK] {slang_path.name}")

    # 2. domain_rules.json
    rules_path = output_dir / "domain_rules.json"
    rules_path.write_text(json.dumps(DOMAIN_RULES, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"  [OK] {rules_path.name}")

    # 3. sentiment_pipeline.joblib
    model = build_model(SLANG_MAP)
    model_path = output_dir / "sentiment_pipeline.joblib"
    joblib.dump(model, model_path, compress=3)
    print(f"  [OK] {model_path.name}")

    # Quick sanity check
    test_features = feature_frame(
        ["Tidak bisa login setelah update", "Aplikasi bagus sekali"],
        SLANG_MAP,
    )
    predictions = list(model.predict(test_features))
    print(f"  Sanity check: {predictions}")
    assert predictions[0] == "negative", f"Expected 'negative', got '{predictions[0]}'"
    assert predictions[1] == "positive", f"Expected 'positive', got '{predictions[1]}'"

    print("Done — all artifacts generated successfully.")


if __name__ == "__main__":
    main()
