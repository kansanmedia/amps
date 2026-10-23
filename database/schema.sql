-- Kansan Manpower Management Database Schema
-- Engineered for Production Use with Strict Integrity and Auditability

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS ledger_entries;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS expense_categories;
DROP TABLE IF EXISTS staff_payments;
DROP TABLE IF EXISTS client_payments;
DROP TABLE IF EXISTS invoice_items;
DROP TABLE IF EXISTS invoices;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS deployments;
DROP TABLE IF EXISTS rate_cards;
DROP TABLE IF EXISTS shifts;
DROP TABLE IF EXISTS sites;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS staff_leaves;
DROP TABLE IF EXISTS staff_groups;
DROP TABLE IF EXISTS staff;
DROP TABLE IF EXISTS staff_categories;
DROP TABLE IF EXISTS financial_accounts;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS company_settings;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. Company Settings
CREATE TABLE company_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_name VARCHAR(150) NOT NULL DEFAULT 'Kansan Manpower Solutions',
    tagline VARCHAR(255) DEFAULT 'Professional Staffing & Workforce Management',
    address TEXT,
    phone VARCHAR(30) DEFAULT '+91 9876543210',
    email VARCHAR(100) DEFAULT 'info@kansanmanpower.com',
    gst_number VARCHAR(30) DEFAULT '27AAAAA0000A1Z5',
    pan_number VARCHAR(30) DEFAULT 'AAAAA0000A',
    currency_symbol VARCHAR(10) DEFAULT '₹',
    invoice_prefix VARCHAR(10) DEFAULT 'INV-',
    terms_conditions TEXT,
    logo_url VARCHAR(255) DEFAULT 'assets/img/logo.png',
    bank_name VARCHAR(100) DEFAULT 'HDFC Bank',
    account_holder VARCHAR(150) DEFAULT 'Kansan Manpower Solutions',
    account_number VARCHAR(50) DEFAULT '50200012345678',
    ifsc_code VARCHAR(20) DEFAULT 'HDFC0001234',
    branch_name VARCHAR(100) DEFAULT 'Main Branch',
    upi_id VARCHAR(100) DEFAULT 'kansanmanpower@hdfcbank',
    upi_qr_url VARCHAR(255) NULL,
    enable_tax_by_default TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Financial Accounts (Cash, Bank, UPI)
CREATE TABLE financial_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    account_code VARCHAR(20) NOT NULL UNIQUE,
    account_name VARCHAR(100) NOT NULL,
    account_type ENUM('cash', 'bank', 'upi') NOT NULL,
    bank_name VARCHAR(100) NULL,
    account_number VARCHAR(50) NULL,
    ifsc_code VARCHAR(20) NULL,
    opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    current_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2b. Staff Categories (Designations & Roles)
CREATE TABLE IF NOT EXISTS staff_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_code VARCHAR(20) NOT NULL UNIQUE,
    category_name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2c. Staff Groups (Operational Teams / Pools)
