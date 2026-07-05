-- Complaint detail rows scraped per-service from the Care Inspectorate.
-- Populated lazily when service profiles are viewed; grows over time.
-- aggregate counts (complaints_upheld / complaints_not_upheld) remain on services.

CREATE TABLE IF NOT EXISTS complaints (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cs_number      VARCHAR(20)  NOT NULL,
    sp_number      VARCHAR(20),
    case_number    VARCHAR(100) NOT NULL DEFAULT '',
    complaint_date DATE,
    category       VARCHAR(500),
    fetched_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_case  (cs_number, case_number),
    INDEX idx_cs        (cs_number),
    INDEX idx_sp        (sp_number),
    INDEX idx_date      (complaint_date),
    FULLTEXT INDEX idx_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
