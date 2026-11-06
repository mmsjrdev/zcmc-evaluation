-- ============================================================================
-- ZCMC Evaluation Form System (Form ZCMC-F-PROC-29)
-- database/schema.sql  -  Step 1: Foundation
--
-- HOW TO USE
--   1. In phpMyAdmin, create an EMPTY database named zcmc-evaluation
--      (on InfinityFree, use the database they created for you).
--   2. Click that database, open the Import tab, choose this file, click Import.
--
-- Run this file ONLY on an empty database (it has no DROP statements, so it
-- will not erase anything, but running it twice would fail or duplicate the
-- seed rows).
--
-- Whenever a later Step changes the tables, this file is updated AND you get
-- an ALTER statement to paste into phpMyAdmin.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- departments: hospital departments that can evaluate forms
-- ----------------------------------------------------------------------------
CREATE TABLE `departments` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`   VARCHAR(150) NOT NULL,
  `active` TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- users: everyone who can log in (suppliers, staff, evaluators, heads, admin)
-- ----------------------------------------------------------------------------
CREATE TABLE `users` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`                VARCHAR(190) NOT NULL,
  `password_hash`        VARCHAR(255) NOT NULL,
  `full_name`            VARCHAR(150) NOT NULL,
  `employee_id`          VARCHAR(50)  NULL,
  `department_id`        INT UNSIGNED NULL,
  `position`             VARCHAR(100) NOT NULL DEFAULT '',
  `contact_no`           VARCHAR(30)  NOT NULL DEFAULT '',
  `business_name`        VARCHAR(190) NULL,          -- suppliers only
  `role`                 ENUM('supplier','mm_staff','head_nurse','evaluator','head','admin') NOT NULL,
  `status`               ENUM('active','pending','disabled','rejected') NOT NULL DEFAULT 'active',
  `requested_role`       ENUM('supplier','mm_staff','head_nurse','evaluator','head','admin') NULL, -- only a request, never trusted
  `must_change_password` TINYINT(1)   NOT NULL DEFAULT 0,
  `saved_signature`      MEDIUMTEXT   NULL,          -- PNG data URL the user chose to keep
  `created_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_status` (`role`, `status`),
  KEY `idx_users_department` (`department_id`),
  CONSTRAINT `fk_users_department`
    FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- alternate_verifiers: a head who may also verify for another department
