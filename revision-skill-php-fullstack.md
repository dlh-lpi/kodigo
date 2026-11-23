## Revisión con la skill PHP Full Stack

**Puntuación: 65/100** · **Riesgo global: alto si la app queda accesible desde otros equipos.**

### Hallazgos principales

1. **Alto — Credenciales predeterminadas y consultas sin restricción de esquema.** Si falta `config.php`, [index.php:10](./index.php:10) usa `root` sin contraseña. Aunque [SqlQueryGuard.php:21](./src/SqlQueryGuard.php:21) bloquea ciertos comandos, no limita las tablas consultables. Verifiqué que acepta `SELECT user, host FROM mysql.user LIMIT 50`. Combinado con la falta de autenticación de la aplicación, esto expone datos si el servidor es accesible y la cuenta tiene permisos. El ejemplo de configuración recomienda `data_reader`, pero ese usuario no es el fallback del código.

2. **Alto — La instrucción “no inventar” no se puede garantizar solo con el prompt.** [ChatAgent.php:94](./src/ChatAgent.php:94) le pide al modelo ceñirse a las filas, pero no verifica sus afirmaciones. Además, el filtro permite consultas como `SELECT 1`, así que el modelo puede producir resultados constantes que no provienen de tablas. Hace falta restringir las consultas al esquema autorizado y validar o calcular de forma determinista los datos numéricos importantes.

3. **Medio — Falso aviso de resultados truncados.** [Database.php:121](./src/Database.php:121) marca el resultado como truncado cuando hay exactamente el máximo de filas, incluso si la consulta devolvió todas las filas existentes. Puede mostrar una advertencia incorrecta y hacer que el modelo describa el conjunto como incompleto.

4. **Medio — El límite de filas no limita el coste de la consulta.** Un `LIMIT` restringe cuántas filas se devuelven, pero no evita joins o agregaciones costosas. No vi un límite de tiempo para la ejecución SQL. Conviene agregar controles de duración y mantener los permisos de la cuenta lo más acotados posible.

5. **Bajo — Selección heurística de columnas para gráficas.** [ChartBuilder.php:67](./src/ChartBuilder.php:67) elige una métrica por coincidencias parciales y la primera columna categórica disponible. En resultados con varios campos numéricos o categorías, puede graficar una dimensión distinta de la solicitada.

### Aspectos positivos

- El HTML escapa las salidas y los datos de las gráficas se serializan con flags `JSON_HEX_*`.
- Se separaron responsabilidades entre base de datos, Ollama, agente, gráficas y validación SQL.
- La conexión usa una transacción de solo lectura y no se conserva historial de usuario.
- Las pruebas actuales y `php -l` pasan.

La cobertura probada es acotada: [tests/run.php](./tests/run.php) cubre el filtro SQL y un caso básico de gráfica, pero no una integración con MySQL/Ollama ni la autorización de tablas.
