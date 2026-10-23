<?php
declare(strict_types=1);

/** No hay credenciales de respaldo: una configuracion incompleta impide conectar. */
function db(): mysqli
{
    static $connection = null;
    if ($connection !== null) return $connection;
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_EXPECTED_DATADIR'] as $key) {
        if (getenv($key) === false || getenv($key) === '') throw new RuntimeException('Configuracion incompleta.');
    }
    $port = filter_var(getenv('DB_PORT'), FILTER_VALIDATE_INT);
    if (getenv('DB_HOST') !== '127.0.0.1' || !$port || $port < 1024 || $port > 65535 || $port === 3306
        || !in_array(getenv('DB_NAME'), ['sena_ga7_ev01', 'sena_ga7_ev01_pruebas'], true)) {
        throw new RuntimeException('Destino de base no autorizado.');
    }
    $expected = realpath(getenv('DB_EXPECTED_DATADIR'));
    if (!$expected || strtolower(str_replace('\\', '/', $expected)) === 'c:/xampp/mysql/data') {
        throw new RuntimeException('Directorio principal o inexistente.');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $candidate = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'), $port);
    $candidate->set_charset('utf8mb4');
    $identity = $candidate->query('SELECT @@port AS puerto, @@datadir AS directorio, DATABASE() AS base')->fetch_assoc();
    $actual = realpath($identity['directorio']);
    if ((int)$identity['puerto'] !== $port || $identity['base'] !== getenv('DB_NAME')
        || !$actual || strcasecmp($actual, $expected) !== 0) {
        $candidate->close();
        throw new RuntimeException('Identidad de instancia no coincide.');
    }
    $candidate->query("SET time_zone = '+00:00'");
    return $connection = $candidate;
}
