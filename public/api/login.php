<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
ejecutar(function (): void {
    metodo('POST');
    verificarCsrf();
    $user = autenticarUsuario(cuerpoJson());
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    // Rotar CSRF tras autenticar evita reutilizar el token de la sesion invitada.
    unset($_SESSION['csrf']);
    salida(['ok' => true, 'message' => 'Inicio de sesion satisfactorio.', 'user' => $user, 'csrf' => tokenCsrf()]);
});
