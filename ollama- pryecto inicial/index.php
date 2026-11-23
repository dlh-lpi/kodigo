<?php
declare(strict_types=1);

session_start();

$defaults = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'ollama_demo',
    'db_user' => 'root',
    'db_password' => '',
    'ollama_url' => 'http://127.0.0.1:11434',
    'ollama_model' => 'llama3.2:3b',
    'row_limit' => 50,
    'sql' => 'SELECT * FROM ventas LIMIT 25',
    'question' => 'Analiza estos datos y resume los hallazgos principales.',
];

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
if (is_file($configPath)) {
    $config = require $configPath;
    if (is_array($config)) {
        $defaults = array_replace($defaults, $config);
    }
}

$errors = [];
$result = null;
$followUpQuestion = '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

$form = [
    'db_host' => field_value('db_host', $defaults),
    'db_port' => field_value('db_port', $defaults),
    'db_name' => field_value('db_name', $defaults),
    'db_user' => field_value('db_user', $defaults),
    'db_password' => posted_password_or_default($defaults),
    'ollama_url' => field_value('ollama_url', $defaults),
    'ollama_model' => field_value('ollama_model', $defaults),
    'row_limit' => field_value('row_limit', $defaults),
    'sql' => field_value('sql', $defaults),
    'question' => field_value('question', $defaults),
];

$availableModels = fetch_ollama_model_names($form['ollama_url']);
if (!$isPost && $availableModels !== [] && !in_array($form['ollama_model'], $availableModels, true)) {
    $suggestedModel = suggest_installed_model($form['ollama_model'], $availableModels);
    if ($suggestedModel !== null) {
        $form['ollama_model'] = $suggestedModel;
    }
}

if ($isPost) {
    try {
        $action = (string) ($_POST['action'] ?? 'analyze');
        if ($action === 'followup') {
            $followUpQuestion = trim((string) ($_POST['followup_question'] ?? ''));
            $result = continue_analysis($followUpQuestion);
        } else {
            $result = analyze_database($form);
            store_analysis_session($result);
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
        $result = load_analysis_session();
    }
} else {
    $result = load_analysis_session();
}

function field_value(string $name, array $defaults): string
{
    if (array_key_exists($name, $_POST)) {
        return trim((string) $_POST[$name]);
    }

    return trim((string) ($defaults[$name] ?? ''));
}

function posted_password_or_default(array $defaults): string
{
    if (!array_key_exists('db_password', $_POST)) {
        return (string) ($defaults['db_password'] ?? '');
    }

    $postedPassword = (string) $_POST['db_password'];
    if ($postedPassword === '' && (string) ($defaults['db_password'] ?? '') !== '') {
        return (string) $defaults['db_password'];
    }

    return $postedPassword;
}

function analyze_database(array $form): array
{
    $rowLimit = normalize_limit($form['row_limit'] ?? 50);
    $sql = normalize_sql((string) ($form['sql'] ?? ''), $rowLimit);
    $question = trim((string) ($form['question'] ?? ''));

    if ($question === '') {
        throw new RuntimeException('Escribe una pregunta o instruccion para Ollama.');
    }

    $rows = fetch_database_rows($form, $sql, $rowLimit);
    [$promptRows, $promptWasTrimmed] = prepare_rows_for_prompt($rows['rows']);
    [$resolvedModel, $modelNotice] = resolve_ollama_model((string) $form['ollama_url'], (string) $form['ollama_model']);

    $answer = call_ollama(
        (string) $form['ollama_url'],
        $resolvedModel,
        build_prompt($question, $sql, $promptRows, $rows['truncated'], $promptWasTrimmed)
    );
    $chart = build_chart_config($question, $rows['rows'], $rows['columns']);
    $conversation = [
        make_conversation_entry($question, $answer, $chart),
    ];

    return [
        'answer' => $answer,
        'question' => $question,
        'rows' => $rows['rows'],
        'columns' => $rows['columns'],
        'row_count' => count($rows['rows']),
        'truncated' => $rows['truncated'],
        'prompt_trimmed' => $promptWasTrimmed,
        'sql' => $sql,
        'ollama_url' => (string) $form['ollama_url'],
        'model' => $resolvedModel,
        'model_notice' => $modelNotice,
        'chart' => $chart,
        'conversation' => $conversation,
    ];
}

function continue_analysis(string $question): array
{
    if ($question === '') {
        throw new RuntimeException('Escribe una nueva pregunta para continuar el analisis.');
    }

    $context = load_analysis_session();
    if ($context === null) {
        throw new RuntimeException('Primero ejecuta una consulta para crear un contexto de datos.');
    }

    $rows = is_array($context['rows'] ?? null) ? $context['rows'] : [];
    $columns = is_array($context['columns'] ?? null) ? $context['columns'] : [];
    [$promptRows, $promptWasTrimmed] = prepare_rows_for_prompt($rows);
    [$resolvedModel, $modelNotice] = resolve_ollama_model((string) $context['ollama_url'], (string) $context['model']);

    $answer = call_ollama(
        (string) $context['ollama_url'],
        $resolvedModel,
        build_followup_prompt($question, $context, $promptRows, $promptWasTrimmed)
    );
    $chart = build_chart_config($question, $rows, $columns);

    $conversation = is_array($context['conversation'] ?? null) ? $context['conversation'] : [];
    $conversation[] = make_conversation_entry($question, $answer, $chart);
    $conversation = array_slice($conversation, -12);

    $context['answer'] = $answer;
    $context['question'] = $question;
    $context['model'] = $resolvedModel;
    $context['model_notice'] = $modelNotice;
    $context['chart'] = $chart;
    $context['conversation'] = $conversation;

    store_analysis_session($context);

    return $context;
}

function make_conversation_entry(string $question, string $answer, array $chart): array
{
    return [
        'question' => $question,
        'answer' => $answer,
        'chart_requested' => !empty($chart['requested']),
        'chart_available' => !empty($chart['available']),
        'created_at' => date('Y-m-d H:i:s'),
    ];
}

function store_analysis_session(array $result): void
{
    $_SESSION['analysis_context'] = [
        'answer' => (string) ($result['answer'] ?? ''),
        'question' => (string) ($result['question'] ?? ''),
        'rows' => is_array($result['rows'] ?? null) ? $result['rows'] : [],
        'columns' => is_array($result['columns'] ?? null) ? $result['columns'] : [],
        'row_count' => (int) ($result['row_count'] ?? 0),
        'truncated' => (bool) ($result['truncated'] ?? false),
        'prompt_trimmed' => (bool) ($result['prompt_trimmed'] ?? false),
        'sql' => (string) ($result['sql'] ?? ''),
        'ollama_url' => (string) ($result['ollama_url'] ?? 'http://127.0.0.1:11434'),
        'model' => (string) ($result['model'] ?? ''),
        'model_notice' => $result['model_notice'] ?? null,
        'chart' => is_array($result['chart'] ?? null) ? $result['chart'] : ['requested' => false, 'available' => false],
        'conversation' => is_array($result['conversation'] ?? null) ? $result['conversation'] : [],
    ];
}

function load_analysis_session(): ?array
{
    $context = $_SESSION['analysis_context'] ?? null;
    if (!is_array($context) || !isset($context['rows'], $context['columns'], $context['sql'], $context['model'])) {
        return null;
    }

    $context['ollama_url'] = (string) ($context['ollama_url'] ?? 'http://127.0.0.1:11434');
    $context['chart'] = is_array($context['chart'] ?? null) ? $context['chart'] : ['requested' => false, 'available' => false];
    $context['conversation'] = is_array($context['conversation'] ?? null) ? $context['conversation'] : [];

    return $context;
}

function normalize_limit(mixed $value): int
{
    $limit = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['default' => 50, 'min_range' => 1, 'max_range' => 500],
    ]);

    return (int) $limit;
}

