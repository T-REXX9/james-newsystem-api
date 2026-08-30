-- Additive extension of incident_reports.issue_type.
-- Existing rows remain valid; only the ENUM membership is widened.

ALTER TABLE incident_reports
    MODIFY COLUMN issue_type ENUM(
        'product_quality',
        'service_quality',
        'delivery',
        'other',
        'lbc_rto'
    ) NOT NULL;
