-- schema.sql
-- Fixed version with resolved SQL errors

DROP DATABASE IF EXISTS binary5_db;

CREATE DATABASE IF NOT EXISTS binary5_db;

USE binary5_db;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    sponsor_id INT DEFAULT NULL,
    upline_id INT DEFAULT NULL,
    position ENUM('left', 'right') DEFAULT NULL,
    left_count INT DEFAULT 0,
    right_count INT DEFAULT 0,
    pairs_today INT DEFAULT 0,
    role ENUM('user', 'admin') DEFAULT 'user',
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    last_login DATETIME NULL,
    failed_attempts INT DEFAULT 0,
    last_ip VARCHAR(45) NULL,
    email VARCHAR(255) UNIQUE NULL,
    email_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sponsor (sponsor_id),
    INDEX idx_upline (upline_id)
);

-- Add foreign key constraints after table creation to avoid circular reference issues
ALTER TABLE users 
    ADD CONSTRAINT fk_users_sponsor FOREIGN KEY (sponsor_id) REFERENCES users (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_users_upline FOREIGN KEY (upline_id) REFERENCES users (id) ON DELETE SET NULL;

-- Wallets table
CREATE TABLE wallets (
    user_id INT PRIMARY KEY,
    balance DECIMAL(12, 2) DEFAULT 0.00,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

-- Wallet transactions table
CREATE TABLE wallet_tx (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM(
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
    ) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

-- Packages table
CREATE TABLE packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    price DECIMAL(12, 2) NOT NULL,
    pv INT NOT NULL COMMENT 'point value for bonus calculation'
);

-- Insert package data
INSERT INTO packages (name, price, pv) VALUES 
    ('Starter', 25.00, 25),
    ('Pro', 50.00, 50),
    ('Elite', 100.00, 100);

-- Flushes table
CREATE TABLE flushes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    reason ENUM(
        'binary_overflow',
        'leadership_requirements_not_met',
        'mentor_requirements_not_met'
    ) NOT NULL DEFAULT 'binary_overflow',
    flushed_on DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

-- Leadership flush log table
CREATE TABLE leadership_flush_log (
    ancestor_id INT NOT NULL,
    downline_id INT NOT NULL,
    level TINYINT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    flushed_on DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ancestor_id, downline_id, level, flushed_on),
    FOREIGN KEY (ancestor_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (downline_id) REFERENCES users (id) ON DELETE CASCADE
);

-- Mentor flush log table
CREATE TABLE mentor_flush_log (
    ancestor_id INT NOT NULL,
    descendant_id INT NOT NULL,
    level TINYINT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    flushed_on DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ancestor_id, descendant_id, level, flushed_on),
    FOREIGN KEY (ancestor_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (descendant_id) REFERENCES users (id) ON DELETE CASCADE
);

-- E-wallet requests table
CREATE TABLE ewallet_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('topup', 'withdraw') NOT NULL,
    usdt_amount DECIMAL(12, 2) NOT NULL,
    b2p_amount DECIMAL(12, 2) NOT NULL,
    tx_hash VARCHAR(70) NULL,
    wallet_address VARCHAR(255) NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

-- Login attempts table (for rate limiting)
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    attempt_time DATETIME NOT NULL,
    INDEX idx_identifier (identifier),
    INDEX idx_attempt_time (attempt_time)
);

-- Remember me tokens table
CREATE TABLE remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_token (token_hash),
    INDEX idx_expires (expires_at)
);

-- Login logs table
CREATE TABLE login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    status ENUM('success', 'failed') NOT NULL,
    attempted_username VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);

-- Password resets table
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_token (token_hash),
    INDEX idx_expires (expires_at)
);