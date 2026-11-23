# Agente de datos MySQL + Ollama

Aplicacion PHP local para consultar una base de datos MySQL o MariaDB en lenguaje natural. El agente lee el esquema, solicita a Ollama una consulta `SELECT`, la valida, la ejecuta dentro de una transaccion de solo lectura y redacta una respuesta a partir de las filas obtenidas. Si se pide una grafica, esta se construye en el navegador usando esas mismas filas.

No se guardan historial ni sesiones de usuario. Cada pregunta es una consulta independiente.

## Requisitos

- PHP 8.1 o superior con `PDO`, `pdo_mysql`, `curl` y `mbstring`.
- MySQL o MariaDB accesible desde el servidor PHP.
- Ollama activo y un modelo instalado, por ejemplo:

```powershell
ollama pull llama3.2:3b
```

## Configuracion

Copia `config.sample.php` a `config.php` y ajusta la conexion:

```powershell
Copy-Item config.sample.php config.php
```

Usa una cuenta MySQL dedicada con permisos unicamente de lectura. Por ejemplo, sustituye el host y la clave por valores adecuados para tu entorno:

```sql
CREATE USER 'data_reader'@'127.0.0.1' IDENTIFIED BY 'CAMBIA_ESTA_CLAVE';
GRANT SELECT, SHOW VIEW ON ollama_demo.* TO 'data_reader'@'127.0.0.1';
```

Cambia `CAMBIA_ESTA_CLAVE` en ambos lugares. Si MySQL esta en otro host, ajusta el host permitido en la cuenta. No uses una cuenta administradora como `root`. La validacion de la aplicacion es una proteccion adicional, no reemplaza los permisos de la base de datos.

## Ejecutar localmente

Activa MySQL y Apache con XAMPP, o inicia el servidor integrado desde esta carpeta:

```powershell
php -S 127.0.0.1:8088
```

Abre `http://127.0.0.1:8088/`.

## Datos de demostracion

Importa `database/demo.sql` con phpMyAdmin o desde PowerShell:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root < database\demo.sql
```

En `config.php`, utiliza `ollama_demo` como base de datos y crea la cuenta de solo lectura descrita arriba.

## Ejemplos

- Resume los ingresos por region.
- Que productos vendieron mas unidades?
- Compara la satisfaccion promedio por canal.
- Grafica el ingreso por region en barras.
- Muestra la tendencia mensual de ventas en una grafica de linea.

La consulta SQL y una vista previa de los resultados aparecen despues de cada respuesta. El maximo de filas se configura en `config.php` y nunca puede superar 500. Los datos de conexion no se envian desde el navegador: se administran en el archivo de configuracion del servidor.

## Limites y privacidad

- El modelo solo recibe metadatos de tablas y columnas para generar SQL; los datos se envian para analizarlos despues de ejecutar la consulta.
- La respuesta y la grafica se basan en las filas obtenidas. Si no hay filas, la aplicacion no solicita una interpretacion al modelo.
- Solo se acepta una consulta `SELECT`; se bloquean instrucciones de escritura, comentarios, multiples sentencias y funciones de bloqueo conocidas.
- Las consultas se ejecutan dentro de una transaccion MySQL de solo lectura y los resultados se limitan.
- Los datos se envian a la instancia de Ollama indicada. Usa una instancia de confianza y no expongas la aplicacion directamente a Internet.
- La aplicacion no mantiene historial de preguntas entre envios.

## Pruebas

Ejecuta las pruebas de las reglas SQL con:

```powershell
php tests\run.php
```
