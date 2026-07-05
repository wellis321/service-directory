-- Add outcome column to complaints detail table.
-- Values from CI: 'Upheld', 'Not Upheld', 'Partially Upheld', 'Withdrawn', 'Resolved', etc.

ALTER TABLE complaints
    ADD COLUMN outcome VARCHAR(80) DEFAULT NULL AFTER category;
