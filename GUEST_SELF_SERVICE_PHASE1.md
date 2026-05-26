# Guest Self-Service: Phase 1 (MVP)

## Purpose
This document defines and records the Phase 1 implementation for guest self-service reservation changes in Simple Guest Registration.

The design intentionally avoids SMS and email dependencies. Guests use a manually saved access credential pair shown at registration time:

- Guest Access ID (example: `PO-AB12CD34`)
- Guest Access Code (example: `739214`)

## Business Goals
Phase 1 delivers these outcomes:

1. Guests can access only their own reservation using Access ID + Code.
2. Guests can request:
   - Early checkout
   - Departure extension
3. Staff/Admin can review and decide requests in a queue.
4. Property Admin can enable/disable and control policy through Admin Settings.
5. All changes are auditable via request history and reservation notes.

## Scope (Phase 1)

### In Scope
- Access credentials generated at registration confirmation.
- Manual guest lookup and access flow (`Manage My Reservation`).
- Request submission by guests.
- Staff/Admin request queue for approve/deny actions.
- Policy toggles in Admin Settings.
- Help popup content for all new pages/cards.

### Out of Scope (Phase 2+)
- SMS/email verification.
- Advanced lockout/rate-limit telemetry dashboards.
- Printable branded receipt templates.
- Batch decision workflows.

## Data Model Additions

### Guests Table (new columns)
- `guest_access_id` (nullable, unique)
- `guest_access_code_hash` (nullable)
- `guest_access_code_expires_at` (nullable datetime)

### New Table: `guest_self_service_requests`
Fields:
- `id`
- `guest_id`
- `guest_name`
- `unit`
- `sg`
- `request_type` (`early_checkout` | `extend_departure`)
- `current_departure_date`
- `requested_departure_date`
- `reason`
- `status` (`pending` | `approved` | `denied` | `auto_approved`)
- `decision_note`
- `requested_by_access_id`
- `decided_by`
- `created_at`
- `decided_at`

## Admin Settings (Exact Phase 1 Fields)
Card: **Guest Self-Service Access**

- Enable Guest Self-Service (checkbox)
- Allow Early Checkout Requests (checkbox)
- Allow Departure Extension Requests (checkbox)
- Require Guest Reason (checkbox)
- Approval Mode (select)
  - Manual Approval
  - Auto-Approve If Policy Passes
- Max Extension Days (number, 1-30)
- Save Guest Self-Service Settings (button)

## Guest UX

### 1) Registration Confirmation
After successful registration, confirmation includes:

- Guest Access ID
- Guest Access Code
- Code Expiration Date/Time

Guest receives instruction to save these values for later reservation management.

### 2) Manage My Reservation
Public entry point for guests to access and request changes.

#### Access step
Inputs:
- Guest Access ID
- Guest Access Code

#### Request step
After successful access, guest sees reservation details and request cards:

- Request Early Checkout
  - New Departure Date
  - Reason (required/optional per settings)
- Request Departure Extension
  - New Departure Date
  - Reason (required/optional per settings)

#### Request history
Guest sees previously submitted requests and statuses.

## Staff/Admin UX

### Guest Self-Service Requests Queue
Page shows all requests for review.

Columns include:
- Time
- Guest
- Lot
- Request Type
- Current Departure
- Requested Departure
- Status
- Reason
- Decision action

Decision action:
- Approve
- Deny
- Optional decision note

## Validation Rules (Phase 1)

### Guest access
- Access denied when Access ID or code is invalid.
- Access denied when code is expired.

### Early checkout request
- Requested date must be valid.
- Requested date must be between arrival and current departure.
- Requested date cannot be in the past.

### Extension request
- Requested date must be after current departure.
- Requested date cannot exceed max extension days.
- Requested date cannot create lot overlap conflict.

### Duplicate pending request guard
- Same guest cannot have multiple pending requests of the same type.

## Decision Behavior

### Approve
- Reservation departure date is updated to requested date.
- If early checkout approved for today or earlier, status transitions to `checked_out`.
- Decision recorded with actor and optional note.

