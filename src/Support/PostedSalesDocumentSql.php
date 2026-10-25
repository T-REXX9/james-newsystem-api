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
        // Posted invoices also carry a separate cancellation flag. Normalize
        // lcancel because posted documents commonly store it as 0 or blank.
        return sprintf(
            "(COALESCE(NULLIF(TRIM(%1\$s.lcancel), ''), '0') = '0' AND COALESCE(%1\$s.lcancel_invoice, 0) = 0 AND LOWER(COALESCE(%1\$s.lstatus, '')) <> 'cancelled')",
            $alias
        );
    }

    public static function deliveryReceiptIsPosted(string $alias = 'l'): string
    {
        return sprintf("COALESCE(NULLIF(TRIM(%s.lcancel), ''), '0') = '0'", $alias);
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
        invoice_sales.customer_id,
        invoice_sales.customer_name,
        invoice_sales.sale_date,
        SUM(invoice_sales.amount) AS amount,
        COUNT(*) AS document_count
    FROM (
        SELECT
            l.lrefno,
            l.lcustomerid AS customer_id,
            NULLIF(TRIM(l.lcustomer_name), '') AS customer_name,
            DATE(l.ldate) AS sale_date,
            GREATEST(
                SUM(COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0)
                    * CASE WHEN LOWER(COALESCE(l.ltax_type, '')) = 'exclusive' THEN 1.12 ELSE 1 END)
                - CASE WHEN COALESCE(vip.lapplied, 0) = 1 THEN COALESCE(vip.ldiscount_amount, 0) ELSE 0 END,
                0
            ) AS amount
        FROM tblinvoice_list l
        INNER JOIN tblinvoice_itemrec i ON i.linvoice_refno = l.lrefno
        LEFT JOIN tblvip_document_discount vip
          ON vip.lmain_id = l.lmain_id
         AND vip.ldocument_type = 'invoice'
         AND vip.ldocument_refno = l.lrefno
        WHERE l.lmain_id = :sales_report_invoice_main_id
          AND {$invoicePosted}
          AND l.ldate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND l.ldate < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
          AND COALESCE(l.lcustomerid, '') <> ''
        GROUP BY l.lrefno, l.lcustomerid, l.lcustomer_name, DATE(l.ldate), vip.lapplied, vip.ldiscount_amount
    ) invoice_sales
    GROUP BY invoice_sales.customer_id, invoice_sales.customer_name, invoice_sales.sale_date

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

    /**
     * Current-month posted sales documents whose customer identifier is blank.
     * Kept separate from the customer-keyed CTE so existing consumers retain
     * their current customer grouping behavior while company totals can still
     * reconcile all posted documents.
     */
    public static function currentMonthBlankCustomerSalesCte(): string
    {
        $invoicePosted = self::invoiceIsPosted('l');
        $receiptPosted = self::deliveryReceiptIsPosted('l');

        return <<<SQL
posted_sales_current_month_blank_customer AS (
    SELECT COALESCE(SUM(document_sales.amount), 0) AS amount
    FROM (
        SELECT invoice_sales.amount
        FROM (
            SELECT
                l.lrefno,
                GREATEST(
                    SUM(COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0)
                        * CASE WHEN LOWER(COALESCE(l.ltax_type, '')) = 'exclusive' THEN 1.12 ELSE 1 END)
                    - CASE WHEN COALESCE(vip.lapplied, 0) = 1 THEN COALESCE(vip.ldiscount_amount, 0) ELSE 0 END,
                    0
                ) AS amount
            FROM tblinvoice_list l
            INNER JOIN tblinvoice_itemrec i ON i.linvoice_refno = l.lrefno
            LEFT JOIN tblvip_document_discount vip
              ON vip.lmain_id = l.lmain_id
             AND vip.ldocument_type = 'invoice'
             AND vip.ldocument_refno = l.lrefno
            WHERE l.lmain_id = :blank_sales_invoice_main_id
              AND {$invoicePosted}
              AND l.ldate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
              AND l.ldate < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
              AND COALESCE(l.lcustomerid, '') = ''
            GROUP BY l.lrefno, vip.lapplied, vip.ldiscount_amount
        ) invoice_sales

        UNION ALL

        SELECT SUM(COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0)) AS amount
        FROM tbldelivery_receipt l
        INNER JOIN tbldelivery_receipt_items i ON i.lor_refno = l.lrefno
        WHERE l.lmain_id = :blank_sales_receipt_main_id
          AND {$receiptPosted}
          AND l.ldate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND l.ldate < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
          AND COALESCE(l.lcustomerid, '') = ''
    ) document_sales
)
SQL;
    }
}
