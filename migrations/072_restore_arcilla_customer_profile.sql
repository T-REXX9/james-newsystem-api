-- Restore Arcilla Calibration Center Co. from final-v1.sql without another
-- full dump import. The customer and contact inserts are skipped when the
-- corresponding profile already exists.
--
-- The deleted test row is removed only when all reviewed identity/status
-- fields still match. The transaction makes the replacement atomic.
START TRANSACTION;

DELETE FROM `tblpatient`
WHERE `lid` = 1451
  AND `lmain_id` = 1
  AND `lsessionid` = '132026090916012727631'
  AND LOWER(TRIM(COALESCE(`lcompany`, ''))) = 'test'
  AND COALESCE(`ldeleted`, 0) = 1
  AND COALESCE(`lstatus`, 1) = 0;

SET @arcilla_profile_inserted := 0;
INSERT INTO `tblpatient` (
    `lid`,
    `lmain_id`,
    `lencoded_by`,
    `ltitle`,
    `lfname`,
    `lmname`,
    `llname`,
    `lgender`,
    `lbday`,
    `lage`,
    `lcivilstatus`,
    `lphone`,
    `lmobile`,
    `lfax`,
    `lecommerce`,
    `lusername`,
    `lemail`,
    `lpassword`,
    `lfb`,
    `loccupation`,
    `lhobbies`,
    `lstatus`,
    `lsessionid`,
    `lzipcode`,
    `lcity`,
    `lprovince`,
    `lcountry`,
    `lremarks`,
    `ldatereg`,
    `ldatetime`,
    `lpatient_today`,
    `lhmo`,
    `lwork`,
    `lcompany`,
    `lsports`,
    `lbilling_city`,
    `lbilling_country`,
    `lbilling_postal_code`,
    `lcompanyadd`,
    `lcompanycity`,
    `lcompanyprov`,
    `limg`,
    `laddress`,
    `lcard`,
    `ltempid`,
    `ltemprecid`,
    `linquire`,
    `lbranch`,
    `lcard_activate`,
    `lcard_expired`,
    `lreferal_no`,
    `lcredit`,
    `ldsp`,
    `lpatient_code`,
    `lvat`,
    `lvat_type`,
    `lterms`,
    `lsales_person`,
    `ltin`,
    `lprice_group`,
    `lclass_code`,
    `lck_stat`,
    `lsince`,
    `lbusiness_line`,
    `lcolor_code`,
    `llast_transaction`,
    `larea`,
    `lgroup`,
    `lrefer_by`,
    `lactive`,
    `ldiscount`,
    `lmark_store`,
    `ltransaction_type`,
    `ldelivery_address`,
    `ldealer_since`,
    `ldealer_quota`,
    `lposition`,
    `lvat_percent`,
    `lnotes`,
    `ldealer_terms`,
    `ldebt_type`,
    `lprofile_type`,
    `lverification`,
    `ldate_assigned`
)
SELECT
    1451,
    1,
    1,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    '1970-01-01',
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
    NULL,
    NULL,
    1,
    '152026092805131331',
    NULL,
    '',
    '',
    NULL,
    'New Patient',
    '2026/09/28 17:16:51',
    '2026-09-28 17:16:51',
    '2026-09-28',
    NULL,
    NULL,
    'ARCILLA CALIBRATION CENTER CO.',
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    'LALAMOVE : #346 QUIRINO HIGHWAY, SANGANDAAN DISTRICT6, QUEZON CITY.',
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    0,
    NULL,
    NULL,
    NULL,
    '',
    'AP/TT-PNB',
    '',
    '',
    'VIP 1',
    NULL,
    NULL,
    '2026-09-29',
    '',
    NULL,
    '2026-10-07',
    '',
    NULL,
    '',
    NULL,
    0,
    0,
    'Order Slip',
    'LALAMOVE : #346 QUIRINO HIGHWAY, SANGANDAAN DISTRICT6, QUEZON CITY.',
    '1970-01-01',
    0,
    NULL,
    0.000012,
    'THEY ARE THE NEPHEW OF FV ARCILLA CALIBRATION CENTER C/0 : PHILIP ARCILLA  /    COUSIN OF LGR CALIBRATION C/O :  GENELYN RINGOR ',
    '',
    '',
    'Old',
    'Verified',
    NULL
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM `tblpatient` existing
    WHERE existing.`lmain_id` = 1
      AND (
          existing.`lsessionid` = '152026092805131331'
          OR LOWER(TRIM(COALESCE(existing.`lcompany`, ''))) = LOWER(TRIM('ARCILLA CALIBRATION CENTER CO.'))
      )
)
  AND NOT EXISTS (
      SELECT 1 FROM `tblpatient` occupied WHERE occupied.`lid` = 1451
  );
SET @arcilla_profile_inserted := ROW_COUNT();

-- Restore the related contact from the dump with a fresh local row ID. Its
-- legacy lid is already occupied by another contact in the target database.
INSERT INTO `tblcontact_person` (
    `lmainid`,
    `lrefno`,
    `lfname`,
    `lmname`,
    `llname`,
    `lgender`,
    `lbday`,
    `laddress`,
    `lc_mobile`,
    `lc_phone`,
    `lemail`,
    `lposition`,
    `lsessionid`
)
SELECT
    '1',
    '152026092805131331',
    'OLIVER T. ARCILLA @0919082340',
    NULL,
    NULL,
    NULL,
    '1970-01-01',
    NULL,
    '0919082340',
    '',
    '',
    '',
    '26092805191767964347'
FROM DUAL
WHERE @arcilla_profile_inserted = 1
  AND NOT EXISTS (
      SELECT 1 FROM `tblcontact_person` existing
      WHERE existing.`lmainid` = 1
        AND existing.`lrefno` = '152026092805131331'
        AND existing.`lsessionid` = '26092805191767964347'
  );

-- Keep the known creation date available to customer creation-date reports.
INSERT IGNORE INTO `tblpatient_created_at` (`lmain_id`, `lsessionid`, `created_at`)
SELECT 1, '152026092805131331', '2026-09-28 17:16:51'
FROM DUAL
WHERE @arcilla_profile_inserted = 1;

COMMIT;
