CREATE DATABASE IF NOT EXISTS invoice_app;
USE invoice_app;

-- Companies table with 30 fields
CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    tin_number VARCHAR(100), -- Added TIN number as requested
    address VARCHAR(500),
    email VARCHAR(255),
    phone VARCHAR(50),
    city VARCHAR(100),
    state VARCHAR(100),
    country VARCHAR(100),
    postal_code VARCHAR(20),
    website VARCHAR(255),
    tax_id VARCHAR(100),
    registration_number VARCHAR(100),
    industry VARCHAR(100),
    established_date DATE,
    employee_count INT,
    annual_revenue DECIMAL(15,2),
    contact_person VARCHAR(255),
    contact_designation VARCHAR(100),
    contact_phone VARCHAR(50),
    contact_email VARCHAR(255),
    bank_name VARCHAR(255),
    bank_account VARCHAR(100),
    bank_routing VARCHAR(50),
    currency VARCHAR(10) DEFAULT 'USD',
    timezone VARCHAR(50),
    language VARCHAR(10) DEFAULT 'en',
    logo VARCHAR(255),
    description TEXT,
    notes TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    role ENUM('admin', 'accountant') NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Customers table with 30 fields
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    tin VARCHAR(100), -- Added TIN as requested
    address VARCHAR(500), -- Moved address up as requested
    email VARCHAR(255),
    phone VARCHAR(50),
    mobile VARCHAR(50),
    fax VARCHAR(50),
    website VARCHAR(255),
    tax_id VARCHAR(100),
    registration_number VARCHAR(100),
    billing_address VARCHAR(500),
    billing_city VARCHAR(100),
    billing_state VARCHAR(100),
    billing_country VARCHAR(100),
    billing_postal_code VARCHAR(20),
    shipping_address VARCHAR(500),
    shipping_city VARCHAR(100),
    shipping_state VARCHAR(100),
    shipping_country VARCHAR(100),
    shipping_postal_code VARCHAR(20),
    contact_person VARCHAR(255),
    contact_designation VARCHAR(100),
    contact_phone VARCHAR(50),
    contact_email VARCHAR(255),
    credit_limit DECIMAL(15,2),
    payment_terms VARCHAR(100),
    discount_percentage DECIMAL(5,2),
    currency VARCHAR(10) DEFAULT 'USD',
    notes TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Items table with 30 fields
CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(100), -- Added item code as requested
    company_id INT NOT NULL,
    name VARCHAR(255) NOT NULL, -- Item name
    hsn_code VARCHAR(50), -- Added HSN code for GST compliance
    description TEXT,
    sku VARCHAR(100),
    barcode VARCHAR(100),
    category VARCHAR(100),
    subcategory VARCHAR(100),
    brand VARCHAR(100),
    model VARCHAR(100),
    color VARCHAR(50),
    size VARCHAR(50),
    weight DECIMAL(10,3),
    dimensions VARCHAR(100),
    unit VARCHAR(50),
    cost_price DECIMAL(15,2),
    selling_price DECIMAL(15,2),
    mrp DECIMAL(15,2),
    tax_rate DECIMAL(5,2),
    discount_percentage DECIMAL(5,2),
    minimum_stock INT,
    current_stock INT,
    reorder_level INT,
    supplier VARCHAR(255),
    supplier_code VARCHAR(100),
    warranty_period VARCHAR(100),
    expiry_date DATE,
    manufacturing_date DATE,
    location VARCHAR(100),
    notes TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Invoices table
CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(100) UNIQUE NOT NULL,
    date DATE NOT NULL, -- Invoice date
    time TIME NOT NULL, -- Invoice time as requested
    customer_id INT NOT NULL, -- Customer reference
    company_id INT NOT NULL,
    user_id INT NOT NULL,
    due_date DATE,
    subtotal DECIMAL(15,2) NOT NULL,
    tax_rate DECIMAL(5,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL,
    status ENUM('draft', 'sent', 'paid', 'cancelled') DEFAULT 'draft',
    api_status ENUM('pending', 'sent', 'success', 'failed') DEFAULT 'pending',
    api_response TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Invoice items table
CREATE TABLE invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item_code VARCHAR(100), -- Item code as requested
    quantity DECIMAL(10,3) NOT NULL,
    rate DECIMAL(15,2) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    item_id INT NOT NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

-- Insert sample data
INSERT INTO companies (name, email, phone, address, city, state, country, postal_code) VALUES
('Sample Company Ltd', 'info@samplecompany.com', '+1-555-0123', '123 Business St', 'Business City', 'Business State', 'USA', '12345');

INSERT INTO users (company_id, username, email, password, first_name, last_name, role) VALUES
(1, 'admin', 'admin@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 'User', 'admin'),
(1, 'accountant', 'accountant@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Account', 'User', 'accountant');