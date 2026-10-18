import pytest
import sklearn

pytestmark = pytest.mark.skipif(
    sklearn.__version__ != "1.6.1",
    reason="Real inference requires original Colab scikit-learn==1.6.1",
)

from fastapi.testclient import TestClient
from app.main import app


def test_health_and_analysis():
    with TestClient(app) as client:
        assert client.get('/health').json()['status'] == 'ok'
        result = client.post('/v1/analyze', json={
            'batch_id': 'test-1',
            'reviews': [
                {'review_id': 'a', 'text': 'Gak bisa login'},
                {'review_id': 'b', 'text': 'Aplikasi tidak lambat'}
            ],
        })
        assert result.status_code == 200, result.text
        body = result.json()
        assert body['summary']['total_reviews'] == 2
        assert body['summary']['candidate_issue_reviews'] == 1
        assert body['cases'][0]['proposed_status'] == 'Pending'
        assert body['results'][1]['disposition'] == 'needs_manual_review'


def test_invalid_payload():
    with TestClient(app) as client:
        response = client.post('/v1/analyze', json={
            'batch_id': 'test-2',
            'reviews': [{'review_id': 'same', 'text': 'x'}, {'review_id': 'same', 'text': 'y'}]
        })
        assert response.status_code == 422


def test_csv_upload():
    with TestClient(app) as client:
        content = 'reviewId,Review Teks,rating,date\n001,Gak bisa login,1,2026-10-18 10:00:00\n'
        response = client.post('/v1/analyze-csv?batch_id=test-3', files={
            'file': ('reviews.csv', content.encode(), 'text/csv')
        })
        assert response.status_code == 200, response.text
        assert response.json()['csv_audit']['accepted'] == 1
