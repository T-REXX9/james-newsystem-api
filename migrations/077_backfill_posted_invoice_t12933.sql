-- Ensure the posted T-12933 invoice is present with the values captured when
-- Sales Order NSO-49187 was converted on 2026-11-05.
-- setup.sh reapplies SQL migrations, so each insert is guarded by stable keys.

CREATE TABLE IF NOT EXISTS tblvip_document_discount (
    lid INT AUTO_INCREMENT PRIMARY KEY,
    lmain_id INT NOT NULL,
    ldocument_type VARCHAR(32) NOT NULL,
    ldocument_refno VARCHAR(64) NOT NULL,
    lcustomerid VARCHAR(64) DEFAULT NULL,
    lsales_date VARCHAR(32) DEFAULT NULL,
    lapplied TINYINT NOT NULL DEFAULT 0,
    ltier VARCHAR(16) NOT NULL DEFAULT 'regular',
    lpercentage DECIMAL(10,2) NOT NULL DEFAULT 0,
    ldiscount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    ltotal_to_pay DECIMAL(15,2) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_vip_doc (ldocument_type, ldocument_refno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tblinvoice_list (
    linvoice_no,
    ldatetime,
    lmain_id,
    luser,
    lrefno,
    lstatus,
    lshipped,
    lterms,
    lsales_refno,
    lsales_no,
    lcancel_invoice,
    lsales_address,
    lprod_type,
    lpo_no,
    lmy_refno,
    ldel_to,
    lcancel,
    lcustomerid,
    ltax_type,
    ldate,
    ldiscount_amt,
    lupdated,
    lcustomer_name,
    lsales_person,
    lsales_person_id,
    lyour_refno,
    lprice_group,
    lcredit_limit,
    lpromisorry_note,
    lnote,
    IsPrinted,
    lcancel_reason,
    lvat_percent,
    lold_invoice_no,
    lchange_reason,
    lchange_date,
    ldm_no,
    ldm_refno,
    ldm_trackingno,
    ldcr_no,
    ldcr_refno,
    lreupdate,
    created_at
)
SELECT
    'T-12933',
    FROM_UNIXTIME(1793891242),
    '1',
    '1',
    '2026110523072233840',
    'Posted',
    '',
    'AP/TT-PNB',
    '2026110523071040306',
    'NSO-49187',
    0,
    'LALAMOVE : 253 QUIRINO HIGHWAY, QUEZON CITY',
    NULL,
    '',
    'INQ26-20992',
    NULL,
    NULL,
    '102021072911493995101',
    NULL,
    '2026-11-05',
    NULL,
    0,
    'FV ARCILLA CALIBRATION CENTER',
    'test',
    '92',
    'PHILIP ARCILLA @09078198211',
    'vip 1',
    0,
    '',
    '',
    0,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    '0',
    '2026-11-05 23:07:22'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM tblinvoice_list
    WHERE lrefno = '2026110523072233840'
       OR linvoice_no = 'T-12933'
);

INSERT INTO tblinvoice_itemrec (
    lrefno,
    ltype,
    litemid,
    lname,
    ldesc,
    lprice,
    lqty,
    luser,
    lpoints,
    lpatient_id,
    ldiscount,
    ltemprecid,
    lvariant_main,
    lvariant_sub,
    lvariant_id,
    lunit,
    linv_refno,
    litem_refno,
    litemcode,
    lpartno,
    lbrand,
    llocation,
    lremark,
    lcategory,
    linvoice_refno,
    ledited_price
)
SELECT
    '2026110523071040306',
    NULL,
    '35131835920200807142128',
    NULL,
    'PLUNGER',
    540,
    1,
    '1',
    NULL,
    NULL,
    0,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    '35131835920200807142128',
    '35131835920200807142128',
    'QK2-006',
    'P-P300',
    'ISHINOMOTO',
    '',
    'OnStock',
    NULL,
    '2026110523072233840',
    NULL
FROM DUAL
WHERE EXISTS (
    SELECT 1
    FROM tblinvoice_list
    WHERE lrefno = '2026110523072233840'
      AND linvoice_no = 'T-12933'
)
  AND NOT EXISTS (
    SELECT 1
    FROM tblinvoice_itemrec
    WHERE linvoice_refno = '2026110523072233840'
);

INSERT INTO tblvip_document_discount (
    lmain_id,
    ldocument_type,
    ldocument_refno,
    lcustomerid,
    lsales_date,
    lapplied,
    ltier,
    lpercentage,
    ldiscount_amount,
    ltotal_to_pay
)
SELECT
    1,
    'invoice',
    '2026110523072233840',
    '102021072911493995101',
    '2026-11-05',
    1,
    'silver',
    10.00,
    54.00,
    486.00
FROM DUAL
WHERE EXISTS (
    SELECT 1
    FROM tblinvoice_list
    WHERE lrefno = '2026110523072233840'
      AND linvoice_no = 'T-12933'
)
  AND NOT EXISTS (
    SELECT 1
    FROM tblvip_document_discount
    WHERE ldocument_type = 'invoice'
      AND ldocument_refno = '2026110523072233840'
);

INSERT INTO tblaudit_trail (
    lmain_id,
    luser_id,
    lpage,
    laction,
    ldatetime,
    lrefno,
    lreason,
    lold_status,
    lnew_status
)
SELECT
    1,
    1,
    'Invoice',
    'Create',
    FROM_UNIXTIME(1793891242),
    '2026110523072233840',
    NULL,
    NULL,
    NULL
FROM DUAL
WHERE EXISTS (
    SELECT 1
    FROM tblinvoice_list
    WHERE lrefno = '2026110523072233840'
      AND linvoice_no = 'T-12933'
)
  AND NOT EXISTS (
    SELECT 1
    FROM tblaudit_trail
    WHERE lpage = 'Invoice'
      AND laction = 'Create'
      AND lrefno = '2026110523072233840'
);
