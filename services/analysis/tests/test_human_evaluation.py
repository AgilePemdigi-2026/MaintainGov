"""Synthetic CSV fixtures test the evaluator; they are not real human labels."""
import csv
import importlib.util
from pathlib import Path
import pytest
ROOT = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location("human_eval", ROOT / "scripts/evaluate_human_annotations.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

def write_csv(path, rows):
    with path.open("w", newline="") as stream:
        writer = csv.DictWriter(stream, fieldnames=list(rows[0]))
        writer.writeheader(); writer.writerows(rows)
    return path

def fixtures(tmp_path, source="human", approved="true"):
    annotations = write_csv(tmp_path / "annotations.csv", [
        {"reviewId":"r1","sentiment":"negative","label_source":source,"reviewer":"fixture-reviewer","approved":approved,"annotator_a":"fixture-a","annotator_b":"fixture-b"},
        {"reviewId":"r2","sentiment":"positive","label_source":source,"reviewer":"fixture-reviewer","approved":approved,"annotator_a":"fixture-a","annotator_b":"fixture-b"},
    ])
    predictions = write_csv(tmp_path / "predictions.csv", [{"reviewId":"r1","predicted":"negative"},{"reviewId":"r2","predicted":"negative"}])
    return annotations, predictions

def test_aggregate_metrics_use_consensus_labels_without_raw_text(tmp_path):
    a,p = fixtures(tmp_path)
    report = module.evaluate(a,p)
    assert report["accuracy"] == .5
    assert report["evaluated_rows"] == 2 and report["raw_text_in_output"] is False
    assert report["confusion_matrix"] == [[1,0,0],[0,0,0],[1,0,0]]

@pytest.mark.parametrize("source,approved", [("ai","true"),("rating_proxy","true"),("human","false")])
def test_nonhuman_or_unapproved_labels_cannot_be_reported_as_human(tmp_path,source,approved):
    a,p = fixtures(tmp_path,source,approved)
    with pytest.raises(ValueError):
        module.evaluate(a,p)

def test_train_rows_are_excluded_from_heldout_human_evaluation(tmp_path):
    a,p = fixtures(tmp_path)
    m = write_csv(tmp_path / "manifest.csv", [{"reviewId":"r1","partition":"train","text_group":"one"},{"reviewId":"r2","partition":"test","text_group":"two"}])
    report = module.evaluate(a,p,m)
    assert report["evaluated_rows"] == 1 and report["partition_counts"] == {"train":1,"test":1}
    assert report["accuracy"] == 0

def test_group_leakage_is_rejected(tmp_path):
    a,p = fixtures(tmp_path)
    m = write_csv(tmp_path / "manifest.csv", [{"reviewId":"r1","partition":"train","text_group":"same"},{"reviewId":"r2","partition":"test","text_group":"same"}])
    with pytest.raises(ValueError, match="leakage"):
        module.evaluate(a,p,m)

def test_holdout_only_predictions_do_not_require_predictions_for_excluded_train_rows(tmp_path):
    a,_ = fixtures(tmp_path)
    p = write_csv(tmp_path / "holdout.csv", [{"reviewId":"r2","predicted":"positive"}])
    m = write_csv(tmp_path / "manifest.csv", [{"reviewId":"r1","partition":"train","text_group":"one"},{"reviewId":"r2","partition":"test","text_group":"two"}])
    report = module.evaluate(a,p,m)
    assert report["evaluated_rows"] == 1 and report["accuracy"] == 1
