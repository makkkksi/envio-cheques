<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getCobranzasConnection();

$passwords = [
    'sistema@app.local' => 'sistema123',
    'vendedor@app.local' => 'vendedor123',
    'tesoreria@app.local' => 'tesoreria123'
];

foreach ($passwords as $email => $plain) {
    $hash = password_hash($plain, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE usuarios SET password_hash = :hash WHERE email = :email');
    $stmt->execute([':hash' => $hash, ':email' => $email]);
    echo "Updated {$email} with hash: {$hash}\n";
}
