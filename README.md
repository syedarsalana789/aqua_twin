# AquaTwin — Local Flood & Water Monitoring System

Runs on a Windows PC using XAMPP (Apache + PHP + MySQL). The website and database are local; internet is required for online GloFAS flood-model requests and external official portals.

## Install
1. Extract the folder to `C:\xampp\htdocs\AquaTwin`.
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin and import `database/twin_db.sql` (database: `twin_db`).
4. Open `config/database.php` and confirm MySQL credentials.
5. Visit `http://localhost/AquaTwin/public/`.

## Demo logins
| Role | Email | Password |
|---|---|---|
| Admin | `admin@aquatwin.local` | `password` |
| Area Operator | `operator@aquatwin.local` | `password` |

The demo Area Operator is assigned only to Indus River — Sukkur and Hub River. Change demo passwords before use beyond a private local demonstration.

## Included local functions
- Two role-protected dashboards and public no-login flood map.
- GPS permission, manual place search and map coordinate selection.
- GloFAS-backed Open-Meteo Flood API proxy for modelled river discharge history/forecast.
- Copernicus Global Flood Monitoring (GFM) Sentinel-1 satellite flood extent WMS overlay on the map (live service; latest available acquisition, not continuous imagery).
- Official Pakistan FFD river-status portal embedded in the public interface with direct links to live river status and flood dashboard.
- MySQL storage for operator/gauge observations, incidents, notices and audit events.
- Admin can set station-specific warning and critical water-level thresholds.
- Operator observations and imported CSV gauge records trigger local in-app alerts when configured thresholds are crossed.
- Admin can import gauge CSV files. Required columns: `station_name, observed_at, water_level_m, rainfall_mm, source`. Station name must exactly match a configured location.
- Admin can upload verified flood-extent GeoJSON polygons; the public Leaflet map renders them as overlays.
- No SMS or WhatsApp.

## Data-source limits
- GloFAS/Open-Meteo returns modelled river discharge in m³/s, not measured water height in metres. It does not provide global sea-surge or flash-flood predictions.
- Pakistan FFD publishes live river status, gauge information and official bulletins on its portal. This build links to that official portal and supports local CSV ingestion; it does not claim an undocumented FFD API integration.
- Satellite flood extent uses the public Copernicus GFM WMS service on demand; additional verified GeoJSON flood layers can also be uploaded by Admin. Latest satellite observations depend on Sentinel-1 acquisition and processing time.
- Threshold alerts are generated from configured station limits and entered/imported measurements. They are AquaTwin local alerts, not official warnings. Configure thresholds only from verified station-specific flood-stage values.
- A map pin is a reference location, not proof that a live gauge exists there. Missing readings show as unavailable.

## Data portals
- Pakistan FFD live river status: https://ffd.pmd.gov.pk/index.php/river-state
- FFD early warning system: https://ffd.pmd.gov.pk/flood-ews
- Copernicus GloFAS / Global Flood Monitoring: https://global-flood.emergency.copernicus.eu/
- Copernicus data access: https://emergency.copernicus.eu/data/

See `public/sample_gauge.csv` for the import format. The sample row is illustrative only; replace it with a verified station reading.


## Automated FFD ingestion, gauge data and forecast outlook (added)

**Existing AquaTwin installation:** Back up `twin_db` first, then import `database/upgrade_automated_features.sql` in phpMyAdmin. This migration only creates the new tables and does not drop existing records. For a brand-new installation, import `database/twin_db.sql` as usual.

- Public dashboard calls `public/api/ffd.php`. When the latest successful FFD fetch is older than 15 minutes, the endpoint attempts a fresh fetch from the official FFD homepage and stores parsed river/station discharge rows in `external_gauge_observations`.
- Admin → Flood data management → **Sync FFD now** runs the same ingestion manually.
- For reliable background ingestion even when no visitor opens the site, configure Windows Task Scheduler to run every 15 minutes:
  - Program: `C:\xampp\php\php.exe`
  - Arguments: `C:\xampp\htdocs\AquaTwin\cron\sync_ffd.php`
  - Start in: `C:\xampp\htdocs\AquaTwin`
- FFD public summary values are discharge in **cusecs**, not water level in metres. AquaTwin stores the discharge as published and leaves `water_level_m` NULL. It never converts discharge into an invented water height. If the upstream FFD page changes its HTML, parser sync fails safely and keeps the last successful records.
- The public 7-day outlook calls Open-Meteo/Copernicus GloFAS, compares forecast discharge/ensemble maximum against the previous 92-day historical 90th percentile, and stores the result in `flood_forecasts`. This is an experimental statistical/ensemble risk heuristic, **not a trained AI/ML model**, not an official warning, and not a gauge measurement.
- A real supervised AI model requires a quality-controlled labelled history of gauge readings and flood outcomes. `ml_engine/train.py` is a training scaffold; do not train it on generated/demo values. The system does not fabricate an AI model or confidence score.
- Network availability, PHP cURL/allow_url_fopen, FFD page structure, and Open-Meteo availability affect live sync. Test on your own XAMPP with internet enabled. PHP CLI and MySQL must be accessible to Task Scheduler.