### Deny
- Reservation unchanged.
- Request status set to `denied`.
- Decision note optional.

### Auto-approve mode
If enabled and validations pass, request is saved as `auto_approved` and applied immediately.

## Security and Operational Notes
- Access code is stored hashed, never in plaintext DB fields.
- Access code is shown once at confirmation for manual save.
- Guest access is session-scoped in app session (`guest_access_guest_id`).
- Staff/Admin review is required by default (`Manual Approval`).

## Help Coverage (New)
Help popup entries are included for:
- Guest Self-Service Access settings card
- Manage My Reservation page
- Request Early Checkout card
- Request Departure Extension card
- Guest Self-Service Requests queue page

## Restore Point
A restore point was created before Phase 1 implementation.

## QA Validation (2026-05-06)
Phase 1 guest self-service was manually validated in-app on 2026-05-06.

### Passed scenarios
1. Guest reached Manage My Reservation from registration confirmation and public landing flow.
2. Guest access login succeeded with issued Access ID + Access Code.
3. Guest request submission succeeded and created `pending` queue items.
4. Staff/Admin approval succeeded and updated reservation departure date.
5. Extension conflict guard blocked invalid extension approval with message:
   - `Requested extension conflicts with another reservation for this lot.`

### Regression checklist (keep for future releases)
- Guest self-service feature toggle OFF blocks access with clear message.
- Guest self-service feature toggle ON allows Access ID + code login.
- Early checkout request validates date range and rejects past dates.
- Extension request validates max extension days and overlap conflicts.
- Staff approve updates guest reservation and records decision metadata.
- Staff deny keeps reservation unchanged and marks request denied.
- Manage My Reservation button from landing page does not submit registration form.
- Generated Guest Access IDs remain unique across rapid registrations.

## Phase 2 Implementation Plan

### Objective
Implement operational hardening and usability upgrades while preserving Phase 1 behavior:
1. Brute-force protection and lockouts for guest access credentials.
2. Staff/Admin guest access code regeneration workflow.
3. Queue filters, sorting, and SLA visibility for Guest Requests.

### Phase 2.1: Brute-force protection and lockouts

#### Data model additions
Add table `guest_self_service_access_events`:
- `id` (PK)
- `guest_access_id` (varchar 32, indexed)
- `ip_address` (varchar 64, indexed)
- `event_type` (`login_success` | `login_failure` | `lockout` | `unlock`)
- `created_at` (timestamp)

Add columns on `guests`:
- `guest_access_failed_attempts` (int, default 0)
- `guest_access_locked_until` (datetime, nullable)
- `guest_access_last_attempt_at` (datetime, nullable)

#### Settings additions (Admin card)
- Max failed attempts before lockout (number, default 5)
- Lockout duration minutes (number, default 15)
- Optional IP throttle window minutes (number, default 10)
- Optional IP failure threshold in window (number, default 20)

#### Runtime behavior
- On failed login:
  - Increment guest attempt counter.
  - Record failure event with access ID + IP.
  - If threshold reached, set `guest_access_locked_until` and record lockout event.
- On successful login:
  - Reset attempt counter and lockout timestamp.
  - Record success event.
- On login while locked:
  - Deny with clear lockout message including unlock time.

#### Acceptance criteria
- Repeated bad code attempts trigger lockout exactly at configured threshold.
- Lockout automatically clears after configured duration.
- Successful login resets counters.
- Access events are queryable for troubleshooting.

### Phase 2.2: Guest access code regeneration workflow

#### UI additions
- Staff/Admin action in Active Guests and/or Guest Requests:
  - `Regenerate Guest Access Code`
- Optional action:
  - `Regenerate Access ID + Code`

#### Behavior
- Generate a new random code (and optionally new ID).
- Hash and store new code; invalidate prior code immediately.
- Set new code expiration based on reservation departure policy.
- Append reservation note with actor + timestamp.

#### Confirmation UX
- Display one-time panel with:
  - New Guest Access ID
  - New Guest Access Code
  - Expiration
  - Copy instruction text for staff to share with guest

