<?php
declare(strict_types=1);

require_once __DIR__ . '/src/SqlQueryGuard.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/OllamaClient.php';
require_once __DIR__ . '/src/ChartBuilder.php';
require_once __DIR__ . '/src/ChatAgent.php';

$defaults = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'ollama_demo',
    'db_user' => 'root',
    'db_password' => '',
    'ollama_url' => 'http://127.0.0.1:11434',
    'ollama_model' => 'llama3.2:3b',
    'row_limit' => 50,
];

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
if (is_file($configPath)) {
    $customConfiguration = require $configPath;
    if (!is_array($customConfiguration)) {
        throw new RuntimeException('config.php debe devolver un arreglo de configuracion.');
    }
    $defaults = array_replace($defaults, $customConfiguration);
}

$question = trim((string) ($_POST['question'] ?? ''));
$errors = [];
$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $rowLimit = filter_var(
            $defaults['row_limit'],
            FILTER_VALIDATE_INT,
            ['options' => ['default' => 50, 'min_range' => 1, 'max_range' => 500]]
        );
        $database = new Database($defaults);
        $ollama = new OllamaClient((string) $defaults['ollama_url'], (string) $defaults['ollama_model']);
        $agent = new ChatAgent($database, $ollama, (int) $rowLimit);
        $result = $agent->answer($question);
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

