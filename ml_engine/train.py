import sys, json
import pandas as pd
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import classification_report, roc_auc_score
from sklearn.model_selection import train_test_split
from sklearn.pipeline import make_pipeline
from sklearn.impute import SimpleImputer
from joblib import dump

if len(sys.argv) != 2:
    raise SystemExit("Usage: python train.py training_data.csv")
df = pd.read_csv(sys.argv[1])
target = "flood_event"
if target not in df or df[target].nunique() < 2:
    raise SystemExit("CSV must include flood_event with both 0 and 1 labels.")
features = [c for c in df.select_dtypes(include="number").columns if c != target]
if not features:
    raise SystemExit("No numeric feature columns found.")
# Preserve chronological order if a timestamp is available; otherwise this is only a starter split.
if "timestamp" in df:
    df = df.sort_values("timestamp")
X, y = df[features], df[target].astype(int)
X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=.2, shuffle=False, stratify=None)
model = make_pipeline(SimpleImputer(strategy="median"), RandomForestClassifier(n_estimators=300, class_weight="balanced", random_state=42))
model.fit(X_train, y_train)
pred = model.predict(X_test)
print(classification_report(y_test, pred, zero_division=0))
if len(set(y_test)) == 2:
    print("ROC-AUC:", round(roc_auc_score(y_test, model.predict_proba(X_test)[:,1]), 4))
dump({"model": model, "features": features}, "aquatwin_model.joblib")
print("Saved aquatwin_model.joblib. Review geographic/time leakage and calibration before deployment.")
