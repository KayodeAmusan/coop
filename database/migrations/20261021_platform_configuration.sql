-- Separate deployment/platform authority; no tblusers roles or cooperative administrator grants.
CREATE TABLE IF NOT EXISTS auracle_platform_admins (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 auth_version INT NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS auracle_platform_login_limits (
 limit_key CHAR(64) NOT NULL PRIMARY KEY,
 failed_attempts INT NOT NULL DEFAULT 0,
 window_started DATETIME NOT NULL,
 locked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS coop_deployment_branding (
 id TINYINT NOT NULL PRIMARY KEY,
 revision INT NOT NULL DEFAULT 0,
 cooperative_name VARCHAR(160) NULL,
 primary_color CHAR(7) NULL,
 accent_color CHAR(7) NULL,
 logo_asset_id CHAR(32) NULL,
 favicon_asset_id CHAR(32) NULL,
 updated_by VARCHAR(255) NULL,
 updated_at TIMESTAMP NULL,
 CONSTRAINT chk_deployment_branding_singleton CHECK (id=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO coop_deployment_branding(id,revision) VALUES(1,0);
CREATE TABLE IF NOT EXISTS coop_integration_configuration (
 provider VARCHAR(30) NOT NULL PRIMARY KEY,
 revision INT NOT NULL,
 enabled BOOLEAN NOT NULL DEFAULT FALSE,
 config_json TEXT NOT NULL,
 encrypted_secrets TEXT NOT NULL,
 updated_by VARCHAR(255) NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS coop_platform_configuration_history (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 platform_actor_id VARCHAR(255) NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 redacted_metadata TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_platform_configuration_history(created_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS coop_platform_configuration_commands (
 key_hash CHAR(64) NOT NULL PRIMARY KEY,
 request_hash CHAR(64) NOT NULL,
 response_json TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS coop_branding_assets (
 asset_id CHAR(32) NOT NULL PRIMARY KEY,
 asset_kind ENUM('logo','favicon') NOT NULL,
 storage_key CHAR(64) NOT NULL UNIQUE,
 content_sha256 CHAR(64) NOT NULL,
 byte_size INT NOT NULL,
 mime_type VARCHAR(30) NOT NULL,
 scan_status ENUM('clean') NOT NULL,
 scan_engine VARCHAR(100) NOT NULL,
 uploaded_by VARCHAR(255) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- No platform users, credentials, provider selections or cooperative brand defaults are inserted.
-- Preserve encrypted values, audit history and asset files during application rollback.