function normalize_sql(string $sql, int $rowLimit): string
{
    $sql = trim($sql);

    if ($sql === '') {
        throw new RuntimeException('Escribe una consulta SQL de lectura.');
    }

    if (strlen($sql) > 10000) {
        throw new RuntimeException('La consulta es demasiado larga.');
    }

    $sql = rtrim($sql, " \t\n\r\0\x0B;");
    if (str_contains($sql, ';')) {
        throw new RuntimeException('Solo se permite una consulta por ejecucion.');
    }

    $firstKeyword = strtolower(strtok(ltrim($sql), " \t\n\r"));
    $allowedStarts = ['select', 'with', 'show', 'describe', 'desc', 'explain'];
    if (!in_array($firstKeyword, $allowedStarts, true)) {
        throw new RuntimeException('Por seguridad, solo se permiten consultas de lectura: SELECT, WITH, SHOW, DESCRIBE o EXPLAIN.');
    }

    $blocked = [
        'insert',
        'update',
        'delete',
        'drop',
        'alter',
        'truncate',
        'create',
        'replace',
        'grant',
        'revoke',
        'call',
        'handler',
        'load_file',
        'outfile',
        'dumpfile',
        'infile',
        'into\s+outfile',
        'into\s+dumpfile',
    ];

    foreach ($blocked as $word) {
        if (preg_match('/\b' . $word . '\b/i', $sql)) {
            throw new RuntimeException('La consulta contiene una instruccion no permitida para este analizador.');
        }
    }

    if (in_array($firstKeyword, ['select', 'with'], true) && !preg_match('/\blimit\s+\d+\b/i', $sql)) {
        $sql .= ' LIMIT ' . $rowLimit;
    }

    return $sql;
}

