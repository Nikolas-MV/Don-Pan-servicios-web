<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
ejecutar(function (): void {
    metodo('POST');
    verificarCsrf();
    $user = registrarUsuario(cuerpoJson());
    salida(['ok' => true, 'message' => 'Usuario registrado.', 'user' => $user], 201);
});
