# Egglogan functionality audit

Date: October 20, 2026

## Result

86 automated checks passed against an isolated database. The runner removes its databases, accounts, stock, receipts, sessions, and backup fixtures afterward. Existing business records were not used for write tests.

Run from the project directory:

```powershell
C:\xampp\php\php.exe tests\run_backend_audit.php
```

## Issues corrected

- Added CSRF protection and POST-only requirements for modifying endpoints, including logout and receipt print tracking.
- Protected live alerts from unauthenticated access and restricted administrator exports and batch deletion.
- Enforced pending-account approval, active-account checks, current roles, and session revocation after password resets. Prevented self-deactivation and removal of the last active administrator.
- Validated account fields, quantities, prices, dates, settings, and forecast overrides on the server.
- Excluded expired stock from automatic sales allocation, enforced server prices and cash change, and rejected insufficient stock or tendered cash.
- Locked stock updates and receipt voids to prevent duplicate sales, double restoration, and conflicting damage updates. Serialized batch number allocation.
- Preserved receipt and damage history by blocking deletion of referenced batches.
- Aligned PHP and database time zones with the configured application time zone.
- Replaced shell-based backups with private, transactional SQL exports, including restorable view definitions. Verified restoration to a separate database.
- Protected CSV exports from spreadsheet formula injection and validated export types and date ranges.

## Verification coverage

The suite covers valid and invalid sales, price tampering, FIFO expiry rules, damage bounds, receipt printing and voiding, two simultaneous cashiers, simultaneous voids, permanent category QR lookup after new stock, account approval, permission checks, password-reset session invalidation, CSRF rejection, settings validation, backup restoration, exports, and rendering ten authenticated pages without PHP warnings.

The map uses `14.325991, 120.775719`. The cookie notice is attached to the bottom screen edge, remains responsive, can be dismissed or reopened from privacy controls, and is hidden while login is open.

## Limits

This is a functional regression and targeted security audit, not an exhaustive penetration or load test. Physical camera scanning and printer output still require checks on the client's devices. External map availability depends on Google Maps and the network.
