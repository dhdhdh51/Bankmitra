<?php

declare(strict_types=1);

namespace Lib;

use RuntimeException;

/**
 * Minimal PDF 1.4 writer - no Composer, no mPDF/TCPDF, nothing to install.
 *
 * Why hand-rolled? The project must deploy by uploading files to cPanel with
 * no `composer install` step. mPDF and TCPDF are thousands of files; this is
 * one. It uses only the base-14 Type1 fonts (Helvetica family), which every
 * PDF reader has built in, so no font files are shipped either.
 *
 * Coordinates are in MILLIMETRES with the origin at the TOP-LEFT of the page
 * (the natural way to lay out a report). Conversion to PDF's bottom-left
 * point space happens internally.
 *
 * Supported: multi-page, text with wrapping and alignment, lines, rectangles,
 * tables with repeating headers, JPEG/PNG images, and vector QR codes.
 *
 * LIMITATION - text is encoded as WinAnsi (CP1252). Devanagari and other
 * non-Latin scripts cannot be rendered by the base-14 fonts, so such text is
 * transliterated to ASCII. See docs/DEPLOYMENT.md if you need true Hindi PDFs.
 */
final class Pdf
{
    private const MM_TO_PT = 2.834645669;

    /** Page sizes in millimetres. */
    private const SIZES = [
        'A4'     => [210.0, 297.0],
        'A5'     => [148.0, 210.0],
        'LETTER' => [215.9, 279.4],
        'LEGAL'  => [215.9, 355.6],
    ];

    private float $pageWidth;
    private float $pageHeight;

    private float $marginLeft = 14.0;
    private float $marginRight = 14.0;
    private float $marginTop = 14.0;
    private float $marginBottom = 16.0;

    /** @var list<string> content stream of each page */
    private array $pages = [];

    private string $buffer = '';
    private int $currentPage = -1;

    private string $fontFamily = 'helvetica';
    private string $fontStyle = '';
    private float $fontSize = 10.0;

    /** @var array<string,int> font resource name => object number placeholder */
    private array $usedFonts = [];

    /**
     * @var list<array{data:string,width:int,height:int,filter:string,colorSpace:string,bpc:int,transparency:?string}>
     */
    private array $images = [];

    /** @var array<string,int> sha1(file) => index in $images */
    private array $imageCache = [];

    private string $title = 'LRMS Report';
    private string $author = 'LRMS';

    /** Callback rendering the footer of every page: fn(Pdf $pdf, int $pageNo) */
    private $footerCallback = null;

    private float $cursorY = 0.0;

    public function __construct(string $orientation = 'P', string $size = 'A4')
    {
        $size = strtoupper($size);
        if (!isset(self::SIZES[$size])) {
            throw new RuntimeException('Unsupported page size: ' . $size);
        }

        [$w, $h] = self::SIZES[$size];
        if (strtoupper($orientation) === 'L') {
            [$w, $h] = [$h, $w];
        }

        $this->pageWidth = $w;
        $this->pageHeight = $h;
    }

    // ------------------------------------------------------------------
    // Document setup
    // ------------------------------------------------------------------

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setAuthor(string $author): void
    {
        $this->author = $author;
    }

    public function setMargins(float $left, float $top, float $right, float $bottom): void
    {
        $this->marginLeft = $left;
        $this->marginTop = $top;
        $this->marginRight = $right;
        $this->marginBottom = $bottom;
    }

    public function setFooter(?callable $callback): void
    {
        $this->footerCallback = $callback;
    }

    public function pageWidth(): float
    {
        return $this->pageWidth;
    }

    public function pageHeight(): float
    {
        return $this->pageHeight;
    }

    public function contentWidth(): float
    {
        return $this->pageWidth - $this->marginLeft - $this->marginRight;
    }

