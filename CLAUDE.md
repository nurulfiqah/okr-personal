# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

This git repository is the **`okr` module** — one subdirectory of a much larger legacy PHP application ("odb") that lives at `C:\laragon\www\odb`. The parent `odb` app (session/auth handling, DB connection bootstrap, the shared `staff`/`staff_department`/`staff_grade`/`staff_struct` tables, etc.) is **not** part of this repo and is only reachable via relative includes such as `../lock_adv.php` and `../common/index_adv.php`. Those files exist on disk one level up from this repo but are not tracked here — do not assume they can be edited as part of this repo's history, and do not try to "fix" a missing-file error by creating stubs for them; they belong to the outer application and are shared by many other modules (`atem`, `consultcall`, etc.).

There is no build step, package manager, or automated test suite in this repo — it is plain PHP + vanilla JS + Bootstrap 5, edited and deployed as-is. There is no `composer.json`/`package.json`; nothing to install.

**PHP version:** this copy of `odb` (under `C:\laragon\www\odb`) runs PHP 8.2. Modern syntax is fine: short array `[]`, `??`/`??=`, arrow functions, etc. (This is distinct from the legacy PHP 5.3 copy at `C:\xampp\htdocs\odb`, which is a different, older codebase — the PHP 5.3 constraints only apply there, not here.)

## Architecture

OKR is a **server-rendered PHP module with a direct-mysqli JSON backend** (no separate API service, unlike `atem`/`consultcall` which proxy to Laravel APIs). Every page and the backend re-query the outer app's `staff` table directly for identity/grade/department.

### Request flow

