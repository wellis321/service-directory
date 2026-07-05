-- LEGACY SCHEMA — prefer sql/schema.sql + cron/import.php for new installs.
-- This file matches the older flat PHP pages (root index.php, import/run.php).
-- CareScotland Directory — MySQL Schema
-- Run once to set up your database
-- Compatible with MySQL 5.7+ and MariaDB 10.3+

CREATE DATABASE IF NOT EXISTS carescotland CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE carescotland;

-- -------------------------------------------------------
-- Core service data (populated from Care Inspectorate CSV)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS services (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Identifiers from CSV
    cs_number       VARCHAR(20)  NOT NULL UNIQUE,   -- e.g. CS2003000123
    sp_number       VARCHAR(20),                    -- Service provider number

    -- Service details
    service_name    VARCHAR(255) NOT NULL,
    care_service    VARCHAR(100),                   -- e.g. "Care Home Service"
    subtype         VARCHAR(100),
    service_type    VARCHAR(100),                   -- e.g. "Voluntary or Not for Profit"
    service_status  VARCHAR(50)  DEFAULT 'Active',

    -- Address
    address_1       VARCHAR(150),
    address_2       VARCHAR(150),
    address_3       VARCHAR(150),
    address_4       VARCHAR(150),
    town            VARCHAR(100),
    postcode        VARCHAR(10),
    uprn            VARCHAR(20),

    -- Contact
    manager_name    VARCHAR(150),
    phone           VARCHAR(30),
    email           VARCHAR(150),

    -- Provider
    provider_name   VARCHAR(255),
    local_authority_run TINYINT(1) DEFAULT 0,

    -- Geography
    council_area    VARCHAR(100),
    health_board    VARCHAR(100),
    integration_authority VARCHAR(100),
    simd_rank       INT,
    simd_decile     TINYINT,
    datazone        VARCHAR(20),

    -- Capacity
    total_beds      SMALLINT,
    single_bedrooms SMALLINT,
    beds_double     SMALLINT,
    beds_shared     SMALLINT,
    registered_places SMALLINT,
    num_staff       SMALLINT,
    client_group    VARCHAR(255),

    -- Inspection grades (1-6 scale)
    grade_wellbeing     TINYINT,
    grade_planning      TINYINT,
    grade_setting       TINYINT,
    grade_staff         TINYINT,
    grade_leadership    TINYINT,
    grade_care_play     TINYINT,   -- Care, play & learning (childcare only)
    grade_min           TINYINT,
    grade_max           TINYINT,
    grade_spread        VARCHAR(20),
    latest_grade_date   DATE,

    -- Complaints summary
    complaints_upheld   SMALLINT DEFAULT 0,
    complaints_total    SMALLINT DEFAULT 0,

    -- Registration
    date_registered     DATE,

    -- Timestamps
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_import_at  TIMESTAMP NULL,

    -- Full-text search index
    FULLTEXT idx_ft (service_name, provider_name, town, care_service, client_group),

    INDEX idx_care_service (care_service),
    INDEX idx_council (council_area),
    INDEX idx_postcode (postcode),
    INDEX idx_status (service_status),
    INDEX idx_grade_min (grade_min),
    INDEX idx_cs_number (cs_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------
-- Provider accounts (care services that have claimed listing)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS providers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cs_number       VARCHAR(20) NOT NULL,           -- links to services.cs_number
    service_id      INT UNSIGNED,

    -- Account
    contact_name    VARCHAR(150) NOT NULL,
    contact_email   VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    verified        TINYINT(1) DEFAULT 0,
    verify_token    VARCHAR(64),

    -- Enhanced listing fields
    description     TEXT,
    tagline         VARCHAR(255),
    website         VARCHAR(255),
    photo_1         VARCHAR(255),
    photo_2         VARCHAR(255),
    photo_3         VARCHAR(255),
    vacancy_status  ENUM('available','limited','full','unknown') DEFAULT 'unknown',
    weekly_fee_from DECIMAL(8,2),
    weekly_fee_to   DECIMAL(8,2),
    features        TEXT,               -- JSON array of features/highlights

    -- Billing (Stripe)
    plan            ENUM('free','basic','premium') DEFAULT 'free',
    stripe_customer_id VARCHAR(100),
    stripe_sub_id   VARCHAR(100),
    plan_expires_at DATE,

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
    INDEX idx_cs (cs_number),
    INDEX idx_email (contact_email),
    INDEX idx_plan (plan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------
-- Enquiries / leads (families contacting providers)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS enquiries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id      INT UNSIGNED NOT NULL,
    provider_id     INT UNSIGNED,

    -- Enquirer details
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(150) NOT NULL,
    phone           VARCHAR(30),
    message         TEXT,
    care_needed_for ENUM('self','relative','client','other') DEFAULT 'relative',

    -- Metadata
    ip_address      VARCHAR(45),
    referrer        VARCHAR(500),
    status          ENUM('new','read','replied','closed') DEFAULT 'new',

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (service_id) REFERENCES services(id),
    FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
    INDEX idx_service (service_id),
    INDEX idx_provider (provider_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------
-- Import log (track monthly CSV imports)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    started_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at     TIMESTAMP NULL,
    status          ENUM('running','success','failed') DEFAULT 'running',
    rows_processed  INT DEFAULT 0,
    rows_inserted   INT DEFAULT 0,
    rows_updated    INT DEFAULT 0,
    rows_cancelled  INT DEFAULT 0,
    error_message   TEXT,
    source_url      VARCHAR(500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------
-- Admin users
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    name            VARCHAR(150),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert a default admin (password: changeme123 — change immediately!)
INSERT IGNORE INTO admins (email, password_hash, name)
VALUES ('admin@carescotland.com', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMlJMs3OEs9.O9TBzpFRFqmNne', 'Admin');
