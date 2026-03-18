# Category Flow Mapping

## Old System
- Controller: `james-oldsystem/controllers/Managementctl.php`
  - Route/action: `management/category/{rec|search|add|edit|delete}`
- View: `james-oldsystem/views/eclinic_template/management_category.php`
- Model: `james-oldsystem/models/Managementmod.php`
  - `get_category_list($code, $name, $remark)`
  - `new_insert_category()`
  - `new_update_category($suppid)`
  - `new_delete_category($suppid)`

## Old-System Behavior
- Single-field master list backed by `tblproduct_group`.
- Stored column: `lname`
- Primary key: `lid`
- Search behavior filters by category name text only.
- List is ordered by `lname ASC`.
- Delete is a hard delete.
- Rename also updates `tblinventory_item.lproduct_group` where the item still matches the old category name.

## New System
- API routes:
  - `GET /api/v1/categories`
  - `GET /api/v1/categories/{categoryId}`
  - `POST /api/v1/categories`
  - `PATCH /api/v1/categories/{categoryId}`
  - `DELETE /api/v1/categories/{categoryId}`
- API files:
  - `api/src/Controllers/CategoryController.php`
  - `api/src/Repositories/CategoryRepository.php`
- Frontend files:
  - `james-newsystem/services/categoryLocalApiService.ts`
  - `james-newsystem/components/Maintenance/Product/Categories.tsx`

## Mapping Notes
- Old `lname` maps to new frontend/API field `name`.
- `main_id` is validated at the API boundary for consistency with the migrated app, even though `tblproduct_group` is not tenant-scoped in the legacy table.
- The new page keeps the old module scope: one category name field with searchable CRUD.
- Update behavior intentionally preserves the legacy item-group sync into `tblinventory_item`.
