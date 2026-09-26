<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical legacy Sales Report document rules.
 *
 * A posted sale is an uncancelled invoice or delivery receipt. Sales orders
 * are references for those documents, not posted sales in their own right.
 */
final class PostedSalesDocumentSql
{
    public static function invoiceIsPosted(string $alias = 'l'): string
    {
        // Match SalesReportRepository exactly: NULL AND empty-string lcancel
        // both count as posted. Some posted documents store lcancel = '' rather
        // than NULL, so an `IS NULL` check silently drops them (e.g. GREG's
        // delivery receipt N-D39172, which the Sales Report page does count).
        return sprintf("COALESCE(%s.lcancel, '') = ''", $alias);
    }

    public static function deliveryReceiptIsPosted(string $alias = 'l'): string
    {
        return sprintf("COALESCE(%s.lcancel, '') = ''", $alias);
    }

    /**
     * CTEs for current-month posted sales grouped by customer. The document
     * rules and invoice VAT calculation deliberately match SalesReportRepository.
     *
     * Required named parameters: :sales_report_invoice_main_id and
     * :sales_report_dr_main_id.
     */
    public static function currentMonthCustomerSalesCtes(): string
    {
        $invoicePosted = self::invoiceIsPosted('l');
        $receiptPosted = self::deliveryReceiptIsPosted('l');

        return <<<SQL
posted_sales_current_month_documents AS (
    SELECT
        l.lcustomerid AS customer_id,
        NULLIF(TRIM(l.lcustomer_name), '') AS customer_name,
        DATE(l.ldate) AS sale_date,
        SUM(COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0)
            * CASE WHEN LOWER(COALESCE(l.ltax_type, '')) = 'exclusive' THEN 1.12 ELSE 1 END) AS amount,
        COUNT(DISTINCT l.lrefno) AS document_count
    FROM tblinvoice_list l
    INNER JOIN tblinvoice_itemrec i ON i.linvoice_refno = l.lrefno
    WHERE l.lmain_id = :sales_report_invoice_main_id
      AND {$invoicePosted}
      AND l.ldate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND l.ldate < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
      AND COALESCE(l.lcustomerid, '') <> ''
    GROUP BY l.lcustomerid, NULLIF(TRIM(l.lcustomer_name), ''), DATE(l.ldate)

    UNION ALL

    SELECT
        l.lcustomerid AS customer_id,
        NULLIF(TRIM(l.lcustomer_name), '') AS customer_name,
        DATE(l.ldate) AS sale_date,
        SUM(COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0)) AS amount,
        COUNT(DISTINCT l.lrefno) AS document_count
    FROM tbldelivery_receipt l
    INNER JOIN tbldelivery_receipt_items i ON i.lor_refno = l.lrefno
    WHERE l.lmain_id = :sales_report_dr_main_id
      AND {$receiptPosted}
      AND l.ldate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND l.ldate < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
      AND COALESCE(l.lcustomerid, '') <> ''
    GROUP BY l.lcustomerid, NULLIF(TRIM(l.lcustomer_name), ''), DATE(l.ldate)
),
sales_report_current_month AS (
    SELECT
        customer_id,
        MAX(customer_name) AS customer_name,
        MIN(sale_date) AS first_sale_date,
        MAX(sale_date) AS last_sale_date,
        SUM(amount) AS current_month_sales,
        SUM(document_count) AS document_count
    FROM posted_sales_current_month_documents
    GROUP BY customer_id
)
SQL;
    }
}
