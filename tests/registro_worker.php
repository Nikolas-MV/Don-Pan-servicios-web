<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../src/usuarios.php';
try {
    db();
    $start = (float)getenv('SENA_WORKER_START');
    while (microtime(true) < $start) usleep(1000);
    registrarUsuario(['nombre' => 'Aprendiz ficticio concurrente', 'usuario' => 'demo_concurrente', 'password' => getenv('SENA_TEST_PASSWORD')]);
    echo '201';
} catch (ApiException $e) { echo $e->status; }
catch (Throwable $e) { echo 'ERROR'; exit(1); }
