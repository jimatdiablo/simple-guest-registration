# Simple Guest Registration

Reusable PHP + MySQL prototype for guest check-in, modem lookup, DHCP lease lookup, and modem reboot workflow.

## Repository status

This repository is currently public and source-available for authorized project collaboration, deployment review, and operational transparency. It is not open source. See `LICENSE` before copying, modifying, hosting, or redistributing the software.

## What this prototype includes

- Landing page titled Guest Registration
- Guest form fields:
  - Name
  - Phone Number
  - Lot Number dropdown from local modems.unit
  - Arrival Date (defaults to current date)
  - Departure Date
- Local MySQL tables:
  - guests
  - modems (mirrors Gunslinger customers structure)
- Landing-page modem refresh hook via configurable Gunslinger REST endpoint
- Submission flow:
  - Save guest record locally
  - Call configurable Gunslinger profile update endpoint
  - DDNet lease lookup by modem MAC using leases API pattern
  - SNMP reboot hook (config-gated)
- Multi-SG registration UX:
  - Lot dropdown grouped by service group (SG) with ascending lot order
  - Date-based availability filtering keeps SG grouping
- Admin registration view:
  - Registered Guests shows active registrations only
  - Registrations are separated by SG with sortable Unit/Arrival/Departure columns
- History report page with length-of-stay column
  - Shows active and historical rows
  - Historical rows are shaded

## Project structure

- app/public/index.php: UI, form submission, local API endpoints
- app/src: config, DB, repositories, service clients
- app/bin/auto_checkout.php: CLI entry point for scheduled auto-checkout runs
- db/init: schema and optional SQL seed transforms

## Container Images

Tagged and `main` branch pushes publish images to GitHub Container Registry:

```text
ghcr.io/jimatdiablo/simple-guest-registration:latest
ghcr.io/jimatdiablo/simple-guest-registration:main
ghcr.io/jimatdiablo/simple-guest-registration-dns:latest
ghcr.io/jimatdiablo/simple-guest-registration-dns:main
```

Version tags use the same tag name:

```text
ghcr.io/jimatdiablo/simple-guest-registration:v1.0.0
ghcr.io/jimatdiablo/simple-guest-registration:1.0.0
ghcr.io/jimatdiablo/simple-guest-registration:1.0
ghcr.io/jimatdiablo/simple-guest-registration:1
ghcr.io/jimatdiablo/simple-guest-registration-dns:v1.0.0
ghcr.io/jimatdiablo/simple-guest-registration-dns:1.0.0
ghcr.io/jimatdiablo/simple-guest-registration-dns:1.0
ghcr.io/jimatdiablo/simple-guest-registration-dns:1
```

Each published image also receives an immutable `sha-*` tag. Production deployments should prefer a version tag or SHA tag rather than `latest` so rollback remains predictable.

The production deployment template is `docker-compose.prod.yml`. It uses persistent named volumes for MySQL data and app storage/logs so normal image updates do not destroy deployment data. The app image includes an HTTP healthcheck against `/?action=health`; the DNS image includes a process healthcheck for `dnsmasq`.

## Quick start

The defaults in this README are lab-oriented examples from the current SGR test environment. Before production deployment, replace lab IPs, credentials, service groups, and endpoint URLs in `.env` with site-specific values.

1. In this folder, copy .env.example to .env.
2. Add your SQL export to db/init as 015_customers_seed.sql.
3. Start stack:

```bash
docker compose up --build -d
```

This now starts both the web app and a scheduler container. The scheduler runs the auto-checkout CLI every minute via cron, so expired reservations are processed even when no one is using the site.

4. Open: http://localhost:8088 or http://192.168.160.4
5. phpMyAdmin (optional DB UI): http://localhost:8089
  - Username: root
  - Password: value of MYSQL_ROOT_PASSWORD in .env

The stack also includes a lab poison-DNS service named `dns`. By default it binds UDP/TCP port 53 on `192.168.160.4` and resolves all IPv4 names to `192.168.160.4`. The web app publishes HTTP on `192.168.160.4:80` so DNS-steered clients can reach SGR without typing a port. The existing `localhost:8088` mapping remains available for local testing.