function fetch_database_rows(array $form, string $sql, int $rowLimit): array
{
    $host = trim((string) ($form['db_host'] ?? '127.0.0.1'));
    $port = trim((string) ($form['db_port'] ?? '3306'));
    $dbName = trim((string) ($form['db_name'] ?? ''));
    $user = trim((string) ($form['db_user'] ?? ''));
    $password = (string) ($form['db_password'] ?? '');

    if ($host === '' || $port === '' || $dbName === '' || $user === '') {
        throw new RuntimeException('Completa host, puerto, base de datos y usuario.');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $statement = $pdo->query($sql);
    if ($statement === false) {
        throw new RuntimeException('No se pudo ejecutar la consulta.');
    }

    $rows = [];
    $truncated = false;
    while (($row = $statement->fetch()) !== false) {
        if (count($rows) >= $rowLimit) {
            $truncated = true;
            break;
        }

        $rows[] = $row;
    }

    $columns = [];
    if ($rows !== []) {
        $columns = array_keys($rows[0]);
    } else {
        for ($index = 0; $index < $statement->columnCount(); $index++) {
            $meta = $statement->getColumnMeta($index);
            if (isset($meta['name'])) {
                $columns[] = (string) $meta['name'];
            }
        }
    }

    return [
        'rows' => $rows,
        'columns' => $columns,
        'truncated' => $truncated,
    ];
}

function prepare_rows_for_prompt(array $rows): array
{
    $prepared = [];
    $maxPromptChars = 22000;
    $wasTrimmed = false;

    foreach ($rows as $row) {
        $cleanRow = [];
        foreach ($row as $key => $value) {
            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                $cleanRow[$key] = $value;
                continue;
            }

            $text = (string) $value;
            if (mb_strlen($text) > 600) {
                $text = mb_substr($text, 0, 600) . '...';
                $wasTrimmed = true;
            }
            $cleanRow[$key] = $text;
        }

        $prepared[] = $cleanRow;
        $encoded = json_encode($prepared, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false && mb_strlen($encoded) > $maxPromptChars) {
            array_pop($prepared);
            $wasTrimmed = true;
            break;
        }
    }

    return [$prepared, $wasTrimmed];
}

function build_chart_config(string $question, array $rows, array $columns): array
{
    if (!question_requests_chart($question)) {
        return [
            'requested' => false,
            'available' => false,
        ];
    }

    if ($rows === [] || $columns === []) {
        return [
            'requested' => true,
            'available' => false,
            'message' => 'No hay filas disponibles para graficar.',
        ];
    }

    $profiles = profile_chart_columns($rows, $columns);
    $valueColumn = choose_value_column($profiles, $question);
    if ($valueColumn === null) {
        return [
            'requested' => true,
            'available' => false,
            'message' => 'No encontre una columna numerica para graficar. Prueba con una columna de monto, cantidad, total o una consulta con COUNT/SUM.',
        ];
    }

    $labelColumn = choose_label_column($profiles, $question, $valueColumn);
    $chartType = choose_chart_type($question, $labelColumn, $profiles);
    $points = aggregate_chart_points($rows, $labelColumn, $valueColumn, $profiles[$labelColumn]['is_date'] ?? false);

    if ($points === []) {
        return [
            'requested' => true,
            'available' => false,
            'message' => 'No encontre valores suficientes para construir la grafica.',
        ];
    }

    $maxPoints = $chartType === 'line' ? 30 : 20;
    if (count($points) > $maxPoints) {
        if ($chartType === 'bar') {
            usort($points, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        }
        $points = array_slice($points, 0, $maxPoints);
    }

    $labelName = $labelColumn ?? 'fila';
    $message = sprintf(
        'Grafica generada usando %s por %s. Si hay etiquetas repetidas, los valores se suman.',
        $valueColumn,
        $labelName
    );

    return [
        'requested' => true,
        'available' => true,
        'type' => $chartType,
        'title' => sprintf('Grafica de %s por %s', $valueColumn, $labelName),
        'x_label' => $labelName,
        'y_label' => $valueColumn,
        'labels' => array_column($points, 'label'),
        'values' => array_column($points, 'value'),
        'message' => $message,
    ];
}

function question_requests_chart(string $question): bool
{
    $text = normalize_search_text($question);

    return (bool) preg_match('/\b(grafic\w*|grafiqu\w*|chart|plot|barras?|linea|visualiz\w*|diagrama|tendencia|evolucion)\b/', $text);
}

function normalize_search_text(string $text): string
{
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }

    return strtolower($text);
}

function profile_chart_columns(array $rows, array $columns): array
{
    $profiles = [];

    foreach ($columns as $column) {
        $nonEmpty = 0;
        $numeric = 0;
        $dates = 0;
        $unique = [];

        foreach ($rows as $row) {
            $value = $row[$column] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $nonEmpty++;
            $unique[(string) $value] = true;
            if (numeric_chart_value($value) !== null) {
                $numeric++;
            }
            if (looks_like_date_value($value, $column)) {
                $dates++;
            }
        }

        $profiles[$column] = [
            'name' => $column,
            'normalized_name' => normalize_search_text((string) $column),
            'non_empty' => $nonEmpty,
            'unique_count' => count($unique),
            'is_numeric' => $nonEmpty > 0 && $numeric >= max(1, (int) ceil($nonEmpty * 0.8)) && $dates === 0,
            'is_date' => $nonEmpty > 0 && $dates >= max(1, (int) ceil($nonEmpty * 0.8)),
        ];
    }

    return $profiles;
}

