"""Isolated API contract tests: stub sentiment model, real domain/grouping logic.
These tests DO NOT validate compatibility of the actual model.joblib artifact.
"""
import pytest
from fastapi.testclient import TestClient
from app import main, pipeline


class StubSentimentModel:
    def predict(self, feature_frame):
        return ['negative' if 'tidak bisa login' in text else 'positive'
                for text in feature_frame['clean_text']]


@pytest.fixture
def client(monkeypatch):
    # Avoid loading scikit-learn 1.6.1 artifact under newer sklearn in contract-only tests.
    def fake_resources():
        _, slang, rules, sha12 = resources_metadata()
        return StubSentimentModel(), slang, rules, sha12

    monkeypatch.setattr(main, 'load_resources', fake_resources)
    monkeypatch.setattr(pipeline, 'load_resources', fake_resources)
    with TestClient(main.app) as instance:
        yield instance


def resources_metadata():
    import hashlib, json
    from app.pipeline import ARTIFACT_DIR
    slang = json.loads((ARTIFACT_DIR / 'slang_map.json').read_text(encoding='utf-8'))
    rules = json.loads((ARTIFACT_DIR / 'domain_rules.json').read_text(encoding='utf-8'))
    sha12 = hashlib.sha256(json.dumps(rules, sort_keys=True).encode()).hexdigest()[:12]
    return None, slang, rules, sha12


def test_health_json_contract(client):
    assert client.get('/health').json()['status'] == 'ok'
    response = client.post('/v1/analyze', json={
        'batch_id': 'batch-001',
        'reviews': [
            {'review_id': '1', 'text': 'Gak bisa login setelah update'},
            {'review_id': '2', 'text': 'Aplikasi tidak lambat'},
            {'review_id': '3', 'text': 'Mohon tambah fitur baru'},
        ],
    })
    assert response.status_code == 200, response.text
    payload = response.json()
    assert payload['summary']['total_reviews'] == 3
    assert payload['summary']['candidate_issue_reviews'] == 2
    assert payload['summary']['candidate_cases'] == 2
    assert len(payload['cases']) == 2
    assert all(case['persisted'] is False and case['proposed_status'] == 'Pending' for case in payload['cases'])
    assert sum(case['frequency'] for case in payload['cases']) == 2
    assert payload['results'][1]['category'] == 'other'
    assert payload['results'][1]['sentiment_lexicon'] is None


def test_duplicate_id_rejected(client):
    result = client.post('/v1/analyze', json={
        'batch_id': 'batch-001',
        'reviews': [
            {'review_id': 'dup', 'text': 'alpha'},
            {'review_id': 'dup', 'text': 'beta'},
        ]
    })
    assert result.status_code == 422


def test_csv_contract(client):
    content = ('reviewId,Review Teks,rating,date\n'
               '001,Gak bisa login,1,2026-10-18 10:00:00\n'
               '002,Review bagus,5,2026-10-18 10:00:00\n'
               '003,Invalid rating,10,2026-10-18 10:00:00\n')
    response = client.post('/v1/analyze-csv?batch_id=batch-002', files={
        'file': ('example.csv', content.encode('utf-8'), 'text/csv')
    })
    assert response.status_code == 200, response.text
    payload = response.json()
    assert payload['csv_audit'] == {'accepted': 2, 'quarantined': 1}
    assert payload['summary']['candidate_issue_reviews'] == 1
