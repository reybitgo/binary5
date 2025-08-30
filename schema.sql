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
    pv INT NOT NULL COMMENT 'point value for bonus calculation',
    daily_max INT NOT NULL DEFAULT 10,
    pair_rate DECIMAL(5,4) NOT NULL DEFAULT 0.2000,
    referral_rate DECIMAL(5,4) NOT NULL DEFAULT 0.1000
);

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

-- Leadership schedule tables (per package & level)
CREATE TABLE package_leadership_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    level TINYINT NOT NULL,
    pvt_required INT NOT NULL,
    gvt_required INT NOT NULL,
    rate DECIMAL(5,4) NOT NULL,
    FOREIGN KEY(package_id) REFERENCES packages(id) ON DELETE CASCADE,
    UNIQUE(package_id, level)
);

CREATE TABLE package_mentor_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    level TINYINT NOT NULL,
    pvt_required INT NOT NULL,
    gvt_required INT NOT NULL,
    rate DECIMAL(5,4) NOT NULL,
    FOREIGN KEY(package_id) REFERENCES packages(id) ON DELETE CASCADE,
    UNIQUE(package_id, level)
);

-- Insert package data with different settings for each package
INSERT INTO packages (name, price, pv, daily_max, pair_rate, referral_rate) VALUES 
    ('Starter', 25.00, 25, 5, 0.1500, 0.0800),
    ('Pro', 50.00, 50, 10, 0.2000, 0.1000),
    ('Elite', 100.00, 100, 20, 0.2500, 0.1200);

-- Leadership schedule for Starter package (ID: 1) - Lower requirements, lower rates
INSERT INTO package_leadership_schedule (package_id, level, pvt_required, gvt_required, rate) VALUES
    (1, 1, 50, 250, 0.030),    -- Level 1: 50 PVT, 250 GVT, 3.0% rate
    (1, 2, 100, 500, 0.025),   -- Level 2: 100 PVT, 500 GVT, 2.5% rate
    (1, 3, 200, 1000, 0.020),  -- Level 3: 200 PVT, 1000 GVT, 2.0% rate
    (1, 4, 300, 2000, 0.015),  -- Level 4: 300 PVT, 2000 GVT, 1.5% rate
    (1, 5, 500, 3000, 0.010);  -- Level 5: 500 PVT, 3000 GVT, 1.0% rate

-- Leadership schedule for Pro package (ID: 2) - Medium requirements, medium rates
INSERT INTO package_leadership_schedule (package_id, level, pvt_required, gvt_required, rate) VALUES
    (2, 1, 100, 500, 0.050),   -- Level 1: 100 PVT, 500 GVT, 5.0% rate
    (2, 2, 200, 1000, 0.040),  -- Level 2: 200 PVT, 1000 GVT, 4.0% rate
    (2, 3, 300, 2500, 0.030),  -- Level 3: 300 PVT, 2500 GVT, 3.0% rate
    (2, 4, 500, 5000, 0.020),  -- Level 4: 500 PVT, 5000 GVT, 2.0% rate
    (2, 5, 1000, 10000, 0.010); -- Level 5: 1000 PVT, 10000 GVT, 1.0% rate

-- Leadership schedule for Elite package (ID: 3) - Higher requirements, higher rates
INSERT INTO package_leadership_schedule (package_id, level, pvt_required, gvt_required, rate) VALUES
    (3, 1, 200, 1000, 0.070),  -- Level 1: 200 PVT, 1000 GVT, 7.0% rate
    (3, 2, 400, 2000, 0.060),  -- Level 2: 400 PVT, 2000 GVT, 6.0% rate
    (3, 3, 600, 5000, 0.050),  -- Level 3: 600 PVT, 5000 GVT, 5.0% rate
    (3, 4, 1000, 10000, 0.040), -- Level 4: 1000 PVT, 10000 GVT, 4.0% rate
    (3, 5, 2000, 20000, 0.030); -- Level 5: 2000 PVT, 20000 GVT, 3.0% rate

-- Mentor schedule for Starter package (ID: 1) - Lower requirements, lower rates
INSERT INTO package_mentor_schedule (package_id, level, pvt_required, gvt_required, rate) VALUES
    (1, 1, 25, 150, 0.020),    -- Level 1: 25 PVT, 150 GVT, 2.0% rate
    (1, 2, 50, 300, 0.018),    -- Level 2: 50 PVT, 300 GVT, 1.8% rate
    (1, 3, 100, 600, 0.015),   -- Level 3: 100 PVT, 600 GVT, 1.5% rate
    (1, 4, 200, 1200, 0.012),  -- Level 4: 200 PVT, 1200 GVT, 1.2% rate
    (1, 5, 300, 2000, 0.010);  -- Level 5: 300 PVT, 2000 GVT, 1.0% rate

-- Mentor schedule for Pro package (ID: 2) - Medium requirements, medium rates
INSERT INTO package_mentor_schedule (package_id, level, pvt_required, gvt_required, rate) VALUES
    (2, 1, 100, 500, 0.030),   -- Level 1: 100 PVT, 500 GVT, 3.0% rate
    (2, 2, 200, 1000, 0.025),  -- Level 2: 200 PVT, 1000 GVT, 2.5% rate
    (2, 3, 300, 2500, 0.020),  -- Level 3: 300 PVT, 2500 GVT, 2.0% rate
    (2, 4, 500, 5000, 0.015),  -- Level 4: 500 PVT, 5000 GVT, 1.5% rate
    (2, 5, 1000, 10000, 0.010); -- Level 5: 1000 PVT, 10000 GVT, 1.0% rate

-- Mentor schedule for Elite package (ID: 3) - Higher requirements, higher rates
INSERT INTO package_mentor_schedule (package_id, level, pvt_required, gvt_required, rate) VALUES
    (3, 1, 150, 800, 0.040),   -- Level 1: 150 PVT, 800 GVT, 4.0% rate
    (3, 2, 300, 1500, 0.035),  -- Level 2: 300 PVT, 1500 GVT, 3.5% rate
    (3, 3, 500, 3000, 0.030),  -- Level 3: 500 PVT, 3000 GVT, 3.0% rate
    (3, 4, 800, 6000, 0.025),  -- Level 4: 800 PVT, 6000 GVT, 2.5% rate
    (3, 5, 1500, 12000, 0.020); -- Level 5: 1500 PVT, 12000 GVT, 2.0% rate

    -- Run this SQL to fix the missing column
ALTER TABLE wallet_tx ADD COLUMN package_id INT NULL AFTER user_id;

-- Add foreign key constraint
ALTER TABLE wallet_tx 
ADD CONSTRAINT fk_wallet_tx_package 
FOREIGN KEY (package_id) REFERENCES packages(id) 
ON DELETE SET NULL;