<?php
declare(strict_types=1);

final class Database
{
    private PDO $connection;

    public function __construct(array $configuration)
    {
        $host = trim((string) ($configuration['db_host'] ?? ''));
        $port = trim((string) ($configuration['db_port'] ?? ''));
        $databaseName = trim((string) ($configuration['db_name'] ?? ''));
        $username = (string) ($configuration['db_user'] ?? '');
        $password = (string) ($configuration['db_password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9.:-]+$/', $host)
            || !ctype_digit($port)
            || (int) $port < 1
            || (int) $port > 65535
            || !preg_match('/^[A-Za-z0-9_$-]+$/', $databaseName)
            || $username === ''
        ) {
            throw new RuntimeException('Revisa el host, puerto, base de datos y usuario de MySQL.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            (int) $port,
            $databaseName
        );
        $this->connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    public function getSchema(): array
    {
        $statement = $this->connection->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_KEY
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );
        $statement->execute();

        $schema = [];
        $columnCount = 0;
        while ($column = $statement->fetch()) {
            $tableName = (string) $column['TABLE_NAME'];
            if (!isset($schema[$tableName])) {
                $schema[$tableName] = ['columns' => [], 'foreign_keys' => []];
            }
            $columnDescription = [
                'name' => (string) $column['COLUMN_NAME'],
                'type' => (string) $column['DATA_TYPE'],
            ];
            if ((string) $column['COLUMN_KEY'] === 'PRI') {
                $columnDescription['key'] = 'primary';
            }
            $schema[$tableName]['columns'][] = $columnDescription;
            $columnCount++;

            if ($columnCount > 3000 || count($schema) > 200) {
                throw new RuntimeException(
                    'El esquema tiene demasiadas tablas o columnas para analizarlo de una vez.'
                );
            }
        }

        $foreignKeyStatement = $this->connection->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY TABLE_NAME, COLUMN_NAME'
        );
        $foreignKeyStatement->execute();
        while ($foreignKey = $foreignKeyStatement->fetch()) {
            $tableName = (string) $foreignKey['TABLE_NAME'];
            if (!isset($schema[$tableName])) {
                continue;
            }
            $schema[$tableName]['foreign_keys'][] = [
                'column' => (string) $foreignKey['COLUMN_NAME'],
                'references' => (string) $foreignKey['REFERENCED_TABLE_NAME']
                    . '.'
                    . (string) $foreignKey['REFERENCED_COLUMN_NAME'],
            ];
        }

        if ($schema === []) {
            throw new RuntimeException('No se encontraron tablas o vistas en la base de datos.');
        }

        return $schema;
    }

    public function fetchRows(string $sql, int $maximumRows): array
    {
        $this->connection->exec('START TRANSACTION READ ONLY');

        try {
            $statement = $this->connection->query($sql);
            if ($statement === false) {
                throw new RuntimeException('No se pudo ejecutar la consulta generada.');
            }

            $rows = [];
            $truncated = false;
            while (($row = $statement->fetch()) !== false) {
                if (count($rows) >= $maximumRows) {
                    $truncated = true;
                    break;
                }
                $rows[] = $row;
            }
            if (count($rows) === $maximumRows) {
                $truncated = true;
            }

            $columns = $statement->columnCount() > 0 ? $this->getColumnMetadata($statement) : [];

            if ($rows !== []) {
                $columns = array_keys($rows[0]);
            }

            $this->connection->rollBack();

            return [
                'rows' => $rows,
                'columns' => $columns,
                'truncated' => $truncated,
            ];
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function getColumnMetadata(PDOStatement $statement): array
    {
        $columns = [];
        for ($index = 0; $index < $statement->columnCount(); $index++) {
            $metadata = $statement->getColumnMeta($index);
            if (is_array($metadata) && isset($metadata['name'])) {
                $columns[] = ['name' => (string) $metadata['name']];
            }
        }

        return $columns;
    }
}