CREATE TABLE IF NOT EXISTS staff_groups (
    id INT PRIMARY KEY AUTO_INCREMENT,
    group_name VARCHAR(100) NOT NULL UNIQUE,
    group_code VARCHAR(50) NULL,
    color_theme VARCHAR(50) DEFAULT 'primary',
    display_order INT DEFAULT 0,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_group_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Staff Directory
CREATE TABLE staff (
    id INT PRIMARY KEY AUTO_INCREMENT,
    staff_code VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(100) NULL,
    emergency_contact VARCHAR(50) NULL,
    address TEXT NULL,
    id_proof_type VARCHAR(50) DEFAULT 'Aadhaar Card',
    id_proof_number VARCHAR(50) NULL,
    id_proof_doc VARCHAR(255) NULL,
    photo VARCHAR(255) NULL,
    bank_name VARCHAR(100) NULL,
    bank_account_no VARCHAR(50) NULL,
    bank_ifsc VARCHAR(20) NULL,
    upi_id VARCHAR(50) NULL,
    skill_category VARCHAR(50) NOT NULL DEFAULT 'General Staff',
    category_id INT NULL,
    staff_group VARCHAR(100) DEFAULT 'GENERAL POOL',
    default_payout_rate DECIMAL(10,2) NOT NULL DEFAULT 500.00,
    status ENUM('active', 'inactive', 'blocked') NOT NULL DEFAULT 'active',
    joined_date DATE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_staff_category FOREIGN KEY (category_id) REFERENCES staff_categories(id) ON DELETE SET NULL,
    INDEX idx_staff_phone (phone),
    INDEX idx_staff_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Users (Authentication & Roles)
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NULL,
    role ENUM('owner_admin', 'manager', 'accountant', 'staff') NOT NULL DEFAULT 'staff',
    staff_id INT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    last_login DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE SET NULL,
    INDEX idx_user_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4b. Staff Leaves & Unavailability
CREATE TABLE IF NOT EXISTS staff_leaves (
    id INT PRIMARY KEY AUTO_INCREMENT,
    staff_id INT NOT NULL,
    leave_type VARCHAR(50) NOT NULL DEFAULT 'casual',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT NULL,
    status ENUM('active', 'cancelled') NOT NULL DEFAULT 'active',
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_leave_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    CONSTRAINT fk_leave_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_leave_dates (start_date, end_date),
    INDEX idx_leave_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Clients Directory
CREATE TABLE clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_code VARCHAR(20) NOT NULL UNIQUE,
    company_name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) NULL,
    gst_number VARCHAR(30) NULL,
    pan_number VARCHAR(30) NULL,
    billing_address TEXT NOT NULL,
    billing_cycle ENUM('weekly', 'fortnightly', 'monthly', 'on_demand') NOT NULL DEFAULT 'monthly',
    payment_terms_days INT NOT NULL DEFAULT 15,
    default_billing_rate DECIMAL(10,2) NOT NULL DEFAULT 800.00,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_code (client_code),
    INDEX idx_client_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Sites (Multiple locations per Client)
CREATE TABLE sites (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    site_code VARCHAR(20) NOT NULL,
    site_name VARCHAR(150) NOT NULL,
    location_address TEXT NOT NULL,
    contact_person VARCHAR(100) NULL,
    contact_phone VARCHAR(20) NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    geofence_radius_meters INT NOT NULL DEFAULT 250,
    default_client_rate DECIMAL(10,2) NULL,
    default_staff_rate DECIMAL(10,2) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sites_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    INDEX idx_sites_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Shifts (Fully Admin Configurable)
CREATE TABLE shifts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    shift_code VARCHAR(20) NOT NULL UNIQUE,
    shift_name VARCHAR(100) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_overnight TINYINT(1) NOT NULL DEFAULT 0,
    duration_hours DECIMAL(4,2) NOT NULL DEFAULT 8.00,
    description VARCHAR(255) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Rate Cards (Overrides for specific Client + Site + Shift + Staff)
CREATE TABLE rate_cards (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    category_id INT NULL,
    site_id INT NULL,
    shift_id INT NULL,
    staff_id INT NULL,
    client_billing_rate DECIMAL(10,2) NOT NULL,
    staff_payout_rate DECIMAL(10,2) NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    notes VARCHAR(255) NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rate_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rate_category FOREIGN KEY (category_id) REFERENCES staff_categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_rate_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_rate_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
    CONSTRAINT fk_rate_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    CONSTRAINT fk_rate_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rate_category (category_id),
    INDEX idx_rate_lookup (client_id, site_id, shift_id, staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Deployments (Daily Staff Mapping with Rate Snapshots)
CREATE TABLE deployments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    deployment_date DATE NOT NULL,
    staff_id INT NOT NULL,
    client_id INT NOT NULL,
    site_id INT NOT NULL,
    shift_id INT NOT NULL,
    client_rate DECIMAL(10,2) NOT NULL,
    staff_rate DECIMAL(10,2) NOT NULL,
    status ENUM('scheduled', 'active', 'completed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_deployment_slot UNIQUE KEY (deployment_date, staff_id, shift_id),
    CONSTRAINT fk_deploy_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_deploy_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_deploy_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE RESTRICT,
    CONSTRAINT fk_deploy_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_deploy_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_deploy_date (deployment_date),
    INDEX idx_deploy_client_site (client_id, site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Attendance (Shift-wise Check-in/Check-out, Overnight & GPS support)
CREATE TABLE attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    deployment_id INT NOT NULL UNIQUE,
    attendance_date DATE NOT NULL,
    staff_id INT NOT NULL,
    client_id INT NOT NULL,
    site_id INT NOT NULL,
    shift_id INT NOT NULL,
    status ENUM('scheduled', 'present', 'half_day', 'absent', 'leave') NOT NULL DEFAULT 'scheduled',
    check_in_time DATETIME NULL,
    check_out_time DATETIME NULL,
    check_in_lat DECIMAL(10,8) NULL,
    check_in_lng DECIMAL(11,8) NULL,
    check_out_lat DECIMAL(10,8) NULL,
    check_out_lng DECIMAL(11,8) NULL,
    gps_verified TINYINT(1) NOT NULL DEFAULT 0,
    hours_worked DECIMAL(4,2) NOT NULL DEFAULT 8.00,
    billable_rate_multiplier DECIMAL(3,2) NOT NULL DEFAULT 1.00,
    final_client_amount DECIMAL(10,2) NOT NULL,
    final_staff_amount DECIMAL(10,2) NOT NULL,
    is_billed TINYINT(1) NOT NULL DEFAULT 0,
    is_settled TINYINT(1) NOT NULL DEFAULT 0,
    supervisor_verified TINYINT(1) NOT NULL DEFAULT 0,
    supervisor_id INT NULL,
    remarks VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_att_deployment FOREIGN KEY (deployment_id) REFERENCES deployments(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_att_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_att_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE RESTRICT,
    CONSTRAINT fk_att_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_att_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_att_date (attendance_date),
    INDEX idx_att_billing (client_id, is_billed),
    INDEX idx_att_settlement (staff_id, is_settled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Invoices
CREATE TABLE invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_number VARCHAR(30) NOT NULL UNIQUE,
    client_id INT NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_percentage DECIMAL(5,2) NOT NULL DEFAULT 18.00,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    balance_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_status ENUM('unpaid', 'partially_paid', 'paid', 'cancelled') NOT NULL DEFAULT 'unpaid',
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inv_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_inv_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_inv_client (client_id),
    INDEX idx_inv_date (invoice_date),
    INDEX idx_inv_status (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Invoice Line Items
CREATE TABLE invoice_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    attendance_id INT NOT NULL,
    deployment_id INT NOT NULL,
    service_date DATE NOT NULL,
    site_name VARCHAR(150) NOT NULL,
    shift_name VARCHAR(100) NOT NULL,
    staff_name VARCHAR(100) NOT NULL,
    staff_category VARCHAR(100) NULL,
    quantity DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    unit_rate DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE RESTRICT,
    CONSTRAINT fk_item_deploy FOREIGN KEY (deployment_id) REFERENCES deployments(id) ON DELETE RESTRICT,
    INDEX idx_item_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Client Payments Received
CREATE TABLE client_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payment_number VARCHAR(30) NOT NULL UNIQUE,
    client_id INT NOT NULL,
    invoice_id INT NULL,
    account_id INT NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_mode ENUM('cash', 'bank_transfer', 'neft_rtgs', 'upi', 'cheque') NOT NULL DEFAULT 'bank_transfer',
    reference_number VARCHAR(100) NULL,
    notes TEXT NULL,
    received_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cpay_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    CONSTRAINT fk_cpay_account FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cpay_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_cpay_client (client_id),
    INDEX idx_cpay_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Staff Payments & Settlements
CREATE TABLE staff_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payment_number VARCHAR(30) NOT NULL UNIQUE,
    staff_id INT NOT NULL,
    account_id INT NOT NULL,
    payment_type ENUM('salary_settlement', 'advance', 'bonus', 'deduction_adjustment') NOT NULL DEFAULT 'salary_settlement',
    payment_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_mode ENUM('cash', 'bank_transfer', 'upi') NOT NULL DEFAULT 'cash',
    reference_number VARCHAR(100) NULL,
    settlement_period_start DATE NULL,
    settlement_period_end DATE NULL,
    notes TEXT NULL,
    processed_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_spay_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE RESTRICT,
    CONSTRAINT fk_spay_account FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_spay_user FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_spay_staff (staff_id),
    INDEX idx_spay_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Expense Categories
CREATE TABLE expense_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Expenses
CREATE TABLE expenses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    expense_number VARCHAR(30) NOT NULL UNIQUE,
    category_id INT NOT NULL,
    account_id INT NOT NULL,
    expense_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    client_id INT NULL,
    site_id INT NULL,
    staff_id INT NULL,
    vendor_name VARCHAR(100) NULL,
    description TEXT NOT NULL,
    receipt_image VARCHAR(255) NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_exp_cat FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_exp_acc FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_exp_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE SET NULL,
    CONSTRAINT fk_exp_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_exp_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Multi-Entity Double-Entry Ledger Entries
CREATE TABLE ledger_entries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(50) NOT NULL,
    entry_date DATE NOT NULL,
    account_id INT NULL,
    entity_type ENUM('client', 'staff', 'vendor', 'company', 'financial_account') NOT NULL,
    entity_id INT NULL,
    entry_type ENUM('debit', 'credit') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    running_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    source_type ENUM('invoice', 'client_payment', 'staff_earning', 'staff_payment', 'expense', 'account_transfer', 'opening_balance') NOT NULL,
    source_id INT NOT NULL,
    narration TEXT NOT NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_led_acc FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_led_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_led_trans (transaction_id),
    INDEX idx_led_entity (entity_type, entity_id),
    INDEX idx_led_date (entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Audit Logs
CREATE TABLE audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    module VARCHAR(50) NOT NULL,
    record_id INT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_module (module, record_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Notifications (WhatsApp, SMS, Email logs)
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    recipient_type ENUM('staff', 'client', 'admin') NOT NULL,
    recipient_id INT NOT NULL,
    channel ENUM('whatsapp', 'sms', 'email') NOT NULL DEFAULT 'whatsapp',
    event_type ENUM('deployment_assigned', 'attendance_reminder', 'invoice_generated', 'payment_received', 'salary_paid', 'custom') NOT NULL,
    subject VARCHAR(200) NULL,
    message_payload TEXT NOT NULL,
    status ENUM('queued', 'sent', 'failed') NOT NULL DEFAULT 'queued',
    scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    INDEX idx_notif_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
