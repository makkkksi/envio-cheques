<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getCobranzasConnection();
$stmt = $pdo->query("SELECT cli_rut, cli_razon_social, cli_mail FROM autotec_ecom.tbl_clientes LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
