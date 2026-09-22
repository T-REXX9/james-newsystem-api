-- Move legacy customer notes into the unified Agent Sales Report conversation.
-- Each migrated note is authored by Old System because its original author is
-- not reliably available in the legacy data. Safe to run multiple times.

INSERT INTO call_report_threads (
    main_id,
    contact_id,
    is_direct,
    call_log_entry_id,
    call_log_refno,
    agent_user_id,
    agent_name,
    outcome,
    report_body,
    created_at
)
SELECT
    p.lmain_id,
    p.lsessionid,
    1,
    NULL,
    CONCAT('legacy-staff-comment:', p.lid),
    0,
    'Old System',
    'note',
    '',
    CASE
        WHEN TRIM(CAST(p.ldatetime AS CHAR)) IN ('', '0000-00-00 00:00:00') THEN NOW()
        ELSE p.ldatetime
    END
FROM tblpatient p
WHERE COALESCE(p.ldeleted, 0) = 0
  AND TRIM(COALESCE(p.lsessionid, '')) <> ''
  AND TRIM(COALESCE(p.lnotes, '')) <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM call_report_threads existing_thread
      WHERE existing_thread.main_id = p.lmain_id
        AND existing_thread.call_log_refno = CONCAT('legacy-staff-comment:', p.lid)
  );

INSERT INTO call_report_messages (
    thread_id,
    sender_user_id,
    sender_name,
    sender_role,
    body,
    created_at
)
SELECT
    legacy_thread.id,
    0,
    'Old System',
    'agent',
    p.lnotes,
    CASE
        WHEN TRIM(CAST(p.ldatetime AS CHAR)) IN ('', '0000-00-00 00:00:00') THEN NOW()
        ELSE p.ldatetime
    END
FROM tblpatient p
INNER JOIN call_report_threads legacy_thread
    ON legacy_thread.main_id = p.lmain_id
    AND legacy_thread.call_log_refno = CONCAT('legacy-staff-comment:', p.lid)
WHERE COALESCE(p.ldeleted, 0) = 0
  AND TRIM(COALESCE(p.lsessionid, '')) <> ''
  AND TRIM(COALESCE(p.lnotes, '')) <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM call_report_messages existing_message
      WHERE existing_message.thread_id = legacy_thread.id
        AND existing_message.sender_user_id = 0
        AND existing_message.sender_name = 'Old System'
        AND existing_message.sender_role = 'agent'
        AND existing_message.body = p.lnotes
  );
