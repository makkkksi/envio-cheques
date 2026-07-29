<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getCobranzasConnection();

$stmt = $pdo->query("SELECT * FROM autotec_ecom.tbl_vendedores WHERE cli_vendedor = 2");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
