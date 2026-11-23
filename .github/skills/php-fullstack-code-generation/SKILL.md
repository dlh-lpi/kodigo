---
name: php-fullstack-code-generation
description: Use when creating, refactoring, extending, or fixing PHP, MySQL/MariaDB, JavaScript, HTML, or CSS code in this repository.
---

# Generacion de codigo PHP full stack

Aplica esta skill al implementar cambios de codigo en este repositorio. Prioriza soluciones completas, seguras, faciles de probar y consistentes con los patrones existentes.

## Flujo de trabajo

1. Lee los archivos relacionados, la configuracion y las pruebas antes de editar. Busca helpers y patrones existentes para reutilizarlos.
2. Resume el enfoque y la estructura propuesta cuando el cambio afecte varias responsabilidades o archivos. Para cambios pequenos, implementa directamente.
3. Haz cambios acotados al requisito. No agregues capas, dependencias, archivos ni configuracion innecesarios.
4. Actualiza la documentacion directamente relacionada y valida el comportamiento con las herramientas existentes.
5. Informa brevemente que cambiaste, que pruebas ejecutaste y cualquier limitacion que no hayas podido validar.

## Estilo y arquitectura

- Usa PHP 8+, `declare(strict_types=1);` en archivos PHP nuevos y PSR-12.
- Usa nombres descriptivos en ingles para clases, metodos y variables; evita nombres como `$a`, `$b` o `$data` cuando exista una opcion mas precisa.
- Aplica Clean Code y SOLID de forma pragmatica: responsabilidades claras, metodos pequenos y tipos explicitos, sin sobrearquitectura.
- Conserva las convenciones del proyecto. No incorpores Composer, frameworks ni paquetes sin necesidad justificada.
- Usa JavaScript ES6+, HTML5 semantico y CSS existente; conserva accesibilidad y comportamiento adaptable.
- Escribe comentarios solo para aclarar decisiones o logica no obvia.

## Seguridad y manejo de datos

- Valida y normaliza entradas en el servidor; no confies en las restricciones del navegador.
- En PDO, usa consultas preparadas para valores. Los identificadores SQL no se parametrizan: deben validarse contra una lista permitida y citarse de acuerdo con el motor.
- No concatentes datos no confiables en SQL. No debilites `SqlQueryGuard` ni el modo de solo lectura usado por el agente.
- Usa cuentas de base de datos con privilegios minimos. No incluyas credenciales reales en codigo, logs, HTML ni respuestas.
- Escapa texto al renderizar HTML. Para datos dentro de JavaScript, serializa con `json_encode` y flags `JSON_HEX_*` apropiados; no insertes HTML no confiable como markup.
- Maneja errores de forma explicita. Evita capturas amplias que oculten fallas, valores por defecto que aparenten exito y silencios ante entradas invalidas.
- Para respuestas del agente, usa solo el esquema disponible y las filas leidas; comunica limites o datos faltantes en vez de inventar resultados.
- No agregues sesiones ni historial de usuario: cada pregunta de la aplicacion es independiente.

## Pruebas y documentacion

- Agrega pruebas enfocadas para comportamientos nuevos o corregidos, preferiblemente junto a las pruebas existentes.
- Ejecuta `php -l` en los archivos PHP modificados y `php tests\run.php` para las pruebas actuales cuando correspondan.
- Prueba el requisito real, incluidos rechazos de entradas invalidas y limites relevantes.
- Actualiza `README.md` cuando cambien instalacion, configuracion, uso o limites del agente.
