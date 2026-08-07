<?php
/**
 * services/AuditService.php
 * 
 * Servicio de Auditoría Transaccional para registrar acciones críticas de Tesorería.
 */

class AuditService {
    /**
     * Registra una acción crítica en la tabla audit_logs.
     */
    public static function log(PDO $pdo, int $userId, string $email, string $accion, string $detalles): void {
        $ip = getClientIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (usuario_id, email, accion, detalles, ip_address, user_agent) 
            VALUES (:uid, :email, :accion, :detalles, :ip, :ua)
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':email' => $email,
            ':accion' => $accion,
            ':detalles' => $detalles,
            ':ip' => $ip,
            ':ua' => $ua
        ]);
    }
}
