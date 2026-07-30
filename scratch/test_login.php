<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getCobranzasConnection();
$stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = :e');
$stmt->execute([':e' => 'tesoreria@automarco.cl']);
$u = $stmt->fetch();
echo "User: " . ($u['nombre'] ?? 'Not found') . "\n";
echo "Auth test: " . (password_verify('tesoreria123', $u['password_hash'] ?? '') ? "OK" : "FAIL") . "\n";
