<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

try {
    $pdo = Database::getCobranzasConnection();
    checkRateLimit($pdo, 'sistema@app.local');
    echo "Check rate limit succeeded.\n";
} catch (Exception $e) {
    echo "CAPTURED EXCEPTION: " . $e->getMessage() . "\n";
}
