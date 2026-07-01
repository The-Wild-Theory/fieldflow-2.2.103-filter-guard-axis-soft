CREATE TABLE licenses (
    id BIGINT IDENTITY(1,1) PRIMARY KEY,
    license_id NVARCHAR(64) NOT NULL UNIQUE,
    product_id NVARCHAR(64) NOT NULL,
    license_key NVARCHAR(128) NOT NULL UNIQUE,
    plan NVARCHAR(32) NOT NULL,
    customer NVARCHAR(128) NULL,
    customer_email NVARCHAR(255) NULL,
    status NVARCHAR(32) NOT NULL DEFAULT 'inactive',
    max_activations INT NOT NULL DEFAULT 1,
    expires_at DATETIME2 NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);

CREATE TABLE license_activations (
    id BIGINT IDENTITY(1,1) PRIMARY KEY,
    activation_id NVARCHAR(64) NOT NULL UNIQUE,
    license_id NVARCHAR(64) NOT NULL,
    domain NVARCHAR(255) NOT NULL,
    site_url NVARCHAR(255) NULL,
    fingerprint NVARCHAR(64) NOT NULL,
    status NVARCHAR(32) NOT NULL DEFAULT 'active',
    activated_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
    last_seen_at DATETIME2 NULL,
    deactivated_at DATETIME2 NULL,
    CONSTRAINT FK_license_activations_license FOREIGN KEY (license_id) REFERENCES licenses(license_id)
);

CREATE TABLE license_logs (
    id BIGINT IDENTITY(1,1) PRIMARY KEY,
    license_id NVARCHAR(64) NULL,
    event_type NVARCHAR(64) NOT NULL,
    payload_json NVARCHAR(MAX) NULL,
    created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
);
