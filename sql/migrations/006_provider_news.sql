-- Migration 006: provider_news table
-- Stores news articles fetched from Google News RSS per care provider.
-- Run: mysql -u user -p database < sql/migrations/006_provider_news.sql

CREATE TABLE IF NOT EXISTS `provider_news` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sp_number`    VARCHAR(20)  NOT NULL,
  `title`        VARCHAR(500) NOT NULL,
  `url`          TEXT         NOT NULL,
  `url_hash`     CHAR(32)     NOT NULL,          -- MD5(url) for dedup
  `source_name`  VARCHAR(255) DEFAULT NULL,
  `snippet`      TEXT         DEFAULT NULL,
  `published_at` DATETIME     DEFAULT NULL,
  `fetched_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status`       ENUM('shown','hidden') NOT NULL DEFAULT 'shown',
  UNIQUE KEY `uq_url_hash` (`url_hash`),
  KEY `idx_sp_status_pub` (`sp_number`, `status`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
