# Raw PHP MySQL API

Minimal framework-free API to replace Supabase reads/writes with direct MySQL access via backend endpoints.

## Requirements
- PHP 8.1+
- MySQL access (local import works with `mysql -u root`)

## Setup
1. Copy env file:
   - `cp .env.example .env`
2. Update DB values in `.env` if needed.
3. Run SQL migrations before starting the API, especially before using `GET/PATCH/POST /api/v1/staff`:
   - `mysql -u root topnotch < migrations/001_create_promotions_tables.sql`
   - `mysql -u root topnotch < migrations/002_add_promotion_targeting.sql`
   - `mysql -u root topnotch < migrations/003_create_ai_campaign_tables.sql`
   - `mysql -u root topnotch < migrations/004_add_tblaccount_access_rights.sql`
   - `mysql -u root topnotch < migrations/005_add_stock_adjustment_header_fields.sql`
   - `mysql -u root topnotch < migrations/006_add_access_groups_and_staff_group.sql`
     *(Required for `/api/v1/access-groups` endpoints and `group_id` on `/api/v1/staff`)*
   - `mysql -u root topnotch < migrations/007_add_tblaccount_access_override.sql`
   - `php migrations/008_backfill_access_groups_from_legacy.php`
     *(Required for rollout — backfills `access_groups` and staff assignments from legacy
     `tblusertype`/`tblweb_permission` data. Idempotent; safe to run multiple times.
     Reads DB credentials from `.env`.)*
4. Run local server:
   - `PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8081 -t public`

If you use the combined launcher, API URL/port come from:
- `/Volumes/ORICO/james-system/.env.shared`
- Start command: `/Volumes/ORICO/james-system/start-dev.sh`

## Base URL
- `http://127.0.0.1:8081/api/v1`

## Endpoints
- `GET /health`
- `GET /customers/{sessionId}`
- `GET /customers/{sessionId}/purchase-history?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`
- `GET /collections?main_id={mainId}&search=&status=&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`
- `POST /collections`
- `GET /collections/unpaid?main_id={mainId}&customer_id={customerSessionId}`
- `GET /collections/{collectionRefno}`
- `GET /collections/{collectionRefno}/items`
- `GET /collections/{collectionRefno}/approver-logs`
- `POST /collections/{collectionRefno}/items/post`
- `POST /collections/{collectionRefno}/payments`
- `POST /collections/{collectionRefno}/actions/{action}`
- `PATCH /collection-items/{itemId}`
- `DELETE /collection-items/{itemId}`
- `GET /daily-call-monitoring/excel?main_id={mainId}&status=all|active|inactive|prospective&search=...`
- `GET /daily-call-monitoring/owner-snapshot?main_id={mainId}`
- `GET /daily-call-monitoring/customers/{contactId}/purchase-history?main_id={mainId}`
- `GET /daily-call-monitoring/customers/{contactId}/sales-reports?main_id={mainId}`
- `GET /daily-call-monitoring/customers/{contactId}/incident-reports?main_id={mainId}`
- `GET /fast-slow-inventory-report?main_id={mainId}&sort_by=sales_volume|part_no&sort_direction=asc|desc`
- `GET /inventory-report/options?main_id={mainId}`
- `GET /inventory-report?main_id={mainId}&category=&part_number=&item_code=&stock_status=all|with_stock|without_stock&report_type=inventory|product`
- `GET /sales-return-report/options?main_id={mainId}`
- `GET /sales-return-report?main_id={mainId}&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&status=&search=&page=1&per_page=100`
- `GET /sales-returns?main_id={mainId}&search=&status=&month=MM&year=YYYY&page=1&per_page=50`
- `GET /sales-returns/{refno}?main_id={mainId}`
- `GET /sales-returns/{refno}/items?main_id={mainId}`
- `GET /products?main_id={mainId}&search=&status=all|active|inactive&page=1&per_page=100`
- `GET /products/{productSession}?main_id={mainId}`
- `POST /products`
- `PATCH /products/{productSession}`
- `POST /products/bulk-update`
- `DELETE /products/{productSession}?main_id={mainId}`
- `GET /staff?main_id={mainId}&search=&page=1&per_page=200`
- `POST /staff`
- `GET /staff/{staffId}?main_id={mainId}`
- `PATCH /staff/{staffId}`
- `DELETE /staff/{staffId}?main_id={mainId}`
- `GET /staff/roles?main_id={mainId}`
- `GET /purchase-orders?main_id={mainId}&month=1-12&year=YYYY&status=all|pending|posted|partial delivery|cancelled&search=&page=1&per_page=100`
- `GET /purchase-orders/suppliers?main_id={mainId}`
- `GET /purchase-orders/{purchaseRefno}?main_id={mainId}`
- `POST /purchase-orders`
- `PATCH /purchase-orders/{purchaseRefno}`
- `DELETE /purchase-orders/{purchaseRefno}?main_id={mainId}`
- `POST /purchase-orders/{purchaseRefno}/items`
- `PATCH /purchase-order-items/{itemId}`
- `DELETE /purchase-order-items/{itemId}?main_id={mainId}`
- `GET /receiving-stocks?main_id={mainId}&month=1-12&year=YYYY&status=all|pending|delivered|cancelled&search=&page=1&per_page=100`
- `GET /receiving-stocks/{receivingRefno}?main_id={mainId}`
- `POST /receiving-stocks`
- `PATCH /receiving-stocks/{receivingRefno}`
- `DELETE /receiving-stocks/{receivingRefno}?main_id={mainId}`
- `POST /receiving-stocks/{receivingRefno}/items`
- `PATCH /receiving-stock-items/{itemId}`
- `DELETE /receiving-stock-items/{itemId}?main_id={mainId}`
- `POST /receiving-stocks/{receivingRefno}/finalize`
- `GET /stock-movements?main_id={mainId}&item_id={productSession}&warehouse_id=WH1|All&transaction_type=&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&search=&page=1&per_page=200`
- `GET /stock-movements/{logId}?main_id={mainId}`
- `POST /stock-movements`
- `PATCH /stock-movements/{logId}`
- `DELETE /stock-movements/{logId}?main_id={mainId}`
- `GET /stock-adjustments?main_id={mainId}`
- `GET /stock-adjustments/{refno}?main_id={mainId}`
- `POST /stock-adjustments`
- `POST /stock-adjustments/{refno}/finalize`
- `POST /auth/login`
- `GET /auth/me` (Authorization: Bearer token)
- `POST /auth/logout` (Authorization: Bearer token)
- `GET /access-groups?main_id={mainId}`
- `POST /access-groups`
- `PATCH /access-groups/{id}`
- `DELETE /access-groups/{id}?main_id={mainId}`
- `GET /sales/flow/inquiry/{inquiryRefno}`
- `GET /sales/flow/so/{soRefno}`

