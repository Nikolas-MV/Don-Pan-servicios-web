# GA7-220501096-AA5-EV01 — Servicios web de registro e inicio de sesion

API educativa independiente, en PHP procedural y MariaDB, con respuestas JSON de exito y error. No depende del codigo, credenciales ni tablas de Don Pan. 

## Requisitos y estructura

- PHP 8.2 con `mysqli`, `mbstring` y sesiones; `curl` adicional para pruebas.
- MariaDB 10.4.32 o una version compatible. Las pruebas incluidas utilizan los binarios de XAMPP en Windows.
- Git para control de versiones. No requiere Composer ni React.

```text
public/api/       registro.php, login.php, session.php, logout.php
includes/         protocolo JSON, sesion y CSRF
src/              validacion y servicios de usuarios
config/           conexion con verificacion de aislamiento
sql/schema.sql    tabla usuarios; sin registros ni credenciales
tests/            pruebas HTTP e inserciones concurrentes
docs/             contrato, resultados y alcance de la evidencia
```

## Instalacion independiente

1. Preparar una instancia NUEVA de MariaDB con directorio de datos propio y un puerto diferente de 3306. 
2. Antes de crear tablas, verificar `SELECT @@port, @@datadir, VERSION();`. Ambos valores deben corresponder al laboratorio.
3. Crear en esa instancia una base vacia `sena_ga7_ev01`, con `utf8mb4`. Seleccionarla explicitamente e importar `sql/schema.sql`. El archivo no contiene `USE`, `CREATE DATABASE`, `DROP` ni semillas.
4. Crear una cuenta de aplicacion exclusiva en la instancia aislada, con `SELECT` e `INSERT` sobre esa base. Generar su secreto localmente. No usar una cuenta de Don Pan.
5. Definir las variables del proceso indicadas abajo. `.env.example` es solo una referencia y no se carga automaticamente.
6. Servir exclusivamente `public/`, por ejemplo desde la raiz del proyecto:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:18827 -t public
```

Variables obligatorias: `DB_HOST=127.0.0.1`, `DB_PORT` (diferente de 3306), `DB_NAME=sena_ga7_ev01`, `DB_USER`, `DB_PASS`, `DB_EXPECTED_DATADIR` (directorio real de la instancia aislada). `SESSION_SAVE_PATH` permite definir una carpeta privada de sesiones fuera de `public/`.

Establecer secretos mediante el entorno del proceso, sin incorporarlos a archivos versionados ni a comandos guardados en historial. La aplicacion no tiene contrasenas por defecto. La conexion falla si faltan variables, se solicita 3306 o no coinciden puerto, base y directorio de datos. Los valores `sena_ga7_ev01` y `sena_ga7_ev01_pruebas` son los unicos nombres de base admitidos deliberadamente.

## Flujo de uso

1. `GET /api/session.php`: conservar la cookie `SENA_GA7_SESSID` y el campo `csrf`.
2. `POST /api/registro.php`: JSON con `nombre`, `usuario`, `password`, cookie y cabecera `X-CSRF-Token`. Devuelve 201; el registro no inicia sesion automaticamente.
3. `POST /api/login.php`: JSON con `usuario`, `password`, misma cookie y CSRF. Devuelve 200 y rota cookie y CSRF: conservar los valores nuevos.
4. `GET /api/session.php`: consultar el perfil autenticado.
5. `POST /api/logout.php`: enviar el objeto JSON vacio `{}`, la cookie y el CSRF nuevo. Devuelve 200 y elimina la sesion.

Todos los POST requieren `Content-Type: application/json`. Usar un cliente con manejo de cookies (Postman o el script de pruebas). En Postman guardar secretos en variables locales no exportadas. No guardar cookies ni tokens reales en capturas o colecciones.

Respuesta de registro, con datos ficticios:

```json
{"ok":true,"message":"Usuario registrado.","user":{"id":1,"nombre":"Aprendiz ficticio","usuario":"demo_aprendiz"}}
```

Error de acceso:

```json
{"ok":false,"error":"credenciales","message":"Usuario o contrasena incorrectos."}
```

## Validaciones y seguridad

- Usuario normalizado a minusculas, entre 3 y 50 caracteres ASCII: letras, numeros, punto, guion y guion bajo. Indice unico; los duplicados simultaneos generan 409.
- Nombre entre 1 y 100 caracteres Unicode, sin controles.
- Contrasena de registro: al menos 12 caracteres y maximo 72 bytes (bcrypt); no se recorta y no admite bytes nulos. Al autenticar se permite comprobar contrasenas de cualquier longitud no vacia dentro del limite de bytes.
- Consultas preparadas, `password_hash(PASSWORD_BCRYPT)` y `password_verify`.
- Mensaje generico para cuenta inexistente o contrasena incorrecta. Verificacion ficticia cuando no existe el usuario; no se promete igualdad exacta de tiempos.
- Cookies HttpOnly/SameSite=Lax, Secure cuando se sirve por HTTPS, regeneracion de sesion y CSRF tras login. No se confia en cabeceras de proxy no configuradas.
- JSON limitado a 16 KiB, rechazo de listas y campos adicionales. Los fallos internos devuelven 503 sin detalles SQL.
- No se publican configuracion, SQL, pruebas o logs: el document root debe ser `public/`.

Alcance educativo: no incluye recuperacion de contrasena, roles administrativos, limitador persistente de intentos, MFA, panel visual ni despliegue productivo. Para exposicion publica se requiere HTTPS y controles adicionales de abuso. El conflicto 409 de registro permite conocer la disponibilidad de un identificador; no usar correos u otros identificadores sensibles sin revisar esa politica.

## Pruebas reproducibles

Desde la raiz del proyecto, en Windows/XAMPP:

```powershell
C:\xampp\php\php.exe tests/ejecutar.php
```

El ejecutor comprueba puertos **13327** (MariaDB) y **18827** (HTTP), crea un directorio nuevo `.runtime/FECHA_ALEATORIA`, inicializa un sistema MariaDB desde cero y verifica su identidad ANTES de crear `sena_ga7_ev01_pruebas` o aplicar el esquema. Si no se garantiza aislamiento, se detiene. No utiliza la instancia principal ni la tabla `mysql.db` danada.

Genera secretos solo en memoria, utiliza una cuenta API de permisos limitados y registra exclusivamente datos ficticios. Comprueba respuestas HTTP, validaciones, cookie/CSRF, almacenamiento con hash, login, logout, guard de puerto y ocho registros concurrentes del mismo usuario. La concurrencia ejecuta el mismo servicio PHP desde procesos CLI; el servidor HTTP de desarrollo es de un solo proceso.

La inicializacion de XAMPP incorpora la base `test` y permisos anonimos predeterminados. Despues de verificar la identidad, el ejecutor elimina esas cuentas y la base test, retira entradas anonimas residuales de las tablas de permisos NUEVAS y recarga los privilegios de esa instancia aislada. No son operaciones de reparacion ni se aplican al MariaDB principal.

Al terminar detiene exclusivamente los procesos que ha creado y escribe `docs/resultados-pruebas.json`, sin secretos. Los directorios `.runtime/` quedan conservados y excluidos de Git; contienen datos ficticios y archivos internos del laboratorio. Cada ejecucion crea un laboratorio nuevo. No se eliminan ni se reusan automaticamente.

Para sintaxis, comprobar todos los archivos PHP con `php -l`. Los resultados reales se documentan en `docs/RESULTADOS.md`; distinguirlos de los escenarios solo propuestos.

## Git y entrega

Repositorio inicializado sin commits ni remotos. Revisar `git status` y `.gitignore` antes de autorizar un primer commit. No publicar `.runtime`, sesiones, contrasenas, tokens, archivos `.env`, respaldos ni bases fisicas.

Entrega propuesta: codigo PHP, `schema.sql`, README, contrato y resultados de pruebas. Agregar guia/lista de chequeo, capturas con datos ficticios, archivo comprimido o enlace del repositorio solo cuando el instrumento exacto confirme sus requisitos y se autorice su publicacion.
