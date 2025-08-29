-- schema.sql
-- Active: 1755594857749@@127.0.0.1@3306@binary5_db
DROP DATABASE IF EXISTS binary5_db;

CREATE DATABASE IF NOT EXISTS binary5_db;

USE binary5_db;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    password VARCHAR(255),
    sponsor_name VARCHAR(50),
    upline_id INT DEFAULT NULL,
    position ENUM('left', 'right') DEFAULT NULL,
    left_count INT DEFAULT 0,
    right_count INT DEFAULT 0,
    pairs_today INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (upline_id) REFERENCES users (id) ON DELETE SET NULL
);

CREATE TABLE wallets (
    user_id INT PRIMARY KEY,
    balance DECIMAL(12, 2) DEFAULT 0.00,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE wallet_tx (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    type ENUM(
        'topup',
        'withdraw',
        'transfer_in',
        'transfer_out',
        'package',
        'pair_bonus'
    ),
    amount DECIMAL(12, 2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50),
    price DECIMAL(12, 2),
    pv INT /* point value for bonus calculation */
);

CREATE TABLE flushes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    amount DECIMAL(12, 2),
    flushed_on DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

INSERT INTO
    packages (name, price, pv)
VALUES ('Starter', 25.00, 25),
    ('Pro', 50.00, 50),
    ('Elite', 100.00, 100);

ALTER TABLE wallet_tx
MODIFY COLUMN type ENUM(
    'topup',
    'withdraw',
    'transfer_in',
    'transfer_out',
    'package',
    'pair_bonus',
    'referral_bonus',
    'leadership_bonus',
    'leadership_reverse_bonus'
);

ALTER TABLE flushes
ADD COLUMN reason ENUM(
    'binary_overflow',
    'leadership_requirements_not_met'
) NOT NULL DEFAULT 'binary_overflow' AFTER amount;

ALTER TABLE flushes
MODIFY COLUMN reason ENUM(
    'binary_overflow',
    'leadership_requirements_not_met',
    'mentor_requirements_not_met'
) DEFAULT 'binary_overflow';

CREATE TABLE leadership_flush_log (
    ancestor_id INT NOT NULL,
    downline_id INT NOT NULL,
    level TINYINT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    flushed_on DATE NOT NULL,
    PRIMARY KEY (
        ancestor_id,
        downline_id,
        level,
        flushed_on
    )
);

CREATE TABLE IF NOT EXISTS mentor_flush_log (
    ancestor_id INT NOT NULL,
    descendant_id INT NOT NULL,
    level TINYINT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    flushed_on DATE NOT NULL,
    PRIMARY KEY (
        ancestor_id,
        descendant_id,
        level,
        flushed_on
    )
);

ALTER TABLE users ADD role ENUM('user', 'admin') DEFAULT 'user';

CREATE TABLE ewallet_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('topup', 'withdraw') NOT NULL,
    usdt_amount DECIMAL(12, 2) NOT NULL,
    b2p_amount DECIMAL(12, 2) NOT NULL,
    tx_hash VARCHAR(70) NULL,
    status ENUM(
        'pending',
        'approved',
        'rejected'
    ) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id)
);

ALTER TABLE wallet_tx
MODIFY COLUMN type ENUM(
    'topup',
    'withdraw',
    'transfer_in',
    'transfer_out',
    'package',
    'pair_bonus',
    'referral_bonus',
    'leadership_bonus',
    'leadership_reverse_bonus',
    'withdraw_hold',
    'withdraw_reject'
);

ALTER TABLE ewallet_requests
ADD COLUMN wallet_address VARCHAR(255) NULL AFTER tx_hash;

-- NEW
ALTER TABLE users
  DROP COLUMN sponsor_name,
  ADD COLUMN sponsor_id INT DEFAULT NULL,
  ADD FOREIGN KEY (sponsor_id) REFERENCES users(id) ON DELETE SET NULL;

  -- Rate limiting table
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255),
    attempt_time DATETIME,
    INDEX idx_identifier (identifier),
    INDEX idx_attempt_time (attempt_time)
);

-- Remember me tokens
CREATE TABLE remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    token_hash VARCHAR(64),
    expires_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token_hash),
    INDEX idx_expires (expires_at)
);

-- Login logs for security auditing
CREATE TABLE login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    status ENUM('success', 'failed'),
    attempted_username VARCHAR(255) NULL,
    created_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);

-- Add columns to users table if not exists
DELIMITER //

CREATE PROCEDURE AddColumnsIfNotExists()
BEGIN
    -- Add 'status' column if it doesn't exist
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = 'users'
        AND TABLE_SCHEMA = 'binary5_db'
        AND COLUMN_NAME = 'status'
    ) THEN
        ALTER TABLE users
        ADD COLUMN status ENUM('active', 'inactive', 'suspended') DEFAULT 'active';
    END IF;

    -- Add 'last_login' column if it doesn't exist
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = 'users'
        AND TABLE_SCHEMA = 'binary5_db'
        AND COLUMN_NAME = 'last_login'
    ) THEN
        ALTER TABLE users
        ADD COLUMN last_login DATETIME NULL;
    END IF;

    -- Add 'failed_attempts' column if it doesn't exist
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = 'users'
        AND TABLE_SCHEMA = 'binary5_db'
        AND COLUMN_NAME = 'failed_attempts'
    ) THEN
        ALTER TABLE users
        ADD COLUMN failed_attempts INT DEFAULT 0;
    END IF;

    -- Add 'last_ip' column if it doesn't exist
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = 'users'
        AND TABLE_SCHEMA = 'binary5_db'
        AND COLUMN_NAME = 'last_ip'
    ) THEN
        ALTER TABLE users
        ADD COLUMN last_ip VARCHAR(45) NULL;
    END IF;

    -- Add 'email' column if it doesn't exist
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = 'users'
        AND TABLE_SCHEMA = 'binary5_db'
        AND COLUMN_NAME = 'email'
    ) THEN
        ALTER TABLE users
        ADD COLUMN email VARCHAR(255) UNIQUE NULL;
    END IF;

    -- Add 'email_verified' column if it doesn't exist
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = 'users'
        AND TABLE_SCHEMA = 'binary5_db'
        AND COLUMN_NAME = 'email_verified'
    ) THEN
        ALTER TABLE users
        ADD COLUMN email_verified BOOLEAN DEFAULT FALSE;
    END IF;
END //

DELIMITER ;

-- Execute the stored procedure
CALL AddColumnsIfNotExists();

-- Optionally, drop the procedure after use
DROP PROCEDURE IF EXISTS AddColumnsIfNotExists;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token_hash),
    INDEX idx_expires (expires_at)
);