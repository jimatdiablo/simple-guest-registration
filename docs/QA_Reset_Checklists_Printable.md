# QA Reset Test Checklists

Date: ____________________
Tester: ____________________
Environment: ____________________
Build/Commit: ____________________

---

## Pre-Checks

- [ ] I can log in with at least one master admin account.
- [ ] I have at least one non-master-admin account available for validation.
- [ ] There is sample guest data present (or I created a few records).
- [ ] There is modem/lot cache data present before testing.
- [ ] Service group settings are known and documented for this environment.

Notes:

____________________________________________________________

____________________________________________________________

---

## Test A: Clear All Registrations

1. Open Admin > Deployment Tools.
- [ ] Pass
- Notes: ________________________________________________

2. Click Clear All Registrations and verify confirmation appears.
- [ ] Pass
- Notes: ________________________________________________

3. Click Cancel on confirmation once.
Expected: No data is cleared; cancel message appears.
- [ ] Pass
- Notes: ________________________________________________

4. Run Clear All Registrations again and click Proceed.
Expected: Success message indicates registrations, modem cache, and related logs/queues were cleared; backup summary appears.
- [ ] Pass
- Notes: ________________________________________________

5. Verify post-reset prompt appears asking whether service group settings are configured and offering resync.
- [ ] Pass
- Notes: ________________________________________________

6. Validate immediate app state after Clear All.
Expected:
- Admin login still works.
- Guest list is empty (or reset as expected).
- Related operational logs/queues are cleared.
- [ ] Pass
- Notes: ________________________________________________

7. Click Yes, Resync Modems and Lots Now.
Expected: Modem/lot cache repopulates successfully for configured service groups.
- [ ] Pass
- Notes: ________________________________________________

8. Try a new guest registration flow after resync.
Expected: Lot/modem lookup works; registration can proceed normally.
- [ ] Pass
- Notes: ________________________________________________

---

## Test B: Full Factory Reset

1. Open Admin > Deployment Tools and click Full Factory Reset.
Expected: Separate confirmation appears for factory reset.
- [ ] Pass
- Notes: ________________________________________________

2. Click Cancel once first.
Expected: No reset performed; cancel message appears.
- [ ] Pass
- Notes: ________________________________________________

3. Run Full Factory Reset and click Proceed Full Factory Reset.
Expected: Success message states data/settings/non-master-admin users were reset and master admin users were preserved.
- [ ] Pass
- Notes: ________________________________________________

4. Verify master admin preservation.
Expected: Master admin can still log in.
- [ ] Pass
- Notes: ________________________________________________

5. Verify non-master-admin user reset behavior.
Expected: Non-master-admin users are removed/reset per design.
- [ ] Pass
- Notes: ________________________________________________

6. Verify factory reset data scope.
Expected: Registrations, modem cache, related logs/queues, and app settings are reset.
- [ ] Pass
- Notes: ________________________________________________

7. Verify post-factory-reset service group prompt and run resync.
Expected: Prompt appears; resync repopulates modem/lot cache.
- [ ] Pass
- Notes: ________________________________________________

8. Validate core smoke flow after factory reset plus resync.
Expected:
- Master admin login works.
- Admin pages load without errors.
- Guest registration works with lot/modem lookup.
- [ ] Pass
- Notes: ________________________________________________

---

## Backup and Recovery Spot Check

- [ ] Backup table names were shown in success messaging when source tables had rows.
- [ ] At least one backup table was spot-verified in DB (optional but recommended).

Notes:

____________________________________________________________

____________________________________________________________

---

## Defect Log

Defect ID: ____________________
Step: ____________________
Expected: ________________________________________________
Actual: ________________________________________________
Severity: ____________________
Screenshots/Links: ________________________________________

---

## Final Sign-Off

Overall Result: [ ] Pass   [ ] Pass with minor issues   [ ] Fail

QA Summary:

____________________________________________________________

____________________________________________________________

Approved By: ____________________
Date: ____________________
