"""HTTP steps; no browser behavior is inferred from these checks."""
from pathlib import Path
import copy
import io
import csv
import sys
import uuid
from urllib.error import URLError
from urllib.request import urlopen, build_opener, HTTPCookieProcessor
from http.cookiejar import CookieJar
import os
from behave import given, when, then
ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / "scripts"))
import smoke_uc01_http as api

@given("a fresh unauthenticated API session")
def fresh(context):
    context.jar, context.client = api.session()
    context.status, context.body = api.request(context.client, "/auth/me")
    assert context.status == 401

@when('I log in as "{role}"')
def login(context, role):
    email = {"maintenance": "maintenance@maintaingov.test", "developer": "developer@maintaingov.test"}[role]
    context.status, context.body = api.request(context.client, "/auth/login", {"email": email, "password": api.PASSWORD})
    assert context.status == 200, context.body

@then('my API role is "{role}"')
def role(context, role):
    status, body = api.request(context.client, "/auth/me")
    assert status == 200 and body["data"]["role"] == role

@when("I log in with an invalid password")
def invalid(context):
    context.status, context.body = api.request(context.client, "/auth/login", {"email": "maintenance@maintaingov.test", "password": "incorrect-test-password"})

@then("the HTTP response is {status:d}")
def http_status(context, status):
    assert context.status == status, context.body

@then("the API does not identify which credential was wrong")
def generic_error(context):
    assert context.body["message"] == "Email atau password salah."
    assert api.request(context.client, "/auth/me")[0] == 401

@when("I log out and replay my old cookie")
def logout(context):
    old = CookieJar()
    for cookie in context.jar:
        old.set_cookie(copy.copy(cookie))
    assert api.request(context.client, "/auth/logout", {})[0] == 200
    assert api.request(context.client, "/auth/me")[0] == 401
    context.status, context.body = api.request(build_opener(HTTPCookieProcessor(old)), "/auth/me")

def submit(context, raw):
    context.key, context.raw = uuid.uuid4().hex, raw
    context.status, context.body = api.upload(context.client, context.key, raw)
    if context.status == 202:
        context.batch_id = context.body["data"]["batch_id"]

@when("I submit the valid feedback fixture")
def valid_csv(context):
    submit(context, (ROOT / "services/analysis/examples/reviews.csv").read_bytes())

@when("I submit a CSV without the required columns")
def invalid_csv(context):
    submit(context, b"wrong,header\none,two\n")

@then("the response explains the CSV structure problem")
def csv_error(context):
    assert "Struktur CSV tidak sesuai" in str(context.body) and "reviewId" in str(context.body)

@then('the batch eventually becomes "{status}"')
def completed(context, status):
    assert context.status == 202, context.body
    context.result = api.wait(context.client, context.batch_id)
    assert context.result["status"] == status, context.result

@then("Pending cases are linked to their source reviews")
def sources(context):
    cases, reviews = context.result["cases"], context.result["reviews"]
    assert cases and all(case["status_validasi"] == "Pending" for case in cases)
    ids = {case["case_id"] for case in cases}
    assert all(r["analysis_result"] is not None for r in reviews)
    assert all(r["case_id"] is None or r["case_id"] in ids for r in reviews)
    assert all(any(r["case_id"] == case["case_id"] for r in reviews) for case in cases)
    context.identifiers = ([r["review_id"] for r in reviews], [c["case_id"] for c in cases])

@when("I request the same batch again and replay the upload")
def replay(context):
    status, response = api.upload(context.client, context.key, context.raw)
    assert status == 202 and response["data"]["batch_id"] == context.batch_id
    status, response = api.request(context.client, "/analysis-batches/" + str(context.batch_id))
    assert status == 200
    context.result = response["data"]

@then("the stored review and case identifiers are unchanged")
def stable(context):
    assert context.identifiers == ([r["review_id"] for r in context.result["reviews"]], [c["case_id"] for c in context.result["cases"]])

@when("I submit a fixture with 201 reviews")
def many_reviews(context):
    stream = io.StringIO()
    writer = csv.writer(stream)
    writer.writerow(["reviewId", "Review Teks", "rating", "date"])
    for index in range(1, 202):
        writer.writerow(["bdd-" + str(index), "Tidak bisa login setelah update", 1, "2026-10-24 08:00:00"])
    submit(context, stream.getvalue().encode())

@then("review 201 is accessible on a later page")
def beyond_200(context):
    status, response = api.request(context.client, "/analysis-batches/" + str(context.batch_id) + "/reviews?page=9&per_page=25")
    assert status == 200 and response["meta"]["total"] == 201
    assert len(response["data"]) == 1 and response["data"][0]["source_review_id"] == "bdd-201"

@given("the configured analysis service is unavailable")
def unavailable(context):
    endpoint = os.environ.get("MAINTAINGOV_ANALYSIS_URL", "http://127.0.0.1:8001")
    try:
        with urlopen(endpoint + "/internal/health", timeout=2) as response:
            assert response.status != 200, "Stop the dedicated test analysis service before this scenario."
    except URLError:
        pass

@then("no partial analysis output is stored")
def no_partial(context):
    assert context.result["cases"] == []
    assert all(r["analysis_result"] is None and r["case_id"] is None for r in context.result["reviews"])