#### Acceptance criteria
- Old code no longer authenticates after regeneration.
- New code authenticates immediately.
- Audit note/log captures who regenerated credentials and when.

### Phase 2.3: Guest Change Requests queue filters and SLA

#### UI additions (Guest Change Requests page)
- Filters:
  - Status (`pending`, `approved`, `denied`, `auto_approved`)
  - Request type (`early_checkout`, `extend_departure`)
  - Service group
  - Date range (created_at)
  - Search text (guest name, unit, access ID)
- Sorting:
  - Created time newest/oldest
  - Requested departure date
- SLA indicators:
  - `Age` column (minutes/hours since submitted)
  - Visual states (for example: > 4h warning, > 12h critical)

#### Backend additions
- Repository method with filterable query parameters and indexed conditions.
- Optional pagination (`limit`, `offset`) for large queues.

#### Acceptance criteria
- Queue can be narrowed to pending extension requests for a specific SG.
- SLA age and highlight update correctly over time.
- Sorting and filters remain stable across page refresh.

#### Completion status (2026-05-06)
Phase 2.3 has been implemented and smoke-tested.

Implemented:
- Filters by status, request type, SG, date range, and search text.
- Sort options for created/requested date and age order.
- SLA age chip rendering for pending requests.
- CSV export for the active filtered result set.
- Server-side pagination with per-page controls (25/50/100/200).
- First/Previous/Next/Last plus numeric page links.
- Queue summary metrics on Guest Requests page:
  - Open pending request count
  - Median pending age
  - Oldest pending age
- Queue panel default-collapsed on entry and auto-opened when request activity/confirmations are present.
- Decision breadcrumb detail for closed requests (actor, timestamp, and note when available).
- Navigation grouped into Reservations/Operations/Account for faster scanning.
- Nav button hover tooltips and Nav Help modal entry with action explanations.
- Added Upcoming Registrations view for future bookings with arrival window filter options.
- Enabled stylesheet cache-busting via file modified time version query to reduce stale-browser-cache incidents.
- Updated restore workflow to produce `SimpleGuestService.zip` containing only `projects/Simple-Guest-Registration`.

### Sequence of implementation
1. Add schema and settings for lockouts.
2. Implement and test guest login lockout logic.
3. Add staff/admin code regeneration action and one-time reveal UX.
4. Add queue filter/sort/SLA query + UI.
5. Add docs and regression tests for all new behaviors.

### Recommended regression tests for Phase 2
1. Lockout threshold and unlock timer.
2. Correct reset of failed-attempt counters on success.
3. Regenerated credentials invalidating prior credentials.
4. Queue filtering by status/type/SG/date/search.
5. SLA age and highlight thresholds.

## Phase 2 Recommendations
1. Add brute-force protection and lockout counters by Access ID/IP.
2. Add code regeneration workflow for staff/admin.
3. Add improved queue filters and SLA highlighting.
4. Add downloadable/printable guest access receipt.

## QA Maintenance Note - 2026-05-22

Staff approval of a guest departure extension correctly updated the reservation date and marked the self-service request approved, but Active Guests still displayed the original provisioning status as `Submitted`.

Resolution:
- Active Guests now displays reservation-facing state from both `guests.submission_status` and the latest approved or auto-approved `guest_self_service_requests` row.
- Normal active reservations display `Active`.
- Approved extension requests display `Extension Approved`.
- Auto-approved extension requests display `Extension Auto-Approved`.
- Approved checkout requests display `Checkout Approved`.

Validation:
- Confirmed the lab row `Jim Clark / Lot B2 / SG 11` has departure date `2026-05-24` and request state `extend_departure / approved`.
- Ran PHP syntax checks for `app/public/index.php` and `app/src/GuestRepository.php`.
- Confirmed the SGR container health endpoint returns OK.

Restore point:
- `restorepoints/SimpleGuestService_2026-05-22_100101.zip`

Parked follow-up:
- Commit and push this update to the repository.
- Rebuild and publish/deploy the SGR container image so deployed environments receive the Active Guests status fix.
