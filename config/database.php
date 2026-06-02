<?php
require_once __DIR__ . '/env.php';

/**
 * Database connection. Credentials are read from .env (DB_HOST / DB_NAME /
 * DB_USER / DB_PASS) so the same code runs locally and on cPanel without
 * editing PHP — just set the values in .env on the server. Falls back to the
 * local XAMPP defaults when those keys are absent.
 */
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        $this->host     = env('DB_HOST', 'localhost');
        $this->db_name  = env('DB_NAME', 'invoice_app');
        $this->username = env('DB_USER', 'root');
        $this->password = env('DB_PASS', '');
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                                $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        return $this->conn;
    }
}
?>
