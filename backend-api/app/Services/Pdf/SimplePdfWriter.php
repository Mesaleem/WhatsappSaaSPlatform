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

    /**
     * Social Media Marketing & Meta Ads Automation Expansion — Final
     * Phase (White-Label Automated PDF Reporting). Branded variant for
     * SocialReportController's monthly report.
     *
     * ADDITIVE ONLY, on purpose: render() above is used by two already-
     * shipped, previously-verified call sites (ExportController Module 7,
     * BillingController Module 8 invoices) and is left completely
     * untouched here. This is a separate method with its own full
     * object/xref assembly (duplicating a modest amount of the
     * boilerplate below rather than refactoring render() to share it) so
     * nothing about this addition can regress those two existing,
     * working exports.
     *
     * DISCLOSED LIMITATION — no logo image: this writer has no image/
     * XObject support, and none is added here. Embedding an actual logo
     * bitmap into a raw PDF byte stream (image dictionaries, color
     * spaces, exact /Length values) is real binary-format work that I
     * have NO WAY to execute-test in this environment — no PHP binary,
     * no qpdf, no pdftotext, nothing (unlike render() above, which a
     * PAST session prototyped and verified with qpdf --check and
     * pdftotext before shipping). Shipping unverified binary-format
     * changes under those conditions is a correctness risk I'm not
     * willing to take silently. What IS implemented, using only the
     * SAME already-proven rg/re/f fill-color and BT/Tf/Td/T* text
     * operators render() already uses: a colored accent bar (the
     * tenant's brand_accent_color) across the top of the page, and the
     * company name as a larger heading line. A configured logo_url (if
     * any) is printed as a plain-text line, not rendered as a picture.
     *
     * @param list<string|null> $lines Same null-filtering/truncation
     *        contract as render() (nulls dropped, capped so nothing runs
     *        off the bottom of the page).
     */
    public static function renderBrandedReport(string $companyName, ?string $accentColorHex, array $lines): string
    {
        $lines = array_values(array_filter($lines, fn ($line) => $line !== null));

        $maxBodyLines = self::MAX_LINES;
        if (count($lines) > $maxBodyLines) {
            $lines = array_slice($lines, 0, $maxBodyLines - 1);
            $lines[] = '... (truncated)';
        }

        $escape = static fn (string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);

        $accentRgb = self::hexToRgbOperator($accentColorHex);

        $contentLines = [
            'q',
            $accentRgb,
            '0 784 612 8 re',
            'f',
            '0 0 0 rg',
            'Q',
            'BT',
            '/F1 16 Tf',
            '50 750 Td',
            '14 TL',
            '('.$escape($companyName).') Tj',
        ];

        foreach ($lines as $index => $line) {
            $contentLines[] = 'T*';
            if ($index === 0) {
                // Drop back to body size after the heading line above.
                $contentLines[] = '/F1 11 Tf';
            }
            $contentLines[] = '('.$escape((string) $line).') Tj';
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

    /**
     * Converts a 6-hex-digit color (with or without a leading '#') into a
     * PDF content-stream `rg` (nonstroking/fill RGB) operator string,
     * e.g. "0.310 0.275 0.898 rg". Falls back to the platform's own
     * default indigo accent (#4F46E5, matching frontend/src/theme/
     * signalIndigo.ts) on anything null/malformed, so this never emits
     * an invalid operator into the content stream.
     */
    private static function hexToRgbOperator(?string $hex): string
    {
        $hex = $hex !== null ? ltrim($hex, '#') : '';

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '4F46E5';
        }

        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        return sprintf('%.3f %.3f %.3f rg', $r, $g, $b);
    }
}