    public function marginLeft(): float
    {
        return $this->marginLeft;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Current vertical cursor (mm from the top of the page). */
    public function y(): float
    {
        return $this->cursorY;
    }

    public function setY(float $y): void
    {
        $this->cursorY = $y;
    }

    public function moveY(float $delta): void
    {
        $this->cursorY += $delta;
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    public function addPage(): void
    {
        if ($this->currentPage >= 0) {
            $this->renderFooter();
            $this->pages[$this->currentPage] = $this->buffer;
        }

        $this->buffer = '';
        $this->pages[] = '';
        $this->currentPage = count($this->pages) - 1;
        $this->cursorY = $this->marginTop;

        // Re-declare the graphics state on every page.
        $this->raw('1 w');
        $this->setDrawColor(30, 30, 30);
        $this->setFillColor(0, 0, 0);
        $this->setTextColor(0, 0, 0);
    }

    /**
     * Ensure $needed mm of vertical space remain, starting a new page if not.
     * Returns true when a page break happened.
     */
    public function ensureSpace(float $needed): bool
    {
        if ($this->currentPage < 0) {
            $this->addPage();
            return true;
        }
        if ($this->cursorY + $needed <= $this->pageHeight - $this->marginBottom) {
            return false;
        }
        $this->addPage();
        return true;
    }

    private function renderFooter(): void
    {
        if ($this->footerCallback === null) {
            return;
        }
        $savedY = $this->cursorY;
        ($this->footerCallback)($this, $this->currentPage + 1);
        $this->cursorY = $savedY;
    }

    // ------------------------------------------------------------------
    // Colours
    // ------------------------------------------------------------------

    public function setDrawColor(int $r, int $g, int $b): void
    {
        $this->raw(sprintf('%.3F %.3F %.3F RG', $r / 255, $g / 255, $b / 255));
    }

    public function setFillColor(int $r, int $g, int $b): void
    {
        $this->raw(sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255));
    }

    /** Text colour is tracked separately so fills do not clobber it. */
    private string $textColor = '0 0 0 rg';

    public function setTextColor(int $r, int $g, int $b): void
    {
        $this->textColor = sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255);
    }

    public function setLineWidth(float $mm): void
    {
        $this->raw(sprintf('%.3F w', $mm * self::MM_TO_PT));
    }

    // ------------------------------------------------------------------
    // Text
    // ------------------------------------------------------------------

    /** @param ''|'B'|'I'|'BI' $style */
    public function setFont(string $style = '', float $size = 10.0, string $family = 'helvetica'): void
    {
        $this->fontFamily = strtolower($family);
        $this->fontStyle = strtoupper($style);
        $this->fontSize = $size;
        $this->usedFonts[$this->fontResourceName()] = 1;
    }

    private function fontResourceName(): string
    {
        return 'F' . match ($this->fontStyle) {
            'B'  => '2',
            'I'  => '3',
            'BI' => '4',
            default => '1',
        };
    }

    private function baseFontName(): string
    {
        return match ($this->fontStyle) {
            'B'  => 'Helvetica-Bold',
            'I'  => 'Helvetica-Oblique',
            'BI' => 'Helvetica-BoldOblique',
            default => 'Helvetica',
        };
    }

    /**
     * Draw a single line of text. $y is the BASELINE offset in mm from the
     * top of the page; pass the cursor for normal flow.
     */
    public function text(float $x, float $y, string $text): void
    {
        $encoded = $this->escape($this->toWinAnsi($text));
        $this->raw(sprintf(
            'BT %s /%s %.2F Tf %.2F %.2F Td (%s) Tj ET',
            $this->textColor,
            $this->fontResourceName(),
            $this->fontSize,
            $x * self::MM_TO_PT,
            ($this->pageHeight - $y) * self::MM_TO_PT,
            $encoded
        ));
        $this->usedFonts[$this->fontResourceName()] = 1;
    }

    /**
     * Draw text inside a box of given width with alignment.
     * @param 'L'|'C'|'R' $align
     */
    public function cell(float $x, float $y, float $width, string $text, string $align = 'L'): void
    {
        $textWidth = $this->textWidth($text);
        $drawX = match ($align) {
            'C' => $x + ($width - $textWidth) / 2,
            'R' => $x + $width - $textWidth,
            default => $x,
        };
        $this->text(max($x, $drawX), $y, $text);
    }

    /** Width of $text in mm at the current font and size. */
    public function textWidth(string $text): float
    {
        $widths = self::charWidths($this->fontStyle);
        $ansi = $this->toWinAnsi($text);
        $total = 0;
        for ($i = 0, $n = strlen($ansi); $i < $n; $i++) {
            $total += $widths[ord($ansi[$i])] ?? 556;
        }
        // Widths are in 1/1000 em.
        return ($total * $this->fontSize / 1000) / self::MM_TO_PT;
    }

    /**
     * Word-wrap $text into $width and draw it, advancing the cursor.
     * Returns the y position just below the last line.
     */
    public function textBlock(float $x, float $y, float $width, string $text, float $lineHeight = 4.6): float
    {
        foreach ($this->wrap($text, $width) as $line) {
            $this->text($x, $y, $line);
            $y += $lineHeight;
        }
        return $y;
    }

