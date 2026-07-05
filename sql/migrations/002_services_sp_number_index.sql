-- Speeds up “all services for this provider” (optional if you already re-imported full schema.sql after idx_services_sp was added).
ALTER TABLE services ADD INDEX idx_services_sp (sp_number);