-- ----------------------------------------------------------------------------
CREATE TABLE `alternate_verifiers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` INT UNSIGNED NOT NULL,
  `head_user_id`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alt_verifier_pair` (`department_id`, `head_user_id`),
  KEY `idx_alt_verifier_head` (`head_user_id`),
  CONSTRAINT `fk_alt_verifier_department`
    FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_alt_verifier_head`
    FOREIGN KEY (`head_user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- procurement_modes: the "Mode of Procurement" choices on the form
-- ----------------------------------------------------------------------------
CREATE TABLE `procurement_modes` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`   VARCHAR(150) NOT NULL,
  `active` TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- evaluations: one row per evaluation form
-- Supplier-owned: bid_ref_no, mode_id, opening_date, header_item_no, bidder, eval_date
--                 (and the items' specifications / bidder_spec)
-- Evaluator-owned: failed_quality, b_*, others_checked, c_*
--                 (and the items' finding / grounds)
-- ----------------------------------------------------------------------------
CREATE TABLE `evaluations` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref_no`          VARCHAR(30)  NULL,                -- stays the same across versions
  `supplier_id`     INT UNSIGNED NOT NULL,
  `bid_ref_no`      VARCHAR(100) NOT NULL DEFAULT '',
  `mode_id`         INT UNSIGNED NULL,
  `opening_date`    DATE         NULL,
  `header_item_no`  VARCHAR(50)  NULL,
  `bidder`          VARCHAR(190) NULL,
  `eval_date`       DATE         NULL,
  `status`          ENUM('supplier_draft','submitted','with_head_nurse','pending_evaluation',
                         'pending_head','pending_conforme','completed','received',
                         'returned_supplier','returned_evaluator','cancelled')
                    NOT NULL DEFAULT 'supplier_draft',
  `version`         INT UNSIGNED NOT NULL DEFAULT 1,  -- goes up when a signed form is returned
  `supplier_hash`   CHAR(64)     NULL,                -- SHA-256 of supplier fields at submit
  `submitted_at`    DATETIME     NULL,
  `department_id`   INT UNSIGNED NULL,
  `evaluator_id`    INT UNSIGNED NULL,
  `distributed_by`  INT UNSIGNED NULL,
  `distributed_at`  DATETIME     NULL,
  `failed_quality`  TINYINT(1)   NOT NULL DEFAULT 0,
  `b_params`        TEXT         NULL,
  `b_details`       TEXT         NULL,
  `others_checked`  TINYINT(1)   NOT NULL DEFAULT 0,
  `c_params`        TEXT         NULL,
  `c_details`       TEXT         NULL,
  `conforme_method` ENUM('device','paper') NULL,
  `paper_scan_file` VARCHAR(255) NULL,                -- file name inside storage/uploads/
  `form_no`         VARCHAR(30)  NOT NULL DEFAULT 'ZCMC-F-PROC-29',
  `revision_no`     VARCHAR(10)  NOT NULL DEFAULT '0',
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_evaluations_ref_no` (`ref_no`),
  KEY `idx_evaluations_supplier` (`supplier_id`),
  KEY `idx_evaluations_status` (`status`),
  KEY `idx_evaluations_department_status` (`department_id`, `status`),
  KEY `idx_evaluations_evaluator` (`evaluator_id`),
  KEY `idx_evaluations_mode` (`mode_id`),
  KEY `idx_evaluations_distributed_by` (`distributed_by`),
  CONSTRAINT `fk_evaluations_supplier`
    FOREIGN KEY (`supplier_id`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_evaluations_mode`
    FOREIGN KEY (`mode_id`) REFERENCES `procurement_modes` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_evaluations_department`
    FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_evaluations_evaluator`
    FOREIGN KEY (`evaluator_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_evaluations_distributed_by`
    FOREIGN KEY (`distributed_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- evaluation_items: the table rows on the form (one row per item)
-- ----------------------------------------------------------------------------
CREATE TABLE `evaluation_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evaluation_id` INT UNSIGNED NOT NULL,
  `item_no`       VARCHAR(20)  NOT NULL DEFAULT '',
  `specifications` TEXT        NULL,                  -- supplier-owned
  `bidder_spec`   TEXT         NULL,                  -- supplier-owned
  `finding`       ENUM('responsive','non_responsive') NULL,  -- evaluator-owned
  `grounds`       TEXT         NULL,                  -- evaluator-owned
  `sort_order`    INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_items_evaluation_order` (`evaluation_id`, `sort_order`),
  CONSTRAINT `fk_items_evaluation`
    FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- status_history: every status change, who did it, and why
-- ----------------------------------------------------------------------------
CREATE TABLE `status_history` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evaluation_id` INT UNSIGNED NOT NULL,
  `from_status`   VARCHAR(30)  NULL,
  `to_status`     VARCHAR(30)  NOT NULL,
  `actor_id`      INT UNSIGNED NULL,
  `remarks`       TEXT         NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_history_evaluation` (`evaluation_id`, `created_at`),
  KEY `idx_history_actor` (`actor_id`),
  CONSTRAINT `fk_history_evaluation`
    FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_history_actor`
    FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- signatures: INSERT-ONLY. The code never runs UPDATE or DELETE on this table.
-- A signature counts only when its version equals evaluations.version.
-- Foreign keys use RESTRICT so a signed form or its signer cannot be deleted.
-- ----------------------------------------------------------------------------
CREATE TABLE `signatures` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evaluation_id` INT UNSIGNED NOT NULL,
  `version`       INT UNSIGNED NOT NULL,
  `role`          ENUM('evaluator','head','conforme') NOT NULL,
  `user_id`       INT UNSIGNED NULL,
  `typed_name`    VARCHAR(150) NOT NULL,
  `position`      VARCHAR(100) NOT NULL DEFAULT '',
  `business_name` VARCHAR(190) NOT NULL DEFAULT '',
  `signature_png` MEDIUMTEXT   NOT NULL,              -- PNG data URL drawn on the canvas
  `content_hash`  CHAR(64)     NOT NULL,              -- SHA-256 of the form content when signed
  `signed_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address`    VARCHAR(45)  NOT NULL DEFAULT '',
  `user_agent`    VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_signatures_one_per_role_version` (`evaluation_id`, `version`, `role`),
  KEY `idx_signatures_user` (`user_id`),
  CONSTRAINT `fk_signatures_evaluation`
    FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_signatures_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- returns: every time a form is sent back, with the remarks
-- ----------------------------------------------------------------------------
CREATE TABLE `returns` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evaluation_id` INT UNSIGNED NOT NULL,
  `version`       INT UNSIGNED NOT NULL,
  `from_status`   VARCHAR(30)  NOT NULL,
  `to_status`     VARCHAR(30)  NOT NULL,
  `returned_by`   INT UNSIGNED NULL,
  `remarks`       TEXT         NOT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_returns_evaluation` (`evaluation_id`, `created_at`),
  KEY `idx_returns_user` (`returned_by`),
  CONSTRAINT `fk_returns_evaluation`
    FOREIGN KEY (`evaluation_id`) REFERENCES `evaluations` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_returns_user`
    FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- series_counters: last used number per month (ym = year+month, e.g. 2611)
-- ----------------------------------------------------------------------------
CREATE TABLE `series_counters` (
  `ym`      CHAR(4)      NOT NULL,
  `last_no` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`ym`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- notifications: in-app messages (PHP mail() is disabled on InfinityFree)
-- ----------------------------------------------------------------------------
CREATE TABLE `notifications` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `message`    VARCHAR(255) NOT NULL,
  `link`       VARCHAR(255) NULL,
  `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_read` (`user_id`, `is_read`, `created_at`),
  CONSTRAINT `fk_notifications_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- audit_log: who did what, when, from which IP
-- ----------------------------------------------------------------------------
CREATE TABLE `audit_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NULL,
  `action`     VARCHAR(80)  NOT NULL,
  `details`    TEXT         NULL,
  `ip_address` VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- login_attempts: used to slow down password guessing
-- (no foreign key on purpose: it also records emails that do not exist)
-- ----------------------------------------------------------------------------
CREATE TABLE `login_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(190) NOT NULL,
  `ip_address`   VARCHAR(45)  NOT NULL DEFAULT '',
  `attempted_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_email_time` (`email`, `attempted_at`),
  KEY `idx_login_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SEED DATA
-- ============================================================================

-- The 10 modes of procurement
INSERT INTO `procurement_modes` (`name`) VALUES
  ('Competitive Bidding'),
  ('Small Value Procurement'),
  ('Direct Contracting'),
  ('Direct Acquisition'),
  ('Emergency Cases'),
  ('Repeat Order'),
  ('Two-Failed Biddings'),
  ('Agency-to-Agency'),
  ('Direct Retail Purchase of POL Products, Electronic Charging Devices and Online Subscriptions'),
  ('Direct Sales');

-- 4 sample departments (edit or add your real ones later from the admin pages)
INSERT INTO `departments` (`name`) VALUES
  ('Pharmacy'),
  ('Laboratory'),
  ('Radiology'),
  ('Nursing Service');
