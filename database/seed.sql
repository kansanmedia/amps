-- Kansan Manpower Management Initial Seed Data

-- 1. Company Settings
INSERT INTO company_settings (id, company_name, tagline, address, phone, email, gst_number, pan_number, currency_symbol, invoice_prefix, terms_conditions)
VALUES (
    1,
    'Kansan Manpower Management',
    'Premier Workforce Solutions & Event Staffing',
    'Plot 45, Commercial Plaza, Sector 18, City Centre',
    '+91 9876543210',
    'contact@kansanmanpower.com',
    '07AAAAA0000A1Z5',
    'ABCDE1234F',
    '₹',
    'INV-',
    '1. Invoices are due within the payment terms specified.\n2. Overtime shifts require prior supervisor sign-off.\n3. Make all cheques/NEFT payable to Kansan Manpower Management.'
) ON DUPLICATE KEY UPDATE company_name=VALUES(company_name);

-- 2. Configurable Shifts (3 default shifts - Admin can edit times anytime)
INSERT INTO shifts (id, shift_code, shift_name, start_time, end_time, is_overnight, duration_hours, description, status) VALUES
(1, 'SHIFT-1', 'Morning Shift', '07:00:00', '15:00:00', 0, 8.00, 'Breakfast, lunch service & morning hotel operations', 'active'),
(2, 'SHIFT-2', 'Evening Shift', '15:00:00', '23:00:00', 0, 8.00, 'Dinner service, parties and banquets', 'active'),
(3, 'SHIFT-3', 'Night Shift (Overnight)', '23:00:00', '07:00:00', 1, 8.00, 'Late-night events, clean-up & overnight facility ops (ends next day)', 'active');

-- 3. Financial Accounts (Cash, Bank, UPI)
INSERT INTO financial_accounts (id, account_code, account_name, account_type, bank_name, account_number, ifsc_code, opening_balance, current_balance, status) VALUES
(1, 'ACC-CASH-01', 'Cash', 'cash', NULL, NULL, NULL, 25000.00, 25000.00, 'active'),
(2, 'ACC-HDFC-01', 'HDFC Bank Current A/C', 'bank', 'HDFC Bank', '50200012345678', 'HDFC0001234', 150000.00, 150000.00, 'active'),
(3, 'ACC-ICICI-01', 'ICICI Bank Operations A/C', 'bank', 'ICICI Bank', '001105009988', 'ICIC0000011', 80000.00, 80000.00, 'active'),
(4, 'ACC-UPI-01', 'Company UPI (PhonePe / GPay)', 'upi', 'HDFC Linked', 'kansanmanpower@hdfcbank', NULL, 15000.00, 15000.00, 'active');

-- 4. Expense Categories
INSERT INTO expense_categories (id, category_name, description, status) VALUES
(1, 'Travel & Staff Transport', 'Cab, auto, bus, and staff transit fares to remote sites', 'active'),
(2, 'Staff Meals & Refreshments', 'Food provided to staff during long shifts and banquets', 'active'),
(3, 'Uniforms & Safety Gear', 'Black aprons, white shirts, bowties, safety shoes, ID tags', 'active'),
(4, 'Accommodation', 'Staff lodging for multi-day outstation wedding events', 'active'),
(5, 'Fuel & Vehicle Conveyance', 'Conveyance allowances for supervisors and site coordinators', 'active'),
(6, 'Office Rent & Utilities', 'Electricity, internet, printing, drinking water', 'active'),
(7, 'Office Supplies & Stationery', 'ID card printing, register books, stamps', 'active'),
(8, 'Miscellaneous Expenses', 'Emergency contingency and one-off operational costs', 'active');

