<?php
declare(strict_types=1);

return [
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
