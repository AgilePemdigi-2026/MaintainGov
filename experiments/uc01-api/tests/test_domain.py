import json
from pathlib import Path

from app.maintain_gov_preprocess import normalize_text
from app.pipeline import case_priority, domain_result

ROOT = Path(__file__).resolve().parent.parent
RULES = json.loads((ROOT / 'artifacts' / 'domain_rules.json').read_text(encoding='utf-8'))
SLANG = json.loads((ROOT / 'artifacts' / 'slang_map.json').read_text(encoding='utf-8'))


def test_login_rule():
    assert domain_result(normalize_text('gak bisa login', SLANG), RULES)['rule_key'] == 'login_failure'


def test_negation():
    assert domain_result(normalize_text('aplikasi tidak lambat', SLANG), RULES)['rule_key'] is None


def test_priority():
    assert case_priority('high', 3) == 79.0
