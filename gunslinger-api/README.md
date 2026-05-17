# Gunslinger API Deploy Files

These are the PHP files to place on the Gunslinger server to support the app:

- `getfreshcustlist.php`: returns `userAccounts.customers` rows for allowed SG values (currently SG 10)
- `guestprofileupdate.php`: updates one `userAccounts.customers.profile` row by `sg + unit + mac`
- `getprofilebootfiles.php`: returns `cqm.profiles` profile-name to bootfile mappings for requested/allowed SG values

## Files

- `deploy/getfreshcustlist.php`
- `deploy/guestprofileupdate.php`
- `deploy/getprofilebootfiles.php`
- `deploy/common.php`
- `deploy/config.sample.php`

## Install on Gunslinger

1. Copy all files from `deploy/` to your web directory on Gunslinger (same folder).
2. Create `config.php` from `config.sample.php`.
3. Set DB credentials for `userAccounts` in `config.php`.
4. Keep `allowed_refresh_sgs` and `allowed_update_sgs` set to `[10]`.
5. Protect endpoint access using the configured basic auth credentials.

## Endpoint contracts

### Refresh modem list

- URL: `/getfreshcustlist.php`
- Method: `POST`
- Auth: Basic Auth
- Request body (JSON or form):
  - `service_groups` (comma-separated, optional)
  - `limit` (optional)

### Update profile

- URL: `/guestprofileupdate.php`
- Method: `POST`
- Auth: Basic Auth
- Request body (JSON or form):
  - `sg` (required)
  - `unit` (required)
  - `mac` (required)
  - `profile` (required)

### Fetch profile bootfiles

- URL: `/getprofilebootfiles.php`
- Method: `POST`
- Auth: Basic Auth
- Request body (JSON or form):
  - `service_groups` (comma-separated, optional)
  - `profile_names` (comma-separated, optional)

## Notes

- Updates are blocked for SG values not listed in `allowed_update_sgs`.
- This package is intentionally scoped to SG10 safety by default.
