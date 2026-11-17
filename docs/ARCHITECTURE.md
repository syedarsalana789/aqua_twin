# AquaTwin flood-monitoring architecture

- PHP public interface: GPS permission, place search, map click-to-query.
- Open-Meteo Flood API proxy: daily GloFAS modelled river discharge, historical + forecast.
- Leaflet / OpenStreetMap: Pakistan and global reference locations; arbitrary map coordinate selection.
- MySQL: users, locations, assignments, observations, alerts, incidents and audit logs.
- Admin and Area Operator only; no public login.
- Actual water-level values are stored only from configured gauge/operator observations.
- No SMS or WhatsApp delivery.

## Current integration boundaries
GloFAS is a global river-discharge model. It is not a global live water-level gauge network, and does not provide coastal/sea flood or flash-flood predictions. The selected grid cell may not represent the nearest river correctly. Pakistan FFD is listed as an official source reference but no unsupported/private API endpoint is assumed. A future integration should use an officially documented feed or imported bulletins and record source, timestamp, station ID, units and quality flags.

## Production requirements
Obtain gauge feeds and official alert agreements; define station-specific warning/action levels; integrate Copernicus GFM for satellite flood extent; build coastal surge/sea-level sources separately; add automated API caching and monitoring, backups, HTTPS, rate limiting and comprehensive tests. Do not label modelled discharge as measured water height.


## Live external data connections
- Pakistan FFD: official river-state and flood-dashboard pages are embedded/linked for current national gauge/discharge status. No undocumented endpoint is reverse-engineered. Admin CSV import provides local database snapshots and threshold alerts.
- Copernicus GFM: public GFM WMS (`https://geoserver.gfm.eodc.eu/geoserver/gfm/wms`) is exposed as an optional Leaflet overlay. Capabilities are fetched server-side to avoid browser CORS restrictions; the latest available Sentinel-1 observed flood-extent layer is selected from advertised layers.
- GloFAS/Open-Meteo: point-based modelled river discharge query for user GPS/manual map point.
- Satellite flood products are not continuous video; each update follows Sentinel-1 acquisition and processing. Absence of a flood polygon does not prove absence of flooding.


## External data ingestion and risk outlook
FFD homepage ingestion is best-effort HTML parsing of explicitly rendered river discharge summary rows. Stored as cusecs, never relabelled as metres. `data_sync_runs` records fetch outcomes. GloFAS outlook uses the previous 92-day 90th percentile as a transparent heuristic. It is not a trained ML model.
