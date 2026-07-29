<?php

declare(strict_types=1);

namespace Lib;

use RuntimeException;

/**
 * Pure-PHP QR Code encoder - no Composer, no external service, no image
 * library required for the matrix itself.
 *
 * Scope: byte (8-bit) mode, error-correction level M, versions 1-10
 * (up to 213 bytes). That comfortably covers the verification URLs printed
 * on LRMS reports and receipts.
 *
 * Implementation notes
 *  - Format and version information are COMPUTED with their BCH codes rather
 *    than copied from a lookup table, which removes a whole class of
 *    transcription bugs.
 *  - All 8 data masks are evaluated with the ISO/IEC 18004 penalty rules and
 *    the best one is used.
 *
 * Output: a boolean matrix, or PNG / SVG / plain-text renderings.
 */
final class QrCode
{
    /** Error-correction level indicator bits: L=01, M=00, Q=11, H=10. */
    private const EC_LEVEL_M_BITS = 0b00;

    /**
     * Block structure for EC level M.
     * version => [ecCodewordsPerBlock, [ [blockCount, dataCodewordsPerBlock], ... ] ]
     */
    private const BLOCKS_M = [
        1  => [10, [[1, 16]]],
        2  => [16, [[1, 28]]],
        3  => [26, [[1, 44]]],
        4  => [18, [[2, 32]]],
        5  => [24, [[2, 43]]],
        6  => [16, [[4, 27]]],
        7  => [18, [[4, 31]]],
        8  => [22, [[2, 38], [2, 39]]],
        9  => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    /** Alignment pattern centre coordinates per version. */
    private const ALIGNMENT_CENTRES = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** @var list<list<bool>> row-major module matrix; true = dark */
    private array $matrix = [];

    /** @var list<list<bool>> true where a function pattern lives (not data) */
    private array $reserved = [];

    private int $version = 1;
    private int $size = 21;

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Encode a string and return the finished instance.
     */
    public static function encode(string $data): self
    {
        $qr = new self();
        $qr->build($data);
        return $qr;
    }

    /** @return list<list<bool>> */
    public function matrix(): array
    {
        return $this->matrix;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * Render as a PNG binary string.
     *
     * @param int $moduleSize pixels per module
     * @param int $quietZone  modules of white border (spec requires 4)
     */
    public function toPng(int $moduleSize = 6, int $quietZone = 4): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException(
                'The PHP "gd" extension is required to render a QR code as PNG. '
                . 'Enable it in cPanel > Select PHP Version > Extensions.'
            );
        }

        $moduleSize = max(1, min(20, $moduleSize));
        $quietZone = max(0, min(8, $quietZone));

        $dimension = ($this->size + $quietZone * 2) * $moduleSize;
        $image = imagecreatetruecolor($dimension, $dimension);
        if ($image === false) {
            throw new RuntimeException('Could not allocate the QR image.');
        }

        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $dimension, $dimension, $white);