```bash
docker compose up --build -d dns
docker compose logs -f dns
```

For lab DHCP, point unregistered clients at DNS server `192.168.160.4`.

Quick DNS test from another machine on the lab network:

```bash
nslookup example.com 192.168.160.4
```

Expected answer: `192.168.160.4`.

Scheduler logs can be checked with:

```bash
docker compose logs -f scheduler
```

## Data seed notes

- On first DB startup, MySQL executes files in db/init in filename order.
- 001_schema.sql creates guests and modems.
- 002_seed_modems_from_customers.sql copies from customers to modems if your SQL export created customers.

## Integration config

Set these in .env. Values shown here are examples; the `192.168.160.0/24` addresses are lab placeholders, not public defaults:

- SERVICE_GROUPS=10,11
- DEFAULT_SERVICE_PROFILE=10baseservice
- GUNSLINGER_REFRESH_URL=
- GUNSLINGER_PROFILE_UPDATE_URL=
- GUNSLINGER_PROFILE_BOOTFILE_URL=
- GUNSLINGER_REFRESH_URL_BY_SG=
- GUNSLINGER_PROFILE_UPDATE_URL_BY_SG=
- GUNSLINGER_PROFILE_BOOTFILE_URL_BY_SG=
- GUNSLINGER_REFRESH_API_KEY=
- GUNSLINGER_UPDATE_ALLOWED_SGS=10,11
- DDNET_BASE_URL=http://192.168.160.220:4000/api/dhcp
- SNMP_ENABLED=false
- SNMP_RETRIES=3
- SNMP_RETRY_DELAY_MS=750
- POISON_DNS_BIND_IP=192.168.160.4
- POISON_DNS_TARGET_IP=192.168.160.4
- POISON_DNS_UPSTREAM_SERVERS=1.1.1.1,8.8.8.8

Notes:

- Profile update calls are hard-restricted to service groups listed in `GUNSLINGER_UPDATE_ALLOWED_SGS`.
- Default service profile settings are SG-specific and managed from Admin Settings using separate forms per SG.
- Each SG profile enforces prefix validation (for example, SG 11 requires profiles starting with `11`).
- `*_URL_BY_SG` settings are optional SG-to-endpoint maps for multi-container deployments. Supported entry separators are comma or semicolon.
- Map format supports `SG=URL` or `SG:URL` entries, for example: `GUNSLINGER_REFRESH_URL_BY_SG=10=http://host-a/getfreshcustlist.php;11=http://host-b/getfreshcustlist.php`.
- If an SG is not present in a map, the base URL setting (`GUNSLINGER_REFRESH_URL`, `GUNSLINGER_PROFILE_UPDATE_URL`, `GUNSLINGER_PROFILE_BOOTFILE_URL`) is used as fallback.

## Local APIs included

- GET /?action=api_refresh
  - Calls Gunslinger refresh endpoint and updates local modems when configured.
- POST /?action=api_profile_update
  - Body fields: sg, unit, profile
- GET /?action=gunslinger_refresh_api
  - Source-table refresh API for Gunslinger customer rows.
  - Optional query params:
    - service_groups=10,11
    - limit=5000
  - Optional auth:
    - Set GUNSLINGER_REFRESH_API_KEY and pass X-API-Key header or api_key query param.

## Known placeholders for production hardening

- Gunslinger request/response contracts are still configurable placeholders.
- DDNet lease response parser currently supports common shapes; lock exact field once final payload sample is provided.
- SNMP command options should be finalized with exact DOCSIS reboot OID/value and runtime network policy.

## Guest self-service (Phase 1)

Phase 1 introduces manual-code based guest reservation self-service without SMS/email dependencies.

- Guest receives and saves Access ID + Access Code at registration confirmation.
- Public Manage My Reservation page allows guest access with those credentials.
- Guests can request early checkout or departure extension.
- Staff/Admin users review requests from Guest Change Requests queue.
- Admin Settings includes policy toggles for enablement, request types, approval mode, reason requirement, and max extension days.

Detailed Phase 1 design and implementation notes are documented in:

- `GUEST_SELF_SERVICE_PHASE1.md`

