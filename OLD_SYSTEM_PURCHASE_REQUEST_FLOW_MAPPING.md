# Old System Purchase Request Flow Mapping

## Sources mapped
- `james-oldsystem/controllers/Purchasectl.php`
  - `recordlist_purchase_request()`
  - `purchase_request_view()`
  - `purchase_request_additem()`
- `james-oldsystem/models/Purchasemod.php`
  - `getrecordlist_purchaserequest()`
  - `create_purchaserequest_record()`
  - `getrecord_purchaserequest()`
  - `getitems_purchaserequest()`
  - `insert_purchaserequest_item()`
  - `update_porequestitem_qty()`
  - `update_pr_item()`
  - `delete_porequest_item()`
  - `clear_purchaserequest()`
  - `approve_purchase_request()`

## Old-system behavior
- PR header table: `tblpr_list`
- PR item table: `tblpr_item`
- Sequence source: `tblnumber_generator` with `ltransaction_type='Purchase Request'`
- PR number format: `PR-` + 2-digit year + sequence (`str_pad(..., 2, "0")`)
- Default state on create:
  - `lstatus = Pending`
  - `lapproval = Pending`
- Approve operation:
  - sets `lapproval = Approved`
- Conversion PR -> PO:
  - creates `tblpo_list` record
  - copies selected/all PR items to `tblpo_itemlist`
  - updates PR item links: `lpo_refno`, `lpo_no`
  - updates PR header status to `Submitted`

## API contract implemented
- `GET /api/v1/purchase-requests`
- `GET /api/v1/purchase-requests/next-number`
- `GET /api/v1/purchase-requests/{prRefno}`
- `POST /api/v1/purchase-requests`
- `PATCH /api/v1/purchase-requests/{prRefno}`
- `DELETE /api/v1/purchase-requests/{prRefno}`
- `POST /api/v1/purchase-requests/{prRefno}/items`
- `PATCH /api/v1/purchase-request-items/{itemId}`
- `DELETE /api/v1/purchase-request-items/{itemId}`
- `POST /api/v1/purchase-requests/{prRefno}/actions/{action}`
  - supported: `approve`, `cancel`, `submit`, `convert-po`

## Notes
- PR list/detail data is read from legacy tables directly.
- Status presented by API is normalized to:
  - `Pending`, `Approved`, `Submitted`, `Cancelled`, `Draft` (if present in raw data).
- `main_id` is required by API for consistency with other endpoints.