        for ($row = 0; $row < $this->size; $row++) {
            for ($col = 0; $col < $this->size; $col++) {
                if (!$this->matrix[$row][$col]) {
                    continue;
                }
                $x = ($col + $quietZone) * $moduleSize;
                $y = ($row + $quietZone) * $moduleSize;
                imagefilledrectangle($image, $x, $y, $x + $moduleSize - 1, $y + $moduleSize - 1, $black);
            }
        }

        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    /** Render as an inline SVG (useful on hosts without GD). */
    public function toSvg(int $moduleSize = 6, int $quietZone = 4): string
    {
        $dimension = ($this->size + $quietZone * 2) * $moduleSize;

        $path = '';
        for ($row = 0; $row < $this->size; $row++) {
            for ($col = 0; $col < $this->size; $col++) {
                if ($this->matrix[$row][$col]) {
                    $x = ($col + $quietZone) * $moduleSize;
                    $y = ($row + $quietZone) * $moduleSize;
                    $path .= sprintf('M%d %dh%dv%dh-%dz', $x, $y, $moduleSize, $moduleSize, $moduleSize);
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d" '
            . 'shape-rendering="crispEdges"><rect width="%1$d" height="%1$d" fill="#fff"/>'
            . '<path d="%2$s" fill="#000"/></svg>',
            $dimension,
            $path
        );
    }

    /** ASCII art, handy for debugging from the CLI. */
    public function toText(): string
    {
        $out = '';
        foreach ($this->matrix as $row) {
            foreach ($row as $dark) {
                $out .= $dark ? '##' : '  ';
            }
            $out .= "\n";
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Encoding pipeline
    // ------------------------------------------------------------------

    private function build(string $data): void
    {
        if ($data === '') {
            throw new RuntimeException('Cannot encode an empty string as a QR code.');
        }

        $this->version = $this->pickVersion($data);
        $this->size = 17 + 4 * $this->version;

        $codewords = $this->buildCodewords($data);

        $this->initialiseMatrix();
        $this->placeFunctionPatterns();

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        $baseMatrix = $this->matrix;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->matrix = $baseMatrix;
            $this->placeData($codewords);
            $this->applyMask($mask);
            $this->placeFormatInformation($mask);
            $this->placeVersionInformation();

            $penalty = $this->penalty();
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $this->matrix;
            }
        }

        if ($best === null) {
            throw new RuntimeException('QR mask selection failed unexpectedly.');
        }

        $this->matrix = $best;
    }

    private function pickVersion(string $data): int
    {
        $length = strlen($data);

        foreach (array_keys(self::BLOCKS_M) as $version) {
            $capacity = $this->byteCapacity((int) $version);
            if ($length <= $capacity) {
                return (int) $version;
            }
        }

        throw new RuntimeException(sprintf(
            'Payload is %d bytes; the built-in QR encoder supports up to %d bytes '
            . '(version 10, EC level M). Shorten the verification URL.',
            $length,
            $this->byteCapacity(10)
        ));
    }

    private function byteCapacity(int $version): int
    {
        $dataCodewords = $this->totalDataCodewords($version);
        $headerBits = 4 + $this->characterCountBits($version);
        return (int) floor(($dataCodewords * 8 - $headerBits) / 8);
    }

    private function characterCountBits(int $version): int
    {
        // Byte mode: 8 bits for versions 1-9, 16 bits for 10-40.
        return $version <= 9 ? 8 : 16;
    }

    private function totalDataCodewords(int $version): int
    {
        [, $groups] = self::BLOCKS_M[$version];
        $total = 0;
        foreach ($groups as [$count, $dataPerBlock]) {
            $total += $count * $dataPerBlock;
        }
        return $total;
    }

    /** @return list<int> final interleaved codeword stream */
    private function buildCodewords(string $data): array
    {
        $version = $this->version;
        [$ecPerBlock, $groups] = self::BLOCKS_M[$version];
        $totalDataCodewords = $this->totalDataCodewords($version);

        // ---- bit stream -------------------------------------------------
        $bits = '';
        $bits .= '0100';                                                   // byte mode
        $bits .= str_pad(decbin(strlen($data)), $this->characterCountBits($version), '0', STR_PAD_LEFT);
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $capacityBits = $totalDataCodewords * 8;

        // Terminator: up to 4 zero bits.
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));

        // Pad to a byte boundary.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        // Pad bytes alternate 0xEC / 0x11 (spec 8.4.9).
        $padBytes = ['11101100', '00010001'];
        $padIndex = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= $padBytes[$padIndex % 2];
            $padIndex++;
        }

        $dataCodewords = [];
        foreach (str_split($bits, 8) as $byte) {
            $dataCodewords[] = (int) bindec($byte);
        }

        // ---- split into blocks and compute ECC ---------------------------
        /** @var list<list<int>> $dataBlocks */
        $dataBlocks = [];
        /** @var list<list<int>> $ecBlocks */
        $ecBlocks = [];

        $offset = 0;
        foreach ($groups as [$blockCount, $dataPerBlock]) {
            for ($b = 0; $b < $blockCount; $b++) {
                $block = array_slice($dataCodewords, $offset, $dataPerBlock);
                $offset += $dataPerBlock;
                $dataBlocks[] = $block;
                $ecBlocks[] = self::reedSolomon($block, $ecPerBlock);
            }
        }

        // ---- interleave --------------------------------------------------
        $result = [];

