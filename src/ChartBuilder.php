<?php
declare(strict_types=1);

final class ChartBuilder
{
    public static function build(string $question, array $rows, array $columns): array
    {
        $normalizedQuestion = self::normalize($question);
        if (!preg_match('/\b(grafic\w*|grafiqu\w*|chart|plot|barras?|linea|visualiz\w*|diagrama|tendencia|evolucion)\b/', $normalizedQuestion)) {
            return ['requested' => false, 'available' => false];
        }

        if ($rows === [] || $columns === []) {
            return ['requested' => true, 'available' => false, 'message' => 'No hay filas para graficar.'];
        }

        $valueColumn = self::chooseValueColumn($columns, $rows, $normalizedQuestion);
        if ($valueColumn === null) {
            return [
                'requested' => true,
                'available' => false,
                'message' => 'La consulta no devolvio una columna numerica que se pueda graficar.',
            ];
        }

        $labelColumn = self::chooseLabelColumn($columns, $rows, $valueColumn, $normalizedQuestion);
        $groups = [];
        foreach ($rows as $index => $row) {
            $value = self::numericValue($row[$valueColumn] ?? null);
            if ($value === null) {
                continue;
            }
            $label = $labelColumn === null
                ? 'Fila ' . ($index + 1)
                : (string) ($row[$labelColumn] ?? 'Sin dato');
            $groups[$label] = ($groups[$label] ?? 0) + $value;
        }

        if ($groups === []) {
            return ['requested' => true, 'available' => false, 'message' => 'No se encontraron valores numericos.'];
        }

        $isLine = preg_match('/\b(linea|line|tendencia|evolucion)\b/', $normalizedQuestion)
            || ($labelColumn !== null && preg_match('/(fecha|date|time|mes|month|year|ano)/', self::normalize($labelColumn)));
        if (!$isLine && count($groups) > 20) {
            arsort($groups);
            $groups = array_slice($groups, 0, 20, true);
        } elseif ($isLine && count($groups) > 30) {
            $groups = array_slice($groups, 0, 30, true);
        }

        return [
            'requested' => true,
            'available' => true,
            'type' => $isLine ? 'line' : 'bar',
            'title' => 'Grafica de ' . $valueColumn . ($labelColumn === null ? '' : ' por ' . $labelColumn),
            'x_label' => $labelColumn ?? 'Fila',
            'y_label' => $valueColumn,
            'labels' => array_map(
                static fn (string $label): string => mb_strlen($label) > 42 ? mb_substr($label, 0, 42) . '...' : $label,
                array_keys($groups)
            ),
            'values' => array_map(static fn ($value): float => round((float) $value, 2), array_values($groups)),
        ];
    }

    private static function chooseValueColumn(array $columns, array $rows, string $question): ?string
    {
        $preferredTerms = ['ingreso', 'venta', 'total', 'monto', 'importe', 'revenue', 'amount', 'sales', 'unidad', 'cantidad', 'count', 'qty'];
        $candidates = [];
        foreach ($columns as $column) {
            $name = self::normalize((string) $column);
            if (preg_match('/\b(id|codigo|code)\b/', $name)) {
                continue;
            }
            $numericCount = 0;
            foreach ($rows as $row) {
                if (self::numericValue($row[$column] ?? null) !== null) {
                    $numericCount++;
                }
            }
            if ($numericCount === 0) {
                continue;
            }

            $score = 0;
            if ($name !== '' && str_contains($question, $name)) {
                $score += 100;
            }
            foreach ($preferredTerms as $term) {
                if (str_contains($name, $term)) {
                    $score += 20;
                    break;
                }
            }
            $candidates[$column] = $score;
        }

        if ($candidates === []) {
            return null;
        }
        arsort($candidates);

        return (string) array_key_first($candidates);
    }

    private static function chooseLabelColumn(array $columns, array $rows, string $valueColumn, string $question): ?string
    {
        $wantsTime = (bool) preg_match('/\b(fecha|date|tiempo|mes|ano|anio|dia|linea|tendencia|evolucion)\b/', $question);
        foreach ($columns as $column) {
            if ($column === $valueColumn) {
                continue;
            }
            $values = array_filter(
                array_column($rows, $column),
                static fn ($value): bool => $value !== null && $value !== ''
            );
            if ($values === []) {
                continue;
            }
            $numericValues = count(array_filter($values, static fn ($value): bool => self::numericValue($value) !== null));
            if ($numericValues >= count($values) * 0.8) {
                continue;
            }

            $name = self::normalize((string) $column);
            $isDate = (bool) preg_match('/(fecha|date|time|mes|month|year|ano)/', $name);
            if (($wantsTime && $isDate) || (!$wantsTime && !$isDate)) {
                return (string) $column;
            }
        }

        return null;
    }

    private static function numericValue(mixed $value): ?float
    {
        if (!is_scalar($value) || $value === '') {
            return null;
        }
        $normalized = str_replace(',', '', trim((string) $value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private static function normalize(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return strtolower($ascii === false ? $text : $ascii);
    }
}