-- 4b. Staff Categories Seed Data
INSERT INTO staff_categories (id, category_code, category_name, description, status) VALUES
(1, 'CAT-STEWARD', 'Senior Steward / Waiter', 'Banquets, fine dining and table service specialists', 'active'),
(2, 'CAT-BARTEND', 'Bartender & Mixologist', 'Bar operations, cocktail preparation and beverage counter', 'active'),
(3, 'CAT-HOUSEKP', 'Housekeeping & Support', 'Event hygiene, clearing, kitchen stewarding and setup', 'active'),
(4, 'CAT-CHEF', 'Assistant Chef / Pantry', 'Kitchen food preparation, buffet restocking and pantry support', 'active'),
(5, 'CAT-HOST', 'Event Usher / Host', 'Guest greetings, registration desks and floor coordination', 'active');

-- 4c. Staff Groups Seed Data
INSERT INTO staff_groups (id, group_name, group_code, color_theme, display_order, status) VALUES
(1, 'GENERAL POOL', 'GRP-GEN', 'secondary', 1, 'active'),
(2, 'AERO CITY BANQUET SQUAD', 'GRP-AERO', 'primary', 2, 'active'),
(3, 'ELITE VIP TEAM', 'GRP-VIP', 'success', 3, 'active');

-- 5. Staff Seed Data
INSERT INTO staff (id, staff_code, full_name, phone, email, emergency_contact, address, id_proof_type, id_proof_number, skill_category, category_id, staff_group, default_payout_rate, status, joined_date) VALUES
(1, 'STF-001', 'Rajesh Sharma', '9811122201', 'rajesh@example.com', '9811122299', 'House 12, Vikas Nagar', 'Aadhaar Card', '4589-2211-7788', 'Senior Steward / Waiter', 1, 'AERO CITY BANQUET SQUAD', 600.00, 'active', '2026-01-10'),
(2, 'STF-002', 'Amit Kumar', '9811122202', 'amit@example.com', '9811122298', 'Block C, Prem Vihar', 'Aadhaar Card', '9988-1122-3344', 'Bartender & Mixologist', 2, 'ELITE VIP TEAM', 750.00, 'active', '2026-01-15'),
(3, 'STF-003', 'Sunil Yadav', '9811122203', 'sunil@example.com', '9811122297', 'Street 4, Govindpur', 'Voter ID', 'VTR8899112', 'Housekeeping & Support', 3, 'GENERAL POOL', 500.00, 'active', '2026-02-01'),
(4, 'STF-004', 'Vikram Verma', '9811122204', 'vikram@example.com', '9811122296', 'Lane 2, Shastri Park', 'Aadhaar Card', '3344-5566-7788', 'Assistant Chef / Pantry', 4, 'AERO CITY BANQUET SQUAD', 700.00, 'active', '2026-02-10'),
(5, 'STF-005', 'Deepak Joshi', '9811122205', 'deepak@example.com', '9811122295', 'Gali 9, Mohan Garden', 'Aadhaar Card', '7788-9900-1122', 'Event Usher / Host', 5, 'GENERAL POOL', 550.00, 'active', '2026-03-01');

-- 6. Users (Admin, Manager, Accountant, Staff)
-- Passwords:
-- admin -> admin123
-- manager -> manager123
-- accountant -> accountant123
-- staff1 -> staff123
INSERT INTO users (id, username, email, password_hash, full_name, phone, role, staff_id, status) VALUES
(1, 'admin', 'admin@kansanmanpower.com', '$2y$10$s4imLDRZKMkcfjqdTqBzeuO1gqY9BaxhVVouko4xe5hE7MIlgAcKq', 'Admin Kansan', '9876543210', 'owner_admin', NULL, 'active'),
(2, 'manager', 'manager@kansanmanpower.com', '$2y$10$hZOi71lfWzNZtFejE/fd6uMylY.2UupgxtUQIuS6Ru59pEU2NKLtq', 'Operational Manager', '9876543211', 'manager', NULL, 'active'),
(3, 'accountant', 'accounts@kansanmanpower.com', '$2y$10$KggG.F19yFR7TfCX2uMlRuZK24t8JyIVhO51eYSw87wXYcJOS9TDm', 'Chief Accountant', '9876543212', 'accountant', NULL, 'active'),
(4, 'staff1', 'rajesh.staff@kansanmanpower.com', '$2y$10$vp/hB.nAWQoAozyJOQS8e.2lxW3ShuO3QMvauLfJlDpwXw/deF9jq', 'Rajesh Sharma', '9811122201', 'staff', 1, 'active');

