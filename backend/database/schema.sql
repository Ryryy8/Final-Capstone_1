-- ===================================================
-- AssessPro Database Schema
-- Municipal Property Assessment System
-- Updated: November 29, 2025
-- ===================================================

USE assesspro_db;

-- ===================================================
-- Core User Management Tables
-- ===================================================

-- Users table for system authentication and role management
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff', 'head') NOT NULL DEFAULT 'staff',
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    last_login TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_status (status)
);

-- Activity logs for audit trail
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50) DEFAULT NULL,
    record_id INT DEFAULT NULL,
    old_values JSON DEFAULT NULL,
    new_values JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_table_name (table_name),
    INDEX idx_created_at (created_at),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ===================================================
-- Assessment Request Management
-- ===================================================

-- Main assessment requests table
CREATE TABLE IF NOT EXISTS assessment_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    inspection_category VARCHAR(100) NOT NULL COMMENT 'Building, Machinery, or Land Property',
    requested_inspection_date DATE DEFAULT NULL COMMENT 'System assigned date (clients view calendar only)',
    property_classification VARCHAR(50) DEFAULT NULL COMMENT 'Required only for Land Property category',
    location TEXT NOT NULL,
    landmark VARCHAR(255) DEFAULT NULL,
    land_reference_arp VARCHAR(100) DEFAULT NULL,
    contact_person VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    purpose TEXT NOT NULL COMMENT 'Client purpose and preferred date mentioned in text',
    valid_id_data LONGBLOB DEFAULT NULL,
    valid_id_type VARCHAR(50) DEFAULT NULL,
    valid_id_name VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'scheduled', 'in_progress', 'completed', 'cancelled', 'declined') DEFAULT 'pending',
    assigned_staff_id INT DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_status (status),
    INDEX idx_inspection_category (inspection_category),
    INDEX idx_requested_date (requested_inspection_date),
    INDEX idx_assigned_staff (assigned_staff_id),
    INDEX idx_created_at (created_at),
    INDEX idx_email (email),
    INDEX idx_category_date (inspection_category, requested_inspection_date),
    INDEX idx_location_category (location(100), inspection_category),
    
    FOREIGN KEY (assigned_staff_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ===================================================
-- Inspection Scheduling System
-- ===================================================

-- Scheduled inspections for barangay-based grouping
CREATE TABLE IF NOT EXISTS scheduled_inspections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    barangay VARCHAR(100) NOT NULL,
    inspection_date DATE NOT NULL,
    request_count INT DEFAULT 1,
    notes TEXT DEFAULT NULL,
    status ENUM('scheduled', 'completed', 'cancelled') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_barangay (barangay),
    INDEX idx_inspection_date (inspection_date),
    INDEX idx_status (status)
);

-- Archived inspections for completed/cancelled items
CREATE TABLE IF NOT EXISTS archived_inspections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    original_id VARCHAR(50) NOT NULL,
    source_table VARCHAR(50) NOT NULL,
    inspection_data JSON NOT NULL,
    archived_by INT DEFAULT NULL,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_original_id (original_id),
    INDEX idx_source_table (source_table),
    INDEX idx_archived_at (archived_at),
    
    FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ===================================================
-- Calendar and Holiday System
-- ===================================================

-- System holidays for calendar blocking
CREATE TABLE IF NOT EXISTS holidays (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    date DATE NOT NULL,
    is_recurring BOOLEAN DEFAULT FALSE,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_date (date),
    INDEX idx_recurring (is_recurring)
);

-- Blocked dates for manual date blocking by staff/admin
CREATE TABLE IF NOT EXISTS blocked_dates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date DATE NOT NULL UNIQUE,
    reason TEXT NOT NULL,
    created_by VARCHAR(100) DEFAULT 'Staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_date (date),
    INDEX idx_created_by (created_by),
    INDEX idx_created_at (created_at)
);

-- ===================================================
-- Communication System
-- ===================================================

-- System-wide announcements
CREATE TABLE IF NOT EXISTS announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    category VARCHAR(50) DEFAULT 'general',
    expiry_date DATE DEFAULT NULL,
    target_all BOOLEAN DEFAULT TRUE,
    target_staff BOOLEAN DEFAULT FALSE,
    target_admins BOOLEAN DEFAULT FALSE,
    author_id VARCHAR(50) DEFAULT NULL,
    author_name VARCHAR(100) DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    view_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_priority (priority),
    INDEX idx_category (category),
    INDEX idx_expiry_date (expiry_date),
    INDEX idx_is_active (is_active),
    INDEX idx_created_at (created_at)
);

-- Announcement read tracking
CREATE TABLE IF NOT EXISTS announcement_reads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    announcement_id INT NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_user_announcement (user_id, announcement_id),
    INDEX idx_user_id (user_id),
    INDEX idx_announcement_id (announcement_id),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE
);

