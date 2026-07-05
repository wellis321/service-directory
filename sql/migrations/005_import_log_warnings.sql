-- Migration 005: allow 'complete_with_warnings' status in import_log
ALTER TABLE import_log
    MODIFY COLUMN status ENUM('running','complete','complete_with_warnings','failed') DEFAULT 'running';
