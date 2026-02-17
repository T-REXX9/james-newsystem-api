# Old System Mapping: Inventory Audit Report

## Scope
- Old page: Stock Audit Report (`report/stock_audit`)
- New API target: `GET /api/v1/inventory-audits`

## Old-System Source Files
- Controller: `/Volumes/ORICO/james-system/james-oldsystem/controllers/Reportctl.php`
  - `stock_audit()`
  - `stock_audit_report()`
- Model: `/Volumes/ORICO/james-system/james-oldsystem/models/Reportmod.php`
  - `get_inv_items_bycode($partno, $code)`
  - `get_inv_stock_adjustment($item_sessionid, $datefrom, $dateto)`
- Views:
  - `/Volumes/ORICO/james-system/james-oldsystem/views/eclinic_template/report_stock_adjustment.php`
  - `/Volumes/ORICO/james-system/james-oldsystem/views/eclinic_template/report_stock_adjustment_view.php`

## Legacy Filters and Behavior
- Date covered (`txttype`):
  - `All` (no date filter)
  - `Today`
  - `Week` (last 1 week)
  - `Month` (last 1 month)
  - `Year` (last 1 year)
  - `Custom` (requires `txtdatefrom`, `txtdateto`)
- Optional item filters:
  - `Part Number` (`txtPartno`) exact match unless `All`
  - `Product Code` (`txtCode`) LIKE search unless `All`
- Data source:
  - Items from `tblinventory_item`
  - Adjustment rows from `tblstock_adjustment_item` by `litemsession`
  - Report displays only rows where `ladjust_qty != 0`

## Legacy Output Columns
Per item header:
- item code (`litemcode`)
- part no (`lpartno`)
- brand (`lbrand`)
- description (`ldescription`)

Per adjustment row:
- date (`ldatetime`)
- warehouse (`lwarehouse`)
- location (`llocation`)
- qty stock (`lold_qty`)
- physical count (`ladjust_qty`)
- discrepancy (`abs(ladjust_qty - lold_qty)`)
- value (`linv_value`)
- remark (`lremarks`)

## New API Parity (Implemented)
- `GET /api/v1/inventory-audits`
  - Query params:
    - `main_id` (required)
    - `time_period` (`all|today|week|month|year|custom`)
    - `date_from`, `date_to` (required for `custom`)
    - `part_no` (default `All`)
    - `item_code` (default `All`)
    - `page`, `per_page`
  - Response includes:
    - grouped records per item (`records`)
    - flat records (`flat_records`)
    - summary totals (`total_items`, `total_adjustments`, `total_value`, `total_discrepancy`)
- `GET /api/v1/inventory-audits/filter-options`
  - Returns distinct part numbers and item codes for filter dropdowns.

## Notes
- This module is report/read-focused (no CRUD writes in legacy flow).
- UI integration is intentionally deferred per request.
