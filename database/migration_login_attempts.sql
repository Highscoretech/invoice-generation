-- ─────────────────────────────────────────────────────────────────────────────
-- Login throttling / brute-force protection (Pentest finding M1).
-- Records each authentication attempt; Auth::isLockedOut() locks an account after
-- 5 failures within a 15-minute sliding window (reset on a successful login).
-- ─────────────────────────────────────────────────────────────────────────────
USE invoice_app;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(100) NOT NULL,
    ip           VARCHAR(64) NULL,
    attempted_at DATETIME NOT NULL,
    success      TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_la_user (username, attempted_at)
);
