<?php

namespace App\Services\Batches;

use DOMDocument;
use RuntimeException;
use ZipArchive;

/**
 * Reads the phone numbers from an uploaded CSV or XLSX list, without a spreadsheet library.
 *
 * The numbers are taken from the column whose header is a phone header (phone, mobile, number, whatsapp, ...); with no
 * such header, the first column is used. Each number is reduced to its digits and must be 10 to 15 digits long.
 * Duplicates are kept once, in file order. A list longer than MAX_ROWS is refused, so one upload stays bounded.
 */
class RecipientFileParser
{
    public const MAX_ROWS = 5000;

    private const PHONE_HEADERS = ['phone', 'phone_number', 'mobile', 'mobile_number', 'number', 'whatsapp', 'recipient_phone'];

    /** @return array{phones: list<string>, invalid: int, rows: int} */
    public function parse(string $path, string $extension): array
    {
        $rows = strtolower($extension) === 'xlsx' ? $this->xlsxRows($path) : $this->csvRows($path);

        return $this->phonesFrom($rows);
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{phones: list<string>, invalid: int, rows: int}
     */
    private function phonesFrom(array $rows): array
    {
        if ($rows === []) {
            return ['phones' => [], 'invalid' => 0, 'rows' => 0];
        }

        $column = 0;
        $start = 0;
        $headers = array_map(fn ($cell) => strtolower(trim((string) $cell)), $rows[0]);
        foreach ($headers as $index => $header) {
            if (in_array($header, self::PHONE_HEADERS, true)) {
                $column = $index;
                $start = 1;
                break;
            }
        }

        $phones = [];
        $invalid = 0;
        $count = 0;

        foreach (array_slice($rows, $start) as $row) {
            $raw = trim((string) ($row[$column] ?? ''));
            if ($raw === '') {
                continue;
            }

            $count++;
            if ($count > self::MAX_ROWS) {
                throw new RuntimeException('This file has more than '.self::MAX_ROWS.' numbers. Split it into smaller files.');
            }

            $phone = $this->normalize($raw);
            if ($phone === null) {
                $invalid++;

                continue;
            }

            $phones[$phone] = true;
        }

        // Keys of a PHP array that look like integers come back as ints; cast them to strings for the phone list.
        return ['phones' => array_map('strval', array_keys($phones)), 'invalid' => $invalid, 'rows' => $count];
    }

    /** The digits of a phone cell, or null when it cannot be a phone number (10 to 15 digits). */
    public function normalize(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    /** @return list<list<string>> */
    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The file could not be read.');
        }

        $rows = [];
        $first = true;
        while (($row = fgetcsv($handle)) !== false) {
            if ($first) {
                // A UTF-8 byte order mark from Excel would otherwise stick to the first header.
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($row[0] ?? '')) ?? '';
                $first = false;
            }
            $rows[] = array_map(fn ($cell) => (string) $cell, $row);
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<list<string>> the first worksheet, one list per row, cells in column order */
    private function xlsxRows(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The Excel file could not be opened.');
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $shared = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        if ($sheet === false) {
            throw new RuntimeException('The Excel file has no first worksheet.');
        }

        $strings = $shared !== false ? $this->sharedStrings($shared) : [];

        $dom = new DOMDocument;
        $dom->loadXML($sheet);

        $rows = [];
        foreach ($dom->getElementsByTagName('row') as $rowNode) {
            $cells = [];
            foreach ($rowNode->getElementsByTagName('c') as $cell) {
                $index = $this->columnIndex((string) $cell->getAttribute('r'));
                $cells[$index] = $this->cellValue($cell, $strings);
            }

            if ($cells === []) {
                $rows[] = [];

                continue;
            }

            $row = [];
            for ($i = 0; $i <= max(array_keys($cells)); $i++) {
                $row[] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<string> */
    private function sharedStrings(string $xml): array
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml);

        $strings = [];
        foreach ($dom->getElementsByTagName('si') as $item) {
            $strings[] = $item->textContent;
        }

        return $strings;
    }

    /** @param list<string> $strings */
    private function cellValue(\DOMElement $cell, array $strings): string
    {
        $type = (string) $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            return $cell->textContent;
        }

        $value = $cell->getElementsByTagName('v')->item(0)?->textContent ?? '';

        if ($type === 's') {
            return $strings[(int) $value] ?? '';
        }

        return $value;
    }

    /** "A" -> 0, "AA" -> 26; reads the letters of a cell reference such as "B12". */
    private function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $match);
        $letters = strtoupper($match[0] ?? 'A');

        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }
}
