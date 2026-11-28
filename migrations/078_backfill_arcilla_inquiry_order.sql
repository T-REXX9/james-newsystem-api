-- Preserve the Sales Inquiry and Sales Order created for FV Arcilla on
-- 2026-11-05. Guard by their original internal references so other documents
-- that share a printed number remain unchanged.

INSERT INTO tblinquiry (
    linqno,
    ldate,
    ltime,
    lcustomerid,
    lmain_id,
    luser,
    lpayment_status,
    lrefno,
    ltopup,
    ltemprecid,
    lbranch,
    lt_lfname,
    lt_llname,
    ltransaction_status,
    lshipped,
    lsubmitstat,
    lvat,
    lcompany,
    lso_refno,
    lso_no,
    lc_address,
    lc_mobile,
    lc_phone,
    lsalesperson,
    lsales_person_id,
    lterms,
    lterms_condition,
    lsales_address,
    lmy_refno,
    ldel_to,
    ltracking_no,
    lpo_no,
    lnote,
    lpromissory_note,
    lyour_refno,
    lprice_group,
    lcredit_limit,
    lsource,
    IsPrinted,
    IsCancel,
    ltransaction_type,
    lvat_percent,
    lvat_type,
    IsRepeat,
    lurgency,
    lurgency_date,
    lcity
)
SELECT
    'INQ26-20992',
    '2026-11-05',
    '23:05:00',
    '102021072911493995101',
    '1',
    '1',
    'Unpaid',
    '261105110654938120384066',
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    'Unposted',
    '',
    'Submitted',
    NULL,
    'FV ARCILLA CALIBRATION CENTER',
    '2026110523071040306',
    'NSO-49187',
    NULL,
    NULL,
    NULL,
    'test',
    '92',
    'AP/TT-PNB',
    'AP/TT-PNB',
    'LALAMOVE : 253 QUIRINO HIGHWAY, QUEZON CITY',
    'INQ26-20992',
    NULL,
    NULL,
    '',
    '',
    '',
    'PHILIP ARCILLA @09078198211',
    'vip 1',
    0,
    'General',
    0,
    0,
    NULL,
    0.00000000000012,
    'Inclusive',
    0,
    'N/A',
    NULL,
    'null'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM tblinquiry
    WHERE lrefno = '261105110654938120384066'
);

INSERT INTO tblinquiry_item (
    linq_no,
    linq_refno,
    litem_id,
    litem_refno,
    lqty,
    lprice,
    litem_code,
    lpartno,
    lbrand,
    ldesc,
    lcategory,
    lapplication,
    llocation,
    lremark,
    linquiry_date,
    is_custom_item,
    mark_as_send,
    lapproved,
    ledited_price,
    lsize,
    lpo_no,
    lrr_no,
    lpr_no,
    lsono,
    lsorefno,
    lreport_remark
)
SELECT
    'INQ26-20992',
    '261105110654938120384066',
    '35131835920200807142128',
    '35131835920200807142128',
    1,
    540,
    'QK2-006',
    'P-P300',
    'ISHINOMOTO',
    'PLUNGER',
    NULL,
    NULL,
    '',
    'OnStock',
    '2026-11-05',
    0,
    1,
    1,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL
FROM DUAL
WHERE EXISTS (
    SELECT 1
    FROM tblinquiry
    WHERE lrefno = '261105110654938120384066'
      AND linqno = 'INQ26-20992'
)
  AND NOT EXISTS (
    SELECT 1
    FROM tblinquiry_item
    WHERE linq_refno = '261105110654938120384066'
);