function escape_html(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function preview_value(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    $text = is_scalar($value) ? (string) $value : '[dato no escalar]';
    return mb_strlen($text) > 120 ? mb_substr($text, 0, 120) . '...' : $text;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agente de datos MySQL + Ollama</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <main class="page-shell">
        <header class="app-header">
            <div>
                <p class="eyebrow">MySQL + Ollama</p>
                <h1>Agente de datos</h1>
                <p class="lede">Pregunta en lenguaje natural. El agente consulta tu base de datos y responde usando solo los resultados obtenidos.</p>
            </div>
            <div class="status-pill">Consultas de solo lectura</div>
        </header>

        <?php if ($errors !== []): ?>
            <section class="alert" role="alert">
                <strong>No se pudo completar la consulta.</strong>
                <?php foreach ($errors as $error): ?>
                    <p><?= escape_html($error) ?></p>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <form method="post" class="analysis-form">
            <section class="panel">
                <div class="panel-heading">
                    <h2>Agente conectado</h2>
                    <p>Base de datos: <?= escape_html($defaults['db_name']) ?> · Modelo: <?= escape_html($defaults['ollama_model']) ?> · Maximo: <?= escape_html($defaults['row_limit']) ?> filas</p>
                </div>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Pregunta</h2>
                    <p>El agente descubre el esquema, genera una consulta de lectura y verifica sus resultados antes de analizarlos.</p>
                </div>
                <label>
                    <span>Que quieres saber?</span>
                    <textarea name="question" rows="4" maxlength="2000" required placeholder="Ejemplo: Compara los ingresos por region y graficalos en barras."><?= escape_html($question) ?></textarea>
                </label>
                <div class="actions">
                    <button type="submit">Consultar datos</button>
                </div>
            </section>
        </form>

        <?php if ($result !== null): ?>
            <section class="results">
                <div class="answer">
                    <div class="panel-heading">
                        <h2>Respuesta</h2>
                        <p><?= escape_html($result['row_count']) ?> filas obtenidas<?= $result['truncated'] ? ' · resultado limitado' : '' ?></p>
                    </div>
                    <div class="markdown-like"><?= nl2br(escape_html($result['answer'])) ?></div>
                </div>

                <?php if (!empty($result['chart']['requested'])): ?>
                    <div class="chart-panel">
                        <div class="panel-heading">
                            <h2><?= escape_html($result['chart']['title'] ?? 'Grafica') ?></h2>
                            <p><?= escape_html($result['chart']['message'] ?? 'Grafica construida directamente con los resultados de la consulta.') ?></p>
                        </div>
                        <?php if (!empty($result['chart']['available'])): ?>
                            <div class="chart-shell">
                                <canvas id="analysisChart" role="img" aria-label="<?= escape_html($result['chart']['title']) ?>"></canvas>
                            </div>
                            <div class="chart-legend">
                                <span><strong>Eje X:</strong> <?= escape_html($result['chart']['x_label']) ?></span>
                                <span><strong>Eje Y:</strong> <?= escape_html($result['chart']['y_label']) ?></span>
                            </div>
                        <?php else: ?>
                            <p class="empty-state"><?= escape_html($result['chart']['message'] ?? 'No hay resultados suficientes para graficar.') ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <details class="data-preview">
                    <summary>Consulta SQL generada y resultados</summary>
                    <p class="muted">SQL ejecutado: <code><?= escape_html($result['sql']) ?></code></p>
                    <?php if ($result['truncated'] || $result['prompt_trimmed']): ?>
                        <p class="muted">La respuesta se limito para ajustarse al maximo de filas o tamano de contexto configurado.</p>
                    <?php endif; ?>
                    <?php if ($result['rows'] === []): ?>
                        <p class="empty-state">La consulta no devolvio filas.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <?php foreach (array_slice($result['columns'], 0, 12) as $column): ?>
                                            <th><?= escape_html($column) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($result['rows'], 0, 15) as $row): ?>
                                        <tr>
                                            <?php foreach (array_slice($result['columns'], 0, 12) as $column): ?>
                                                <td><?= escape_html(preview_value($row[$column] ?? null)) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </details>
            </section>
        <?php endif; ?>
    </main>

    <?php if ($result !== null && !empty($result['chart']['available'])): ?>
        <script>
            const chartData = <?= json_encode(
                $result['chart'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
            ) ?>;

            function drawChart() {
                const canvas = document.getElementById('analysisChart');
                if (!canvas || !Array.isArray(chartData.labels) || !Array.isArray(chartData.values)) return;

                const container = canvas.parentElement;
                const ratio = window.devicePixelRatio || 1;
                const width = Math.max(container.clientWidth, 320);
                const height = Math.max(container.clientHeight, 320);
                canvas.width = width * ratio;
                canvas.height = height * ratio;
                canvas.style.width = width + 'px';
                canvas.style.height = height + 'px';

                const context = canvas.getContext('2d');
                context.setTransform(ratio, 0, 0, ratio, 0, 0);
                context.clearRect(0, 0, width, height);
                const values = chartData.values.map(Number);
                const maximum = Math.max(...values, 0);
                const minimum = Math.min(...values, 0);
                const range = Math.max(maximum - minimum, 1);
                const padding = { top: 24, right: 20, bottom: 76, left: 72 };
                const plotWidth = width - padding.left - padding.right;
                const plotHeight = height - padding.top - padding.bottom;
                const baseline = padding.top + plotHeight - ((0 - minimum) / range) * plotHeight;

                context.font = '12px system-ui, sans-serif';
                context.fillStyle = '#637067';
                context.strokeStyle = '#dce3da';
                for (let tick = 0; tick <= 4; tick++) {
                    const fraction = tick / 4;
                    const y = padding.top + plotHeight - fraction * plotHeight;
                    context.beginPath();
                    context.moveTo(padding.left, y);
                    context.lineTo(width - padding.right, y);
                    context.stroke();
                    context.fillText(((minimum + fraction * range).toLocaleString('es-SV')), 8, y + 4);
                }

                if (chartData.type === 'line') {
                    const step = values.length > 1 ? plotWidth / (values.length - 1) : 0;
                    context.strokeStyle = '#1f7a5b';
                    context.lineWidth = 3;
                    context.beginPath();
                    values.forEach((value, index) => {
                        const x = values.length > 1 ? padding.left + step * index : padding.left + plotWidth / 2;
                        const y = padding.top + plotHeight - ((value - minimum) / range) * plotHeight;
                        if (index === 0) context.moveTo(x, y); else context.lineTo(x, y);
                        context.fillStyle = '#145a42';
                        context.fillText(value.toLocaleString('es-SV'), x - 16, y - 10);
                        if (index === 0 || index === values.length - 1 || index % Math.ceil(values.length / 8) === 0) {
                            drawLabel(context, chartData.labels[index], x, padding.top + plotHeight + 20, 72);
                        }
                    });
                    context.stroke();
                } else {
                    const slotWidth = plotWidth / Math.max(values.length, 1);
                    const barWidth = Math.max(10, Math.min(48, slotWidth * 0.6));
                    values.forEach((value, index) => {
                        const centerX = padding.left + slotWidth * (index + 0.5);
                        const y = padding.top + plotHeight - ((value - minimum) / range) * plotHeight;
                        context.fillStyle = '#1f7a5b';
                        context.fillRect(centerX - barWidth / 2, Math.min(y, baseline), barWidth, Math.max(Math.abs(baseline - y), 2));
                        context.fillStyle = '#145a42';
                        context.fillText(value.toLocaleString('es-SV'), centerX - barWidth / 2, Math.min(y, baseline) - 8);
                        drawLabel(context, chartData.labels[index], centerX, padding.top + plotHeight + 20, slotWidth);
                    });
                }
            }

            function drawLabel(context, label, x, y, slotWidth) {
                const text = String(label ?? '');
                const maximumLength = Math.max(8, Math.floor(slotWidth / 7));
                const shortened = text.length > maximumLength ? text.slice(0, maximumLength - 1) + '...' : text;
                context.save();
                context.translate(x, y);
                context.rotate(-Math.PI / 5);
                context.textAlign = 'right';
                context.fillStyle = '#637067';
                context.fillText(shortened, 0, 0);
                context.restore();
            }

            drawChart();
            window.addEventListener('resize', drawChart);
        </script>
    <?php endif; ?>
</body>
</html>