    /**
     * Flow text at the current cursor, breaking pages as needed.
     */
    public function write(string $text, float $lineHeight = 4.6): void
    {
        foreach ($this->wrap($text, $this->contentWidth()) as $line) {
            $this->ensureSpace($lineHeight);
            $this->text($this->marginLeft, $this->cursorY + $lineHeight * 0.75, $line);
            $this->cursorY += $lineHeight;
        }
    }

    /** @return list<string> */
    public function wrap(string $text, float $width): array
    {
        $lines = [];
        // Honour explicit newlines first.
        foreach (preg_split('/\R/', $text) ?: [$text] as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph === '') {
                $lines[] = '';
                continue;
            }

            $current = '';
            foreach (explode(' ', $paragraph) as $word) {
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if ($this->textWidth($candidate) <= $width) {
                    $current = $candidate;
                    continue;
                }
                if ($current !== '') {
                    $lines[] = $current;
                }
                // A single word longer than the box: hard-split it.
                while ($this->textWidth($word) > $width && strlen($word) > 1) {
                    $cut = strlen($word);
                    while ($cut > 1 && $this->textWidth(substr($word, 0, $cut)) > $width) {
                        $cut--;
                    }
                    $lines[] = substr($word, 0, $cut);
                    $word = substr($word, $cut);
                }
                $current = $word;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
        }
        return $lines;
    }

