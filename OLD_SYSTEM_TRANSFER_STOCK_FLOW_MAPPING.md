# Old System Mapping: Transfer Stock

## Scope
- Old page: Inventory Transfer (Transfer Product)
- New target: `/api/v1/transfer-stocks` endpoints in local API

## Old-System Source Files
- `/Volumes/ORICO/james-system/james-oldsystem/controllers/Inventoryctl.php`
- `/Volumes/ORICO/james-system/james-oldsystem/models/Inventorymod.php`

## Tables Used by Legacy Flow
- Header: `tblbranchinventory_transferlist`
- Items: `tblbranchinventory_transferproducts`
- Numbering: `tblnumber_generator`
- Inventory movement logs: `tblinventory_logs`
- Approver check: `tblapprover`

## Core Columns and Meaning
- `tblbranchinventory_transferlist`
- `ltransfer_no`: visible transfer number (example `TR-123`)
- `lrefno`: internal transfer reference key
- `lmain_id`: tenant/company id
- `luser_id`: creator/processor account id
- `lpartno`: comma-separated part list snapshot
- `ltimestamp`: transfer date/time
- `lstatus`: workflow state (`Pending`, `Submitted`, `Approved`)

- `tblbranchinventory_transferproducts`
- `lrefno`: parent transfer key
- `litem_id`: inventory item id (`tblinventory_item.lid`)
- `lpartno`, `litemcode`, `lbrand`, `ldescription`, `llocation`: item identity snapshot
- `litemsession_from`, `lwarehouse_from`, `loriginal_qty_from`: source stock context
- `litemsession_to`, `lwarehouse_to`, `loriginal_qty_to`: destination stock context
- `ltransfer_qty`: quantity to move
- `ledited`: marks user-edited transfer rows

## Legacy Workflow Behavior
1. Create transfer
- Generates transfer number with `tblnumber_generator` (`Transfer Product` sequence).
- Inserts transfer header into `tblbranchinventory_transferlist` with initial `Pending` status.
- Adds rows into `tblbranchinventory_transferproducts` from selected inventory part/items.

2. Edit transfer
- Allowed while still pending/submitted.
- Updates header date/status/part snapshot and row-level transfer quantities/warehouse/session fields.

3. Submit transfer
- `submitRecord`/`editRecord` logic sets header status to `Submitted`.

4. Approve transfer
- Only users in `tblapprover` can approve.
- Approval runs posting logic that inserts two `tblinventory_logs` entries per moved item:
  - `+` entry to destination warehouse/session
  - `-` entry from source warehouse/session
- Header status becomes `Approved` after posting succeeds.

## New API Parity (Implemented)
- `GET /api/v1/transfer-stocks`
- `GET /api/v1/transfer-stocks/{transferRefno}`
- `POST /api/v1/transfer-stocks`
- `PATCH /api/v1/transfer-stocks/{transferRefno}`
- `DELETE /api/v1/transfer-stocks/{transferRefno}`
- `POST /api/v1/transfer-stocks/{transferRefno}/items`
- `PATCH /api/v1/transfer-stock-items/{itemId}`
- `DELETE /api/v1/transfer-stock-items/{itemId}`
- `POST /api/v1/transfer-stocks/{transferRefno}/actions/{action}`

## Action Mapping
- `submit`, `submitRecord`, `editRecord` -> set `Submitted`
- `approve`, `approveRecord` -> approver-validated posting to `tblinventory_logs`, then set `Approved`

## Notes for New-System Integration
- Use `main_id` filter for every request.
- Use server-side pagination (`page`, `per_page`) for list screens.
- Use action endpoint for workflow transitions to keep old approval behavior intact.
