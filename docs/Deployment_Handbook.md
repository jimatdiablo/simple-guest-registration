# Simple Guest Registration Deployment Handbook

This handbook describes the intended production deployment workflow for Simple Guest Registration (SGR) when it is deployed as a reusable container image managed by a Pod Manager.

The goal is simple: each customer deployment gets its own network identity, environment, database volume, and storage volume. App updates replace only the application image, while customer data, logs, and deployment settings are preserved.

## Deployment Model

SGR is packaged as a reusable container image. The Pod Manager owns the per-customer deployment details.

Each SGR instance must have:

- A unique customer/site name.
- A unique IP address from that customer's WireGuard/VPN subnet.
- A site-specific `.env`.
- A unique MySQL data volume.
- A unique app storage volume.
- Unique container names, or Pod Manager-generated container identities.
- The same SGR app image unless a site is pinned to a specific version.

Example customer VPN subnet:

```text
192.168.10.0/24
```

Example SGR app IP assigned by the Pod Manager:

```text
192.168.10.50
```

## Required Per-Instance Values

For each deployment, the Pod Manager should render these values into that instance's `.env` before the containers are started:

```env
APP_URL=http://192.168.10.50
POISON_DNS_BIND_IP=192.168.10.50
POISON_DNS_TARGET_IP=192.168.10.50
SGR_HTTP_BIND=192.168.10.50:80
```

These four values should normally use the same assigned customer VPN IP.

- `APP_URL` is the URL returned by captive portal APIs and used by the app.
- `POISON_DNS_BIND_IP` is the host/VPN IP where the poison DNS listener binds on port 53.
- `POISON_DNS_TARGET_IP` is the IP returned by poison DNS answers.
- `SGR_HTTP_BIND` is the host/VPN IP and port where the web app binds.

The Docker host or Pod Manager must actually own or route this IP. Docker cannot bind to `192.168.10.50` unless that address exists on the host, on a host interface, or is otherwise provided by the Pod Manager networking layer.

## Per-Instance Container and Volume Names

When multiple SGR deployments run on one host or Pod Manager, each instance must use unique names.

Example for customer/site `sg11`:

```env
SGR_APP_CONTAINER_NAME=sgr_sg11_app
SGR_SCHEDULER_CONTAINER_NAME=sgr_sg11_scheduler
SGR_DNS_CONTAINER_NAME=sgr_sg11_dns
SGR_DB_CONTAINER_NAME=sgr_sg11_db
SGR_DB_VOLUME=sgr_sg11_db_data
SGR_APP_STORAGE_VOLUME=sgr_sg11_app_storage
```

The database volume stores all reservation, user, modem cache, settings, and DB-backed log data.

The app storage volume stores file-based app data such as `storage/logs/snmp_audit.log`.

## Production Compose File

Use `docker-compose.prod.yml` for production-style deployments. It uses published images instead of local source bind mounts.

The current image variables are:

```env
SGR_APP_IMAGE=ghcr.io/jimatdiablo/simple-guest-registration:latest
SGR_DNS_IMAGE=ghcr.io/jimatdiablo/simple-guest-registration-dns:latest
```

For stricter production change control, prefer a versioned tag instead of `latest`, for example:

```env
SGR_APP_IMAGE=ghcr.io/jimatdiablo/simple-guest-registration:v1.0.3
SGR_DNS_IMAGE=ghcr.io/jimatdiablo/simple-guest-registration-dns:v1.0.3
```

## Initial Deployment Workflow

1. Assign an unused IP from the customer's WireGuard/VPN subnet.
2. Confirm the Pod Manager host can bind or route that IP.
3. Generate a site-specific `.env`.
4. Set customer-specific app identity values:
   - `APP_TITLE`
   - `APP_URL`
   - `APP_TIMEZONE`
5. Set the four network identity values:
   - `APP_URL`
   - `POISON_DNS_BIND_IP`
   - `POISON_DNS_TARGET_IP`
   - `SGR_HTTP_BIND`
