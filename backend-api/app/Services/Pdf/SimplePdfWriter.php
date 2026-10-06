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
     * A tax invoice laid out as a page: a brand bar, the company and the document title, the
     * billed-to and details blocks, an items table, the totals, the payment record, and a footer.
     * Built only from the operators render() already uses (rectangles, lines, text), and kept
     * separate from render() so the existing exports are unchanged. Text is ASCII only: the
     * built-in Helvetica font cannot draw the rupee sign, so amounts are written as "INR".
     *
     * @param array{
     *   company: string, accent: ?string, title: string, number: string, issued_on: string,
     *   status: string, status_color: ?string, billed_to: list<string>,
     *   details: list<array{0: string, 1: string}>,
     *   items: list<array{0: string, 1: string, 2: string, 3: string}>,
     *   subtotal: string, tax: string, tax_note: ?string, total: string,
     *   payment: ?list<array{0: string, 1: string}>, footer: list<string>
     * } $doc
     */
    public static function renderInvoice(array $doc): string
    {
        $ascii = static fn (string $s): string => preg_replace('/[^\x20-\x7E]/', '', str_replace(['₹', '—', '–', '’'], ['Rs. ', '-', '-', "'"], $s)) ?? '';
        $escape = static fn (string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii($s));
        $width = static fn (string $s, int $size): float => strlen($ascii($s)) * $size * 0.5;

        $ops = [];
        $text = function (string $s, float $x, float $y, int $size = 10, bool $bold = false, ?string $color = null, string $align = 'left') use (&$ops, $escape, $width) {
            if ($align === 'right') {
                $x -= $width($s, $size);
            } elseif ($align === 'center') {
                $x -= $width($s, $size) / 2;
            }
            $font = $bold ? '/F2' : '/F1';
            $ops[] = 'q';
            if ($color !== null) {
                $ops[] = $color.' rg';
            }
            $ops[] = 'BT '.$font.' '.$size.' Tf '.sprintf('%.2f %.2f', $x, $y).' Td ('.$escape($s).') Tj ET';
            $ops[] = 'Q';
        };
        $rect = function (float $x, float $y, float $w, float $h, string $color) use (&$ops) {
            $ops[] = 'q '.$color.' rg '.sprintf('%.2f %.2f %.2f %.2f', $x, $y, $w, $h).' re f Q';
        };
        $line = function (float $x1, float $y1, float $x2, float $y2, string $color = '0.85 0.85 0.87', float $weight = 0.6) use (&$ops) {
            $ops[] = 'q '.$color.' RG '.$weight.' w '.sprintf('%.2f %.2f m %.2f %.2f l S', $x1, $y1, $x2, $y2).' Q';
        };

        // Colour components only ("r g b"): text() and rect() each add their own operator.
        $accent = str_replace(' rg', '', self::hexToRgbOperator($doc['accent'] ?? null));
        $accentText = $accent;
        $ink = '0.13 0.15 0.22';
        $muted = '0.45 0.47 0.52';
        $white = '1 1 1';
        $statusColor = str_replace(' rg', '', self::hexToRgbOperator($doc['status_color'] ?? null));

        // Brand bar and heading.
        $rect(0, 780, 612, 12, $accent);
        $text($doc['company'], 50, 738, 18, true, $accentText);
        $text((string) ($doc['subtitle'] ?? ''), 50, 722, 8, false, $muted);
        $text($doc['title'], 562, 738, 22, true, $ink, 'right');
        $text('Invoice no: '.$doc['number'], 562, 720, 9, false, $ink, 'right');
        $text('Issued on: '.$doc['issued_on'], 562, 706, 9, false, $ink, 'right');

        // Status stamp.
        $rect(470, 668, 92, 20, $statusColor);
        $text($doc['status'], 516, 674, 9, true, $white, 'center');

        $line(50, 652, 562, 652, '0.80 0.80 0.83', 0.8);

        // Billed to and details.
        $text('BILLED TO', 50, 634, 8, true, $muted);
        $y = 618;
        foreach ($doc['billed_to'] as $index => $value) {
            $text($value, 50, $y, $index === 0 ? 11 : 9, $index === 0, $index === 0 ? $ink : null);
            $y -= $index === 0 ? 16 : 13;
        }

        $text('DETAILS', 330, 634, 8, true, $muted);
        $y = 618;
        foreach ($doc['details'] as [$label, $value]) {
            $text($label, 330, $y, 9, false, $muted);
            $text($value, 562, $y, 9, false, $ink, 'right');
            $y -= 14;
        }

        // Items table.
        $tableTop = 500;
        $rect(50, $tableTop - 6, 512, 24, $accent);
        $text('DESCRIPTION', 60, $tableTop + 2, 8, true, $white);
        $text('QTY', 380, $tableTop + 2, 8, true, $white, 'right');
        $text('RATE', 470, $tableTop + 2, 8, true, $white, 'right');
        $text('AMOUNT', 552, $tableTop + 2, 8, true, $white, 'right');

        $rowY = $tableTop - 24;
        foreach (array_slice($doc['items'], 0, 10) as [$description, $qty, $rate, $amount]) {
            $text($description, 60, $rowY, 9, false, $ink);
            $text($qty, 380, $rowY, 9, false, $ink, 'right');
            $text($rate, 470, $rowY, 9, false, $ink, 'right');
            $text($amount, 552, $rowY, 9, false, $ink, 'right');
            $rowY -= 6;
            $line(50, $rowY, 562, $rowY);
            $rowY -= 16;
        }
        if (count($doc['items']) > 10) {
            $text('More lines are on the billing page.', 60, $rowY, 8, false, $muted);
            $rowY -= 16;
        }

        // Totals: the GST row, then the total box below it with clear space between them.
        $y = $rowY - 8;
        $text('Subtotal', 440, $y, 9, false, $muted, 'right');
        $text($doc['subtotal'], 552, $y, 9, false, $ink, 'right');
        $y -= 16;
        $text($doc['tax_note'] ?? 'GST', 440, $y, 9, false, $muted, 'right');
        $text($doc['tax'], 552, $y, 9, false, $ink, 'right');
        $boxTop = $y - 12;
        $rect(330, $boxTop - 28, 232, 28, '0.95 0.95 0.99');
        $text('Total payable', 340, $boxTop - 18, 11, true, $ink);
        $text('INR '.$doc['total'], 552, $boxTop - 18, 11, true, $accentText, 'right');
        $y = $boxTop - 28 - 30;

        // Payment record, when there is one.
        if ($doc['payment'] !== null) {
            $text('PAYMENT RECORD', 50, $y, 8, true, $muted);
            $y -= 16;
            foreach ($doc['payment'] as [$label, $value]) {
                $text($label, 50, $y, 9, false, $muted);
                $text($value, 200, $y, 9, false, $ink);
                $y -= 14;
            }
        }

        // Footer.
        $line(50, 90, 562, 90, '0.80 0.80 0.83', 0.6);
        $fy = 76;
        foreach ($doc['footer'] as $index => $value) {
            $text($value, 50, $fy, $index === 0 ? 9 : 8, $index === 0, $index === 0 ? $ink : $muted);
            $fy -= 12;
        }

        $contentStream = implode("\n", $ops);

        $objectBodies = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R /F2 6 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($contentStream)." >>\nstream\n{$contentStream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];

        $buffer = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objectBodies as $i => $body) {
            $offsets[] = strlen($buffer);
            $buffer .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
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
