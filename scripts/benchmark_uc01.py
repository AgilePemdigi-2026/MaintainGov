"""Measure a real UC01 upload without publishing source text or pretending synthetic data is original."""
import argparse
import csv
import io
import json
from pathlib import Path
import time
import uuid
import smoke_uc01_http as api

def synthetic(rows):
    stream = io.StringIO()
    writer = csv.writer(stream)
    writer.writerow(["reviewId", "Review Teks", "rating", "date"])
    texts = [("Tidak bisa login setelah update",1),("Aplikasi bagus sekali",5),("Aplikasi sangat lambat",2),
             ("Tolong tambah fitur baru",3),("Tidak bisa login, muncul error\nsetelah update",1)]
    for index in range(rows):
        text,rating = texts[index % len(texts)]
        writer.writerow([f"capacity-{rows}-{index}",text,rating,"2026-10-24 08:00:00"])
    return stream.getvalue().encode()

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--csv", type=Path)
    source.add_argument("--synthetic-rows", type=int)
    parser.add_argument("--timeout", type=int, default=240)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if args.synthetic_rows is not None and not 1 <= args.synthetic_rows <= 10000:
        parser.error("--synthetic-rows must be between 1 and 10000.")
    raw = args.csv.read_bytes() if args.csv else synthetic(args.synthetic_rows)
    _,client = api.session()
    code,_ = api.request(client,"/auth/login",{"email":"maintenance@maintaingov.test","password":api.PASSWORD})
    if code != 200: raise SystemExit(f"Login failed: HTTP {code}.")
    start = time.monotonic()
    code,body = api.upload(client,uuid.uuid4().hex,raw)
    uploaded = time.monotonic()
    if code != 202: raise SystemExit(f"Upload rejected: HTTP {code}.")
    batch = body["data"]["batch_id"]
    while True:
        code,body = api.request(client,f"/analysis-batches/{batch}/status")
        if code != 200: raise SystemExit(f"Status request failed: HTTP {code}.")
        snapshot = body["data"]
        if snapshot["status"] != "Diproses": break
        if time.monotonic()-start > args.timeout: raise SystemExit("Batch timed out; inspect worker and batch status.")
        time.sleep(.25)
    processed = time.monotonic()
    if snapshot["status"] != "Selesai": raise SystemExit("Analysis failed; inspect the batch error in the application.")
    identifiers=set(); page=1
    while True:
        code,body = api.request(client,f"/analysis-batches/{batch}/reviews?page={page}&per_page=100")
        if code != 200: raise SystemExit(f"Result request failed: HTTP {code}.")
        for review in body["data"]:
            if review["review_id"] in identifiers or review["analysis_result"] is None:
                raise SystemExit("Duplicate or missing persisted result.")
            identifiers.add(review["review_id"])
        if page >= body["meta"]["last_page"]: break
        page += 1
    if len(identifiers) != snapshot["review_count"]: raise SystemExit("Result count differs from accepted review count.")
    report={"dataset_kind":"source CSV supplied by operator" if args.csv else "synthetic capacity fixture",
            "original_dataset_identity_verified":False,"rows":snapshot["review_count"],"cases":snapshot["case_count"],
            "upload_seconds":round(uploaded-start,4),"observed_end_to_end_seconds_including_queue":round(processed-start,4),
            "all_paginated_results_accessible":True,"raw_text_in_report":False,"human_accuracy_evaluated":False}
    args.output.parent.mkdir(parents=True,exist_ok=True)
    args.output.write_text(json.dumps(report,indent=2)+"\n")
    print(json.dumps(report,indent=2))

if __name__ == "__main__": main()
