-- =============================================================
-- CareScotland Directory — MySQL Schema
-- =============================================================
-- Run once on your hosting MySQL instance:
--   mysql -u youruser -p yourdatabase < schema.sql
-- =============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -------------------------------------------------------------
-- 1. SERVICES
--    Populated/refreshed monthly from the Care Inspectorate CSV.
--    cs_number is the official unique ID (e.g. CS2003000001).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS services (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cs_number        VARCHAR(20)  NOT NULL UNIQUE,  -- official CI identifier
    care_service     VARCHAR(100),                  -- e.g. "Care Home Service"
    subtype          VARCHAR(100),
    additional_subtypes TEXT,
    service_type     VARCHAR(100),
    service_name     VARCHAR(255) NOT NULL,
    address_1        VARCHAR(100),
    address_2        VARCHAR(100),
    address_3        VARCHAR(100),
    address_4        VARCHAR(100),
    town             VARCHAR(100),
    postcode         VARCHAR(10),
    phone            VARCHAR(30),
    email            VARCHAR(150),
    manager_name     VARCHAR(150),
    sp_number        VARCHAR(20),                   -- service provider ID
    provider_name    VARCHAR(255),
    provided_by_la   TINYINT(1) DEFAULT 0,          -- 1 = local authority
    service_status   VARCHAR(30),                   -- Active, Cancelled, etc.
    date_registered  DATE,
    simd_rank        INT,
    simd_decile      TINYINT,
    datazone         VARCHAR(20),
    integration_auth VARCHAR(150),
    council_area     VARCHAR(100),
    health_board     VARCHAR(100),
    total_beds       SMALLINT,
    single_bedrooms  SMALLINT,
    double_beds      SMALLINT,
    beds_3plus       SMALLINT,
    registered_places SMALLINT,
    num_staff        SMALLINT,
    client_group     TEXT,
    care_home_main_area VARCHAR(150),
    care_home_areas  TEXT,
    public_list      TINYINT(1) DEFAULT 1,
    -- Inspection grades (1-6, NULL if not yet graded)
    grade_wellbeing  TINYINT,
    grade_planning   TINYINT,
    grade_setting    TINYINT,
    grade_staff      TINYINT,
    grade_leadership TINYINT,
    grade_cpl        TINYINT,   -- Care, Play & Learning (childcare services)
    grade_min        TINYINT,
    grade_max        TINYINT,
    grade_spread     VARCHAR(10),
    grade_published  DATE,
    grade_year_month VARCHAR(10),
    -- Satisfaction score (relatives/service users survey)
    rad_sat_score    DECIMAL(4,2),
    -- Complaints
    complaints_upheld   SMALLINT DEFAULT 0,
    complaints_not_upheld SMALLINT DEFAULT 0,
    -- Timestamps
    ci_last_updated  DATE,                          -- date of the CSV snapshot
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes for common search/filter patterns
    INDEX idx_postcode    (postcode),
    INDEX idx_council     (council_area),
    INDEX idx_service_type (care_service),
    INDEX idx_grade_min   (grade_min),
    INDEX idx_grade_published (grade_published),
    INDEX idx_status      (service_status),
    INDEX idx_services_sp (sp_number),
    FULLTEXT INDEX idx_search (service_name, town, provider_name, client_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
-- 2. PROVIDERS
--    A provider is the organisation/person that claimed one or
--    more service listings on our directory.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS providers (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sp_number    VARCHAR(20),                       -- links back to services.sp_number
    company_name VARCHAR(255) NOT NULL,
    contact_name VARCHAR(150),
    email        VARCHAR(150) NOT NULL UNIQUE,
    phone        VARCHAR(30),
    password_hash VARCHAR(255) NOT NULL,
    email_verified TINYINT(1) DEFAULT 0,
    verify_token VARCHAR(64),
    reset_token  VARCHAR(64),
    reset_expires DATETIME,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login   TIMESTAMP NULL,
    INDEX idx_sp (sp_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
-- 3. LISTING_TIERS
--    Tracks which tier each claimed service is on, and the
--    Stripe subscription that backs the paid tiers.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS listing_tiers (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id        INT UNSIGNED NOT NULL,
    provider_id       INT UNSIGNED NOT NULL,
    tier              ENUM('free','premium','pro') DEFAULT 'free',
    -- Enhanced profile fields (editable by provider)
    tagline           VARCHAR(255),
    description       TEXT,
    photo_urls        TEXT,                         -- JSON array of image paths
    vacancy_count     SMALLINT,
    vacancy_updated   DATE,
    weekly_fee_from   DECIMAL(8,2),
    weekly_fee_to     DECIMAL(8,2),
    website_url       VARCHAR(255),
    enquiry_email     VARCHAR(150),
    -- Stripe
    stripe_customer_id      VARCHAR(100),
    stripe_subscription_id  VARCHAR(100),
    stripe_price_id         VARCHAR(100),
    subscription_status     VARCHAR(30),           -- active, past_due, cancelled
    subscription_start      DATE,
    subscription_end        DATE,
    -- Moderation
    approved         TINYINT(1) DEFAULT 0,
    claimed_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_service (service_id),
    FOREIGN KEY (service_id)   REFERENCES services(id)  ON DELETE CASCADE,
    FOREIGN KEY (provider_id)  REFERENCES providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
-- 4. ENQUIRIES
--    Sent via the enquiry form on a service profile page.
--    Lead is forwarded to the provider's enquiry_email.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS enquiries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id  INT UNSIGNED NOT NULL,
    sender_name VARCHAR(150) NOT NULL,
    sender_email VARCHAR(150) NOT NULL,
    sender_phone VARCHAR(30),
    message     TEXT NOT NULL,
    care_start  DATE,
    care_type   VARCHAR(100),
    ip_address  VARCHAR(45),
    sent_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    emailed     TINYINT(1) DEFAULT 0,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    INDEX idx_service (service_id),
    INDEX idx_sent   (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
-- 5. IMPORT_LOG
--    Track each monthly import run for debugging & audit.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS import_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    started_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at  TIMESTAMP NULL,
    csv_date     DATE,                              -- date of the CI snapshot
    rows_parsed  INT DEFAULT 0,
    rows_inserted INT DEFAULT 0,
    rows_updated  INT DEFAULT 0,
    rows_skipped  INT DEFAULT 0,
    status       ENUM('running','complete','failed') DEFAULT 'running',
    notes        TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -------------------------------------------------------------
-- 6. PROVIDER_NEWS
--    News articles fetched daily from Google News RSS per
--    service provider (keyed by sp_number).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `provider_news` (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sp_number    VARCHAR(20)  NOT NULL,
    title        VARCHAR(500) NOT NULL,
    url          TEXT         NOT NULL,
    url_hash     CHAR(32)     NOT NULL,           -- MD5(url) for dedup
    source_name  VARCHAR(255) DEFAULT NULL,
    snippet      TEXT         DEFAULT NULL,
    published_at DATETIME     DEFAULT NULL,
    fetched_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status       ENUM('shown','hidden') NOT NULL DEFAULT 'shown',
    UNIQUE KEY uq_url_hash (url_hash),
    KEY idx_sp_status_pub (sp_number, status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
