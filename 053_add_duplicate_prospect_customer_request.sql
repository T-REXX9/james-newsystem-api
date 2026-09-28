-- A duplicate prospect submitted by staff is held in the existing customer
-- approval queue until a Master User decides on it.
ALTER TABLE customer_requests
  MODIFY kind ENUM('customer_update', 'discount', 'duplicate_prospect') NOT NULL;
