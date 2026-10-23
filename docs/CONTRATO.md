# Contrato HTTP de la evidencia

Base local de pruebas: `http://127.0.0.1:18827`. Todas las respuestas de endpoints son JSON con `Cache-Control: no-store`. No se habilita CORS; los clientes de navegador deben compartir origen.

| Metodo y ruta | Cuerpo | Exito |
| --- | --- | --- |
| GET /api/session.php | Ninguno | 200: ok, authenticated, user (perfil o null), csrf |
| POST /api/registro.php | nombre, usuario, password | 201: ok, message, user |
| POST /api/login.php | usuario, password | 200: ok, message, user, csrf |
| POST /api/logout.php | Objeto vacio | 200: ok, message |

Perfil publico: `{id: entero, nombre: texto, usuario: texto}`. Nunca incluye hash ni contrasena.

Errores: `{ok:false,error:codigo,message:texto}`.

| HTTP | Codigo | Significado |
| --- | --- | --- |
| 400 | json_invalido | JSON malformado, lista o valor escalar |
| 401 | credenciales | Usuario o contrasena incorrectos |
| 403 | csrf | Cookie/token ausentes, incorrectos o antiguos |
| 405 | metodo_no_permitido | Metodo distinto del requerido; cabecera Allow |
| 409 | usuario_duplicado | Nombre de usuario normalizado ya registrado |
| 413 | cuerpo_grande | Mas de 16 KiB |
| 415 | tipo_invalido | Content-Type distinto de application/json |
| 422 | validacion | Campos ausentes, extra o de tipo/longitud incorrectos |
| 503 | servidor | Configuracion, conexion o fallo interno |

Orden en POST: metodo, CSRF, formato/tamano JSON, validacion y operacion. Si hay varias causas de error se devuelve la primera; por ejemplo, sin CSRF no se procesa el contenido JSON.

Registro no autentica automaticamente. Login rota sesion/CSRF; logout exige el token vigente y destruye la sesion. GET session inicia una sesion invitada si no existe.