        $maxDataLength = 0;
        foreach ($dataBlocks as $block) {
            $maxDataLength = max($maxDataLength, count($block));
        }
        for ($i = 0; $i < $maxDataLength; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Reed-Solomon over GF(256), primitive polynomial 0x11D
    // ------------------------------------------------------------------

    /** @var list<int>|null */
    private static ?array $expTable = null;
    /** @var list<int>|null */
    private static ?array $logTable = null;

    private static function initGf(): void
    {
        if (self::$expTable !== null) {
            return;
        }

        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);

        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }

        self::$expTable = $exp;
        self::$logTable = $log;
    }

    private static function gfMultiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        self::initGf();
        return self::$expTable[(self::$logTable[$a] + self::$logTable[$b]) % 255];
    }

    /**
     * @param list<int> $data
     * @return list<int> $ecCount error-correction codewords
     */
    private static function reedSolomon(array $data, int $ecCount): array
    {
        self::initGf();

        // Generator polynomial: product of (x - a^i) for i in 0..ecCount-1
        $generator = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $index => $coefficient) {
                $next[$index] ^= $coefficient;
                $next[$index + 1] ^= self::gfMultiply($coefficient, self::$expTable[$i]);
            }
            $generator = $next;
        }

        // Polynomial long division of data*x^ecCount by the generator.
        $remainder = array_merge($data, array_fill(0, $ecCount, 0));

        for ($i = 0, $n = count($data); $i < $n; $i++) {
            $factor = $remainder[$i];
            if ($factor === 0) {
                continue;
            }
            foreach ($generator as $index => $coefficient) {
                $remainder[$i + $index] ^= self::gfMultiply($coefficient, $factor);
            }
        }

        return array_values(array_slice($remainder, count($data), $ecCount));
    }

    // ------------------------------------------------------------------
    // Matrix construction
    // ------------------------------------------------------------------

    private function initialiseMatrix(): void
    {
        $this->matrix = [];
        $this->reserved = [];
        for ($row = 0; $row < $this->size; $row++) {
            $this->matrix[$row] = array_fill(0, $this->size, false);
            $this->reserved[$row] = array_fill(0, $this->size, false);
        }
    }

    private function placeFunctionPatterns(): void
    {
        $last = $this->size - 7;

        // Three finder patterns with their separators.
        foreach ([[0, 0], [0, $last], [$last, 0]] as [$row, $col]) {
            $this->drawFinder($row, $col);
        }

        // Timing patterns on row 6 and column 6.
        for ($i = 8; $i < $this->size - 8; $i++) {
            $dark = $i % 2 === 0;
            $this->setFunction(6, $i, $dark);
            $this->setFunction($i, 6, $dark);
        }

        // Alignment patterns.
        $centres = self::ALIGNMENT_CENTRES[$this->version];
        foreach ($centres as $rowCentre) {
            foreach ($centres as $colCentre) {
                // Skip the three that would overlap a finder pattern.
                if (($rowCentre === 6 && $colCentre === 6)
                    || ($rowCentre === 6 && $colCentre === $this->size - 7)
                    || ($rowCentre === $this->size - 7 && $colCentre === 6)) {
                    continue;
                }
                $this->drawAlignment($rowCentre, $colCentre);
            }
        }

        // Dark module (always at (4*version+9, 8)).
        $this->setFunction(4 * $this->version + 9, 8, true);

        // Reserve the format information areas.
        for ($i = 0; $i < 9; $i++) {
            if (!$this->reserved[8][$i]) {
                $this->setFunction(8, $i, false);
            }
            if (!$this->reserved[$i][8]) {
                $this->setFunction($i, 8, false);
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction(8, $this->size - 1 - $i, false);
            $this->setFunction($this->size - 1 - $i, 8, false);
        }

        // Reserve the version information areas (versions 7+).
        if ($this->version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $this->setFunction($this->size - 11 + $j, $i, false);
                    $this->setFunction($i, $this->size - 11 + $j, false);
                }
            }
        }
    }

    private function drawFinder(int $topRow, int $leftCol): void
    {
        // 7x7 pattern plus a 1-module separator all around.
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $row = $topRow + $r;
                $col = $leftCol + $c;
                if ($row < 0 || $row >= $this->size || $col < 0 || $col >= $this->size) {
                    continue;
                }
                $inRing = ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6)
                    && ($r === 0 || $r === 6 || $c === 0 || $c === 6);
                $inCore = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                $this->setFunction($row, $col, $inRing || $inCore);
            }
        }
    }

    private function drawAlignment(int $rowCentre, int $colCentre): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $dark = max(abs($r), abs($c)) !== 1;
                $this->setFunction($rowCentre + $r, $colCentre + $c, $dark);
            }
        }
    }

    private function setFunction(int $row, int $col, bool $dark): void
    {
        if ($row < 0 || $row >= $this->size || $col < 0 || $col >= $this->size) {
            return;
        }
        $this->matrix[$row][$col] = $dark;
        $this->reserved[$row][$col] = true;
    }

    /** @param list<int> $codewords */
    private function placeData(array $codewords): void
    {
        $bits = '';
        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        $bitIndex = 0;
        $bitCount = strlen($bits);
        $upward = true;

        // Two-module-wide columns, right to left, skipping the timing column 6.
        for ($col = $this->size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col = 5; // shift past the vertical timing pattern
            }

            for ($i = 0; $i < $this->size; $i++) {
                $row = $upward ? $this->size - 1 - $i : $i;

                foreach ([$col, $col - 1] as $c) {
                    if ($c < 0 || $this->reserved[$row][$c]) {
                        continue;
                    }
                    // Remainder bits (beyond the codeword stream) stay light.
                    $this->matrix[$row][$c] = $bitIndex < $bitCount && $bits[$bitIndex] === '1';
                    $bitIndex++;
                }
            }

            $upward = !$upward;
        }
    }

    private function applyMask(int $mask): void
    {
        for ($row = 0; $row < $this->size; $row++) {
            for ($col = 0; $col < $this->size; $col++) {
                if ($this->reserved[$row][$col]) {
                    continue;
                }
                if ($this->maskCondition($mask, $row, $col)) {
                    $this->matrix[$row][$col] = !$this->matrix[$row][$col];
                }
            }
        }
    }

    private function maskCondition(int $mask, int $i, int $j): bool
    {
        return match ($mask) {
            0 => ($i + $j) % 2 === 0,
            1 => $i % 2 === 0,
            2 => $j % 3 === 0,
            3 => ($i + $j) % 3 === 0,
            4 => ((int) floor($i / 2) + (int) floor($j / 3)) % 2 === 0,
            5 => (($i * $j) % 2) + (($i * $j) % 3) === 0,
            6 => (((($i * $j) % 2) + (($i * $j) % 3)) % 2) === 0,
            7 => (((($i + $j) % 2) + (($i * $j) % 3)) % 2) === 0,
            default => false,
        };
    }

    /**
     * 15-bit format information = 5 data bits (EC level + mask) protected by
     * a BCH(15,5) code, then XORed with 0x5412.
     */
    private function placeFormatInformation(int $mask): void
    {
        $data = (self::EC_LEVEL_M_BITS << 3) | $mask;

        $remainder = $data << 10;
        for ($i = 4; $i >= 0; $i--) {
            if ((($remainder >> ($i + 10)) & 1) !== 0) {
                $remainder ^= 0x537 << $i;   // generator 10100110111
            }
        }
        $format = (($data << 10) | $remainder) ^ 0x5412;

        // Every one of the 15 bits is written twice, once in the vertical
        // strip of column 8 and once in the horizontal strip of row 8
        // (ISO/IEC 18004 section 8.9). Row 6 and column 6 are skipped because
        // they belong to the timing patterns.
        for ($i = 0; $i < 15; $i++) {
            $bit = $this->bitAt($format, $i);

            // --- vertical strip: column 8 ---------------------------------
            if ($i < 6) {
                $this->matrix[$i][8] = $bit;                       // rows 0-5
            } elseif ($i < 8) {
                $this->matrix[$i + 1][8] = $bit;                   // rows 7-8
            } else {
                $this->matrix[$this->size - 15 + $i][8] = $bit;    // bottom-left
            }

            // --- horizontal strip: row 8 ----------------------------------
            if ($i < 8) {
                $this->matrix[8][$this->size - 1 - $i] = $bit;     // top-right
            } elseif ($i === 8) {
                $this->matrix[8][7] = $bit;
            } else {
                $this->matrix[8][14 - $i] = $bit;                  // cols 5-0
            }
        }

        // The dark module is fixed and must survive.
        $this->matrix[4 * $this->version + 9][8] = true;
    }

    /**
     * 18-bit version information = 6 version bits protected by BCH(18,6).
     * Only present from version 7 upwards.
     */
    private function placeVersionInformation(): void
    {
        if ($this->version < 7) {
            return;
        }

        $remainder = $this->version << 12;
        for ($i = 5; $i >= 0; $i--) {
            if ((($remainder >> ($i + 12)) & 1) !== 0) {
                $remainder ^= 0x1F25 << $i;  // generator 1111100100101
            }
        }
        $versionBits = ($this->version << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $bit = $this->bitAt($versionBits, $i);
            $row = (int) floor($i / 3);
            $col = $i % 3;
            $this->matrix[$this->size - 11 + $col][$row] = $bit;
            $this->matrix[$row][$this->size - 11 + $col] = $bit;
        }
    }

    private function bitAt(int $value, int $index): bool
    {
        return (($value >> $index) & 1) === 1;
    }

    // ------------------------------------------------------------------
    // Mask penalty (ISO/IEC 18004 section 8.8.2)
    // ------------------------------------------------------------------

    private function penalty(): int
    {
        return $this->penaltyRule1()
            + $this->penaltyRule2()
            + $this->penaltyRule3()
            + $this->penaltyRule4();
    }

    /** Runs of 5 or more same-colour modules in a row/column. */
    private function penaltyRule1(): int
    {
        $penalty = 0;

        for ($row = 0; $row < $this->size; $row++) {
            $penalty += $this->runPenalty($this->matrix[$row]);
        }
        for ($col = 0; $col < $this->size; $col++) {
            $column = [];
            for ($row = 0; $row < $this->size; $row++) {
                $column[] = $this->matrix[$row][$col];
            }
            $penalty += $this->runPenalty($column);
        }

        return $penalty;
    }

    /** @param list<bool> $line */
    private function runPenalty(array $line): int
    {
        $penalty = 0;
        $runLength = 1;
        $previous = $line[0];

        for ($i = 1, $n = count($line); $i < $n; $i++) {
            if ($line[$i] === $previous) {
                $runLength++;
                continue;
            }
            if ($runLength >= 5) {
                $penalty += 3 + ($runLength - 5);
            }
            $previous = $line[$i];
            $runLength = 1;
        }
        if ($runLength >= 5) {
            $penalty += 3 + ($runLength - 5);
        }

        return $penalty;
    }

    /** 2x2 blocks of the same colour. */
    private function penaltyRule2(): int
    {
        $penalty = 0;
        for ($row = 0; $row < $this->size - 1; $row++) {
            for ($col = 0; $col < $this->size - 1; $col++) {
                $value = $this->matrix[$row][$col];
                if ($value === $this->matrix[$row][$col + 1]
                    && $value === $this->matrix[$row + 1][$col]
                    && $value === $this->matrix[$row + 1][$col + 1]) {
                    $penalty += 3;
                }
            }
        }
        return $penalty;
    }

    /** 1:1:3:1:1 finder-like patterns with 4 light modules on either side. */
    private function penaltyRule3(): int
    {
        $needleA = [true, false, true, true, true, false, true, false, false, false, false];
        $needleB = [false, false, false, false, true, false, true, true, true, false, true];

        $penalty = 0;

        for ($row = 0; $row < $this->size; $row++) {
            for ($col = 0; $col <= $this->size - 11; $col++) {
                $window = array_slice($this->matrix[$row], $col, 11);
                if ($window === $needleA || $window === $needleB) {
                    $penalty += 40;
                }
            }
        }

        for ($col = 0; $col < $this->size; $col++) {
            $column = [];
            for ($row = 0; $row < $this->size; $row++) {
                $column[] = $this->matrix[$row][$col];
            }
            for ($row = 0; $row <= $this->size - 11; $row++) {
                $window = array_slice($column, $row, 11);
                if ($window === $needleA || $window === $needleB) {
                    $penalty += 40;
                }
            }
        }

        return $penalty;
    }

    /** Deviation of the dark-module ratio from 50%. */
    private function penaltyRule4(): int
    {
        $dark = 0;
        $total = $this->size * $this->size;
        foreach ($this->matrix as $row) {
            foreach ($row as $module) {
                if ($module) {
                    $dark++;
                }
            }
        }

        $percent = ($dark * 100) / $total;
        $deviation = (int) (abs($percent - 50) / 5);

        return $deviation * 10;
    }
}