1. Every page-level PHP file (`index.php`, `list.php`, `view.php`, `create.php`, `edit.php`, `performance.php`, `admin/index.php`) includes `header.php`, which: buffers output (`ob_start()`, because `lock_adv.php` echoes HTML before the redirect below can fire), requires `../lock_adv.php` (outer-app session/auth gate — sets `$grade`, `$department`, `$id_user`, etc.), sets `$connect = 1` and includes `../common/index_adv.php` (sets up `$conn` mysqli connection), then derives `$okr_permission` (`(int)$grade`) and `$okr_is_admin` (union of `staff.okr` and `staff.atem` — see SuperAdmin flag section below).
2. `download.php` and `export_performance.php` do the same `lock_adv.php` + `common/index_adv.php` bootstrap independently (they don't include `header.php`, since they stream a file/CSV instead of HTML) — same `ob_start()`/`ob_end_clean()` trick to keep the auth redirect/`header()` calls working.
3. `admin/backend.php` and `admin/index.php` use `../../common/index_adv.php` (one extra `../` for the nesting). This page still exists and works, but `navbar.php` no longer links to it — see "Admin" below.
4. Frontend JS (`js/*.js`, one file per page, vanilla ES6, no bundler) posts `{ action, ...data }` to `backend.php` (or `admin/backend.php` for the admin-only backdate toggle), which dispatches on `$_POST['action']`/`$_GET['action']` via a flat `if` chain (see the action list in `backend.php`: `listCards`, `dashboardStats`, `staffPerformanceList`, `lockPayoutCards`, `unlockPayoutCards`, `staffOkrList`, `getCard`, `createCard`, `stageAttachment`/`addAttachment`/`deleteAttachment`, `stageReferenceLink`/`addReferenceLink`/`deleteReferenceLink`, `updateCard`, `deleteCard`, `permanentlyDeleteCard`, `suspendCard`, `unsuspendCard`).
5. Shared query builders, formatters, and business logic live in `lib.php` (required by `backend.php`, `header.php`'s callers, and `export_performance.php`) — see "Shared helpers" below. `lib.php` requires `nas_config.php` for NAS credentials/paths.

### Grade-based permission model (`staff.grade`, 0–5)

| Grade | Access |
|---|---|
| 0 | No OKR access — redirected out by `header.php` |
| 1–2 | See/own only their own cards (`okrScopeWhere`: `owner_staff_id` or `owner2_staff_id` match) |
| 3 | Senior management — sees own department's cards (`dept_scope` overlap) plus anything they personally issued; can create OKRs (`create.php` gate: `$okr_permission < 3`) |
| 4–5 | Company-wide visibility; Performance page access (`performance.php` gate: `$okr_permission < 4`) |
| 5 (CEO) only | Suspend/Unsuspend a card (`backend.php`: `$requester_grade !== 5 && !$requester_is_admin` blocks `suspendCard`/`unsuspendCard`) |

**SuperAdmin flag**: `okr_is_admin` (page-side) / `requester_is_admin` (backend-side) is `true` when **either** `staff.okr = 1` **or** `staff.atem = 1` (the union of both modules' flags — a SuperAdmin in either system gets full admin access in both), and bypasses every grade-based restriction in the module regardless of the user's actual `staff.grade`. Checked independently in every entry point (`header.php`, `backend.php`, `admin/backend.php`, `download.php`, `export_performance.php`) — there is no shared session cache of this flag, it's re-queried from `staff` each time. The ATEM side of this union lives in `atem/header.php`'s `$_is_superadmin` and the `atem/access_control/backend.php` / `atem/admin/backend.php` `$db_is_superadmin` checks — not part of this repo, but relevant when reasoning about who has admin access here.

Card-level edit gate (`edit.php`, `backend.php`'s `updateCard`): issuer or admin, **and** not `incentive_locked`. A locked-for-payout card can never be edited again except via unlock.

### Shared helpers (`lib.php`)

- `okrCardSelectSql($where, $include_deleted)` / `okrFormatCard($row)` — the canonical card SELECT (with all joins: owner/owner2/issuer names, level, status, incentive rule) and row-to-API-shape formatter. Used everywhere a card is read.
- `okrScopeWhere($requester_id, $requester_grade, $requester_dept_ids, $is_admin)` — the grade-based visibility WHERE fragment described above.
- `okrDeptIdsFromCsv($csv)` — `staff.department` is a comma-separated list of department IDs (not a single FK, same convention as `atem`/`consultcall`); this parses it to an int array.
- `okrPerformanceFilterSql($input)` — parses the Performance page's Year/Month/Quarter/Department/Closure-date filters into one `$filter_sql` fragment, shared by `okrStaffPerformanceRows`, `okrExportRows`, `okrLockPayoutCards`, `okrUnlockPayoutCards` (all four call sites stay in sync instead of re-parsing four times). Grade/struct filters are applied separately since they filter on the *owner*, not the card.
- `okrStaffPerformanceRows` / `okrExportRows` — per-owner performance aggregation and per-(staff,card) export rows respectively. Both split a two-owner card's RM by `incentive_rule`: rule id 1 (`RULE1`) pays the `incentivised_owner_staff_id` 100% and the other owner 0%; anything else (`RULE2`) splits 50/50.
- `okrLockPayoutCards` / `okrUnlockPayoutCards` — bulk lock/unlock of `incentive_locked` for every card where `okr_statuses.pays_incentive = 1` (joined in directly, not a hardcoded status name list), restricted to People Management (dept id 17) or admin (`$can_lock_payout` in `performance.php`). Locking stamps `locked_by`/`locked_at`/`payout_remark` and writes one audit-log row per card; unlocking reverses it. `export_performance.php` calls `okrLockPayoutCards` too — People Management's export auto-locks the same set it just downloaded.
- `okrFetchStatuses($conn, $include_recycled)` — the single DB-driven read of `okr_statuses` (id/value/description/pays_incentive/sort_order/recycle), mirroring `okrFetchTypes`/`okrTypeValues` for `okr_types`. Every status picker/filter (Timeline dropdown in `edit.php`, `list.php`'s filter checkboxes) reads from this instead of a hardcoded status list, so an admin renaming/adding/soft-deleting a status via the lookup-table UI needs no code change. `okrTimelineAssignableStatuses($conn)` derives the Timeline-settable subset (everything except the two system-managed statuses, see below).
- Attachment/reference-link staging (`okrStageAttachment`/`okrStageReferenceLink` + their `okrFinalize*`/`okrRemoveStaged*` counterparts) — the create form stages uploads/links in `$_SESSION` before the card exists, then `createCard` finalizes them once the card row is inserted. Attachments only ever touch local disk transiently (`uploads/tmp/`, needed because `CURLFile` requires a real filesystem path) — the permanent copy lives on the corporate Synology NAS (`nas_config.php` / `lib/synologynas.php`), never in `uploads/`.
- `okrLogAudit` / `okrFetchAuditLogs` — every status change, lock/unlock, suspend/unsuspend writes one `okr_audit_logs` row (`card_id`, `event`, `actor_staff_id`, `changes` JSON, `summary`).

### OKR lifecycle / statuses

Statuses (`okr_statuses` table, current values — the table's `value` strings are admin-editable and have been renamed before, so **treat this list as a snapshot, not a contract**; always confirm against `okrFetchStatuses($conn)` / the live table): `Draft`, `Active`, `Completed`, `Completed with Excellence`, `Extended`, `Failed`, `Suspended`, `Completed with Extension`.

The module does not hardcode this list. `okrFetchStatuses($conn, $include_recycled)` (`lib.php`) is the single DB-driven read of the table; `okrTimelineAssignableStatuses($conn)` derives what the Timeline card's Status field can be set to directly (`edit.php`'s dropdown, `backend.php`'s `updateCard` validation) — every non-recycled status except two that are only ever reached indirectly:
- `Suspended` — only via the dedicated CEO Suspend/Unsuspend actions, which have their own restore-previous-status logic on unsuspend.
- `Completed with Extension` — never submitted directly; `updateCard` remaps a `Completed` submission to this status instead when the card is (or is being) extended, so the extension is visible in the stored value itself, not just a computed display label. `okrStatusDisplayLabel($status, $extended)` only previews this on the edit form's Completed option before save.

A handful of status *names* are still irreducible constants in `lib.php` (`OKR_STATUS_ACTIVE`, `OKR_STATUS_DRAFT`, `OKR_STATUS_COMPLETED`, `OKR_STATUS_SUSPENDED`, `OKR_STATUS_COMPLETED_EXTENSION`) because specific business logic keys off their identity (which status is the create-card default, which status a Completed+extended card resolves to, etc.) — not a table-shape fact, so not derivable from a query. Everything else (paid/lockable-for-payout logic, pill/tile styling, dropdown/filter option lists) is driven off the table's own data (`pays_incentive`, `sort_order`, `recycle`) or the shared `okrPillClass`/`okrIncentiveTileClass` maps in `lib.php`, never re-declared per-page or per-JS-file.

- **Final Due Date**: mirrors `end_date` unless the card is `extended` *and* actually closed (`closed_at` set), in which case it follows `closed_at` instead — it does not just mirror `extended_date` the moment the Extended checkbox is ticked.
- **Paid / lockable for payout**: driven by `okr_statuses.pays_incentive`, not a hardcoded list of status names — `okrIsPaidStatus`-style checks were replaced with `os.pays_incentive = 1` joined into the relevant queries (`okrCardSelectSql`, `dashboardStats`, `okrStaffPerformanceRows`, `okrExportRows`, `okrLockPayoutCards`). A future status flagged `pays_incentive = 1` becomes forecastable/lockable automatically.
- **Incentive**: `okr_levels.base_rm` (Level 1 = RM0, Levels 2–4 = escalating RM) is the payout amount, split per `okr_incentive_rules` when there's a second owner (see `okrStaffPerformanceRows` above). `incentive_locked` freezes both the card (no further edits) and the payout amount once People Management locks it for payroll.
- **Difficulty levels / incentive rules / statuses / types** are all soft-deletable lookup tables (`recycle` flag) editable only by admin, not hard-deleted, so historical cards keep referencing retired values.
- **Backdating**: `okr_config.backdate_enabled` globally allows Start/End/Extended dates to be set in the past across the module when enabled; default is disabled. Read via `okrBackdateEnabled($conn)` in `lib.php`. Writable from **two** places (both write the same `okr_config` row): this repo's own `admin/index.php` (`admin/backend.php`'s `toggleBackdate` action), and `atem/admin/index.php`'s "Allow Backdated OKRs" toggle (`atem/admin/backend.php`'s `toggleOkrBackdate` action, not part of this repo) — see "Admin" below for why both exist.

### Admin

`admin/index.php` + `admin/backend.php` are this module's own tiny admin area (distinct from the outer `odb` admin) — currently just the backdate toggle, gated by `$okr_is_admin` (not grade); `admin/backend.php` independently re-verifies the SuperAdmin union server-side. **`navbar.php` no longer links here** — ATEM and OKR are combined under one Admin menu, so the nav's "Admin" item points to `atem/admin/index.php` instead, whose "Allow Backdated OKRs" toggle (`atem/admin/backend.php`'s `toggleOkrBackdate` action, not part of this repo) writes the exact same `okr_config.backdate_enabled` row. This page and its backend are kept (not deleted) intentionally — they still work if reached directly, just aren't surfaced in the nav — so treat both write paths as live when reasoning about this setting, and keep them in sync if the toggle's behavior ever changes.

## Key Files

| File | Role |
|---|---|
| `header.php` | Base layout top half; auth gate via `lock_adv.php`; sets `$okr_permission`/`$okr_is_admin` (union of `staff.okr`/`staff.atem`) |
| `navbar.php` | Nav; Performance link (grade 2+/dept 17/`$okr_is_admin`) and Access Control/Masterlist/Admin links (all gated on `$okr_is_admin`) all point into the `atem/` repo, not this one — OKR has no Performance or Admin page of its own linked from the nav |
| `lib.php` | All shared query builders, scope/permission logic, formatters (see above) |
| `nas_config.php` | Synology NAS connection constants + `corpNasConnect()`; requires `lib/synologynas.php` |
| `backend.php` | Main AJAX/JSON dispatcher for card CRUD, dashboard stats, performance, lock/unlock, suspend |
| `index.php` | Dashboard (stat cards, type/level/department breakdowns) |
| `list.php` / `view.php` | Card list and single-card read view; CEO Suspend/Unsuspend action lives in `view.php` |
| `create.php` / `edit.php` | Card create/edit forms; grade 3+/admin only for create, issuer/admin + unlocked for edit |
| `performance.php` / `export_performance.php` | Grade 4+/admin staff performance view and CSV export (with auto-lock) |
| `download.php` | Streams an attachment from the NAS after re-checking scope access |
| `admin/index.php` / `admin/backend.php` | This module's own admin settings (backdate toggle); still functional but no longer linked from `navbar.php` (see "Admin" above) |
| `js/*.js` | One vanilla JS file per page, matching the PHP page name |
| `sql/*.sql` | phpMyAdmin dumps of each `okr_*` table's schema — reference for column names/types, not a migration system |

## Database Tables

| Table | Purpose |
|---|---|
| `okr_cards` | The core OKR record: objective, key results, type, difficulty level, owner(s), issuer, dates, status, incentive lock state |
| `okr_card_attachments` | File attachments per card; `stored_name` is the NAS path, not a local one |
| `okr_reference_links` | Named URL references per card |
| `okr_audit_logs` | Append-only event log per card (status changes, lock/unlock, suspend/unsuspend) |
| `okr_levels` | Difficulty level 1–4 → label/rubric/`base_rm` payout, soft-delete via `recycle` |
| `okr_incentive_rules` | `RULE1` (100% to one incentivised owner) / `RULE2` (50/50 split), soft-delete via `recycle` |
| `okr_statuses` | Lifecycle status lookup, `pays_incentive` flag, soft-delete via `recycle` |
| `okr_types` | Committed / Aspiration / Learning, soft-delete via `recycle` |
| `okr_config` | Key/value settings table; currently only `backdate_enabled` (written from either this repo's `admin/backend.php` or `atem/admin/backend.php`, read here via `okrBackdateEnabled()`) |
| `staff`, `staff_department`, `staff_grade`, `staff_struct` | Outer `odb` app tables — grade, `okr` admin flag, comma-separated `department`, name resolution. Not owned by this repo. |

## Important Constraints

- Do not bypass `lock_adv.php`/`common/index_adv.php` session checks, and do not edit those files as part of OKR work — they are shared outer-app infrastructure.
- Do not create new SQL migration files under `sql/` for schema changes that have already shipped — those files are dumps of the current live schema, not a migration history. If you change a table, update the corresponding dump to match.
- Attachments must go through the staged-upload flow (`okrStageAttachment`/`okrFinalizeStagedAttachments`) and the NAS (`corpNasConnect()`), never saved permanently under `uploads/` (`uploads/tmp/` is transient only).
- Never let a `incentive_locked` card be edited, suspended, or have its status changed — every write path (`updateCard`, `suspendCard`, `unsuspendCard`) must check this first.
