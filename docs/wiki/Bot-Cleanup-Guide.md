# Bot Cleanup Guide

## Overview

The Bot Cleanup system identifies suspected bot accounts using real WHMCS financial data with zero false positives. It distinguishes between legitimate customers and automated attackers by examining invoice history and active hosting services.

## Bot Detection Criteria

A **real customer** has at least ONE of:
- At least one paid invoice (total > $0)
- At least one active hosting service

A **suspected bot** has:
- Zero paid invoices AND
- Zero active hosting services

This approach eliminates false positives from legitimate customers who register but haven't purchased yet.

## Available Actions

### 1. Flag (Non-Destructive)

Adds a `[FPS-BOT]` note to the client record for manual review.

**Effect**: Read-only marker; account remains active and unchanged.
**Preview**: Yes
**Data Impact**: Adds one note entry to client record.

### 2. Deactivate (Reversible)

Sets client status to "Inactive" in WHMCS.

**Effect**: Client cannot log in; hosting services remain active.
**Preview**: Yes
**Data Impact**: Changes `tblclients.status` to "Inactive". Reversible via WHMCS Admin.

### 3. Purge (Standard)

Deletes client with ALL dependencies if no invoices or orders exist.

**Deletion order**:
1. Verify client has zero orders AND zero invoices
2. Delete from `tblclients`
3. Delete associated billing contacts from `tblcontacts`
4. Remove client from any allowlist/blacklist
5. Delete all fraud checks for that client

**Preview**: Yes
**Data Impact**: Complete account deletion. **Cannot be undone.**
**Fraud Preservation**: Automatically harvests fraud intelligence to Global Intel before deletion.

### 4. Deep Purge (Aggressive)

Deletes client accounts where ALL remaining records are marked Fraud/Cancelled/Unpaid.

**Detection logic**:
- If all invoices are "Unpaid" or "Cancelled", AND
- If all orders are "Fraud" or "Cancelled", OR
- If client has zero invoices and zero orders

**Use case**: Bot accounts that accumulated fraudulent activity but shouldn't be part of your business history.

**Preview**: Yes
**Data Impact**: Complete account deletion. **Cannot be undone.**
**Fraud Preservation**: Harvests fraud intelligence to Global Intel before deletion.

## Preview and Dry-Run

Before executing any destructive action (purge, deep_purge, deactivate), click the action button in preview mode. The system shows:

- Exact client names and IDs to be affected
- Invoice totals and statuses
- Order count and statuses
- What data will be deleted
- Estimated time to completion

**No changes are made in preview mode.** Click "Execute" in the progress dialog to proceed.

## Harvest Before Delete

When purging or deep purging, FPS automatically extracts fraud intelligence:

**Data harvested**:
- SHA-256 email hash (irreversible)
- IP address (if Global Intel IP sharing enabled)
- Country code
- Final risk score
- Evidence flags (disposable email, proxy, velocity, etc.)

**Destination**: `mod_fps_global_intel` table for cross-instance sharing.

**GDPR Compliant**: Email addresses are hashed before storage; IP sharing is optional.

## User Account Cleanup (WHMCS 8.x)

WHMCS 8.x separates Client (billing) from User (login). When a bot client is purged, the associated login account may orphan.

### Automatic Cleanup

When a bot client is purged, FPS checks if the associated user login:
- Has no other client links, OR
- Is not linked to any real customers

If so, FPS removes the orphan user from `tblusers`.

### Manual Detection

Go to **Bot Cleanup > User Account Cleanup** section:

1. Click "Detect Orphan Users"
2. System scans for users with:
   - Zero active client associations
   - All linked clients are bots (zero paid invoices/services)
3. Orphan users are highlighted in the list
4. Click "Select All Bots" to mark for removal
5. Click "Purge Selected" to delete login accounts

### Integration with WHMCS Users Page

If enabled in Settings, FPS injects a toolbar into the standard WHMCS Users page (`/admin/user/list`):

- "Scan for Bot Users" button initiates detection
- Matching rows are highlighted in red with bot icon
- "Select All Bots" and "Purge Selected" buttons appear once results load
- Users are deleted from `tblusers` only; client accounts unaffected

**Enable/Disable**: Settings > Bot & User Cleanup > "Inject user purge toolbar"

## Mass Purge Actions

The Bot Cleanup tab includes bulk action buttons at top and bottom:

| Button | Scope | Behavior |
|--------|-------|----------|
| Dry-Run (Preview) | All detected bots | Shows count and list without changes |
| Dry-Run (Top) | All detected bots | Same as above |
| Flag All | All detected bots | Adds notes; reversible |
| Deactivate All | All detected bots | Sets status to Inactive; reversible |
| Purge All | Bots with zero records | Deletes accounts; one-way |
| Deep Purge All | Aggressive candidates | Deletes fraud/cancelled accounts; one-way |

Each action includes a confirmation dialog showing exact numbers and impact.

## Status Filters

Narrow bot detection by client status:

- **All Statuses**: Every client
- **Active Only**: Clients with status = Active
- **Inactive Only**: Clients with status = Inactive
- **Closed**: Clients with status = Closed

## What Happens After Purge

### Client Account
- Entry removed from `tblclients`
- Name, email, phone all deleted
- Cannot be recovered without database backup

### Associated Data
- All orders deleted (unless linked to other clients)
- All invoices deleted (unless linked to other clients)
- All contacts deleted
- All fraud checks deleted locally (preserved in global_intel)

### Global Intel
- Email hash, IP, country, score stored in `mod_fps_global_intel`
- Can be pushed to central hub (if configured)
- Other WHMCS instances can query for fraud signals from this bot

### User Account (if orphan)
- Login removed from `tblusers`
- User cannot log in
- Any API tokens for that user are invalidated

## Safety Mechanisms

1. **Confirmations**: Two-click confirmation required for destructive actions
2. **Preview Mode**: Always preview before execution
3. **Dry-Run AJAX Actions**: Each action has a preview step that returns counts
4. **Logging**: All purges logged to WHMCS Activity Log with user ID and count
5. **Fraud Preservation**: Intelligence harvested before deletion
6. **Backups**: WHMCS database backup recommended before deep purge operations

## Troubleshooting

**Q: Bot Scan shows 0 suspects**
- Verify status filter: "Inactive Only" may hide active bots
- All clients may have paid invoices or active hosting
- Check specific client in Client Profile tab to verify financial data

**Q: Action fails with "Access Denied"**
- Verify admin user has FPS module access in role permissions
- Check WHMCS error log for specific database errors

**Q: Purge hangs or times out**
- Large bulk purges may exceed PHP timeout on shared hosting
- Purge smaller batches (50-100 at a time) via dry-run dialog
- Contact hosting provider for timeout limit increase

**Q: Deleted data was important**
- Restore from database backup
- Global Intel data is preserved in `mod_fps_global_intel`
- Open a support ticket with backup timestamp
