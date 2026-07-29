<?php

declare(strict_types=1);

namespace Lib;

use RuntimeException;

/**
 * Writes real .xlsx workbooks and .csv files with only the `zip` extension.
 *
 * A minimal but valid OOXML package is produced:
 *   [Content_Types].xml, _rels/.rels, xl/workbook.xml,
 *   xl/_rels/workbook.xml.rels, xl/styles.xml, xl/worksheets/sheet1.xml
 *
 * Strings are written as inline strings so no sharedStrings part is needed.
 */
final class SpreadsheetWriter
{
    /**
     * Build an .xlsx in memory.
     *
     * @param list<string>             $headers
     * @param iterable<list<mixed>>    $rows
     * @param string                   $sheetName
     * @param list<string>             $columnFormats one of text|number|money|date per column ('' = auto)
     */
    public static function xlsx(array $headers, iterable $rows, string $sheetName = 'Sheet1', array $columnFormats = []): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException(
                'The PHP "zip" extension is required for Excel export. '
                . 'Enable it in cPanel > Select PHP Version > Extensions, or export CSV instead.'
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'lrms_xlsx_');
        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary file for the Excel export.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Could not create the Excel archive.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($headers, $rows, $columnFormats));

        $zip->close();

        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $content;
    }

    /**
     * Build a CSV string. A UTF-8 BOM is prepended so Excel on Windows shows
     * Hindi / Devanagari names correctly instead of mojibake.
     *
     * @param list<string>          $headers
     * @param iterable<list<mixed>> $rows
     */
    public static function csv(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary stream for the CSV export.');
        }

        fwrite($handle, "\xEF\xBB\xBF");

        // The $escape argument is passed explicitly: PHP 8.4 deprecates relying
        // on its default, and '' is the RFC 4180 behaviour Excel expects.
        if ($headers !== []) {
            fputcsv($handle, $headers, ',', '"', '');
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'csvCell'], $row), ',', '"', '');
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * Guard against CSV formula injection: a leading =, +, - or @ makes Excel
     * evaluate the cell, which is a real risk when the data came from an
     * uploaded file.
     */
    private static function csvCell(mixed $value): string
    {
        $string = $value === null ? '' : (string) $value;
        if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $string;
        }
        return $string;
    }

    // ------------------------------------------------------------------
    // OOXML parts
    // ------------------------------------------------------------------

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        // Sheet names cannot exceed 31 chars or contain : \ / ? * [ ]
        $name = preg_replace('#[:\\\\/?*\[\]]#', '-', $sheetName) ?? 'Sheet1';
        $name = mb_substr($name === '' ? 'Sheet1' : $name, 0, 31);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::esc($name) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /**
     * Style indexes used by sheet():
     *   0 = default, 1 = bold header, 2 = number (2dp), 3 = date (dd-mm-yyyy)
     */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="#,##0.00"/>'
            . '<numFmt numFmtId="165" formatCode="dd\-mm\-yyyy"/>'
            . '</numFmts>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0D5C46"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * @param list<string>          $headers
     * @param iterable<list<mixed>> $rows
     * @param list<string>          $columnFormats
     */
    private static function sheet(array $headers, iterable $rows, array $columnFormats): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Reasonable default widths so the export is readable without fiddling.
        if ($headers !== []) {
            $xml .= '<cols>';
            foreach ($headers as $i => $header) {
                $width = max(12, min(40, mb_strlen($header) + 4));
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        $rowNumber = 1;
        if ($headers !== []) {
            $xml .= '<row r="1">';
            foreach ($headers as $i => $header) {
                $xml .= self::cell(self::columnLetter($i) . '1', (string) $header, 'text', 1);
            }
            $xml .= '</row>';
            $rowNumber++;
        }

        foreach ($rows as $row) {
            $xml .= '<row r="' . $rowNumber . '">';
            $i = 0;
            foreach ($row as $value) {
                $format = $columnFormats[$i] ?? '';
                $xml .= self::cell(self::columnLetter($i) . $rowNumber, $value, $format, 0);
                $i++;
            }
            $xml .= '</row>';
            $rowNumber++;
        }

        $xml .= '</sheetData>';

        // Freeze the header row and enable autofilter.
        if ($headers !== []) {
            $lastCol = self::columnLetter(count($headers) - 1);
            $xml .= '<autoFilter ref="A1:' . $lastCol . ($rowNumber - 1) . '"/>';
        }

        $xml .= '</worksheet>';

        return $xml;
    }

    private static function cell(string $ref, mixed $value, string $format, int $headerStyle): string
    {
        if ($value === null || $value === '') {
            return '<c r="' . $ref . '"' . ($headerStyle > 0 ? ' s="' . $headerStyle . '"' : '') . '/>';
        }

        if ($headerStyle > 0) {
            return '<c r="' . $ref . '" s="' . $headerStyle . '" t="inlineStr"><is><t>'
                . self::esc((string) $value) . '</t></is></c>';
        }

        if ($format === 'money' || $format === 'number') {
            if (is_numeric($value)) {
                $style = $format === 'money' ? ' s="2"' : '';
                return '<c r="' . $ref . '"' . $style . '><v>' . (float) $value . '</v></c>';
            }
        }

        if ($format === 'date') {
            $serial = self::dateToSerial((string) $value);
            if ($serial !== null) {
                return '<c r="' . $ref . '" s="3"><v>' . $serial . '</v></c>';
            }
        }

        // Auto: numeric-looking strings that are NOT identifiers stay numeric.
        // Account numbers must stay text or Excel mangles them, so we only
        // treat a value as a number when the caller asked for it.
        return '<c r="' . $ref . '" t="inlineStr"><is><t>' . self::esc((string) $value) . '</t></is></c>';
    }

    private static function dateToSerial(string $date): ?int
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return null;
        }
        // 25569 days between 1900-01-01 (Excel serial 1) and 1970-01-01.
        return (int) floor($ts / 86400) + 25569;
    }

    public static function columnLetter(int $index): string
    {
        $letters = '';
        $index++;
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder) . $letters;
            $index = (int) (($index - $remainder - 1) / 26);
        }
        return $letters;
    }

    private static function esc(string $value): string
    {
        // Strip control characters that are illegal in XML 1.0.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