    // ------------------------------------------------------------------
    // Shapes
    // ------------------------------------------------------------------

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->raw(sprintf(
            '%.2F %.2F m %.2F %.2F l S',
            $x1 * self::MM_TO_PT,
            ($this->pageHeight - $y1) * self::MM_TO_PT,
            $x2 * self::MM_TO_PT,
            ($this->pageHeight - $y2) * self::MM_TO_PT
        ));
    }

    /** @param 'S'|'F'|'DF' $style stroke / fill / both */
    public function rect(float $x, float $y, float $width, float $height, string $style = 'S'): void
    {
        $operator = match ($style) {
            'F'  => 'f',
            'DF' => 'B',
            default => 'S',
        };
        $this->raw(sprintf(
            '%.2F %.2F %.2F %.2F re %s',
            $x * self::MM_TO_PT,
            ($this->pageHeight - $y - $height) * self::MM_TO_PT,
            $width * self::MM_TO_PT,
            $height * self::MM_TO_PT,
            $operator
        ));
    }

    /** Filled rectangle in one call, restoring the previous fill colour. */
    public function filledRect(float $x, float $y, float $w, float $h, int $r, int $g, int $b): void
    {
        $this->raw('q');
        $this->setFillColor($r, $g, $b);
        $this->rect($x, $y, $w, $h, 'F');
        $this->raw('Q');
    }

    // ------------------------------------------------------------------
    // QR code (drawn as vectors - always crisp, no image object needed)
    // ------------------------------------------------------------------

    public function qrCode(QrCode $qr, float $x, float $y, float $sizeMm): void
    {
        $matrix = $qr->matrix();
        $modules = $qr->size();
        if ($modules === 0) {
            return;
        }

        $quiet = 2; // modules of white border
        $step = $sizeMm / ($modules + $quiet * 2);

        $this->raw('q');
        $this->setFillColor(255, 255, 255);
        $this->rect($x, $y, $sizeMm, $sizeMm, 'F');
        $this->setFillColor(0, 0, 0);

        // Merge horizontal runs into single rectangles - far fewer operators.
        for ($row = 0; $row < $modules; $row++) {
            $col = 0;
            while ($col < $modules) {
                if (!$matrix[$row][$col]) {
                    $col++;
                    continue;
                }
                $runStart = $col;
                while ($col < $modules && $matrix[$row][$col]) {
                    $col++;
                }
                $this->rect(
                    $x + ($quiet + $runStart) * $step,
                    $y + ($quiet + $row) * $step,
                    ($col - $runStart) * $step,
                    $step,
                    'F'
                );
            }
        }

        $this->raw('Q');
    }

    // ------------------------------------------------------------------
    // Images
    // ------------------------------------------------------------------

    /**
     * Place an image. JPEG is embedded as-is (DCTDecode); anything else is
     * re-encoded to JPEG with GD so we never need a PNG predictor decoder.
     *
     * Returns false (and logs) when the image is unusable, so a missing photo
     * degrades gracefully instead of corrupting the whole PDF.
     */
    public function image(string $path, float $x, float $y, float $maxWidth, float $maxHeight): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            Logger::warning('PDF image missing: ' . $path);
            return false;
        }

        $key = (string) sha1_file($path);
        if (isset($this->imageCache[$key])) {
            $index = $this->imageCache[$key];
        } else {
            $prepared = $this->prepareImage($path);
            if ($prepared === null) {
                return false;
            }
            $this->images[] = $prepared;
            $index = count($this->images) - 1;
            $this->imageCache[$key] = $index;
        }

        $image = $this->images[$index];

        // Preserve aspect ratio inside the box.
        $ratio = min($maxWidth / max(1, $image['width']), $maxHeight / max(1, $image['height']));
        $drawWidth = $image['width'] * $ratio;
        $drawHeight = $image['height'] * $ratio;

        $this->raw(sprintf(
            'q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q',
            $drawWidth * self::MM_TO_PT,
            $drawHeight * self::MM_TO_PT,
            $x * self::MM_TO_PT,
            ($this->pageHeight - $y - $drawHeight) * self::MM_TO_PT,
            $index
        ));

        return true;
    }

    /**
     * @return array{data:string,width:int,height:int,filter:string,colorSpace:string,bpc:int,transparency:?string}|null
     */
    private function prepareImage(string $path): ?array
    {
        $info = @getimagesize($path);
        if ($info === false) {
            Logger::warning('PDF image is not a valid image: ' . $path);
            return null;
        }

        $mime = (string) ($info['mime'] ?? '');

        // JPEG without CMYK / progressive weirdness can go straight in.
        if ($mime === 'image/jpeg' && (int) ($info['channels'] ?? 3) === 3) {
            $data = (string) file_get_contents($path);
            return [
                'data' => $data,
                'width' => (int) $info[0],
                'height' => (int) $info[1],
                'filter' => 'DCTDecode',
                'colorSpace' => 'DeviceRGB',
                'bpc' => 8,
                'transparency' => null,
            ];
        }

        if (!function_exists('imagecreatetruecolor')) {
            Logger::warning('PDF image needs conversion but GD is unavailable: ' . $path);
            return null;
        }

        $source = ImageProcessor::load($path, $mime);
        if ($source === null) {
            Logger::warning('PDF image could not be decoded: ' . $path);
            return null;
        }

        ob_start();
        imagejpeg($source, null, 88);
        $data = (string) ob_get_clean();
        $width = imagesx($source);
        $height = imagesy($source);
        imagedestroy($source);

        return [
            'data' => $data,
            'width' => $width,
            'height' => $height,
            'filter' => 'DCTDecode',
            'colorSpace' => 'DeviceRGB',
            'bpc' => 8,
            'transparency' => null,
        ];
    }

    // ------------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------------

    /**
     * Render a table, repeating the header on every page.
     *
     * @param list<string>              $headers
     * @param iterable<list<mixed>>     $rows
     * @param list<float>               $widths   column widths in mm
     * @param list<'L'|'C'|'R'>         $aligns
     */
    public function table(
        array $headers,
        iterable $rows,
        array $widths,
        array $aligns = [],
        float $rowHeight = 6.2,
        float $fontSize = 8.5
    ): void {
        $x0 = $this->marginLeft;
        $drawHeader = function () use ($headers, $widths, $x0, $rowHeight, $fontSize): void {
            $this->ensureSpace($rowHeight * 2);
            $this->filledRect($x0, $this->cursorY, array_sum($widths), $rowHeight, 13, 92, 70);
            $this->setFont('B', $fontSize);
            $this->setTextColor(255, 255, 255);
            $x = $x0;
            foreach ($headers as $i => $header) {
                $this->cell($x + 1.4, $this->cursorY + $rowHeight * 0.7, ($widths[$i] ?? 20) - 2.8, $header, 'L');
                $x += $widths[$i] ?? 20;
            }
            $this->setTextColor(0, 0, 0);
            $this->cursorY += $rowHeight;
        };

        $drawHeader();
        $this->setFont('', $fontSize);

        $stripe = false;
        foreach ($rows as $row) {
            if ($this->ensureSpace($rowHeight + 2)) {
                $drawHeader();
                $this->setFont('', $fontSize);
            }

            if ($stripe) {
                $this->filledRect($x0, $this->cursorY, array_sum($widths), $rowHeight, 244, 246, 248);
            }
            $stripe = !$stripe;

            $x = $x0;
            foreach (array_values($row) as $i => $value) {
                $width = $widths[$i] ?? 20;
                $align = $aligns[$i] ?? 'L';
                $string = $value === null ? '' : (string) $value;
                // Truncate rather than overflow into the next column.
                $string = $this->truncate($string, $width - 2.8);
                $this->cell($x + 1.4, $this->cursorY + $rowHeight * 0.7, $width - 2.8, $string, $align);
                $x += $width;
            }

            $this->cursorY += $rowHeight;
        }

        // Outer border.
        $this->raw('q');
        $this->setDrawColor(200, 205, 210);
        $this->setLineWidth(0.2);
        $this->raw('Q');
    }

    public function truncate(string $text, float $maxWidth): string
    {
        if ($this->textWidth($text) <= $maxWidth) {
            return $text;
        }
        $ellipsis = '...';
        $cut = strlen($text);
        while ($cut > 1 && $this->textWidth(substr($text, 0, $cut) . $ellipsis) > $maxWidth) {
            $cut--;
        }
        return substr($text, 0, max(1, $cut)) . $ellipsis;
    }

    /** Two-column label/value block, common in visit reports. */
    public function keyValue(float $x, float $width, string $label, string $value, float $lineHeight = 5.0): void
    {
        $labelWidth = $width * 0.42;
        $this->setFont('', 9);
        $this->setTextColor(90, 96, 104);
        // Text sits on its baseline; ~72% of the line height looks centred.
        $baseline = $this->cursorY + $lineHeight * 0.72;
        $this->cell($x, $baseline, $labelWidth, $label, 'L');
        $this->setTextColor(20, 22, 26);
        $this->setFont('B', 9);
        $lines = $this->wrap($value === '' ? '-' : $value, $width - $labelWidth);
        $y = $baseline;
        foreach ($lines as $line) {
            $this->text($x + $labelWidth, $y, $line);
            $y += $lineHeight;
        }
        $this->cursorY += max($lineHeight, $lineHeight * count($lines));
        $this->setFont('', 9);
        $this->setTextColor(0, 0, 0);
    }

    // ------------------------------------------------------------------
    // Output
    // ------------------------------------------------------------------

    public function output(): string
    {
        if ($this->currentPage < 0) {
            $this->addPage();
        }
        $this->renderFooter();
        $this->pages[$this->currentPage] = $this->buffer;

        $objects = [];
        $pageCount = count($this->pages);

        // Object numbering plan:
        //   1                      = Catalog
        //   2                      = Pages
        //   3..(2+n)               = Page objects
        //   (3+n)..(2+2n)          = Page content streams
        //   then fonts, then images, then Info
        $firstPageObject = 3;
        $firstContentObject = $firstPageObject + $pageCount;
        $firstFontObject = $firstContentObject + $pageCount;

        $fontNames = ['F1' => 'Helvetica', 'F2' => 'Helvetica-Bold', 'F3' => 'Helvetica-Oblique', 'F4' => 'Helvetica-BoldOblique'];
        $fontObjectNumbers = [];
        $n = $firstFontObject;
        foreach ($fontNames as $resource => $base) {
            $fontObjectNumbers[$resource] = $n++;
        }
        $firstImageObject = $n;
        $infoObject = $firstImageObject + count($this->images);

        // ---- Catalog ----------------------------------------------------
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        // ---- Pages tree -------------------------------------------------
        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = ($firstPageObject + $i) . ' 0 R';
        }
        $objects[2] = sprintf(
            "<< /Type /Pages /Count %d /Kids [%s] /MediaBox [0 0 %.2F %.2F] >>",
            $pageCount,
            implode(' ', $kids),
            $this->pageWidth * self::MM_TO_PT,
            $this->pageHeight * self::MM_TO_PT
        );

        // ---- Resources dictionary (shared by all pages) ------------------
        $fontEntries = [];
        foreach ($fontObjectNumbers as $resource => $number) {
            $fontEntries[] = sprintf('/%s %d 0 R', $resource, $number);
        }
        $xobjectEntries = [];
        foreach (array_keys($this->images) as $index) {
            $xobjectEntries[] = sprintf('/I%d %d 0 R', $index, $firstImageObject + $index);
        }
        $resources = '<< /ProcSet [/PDF /Text /ImageB /ImageC] /Font << '
            . implode(' ', $fontEntries) . ' >>'
            . ($xobjectEntries === [] ? '' : ' /XObject << ' . implode(' ', $xobjectEntries) . ' >>')
            . ' >>';

        // ---- Page objects + content streams -----------------------------
        for ($i = 0; $i < $pageCount; $i++) {
            $objects[$firstPageObject + $i] = sprintf(
                "<< /Type /Page /Parent 2 0 R /Resources %s /Contents %d 0 R >>",
                $resources,
                $firstContentObject + $i
            );

            $content = $this->pages[$i];
            $compressed = function_exists('gzcompress') ? gzcompress($content, 7) : false;

            if ($compressed !== false) {
                $objects[$firstContentObject + $i] = sprintf(
                    "<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
                    strlen($compressed),
                    $compressed
                );
            } else {
                $objects[$firstContentObject + $i] = sprintf(
                    "<< /Length %d >>\nstream\n%s\nendstream",
                    strlen($content),
                    $content
                );
            }
        }

        // ---- Fonts ------------------------------------------------------
        foreach ($fontObjectNumbers as $resource => $number) {
            $objects[$number] = sprintf(
                "<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding >>",
                $fontNames[$resource]
            );
        }

        // ---- Images -----------------------------------------------------
        foreach ($this->images as $index => $image) {
            $objects[$firstImageObject + $index] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s "
                . "/BitsPerComponent %d /Filter /%s /Length %d >>\nstream\n%s\nendstream",
                $image['width'],
                $image['height'],
                $image['colorSpace'],
                $image['bpc'],
                $image['filter'],
                strlen($image['data']),
                $image['data']
            );
        }

        // ---- Info -------------------------------------------------------
        $objects[$infoObject] = sprintf(
            "<< /Title (%s) /Author (%s) /Producer (LRMS PDF Writer) /CreationDate (D:%s) >>",
            $this->escape($this->toWinAnsi($this->title)),
            $this->escape($this->toWinAnsi($this->author)),
            date('YmdHis')
        );

        // ---- Assemble with a cross-reference table ------------------------
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        ksort($objects);
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $maxObject = max(array_keys($objects));
        $xrefOffset = strlen($pdf);

        $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObject; $i++) {
            $pdf .= isset($offsets[$i])
                ? sprintf("%010d 00000 n \n", $offsets[$i])
                : "0000000000 65535 f \n";
        }

        $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R /Info " . $infoObject . " 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function raw(string $operators): void
    {
        if ($this->currentPage < 0) {
            $this->addPage();
        }
        $this->buffer .= $operators . "\n";
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $text);
    }

    /**
     * UTF-8 -> WinAnsi (CP1252). Characters outside CP1252 (Devanagari, the
     * rupee sign, emoji) are transliterated or replaced so the PDF never
     * contains invalid bytes.
     */
    private function toWinAnsi(string $text): string
    {
        // Common substitutions that would otherwise be dropped.
        $text = str_replace(
            ['₹', '–', '—', '“', '”', '‘', '’', '•', '≥', '≤', '→', '±'],
            ['Rs.', '-', '-', '"', '"', "'", "'", '-', '>=', '<=', '->', '+/-'],
            $text
        );

        if (preg_match('//u', $text) !== 1) {
            // Not valid UTF-8 - assume it is already single byte.
            return $text;
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);
            if (is_string($converted)) {
                return $converted;
            }
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'CP1252', 'UTF-8');
            if (is_string($converted)) {
                return $converted;
            }
        }

        return (string) preg_replace('/[^\x20-\x7E]/', '?', $text);
    }

    /**
     * Advance widths in 1/1000 em for Helvetica and Helvetica-Bold
     * (Adobe base-14 metrics, WinAnsi codepoints 32-126 plus a default).
     *
     * @return array<int,int>
     */
    private static function charWidths(string $style): array
    {
        static $cache = [];
        $bold = str_contains($style, 'B');
        $key = $bold ? 'B' : 'R';

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $regular = [
            278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
            556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
        ];

        $boldWidths = [
            278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
            556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
            975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
            667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
            333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
            611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
        ];

        $source = $bold ? $boldWidths : $regular;

        $map = [];
        foreach ($source as $offset => $width) {
            $map[32 + $offset] = $width;
        }
        // Anything above 126 (accented Latin) is close enough to 'o'.
        for ($code = 127; $code <= 255; $code++) {
            $map[$code] = $bold ? 611 : 556;
        }

        return $cache[$key] = $map;
    }
}
