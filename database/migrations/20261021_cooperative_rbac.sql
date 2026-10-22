-- Cooperative staff authority only. No seeded roles, memberships or inferred Admin grants.
-- Auracle platform identities and member JWT identities are outside this schema.
CREATE TABLE IF NOT EXISTS coop_access_roles (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 role_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 display_name VARCHAR(150) NOT NULL,
 created_by_user_id INT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS coop_access_role_permissions (
 role_id INT NOT NULL,
 permission_code VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(role_id, permission_code),
 CONSTRAINT fk_coop_access_permission_role FOREIGN KEY(role_id) REFERENCES coop_access_roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS coop_access_staff_roles (
 staff_user_id INT NOT NULL,
 role_id INT NOT NULL,
 PRIMARY KEY(staff_user_id, role_id),
 KEY idx_coop_access_staff_role(role_id, staff_user_id),
 CONSTRAINT fk_coop_access_staff_role FOREIGN KEY(role_id) REFERENCES coop_access_roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Serialize role administration and its authorization check so revocation cannot race a grant.
CREATE TABLE IF NOT EXISTS coop_access_control (
 id TINYINT NOT NULL PRIMARY KEY,
 bootstrapped_at TIMESTAMP NULL,
 bootstrap_staff_user_id INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO coop_access_control(id) VALUES(1);
CREATE TABLE IF NOT EXISTS coop_access_commands (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_user_id INT NOT NULL,
 action VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 result_json JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_coop_access_command(actor_user_id,action,idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password commands keep a bcrypt verification proof, never a fast plaintext-password fingerprint.
ALTER TABLE coop_access_commands ADD COLUMN IF NOT EXISTS secret_hash VARCHAR(255) NULL;
