<?php
// Harden the session cookie BEFORE the session starts (Pentest finding L1):
// HttpOnly stops JS from reading PHPSESSID, Secure keeps it to HTTPS, and
// SameSite=Strict removes the cookie from cross-site requests (CSRF defence).
$__secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (($_SERVER['SERVER_PORT'] ?? '') == 443);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime'  => 0,
        'path'      => '/',
        'httponly'  => true,
        'secure'    => $__secure,
        'samesite'  => 'Strict',
    ]);
    session_start();
}
require_once __DIR__ . '/../config/database.php';

class Auth {
    private $conn;
    /** Last login error message (distinguishes lockout from bad credentials). */
    public $loginError = '';

    private const MAX_ATTEMPTS  = 5;   // failures before lockout
    private const WINDOW_MINUTES = 15; // sliding lockout window

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function login($username, $password) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // Brute-force protection (Pentest finding M1).
        if ($this->isLockedOut($username)) {
            $this->loginError = 'Too many failed attempts. Please try again in '
                              . self::WINDOW_MINUTES . ' minutes.';
            return false;
        }

        $query = "SELECT u.*, c.name as company_name FROM users u
                  JOIN companies c ON u.company_id = c.id
                  WHERE u.username = :username AND u.status = 'active'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (password_verify($password, $user['password'])) {
                // Session fixation defence (Pentest finding M2): issue a brand
                // new session id and delete the old file before storing identity.
                session_regenerate_id(true);

                $_SESSION['user_id']      = $user['id'];
                $_SESSION['company_id']   = $user['company_id'];
                $_SESSION['username']     = $user['username'];
                $_SESSION['role']         = $user['role'];
                $_SESSION['company_name'] = $user['company_name'];

                $this->logAttempt($username, $ip, true);
                return true;
            }
        }

        $this->logAttempt($username, $ip, false);
        $this->loginError = 'Invalid username or password';
        return false;
    }

    public function logout() {
        // Fully invalidate the session (Pentest finding M2).
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit();
    }

    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: login.php');
            exit();
        }
    }

    public function requireRole($role) {
        $this->requireLogin();
        if ($_SESSION['role'] !== $role) {
            header('Location: dashboard.php');
            exit();
        }
    }

    public function hasRole($role) {
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }

    // ── Brute-force helpers (fail open if the table is absent) ───────────────

    /** Locked when there are >= MAX_ATTEMPTS failures since the last success
     *  within the sliding window. */
    private function isLockedOut($username) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE username = :u AND success = 0
                   AND attempted_at > (NOW() - INTERVAL " . self::WINDOW_MINUTES . " MINUTE)
                   AND attempted_at > COALESCE(
                       (SELECT MAX(attempted_at) FROM login_attempts
                         WHERE username = :u2 AND success = 1), '1970-01-01 00:00:00')");
            $stmt->execute([':u' => $username, ':u2' => $username]);
            return ((int) $stmt->fetchColumn()) >= self::MAX_ATTEMPTS;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function logAttempt($username, $ip, $success) {
        try {
            $this->conn->prepare(
                "INSERT INTO login_attempts (username, ip, attempted_at, success)
                 VALUES (:u, :ip, NOW(), :s)")
                 ->execute([':u' => $username, ':ip' => $ip, ':s' => $success ? 1 : 0]);
        } catch (Throwable $e) {
            // Logging failure must never block authentication.
        }
    }
}
