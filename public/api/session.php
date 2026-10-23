<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
ejecutar(function (): void {
    metodo('GET');
    salida(['ok' => true, 'authenticated' => isset($_SESSION['user']), 'user' => $_SESSION['user'] ?? null, 'csrf' => tokenCsrf()]);
});
