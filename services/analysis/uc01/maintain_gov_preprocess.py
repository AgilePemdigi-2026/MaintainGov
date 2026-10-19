import re
import unicodedata
import pandas as pd

NUMERIC_COLUMNS = ["char_count", "word_count", "negation_count", "exclaim_count", "question_count", "uppercase_ratio"]
NEGATORS = {"tidak", "bukan", "belum", "jangan"}

def normalize_text(text, slang):
    text = unicodedata.normalize("NFKC", str(text))
    text = re.sub(r"https?://\S+|www\.\S+", " urltoken ", text, flags=re.I)
    text = re.sub(r"\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b", " emailtoken ", text)
    text = re.sub(r"\d{5,}", " numbertoken ", text)
    tokens = re.findall(r"\w+|[^\w\s]", text.casefold())
    return " ".join(slang.get(token, token) for token in tokens)

def feature_frame(texts, slang):
    records = []
    for original in texts:
        normalized = normalize_text(original, slang)
        tokens = normalized.split()
        letters = [c for c in str(original) if c.isalpha()]
        records.append({"clean_text": normalized,
                        "char_count": len(normalized),
                        "word_count": sum(bool(re.fullmatch(r"\w+",token)) for token in tokens),
                        "negation_count": sum(token in NEGATORS for token in tokens),
                        "exclaim_count": normalized.count("!"),
                        "question_count": normalized.count("?"),
                        "uppercase_ratio": sum(c.isupper() for c in letters)/max(len(letters),1)})
    return pd.DataFrame(records)
