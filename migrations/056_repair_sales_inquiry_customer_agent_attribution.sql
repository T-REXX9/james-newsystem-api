-- Sales Person is the selected customer's assigned sales agent. The luser field
-- remains the inquiry creator and is used by the UI as Prepared By.
UPDATE tblinquiry AS inquiry
INNER JOIN tblpatient AS customer
    ON customer.lmain_id = inquiry.lmain_id
   AND customer.lsessionid = inquiry.lcustomerid
LEFT JOIN tblaccount AS agent
    -- lSales_person is legacy text and can be blank or a stale display name.
    -- Compare as text so strict MySQL mode never tries to coerce those values.
    ON CAST(agent.lid AS CHAR) = TRIM(COALESCE(customer.lsales_person, ''))
SET
    inquiry.lsales_person_id = CAST(customer.lsales_person AS CHAR),
    inquiry.lsalesperson = TRIM(CONCAT(COALESCE(agent.lfname, ''), ' ', COALESCE(agent.llname, '')))
WHERE COALESCE(inquiry.lsales_person_id, '') <> COALESCE(CAST(customer.lsales_person AS CHAR), '')
   OR TRIM(COALESCE(inquiry.lsalesperson, '')) <> TRIM(CONCAT(COALESCE(agent.lfname, ''), ' ', COALESCE(agent.llname, '')));

-- Linked Sales Orders mirror the Sales Inquiry header, so correct the same
-- attribution there without changing the creator stored on either document.
UPDATE tbltransaction AS sales_order
INNER JOIN tblinquiry AS inquiry
    ON inquiry.lmain_id = sales_order.lmain_id
   AND inquiry.lrefno = sales_order.linquiry_refno
INNER JOIN tblpatient AS customer
    ON customer.lmain_id = inquiry.lmain_id
   AND customer.lsessionid = inquiry.lcustomerid
LEFT JOIN tblaccount AS agent
    ON CAST(agent.lid AS CHAR) = TRIM(COALESCE(customer.lsales_person, ''))
SET
    sales_order.lsales_person_id = CAST(customer.lsales_person AS CHAR),
    sales_order.lsales_person = TRIM(CONCAT(COALESCE(agent.lfname, ''), ' ', COALESCE(agent.llname, '')))
WHERE COALESCE(sales_order.lsales_person_id, '') <> COALESCE(CAST(customer.lsales_person AS CHAR), '')
   OR TRIM(COALESCE(sales_order.lsales_person, '')) <> TRIM(CONCAT(COALESCE(agent.lfname, ''), ' ', COALESCE(agent.llname, '')));
