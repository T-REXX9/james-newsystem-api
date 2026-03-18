# Customer Group Flow Mapping

## Old System
- Controller: `james-oldsystem/controllers/Groupctl.php`
  - Routes/actions: `groups/list`, `groups/search`, `groups/new`, `groups/edit/{id}`, `groups/delete/{id}`
- Views:
  - `james-oldsystem/views/eclinic_template/patient_group.php`
  - `james-oldsystem/views/eclinic_template/patient_group_add.php`
  - `james-oldsystem/views/eclinic_template/patient_group_edit.php`
- Model: `james-oldsystem/models/Groupmod.php`
  - `getrecordlist()`
  - `getrecordlist_search()`
  - `getrecordlist_byid($id)`
  - `insert_group()`
  - `update_group($id)`
  - `delete_group($id)`

## Old-System Behavior
- Customer groups are stored in `tblmain_group`.
- Active UI uses only the group name field `lname`.
- Records are scoped by `lmain_id`.
- List supports search by group name and displays delete confirmation.
- Delete also removes matching `tblgroup_patient` rows for that group name.
- List query also computes a contact count per group.

## New System
- API routes:
  - `GET /api/v1/customer-groups`
  - `GET /api/v1/customer-groups/{groupId}`
  - `POST /api/v1/customer-groups`
  - `PATCH /api/v1/customer-groups/{groupId}`
  - `DELETE /api/v1/customer-groups/{groupId}`
- API files:
  - `api/src/Controllers/CustomerGroupController.php`
  - `api/src/Repositories/CustomerGroupRepository.php`
- Frontend files:
  - `james-newsystem/services/customerGroupLocalApiService.ts`
  - `james-newsystem/components/Maintenance/Customer/CustomerGroups.tsx`

## Mapping Notes
- Old `lname` maps to new frontend/API field `name`.
- The maintenance page now follows the old behavior rather than the Supabase-only `description` and `color` schema.
- Delete behavior removes the group and its `tblgroup_patient` memberships for the same `main_id`.
- Update behavior preserves old-system behavior and does not rewrite related membership rows.
