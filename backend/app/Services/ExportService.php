<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export to open formats: CSV (RFC 4180, UTF-8 with BOM for Excel) and PDF.
 */
class ExportService
{
    /**
     * @param array<string,string> $columns key => label
     * @param iterable<array<string,mixed>> $rows
     */
    public function csv(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // empty escape character = strict RFC 4180 (and avoids the PHP 8.4 deprecation notice)
            fputcsv($out, array_values($columns), ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(
                    fn ($k) => $this->cell($row[$k] ?? null),
                    array_keys($columns)
                ), ',', '"', '');
            }
            fclose($out);
        }, $this->safeName($filename) . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param array{title: string, description?: string, columns: array<string,string>, rows: array} $report
     */
    public function pdf(string $filename, array $report, string $scopeLabel = '', array $extra = []): Response
    {
        $pdf = Pdf::loadView('pdf.report', [
            'report' => $report,
            'scopeLabel' => $scopeLabel,
            'generatedAt' => now(),
            'extra' => $extra,
        ])->setPaper('a4', count($report['columns']) > 5 ? 'landscape' : 'portrait');

        return $pdf->download($this->safeName($filename) . '.pdf');
    }

    private function cell(mixed $v): string
    {
        $text = match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'yes' : 'no',
            is_array($v) => implode('; ', $v),
            default => (string) $v,
        };

        // CSV injection guard: text starting with = + - @ would run as a formula in Excel / LibreOffice.
        // Product names come from Open Food Facts and users, so they are not trusted.
        if (! is_numeric($text) && preg_match('/^[=+\-@\t\r]/', $text)) {
            $text = "'" . $text;
        }
        return $text;
    }

    private function safeName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]+/', '_', $name) . '_' . now()->format('Ymd_His');
    }
}