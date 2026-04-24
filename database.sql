-- =========================================================
-- TB5 Monitoring System — Database Schema
-- Database: monitoring_system
-- =========================================================

CREATE DATABASE IF NOT EXISTS `monitoring_system`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `monitoring_system`;

-- ---------------------------------------------------------
-- systems (Big Five = 1, Big Blossom = 2)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `systems` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `logo_filename` VARCHAR(150) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `systems` (`id`, `name`, `logo_filename`) VALUES
  (1, 'The Big Five Monitoring System', 'bigfive_logo.png'),
  (2, 'Big Blossom Monitoring System',  'bigblossom_logo.png');

-- ---------------------------------------------------------
-- users
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(80)  NOT NULL,
  `email`         VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('admin') NOT NULL DEFAULT 'admin',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin: admin@example.com / Admin@1234
INSERT INTO `users` (`username`, `email`, `password_hash`, `role`) VALUES
  ('Admin', 'admin@example.com',
   '$2y$12$pBqDaF.4kB5QzXnJ/vPZMuOq.FQA1nTn1PY9kHgn0lVmOJJ7UNFvq',
   'admin');

-- ---------------------------------------------------------
-- otp_codes
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_codes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(150) NOT NULL,
  `otp_code`   CHAR(6)      NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `is_used`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- categories
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_id`  INT UNSIGNED NOT NULL,
  `name`       VARCHAR(150) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`system_id`) REFERENCES `systems`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categories` (`system_id`, `name`) VALUES
  (1, 'Cheque'),
  (1, 'Others'),
  (1, 'Appointment / RWAC / Tools'),
  (2, 'Cheque'),
  (2, 'Others');

-- ---------------------------------------------------------
-- document_types
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_types` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_id`  INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `qualification_id` INT UNSIGNED NULL,
  `name`       VARCHAR(150) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`system_id`) REFERENCES `systems`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `document_types` (`system_id`, `name`) VALUES
  (1, 'Batch 51401 / Assessment Billing'),
  (1, 'MTP CTPR TB5'),
  (1, 'Batch 0 / Assessment Billing'),
  (1, 'Employment Report Batch 12 - 13'),
  (1, 'Employment Report Batch 51 - 64'),
  (2, 'Batch 51401 / Assessment Billing'),
  (2, 'MTP CTPR TB5');

-- ---------------------------------------------------------
-- document_subs (sub-documents linked to document_types)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_subs` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_id`        INT UNSIGNED NOT NULL,
  `document_type_id` INT UNSIGNED NOT NULL,
  `name`             VARCHAR(150) NOT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`system_id`)        REFERENCES `systems`(`id`)        ON DELETE CASCADE,
  FOREIGN KEY (`document_type_id`) REFERENCES `document_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- add FK from document_types.qualification_id -> qualifications.id (if both exist already when applying ALTERs at runtime, migrations needed)
ALTER TABLE `document_types`
  ADD CONSTRAINT IF NOT EXISTS `fk_document_types_qualification` FOREIGN KEY (`qualification_id`) REFERENCES `qualifications`(`id`) ON DELETE SET NULL;

-- ---------------------------------------------------------
-- qualifications
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `qualifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_id`  INT UNSIGNED NOT NULL,
  `name`       VARCHAR(150) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`system_id`) REFERENCES `systems`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `qualifications` (`system_id`, `name`) VALUES
  (1, 'BPP'),
  (1, 'CSS'),
  (1, 'EPAS'),
  (2, 'BPP'),
  (2, 'CSS');

-- ---------------------------------------------------------
-- documents
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `documents` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `system_id`        INT UNSIGNED  NOT NULL,
  `category_id`      INT UNSIGNED  NULL,
  `document_type_id` INT UNSIGNED  NULL,
  `document_sub`     VARCHAR(100)  NULL,
  `qualification_id` INT UNSIGNED  NULL,
  `date_submission`  DATE          NULL,
  `batch_no`         VARCHAR(100)  NULL,
  `remarks`          TEXT          NULL,
  `received_tesda`   DATE          NULL,
  `returned_center`  DATE          NULL,
  `staff_received`   VARCHAR(150)  NULL,
  `date_assessment`  DATE          NULL,
  `assessor_name`    VARCHAR(150)  NULL,
  `tesda_released`   DATE          NULL,
  `image_path`       TEXT          NULL,
  `is_archived`      TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`system_id`)        REFERENCES `systems`(`id`)        ON DELETE CASCADE,
  FOREIGN KEY (`category_id`)      REFERENCES `categories`(`id`)     ON DELETE SET NULL,
  FOREIGN KEY (`document_type_id`) REFERENCES `document_types`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`qualification_id`) REFERENCES `qualifications`(`id`) ON DELETE SET NULL,
  INDEX `idx_system_archived` (`system_id`, `is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