function choose_value_column(array $profiles, string $question): ?string
{
    $questionText = normalize_search_text($question);
    $bestColumn = null;
    $bestScore = PHP_INT_MIN;

    foreach ($profiles as $column => $profile) {
        if (!$profile['is_numeric']) {
            continue;
        }

        $name = $profile['normalized_name'];
        $score = 0;

        if (str_contains($questionText, $name)) {
            $score += 100;
        }
        if (preg_match('/(^|_)(id|codigo|code)$/', $name)) {
            $score -= 80;
        }
        if (preg_match('/(ingreso|venta|total|monto|valor|importe|precio|saldo|revenue|amount|sales)/', $name)) {
            $score += 35;
        }
        if (preg_match('/(unidad|cantidad|conteo|count|qty|stock)/', $name)) {
            $score += 25;
        }
        if ($profile['unique_count'] <= 1) {
            $score -= 10;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestColumn = $column;
        }
    }

    return $bestColumn;
}

function choose_label_column(array $profiles, string $question, string $valueColumn): ?string
{
    $questionText = normalize_search_text($question);
    $wantsTime = (bool) preg_match('/\b(linea|tendencia|evolucion|fecha|tiempo|mes|ano|anio|dia|semanal|mensual)\b/', $questionText);
    $bestColumn = null;
    $bestScore = PHP_INT_MIN;

    foreach ($profiles as $column => $profile) {
        if ($column === $valueColumn || $profile['is_numeric'] || $profile['non_empty'] === 0) {
            continue;
        }

        $name = $profile['normalized_name'];
        $score = 0;

        if (str_contains($questionText, $name)) {
            $score += 100;
        }
        if ($profile['is_date']) {
            $score += $wantsTime ? 60 : 5;
        } else {
            $score += $wantsTime ? 0 : 25;
        }
        if ($profile['unique_count'] >= 2 && $profile['unique_count'] <= 20) {
            $score += 20;
        }
        if ($profile['unique_count'] > 40) {
            $score -= 30;
        }
        if (preg_match('/(region|categoria|producto|canal|tipo|nombre|name|category|status)/', $name)) {
            $score += 15;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestColumn = $column;
        }
    }

    return $bestColumn;
}

function choose_chart_type(string $question, ?string $labelColumn, array $profiles): string
{
    $questionText = normalize_search_text($question);
    if (preg_match('/\b(barra|barras|bar)\b/', $questionText)) {
        return 'bar';
    }
    if (preg_match('/\b(linea|line|tendencia|evolucion)\b/', $questionText)) {
        return 'line';
    }
    if ($labelColumn !== null && ($profiles[$labelColumn]['is_date'] ?? false)) {
        return 'line';
    }

    return 'bar';
}

function aggregate_chart_points(array $rows, ?string $labelColumn, string $valueColumn, bool $labelIsDate): array
{
    $groups = [];
    $order = [];

    foreach ($rows as $index => $row) {
        $value = numeric_chart_value($row[$valueColumn] ?? null);
        if ($value === null) {
            continue;
        }

        $label = $labelColumn === null ? 'Fila ' . ($index + 1) : chart_label($row[$labelColumn] ?? null);
        if (!array_key_exists($label, $groups)) {
            $groups[$label] = 0.0;
            $order[$label] = $index;
        }
        $groups[$label] += $value;
    }

    $points = [];
    foreach ($groups as $label => $value) {
        $points[] = [
            'label' => $label,
            'value' => round($value, 2),
            'order' => $order[$label],
        ];
    }

    if ($labelIsDate) {
        usort($points, static function (array $a, array $b): int {
            $dateA = strtotime($a['label']);
            $dateB = strtotime($b['label']);

            return ($dateA ?: 0) <=> ($dateB ?: 0);
        });
    } else {
        usort($points, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
    }

    foreach ($points as &$point) {
        unset($point['order']);
    }
    unset($point);

    return $points;
}

function numeric_chart_value(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $normalized = str_replace(',', '', $text);
    return is_numeric($normalized) ? (float) $normalized : null;
}

function looks_like_date_value(mixed $value, string $column): bool
{
    if ($value === null || $value === '') {
        return false;
    }

    $name = normalize_search_text($column);
    $text = trim((string) $value);

    if (!preg_match('/(fecha|date|time|created|updated|mes|month|dia|day|ano|anio|year)/', $name)
        && !preg_match('/^\d{4}-\d{2}-\d{2}/', $text)
    ) {
        return false;
    }

    return strtotime($text) !== false;
}

function chart_label(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'Sin dato';
    }

    $label = (string) $value;
    return mb_strlen($label) > 42 ? mb_substr($label, 0, 42) . '...' : $label;
}

