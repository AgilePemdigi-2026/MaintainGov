"""Evaluate reviewed human labels without publishing review text or assuming a gold set exists."""
import argparse
import csv
import json
from pathlib import Path
from sklearn.metrics import classification_report, confusion_matrix, accuracy_score

LABELS = ["negative", "neutral", "positive"]
TRUTHY = {"true", "yes", "1"}

def indexed_csv(path, required):
    with Path(path).open(encoding="utf-8-sig", newline="") as stream:
        reader = csv.DictReader(stream)
        missing = set(required) - set(reader.fieldnames or [])
        if missing:
            raise ValueError("Missing columns: " + ", ".join(sorted(missing)))
        rows = list(reader)
    ids = [row["reviewId"].strip() for row in rows]
    if any(not identifier for identifier in ids) or len(ids) != len(set(ids)):
        raise ValueError("reviewId must be nonempty and unique.")
    return dict(zip(ids, rows))

def evaluate(annotations, predictions, manifest=None):
    gold = indexed_csv(annotations, ["reviewId", "sentiment", "label_source", "reviewer", "approved", "annotator_a", "annotator_b"])
    predicted = indexed_csv(predictions, ["reviewId", "predicted"])
    approved = {key: row for key, row in gold.items() if row["approved"].strip().lower() in TRUTHY}
    if not approved:
        raise ValueError("No approved human labels. Annotation and acceptance are still pending.")
    for row in approved.values():
        if row["label_source"].strip().lower() != "human":
            raise ValueError("Only reviewed human labels can be reported as human evaluation.")
        if row["sentiment"] not in LABELS or not all(row[name].strip() for name in ["reviewer", "annotator_a", "annotator_b"]):
            raise ValueError("Each approved row needs a valid consensus sentiment, two raters and a reviewer.")
        if row["annotator_a"].strip() == row["annotator_b"].strip():
            raise ValueError("The two rater identifiers must differ.")
    selected = approved
    partition_summary = None
    if manifest:
        split = indexed_csv(manifest, ["reviewId", "partition", "text_group"])
        if set(approved) - set(split):
            raise ValueError("Approved review missing from split manifest.")
        partitions = {}
        group_partitions = {}
        for row in split.values():
            group_partitions.setdefault(row["text_group"], set()).add(row["partition"])
        if any(len(partitions) > 1 for partitions in group_partitions.values()):
            raise ValueError("Text-group leakage across partitions.")
        for key in approved:
            partition = split[key]["partition"]
            partitions[partition] = partitions.get(partition, 0) + 1
        partition_summary = partitions
        selected = {key: row for key, row in approved.items() if split[key]["partition"] == "test"}
        if not selected:
            raise ValueError("No approved human labels are in the held-out test partition.")
    missing = set(selected) - set(predicted)
    if missing:
        raise ValueError(f"{len(missing)} selected reviews have no prediction.")
    actual = [row["sentiment"] for row in selected.values()]
    values = [predicted[key]["predicted"] for key in selected]
    if any(value not in LABELS for value in values):
        raise ValueError("Predicted sentiment has an unsupported value.")
    return {
        "evaluation_scope": "human_labels_on_existing_heldout_partition" if manifest else "reviewed_human_audit_sample; independence_not_verified",
        "approved_annotation_count": len(approved), "evaluated_rows": len(selected), "partition_counts": partition_summary,
        "label_order": LABELS, "accuracy": accuracy_score(actual, values),
        "classification_report": classification_report(actual, values, labels=LABELS, output_dict=True, zero_division=0),
        "confusion_matrix": confusion_matrix(actual, values, labels=LABELS).tolist(),
        "limitations": ["Human identity and approval are declarations supplied by the reviewers; verify their provenance.",
                        "Small or purposive samples do not establish population accuracy.",
                        "Dataset source/license and PO acceptance must be checked separately."],
        "raw_text_in_output": False,
    }

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--annotations", required=True, type=Path)
    parser.add_argument("--predictions", required=True, type=Path)
    parser.add_argument("--split-manifest", type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    try:
        report = evaluate(args.annotations, args.predictions, args.split_manifest)
    except ValueError as error:
        parser.exit(2, str(error) + "\n")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + "\n")
    print(f"Evaluated {report['evaluated_rows']} reviewed rows; aggregate report saved.")
