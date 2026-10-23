<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
ejecutar(function (): void {
    metodo('POST');
    verificarCsrf();
    $body = cuerpoJson();
    if ($body !== []) throw new ApiException(422, 'validacion', 'El cierre de sesion requiere un objeto vacio.');
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'],
        'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
    salida(['ok' => true, 'message' => 'Sesion cerrada.']);
});
