-- Migration 004: add file metadata columns to import_log
-- Captures what was actually downloaded so integrity can be verified later.

ALTER TABLE import_log
    ADD COLUMN source_url       VARCHAR(500)  NULL AFTER status,
    ADD COLUMN file_size_bytes  INT UNSIGNED  NULL AFTER source_url,
    ADD COLUMN file_hash_md5    VARCHAR(32)   NULL AFTER file_size_bytes,
    ADD COLUMN services_total   INT UNSIGNED  NULL AFTER file_hash_md5,
    ADD COLUMN services_active  INT UNSIGNED  NULL AFTER services_total;
