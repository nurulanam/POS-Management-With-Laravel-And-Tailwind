<?php

namespace App\Support;

class ReportCsv
{
    // Apply only to untrusted text, not typed numeric columns (negative money must stay numeric).
    public static function text(?string $value): string
    {
        $value ??= '';
        return preg_match('/^[\s\p{C}]*[=+\-@＝＋－＠]|^[\x00-\x1F\x7F]/u', $value) ? "'".$value : $value;
    }

    public static function download(string $filename, array $headings, iterable $rows)
    {
        // Rows are materialized within the controller's read transaction BEFORE headers.
        // Export limits fail as normal validation errors, never as a truncated CSV download.
        return response()->streamDownload(function () use ($headings, $rows) {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headings, ',', '"', '', "\r\n");
            foreach ($rows as $row) fputcsv($output, $row, ',', '"', '', "\r\n");
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
    }
}