-- 7. Clients Seed Data
INSERT INTO clients (id, client_code, company_name, contact_person, phone, email, gst_number, pan_number, billing_address, billing_cycle, payment_terms_days, default_billing_rate, status) VALUES
(1, 'CLI-001', 'Grand Hyatt & Banquets', 'Mr. Sanjeev Kapoor', '9822233344', 'banquets@grandhyatt.test', '07AAACG1111A1Z1', 'AAACG1111A', 'Plot 10, Aero City, Hospitality District', 'monthly', 15, 950.00, 'active'),
(2, 'CLI-002', 'Royal Spice Fine Dining Restaurant', 'Ms. Meenakshi Iyer', '9822233355', 'ops@royalspice.test', '07AAACR2222A1Z2', 'AAACR2222A', 'Shop 4-6, Galleria Promenade, Level 2', 'fortnightly', 10, 850.00, 'active'),
(3, 'CLI-003', 'Skyline Celebrations & Events', 'Mr. Rohan Malhotra', '9822233366', 'events@skyline.test', '07AAACS3333A1Z3', 'AAACS3333A', 'Farm 22, Green Avenue, Express Highway', 'weekly', 7, 1000.00, 'active');

-- 8. Sites Seed Data (Multiple sites per client with GPS coords)
INSERT INTO sites (id, client_id, site_code, site_name, location_address, contact_person, contact_phone, latitude, longitude, geofence_radius_meters, default_client_rate, default_staff_rate, status) VALUES
(1, 1, 'SIT-HYT-01', 'Grand Ballroom & Convention Center', 'Grand Hyatt Gate 2, Aero City', 'Ramesh Verma', '9833344411', 28.55616000, 77.10000000, 250, 950.00, 600.00, 'active'),
(2, 1, 'SIT-HYT-02', 'Lakeside Pavilion & Lawns', 'Grand Hyatt South Wing Lawns', 'Suresh Nair', '9833344422', 28.55700000, 77.10150000, 200, 900.00, 580.00, 'active'),
(3, 2, 'SIT-RSP-01', 'Downtown Flagship Bistro', 'Galleria Mall Level 2, Central Wing', 'Kiran Joshi', '9833344433', 28.46820000, 77.08500000, 150, 850.00, 550.00, 'active'),
(4, 3, 'SIT-SKY-01', 'Skyline Royal Palms Lawn', 'Farm 22, Green Avenue Road', 'Rakesh Singh', '9833344444', 28.51200000, 77.14500000, 300, 1000.00, 650.00, 'active');

-- 9. Initial Opening Balances in Ledger
INSERT INTO ledger_entries (transaction_id, entry_date, account_id, entity_type, entity_id, entry_type, amount, running_balance, source_type, source_id, narration, created_by) VALUES
('TXN-INIT-001', '2026-01-01', 1, 'financial_account', 1, 'debit', 25000.00, 25000.00, 'opening_balance', 1, 'Opening Balance - Cash', 1),
('TXN-INIT-002', '2026-01-01', 2, 'financial_account', 2, 'debit', 150000.00, 150000.00, 'opening_balance', 2, 'Opening Balance - HDFC Bank Current A/C', 1),
('TXN-INIT-003', '2026-01-01', 3, 'financial_account', 3, 'debit', 80000.00, 80000.00, 'opening_balance', 3, 'Opening Balance - ICICI Bank Operations A/C', 1),
('TXN-INIT-004', '2026-01-01', 4, 'financial_account', 4, 'debit', 15000.00, 15000.00, 'opening_balance', 4, 'Opening Balance - Company UPI', 1);