## DCR Action Values
For `POST /collections/{collectionRefno}/actions/{action}`:
- `submitrecord`
- `approverecord`
- `disapproverecord`
- `postrecord`
- `cancelrecord`
- `posttoledger`

### Action payload requirements
- `submitrecord`: `{ "main_id": 1 }`
- `approverecord`: `{ "main_id": 1, "staff_id": "123", "remarks": "optional" }`
- `disapproverecord`: `{ "main_id": 1, "staff_id": "123", "remarks": "required in practice" }`

## Sample Payload: Create DCR
```json
{
  "main_id": 1,
  "user_id": 1
}
```

## Sample Payload: Add Collection Payment
```json
{
  "main_id": 1,
  "user_id": 1,
  "customer_id": "customer-session-id",
  "type": "Check",
  "bank": "BDO",
  "check_no": "123456",
  "check_date": "2026-02-16",
  "amount": 5000,
  "status": "Pending",
  "remarks": "partial payment",
  "collect_date": "2026-02-16",
  "transactions": [
    {
      "transaction_type": "Invoice",
      "transaction_refno": "INV_REFNO",
      "transaction_no": "000123",
      "transaction_amount": 3000
    },
    {
      "transaction_type": "OrderSlip",
      "transaction_refno": "DR_REFNO",
      "transaction_no": "N-D123",
      "transaction_amount": 4000
    }
  ]
}
```

## Sample Payload: Update Collection Payment Line
`PATCH /collection-items/{itemId}`

```json
{
  "main_id": 1,
  "user_id": 1,
  "type": "Check",
  "bank": "BDO",
  "check_no": "123456",
  "check_date": "2026-02-16",
  "amount": 4500,
  "status": "Pending",
  "remarks": "updated amount",
  "transactions": [
    {
      "transaction_type": "Invoice",
      "transaction_refno": "INV_REFNO",
      "transaction_no": "000123",
      "transaction_amount": 4500
    }
  ]
}
```

## Sample Payload: Post Selected DCR Items
`POST /collections/{collectionRefno}/items/post`

```json
{
  "main_id": 1,
  "user_id": 1,
  "item_ids": [101, 102]
}
```

## Notes
- Uses PDO with prepared statements only.
- This scaffold is intentionally simple so we can add modules quickly.
- Next step is adding write endpoints for Inquiry -> SO -> Order Slip -> Invoice.
