<?php

declare(strict_types=1);

namespace Cpdc\Http;

/**
 * CSV download. Cells that a spreadsheet would run as a formula (= + - @, the full-width ＠, tab, CR) get a
 * leading quote.
 */
final class Csv
{
    /**
     * @param list<string> $header
     * @param list<list<string|int|float|null>> $rows
     */
    public static function response(string $filename, array $header, array $rows): Response
    {
        $lines = [self::line($header)];
        foreach ($rows as $row) {
            $lines[] = self::line($row);
        }

        return new Response(200, "\u{FEFF}" . implode("\r\n", $lines) . "\r\n", [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $filename) . '"',
        ]);
    }

    /** @param list<string|int|float|null> $cells */
    private static function line(array $cells): string
    {
        return implode(',', array_map(static function ($cell): string {
            $text = (string) ($cell ?? '');
            $formula = $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true);
            if ($formula || str_starts_with($text, "\u{FF20}")) {
                $text = "'" . $text;
            }

            return '"' . str_replace('"', '""', $text) . '"';
        }, $cells));
    }
}
