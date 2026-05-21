# Security Policy

This project is source-available for authorized collaboration and deployment review. Please do not file public issues for suspected vulnerabilities, secrets, credentials, deployment hostnames, or customer data.

Report security concerns directly to the project maintainer through the established Diablo Data support channel.

## Sensitive Local Files

Do not commit these files or folders:

- `.env`
- `data/`
- `restorepoints/`
- `db/init/015_customers_seed.sql`
- `app/storage/logs/`

The repository intentionally tracks `.env.example` with placeholder values only.
