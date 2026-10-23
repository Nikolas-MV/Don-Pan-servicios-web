<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/usuarios.php';

// La cookie es exclusiva de esta evidencia; el document root sera public/.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
$sessionPath = getenv('SESSION_SAVE_PATH');
if ($sessionPath && is_dir($sessionPath)) session_save_path($sessionPath);
session_name('SENA_GA7_SESSID');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function salida(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function ejecutar(callable $handler): never
{
    try {
        $handler();
        throw new RuntimeException('El endpoint no emitio respuesta.');
    } catch (ApiException $e) {
        salida(['ok' => false, 'error' => $e->error, 'message' => $e->getMessage()], $e->status);
    } catch (Throwable $e) {
        // Solo clase/codigo en logs; nunca peticiones, SQL, contrasenas o perfiles.
        error_log('[sena-api] ' . get_class($e) . ' codigo=' . $e->getCode());
        salida(['ok' => false, 'error' => 'servidor', 'message' => 'Servicio temporalmente no disponible.'], 503);
    }
}

function metodo(string $expected): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $expected) {
        header('Allow: ' . $expected);
        throw new ApiException(405, 'metodo_no_permitido', 'Metodo no permitido.');
    }
}

function cuerpoJson(): array
{
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($type !== 'application/json') throw new ApiException(415, 'tipo_invalido', 'Se requiere application/json.');
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if ($raw === false || strlen($raw) > 16384) throw new ApiException(413, 'cuerpo_grande', 'Cuerpo demasiado grande.');
    try { $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new ApiException(400, 'json_invalido', 'JSON invalido.'); }
    if (!$object instanceof stdClass) throw new ApiException(400, 'json_invalido', 'El cuerpo debe ser un objeto JSON.');
    return (array)$object;
}

function tokenCsrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verificarCsrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf'] ?? '';
    if ($sent === '' || $expected === '' || !hash_equals($expected, $sent)) throw new ApiException(403, 'csrf', 'Token CSRF invalido.');
}
