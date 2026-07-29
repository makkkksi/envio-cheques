<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getCobranzasConnection();
$stmt = $pdo->query("SHOW COLUMNS FROM bd_automarco.tbl_cobranza");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
