# Vacant Lot Profile Audit + Bulk Update Plan

## Objective
Create an admin tool that identifies lots with no active registration, shows each modem's current profile from Gunslinger, and allows safe bulk updates to a configured vacant profile.

## Scope Summary
- Add a new Admin workflow: preview vacant lots, select targets, confirm, then apply profile updates.
- Use current live Gunslinger data during preview and apply.
- Support multiple service groups with per-service-group vacant profile settings.
- Record detailed audit logs for each attempted profile change.

## Phase 1: Rules and Data Definitions
1. Active registration rule:
   - A lot is occupied only when submission_status is not checked_out and today's date is between arrival_date and departure_date.
2. Vacant lot rule:
   - A lot appears in modem inventory for configured service groups but has no active registration.
3. Profile source:
   - Use fresh Gunslinger refresh results as source of current profile values.
4. Target profile:
   - Define per-service-group vacant profile values (for example, SG10 and SG11 each have their own vacant profile).

## Phase 2: Admin Settings
1. Add per-service-group vacant profile settings in Admin.
2. Enforce profile prefix validation per service group.
3. Save metadata such as updated timestamp and actor.

## Phase 3: Preview/Audit Action
1. Add a controller action for preview that:
   - Refreshes modem/customer data from Gunslinger for configured service groups.
   - Builds occupied lot keys from active registrations.
   - Computes vacant lots as modem lots minus occupied lot keys.
2. Output grouped rows by service group with:
   - Lot
   - MAC
   - Current profile in Gunslinger
   - Target vacant profile
   - Needs change flag
3. Include summary counts:
   - Total vacant lots
   - Already on vacant profile
   - Needing update

## Phase 4: Admin UI Workflow
1. Add new Admin card: Vacant Lot Profile Management.
2. Step A: Preview button to refresh and calculate candidates.
3. Step B: Selection interface:
   - Row checkbox
   - Select all by service group
   - Filters for service group, lot text, and profile text
   - Default selection for rows where current profile differs from target
4. Step C: Apply button opens confirmation section with selected counts and service-group breakdown.
5. Step D: Proceed/Cancel confirmation before any updates are sent.

## Phase 5: Bulk Apply Execution
1. For each selected row, call profile update endpoint with service group, lot, MAC, and target profile.
2. Capture per-row outcome:
   - Success
   - Failure with reason
3. Return result table and quick retry option for failed rows.
4. Provide dry-run mode to preview intended changes without applying updates.

## Phase 6: Audit Logging
1. Add a dedicated audit table for vacant-profile updates.
2. Record:
   - Timestamp
   - Actor
   - Service group
   - Lot
   - MAC
   - Old profile
   - New profile
   - Result
   - Error details (if any)
3. Add admin log viewer with filters for service group, lot, actor, and result status.

## Phase 7: Safety Controls
1. Permission model:
   - Admin can preview.
   - Master admin required to apply updates.
2. Request integrity:
   - CSRF token and one-time anti-replay token for apply action.
3. Batch safety:
   - Default cap on rows per run (for example, 200) unless explicit override.
4. Freshness control:
   - Require preview data age under a short threshold (for example, 5 minutes) before apply.

## Phase 8: Testing and Acceptance
1. Unit tests:
   - Vacant lot computation by service group.
   - Prefix validation for vacant profiles.
2. Integration tests:
   - Preview path
   - Apply path
   - Partial failure handling
3. Manual test scenarios:
   - No active registrations
   - Mixed occupied and vacant lots
   - Already-vacant rows
   - Gunslinger partial/unavailable responses
4. Acceptance criteria:
   - Preview counts match expected vacant lots.
   - Apply updates only selected rows.
   - Audit log captures all attempts and outcomes.
   - No impact to occupied guest registrations.

## Expected Files to Change
- projects/Simple-Guest-Registration/app/public/index.php
- projects/Simple-Guest-Registration/app/src/GuestRepository.php
- projects/Simple-Guest-Registration/app/src/ModemRepository.php
- projects/Simple-Guest-Registration/app/src/Services/GunslingerClient.php
- projects/Simple-Guest-Registration/app/public/style.css
- projects/Simple-Guest-Registration/db/init/001_schema.sql

## Open Decisions for Review
1. Confirm apply permissions:
   - Confirmed: admin + master admin can apply updates.
2. Confirm vacant profile model:
   - Confirmed: global default with optional per-service-group overrides.
3. Confirm default selection behavior:
   - Confirmed: auto-select all rows needing change.
4. Confirm occupancy treatment:
   - Confirmed: checked-out rows count as vacant immediately.

## Decisions Confirmed on 2026-05-06
1. Apply permissions:
   - Both admin and master admin roles can apply vacant profile updates.
2. Vacant profile model:
   - Use one global vacant profile as default, with optional service-group-specific overrides.
3. Default selection behavior:
   - Auto-select all rows where current profile differs from target vacant profile.
4. Occupancy treatment:
   - Checked-out rows are treated as vacant immediately.

## Implementation Sequence Recommendation
1. Build settings and validation.
2. Build preview calculation and UI rendering.
3. Add selection and confirmation flow.
4. Enable apply action with audit logging.
5. Add tests and finalize documentation.