INSERT INTO tbltransaction (
    lsale_source,
    lsaleno,
    ldate,
    ltime,
    lcustomerid,
    lmain_id,
    lpromo_discount,
    luser,
    lpayment_status,
    lrefno,
    ltopup,
    ltemprecid,
    lbranch,
    lt_lfname,
    lt_llname,
    ltransaction_status,
    lshipped,
    lsubmitstat,
    lvat,
    lreturn,
    lcompany,
    linquiry_refno,
    linquiry_no,
    invoice_refno,
    invoice_no,
    lterms,
    lterm_condition,
    ldr_refno,
    ldr_no,
    lnote_status,
    lnote,
    llocation,
    lcancel,
    ldel_to,
    lmy_refno,
    lpo_no,
    lprod_type,
    lsales_address,
    ltax_type,
    lprocess_type,
    limported,
    lamount,
    lsales_person,
    lsales_person_id,
    ldiscount_type,
    ldiscount_val,
    ldiscount_amt,
    IsInquiry,
    lsaved,
    ltotalAmount,
    lupdated,
    ltracking_no,
    lyour_refno,
    lprice_group,
    lcredit_limit,
    lpromissory_note,
    lcustomer_remark,
    IsPrinted,
    lcancel_reason,
    ltransaction_type,
    lvat_percent,
    llabel_ins,
    llabel_w,
    llabel_box,
    lurgency,
    lurgency_date,
    lreupdate,
    llast_refno,
    lcity
)
SELECT
    NULL,
    'NSO-49187',
    '2026-11-05',
    '23:05:00',
    '102021072911493995101',
    '1',
    0,
    '1',
    'Unpaid',
    '2026110523071040306',
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    'Posted',
    '',
    'Posted',
    NULL,
    0,
    'FV ARCILLA CALIBRATION CENTER',
    '261105110654938120384066',
    'INQ26-20992',
    '2026110523072233840',
    'T-12933',
    'AP/TT-PNB',
    'AP/TT-PNB',
    NULL,
    NULL,
    NULL,
    '',
    NULL,
    0,
    NULL,
    'INQ26-20992',
    '',
    NULL,
    'LALAMOVE : 253 QUIRINO HIGHWAY, QUEZON CITY',
    NULL,
    NULL,
    1,
    0,
    'test',
    '92',
    NULL,
    NULL,
    NULL,
    0,
    0,
    0,
    0,
    NULL,
    'PHILIP ARCILLA @09078198211',
    'vip 1',
    0,
    '',
    NULL,
    0,
    NULL,
    NULL,
    NULL,
    NULL,
    0,
    1,
    'N/A',
    NULL,
    '0',
    NULL,
    ''
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM tbltransaction
    WHERE lrefno = '2026110523071040306'
);

INSERT INTO tbltransaction_item (
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
    ldiscount_amount,
    ltemprecid,
    lvariant_main,
    lvariant_sub,
    lvariant_id,
    lunit,
    ldatetime,
    linv_refno,
    litem_refno,
    litemcode,
    lpartno,
    lbrand,
    llocation,
    lremark,
    lcategory,
    ltransaction_date,
    lcancel,
    ltax_type,
    mark_as_send,
    ltax_amt,
    lvat_percent,
    ledited_price
)
SELECT
    '2026110523071040306',
    'Sales Order',
    '35131835920200807142128',
    'PLUNGER',
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
    NULL,
    FROM_UNIXTIME(1793891230),
    '35131835920200807142128',
    '35131835920200807142128',
    'QK2-006',
    'P-P300',
    'ISHINOMOTO',
    '',
    'OnStock',
    NULL,
    '2026-11-05',
    0,
    NULL,
    0,
    NULL,
    NULL,
    NULL
FROM DUAL
WHERE EXISTS (
    SELECT 1
    FROM tbltransaction
    WHERE lrefno = '2026110523071040306'
      AND lsaleno = 'NSO-49187'
)
  AND NOT EXISTS (
    SELECT 1
    FROM tbltransaction_item
    WHERE lrefno = '2026110523071040306'
      AND ltype = 'Sales Order'
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
    'sales_inquiry',
    '261105110654938120384066',
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
    FROM tblinquiry
    WHERE lrefno = '261105110654938120384066'
)
  AND NOT EXISTS (
    SELECT 1
    FROM tblvip_document_discount
    WHERE ldocument_type = 'sales_inquiry'
      AND ldocument_refno = '261105110654938120384066'
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
    'sales_order',
    '2026110523071040306',
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
    FROM tbltransaction
    WHERE lrefno = '2026110523071040306'
      AND lsaleno = 'NSO-49187'
)
  AND NOT EXISTS (
    SELECT 1
    FROM tblvip_document_discount
    WHERE ldocument_type = 'sales_order'
      AND ldocument_refno = '2026110523071040306'
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
    'Sales Inquiry',
    'Create',
    FROM_UNIXTIME(1793891214),
    '261105110654938120384066',
    NULL,
    NULL,
    NULL
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM tblaudit_trail
    WHERE lpage = 'Sales Inquiry'
      AND laction = 'Create'
      AND lrefno = '261105110654938120384066'
);
