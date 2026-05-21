# Simple Guest Registration Deployment Intake

This file captures lab and deployment planning values. It may contain private network addresses, placeholder credentials, and environment-specific endpoint examples. Do not treat completed copies as public documentation.

Fill this file and send it back when ready. Leave unknown fields as `TBD`.

## 1. Environment

1. Deployment name: Paradise Oaks
2. App base URL: http://192.168.160.199 (temporary until internal DNS is ready)
3. Timezone (example: America/New_York): America/New_York
4. Service groups for this deployment (comma-separated, example: 10,11): 10 (testing and production testing)

## 2. Gunslinger Modem Refresh API

1. Endpoint URL: http://192.168.160.3/getfreshcustlist.php
2. Method (GET/POST): post
3. Auth type (none/basic/bearer/custom): basic auth, user: api_user, password replace-with-api-password
4. Auth value/header format: Authorization: Basic base64(api_user:replace-with-api-password)
5. Request fields: service_groups (comma-separated), limit (optional)
6. Example request: POST /<path> {"service_groups":"10","limit":5000}
7. Example success response JSON: {"ok":true,"service_groups":[10],"count":123,"data":[{"id":13409,"sg":10,"unit":"D4E9","mac":"bc:4d:fb:ab:d4:e9"}]}
8. Example error response JSON: {"ok":false,"error":"Unauthorized"}
9. Timeout seconds: 10 (proposed)
10. Retry policy (count + delay): 2 retries, 1s delay (proposed)

## 3. Gunslinger Profile Update API

1. Endpoint URL:http://192.168.160.3/guestprofileupdate.php
2. Method (GET/POST/PUT): post
3. Auth type (none/basic/bearer/custom): basic auth, user: api_user, password replace-with-api-password
4. Auth value/header format: Authorization: Basic base64(api_user:replace-with-api-password)
5. Required request fields (must include sg/unit/profile): sg, unit, profile, mac
6. Rule confirmation:
   - Update only one record (yes/no):yes
   - Match conditions are sg + unit : sg + unit + mac
7. Example request: POST /<path> {"sg":10,"unit":"D4E9","mac":"bc:4d:fb:ab:d4:e9","profile":"10baseservice","limit":1}
8. Example success response JSON: {"ok":true,"matched":1,"updated":1,"sg":10,"unit":"D4E9","mac":"bc:4d:fb:ab:d4:e9","profile":"10baseservice"}
9. Example error response JSON: {"ok":false,"error":"No matching record for sg+unit+mac"}
10. Timeout seconds: 10 (proposed)
11. Retry policy (count + delay): 2 retries, 1s delay (proposed)

## 4. DDNet Lease Lookup

1. Base URL (confirm): http://192.168.160.220:4000/api/dhcp
2. Use leases endpoint with mac filter (yes/no): yes
3. MAC format required (lowercase colon, uppercase colon, etc.): non case sensitive with colon
4. Confirm IP field path (example: [0].ip_address): [0].ip_address
5. Example not found response + status: status 404, body {"error":"Modem for this location is offline."}
6. Timeout seconds: 10
7. Retry policy: 2 retries, 1s delay (proposed)

## 5. SNMP Reboot Command

1. Enable SNMP in this environment (true/false): true
2. SNMP version (2c/3): 2c
3. If v2c, community: private
4. If v3, user/auth proto/auth pass/privacy proto/privacy pass:
5. Reboot OID:  1.3.6.1.2.1.69.1.1.3.0 
6. Reboot value type/value (example: i 1): i 1
7. SNMP timeout seconds: 10
8. Retries: 1
9. Any command wrapper required (direct snmpset or specific script):

## 6. Form and Validation Rules

1. Phone format requirement: no user format required
2. Arrival/departure rule (confirm departure >= arrival): confirm departure >= arrival
3. Duplicate policy (allow/block/warn): warn
4. Required fields beyond current form:

## 7. Reports (v1 required)

1. Must-have report pages: Active registrations, guest list by name or lot number
2. Filters needed (date range, unit, sg, status): date range, unit, sg, status, name search
3. Export required (CSV yes/no): yes
4. Extra metrics required: length of stay (days), active count, average stay

## 8. Data and Audit

1. Keep full API request/response logs (yes/no): yes
2. Keep SNMP attempt logs (yes/no): yes
3. Guest record retention policy: 180 days
4. Masking requirements for phone/IP in UI/logs: phone (xxx) yyy-zzzz IP 1.2.3.4

## 9. Seed and Refresh Source

1. Initial seed source file path: customersgp1.sql (current lab seed)
2. Will modem refresh always come from API after go-live (yes/no): yes, modem table refresh always comes from Gunslinger API after go-live
3. If no, alternate refresh process:

## Open Items Still Needed

1. Final DNS hostname once internal DNS entry is created.
2. Final production service group list if/when expanded beyond SG10.
