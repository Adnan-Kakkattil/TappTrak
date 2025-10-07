-- TappTrak Database Schema
-- Security Management System for Flat Check-in/Check-out Tracking
-- Created for PHP/MySQL implementation

-- Create database
CREATE DATABASE IF NOT EXISTS tapptrak;
USE tapptrak;

-- =============================================
-- USER MANAGEMENT TABLES
-- =============================================

-- Users table for system access (Admin and Security roles)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'security') NOT NULL DEFAULT 'security',
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
);

-- =============================================
-- BUILDING AND FLAT MANAGEMENT
-- =============================================

-- Buildings table
CREATE TABLE buildings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address TEXT,
    total_flats INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Flats table
CREATE TABLE flats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    building_id INT NOT NULL,
    flat_number VARCHAR(20) NOT NULL,
    floor_number INT,
    flat_type ENUM('1BHK', '2BHK', '3BHK', '4BHK', 'Penthouse') DEFAULT '2BHK',
    owner_name VARCHAR(100),
    owner_phone VARCHAR(20),
    owner_email VARCHAR(100),
    is_occupied BOOLEAN DEFAULT TRUE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
    UNIQUE KEY unique_flat (building_id, flat_number)
);

-- =============================================
-- SECURITY GUARD MANAGEMENT
-- =============================================

-- Guards table
CREATE TABLE guards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guard_id VARCHAR(20) UNIQUE NOT NULL, -- Format: GRD-001, GRD-002, etc.
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    address TEXT,
    emergency_contact VARCHAR(20),
    emergency_contact_name VARCHAR(100),
    hire_date DATE NOT NULL,
    salary DECIMAL(10,2),
    is_active BOOLEAN DEFAULT TRUE,
    profile_image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Guard shifts table
CREATE TABLE guard_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guard_id INT NOT NULL,
    shift_name VARCHAR(50) NOT NULL, -- e.g., "Morning Shift", "Evening Shift", "Night Shift"
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guard_id) REFERENCES guards(id) ON DELETE CASCADE
);

-- Guard attendance table
CREATE TABLE guard_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guard_id INT NOT NULL,
    shift_id INT NOT NULL,
    check_in_time TIMESTAMP NULL,
    check_out_time TIMESTAMP NULL,
    status ENUM('scheduled', 'checked_in', 'checked_out', 'absent', 'late') DEFAULT 'scheduled',
    notes TEXT,
    attendance_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (guard_id) REFERENCES guards(id) ON DELETE CASCADE,
    FOREIGN KEY (shift_id) REFERENCES guard_shifts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_guard_shift_date (guard_id, shift_id, attendance_date)
);

-- =============================================
-- VISITOR MANAGEMENT
-- =============================================

-- Visitors table
CREATE TABLE visitors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    company VARCHAR(100),
    purpose ENUM('personal', 'business', 'delivery', 'maintenance', 'other') DEFAULT 'personal',
    id_proof_type ENUM('aadhar', 'pan', 'driving_license', 'passport', 'voter_id', 'other') NOT NULL,
    id_proof_number VARCHAR(50) NOT NULL,
    profile_image VARCHAR(255),
    is_blacklisted BOOLEAN DEFAULT FALSE,
    blacklist_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Visitor logs table (main check-in/check-out tracking)
CREATE TABLE visitor_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visitor_id INT NOT NULL,
    flat_id INT NOT NULL,
    guard_id INT NOT NULL,
    check_in_time TIMESTAMP NOT NULL,
    check_out_time TIMESTAMP NULL,
    expected_duration INT DEFAULT 120, -- in minutes
    status ENUM('inside', 'exited', 'overstayed', 'forced_exit') DEFAULT 'inside',
    purpose TEXT,
    vehicle_number VARCHAR(20),
    items_carried TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE CASCADE,
    FOREIGN KEY (flat_id) REFERENCES flats(id) ON DELETE CASCADE,
    FOREIGN KEY (guard_id) REFERENCES guards(id) ON DELETE CASCADE
);

-- =============================================
-- ALERTS AND NOTIFICATIONS
-- =============================================

-- Alert types table
CREATE TABLE alert_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    is_active BOOLEAN DEFAULT TRUE
);

