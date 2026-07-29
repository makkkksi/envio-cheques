<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getCobranzasConnection();

echo "=== COBRANZA CABECERA ===\n";
print_r($pdo->query("SELECT * FROM cobranzas WHERE id = 10")->fetch(PDO::FETCH_ASSOC));

echo "\n=== COBRANZA FACTURAS (PIVOT) ===\n";
print_r($pdo->query("SELECT * FROM cobranza_facturas WHERE cobranza_id = 10")->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== CHEQUES ===\n";
print_r($pdo->query("SELECT * FROM cheques WHERE cobranza_id = 10")->fetchAll(PDO::FETCH_ASSOC));
