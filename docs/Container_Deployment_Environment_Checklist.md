# Container Deployment Environment Checklist

Use this checklist when preparing a Simple Guest Registration container deployment for a new customer or site.

Examples that use `192.168.160.0/24` are lab placeholders from the SGR test environment. Replace them with the target site's addressing before deployment.

This is a planning checklist for customer-unique values. It is not a full installation guide.

## Application Identity

- `APP_NAME`
  - Internal/default application name.
  - Example: `Simple Guest Registration`

- `APP_TITLE`
  - Visible title shown in the browser tab and page header.
  - Customer-specific.
  - Example: `Paradise Oaks Guest Registration`

- `APP_URL`
  - Public or routed URL/IP staff and guests should use to reach the app.
  - This does not by itself assign the Docker host IP.
  - Example: `http://192.168.160.199`

- `APP_TIMEZONE`
  - Local timezone for timestamps, checkout timing, logs, and reports.
  - Example: `America/New_York`

## Docker / Host Exposure

These may live in `docker-compose.yml` or a customer-specific Compose override.

- Web app host port
  - Current local example: `8088:80`
  - Customer deployments may need `80:80` or a site-specific host IP binding.

- MySQL host port
  - Current local example: `33306:3306`
  - Avoid exposing publicly.

- phpMyAdmin host port
  - Current local example: `8089:80`
  - Consider disabling or restricting in production.

- Future bundled DNS exposure
  - DNS requires UDP/TCP port `53`.
  - Guest networks must be able to reach the DNS service IP.

## Database

- `DB_HOST`
  - Usually `db` when using the bundled Compose MySQL service.

- `DB_PORT`
  - Usually `3306` inside Docker.

- `DB_NAME`
  - Customer database name.

- `DB_USER`
  - App database user.

- `DB_PASSWORD`
  - App database password. Must be customer-unique.

- `MYSQL_ROOT_PASSWORD`
  - MySQL root password. Must be customer-unique.

## Service Groups and Profiles

- `SERVICE_GROUPS`
  - Comma-separated service groups this deployment manages.
  - Example: `10,11`

- `DEFAULT_SERVICE_PROFILE`
  - Fallback working profile used when a service-group-specific profile has not been configured in Admin Settings.
  - Example: `10baseservice`

- `MODEM_EXCLUDED_UNIT_VALUES`
  - Comma-separated unit values that represent spare/stock/undeployed modems.
  - These are excluded from lot lists and modem cache behavior.
  - Example: `stock`

- Admin Settings values
  - Service group display names
  - Service-group-specific working profiles
  - Service-group-specific vacant profiles
  - Global checkout time
  - Guest self-service behavior

## Gunslinger Integration

- `GUNSLINGER_REFRESH_URL`
  - Default endpoint used to refresh modem/customer data.

- `GUNSLINGER_REFRESH_URL_BY_SG`
  - Optional per-service-group refresh endpoint map.
  - Useful when service groups live on different Gunslinger systems.

- `GUNSLINGER_PROFILE_UPDATE_URL`
  - Default endpoint used to update a modem profile.

- `GUNSLINGER_PROFILE_UPDATE_URL_BY_SG`
  - Optional per-service-group profile update endpoint map.

- `GUNSLINGER_PROFILE_BOOTFILE_URL`
  - Default endpoint used to discover profile-to-bootfile mappings.

- `GUNSLINGER_PROFILE_BOOTFILE_URL_BY_SG`
  - Optional per-service-group bootfile endpoint map.

- `GUNSLINGER_API_TOKEN`
  - Customer/site API token if token auth is used.

- `GUNSLINGER_BASIC_USER`
  - Basic auth username, if used.

- `GUNSLINGER_BASIC_PASS`
  - Basic auth password, if used.

- `GUNSLINGER_REFRESH_API_KEY`
  - API key for inbound refresh calls to SGR, if enabled.

- `GUNSLINGER_UPDATE_ALLOWED_SGS`
  - Service groups where profile updates are allowed.
  - Should match the customer deployment scope.

- `GUNSLINGER_TIMEOUT`
  - API timeout in seconds.

## DDNet Integration

- `DDNET_BASE_URL`
  - DDNet DHCP API base URL for the customer/site.
  - Example: `http://192.168.160.220:4000/api/dhcp`

- `DDNET_TIMEOUT`
  - API timeout in seconds.

DDNet must support the required operations:

- lookup modem by CPE IP
- create/update normal reservations
- create modem-scoped reservations
- delete modem-scoped reservations
- lease lookup by MAC

## SNMP Reboot Integration

- `SNMP_ENABLED`
  - Enables or disables modem reboot attempts.

- `SNMP_COMMUNITY`
  - SNMP community string. Must be customer-unique.

- `SNMP_REBOOT_OID`
  - OID used for reboot action.

- `SNMP_REBOOT_VALUE`
  - Value sent to the reboot OID.

- `SNMP_TIMEOUT`
  - SNMP timeout.

- `SNMP_RETRIES`
  - Retry count.

- `SNMP_RETRY_DELAY_MS`
  - Delay between retry attempts.

- `SNMP_AUDIT_LOG_ENABLED`
  - Enables SNMP audit logging.

- `SNMP_AUDIT_LOG_PATH`
  - Log path inside the app container.

## Security and Access

- `ADMIN_ACCESS_KEY`
  - Currently also used as the guest access code lookup pepper fallback.
  - Should be customer-unique and treated as sensitive.

- Staff/admin users
  - Create customer-specific accounts.
  - Remove test users before production.

- Secrets
  - Rotate all default passwords.
  - Do not reuse passwords between customers.
  - Do not commit customer `.env` files to Git.

## Future Walled Garden DNS Values

If bundled Poison DNS is added later, expect customer-specific values such as:

- DNS service IP
- upstream DNS servers
- app redirect IP
- allowed domains
- captive portal handling domains
- normal DNS servers after registration
- walled garden lease duration

## Bring-Up Notes

- A temporary `APP_URL` is acceptable for first container bring-up.
- The actual reachable IP/URL must be changed before customer testing.
- Docker port bindings or host networking determine where the app listens.
- `APP_URL` should describe how users reach the app; it does not assign an IP to the container by itself.