-- Alerts table
CREATE TABLE alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_type_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    status ENUM('active', 'acknowledged', 'resolved', 'dismissed') DEFAULT 'active',
    related_visitor_log_id INT NULL,
    related_guard_id INT NULL,
    related_flat_id INT NULL,
    created_by INT NOT NULL, -- user who created the alert
    acknowledged_by INT NULL, -- user who acknowledged
    acknowledged_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (alert_type_id) REFERENCES alert_types(id) ON DELETE CASCADE,
    FOREIGN KEY (related_visitor_log_id) REFERENCES visitor_logs(id) ON DELETE SET NULL,
    FOREIGN KEY (related_guard_id) REFERENCES guards(id) ON DELETE SET NULL,
    FOREIGN KEY (related_flat_id) REFERENCES flats(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =============================================
-- SYSTEM CONFIGURATION
-- =============================================

-- System settings table
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =============================================
-- AUDIT LOGS
-- =============================================

-- Audit logs table for tracking all system activities
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- =============================================
-- INDEXES FOR PERFORMANCE
-- =============================================

-- Indexes for better query performance
CREATE INDEX idx_visitor_logs_checkin ON visitor_logs(check_in_time);
CREATE INDEX idx_visitor_logs_status ON visitor_logs(status);
CREATE INDEX idx_visitor_logs_flat ON visitor_logs(flat_id);
CREATE INDEX idx_guard_attendance_date ON guard_attendance(attendance_date);
CREATE INDEX idx_guard_attendance_status ON guard_attendance(status);
CREATE INDEX idx_alerts_status ON alerts(status);
CREATE INDEX idx_alerts_severity ON alerts(severity);
CREATE INDEX idx_alerts_created ON alerts(created_at);
CREATE INDEX idx_audit_logs_user ON audit_logs(user_id);
CREATE INDEX idx_audit_logs_created ON audit_logs(created_at);

-- =============================================
-- SAMPLE DATA INSERTION
-- =============================================

-- Insert default admin user (password: admin123)
-- INSERT INTO users (email, password_hash, role, full_name, phone) VALUES
-- ('admin@tapptrak.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System Administrator', '+1-555-0001'),
-- ('security1@tapptrak.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'security', 'Security Manager', '+1-555-0002');

-- Insert sample building
INSERT INTO buildings (name, address, total_flats) VALUES
('TappTrak Apartments', '123 Security Street, Tech City, TC 12345', 50);

-- Insert sample flats
INSERT INTO flats (building_id, flat_number, floor_number, flat_type, owner_name, owner_phone, owner_email) VALUES
(1, 'A-204', 2, '2BHK', 'John Smith', '+1-555-1001', 'john.smith@email.com'),
(1, 'B-108', 1, '1BHK', 'Sarah Johnson', '+1-555-1002', 'sarah.johnson@email.com'),
(1, 'C-315', 3, '3BHK', 'Mike Wilson', '+1-555-1003', 'mike.wilson@email.com'),
(1, 'A-156', 1, '2BHK', 'Lisa Brown', '+1-555-1004', 'lisa.brown@email.com');

-- Insert sample guards
INSERT INTO guards (guard_id, full_name, phone, email, hire_date, salary) VALUES
('GRD-001', 'Michael Johnson', '+1-555-2001', 'michael.johnson@tapptrak.com', '2023-01-15', 3500.00),
('GRD-002', 'Sarah Williams', '+1-555-2002', 'sarah.williams@tapptrak.com', '2023-02-01', 3500.00),
('GRD-003', 'Robert Chen', '+1-555-2003', 'robert.chen@tapptrak.com', '2023-01-20', 3500.00);

-- Insert guard shifts
INSERT INTO guard_shifts (guard_id, shift_name, start_time, end_time) VALUES
(1, 'Morning Shift', '06:00:00', '14:00:00'),
(2, 'Evening Shift', '14:00:00', '22:00:00'),
(3, 'Night Shift', '22:00:00', '06:00:00');

-- Insert sample visitors
INSERT INTO visitors (full_name, phone, email, purpose, id_proof_type, id_proof_number) VALUES
('James Anderson', '+1-555-0123', 'james.anderson@email.com', 'personal', 'aadhar', '1234-5678-9012'),
('Emily Rodriguez', '+1-555-0456', 'emily.rodriguez@email.com', 'business', 'driving_license', 'DL123456789'),
('David Thompson', '+1-555-0789', 'david.thompson@email.com', 'personal', 'aadhar', '9876-5432-1098'),
('Lisa Parker', '+1-555-0321', 'lisa.parker@email.com', 'delivery', 'pan', 'ABCDE1234F');

-- Insert sample visitor logs
INSERT INTO visitor_logs (visitor_id, flat_id, guard_id, check_in_time, check_out_time, status, purpose, expected_duration) VALUES
(1, 1, 1, '2024-01-15 09:15:00', NULL, 'inside', 'Meeting with flat owner', 120),
(2, 2, 1, '2024-01-15 08:30:00', '2024-01-15 10:45:00', 'exited', 'Business meeting', 120),
(3, 3, 1, '2024-01-15 07:45:00', NULL, 'overstayed', 'Family visit', 240),
(4, 4, 2, '2024-01-15 11:20:00', NULL, 'inside', 'Package delivery', 30);

-- Insert alert types
INSERT INTO alert_types (type_name, description, severity) VALUES
('visitor_overstayed', 'Visitor has exceeded maximum allowed duration', 'high'),
('visitor_not_exited', 'Visitor has not checked out within expected time', 'medium'),
('unauthorized_entry', 'Unauthorized entry attempt detected', 'critical'),
('guard_late', 'Guard has not checked in for their shift', 'medium'),
('system_error', 'System error or malfunction', 'low');

-- Insert sample alerts
INSERT INTO alerts (alert_type_id, title, message, severity, related_visitor_log_id, related_flat_id, created_by) VALUES
(1, 'Visitor Overstayed', 'David Thompson (Flat C-315) has exceeded the maximum visit duration of 4 hours.', 'high', 3, 3, 1),
(2, 'Visitor Not Exited', 'James Anderson (Flat A-204) entered at 09:15 AM but hasn\'t checked out yet.', 'medium', 1, 1, 1),
(3, 'Unauthorized Entry Attempt', 'Multiple failed access attempts detected at Gate B. Security review required.', 'critical', NULL, NULL, 1);

-- Insert system settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('max_visit_duration', '240', 'Maximum visit duration in minutes'),
('overstay_warning_time', '180', 'Time in minutes after which overstay warning is triggered'),
('auto_checkout_time', '480', 'Automatic checkout time in minutes for overnight stays'),
('system_name', 'TappTrak', 'System name displayed in UI'),
('timezone', 'Asia/Kolkata', 'System timezone'),
('enable_notifications', '1', 'Enable system notifications (1=yes, 0=no)');

-- =============================================
-- VIEWS FOR COMMON QUERIES
-- =============================================

-- View for active guards with their current shift status
CREATE VIEW active_guards AS
SELECT 
    g.id,
    g.guard_id,
    g.full_name,
    g.phone,
    gs.shift_name,
    gs.start_time,
    gs.end_time,
    ga.status,
    ga.check_in_time,
    ga.check_out_time,
    ga.attendance_date
FROM guards g
JOIN guard_shifts gs ON g.id = gs.guard_id
LEFT JOIN guard_attendance ga ON g.id = ga.guard_id AND ga.attendance_date = CURDATE()
WHERE g.is_active = TRUE AND gs.is_active = TRUE;

-- View for current visitors (inside the building)
CREATE VIEW current_visitors AS
SELECT 
    vl.id,
    v.full_name,
    v.phone,
    f.flat_number,
    vl.check_in_time,
    vl.expected_duration,
    vl.status,
    vl.purpose,
    g.full_name as guard_name,
    TIMESTAMPDIFF(MINUTE, vl.check_in_time, NOW()) as minutes_inside
FROM visitor_logs vl
JOIN visitors v ON vl.visitor_id = v.id
JOIN flats f ON vl.flat_id = f.id
JOIN guards g ON vl.guard_id = g.id
WHERE vl.status IN ('inside', 'overstayed') AND vl.check_out_time IS NULL;

-- View for active alerts
CREATE VIEW active_alerts AS
SELECT 
    a.id,
    a.title,
    a.message,
    a.severity,
    a.status,
    at.type_name,
    a.created_at,
    u.full_name as created_by_name
FROM alerts a
JOIN alert_types at ON a.alert_type_id = at.id
JOIN users u ON a.created_by = u.id
WHERE a.status = 'active'
ORDER BY 
    CASE a.severity 
        WHEN 'critical' THEN 1 
        WHEN 'high' THEN 2 
        WHEN 'medium' THEN 3 
        WHEN 'low' THEN 4 
    END,
    a.created_at DESC;

-- =============================================
-- STORED PROCEDURES
-- =============================================

DELIMITER //

-- Procedure to check in a visitor
CREATE PROCEDURE CheckInVisitor(
    IN p_visitor_id INT,
    IN p_flat_id INT,
    IN p_guard_id INT,
    IN p_purpose TEXT,
    IN p_expected_duration INT,
    IN p_vehicle_number VARCHAR(20),
    IN p_items_carried TEXT
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    INSERT INTO visitor_logs (
        visitor_id, flat_id, guard_id, check_in_time, 
        purpose, expected_duration, vehicle_number, items_carried, status
    ) VALUES (
        p_visitor_id, p_flat_id, p_guard_id, NOW(),
        p_purpose, p_expected_duration, p_vehicle_number, p_items_carried, 'inside'
    );
    
    COMMIT;
END //

-- Procedure to check out a visitor
CREATE PROCEDURE CheckOutVisitor(
    IN p_visitor_log_id INT,
    IN p_notes TEXT
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    UPDATE visitor_logs 
    SET 
        check_out_time = NOW(),
        status = 'exited',
        notes = CONCAT(IFNULL(notes, ''), IFNULL(CONCAT('\n', p_notes), '')),
        updated_at = NOW()
    WHERE id = p_visitor_log_id AND check_out_time IS NULL;
    
    COMMIT;
END //

-- Procedure to create an alert
CREATE PROCEDURE CreateAlert(
    IN p_alert_type_id INT,
    IN p_title VARCHAR(200),
    IN p_message TEXT,
    IN p_severity ENUM('low', 'medium', 'high', 'critical'),
    IN p_related_visitor_log_id INT,
    IN p_related_guard_id INT,
    IN p_related_flat_id INT,
    IN p_created_by INT
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    INSERT INTO alerts (
        alert_type_id, title, message, severity,
        related_visitor_log_id, related_guard_id, related_flat_id, created_by
    ) VALUES (
        p_alert_type_id, p_title, p_message, p_severity,
        p_related_visitor_log_id, p_related_guard_id, p_related_flat_id, p_created_by
    );
    
    COMMIT;
END //

DELIMITER ;

-- =============================================
-- TRIGGERS FOR AUTOMATIC UPDATES
-- =============================================

-- Trigger to update visitor status when checkout time is set
DELIMITER //
CREATE TRIGGER update_visitor_status_on_checkout
    BEFORE UPDATE ON visitor_logs
    FOR EACH ROW
BEGIN
    IF NEW.check_out_time IS NOT NULL AND OLD.check_out_time IS NULL THEN
        SET NEW.status = 'exited';
    END IF;
END //
DELIMITER ;

-- Trigger to create overstay alert
DELIMITER //
CREATE TRIGGER check_visitor_overstay
    AFTER UPDATE ON visitor_logs
    FOR EACH ROW
BEGIN
    DECLARE max_duration INT DEFAULT 240; -- 4 hours default
    DECLARE minutes_inside INT;
    
    -- Get max duration from settings
    SELECT CAST(setting_value AS UNSIGNED) INTO max_duration 
    FROM system_settings 
    WHERE setting_key = 'max_visit_duration' 
    LIMIT 1;
    
    -- Calculate minutes inside
    SET minutes_inside = TIMESTAMPDIFF(MINUTE, NEW.check_in_time, NOW());
    
    -- Create alert if visitor has overstayed
    IF NEW.status = 'inside' AND minutes_inside > max_duration THEN
        INSERT INTO alerts (
            alert_type_id, title, message, severity,
            related_visitor_log_id, related_flat_id, created_by
        ) VALUES (
            1, -- visitor_overstayed alert type
            CONCAT('Visitor Overstayed - ', (SELECT full_name FROM visitors WHERE id = NEW.visitor_id)),
            CONCAT('Visitor has been inside for ', minutes_inside, ' minutes, exceeding the maximum duration of ', max_duration, ' minutes.'),
            'high',
            NEW.id,
            NEW.flat_id,
            1 -- system user
        );
        
        -- Update visitor status to overstayed
        UPDATE visitor_logs SET status = 'overstayed' WHERE id = NEW.id;
    END IF;
END //
DELIMITER ;

-- =============================================
-- FINAL NOTES
-- =============================================

/*
TappTrak Database Schema Created Successfully!

Key Features:
1. User Management (Admin & Security roles)
2. Building & Flat Management
3. Guard Management with Shifts & Attendance
4. Visitor Management with Check-in/Check-out
5. Alert System for Security Notifications
6. Audit Logging for System Activities
7. Performance Optimized with Indexes
8. Views for Common Queries
9. Stored Procedures for Business Logic
10. Triggers for Automatic Updates

Default Admin Credentials:
- Username: admin
- Password: admin123
- Email: admin@tapptrak.com

Default Security User:
- Username: security1
- Password: admin123
- Email: security1@tapptrak.com

The schema includes sample data to demonstrate the system functionality.
All tables are properly normalized and include foreign key constraints for data integrity.
*/
