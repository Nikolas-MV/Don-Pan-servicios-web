<?php
declare(strict_types=1);
/** Pruebas Windows/XAMPP: inicializan una instancia NUEVA, nunca usan 3306. */
if (PHP_SAPI !== 'cli') exit(1);
$project = realpath(__DIR__ . '/..');
$bin = 'C:/xampp/mysql/bin';
$port = 13327;
$httpPort = 18827;
$runtime = $project . '/.runtime/' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
$database = null; $http = null; $admin = null; $workers = [];
$results = []; $failure = false; $rootPassword = null;
function check(bool $ok, string $label): void {
    global $results;
    if (!$ok) throw new RuntimeException($label);
    $results[] = $label;
    echo 'PASS ' . $label . PHP_EOL;
}
function command(array $cmd, string $cwd, ?array $env = null): array {
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env, ['bypass_shell' => true]);
    if (!is_resource($p)) throw new RuntimeException('Proceso no disponible.');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}
function available(int $port): bool {
    $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($s) fclose($s);
    return $s === false;
}
function request(CurlHandle $c, string $path, ?string $body = null, ?string $csrf = null, string $type = 'application/json', ?string $verb = null): array {
    global $httpPort;
    $headers = []; $sent = [];
    if ($body !== null) $sent[] = 'Content-Type: ' . $type;
    if ($csrf !== null) $sent[] = 'X-CSRF-Token: ' . $csrf;
    curl_setopt_array($c, [CURLOPT_URL => 'http://127.0.0.1:' . $httpPort . $path,
        CURLOPT_CUSTOMREQUEST => $verb ?? ($body === null ? 'GET' : 'POST'), CURLOPT_HTTPHEADER => $sent,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) { $headers[] = $line; return strlen($line); }]);
    if ($body !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $body);
    else curl_setopt($c, CURLOPT_POSTFIELDS, null);
    $raw = curl_exec($c);
    if ($raw === false) throw new RuntimeException('Peticion HTTP fallo.');
    return ['status' => curl_getinfo($c, CURLINFO_HTTP_CODE), 'data' => json_decode($raw, true), 'headers' => implode('', $headers), 'raw' => $raw];
}
function client(): array {
    $c = curl_init();
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10]);
    $r = request($c, '/api/session.php');
    if ($r['status'] !== 200 || empty($r['data']['csrf'])) throw new RuntimeException('Sesion inicial no disponible.');
    return [$c, $r['data']['csrf'], $r];
}
try {
    check(extension_loaded('mysqli') && extension_loaded('mbstring') && extension_loaded('curl'), 'extensiones PHP requeridas');
    check($port !== 3306 && available($port) && available($httpPort), 'puertos aislados disponibles');
    check(!str_starts_with(strtolower(str_replace('\\', '/', $project)), 'c:/xampp/htdocs/don_pan'), 'proyecto fuera de Don Pan');
    mkdir($runtime . '/sessions', 0700, true);
    $data = $runtime . '/data';
    [$code] = command([$bin . '/mysql_install_db.exe', '--datadir=' . $data, '--port=' . $port], $project);
    check($code === 0, 'sistema MariaDB nuevo inicializado sin copiar mysql principal');
    $database = proc_open([$bin . '/mysqld.exe', '--no-defaults', '--basedir=C:/xampp/mysql', '--datadir=' . $data,
        '--port=' . $port, '--bind-address=127.0.0.1', '--skip-name-resolve', '--skip-log-bin', '--general-log=0',
        '--slow-query-log=0', '--event-scheduler=OFF', '--local-infile=0', '--pid-file=' . $runtime . '/mariadb.pid',
        '--aria-log-dir-path=' . $data, '--tmpdir=' . $runtime, '--log-error=' . $runtime . '/mariadb-error.log'],
        [0 => ['pipe', 'r'], 1 => ['file', $runtime . '/db.out', 'a'], 2 => ['file', $runtime . '/db.err', 'a']], $pipes, $project, null, ['bypass_shell' => true]);
    if (!is_resource($database)) throw new RuntimeException('No se pudo iniciar MariaDB aislado.');
    fclose($pipes[0]);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    for ($i = 0; $i < 100; $i++) {
        try { $admin = @new mysqli('127.0.0.1', 'root', '', null, $port); break; }
        catch (Throwable $e) { if ($i === 99) throw new RuntimeException('MariaDB aislado no disponible.'); usleep(100000); }
    }
    $admin->set_charset('utf8mb4');
    $identity = $admin->query('SELECT @@port AS puerto, @@datadir AS directorio, VERSION() AS version')->fetch_assoc();
    check((int)$identity['puerto'] === $port && strcasecmp(realpath($identity['directorio']), realpath($data)) === 0,
        'identidad verificada antes de crear base o tablas');
    check((int)$identity['puerto'] !== 3306 && strcasecmp(realpath($data), 'C:\xampp\mysql\data') !== 0, 'sin acceso a MariaDB principal');
    $rootPassword = bin2hex(random_bytes(32));
    $admin->query("SET PASSWORD = PASSWORD('" . $admin->real_escape_string($rootPassword) . "')");
    // mysql_install_db incluye test y permisos anonimos test/test_%. Se eliminan
    // SOLO en esta instancia nueva, despues de comprobar puerto y directorio.
    // Se retiran titulares anonimos mediante DROP USER antes de limpiar
    // las entradas huerfanas que esta version conserva en el sistema nuevo.
    $anonymousPassword = bin2hex(random_bytes(32));
    $admin->query("CREATE USER IF NOT EXISTS ''@'%' IDENTIFIED BY '" . $anonymousPassword . "'");
    $anonymous = $admin->query("SELECT Host FROM mysql.user WHERE User = ''")->fetch_all(MYSQLI_NUM);
    foreach ($anonymous as [$host]) $admin->query("DROP USER ''@'" . $admin->real_escape_string($host) . "'");
    $admin->query('DROP DATABASE IF EXISTS test');
    // MariaDB 10.4 conserva las filas anonimas test/test_% aunque DROP USER
    // retire las cuentas. Limpieza de provisionamiento en mysql NUEVO y sano;
    // no es reparacion ni conexion a mysql.db del servidor principal.
    $admin->query("DELETE FROM mysql.db WHERE User = ''");
    $admin->query('FLUSH PRIVILEGES');
    check((int)$admin->query("SELECT COUNT(*) FROM mysql.db WHERE User = ''")->fetch_row()[0] === 0,
        'laboratorio sin cuentas ni permisos anonimos predeterminados');
    $admin->query('CREATE DATABASE sena_ga7_ev01_pruebas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->select_db('sena_ga7_ev01_pruebas');
    $admin->query(file_get_contents($project . '/sql/schema.sql'));
    $appPassword = bin2hex(random_bytes(32));
    $admin->query("CREATE USER 'sena_api'@'127.0.0.1' IDENTIFIED BY '" . $admin->real_escape_string($appPassword) . "'");
    $admin->query("GRANT SELECT, INSERT ON `sena\\_ga7\\_ev01\\_pruebas`.* TO 'sena_api'@'127.0.0.1'");
    $testPassword = bin2hex(random_bytes(16));
    foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => (string)$port, 'DB_NAME' => 'sena_ga7_ev01_pruebas',
        'DB_USER' => 'sena_api', 'DB_PASS' => $appPassword, 'DB_EXPECTED_DATADIR' => $data,
        'SESSION_SAVE_PATH' => $runtime . '/sessions', 'SENA_TEST_PASSWORD' => $testPassword] as $key => $value) putenv($key . '=' . $value);
    require $project . '/src/usuarios.php';
    $visible = db()->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME NOT IN ('information_schema','sena_ga7_ev01_pruebas')")->num_rows;
    check($visible === 0, 'cuenta API sin visibilidad de otras bases');
    $grants = db()->query('SHOW GRANTS FOR CURRENT_USER')->fetch_all(MYSQLI_NUM);
    check(count($grants) === 2 && str_contains($grants[1][0], 'SELECT, INSERT') && !str_contains($grants[1][0], 'GRANT OPTION'), 'cuenta API con SELECT e INSERT exclusivamente');
    check((int)db()->query('SELECT COUNT(*) FROM usuarios')->fetch_row()[0] === 0, 'esquema sin datos precargados');
    [$guardCode] = command([PHP_BINARY, '-r', 'putenv("DB_PORT=3306"); require "config/db.php"; try { db(); exit(1); } catch (RuntimeException $e) { exit(0); }'], $project);
    check($guardCode === 0, 'guard rechaza 3306 antes de conectar');
    $http = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $httpPort, '-t', $project . '/public'],
        [0 => ['pipe', 'r'], 1 => ['file', $runtime . '/http.out', 'a'], 2 => ['file', $runtime . '/http.err', 'a']], $hp, $project, null, ['bypass_shell' => true]);
    if (!is_resource($http)) throw new RuntimeException('Servidor HTTP no disponible.');
    fclose($hp[0]);
    for ($i = 0; $i < 50 && available($httpPort); $i++) usleep(100000);
    [$c, $csrf, $initial] = client();
    check($initial['data']['authenticated'] === false && $initial['data']['user'] === null, 'sesion invitada y token CSRF');
    check(str_contains($initial['headers'], 'HttpOnly') && str_contains($initial['headers'], 'SameSite=Lax'), 'cookie exclusiva HttpOnly SameSite=Lax');
    $body = ['nombre' => 'Aprendiz ficticio', 'usuario' => 'Demo_Aprendiz', 'password' => $testPassword];
    $r = request($c, '/api/registro.php', json_encode($body), $csrf);
    check($r['status'] === 201 && $r['data']['ok'] && $r['data']['user']['usuario'] === 'demo_aprendiz', 'registro satisfactorio y normalizacion');
    check(!str_contains($r['raw'], $testPassword) && !str_contains($r['raw'], 'password_hash'), 'respuesta no expone credenciales');
    $stored = db()->query("SELECT password_hash FROM usuarios WHERE usuario='demo_aprendiz'")->fetch_row()[0];
    check($stored !== $testPassword && password_verify($testPassword, $stored), 'hash bcrypt almacenado y verificable');
    $r = request($c, '/api/registro.php', json_encode($body), $csrf);
    check($r['status'] === 409 && $r['data']['error'] === 'usuario_duplicado', 'duplicado controlado');
    $invalid = [[], ['nombre' => 'X', 'usuario' => [], 'password' => $testPassword], ['nombre' => 'X', 'usuario' => 'ab', 'password' => $testPassword],
        ['nombre' => 'X', 'usuario' => 'valido', 'password' => 'corta'], ['nombre' => '', 'usuario' => 'valido', 'password' => $testPassword],
        ['nombre' => 'X', 'usuario' => 'valido', 'password' => str_repeat('x', 73)], ['nombre' => 'X', 'usuario' => 'valido', 'password' => str_repeat('ñ', 37)],
        ['nombre' => 'X', 'usuario' => 'valido', 'password' => $testPassword, 'rol' => 'admin']];
    foreach ($invalid as $i => $payload) {
        $r = request($c, '/api/registro.php', json_encode((object)$payload), $csrf);
        check($r['status'] === 422, 'validacion de registro ' . ($i + 1));
    }
    check(request($c, '/api/registro.php')['status'] === 405, 'metodo incorrecto devuelve 405');
    check(request($c, '/api/registro.php', '{}')['status'] === 403, 'CSRF ausente devuelve 403');
    check(request($c, '/api/registro.php', '{', $csrf)['status'] === 400, 'JSON malformado devuelve 400');
    check(request($c, '/api/registro.php', '[]', $csrf)['status'] === 400, 'JSON lista rechazado');
    check(request($c, '/api/registro.php', '{}', $csrf, 'text/plain')['status'] === 415, 'tipo de contenido devuelve 415');
    check(request($c, '/api/registro.php', str_repeat(' ', 16385), $csrf)['status'] === 413, 'cuerpo excesivo devuelve 413');
    $wrong = request($c, '/api/login.php', json_encode(['usuario' => 'demo_aprendiz', 'password' => bin2hex(random_bytes(16))]), $csrf);
    $absent = request($c, '/api/login.php', json_encode(['usuario' => 'no_existe', 'password' => $testPassword]), $csrf);
    check($wrong['status'] === 401 && $absent['status'] === 401 && $wrong['data'] === $absent['data'], 'login incorrecto e inexistente: error generico');
    $cookiesBefore = curl_getinfo($c, CURLINFO_COOKIELIST);
    $r = request($c, '/api/login.php', json_encode(['usuario' => 'DEMO_APRENDIZ', 'password' => $testPassword]), $csrf);
    check($r['status'] === 200 && $r['data']['ok'], 'login satisfactorio');
    $newCsrf = $r['data']['csrf'];
    check($newCsrf !== $csrf && curl_getinfo($c, CURLINFO_COOKIELIST) !== $cookiesBefore, 'rotacion de sesion y CSRF al autenticar');
    $r = request($c, '/api/session.php');
    check($r['data']['authenticated'] === true && $r['data']['user']['usuario'] === 'demo_aprendiz', 'sesion autenticada recuperable');
    check(request($c, '/api/logout.php', '{}', $csrf)['status'] === 403, 'token anterior rechazado');
    check(request($c, '/api/logout.php', '{}', $newCsrf)['status'] === 200, 'logout satisfactorio');
    check(request($c, '/api/session.php')['data']['authenticated'] === false, 'sesion cerrada no permanece autenticada');
    putenv('SENA_WORKER_START=' . (microtime(true) + 2));
    for ($i = 0; $i < 8; $i++) {
        $p = proc_open([PHP_BINARY, $project . '/tests/registro_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $wp, $project, null, ['bypass_shell' => true]);
        fclose($wp[0]); $workers[] = [$p, $wp];
    }
    $concurrent = [];
    foreach ($workers as [$p, $wp]) {
        $out = stream_get_contents($wp[1]); $err = stream_get_contents($wp[2]);
        fclose($wp[1]); fclose($wp[2]);
        check(proc_close($p) === 0 && $err === '', 'worker concurrente sin errores internos');
        $concurrent[$out] = ($concurrent[$out] ?? 0) + 1;
    }
    $workers = [];
    check(($concurrent['201'] ?? 0) === 1 && ($concurrent['409'] ?? 0) === 7 && count($concurrent) === 2, 'ocho registros simultaneos: uno creado y siete duplicados');
    check((int)db()->query('SELECT COUNT(*) FROM usuarios')->fetch_row()[0] === 2, 'solo dos usuarios ficticios al finalizar');
    $report = ['fecha_utc' => gmdate('c'), 'resultado' => 'CORRECTO', 'instancia' => $identity,
        'base' => 'sena_ga7_ev01_pruebas', 'http_puerto' => $httpPort, 'comprobaciones' => $results,
        'concurrencia' => $concurrent, 'alcance' => 'HTTP real para endpoints; ocho procesos CLI con el mismo servicio de registro para concurrencia'];
} catch (Throwable $e) {
    $failure = true;
    $message = $e instanceof mysqli_sql_exception ? 'MariaDB codigo ' . $e->getCode() : $e->getMessage();
    fwrite(STDERR, 'FAIL ' . $message . PHP_EOL);
    $report = ['fecha_utc' => gmdate('c'), 'resultado' => 'FALLO', 'error' => $message, 'comprobaciones' => $results];
} finally {
    foreach ($workers as [$p, $wp]) { if (is_resource($p)) proc_terminate($p); }
    if (is_resource($http)) { proc_terminate($http); proc_close($http); }
    if (is_resource($database)) {
        if ($admin instanceof mysqli && isset($identity) && (int)$identity['puerto'] === $port && strcasecmp(realpath($identity['directorio']), realpath($runtime . '/data')) === 0) {
            try { $admin->query('SHUTDOWN'); } catch (Throwable $e) {}
        }
        for ($i = 0; $i < 100; $i++) { if (!proc_get_status($database)['running']) break; usleep(100000); }
        if (proc_get_status($database)['running']) { proc_terminate($database); $report['apagado_forzado_aislado'] = true; }
        proc_close($database);
    }
    $report['procesos_aislados_detenidos'] = true;
    // El informe versionable no debe divulgar la ruta personal del laboratorio.
    if (isset($report['instancia'])) $report['instancia']['directorio'] = '.runtime/' . basename($runtime) . '/data/';
    file_put_contents($project . '/docs/resultados-pruebas.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    $summary = "# Resultados de pruebas\n\nFecha UTC: " . $report['fecha_utc'] . "\n\nResultado: **" . $report['resultado'] . "**. Comprobaciones satisfactorias: " . count($results) . ".\n\n";
    if (isset($report['instancia'])) $summary .= "MariaDB " . $report['instancia']['version'] . ", puerto 13327, directorio nuevo bajo .runtime/. Base sena_ga7_ev01_pruebas y HTTP 18827. Identidad comprobada antes de crear tablas.\n\n";
    foreach ($results as $label) $summary .= '- ' . $label . "\n";
    $summary .= "\nLos procesos aislados se detuvieron al finalizar. No se uso el servidor principal ni su tabla mysql.db. El arranque de XAMPP incluye permisos anonimos para test/test_%: el ejecutor los retira solo del sistema nuevo despues de verificar su identidad. Dos ejecuciones previas fallaron en esa comprobacion de permisos y se detuvieron; sus directorios runtime se conservaron.\n\nLas pruebas HTTP son reales. La concurrencia se comprueba mediante procesos CLI que invocan el mismo servicio de registro. No se prueban carga de produccion, HTTPS, navegador ni limitacion persistente de intentos. No se realizaron commits ni publicaciones.\n";
    if ($failure) $summary .= "\nError: " . $report['error'] . "\n";
    file_put_contents($project . '/docs/RESULTADOS.md', $summary);
}
echo 'TOTAL: ' . count($results) . ' comprobaciones; ' . ($failure ? 'FALLO' : 'CORRECTO') . PHP_EOL;
exit($failure ? 1 : 0);