### Phase 1 QA status

Manual validation was completed on 2026-05-06 with these confirmed outcomes:

- Guest access login works with Access ID + Access Code.
- Guest requests are submitted as pending for staff review.
- Staff approval updates reservation departure date.
- Extension conflict guard blocks invalid overlapping extensions.

### Phase 2 planned scope

- Brute-force protection and lockouts for guest access login.
- Staff/Admin guest access credential regeneration workflow.
- Guest Requests queue filters, sorting, and SLA highlighting.

### Phase 2.3 status (2026-05-06)

Guest Change Requests scale/readability enhancements are now active:

- Filters + sorting + SLA highlights
- Filtered CSV export
- Server-side pagination (rows-per-page + numeric paging)
- Queue metrics (open pending, median age, oldest age)
- Default-collapsed queue panel that auto-opens on decision/confirmation activity
- Upcoming Registrations page with arrival-window filter (7/14/30/60/90/all)
- Grouped top navigation with hover tooltips and Nav Help modal entry

### Maintenance update (2026-05-06)

- Browser cache-busting enabled for the main stylesheet (`/style.css`) using file modified time query versioning.
- Restore script now lives at `tools/create_restore_point.ps1`, creates timestamped `SimpleGuestService_*.zip` archives, and is scoped to back up only `projects/Simple-Guest-Registration`.

### Maintenance update (2026-05-08)

- Logs page cards now default to collapsed, with table sections using internal vertical scrolling to reduce long-page navigation.
- Admin and Master Admin users can both create Staff/Admin accounts from the User Management card.
- Admin Settings card ordering was updated and made role-aware so visible cards stay in a contiguous sequence with no layout gaps.
- Active Guests and Rental History tables now use scroll containers with sticky headers so column headings remain visible during list scrolling.

### Maintenance update (2026-05-16)

- Captive portal support now includes the RFC 8910 API endpoint at `/api/captive-portal` plus legacy browser/OS probe redirects for lab captive-portal testing.
- Admin Settings was reorganized into top-level cards for Profiles, Diagnostics and Logs, and Users, with nested sub-cards collapsed by default.
- Individual operational log cards include per-log clear buttons, and Full Factory Reset clears DB-backed logs plus file logs under `app/storage/logs`.
- Checkout, early-checkout, void, and auto-checkout paths reset modems toward the vacant profile and remove DDNet/modem-scoped reservations before reboot.
- Production deployment support now includes `docker-compose.prod.yml`, persistent DB/storage volumes, image-based app deployment, and per-instance container/volume environment variables.
- The production image now copies the application code into the image and keeps `/var/www/html/storage` as persistent writable storage.
- Staff/Admin users now see a quiet version footer driven by `APP_VERSION`, `APP_BUILD_DATE`, and `APP_IMAGE_TAG`; guest-facing pages do not show version text.
- Deployment operations are documented in `docs/Deployment_Handbook.md` and `docs/App_Update_And_Container_Maintenance_Cheat_Sheet.md`.
- Restore point created after this update: `restorepoints/SimpleGuestService_2026-05-16_190623.zip`.

### Maintenance update (2026-05-22)

- QA found that after a guest requested a departure extension and staff approved it, Active Guests still showed the original provisioning status as `Submitted`.
- Active Guests now combines the reservation provisioning status with the latest approved or auto-approved guest self-service request. Normal active rows show `Active`; approved extension rows show `Extension Approved`; auto-approved extension rows show `Extension Auto-Approved`.
- Added a success status-chip style for approved guest-change states.
- Verified against the lab row for `Jim Clark / Lot B2 / SG 11`, where the reservation departure date is `2026-05-24` and the related request is `extend_departure / approved`.
- Validation passed with PHP syntax checks for `app/public/index.php` and `app/src/GuestRepository.php`, plus the container health endpoint.
- Restore point created after this update: `restorepoints/SimpleGuestService_2026-05-22_100101.zip`.
- Parked follow-up: commit and push this update to the repository, then rebuild and publish/deploy the SGR container image.

Implementation details are tracked in `GUEST_SELF_SERVICE_PHASE1.md` under the Phase 2 plan section.
