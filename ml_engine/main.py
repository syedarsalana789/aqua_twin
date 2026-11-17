from fastapi import FastAPI
from pathlib import Path
from joblib import load
import pandas as pd
app = FastAPI(title="AquaTwin ML Engine", version="0.1")
MODEL = Path(__file__).with_name("aquatwin_model.joblib")
@app.get("/health")
def health():
    return {"status":"ready" if MODEL.exists() else "model_not_trained"}
@app.post("/predict")
def predict(payload: dict):
    if not MODEL.exists():
        return {"available":False,"message":"No validated model is installed. No risk score generated."}
    bundle=load(MODEL); model=bundle["model"]; features=bundle["features"]
    row={k:payload.get(k) for k in features}
    if any(v is None for v in row.values()):
        return {"available":False,"message":"Missing required model inputs."}
    X=pd.DataFrame([row],columns=features)
    probability=float(model.predict_proba(X)[0][1])
    return {"available":True,"experimental_probability":round(probability,4),"warning":"Research output only; not an official warning."}
