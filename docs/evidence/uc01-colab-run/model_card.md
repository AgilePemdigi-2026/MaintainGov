# MaintainGov sentiment experiment

Target: label proksi rating; belum gold sentiment. Mode: rating_proxy.
Model: LogisticRegression / Tuned. Train rows: 5517.
Holdout rows: 1632; grouped text overlap = 0.
Macro-F1: 0.6196; group-bootstrap CI95% [0.5885, 0.6440].
Balanced accuracy: 0.6259.

Best CV is a selection score, not independent test performance.
Rating-proxy performance must not be presented as accuracy against human sentiment.
Model vocabulary, parameters and corpus may encode source information; keep the model private until review.
Domain category, severity, grouping and priority are draft rules, not learned gold-label models.
Laravel/FastAPI/MySQL integration and PO acceptance are not verified by this notebook.

Source ZIP SHA256: 243ac29f1907baade790589ced4f331fc4e6d32e78691ee8477541aacdf1bdcf. Source license: belum dikonfirmasi.