function fetch_ollama_model_names(string $baseUrl): array
{
    $baseUrl = rtrim(trim($baseUrl), '/');
    if ($baseUrl === '') {
        return [];
    }

    $curl = curl_init($baseUrl . '/api/tags');
    if ($curl === false) {
        return [];
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => 3,
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false || $status < 200 || $status >= 300) {
        return [];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['models']) || !is_array($decoded['models'])) {
        return [];
    }

    $names = [];
    foreach ($decoded['models'] as $model) {
        if (!is_array($model)) {
            continue;
        }

        $name = trim((string) ($model['name'] ?? $model['model'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return array_values(array_unique($names));
}

function suggest_installed_model(string $requestedModel, array $availableModels): ?string
{
    $requestedModel = trim($requestedModel);
    if ($requestedModel === '') {
        return $availableModels[0] ?? null;
    }

    $matches = array_values(array_filter(
        $availableModels,
        static fn (string $model): bool => str_starts_with($model, $requestedModel . ':')
    ));

    if (count($matches) === 1) {
        return $matches[0];
    }

    return null;
}

function resolve_ollama_model(string $baseUrl, string $requestedModel): array
{
    $requestedModel = trim($requestedModel);
    $availableModels = fetch_ollama_model_names($baseUrl);

    if ($requestedModel === '') {
        throw new RuntimeException('Completa el nombre del modelo de Ollama.');
    }

    if ($availableModels === [] || in_array($requestedModel, $availableModels, true)) {
        return [$requestedModel, null];
    }

    $suggestedModel = suggest_installed_model($requestedModel, $availableModels);
    if ($suggestedModel !== null) {
        return [
            $suggestedModel,
            "Se uso {$suggestedModel} porque {$requestedModel} no esta instalado con ese nombre exacto.",
        ];
    }

    throw new RuntimeException(
        "El modelo '{$requestedModel}' no esta instalado en Ollama. Modelos disponibles: " . implode(', ', $availableModels)
    );
}

function build_prompt(string $question, string $sql, array $rows, bool $queryWasTruncated, bool $promptWasTrimmed): string
{
    $flags = [];
    if ($queryWasTruncated) {
        $flags[] = 'La consulta devolvio mas filas que el limite configurado.';
    }
    if ($promptWasTrimmed) {
        $flags[] = 'Parte del contenido fue recortado para entrar en el contexto.';
    }

    $data = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($data === false) {
        $data = '[]';
    }

    $notes = $flags === [] ? 'No hay notas adicionales.' : implode("\n", array_map(static fn (string $flag): string => '- ' . $flag, $flags));

    return <<<PROMPT
Pregunta o tarea del usuario:
{$question}

Consulta SQL ejecutada:
{$sql}

Datos disponibles en formato JSON:
{$data}

Notas de contexto:
{$notes}
PROMPT;
}

function build_followup_prompt(string $question, array $context, array $rows, bool $promptWasTrimmed): string
{
    $data = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($data === false) {
        $data = '[]';
    }

    $flags = [];
    if (!empty($context['truncated'])) {
        $flags[] = 'La consulta original devolvio mas filas que el limite configurado.';
    }
    if (!empty($context['prompt_trimmed']) || $promptWasTrimmed) {
        $flags[] = 'Parte del contenido fue recortado para entrar en el contexto.';
    }
    $notes = $flags === [] ? 'No hay notas adicionales.' : implode("\n", array_map(static fn (string $flag): string => '- ' . $flag, $flags));
    $history = format_conversation_history(is_array($context['conversation'] ?? null) ? $context['conversation'] : []);
    $sql = (string) ($context['sql'] ?? '');

    return <<<PROMPT
Estas continuando un analisis de datos. Responde la nueva pregunta usando los mismos datos y tomando en cuenta la conversacion previa.

Nueva pregunta del usuario:
{$question}

Consulta SQL original:
{$sql}

Datos disponibles en formato JSON:
{$data}

Conversacion previa:
{$history}

Notas de contexto:
{$notes}

Instrucciones:
- No inventes datos que no esten en el JSON.
- Si la pregunta requiere otra consulta SQL o mas filas, dilo claramente.
- Responde en espanol claro, con analisis practico y conclusiones accionables.
PROMPT;
}

function format_conversation_history(array $conversation): string
{
    if ($conversation === []) {
        return 'No hay conversacion previa.';
    }

    $items = [];
    foreach (array_slice($conversation, -6) as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $question = trim((string) ($entry['question'] ?? ''));
        $answer = trim((string) ($entry['answer'] ?? ''));
        if ($question === '' && $answer === '') {
            continue;
        }

        if (mb_strlen($answer) > 1200) {
            $answer = mb_substr($answer, 0, 1200) . '...';
        }

        $items[] = sprintf(
            "%d. Usuario: %s\n   Ollama: %s",
            $index + 1,
            $question,
            $answer
        );
    }

    return $items === [] ? 'No hay conversacion previa.' : implode("\n\n", $items);
}

function call_ollama(string $baseUrl, string $model, string $prompt): string
{
    $baseUrl = rtrim(trim($baseUrl), '/');
    $model = trim($model);

    if ($baseUrl === '' || $model === '') {
        throw new RuntimeException('Completa la URL de Ollama y el nombre del modelo.');
    }

    $systemPrompt = implode(' ', [
        'Eres un analista de datos.',
        'Responde en espanol claro y practico.',
        'Usa solo los datos entregados; si falta informacion, dilo.',
        'Incluye conclusiones, patrones relevantes y una respuesta directa a la pregunta.',
    ]);

    $payload = [
        'model' => $model,
        'stream' => false,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt],
        ],
        'options' => [
            'temperature' => 0.2,
        ],
    ];

    $curl = curl_init($baseUrl . '/api/chat');
    if ($curl === false) {
        throw new RuntimeException('No se pudo inicializar la conexion con Ollama.');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('No se pudo conectar con Ollama. Revisa que Ollama este ejecutandose y que la URL sea correcta. Detalle: ' . $error);
    }

    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) && isset($decoded['error']) ? (string) $decoded['error'] : $response;
        throw new RuntimeException('Ollama respondio con error HTTP ' . $status . ': ' . $message);
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('Ollama devolvio una respuesta que no se pudo interpretar.');
    }

    $content = $decoded['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('Ollama no devolvio contenido en la respuesta.');
    }

    return trim($content);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function preview_value(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    $text = (string) $value;
    return mb_strlen($text) > 120 ? mb_substr($text, 0, 120) . '...' : $text;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Analizador MySQL + Ollama</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <main class="page-shell">
        <header class="app-header">
            <div>
                <p class="eyebrow">MySQL + Ollama</p>
                <h1>Analizador de datos local</h1>
                <p class="lede">Consulta tu base de datos, envia los resultados a Ollama y recibe respuestas basadas en esa informacion.</p>
            </div>
            <div class="status-pill">Lectura segura</div>
        </header>

        <?php if ($errors !== []): ?>
            <section class="alert" role="alert">
                <strong>No se pudo completar el analisis.</strong>
                <?php foreach ($errors as $error): ?>
                    <p><?= e($error) ?></p>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <form method="post" class="analysis-form">
            <input type="hidden" name="action" value="analyze">
            <section class="panel">
                <div class="panel-heading">
                    <h2>Base de datos</h2>
                    <p>Usa tus datos locales de XAMPP o cualquier MySQL accesible desde este equipo.</p>
                </div>

                <div class="grid four">
                    <label>
                        <span>Host</span>
                        <input name="db_host" value="<?= e($form['db_host']) ?>" required>
                    </label>
                    <label>
                        <span>Puerto</span>
                        <input name="db_port" value="<?= e($form['db_port']) ?>" inputmode="numeric" required>
                    </label>
                    <label>
                        <span>Base de datos</span>
                        <input name="db_name" value="<?= e($form['db_name']) ?>" required>
                    </label>
                    <label>
                        <span>Usuario</span>
                        <input name="db_user" value="<?= e($form['db_user']) ?>" required>
                    </label>
                </div>

                <div class="grid two">
                    <label>
                        <span>Contrasena</span>
                        <input type="password" name="db_password" placeholder="<?= e((string) ($defaults['db_password'] ?? '') !== '' ? 'Configurada en config.php' : 'Vacia si usas root sin contrasena') ?>">
                    </label>
                    <label>
                        <span>Maximo de filas</span>
                        <input type="number" name="row_limit" value="<?= e($form['row_limit']) ?>" min="1" max="500">
                    </label>
                </div>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Ollama</h2>
                    <p>El modelo debe estar descargado en Ollama antes de ejecutar el analisis.</p>
                </div>

                <div class="grid two">
                    <label>
                        <span>URL</span>
                        <input name="ollama_url" value="<?= e($form['ollama_url']) ?>" required>
                    </label>
                    <label>
                        <span>Modelo</span>
                        <input name="ollama_model" value="<?= e($form['ollama_model']) ?>" list="ollama-models" required>
                        <?php if ($availableModels !== []): ?>
                            <datalist id="ollama-models">
                                <?php foreach ($availableModels as $modelName): ?>
                                    <option value="<?= e($modelName) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <small class="field-hint">Disponibles: <?= e(implode(', ', $availableModels)) ?></small>
                        <?php else: ?>
                            <small class="field-hint">No se pudo leer la lista de modelos; confirma que Ollama este activo.</small>
                        <?php endif; ?>
                    </label>
                </div>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Consulta y pregunta</h2>
                    <p>Solo se permiten consultas de lectura. Si no agregas LIMIT, la app lo agrega por ti.</p>
                </div>

                <label>
                    <span>SQL</span>
                    <textarea name="sql" rows="7" spellcheck="false" required><?= e($form['sql']) ?></textarea>
                </label>

                <label>
                    <span>Pregunta para Ollama</span>
                    <textarea name="question" rows="4" required><?= e($form['question']) ?></textarea>
                </label>

                <div class="actions">
                    <button type="submit">Analizar datos</button>
                </div>
            </section>
        </form>

        <?php if ($result !== null): ?>
            <section class="results">
                <div class="answer">
                    <div class="panel-heading">
                        <h2>Respuesta de Ollama</h2>
                        <p>Modelo: <?= e($result['model']) ?> · Filas analizadas: <?= e($result['row_count']) ?></p>
                        <?php if (!empty($result['model_notice'])): ?>
                            <p><?= e($result['model_notice']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="markdown-like"><?= nl2br(e($result['answer'])) ?></div>
                </div>

                <?php if (!empty($result['chart']['requested'])): ?>
                    <div class="chart-panel">
                        <div class="panel-heading">
                            <h2><?= e($result['chart']['available'] ? $result['chart']['title'] : 'Grafica') ?></h2>
                            <p><?= e($result['chart']['message'] ?? 'No se pudo generar la grafica con los datos disponibles.') ?></p>
                        </div>

                        <?php if (!empty($result['chart']['available'])): ?>
                            <div class="chart-shell">
                                <canvas id="analysisChart" aria-label="<?= e($result['chart']['title']) ?>" role="img"></canvas>
                            </div>
                            <div class="chart-legend">
                                <span><strong>Eje X:</strong> <?= e($result['chart']['x_label']) ?></span>
                                <span><strong>Eje Y:</strong> <?= e($result['chart']['y_label']) ?></span>
                            </div>
                        <?php else: ?>
                            <p class="empty-state"><?= e($result['chart']['message'] ?? 'No hay datos suficientes para graficar.') ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="followup-panel">
                    <div class="panel-heading">
                        <h2>Seguir preguntando</h2>
                        <p>Estas preguntas usan la misma consulta y las mismas filas del analisis actual.</p>
                    </div>

                    <form method="post" class="followup-form">
                        <input type="hidden" name="action" value="followup">
                        <label>
                            <span>Nueva pregunta o analisis</span>
                            <textarea name="followup_question" rows="3" required placeholder="Ejemplo: compara regiones, encuentra anomalias o grafica el ingreso por fecha."><?= e($followUpQuestion) ?></textarea>
                        </label>
                        <div class="actions">
                            <button type="submit">Preguntar sobre estos datos</button>
                        </div>
                    </form>

                    <?php if (!empty($result['conversation'])): ?>
                        <details class="conversation-history">
                            <summary>Historial de este analisis</summary>
                            <div class="conversation-list">
                                <?php foreach ($result['conversation'] as $index => $entry): ?>
                                    <?php if (!is_array($entry)) { continue; } ?>
                                    <article class="conversation-item">
                                        <div class="conversation-meta">
                                            <span>Pregunta <?= e($index + 1) ?></span>
                                            <?php if (!empty($entry['chart_available'])): ?>
                                                <span class="history-badge">Con grafica</span>
                                            <?php elseif (!empty($entry['chart_requested'])): ?>
                                                <span class="history-badge muted-badge">Grafica no disponible</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="conversation-question"><?= e($entry['question'] ?? '') ?></p>
                                        <div class="conversation-answer"><?= nl2br(e($entry['answer'] ?? '')) ?></div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>

                <details class="data-preview" open>
                    <summary>Vista previa de datos enviados</summary>
                    <p class="muted">Consulta ejecutada: <code><?= e($result['sql']) ?></code></p>
                    <?php if ($result['truncated'] || $result['prompt_trimmed']): ?>
                        <p class="muted">Algunos datos fueron limitados para proteger el rendimiento y el tamano del contexto.</p>
                    <?php endif; ?>

                    <?php if ($result['rows'] === []): ?>
                        <p class="empty-state">La consulta no devolvio filas.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <?php foreach (array_slice($result['columns'], 0, 12) as $column): ?>
                                            <th><?= e($column) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($result['rows'], 0, 15) as $row): ?>
                                        <tr>
                                            <?php foreach (array_slice($result['columns'], 0, 12) as $column): ?>
                                                <td><?= e(preview_value($row[$column] ?? null)) ?></td>
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
            const chartData = <?= json_encode($result['chart'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

            function drawAnalysisChart() {
                const canvas = document.getElementById('analysisChart');
                if (!canvas || !chartData || !Array.isArray(chartData.labels) || !Array.isArray(chartData.values)) {
                    return;
                }

                const container = canvas.parentElement;
                const ratio = window.devicePixelRatio || 1;
                const width = Math.max(container.clientWidth, 320);
                const height = Math.max(container.clientHeight, 360);
                canvas.width = Math.floor(width * ratio);
                canvas.height = Math.floor(height * ratio);
                canvas.style.width = width + 'px';
                canvas.style.height = height + 'px';

                const context = canvas.getContext('2d');
                context.setTransform(ratio, 0, 0, ratio, 0, 0);
                context.clearRect(0, 0, width, height);

                const labels = chartData.labels;
                const values = chartData.values.map(Number);
                const padding = { top: 28, right: 24, bottom: 76, left: 72 };
                const plotWidth = width - padding.left - padding.right;
                const plotHeight = height - padding.top - padding.bottom;
                const maxValue = Math.max(...values, 0);
                const minValue = Math.min(...values, 0);
                const range = Math.max(maxValue - Math.min(minValue, 0), 1);
                const baseline = padding.top + plotHeight - ((0 - Math.min(minValue, 0)) / range) * plotHeight;

                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, width, height);
                context.strokeStyle = '#dce3da';
                context.lineWidth = 1;
                context.font = '12px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
                context.fillStyle = '#637067';

                for (let index = 0; index <= 4; index++) {
                    const ratioLine = index / 4;
                    const y = padding.top + plotHeight - ratioLine * plotHeight;
                    const value = Math.min(minValue, 0) + ratioLine * range;
                    context.beginPath();
                    context.moveTo(padding.left, y);
                    context.lineTo(width - padding.right, y);
                    context.stroke();
                    context.fillText(formatChartNumber(value), 12, y + 4);
                }

                context.strokeStyle = '#93a19a';
                context.beginPath();
                context.moveTo(padding.left, padding.top);
                context.lineTo(padding.left, padding.top + plotHeight);
                context.lineTo(width - padding.right, padding.top + plotHeight);
                context.stroke();

                if (chartData.type === 'line') {
                    drawLineChart(context, labels, values, padding, plotWidth, plotHeight, range, minValue);
                } else {
                    drawBarChart(context, labels, values, padding, plotWidth, plotHeight, range, minValue, baseline);
                }
            }

            function drawBarChart(context, labels, values, padding, plotWidth, plotHeight, range, minValue, baseline) {
                const slotWidth = plotWidth / Math.max(values.length, 1);
                const barWidth = Math.max(12, Math.min(48, slotWidth * 0.58));

                values.forEach((value, index) => {
                    const centerX = padding.left + slotWidth * index + slotWidth / 2;
                    const y = padding.top + plotHeight - ((value - Math.min(minValue, 0)) / range) * plotHeight;
                    const barY = Math.min(y, baseline);
                    const barHeight = Math.max(Math.abs(baseline - y), 2);

                    context.fillStyle = '#1f7a5b';
                    context.fillRect(centerX - barWidth / 2, barY, barWidth, barHeight);
                    context.fillStyle = '#145a42';
                    context.fillText(formatChartNumber(value), centerX - barWidth / 2, barY - 8);

                    drawXAxisLabel(context, labels[index], centerX, padding.top + plotHeight + 18, slotWidth);
                });
            }

            function drawLineChart(context, labels, values, padding, plotWidth, plotHeight, range, minValue) {
                const step = values.length > 1 ? plotWidth / (values.length - 1) : plotWidth;
                const points = values.map((value, index) => {
                    return {
                        x: values.length > 1 ? padding.left + step * index : padding.left + plotWidth / 2,
                        y: padding.top + plotHeight - ((value - Math.min(minValue, 0)) / range) * plotHeight,
                        value
                    };
                });

                context.strokeStyle = '#1f7a5b';
                context.lineWidth = 3;
                context.beginPath();
                points.forEach((point, index) => {
                    if (index === 0) {
                        context.moveTo(point.x, point.y);
                    } else {
                        context.lineTo(point.x, point.y);
                    }
                });
                context.stroke();

                points.forEach((point, index) => {
                    context.fillStyle = '#ffffff';
                    context.strokeStyle = '#1f7a5b';
                    context.lineWidth = 2;
                    context.beginPath();
                    context.arc(point.x, point.y, 4, 0, Math.PI * 2);
                    context.fill();
                    context.stroke();

                    if (index === 0 || index === points.length - 1 || points.length <= 10) {
                        context.fillStyle = '#145a42';
                        context.fillText(formatChartNumber(point.value), point.x - 14, point.y - 10);
                    }

                    const shouldLabel = points.length <= 12 || index % Math.ceil(points.length / 8) === 0;
                    if (shouldLabel) {
                        drawXAxisLabel(context, labels[index], point.x, padding.top + plotHeight + 18, 70);
                    }
                });
            }

            function drawXAxisLabel(context, label, x, y, slotWidth) {
                const text = String(label ?? '');
                const maxLength = Math.max(8, Math.floor(slotWidth / 7));
                const shown = text.length > maxLength ? text.slice(0, maxLength - 1) + '...' : text;

                context.save();
                context.translate(x, y);
                context.rotate(-Math.PI / 5);
                context.fillStyle = '#637067';
                context.textAlign = 'right';
                context.fillText(shown, 0, 0);
                context.restore();
            }

            function formatChartNumber(value) {
                return Number(value).toLocaleString('es-SV', {
                    maximumFractionDigits: 2
                });
            }

            drawAnalysisChart();
            window.addEventListener('resize', drawAnalysisChart);
        </script>
    <?php endif; ?>
</body>
</html>
