<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once __DIR__ . '/../config/db.php';

echo "=== TEST ADMIN API GET_COBRANZAS ===\n";
$_GET['busqueda'] = '022048'; // Búsqueda por número de factura pivote

ob_start();
require __DIR__ . '/../admin/api/get_cobranzas.php';
$output = ob_get_clean();

echo "Respuesta get_cobranzas.php:\n";
echo $output . "\n\n";

echo "=== TEST ADMIN API GET_DETALLE_COBRANZA ===\n";
$_GET['id'] = 12;

ob_start();
require __DIR__ . '/../admin/api/get_detalle_cobranza.php';
$outputDetalle = ob_get_clean();

echo "Respuesta get_detalle_cobranza.php:\n";
echo $outputDetalle . "\n";
