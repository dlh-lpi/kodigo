<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/SqlQueryGuard.php';
require_once __DIR__ . '/../src/ChartBuilder.php';

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nEsperado: " . var_export($expected, true) . "\nObtenido: " . var_export($actual, true));
    }
}

function expectRejected(string $sql, string $message): void
{
    try {
        SqlQueryGuard::applyRowLimit($sql, 50);
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

expectSame('SELECT 1 LIMIT 50', SqlQueryGuard::applyRowLimit('SELECT 1', 50), 'Agrega el limite predeterminado.');
expectSame('SELECT 1 LIMIT 50', SqlQueryGuard::applyRowLimit('SELECT 1;', 50), 'Permite un terminador SQL final.');
expectSame(
    'SELECT * FROM ventas LIMIT 50',
    SqlQueryGuard::applyRowLimit('SELECT * FROM ventas LIMIT 999', 50),
    'Reduce los LIMIT mayores al maximo.'
);
expectSame(
    'SELECT * FROM ventas LIMIT 10, 50',
    SqlQueryGuard::applyRowLimit('SELECT * FROM ventas LIMIT 10, 999', 50),
    'Reduce el conteo del LIMIT con desplazamiento.'
);
expectSame(
    'SELECT * FROM ventas LIMIT 10 OFFSET 20',
    SqlQueryGuard::applyRowLimit('SELECT * FROM ventas LIMIT 10 OFFSET 20', 50),
    'Conserva el desplazamiento del LIMIT.'
);
expectSame('SELECT 1 LIMIT 501', SqlQueryGuard::applyRowLimit('SELECT 1', 501), 'Permite una fila centinela para detectar truncamiento.');
expectSame(
    "SELECT 'update drop into' AS nota LIMIT 50",
    SqlQueryGuard::applyRowLimit("SELECT 'update drop into' AS nota", 50),
    'Permite palabras reservadas dentro de literales.'
);
expectSame(
    'WITH ventas_mes AS (SELECT fecha FROM ventas) SELECT fecha FROM ventas_mes LIMIT 50',
    SqlQueryGuard::applyRowLimit(
        'WITH ventas_mes AS (SELECT fecha FROM ventas) SELECT fecha FROM ventas_mes',
        50
    ),
    'Permite CTE de lectura.'
);

expectRejected('DELETE FROM ventas', 'Rechaza operaciones de escritura.');
expectRejected('WITH cambio AS (DELETE FROM ventas) SELECT * FROM cambio', 'Rechaza escritura dentro de CTE.');
expectRejected('SELECT nombre FROM ventas INTO OUTFILE \'/tmp/ventas\'', 'Rechaza exportaciones a archivos.');
expectRejected('SELECT * FROM ventas; DROP TABLE ventas', 'Rechaza instrucciones multiples.');
expectRejected('SELECT * FROM ventas;;', 'Rechaza multiples terminadores de instruccion.');
expectRejected('SELECT * FROM ventas /* comentario */', 'Rechaza comentarios SQL.');
expectRejected('SELECT * FROM ventas FOR UPDATE', 'Rechaza bloqueos de filas.');
expectRejected('SELECT @resultado := 1', 'Rechaza asignaciones de variables de sesion.');
expectRejected('SELECT * FROM ventas LIMIT ?;', 'Rechaza formatos de limite no compatibles.');

$chart = ChartBuilder::build(
    'Grafica el ingreso por region en barras',
    [
        ['region' => 'Norte', 'ingreso' => 120],
        ['region' => 'Sur', 'ingreso' => 80],
    ],
    ['region', 'ingreso']
);
expectSame(true, $chart['available'], 'Genera graficas a partir de las filas leidas.');
expectSame(['Norte', 'Sur'], $chart['labels'], 'Usa las categorias presentes en los resultados.');
expectSame([120.0, 80.0], $chart['values'], 'Grafica los valores realmente obtenidos.');

echo "Todas las pruebas pasaron.\n";
