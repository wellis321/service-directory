-- Run once if tables were created with latin1/swedish_ci (fixes SQLSTATE 1366 on import).
-- In phpMyAdmin: select your database, then SQL tab, paste and run.
-- Skip the ALTER DATABASE line on hosts that forbid it; the ALTER TABLE lines are the important part.

ALTER DATABASE `service-directory` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE services       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE providers      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE listing_tiers  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE enquiries      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE import_log     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
