# Remark Template Flow Mapping

## Old System
- Controller: `james-oldsystem/controllers/Managementctl.php`
  - Route/action: `management/remark_template/{rec|search|add|edit|delete}`
- View: `james-oldsystem/views/eclinic_template/management_remark_template.php`
- Model: `james-oldsystem/models/Managementmod.php`
  - `get_remark_template_list($code, $name, $remark)`
  - `new_insert_remark_template()`
  - `new_update_remark_template($suppid)`
  - `new_delete_remark_template($suppid)`

## Old-System Behavior
- Single-field master list backed by `tblremark_template`.
- Stored column: `lname`
- Primary key: `lid`
- Search behavior filters by remark text only.
- List is ordered by `lname ASC`.
- Delete is a hard delete.

## New System
- API routes:
  - `GET /api/v1/remark-templates`
  - `GET /api/v1/remark-templates/{remarkTemplateId}`
  - `POST /api/v1/remark-templates`
  - `PATCH /api/v1/remark-templates/{remarkTemplateId}`
  - `DELETE /api/v1/remark-templates/{remarkTemplateId}`
- API files:
  - `api/src/Controllers/RemarkTemplateController.php`
  - `api/src/Repositories/RemarkTemplateRepository.php`
- Frontend files:
  - `james-newsystem/services/remarkTemplateLocalApiService.ts`
  - `james-newsystem/components/Maintenance/Product/RemarkTemplates.tsx`

## Mapping Notes
- Old `lname` maps to new frontend/API field `name`.
- `main_id` is validated at the API boundary for consistency with the migrated app, even though `tblremark_template` is not tenant-scoped in the legacy table.
- The new page keeps the old module scope: one remark text field with searchable CRUD.
