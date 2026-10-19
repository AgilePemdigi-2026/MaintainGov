"""Run against a local demo seeded with UserAccountSeeder; no private dataset needed."""
import copy
import json
import os
import time
import uuid
from http.cookiejar import CookieJar
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import HTTPCookieProcessor, Request, build_opener

BASE = os.environ.get("MAINTAINGOV_API_URL", "http://127.0.0.1:8000/api/v1")
PASSWORD = os.environ.get("MAINTAINGOV_DEMO_PASSWORD", "password123")
checks = {}


def session():
    jar = CookieJar()
    return jar, build_opener(HTTPCookieProcessor(jar))


def request(opener, path, body=None, method=None, headers=None, csrf=True):
    h = {"Accept": "application/json", "Origin": "http://127.0.0.1:3000", **(headers or {})}
    if body is not None and csrf:
        h["X-CSRF-TOKEN"] = request(opener, "/auth/csrf")[1]["token"]
    if isinstance(body, dict):
        h["Content-Type"] = "application/json"
        body = json.dumps(body).encode()
    try:
        with opener.open(Request(BASE + path, data=body, method=method, headers=h), timeout=150) as r:
            return r.status, json.load(r)
    except HTTPError as e:
        return e.code, json.loads(e.read())


def upload(opener, key, raw):
    boundary = "MaintainGovSmokeBoundary"
    body = (f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="reviews.csv"\r\nContent-Type: text/csv\r\n\r\n').encode() + raw + f"\r\n--{boundary}--\r\n".encode()
    return request(opener, "/analysis-batches", body, headers={
        "Content-Type": f"multipart/form-data; boundary={boundary}", "Idempotency-Key": key})


def wait(opener, batch_id):
    for _ in range(60):
        status, body = request(opener, f"/analysis-batches/{batch_id}")
        assert status == 200, body
        if body["data"]["status"] != "Diproses":
            return body["data"]
        time.sleep(1)
    raise AssertionError("Batch did not finish within 60 seconds")


def main():
    jar, op = session()
    assert request(op, "/auth/me")[0] == 401
    assert request(op, "/auth/login", {}, csrf=False)[0] == 419
    checks["anonymous_and_csrf"] = "passed"
    assert request(op, "/auth/login", {"email": "maintenance@maintaingov.test", "password": "wrong"})[0] == 401
    assert request(op, "/auth/login", {"email": "maintenance@maintaingov.test", "password": PASSWORD, "remember": True})[0] == 200
    assert request(op, "/auth/me")[1]["data"]["role"] == "Tim Maintenance"
    checks["login_me_remember"] = "passed"
    assert upload(op, uuid.uuid4().hex, b"wrong,header\na,b\n")[0] == 422
    checks["invalid_csv"] = "passed"
    raw = (Path(__file__).resolve().parents[1] / "services/analysis/examples/reviews.csv").read_bytes()
    key = uuid.uuid4().hex
    status, body = upload(op, key, raw)
    assert status == 202, body
    batch_id = body["data"]["batch_id"]
    data = wait(op, batch_id)
    assert data["status"] == "Selesai", data
    assert len(data["reviews"]) == 3 and len(data["cases"]) >= 1
    assert all(r["analysis_result"] is not None for r in data["reviews"])
    assert all(c["status_validasi"] == "Pending" for c in data["cases"])
    assert request(op, f"/analysis-batches/{batch_id}")[1]["data"] == data
    assert upload(op, key, raw)[1]["data"]["batch_id"] == batch_id
    assert len(request(op, f"/analysis-batches/{batch_id}")[1]["data"]["cases"]) == len(data["cases"])
    checks["real_model_queue_persistence_reload_idempotency"] = {
        "status": "passed", "reviews": len(data["reviews"]), "cases": len(data["cases"])}
    old_jar = CookieJar()
    for cookie in jar:
        old_jar.set_cookie(copy.copy(cookie))
    stolen = build_opener(HTTPCookieProcessor(old_jar))
    assert request(op, "/auth/logout", {})[0] == 200
    assert request(op, "/auth/me")[0] == 401
    assert request(stolen, "/auth/me")[0] == 401
    checks["logout_and_old_cookie_replay"] = "passed"
    _, dev = session()
    assert request(dev, "/auth/login", {"email": "developer@maintaingov.test", "password": PASSWORD})[0] == 200
    assert upload(dev, uuid.uuid4().hex, raw)[0] == 403
    assert request(dev, f"/analysis-batches/{batch_id}")[0] == 403
    checks["developer_rbac"] = "passed"
    print(json.dumps(checks, indent=2))


if __name__ == "__main__":
    main()
