# Analizador MySQL + Ollama

Aplicacion web local para leer datos desde MySQL, enviarlos a Ollama y responder preguntas sobre esa informacion.

## Requisitos

- XAMPP con Apache y MySQL activos.
- Ollama instalado y ejecutandose.
- Un modelo descargado, por ejemplo:

```powershell
ollama pull llama3.2:3b
```

## Abrir la app

Con Apache de XAMPP activo, abre:

```text
http://localhost/ollama/
```

Tambien puedes usar el servidor local de PHP desde esta carpeta:

```powershell
php -S 127.0.0.1:8088
```

Y luego abrir:

```text
http://127.0.0.1:8088/
```

## Probar con datos demo

Importa `database/demo.sql` en phpMyAdmin o con la consola de MySQL:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root < database\demo.sql
```

Despues abre la app y usa los valores por defecto:

- Base de datos: `ollama_demo`
- Usuario: `root`
- Contrasena: vacia
- SQL: `SELECT * FROM ventas LIMIT 25`
- Modelo: `llama3.2:3b`

## Usar tu propia base de datos

En la pantalla puedes cambiar host, puerto, base, usuario, contrasena, SQL, modelo y pregunta.

Si quieres dejar valores fijos, copia `config.sample.php` como `config.php` y edita ese archivo:

```powershell
Copy-Item config.sample.php config.php
```

La app solo permite consultas de lectura como `SELECT`, `WITH`, `SHOW`, `DESCRIBE` o `EXPLAIN`. Si escribes un `SELECT` sin `LIMIT`, la app agrega un limite para proteger el rendimiento.

## Ejemplos de preguntas

- Resume las ventas por region y senala oportunidades.
- Que productos generan mayor ingreso?
- Hay algun canal con baja satisfaccion?
- Que patron ves entre unidades vendidas e ingreso?
- Grafica el ingreso por region.
- Muestra una grafica de linea del ingreso por fecha.

Cuando la pregunta incluye palabras como `grafica`, `grafico`, `barras`, `linea`, `chart`, `tendencia` o `evolucion`, la app dibuja una grafica con los datos devueltos por la consulta.

## Seguir preguntando

Despues del primer analisis aparece la seccion `Seguir preguntando`. Todo lo que escribas ahi reutiliza la misma consulta y las mismas filas ya cargadas, asi puedes pedir comparaciones, explicaciones, graficas o nuevos resumenes sin volver a escribir SQL.

Ejemplos:

- Ahora compara las regiones con peor rendimiento.
- Explica por que el producto con mas ingreso destaca.
- Graficame estos datos en barras.
- Que recomendacion tomarias a partir de este resultado?