-- ===================================================
-- Security and Anti-Spam System
-- ===================================================

-- Rate limiting for request protection
CREATE TABLE IF NOT EXISTS request_rate_limits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_identifier VARCHAR(255) NOT NULL,
    request_type VARCHAR(100) NOT NULL,
    request_count INT DEFAULT 1,
    first_request_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_request_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_blocked BOOLEAN DEFAULT FALSE,
    blocked_until DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_client_type (client_identifier, request_type),
    INDEX idx_blocked (is_blocked),
    INDEX idx_time (last_request_time)
);

-- Duplicate request detection
CREATE TABLE IF NOT EXISTS duplicate_request_checks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_identifier VARCHAR(255) NOT NULL,
    request_hash VARCHAR(255) NOT NULL,
    request_data_hash VARCHAR(255) NOT NULL,
    attempt_count INT DEFAULT 1,
    first_attempt DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_attempt DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_flagged BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_client_hash (client_identifier, request_hash),
    INDEX idx_flagged (is_flagged),
    INDEX idx_time (last_attempt)
);

-- Security violation tracking
CREATE TABLE IF NOT EXISTS security_violations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_identifier VARCHAR(255) NOT NULL,
    violation_type ENUM('RATE_LIMIT', 'DUPLICATE_SPAM', 'SUSPICIOUS_ACTIVITY', 'BLOCKED_REQUEST') NOT NULL,
    severity ENUM('LOW', 'MEDIUM', 'HIGH', 'CRITICAL') NOT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_client (client_identifier),
    INDEX idx_violation_type (violation_type),
    INDEX idx_severity (severity),
    INDEX idx_time (created_at)
);

-- ===================================================
-- Sample Data Initialization
-- ===================================================

-- Default system holidays (Philippine holidays)
INSERT IGNORE INTO holidays (name, date, is_recurring) VALUES
('New Year\'s Day', '2025-01-01', 1),
('People Power Anniversary', '2025-02-25', 1),
('Maundy Thursday', '2025-04-17', 0),
('Good Friday', '2025-04-18', 0),
('Araw ng Kagitingan', '2025-04-09', 1),
('Labor Day', '2025-05-01', 1),
('Independence Day', '2025-06-12', 1),
('National Heroes Day', '2025-08-25', 0),
('Bonifacio Day', '2025-11-30', 1),
('Immaculate Conception', '2025-12-08', 1),
('Rizal Day', '2025-12-30', 1),
('Christmas Day', '2025-12-25', 1),
('New Year\'s Eve', '2025-12-31', 1);

-- ===================================================
-- Schema Information and Metadata
-- ===================================================

-- Schema version tracking (optional)
CREATE TABLE IF NOT EXISTS schema_info (
    version VARCHAR(20) PRIMARY KEY,
    description TEXT,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO schema_info (version, description) VALUES
('1.0.0', 'Initial AssessPro database schema with all core tables'),
('1.1.0', 'Updated assessment_requests for new calendar functionality'),
('1.2.0', 'Added security and anti-spam protection tables'),
('1.3.0', 'Added batch scheduling and archived inspections support'),
('1.4.0', 'Enhanced performance with optimized indexes and views - November 29, 2025');

-- ===================================================
-- Performance Optimization Views (Optional)
-- ===================================================

-- View for active assessment requests with staff information
CREATE OR REPLACE VIEW active_assessment_requests AS
SELECT 
    ar.id,
    ar.name,
    ar.email,
    ar.inspection_category,
    ar.requested_inspection_date,
    ar.property_classification,
    ar.location,
    ar.status,
    ar.created_at,
    CONCAT(u.first_name, ' ', u.last_name) as assigned_staff_name,
    u.username as staff_username
FROM assessment_requests ar
LEFT JOIN users u ON ar.assigned_staff_id = u.id
WHERE ar.status != 'completed' AND ar.status != 'cancelled';

-- View for system statistics
CREATE OR REPLACE VIEW system_statistics AS
SELECT 
    (SELECT COUNT(*) FROM assessment_requests) as total_requests,
    (SELECT COUNT(*) FROM assessment_requests WHERE status = 'pending') as pending_requests,
    (SELECT COUNT(*) FROM assessment_requests WHERE status = 'completed') as completed_requests,
    (SELECT COUNT(*) FROM users WHERE status = 'active') as active_users,
    (SELECT COUNT(*) FROM users WHERE role = 'staff' AND status = 'active') as active_staff,
    (SELECT COUNT(*) FROM scheduled_inspections WHERE status = 'scheduled') as scheduled_inspections,
    (SELECT COUNT(*) FROM announcements WHERE is_active = 1) as active_announcements;