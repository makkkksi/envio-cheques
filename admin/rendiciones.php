<?php
/**
 * admin/rendiciones.php
 * 
 * Módulo 3: Rendición de Gastos y Viáticos — Portal Administrativo
 * Estado: En Desarrollo (Fase de Diseño y Arquitectura)
 */

require_once __DIR__ . '/../config/app.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// Redirección si no está autenticado
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$rolUsuario = $_SESSION['admin_user_rol'] ?? '';
if (!in_array($rolUsuario, ['ADMINISTRADOR', 'TESORERIA', 'SUPERVISORA_CC'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rendiciones de Gastos — Portal Tesorería</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=4">
    <link rel="stylesheet" href="css/shell.css?v=1">
    <link rel="stylesheet" href="css/modal_config_cc.css">
    <style>
        .placeholder-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            background: var(--color-bg, #f8fafc);
        }
        .placeholder-card {
            background: #ffffff;
            border: 1px solid var(--color-border, #e2e8f0);
            border-radius: 12px;
            padding: 40px;
            max-width: 600px;
            width: 100%;
            text-align: center;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .placeholder-icon {
            font-size: 3rem;
            margin-bottom: 16px;
            display: inline-block;
        }
        .placeholder-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 10px;
        }
        .placeholder-badge {
            display: inline-block;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }
        .placeholder-desc {
            color: #64748b;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .placeholder-features {
            text-align: left;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 24px;
            font-size: 0.88rem;
            color: #334155;
            line-height: 1.7;
        }
        .placeholder-features li {
            list-style: none;
            position: relative;
            padding-left: 20px;
            margin-bottom: 6px;
        }
        .placeholder-features li::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #2563eb;
            font-weight: bold;
        }
        .placeholder-actions {
            display: flex;
            justify-content: center;
            gap: 12px;
        }
        .btn-placeholder-primary {
            background: #1e3a8a;
            color: #ffffff;
            padding: 9px 20px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.88rem;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-placeholder-primary:hover {
            background: #1e40af;
        }
    </style>
</head>
<body>

    <div class="app-viewport">
        
        <?php 
        $CURRENT_MODULE = 'rendiciones';
        require_once __DIR__ . '/includes/app_header.php'; 
        ?>

        <main class="placeholder-container">
            <div class="placeholder-card">
                <div class="placeholder-icon">🧾</div>
                <h2 class="placeholder-title">Módulo de Rendición de Gastos y Viáticos</h2>
                <span class="placeholder-badge">Fase de Implementación SaaS</span>
                <p class="placeholder-desc">
                    Este módulo centralizará la recepción de boletas, control de presupuestos mensuales por vendedor y aprobación de excesos mediante Magic Token.
                </p>
                <div class="placeholder-features">
                    <strong>Próximas capacidades del Módulo 3:</strong>
                    <ul>
                        <li>Presupuestos mensuales parametrizables por vendedor y empresa</li>
                        <li>Subida y validación antifraude de boletas/facturas con hash único</li>
                        <li>Aprobación de excesos en 1-clic por correo sin intermediarios</li>
                        <li>Recepción auditada de comprobantes físicos en oficina central</li>
                    </ul>
                </div>
                <div class="placeholder-actions">
                    <a href="index.php" class="btn-placeholder-primary">Volver a Cheques de Cobranza</a>
                </div>
            </div>
        </main>

    </div>

    <script src="js/shared_ui.js"></script>
    <script src="js/modal_config_cc.js"></script>
    <?php include __DIR__ . '/components/modal_config_cc.php'; ?>
</body>
</html>
