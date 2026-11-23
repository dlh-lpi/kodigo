<?php
declare(strict_types=1);

final class SqlQueryGuard
{
    public static function applyRowLimit(string $sql, int $maximumRows): string
    {
        $sql = trim($sql);
        if (str_ends_with($sql, ';')) {
            $sql = rtrim(substr($sql, 0, -1));
        }

        if ($sql === '' || strlen($sql) > 10000) {
            throw new RuntimeException('El modelo no genero una consulta valida.');
        }

        if (str_contains($sql, ';') || preg_match('/--|#|\/\*|\*\//', $sql)) {
            throw new RuntimeException('La consulta contiene comentarios o mas de una instruccion.');
        }

        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new RuntimeException('Solo se permiten consultas SELECT de lectura.');
        }

        $tokens = preg_replace(
            '/\'(?:\\\\.|\'\'|[^\'\\\\])*\'|"(?:\\\\.|""|[^"\\\\])*"|`(?:``|[^`])*`/s',
            ' ',
            $sql
        );
        if (!is_string($tokens)) {
            throw new RuntimeException('No se pudo validar la consulta generada.');
        }

        $blockedTokens = [
            'insert', 'update', 'delete', 'replace', 'drop', 'alter', 'truncate',
            'create', 'grant', 'revoke', 'call', 'do', 'handler', 'load', 'outfile',
            'dumpfile', 'infile', 'into', 'sleep', 'benchmark', 'load_file',
            'get_lock', 'release_lock', 'is_free_lock', 'is_used_lock', 'last_insert_id',
        ];
        $blockedPattern = '/\b(?:' . implode('|', $blockedTokens) . ')\b/i';
        if (preg_match($blockedPattern, $tokens)
            || preg_match('/\bFOR\s+(?:UPDATE|SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $tokens)
            || str_contains($tokens, ':=')
        ) {
            throw new RuntimeException('La consulta contiene una operacion o funcion no permitida.');
        }

        $maximumRows = max(1, min($maximumRows, 501));
        $limitPattern = '/\bLIMIT\s+(\d+)(?:\s*,\s*(\d+))?(?:\s+OFFSET\s+\d+)?\s*$/i';
        if (preg_match($limitPattern, $sql, $matches, PREG_OFFSET_CAPTURE)) {
            $countGroup = isset($matches[2]) && $matches[2][1] !== -1 ? 2 : 1;
            $rowCount = (int) $matches[$countGroup][0];
            $limitedRowCount = min($rowCount, $maximumRows);
            $countOffset = $matches[$countGroup][1];

            return substr_replace($sql, (string) $limitedRowCount, $countOffset, strlen($matches[$countGroup][0]));
        }

        if (preg_match('/\bLIMIT\b/i', $tokens)) {
            throw new RuntimeException('El LIMIT de la consulta tiene un formato no permitido.');
        }

        return $sql . ' LIMIT ' . $maximumRows;
    }
}
