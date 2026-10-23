# Resultados de pruebas

Fecha UTC: 2026-10-23T03:36:37+00:00

Resultado: **CORRECTO**. Comprobaciones satisfactorias: 48.

MariaDB 10.4.32-MariaDB, puerto 13327, directorio nuevo bajo .runtime/. Base sena_ga7_ev01_pruebas y HTTP 18827. Identidad comprobada antes de crear tablas.

- extensiones PHP requeridas
- puertos aislados disponibles
- proyecto fuera de Don Pan
- sistema MariaDB nuevo inicializado sin copiar mysql principal
- identidad verificada antes de crear base o tablas
- sin acceso a MariaDB principal
- laboratorio sin cuentas ni permisos anonimos predeterminados
- cuenta API sin visibilidad de otras bases
- cuenta API con SELECT e INSERT exclusivamente
- esquema sin datos precargados
- guard rechaza 3306 antes de conectar
- sesion invitada y token CSRF
- cookie exclusiva HttpOnly SameSite=Lax
- registro satisfactorio y normalizacion
- respuesta no expone credenciales
- hash bcrypt almacenado y verificable
- duplicado controlado
- validacion de registro 1
- validacion de registro 2
- validacion de registro 3
- validacion de registro 4
- validacion de registro 5
- validacion de registro 6
- validacion de registro 7
- validacion de registro 8
- metodo incorrecto devuelve 405
- CSRF ausente devuelve 403
- JSON malformado devuelve 400
- JSON lista rechazado
- tipo de contenido devuelve 415
- cuerpo excesivo devuelve 413
- login incorrecto e inexistente: error generico
- login satisfactorio
- rotacion de sesion y CSRF al autenticar
- sesion autenticada recuperable
- token anterior rechazado
- logout satisfactorio
- sesion cerrada no permanece autenticada
- worker concurrente sin errores internos
- worker concurrente sin errores internos
- worker concurrente sin errores internos
- worker concurrente sin errores internos
- worker concurrente sin errores internos
- worker concurrente sin errores internos
- worker concurrente sin errores internos
- worker concurrente sin errores internos
- ocho registros simultaneos: uno creado y siete duplicados
- solo dos usuarios ficticios al finalizar

Los procesos aislados se detuvieron al finalizar. No se uso el servidor principal ni su tabla mysql.db. El arranque de XAMPP incluye permisos anonimos para test/test_%: el ejecutor los retira solo del sistema nuevo despues de verificar su identidad. Dos ejecuciones previas fallaron en esa comprobacion de permisos y se detuvieron; sus directorios runtime se conservaron.

Las pruebas HTTP son reales. La concurrencia se comprueba mediante procesos CLI que invocan el mismo servicio de registro. No se prueban carga de produccion, HTTPS, navegador ni limitacion persistente de intentos. No se realizaron commits ni publicaciones.
