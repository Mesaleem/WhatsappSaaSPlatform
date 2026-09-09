<?php

namespace App\Services\Pdf;

/**
 * Hand-rolled, dependency-free minimal PDF 1.4 writer: one page, Helvetica
 * 11pt, one string per line. Extracted from Module 7's ExportController
 * (which introduced it) now that Module 8's BillingController needs the
 * exact same capability for invoice PDFs — a second real consumer is what
 * justifies pulling this into a shared service rather than leaving it
 * duplicated.
 *
 * WHY NOT A LIBRARY: composer.json has no PDF package (no dompdf/mpdf/
 * tcpdf), and none can be installed from this session — there is no
 * php/composer binary reachable in this environment. This writes valid
 * PDF bytes directly against the file format spec instead. The byte-
 * offset/xref-table algorithm was prototyped in Python and validated with
 * `qpdf --check` (0 structural errors) and `pdftotext` (correct text
 * extraction) before being ported here — see the Module 7 report for that
 * verification. If a richer, styled report is ever wanted,
 * `composer require barryvdh/laravel-dompdf` is the natural upgrade path.
 */
class SimplePdfWriter
{
    /** Letter page (612x792pt); text starts at y=740 and steps down 14pt/line. */
    private const MAX_LINES = 45;

    /**
     * @param list<string|null> $lines Null entries are dropped before
     *        rendering (lets callers build a line list with conditional
     *        entries via array_filter-free inline ternaries).
     */
    public static function render(array $lines): string
    {
        $lines = array_values(array_filter($lines, fn ($line) => $line !== null));

        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, 0, self::MAX_LINES - 1);
            $lines[] = '... (truncated)';
        }

        $escape = static fn (string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);

        $contentLines = ['BT', '/F1 11 Tf', '50 740 Td', '14 TL'];
        $first = true;
        foreach ($lines as $line) {
            if (! $first) {
                $contentLines[] = 'T*';
            }
            $contentLines[] = '('.$escape((string) $line).') Tj';
            $first = false;
        }
        $contentLines[] = 'ET';
        $contentStream = implode("\n", $contentLines);

        $objectBodies = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($contentStream)." >>\nstream\n{$contentStream}\nendstream",
        ];

        $buffer = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objectBodies as $i => $body) {
            $num = $i + 1;
            $offsets[] = strlen($buffer);
            $buffer .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($buffer);
        $n = count($objectBodies);
        $buffer .= "xref\n0 ".($n + 1)."\n";
        $buffer .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $buffer .= sprintf("%010d 00000 n \n", $offset);
        }

        $buffer .= "trailer\n<< /Size ".($n + 1)." /Root 1 0 R >>\n";
        $buffer .= "startxref\n{$xrefOffset}\n%%EOF";

        return $buffer;
    }
}
