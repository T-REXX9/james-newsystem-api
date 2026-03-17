# Courier Flow Mapping

## Old System
- Controller: `james-oldsystem/controllers/Managementctl.php`
  - Route/action: `management/courier/{rec|search|add|edit|delete}`
- View: `james-oldsystem/views/eclinic_template/management_courier.php`
- Model: `james-oldsystem/models/Managementmod.php`
  - `get_courier_list($code, $name, $remark)`
  - `new_insert_courier()`
  - `new_update_courier($suppid)`
  - `new_delete_courier($suppid)`

## Old-System Behavior
- Single-field master list backed by `tblsend_by`.
- Stored column: `lname`
- Primary key: `lid`
- Search behavior filters by courier name text only.
- List is ordered by `lname ASC`.
- Delete is a hard delete.

## New System
- API routes:
  - `GET /api/v1/couriers`
  - `GET /api/v1/couriers/{courierId}`
  - `POST /api/v1/couriers`
  - `PATCH /api/v1/couriers/{courierId}`
  - `DELETE /api/v1/couriers/{courierId}`
- API files:
  - `api/src/Controllers/CourierController.php`
  - `api/src/Repositories/CourierRepository.php`
- Frontend files:
  - `james-newsystem/services/courierLocalApiService.ts`
  - `james-newsystem/components/Maintenance/Product/Couriers.tsx`

## Mapping Notes
- Old `lname` maps to new frontend/API field `name`.
- `main_id` is validated at the API boundary for consistency with the migrated app, even though `tblsend_by` is not tenant-scoped in the legacy table.
- The new page keeps the old module scope: one courier name field with searchable CRUD.
