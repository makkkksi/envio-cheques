<?php
/**
 * GoogleSheetsService.php
 * 
 * Servicio ligero (sin SDK / sin Composer) para integrar Google Sheets API v4.
 * Genera JWT con openssl_sign, obtiene access_token vía cURL y realiza POST :append.
 */

require_once __DIR__ . '/../config/app.php';

class GoogleSheetsService
{
    private static $cachedToken = null;
    private static $tokenExpiresAt = 0;

    /**
     * Genera un JWT y obtiene un Access Token de la API de Google OAuth2.
     */
    public static function getAccessToken(): ?string
    {
        if (self::$cachedToken && time() < (self::$tokenExpiresAt - 60)) {
            return self::$cachedToken;
        }

        if (!function_exists('openssl_sign')) {
            error_log('[GoogleSheetsService] La extensión PHP openssl no está habilitada en php.ini. Omitiendo integración con Google Sheets.');
            return null;
        }

        $credFile = defined('GOOGLE_SHEETS_CREDENTIALS') ? GOOGLE_SHEETS_CREDENTIALS : null;
        if (!$credFile || !file_exists($credFile)) {
            error_log('[GoogleSheetsService] Archivo de credenciales no encontrado: ' . $credFile);
            return null;
        }

        $keyData = json_decode(file_get_contents($credFile), true);
        if (!$keyData || empty($keyData['private_key']) || empty($keyData['client_email'])) {
            error_log('[GoogleSheetsService] Archivo de credenciales JSON inválido.');
            return null;
        }

        $privateKey = $keyData['private_key'];
        $clientEmail = $keyData['client_email'];
        $scope = "https://www.googleapis.com/auth/spreadsheets";

        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $now = time();
        $claim = json_encode([
            "iss" => $clientEmail,
            "sub" => $clientEmail,
            "scope" => $scope,
            "aud" => "https://oauth2.googleapis.com/token",
            "exp" => $now + 3600,
            "iat" => $now,
        ]);

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlClaim = self::base64UrlEncode($claim);

        $signature = '';
        $success = openssl_sign("$base64UrlHeader.$base64UrlClaim", $signature, $privateKey, 'SHA256');
        if (!$success) {
            error_log('[GoogleSheetsService] Error al firmar JWT con openssl_sign.');
            return null;
        }

        $base64UrlSignature = self::base64UrlEncode($signature);
        $jwt = "$base64UrlHeader.$base64UrlClaim.$base64UrlSignature";

        $ch = curl_init("https://oauth2.googleapis.com/token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
            "assertion" => $jwt
        ]));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $responseStr = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$responseStr) {
            error_log("[GoogleSheetsService] Error solicitando access_token (HTTP $httpCode): " . $responseStr);
            return null;
        }

        $responseData = json_decode($responseStr, true);
        if (empty($responseData['access_token'])) {
            error_log('[GoogleSheetsService] No se recibió access_token.');
            return null;
        }

        self::$cachedToken = $responseData['access_token'];
        self::$tokenExpiresAt = time() + ($responseData['expires_in'] ?? 3600);

        return self::$cachedToken;
    }

    /**
     * Inserta filas al Spreadsheet mediante POST :append.
     */
    public static function appendRows(array $rows, string $spreadsheetId): bool
    {
        $accessToken = self::getAccessToken();
        if (!$accessToken) {
            error_log('[GoogleSheetsService] No se pudo obtener access token para appendRows.');
            return false;
        }

        $baseRange = defined('GOOGLE_SHEETS_RANGE') ? GOOGLE_SHEETS_RANGE : 'A:K';

        // Si el rango no contiene "!", anteponer el año actual (ej: "2026!A:K")
        $currentYear = date('Y');
        if (strpos($baseRange, '!') === false) {
            $range = $currentYear . '!' . $baseRange;
        } else {
            $range = $baseRange;
        }

        if (empty($spreadsheetId)) {
            error_log('[GoogleSheetsService] spreadsheetId no proporcionado.');
            return false;
        }

        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/" . urlencode($range) . ":append?valueInputOption=USER_ENTERED";

        $payload = json_encode([
            "values" => $rows
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$accessToken}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $responseStr = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("[GoogleSheetsService] Error al agregar filas (HTTP $httpCode): " . $responseStr);
            return false;
        }

        return true;
    }

    /**
     * Auxiliar para codificar en Base64Url sin relleno '=' y con caracteres seguros.
     */
    private static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
