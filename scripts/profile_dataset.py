"""Read-only profile of the supplied ZIP; output aggregates, never raw reviewers."""
from pathlib import Path
from zipfile import ZipFile
from collections import Counter, defaultdict
from datetime import datetime
import csv
import hashlib
import io
import json
import sys

sys.stdout.reconfigure(encoding="utf-8")
source = Path(sys.argv[1])
output = Path(sys.argv[2])
result = {"source_name": source.name, "sha256": hashlib.sha256(source.read_bytes()).hexdigest(), "files": {}}
with ZipFile(source) as archive:
    for name in archive.namelist():
        raw = archive.read(name)
        delimiter = "\t" if name.endswith(".tsv") else ";" if name == "slang.csv" else ","
        reader = csv.reader(io.StringIO(raw.decode("utf-8-sig")), delimiter=delimiter)
        header = next(reader)
        rows = []
        end_lines = []
        for row in reader:
            rows.append(row)
            end_lines.append(reader.line_num)
        valid_width = [row for row in rows if len(row) == len(header)]
        info = {
            "bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest(), "encoding": "utf-8-sig",
            "delimiter": delimiter, "columns": header, "parsed_records": len(rows),
            "record_width_counts": dict(Counter(map(len, rows))),
            "width_mismatch_count": len(rows) - len(valid_width),
            "width_mismatch_record_numbers": [i + 1 for i, row in enumerate(rows) if len(row) != len(header)],
            "width_mismatch_physical_end_lines": [line for row, line in zip(rows, end_lines) if len(row) != len(header)],
            "blank_cells_on_correct_width_records": {k: sum(not row[i].strip() for row in valid_width) for i, k in enumerate(header)},
            "exact_duplicate_excess": len(rows) - len(set(map(tuple, rows))),
        }
        if name == "Review Aplikasi Tangerang Live.csv":
            candidates = []
            invalid = Counter()
            for row in valid_width:
                reasons = []
                if not row[0].strip(): reasons.append("blank_reviewId")
                if not row[2].strip(): reasons.append("blank_review_text")
                if row[3].strip() not in {"1", "2", "3", "4", "5"}: reasons.append("invalid_rating")
                if not row[4].strip().isdigit(): reasons.append("invalid_thumbsUpCount")
                try:
                    parsed = datetime.strptime(row[5].strip(), "%Y-%m-%d %H:%M:%S")
                except ValueError:
                    reasons.append("invalid_date")
                invalid.update(reasons)
                if not reasons: candidates.append((row, parsed))
            text_counts = Counter(" ".join(row[2].casefold().split()) for row, _ in candidates)
            ids = Counter(row[0] for row in rows if row)
            info.update({
                "scope_note": "Candidate-valid is structural/type validity only; no automatic cleaning or approval.",
                "invalid_field_counts_on_correct_width_records": dict(invalid),
                "candidate_valid_records": len(candidates),
                "quarantine_candidate_records": len(rows) - len(candidates),
                "duplicate_review_id_excess": sum(v - 1 for v in ids.values()),
                "normalized_text_unique_on_candidates": len(text_counts),
                "normalized_text_duplicate_excess_on_candidates": sum(v - 1 for v in text_counts.values()),
                "normalized_text_duplicate_groups_on_candidates": sum(v > 1 for v in text_counts.values()),
                "rating_counts_on_candidates": dict(sorted(Counter(row[3] for row, _ in candidates).items())),
                "date_min_on_candidates": min(d for _, d in candidates).isoformat() if candidates else None,
                "date_max_on_candidates": max(d for _, d in candidates).isoformat() if candidates else None,
                "timezone": "not supplied",
                "gold_analysis_label_columns": [],
            })
        else:
            values = defaultdict(set)
            keys = Counter()
            for row in valid_width:
                key = " ".join(row[0].casefold().split())
                keys[key] += 1
                values[key].add(row[1].strip())
            info.update({"normalized_key_duplicate_excess": sum(v - 1 for v in keys.values()), "normalized_keys_with_conflicting_values": sum(len(v) > 1 for v in values.values())})
        result["files"][name] = info
    def words(name):
        return {" ".join(row["word"].casefold().split()) for row in csv.DictReader(io.StringIO(archive.read(name).decode("utf-8-sig")), delimiter="\t")}
    result["sentiment_lexicon_overlap_unique_terms"] = len(words("positive.tsv") & words("negative.tsv"))
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps(result, ensure_ascii=False, indent=2))
