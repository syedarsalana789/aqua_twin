# AquaTwin ML Engine (research scaffold)

This folder deliberately does **not** return invented flood probabilities. Train and evaluate a model on verified historical event labels before serving predictions.

## Expected CSV
`training_data.csv` should contain numerical predictors such as rainfall_24h_mm, rainfall_72h_mm, water_level_m, elevation_m, slope_deg and a binary `flood_event` target. Rows must be time-stamped and location-aware. Keep source IDs and label provenance in your research dataset.

## Setup
```bash
python -m venv .venv
# Windows: .venv\\Scripts\\activate
pip install -r requirements.txt
python train.py training_data.csv
```
The script uses a chronological split, reports precision/recall/F1/ROC-AUC where possible, and saves a model only after training. This starter is not an operational early-warning system.
