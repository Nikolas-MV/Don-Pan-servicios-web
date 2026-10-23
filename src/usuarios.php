<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

/** Error de negocio seguro: nunca contiene contrasenas ni SQL. */
class ApiException extends RuntimeException
{
    public function __construct(public int $status, public string $error, string $message)
    {
        parent::__construct($message);
    }
}

function validarAcceso(array $data, bool $registro = false): array
{
    $allowed = $registro ? ['nombre', 'usuario', 'password'] : ['usuario', 'password'];
    if (array_diff(array_keys($data), $allowed)) throw new ApiException(422, 'validacion', 'Campos no permitidos.');
    foreach ($allowed as $field) {
        if (!isset($data[$field]) || !is_string($data[$field])) throw new ApiException(422, 'validacion', 'Campos obligatorios ausentes o de tipo incorrecto.');
    }
    $usuario = strtolower(trim($data['usuario']));
    if (!preg_match('/\A[a-z0-9_.-]{3,50}\z/D', $usuario)) throw new ApiException(422, 'validacion', 'El usuario debe tener entre 3 y 50 caracteres: letras, numeros, punto, guion o guion bajo.');
    // No recortar la contrasena: sus espacios forman parte del secreto.
    $password = $data['password'];
    if (!mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")
        || strlen($password) > 72 || $password === '' || ($registro && mb_strlen($password, 'UTF-8') < 12)) {
        throw new ApiException(422, 'validacion', 'Contrasena invalida: en registro requiere al menos 12 caracteres y admite como maximo 72 bytes.');
    }
    $result = ['usuario' => $usuario, 'password' => $password];
    if ($registro) {
        $nombre = trim($data['nombre']);
        if (!mb_check_encoding($nombre, 'UTF-8') || mb_strlen($nombre, 'UTF-8') < 1
            || mb_strlen($nombre, 'UTF-8') > 100 || preg_match('/[\x00-\x1f\x7f]/u', $nombre)) {
            throw new ApiException(422, 'validacion', 'El nombre debe tener entre 1 y 100 caracteres sin controles.');
        }
        $result['nombre'] = $nombre;
    }
    return $result;
}

function perfilPublico(array $row): array
{
    return ['id' => (int)$row['id_usuario'], 'nombre' => $row['nombre'], 'usuario' => $row['usuario']];
}

function registrarUsuario(array $data): array
{
    $v = validarAcceso($data, true);
    $hash = password_hash($v['password'], PASSWORD_BCRYPT);
    $c = db();
    try {
        $q = $c->prepare('INSERT INTO usuarios (nombre, usuario, password_hash) VALUES (?, ?, ?)');
        $q->bind_param('sss', $v['nombre'], $v['usuario'], $hash);
        $q->execute();
    } catch (mysqli_sql_exception $e) {
        // El indice unico decide tambien cuando llegan registros simultaneos.
        if ($e->getCode() === 1062) throw new ApiException(409, 'usuario_duplicado', 'El usuario ya esta registrado.');
        throw $e;
    }
    return perfilPublico(['id_usuario' => $c->insert_id, 'nombre' => $v['nombre'], 'usuario' => $v['usuario']]);
}

function autenticarUsuario(array $data): array
{
    $v = validarAcceso($data);
    $q = db()->prepare('SELECT id_usuario, nombre, usuario, password_hash FROM usuarios WHERE usuario = ?');
    $q->bind_param('s', $v['usuario']);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    // Hash ficticio generado solo en memoria; no se distribuyen hashes de prueba.
    static $dummy = null;
    $dummy ??= password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
    $correct = password_verify($v['password'], $row['password_hash'] ?? $dummy);
    if (!$row || !$correct) throw new ApiException(401, 'credenciales', 'Usuario o contrasena incorrectos.');
    return perfilPublico($row);
}
