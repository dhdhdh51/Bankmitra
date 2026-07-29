<?php

declare(strict_types=1);

namespace Lib;

use RuntimeException;

/**
 * Reads .xlsx and .csv files with nothing but the `zip` and `xml` extensions
 * (both standard on cPanel). No PhpSpreadsheet, no Composer.
 *
 * .xlsx is a ZIP archive of XML parts:
 *   xl/workbook.xml          - sheet names + relationship ids
 *   xl/_rels/workbook.xml.rels - maps rId -> worksheets/sheetN.xml
 *   xl/sharedStrings.xml     - deduplicated string table
 *   xl/styles.xml            - number formats (used to detect date columns)
 *   xl/worksheets/sheetN.xml - the actual cells
 *
 * Rows are streamed with XMLReader so a 50 000-row allocation file does not
 * exhaust the 128 MB memory limit typical of shared hosting.
 *
 * NOTE: the legacy binary .xls format is NOT supported. The upload screen
 * tells the user to "Save As -> .xlsx" or export CSV.
 */
final class SpreadsheetReader
{
    /** @var list<string> shared string table */
    private array $sharedStrings = [];

    /** @var array<int,bool> xf index => is a date format */
    private array $dateStyles = [];

    private string $path;
    private bool $isCsv;

    public function __construct(string $path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Uploaded file could not be read from disk.');
        }
        $this->path = $path;
        $this->isCsv = $this->detectCsv($path);
    }

    private function detectCsv(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Uploaded file could not be opened.');
        }
        $magic = (string) fread($handle, 4);
        fclose($handle);

        // .xlsx (and every OOXML file) starts with the ZIP magic "PK\x03\x04".
        return strncmp($magic, "PK\x03\x04", 4) !== 0;
    }

    /**
     * Read the whole sheet into memory as a list of rows.
     * Use rows() instead for large files.
     *
     * @return list<list<string>>
     */
    public function toArray(int $sheetIndex = 0, int $limit = 0): array
    {
        $out = [];
        foreach ($this->rows($sheetIndex) as $row) {
            $out[] = $row;
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Generator of rows. Each row is a list of trimmed string values,
     * padded so every row has the same number of columns as the header.
     *
     * @return \Generator<int,list<string>>
     */
    public function rows(int $sheetIndex = 0): \Generator
    {
        if ($this->isCsv) {
            yield from $this->csvRows();
            return;
        }
        yield from $this->xlsxRows($sheetIndex);
    }

    /**
     * Convenience: read the header row and return rows as associative
     * arrays keyed by a normalised header name (lowercase, underscores).
     *
     * @return \Generator<int,array<string,string>>
     */
    public function assocRows(int $sheetIndex = 0): \Generator
    {
        $header = null;
        $rowNumber = 0;

        foreach ($this->rows($sheetIndex) as $row) {
            $rowNumber++;

            if ($header === null) {
                // Skip fully blank leading rows before the real header.
                if (implode('', $row) === '') {
                    continue;
                }
                $header = array_map([self::class, 'normaliseHeader'], $row);
                continue;
            }

            if (implode('', $row) === '') {
                continue;
            }

            $assoc = [];
            foreach ($header as $i => $name) {
                if ($name === '') {
                    continue;
                }
                $assoc[$name] = $row[$i] ?? '';
            }
            $assoc['__row'] = (string) $rowNumber;

            yield $assoc;
        }
    }

    /** "BC CODE" / "bc-code" / "Bc  Code" all become "bc_code". */
    public static function normaliseHeader(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(["\xc2\xa0", '-', '.', '/', '(', ')'], ' ', $value);
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);
        return trim($value, '_');
    }

    // ------------------------------------------------------------------
    // CSV
    // ------------------------------------------------------------------

    /** @return \Generator<int,list<string>> */
    private function csvRows(): \Generator
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('CSV file could not be opened.');
        }

        // Strip a UTF-8 BOM if Excel added one.
        $bom = (string) fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $delimiter = $this->sniffDelimiter();

        try {
            // $escape is passed explicitly because PHP 8.4 deprecates its
            // default, and '' matches how Excel actually writes CSV.
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if ($row === [null]) {
                    continue; // blank line
                }
                yield array_map(
                    static fn ($v): string => trim((string) $v),
                    $row
                );
            }
        } finally {
            fclose($handle);
        }
    }

    private function sniffDelimiter(): string
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            return ',';
        }
        $sample = (string) fread($handle, 8192);
        fclose($handle);

        $counts = [
            ','  => substr_count($sample, ','),
            ';'  => substr_count($sample, ';'),
            "\t" => substr_count($sample, "\t"),
            '|'  => substr_count($sample, '|'),
        ];
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? (string) $best : ',';
    }

    // ------------------------------------------------------------------
    // XLSX
    // ------------------------------------------------------------------

    /** @return list<string> visible sheet names */
    public function sheetNames(): array
    {
        if ($this->isCsv) {
            return ['CSV'];
        }

        $zip = $this->openZip();
        $xml = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        if ($xml === false) {
            return [];
        }

        $names = [];
        if (preg_match_all('/<sheet\b[^>]*name="([^"]*)"/i', $xml, $m) > 0) {
            foreach ($m[1] as $name) {
                $names[] = html_entity_decode($name, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        return $names;
    }

    /** @return \Generator<int,list<string>> */
    private function xlsxRows(int $sheetIndex): \Generator
    {
        $zip = $this->openZip();

        $this->loadSharedStrings($zip);
        $this->loadDateStyles($zip);
        $sheetPath = $this->resolveSheetPath($zip, $sheetIndex);

        // XMLReader cannot read from a ZIP entry directly, so extract the one
        // sheet we need to a temp file and stream that.
        $tmp = tempnam(sys_get_temp_dir(), 'lrms_sheet_');
        if ($tmp === false) {
            $zip->close();
            throw new RuntimeException('Could not create a temporary file to parse the spreadsheet.');
        }

        $stream = $zip->getStream($sheetPath);
        if ($stream === false) {
            $zip->close();
            @unlink($tmp);
            throw new RuntimeException('Worksheet "' . $sheetPath . '" is missing from the .xlsx file.');
        }

        $out = fopen($tmp, 'wb');
        if ($out === false) {
            fclose($stream);
            $zip->close();
            @unlink($tmp);
            throw new RuntimeException('Could not write the temporary worksheet file.');
        }
        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);
        $zip->close();

        try {
            yield from $this->streamSheet($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** @return \Generator<int,list<string>> */
    private function streamSheet(string $file): \Generator
    {
        $reader = new \XMLReader();
        if (!$reader->open($file, 'UTF-8', LIBXML_NOENT | LIBXML_NONET)) {
            throw new RuntimeException('Could not parse the worksheet XML.');
        }

        $maxColumns = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'row') {
                    continue;
                }

                $rowXml = $reader->readOuterXml();
                if ($rowXml === '') {
                    continue;
                }

                $row = $this->parseRow($rowXml);

                // Keep the row rectangle consistent with the widest row so far
                // (Excel omits trailing empty cells).
                $maxColumns = max($maxColumns, count($row));
                while (count($row) < $maxColumns) {
                    $row[] = '';
                }

                yield $row;
            }
        } finally {
            $reader->close();
        }
    }

    /** @return list<string> */
    private function parseRow(string $rowXml): array
    {
        $row = [];

        $doc = new \SimpleXMLElement($rowXml, LIBXML_NOENT | LIBXML_NONET);
        foreach ($doc->c as $cell) {
            $attributes = $cell->attributes();
            $ref = (string) ($attributes['r'] ?? '');
            $type = (string) ($attributes['t'] ?? '');
            $styleIndex = isset($attributes['s']) ? (int) $attributes['s'] : -1;

            $columnIndex = $ref === '' ? count($row) : self::columnIndex($ref);

            // Pad any columns Excel skipped because they were empty.
            while (count($row) < $columnIndex) {
                $row[] = '';
            }

            $row[] = $this->cellValue($cell, $type, $styleIndex);
        }

        return $row;
    }

    private function cellValue(\SimpleXMLElement $cell, string $type, int $styleIndex): string
    {
        // Inline string
        if ($type === 'inlineStr') {
            return trim($this->extractText($cell->is));
        }

        // Shared string
        if ($type === 's') {
            $index = (int) (string) $cell->v;
            return $this->sharedStrings[$index] ?? '';
        }

        // Boolean
        if ($type === 'b') {
            return ((string) $cell->v) === '1' ? '1' : '0';
        }

        // Formula error
        if ($type === 'e') {
            return '';
        }

        // Formula results already carry <v>; str type carries the formula text.
        $raw = trim((string) $cell->v);
        if ($raw === '') {
            return '';
        }

        // Excel stores dates as serial numbers - convert when the cell's
        // number format says it is a date.
        if ($type === '' && is_numeric($raw) && ($this->dateStyles[$styleIndex] ?? false)) {
            return self::excelSerialToDate((float) $raw);
        }

        return $raw;
    }

    private function extractText(?\SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        // Rich text is split across multiple <r><t> runs.
        $text = '';
        if (isset($node->t)) {
            foreach ($node->t as $t) {
                $text .= (string) $t;
            }
        }
        if (isset($node->r)) {
            foreach ($node->r as $run) {
                $text .= (string) $run->t;
            }
        }
        return $text === '' ? (string) $node : $text;
    }

    /** "C" -> 2, "AB" -> 27 (zero-based). */
    public static function columnIndex(string $cellRef): int
    {
        if (preg_match('/^([A-Z]+)/i', $cellRef, $m) !== 1) {
            return 0;
        }
        $letters = strtoupper($m[1]);
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1;
    }

    /**
     * Excel serial date -> Y-m-d (or Y-m-d H:i:s when there is a time part).
     * Day 1 is 1900-01-01, and Excel wrongly counts 1900 as a leap year,
     * which is why the epoch offset is 25569 days from the Unix epoch.
     */
    public static function excelSerialToDate(float $serial): string
    {
        if ($serial <= 0) {
            return '';
        }

        $days = (int) floor($serial);
        $fraction = $serial - $days;

        // Serial 60 is Excel's phantom 1900-02-29.
        if ($days < 60) {
            $days++;
        }

        $timestamp = ($days - 25569) * 86400;
        $timestamp += (int) round($fraction * 86400);

        if ($timestamp < -2208988800) { // before 1900
            return '';
        }

        return $fraction > 0.00001
            ? gmdate('Y-m-d H:i:s', $timestamp)
            : gmdate('Y-m-d', $timestamp);
    }

    private function openZip(): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException(
                'The PHP "zip" extension is not enabled, so .xlsx files cannot be read. '
                . 'Enable it in cPanel > Select PHP Version > Extensions, or upload a CSV instead.'
            );
        }

        $zip = new \ZipArchive();
        if ($zip->open($this->path) !== true) {
            throw new RuntimeException(
                'This file is not a valid .xlsx workbook. If it is an old .xls file, '
                . 'open it in Excel and use "Save As" -> "Excel Workbook (.xlsx)", or export CSV.'
            );
        }
        return $zip;
    }

    private function loadSharedStrings(\ZipArchive $zip): void
    {
        $this->sharedStrings = [];

        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') {
            return;
        }

        $doc = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOENT | LIBXML_NONET);
        if ($doc === false) {
            Logger::warning('sharedStrings.xml could not be parsed; string cells may be empty.');
            return;
        }

        foreach ($doc->si as $si) {
            $this->sharedStrings[] = trim($this->extractText($si));
        }
    }

    /**
     * Work out which cell-format indexes represent dates, by looking at the
     * numFmtId of each <xf> in styles.xml.
     */
    private function loadDateStyles(\ZipArchive $zip): void
    {
        $this->dateStyles = [];

        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false || $xml === '') {
            return;
        }

        $doc = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOENT | LIBXML_NONET);
        if ($doc === false) {
            return;
        }

        // Built-in date/time formats (ECMA-376 18.8.30).
        $builtInDateFormats = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];

        // Custom formats: treat as a date when the code contains y/m/d/h tokens.
        $customDateFormats = [];
        if (isset($doc->numFmts->numFmt)) {
            foreach ($doc->numFmts->numFmt as $numFmt) {
                $id = (int) (string) $numFmt['numFmtId'];
                $code = strtolower((string) $numFmt['formatCode']);
                // Strip literal text in quotes/brackets before sniffing.
                $code = (string) preg_replace('/\[[^\]]*\]|"[^"]*"/', '', $code);
                if (preg_match('/(yy|dd|mmm|hh|ss)/', $code) === 1
                    || preg_match('/\bd\b.*\bm\b|\bm\b.*\by\b/', $code) === 1) {
                    $customDateFormats[] = $id;
                }
            }
        }

        $dateFormatIds = array_merge($builtInDateFormats, $customDateFormats);

        if (isset($doc->cellXfs->xf)) {
            $index = 0;
            foreach ($doc->cellXfs->xf as $xf) {
                $numFmtId = (int) (string) $xf['numFmtId'];
                $this->dateStyles[$index] = in_array($numFmtId, $dateFormatIds, true);
                $index++;
            }
        }
    }

    private function resolveSheetPath(\ZipArchive $zip, int $sheetIndex): string
    {
        // Map rIdN -> target path from the workbook relationships.
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relations = [];
        if ($relsXml !== false && preg_match_all(
            '/<Relationship\b[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"/i',
            $relsXml,
            $m,
            PREG_SET_ORDER
        ) > 0) {
            foreach ($m as $match) {
                $relations[$match[1]] = ltrim(str_replace('/xl/', '', $match[2]), '/');
            }
        }

        // Ordered list of sheets from workbook.xml.
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $sheetRels = [];
        if ($workbookXml !== false && preg_match_all('/<sheet\b[^>]*>/i', $workbookXml, $m) > 0) {
            foreach ($m[0] as $tag) {
                if (preg_match('/r:id="([^"]+)"/i', $tag, $rm) === 1) {
                    $sheetRels[] = $rm[1];
                }
            }
        }

        $rid = $sheetRels[$sheetIndex] ?? null;
        if ($rid !== null && isset($relations[$rid])) {
            $target = $relations[$rid];
            $candidate = str_starts_with($target, 'worksheets/') ? 'xl/' . $target : 'xl/' . ltrim($target, '/');
            if ($zip->locateName($candidate) !== false) {
                return $candidate;
            }
        }

        // Fallback to the conventional path.
        $fallback = 'xl/worksheets/sheet' . ($sheetIndex + 1) . '.xml';
        if ($zip->locateName($fallback) !== false) {
            return $fallback;
        }

        throw new RuntimeException('No worksheet found at index ' . $sheetIndex . ' in this workbook.');
    }
}
