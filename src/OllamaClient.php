<?php
declare(strict_types=1);

final class OllamaClient
{
    private string $baseUrl;
    private string $model;

    public function __construct(string $baseUrl, string $model)
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->model = trim($model);

        $url = filter_var($this->baseUrl, FILTER_VALIDATE_URL);
        $scheme = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : '';
        if ($url === false || !in_array($scheme, ['http', 'https'], true) || $this->model === '') {
            throw new RuntimeException('Configura una URL HTTP(S) valida y el nombre del modelo de Ollama.');
        }
    }

    public function chat(string $systemPrompt, string $userPrompt, bool $expectJson = false): string
    {
        $payload = [
            'model' => $this->model,
            'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'options' => ['temperature' => 0.1],
        ];
        if ($expectJson) {
            $payload['format'] = 'json';
        }

        $curl = curl_init($this->baseUrl . '/api/chat');
        if ($curl === false) {
            throw new RuntimeException('No se pudo iniciar la conexion con Ollama.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('No se pudo conectar con Ollama: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['error'] ?? '') : '';
            throw new RuntimeException(
                'Ollama respondio con HTTP ' . $statusCode . ($message === '' ? '.' : ': ' . $message)
            );
        }

        $content = is_array($decoded) ? ($decoded['message']['content'] ?? null) : null;
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('Ollama devolvio una respuesta vacia o invalida.');
        }

        return trim($content);
    }
}