6. Set unique DB credentials:
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASSWORD`
   - `MYSQL_ROOT_PASSWORD`
7. Set unique container and volume names.
8. Set customer service groups and integrations:
   - `SERVICE_GROUPS`
   - `GUNSLINGER_*`
   - `DDNET_BASE_URL`
   - `SNMP_*`
9. Start the deployment:

```powershell
docker compose -f docker-compose.prod.yml up -d
```

10. Verify:
    - Web app responds on `http://assigned-ip/`.
    - DNS listens on `assigned-ip:53` UDP and TCP.
    - Captive portal endpoint responds at `http://assigned-ip/api/captive-portal`.
    - The scheduler container is running.
    - The database volume and app storage volume were created with the expected unique names.

## DHCP and Captive Portal Configuration

For bridge-style CPE deployments, the DHCP server can provide Option 114 directly to the CPE:

```text
http://assigned-ip/api/captive-portal
```

For eRouter/NAT-style modem deployments, the goal is still for LAN-side CPE devices to learn the captive portal URL. If the modem/eRouter passes or supplies Option 114 correctly, the app model remains the same.

Poison DNS should point unregistered or captive clients toward the assigned SGR IP:

```text
DNS server: assigned-ip
Poison DNS answers: assigned-ip
```

The application also supports legacy captive portal probe redirects for browsers and operating systems that do not rely only on RFC 8910 Option 114.

## Application Update Workflow

A normal application update should preserve customer data and logs.

The source-of-truth flow is:

1. Code change is made in the repo.
2. Change is tested in the lab.
3. Change is committed and pushed to GitHub.
4. GitHub Actions builds and publishes a new GHCR image.
5. The Pod Manager updates the selected deployment to the new image tag.
6. The Pod Manager recreates the app and scheduler containers while preserving volumes and `.env`.
7. Staff verifies the app after restart.

Manual production update command pattern:

```powershell
docker compose -f docker-compose.prod.yml pull
docker compose -f docker-compose.prod.yml up -d
```

This preserves:

- MySQL data in `SGR_DB_VOLUME`.
- App storage and file logs in `SGR_APP_STORAGE_VOLUME`.
- Customer configuration in `.env`.

## What Not To Do

Do not run this during routine updates:

```powershell
docker compose -f docker-compose.prod.yml down -v
```

The `-v` flag deletes named volumes. That can destroy the customer database and app storage logs.

Do not reuse the same volume names for two customer deployments on the same Docker host.

Do not reuse the same IP address for two deployments.

Do not keep site-specific secrets in the GitHub repository.

## Backup Expectations

Before major upgrades, export or snapshot:

- The MySQL data volume or database dump.
- The app storage volume.
- The site-specific `.env`.

The app's Full Factory Reset creates database backup tables before clearing data, but it is not a replacement for host-level backup of persistent volumes.

## Full Factory Reset Behavior

Full Factory Reset is only available to `master_admin` users.

It clears:

- Reservations.
- Modem cache.
- Guest self-service queues.
- DB-backed operational logs.
- Captive portal API logs.
- Profile bootfile cache.
- App settings.
- Non-master-admin users.
- File logs under app storage, including the SNMP audit log.

It preserves:

- Users with role `master_admin`.
- The database volume itself.
- The app storage volume itself.

After a Full Factory Reset, the app prompts the user to resync modems and lots.

## Production Readiness Checklist

Before marking a deployment live:

- The assigned IP is unique and reachable over the customer VPN path.
- `APP_URL`, `POISON_DNS_BIND_IP`, `POISON_DNS_TARGET_IP`, and `SGR_HTTP_BIND` all match the assigned IP.
- DNS UDP/TCP port 53 is reachable by intended CPE/guest networks.
- HTTP port 80 is reachable by intended CPE/guest networks.
- DHCP Option 114 is set to `http://assigned-ip/api/captive-portal` where applicable.
- DB credentials are customer-unique.
- `SGR_DB_VOLUME` and `SGR_APP_STORAGE_VOLUME` are customer-unique.
- `master_admin` access has been verified.
- A normal app restart does not lose data.
- A test image update preserves reservations, settings, and logs.
