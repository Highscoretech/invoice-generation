<?php
require_once 'config/database.php';

// New password for accountant
$new_password = 'acc123';
$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

echo "Generated hash for password 'acc123': " . $hashed_password . "\n\n";
echo "SQL Command to update accountant password:\n";
echo "UPDATE users SET password = '" . $hashed_password . "' WHERE username = 'accountant';\n\n";

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Update the accountant's password
    $query = "UPDATE users SET password = :password WHERE username = 'accountant'";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':password', $hashed_password);
    
    if ($stmt->execute()) {
        echo "✓ Password updated successfully for accountant user.\n";
        echo "✓ New password: acc123\n";
        echo "✓ The accountant can now login with username 'accountant' and password 'acc123'\n";
    } else {
        echo "✗ Error updating password.\n";
    }
} catch (Exception $e) {
    echo "✗ Database Error: " . $e->getMessage() . "\n";
    echo "\nIf you see this error, you can manually run the SQL command above in your MySQL database.\n";
}
?>