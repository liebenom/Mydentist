<?php
/**
 * Self-contained QR Code generator (ISO/IEC 18004), byte mode, error correction
 * level M, versions 1-5. Enough for booking references and short URLs, works
 * offline and needs only the GD extension (bundled with XAMPP) for PNG output.
 *
 * Based on the algorithm described by Project Nayuki's reference implementation.
 */

final class QrCode
{
    // [total codewords, EC codewords per block, number of blocks] for level M
    private const VERSIONS = [
        1 => [26, 10, 1],
        2 => [44, 16, 1],
        3 => [70, 26, 1],
        4 => [100, 18, 2],
        5 => [134, 24, 2],
    ];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30]];

    private int $version;
    private int $size;
    private array $modules = [];
    private array $isFunction = [];

    public function __construct(string $data)
    {
        $len = strlen($data);
        $this->version = 0;
        foreach (self::VERSIONS as $v => [$total, $ec, $blocks]) {
            $capacityBits = ($total - $ec * $blocks) * 8;
            if (4 + 8 + $len * 8 <= $capacityBits) {
                $this->version = $v;
                break;
            }
        }
        if ($this->version === 0) {
            throw new InvalidArgumentException('Data too long for QR code');
        }
        $this->size = 17 + 4 * $this->version;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = $this->modules;

        $this->drawFunctionPatterns();
        $codewords = $this->addEccAndInterleave($this->encodeData($data));
        $this->drawCodewords($codewords);

        // Choose the mask with the lowest penalty score
        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $this->applyMask($m);
            $this->drawFormatBits($m);
            $p = $this->penalty();
            if ($p < $bestPenalty) {
                $best = $m;
                $bestPenalty = $p;
            }
            $this->applyMask($m); // undo (XOR)
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    public function size(): int
    {
        return $this->size;
    }

    public function get(int $x, int $y): bool
    {
        return $this->modules[$y][$x];
    }

    // ---------------------------------------------------------------- encoding
    private function encodeData(string $data): array
    {
        [$total, $ec, $blocks] = self::VERSIONS[$this->version];
        $capacity = ($total - $ec * $blocks) * 8;
        $bits = [];
        $push = function (int $val, int $n) use (&$bits) {
            for ($i = $n - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $push(0b0100, 4);              // byte mode
        $push(strlen($data), 8);        // character count (versions 1-9)
        foreach (str_split($data) as $ch) {
            $push(ord($ch), 8);
        }
        $push(0, min(4, $capacity - count($bits)));   // terminator
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        $bytes = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $bytes[] = bindec(implode('', $chunk));
        }
        for ($pad = 0xEC; count($bytes) < $capacity / 8; $pad ^= 0xEC ^ 0x11) {
            $bytes[] = $pad;
        }
        return $bytes;
    }

    private function addEccAndInterleave(array $data): array
    {
        [$total, $ec, $numBlocks] = self::VERSIONS[$this->version];
        $blockLen = intdiv(count($data), $numBlocks);   // all blocks equal for v1-5 level M
        $divisor = self::rsDivisor($ec);
        $blocks = [];
        $eccs = [];
        for ($i = 0; $i < $numBlocks; $i++) {
            $blk = array_slice($data, $i * $blockLen, $blockLen);
            $blocks[] = $blk;
            $eccs[] = self::rsRemainder($blk, $divisor);
        }
        $out = [];
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $b) {
                $out[] = $b[$i];
            }
        }
        for ($i = 0; $i < $ec; $i++) {
            foreach ($eccs as $e) {
                $out[] = $e[$i];
            }
        }
        return $out;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }
        return $result;
    }

    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::gfMul($coef, $factor);
            }
        }
        return $result;
    }

    // ---------------------------------------------------------------- drawing
    private function setFn(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFn(6, $i, $i % 2 === 0);
            $this->setFn($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $pos = self::ALIGN[$this->version];
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue; // overlaps a finder pattern
                }
                $this->drawAlignment($pos[$i], $pos[$j]);
            }
        }
        $this->drawFormatBits(0); // reserve the format areas
    }

    private function drawFinder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->setFn($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFn($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (0b00 << 3) | $mask;   // EC level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn(int $i) => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFn(8, $i, $bit($i));
        }
        $this->setFn(8, 7, $bit(6));
        $this->setFn(8, 8, $bit(7));
        $this->setFn(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFn(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFn($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFn(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFn(8, $this->size - 8, true); // dark module
    }

    private function drawCodewords(array $data): void
    {
        $i = 0;
        $totalBits = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $totalBits) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }
                switch ($mask) {
                    case 0: $inv = ($x + $y) % 2 === 0; break;
                    case 1: $inv = $y % 2 === 0; break;
                    case 2: $inv = $x % 3 === 0; break;
                    case 3: $inv = ($x + $y) % 3 === 0; break;
                    case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
                    case 5: $inv = ($x * $y % 2 + $x * $y % 3) === 0; break;
                    case 6: $inv = (($x * $y % 2 + $x * $y % 3) % 2) === 0; break;
                    default: $inv = ((($x + $y) % 2 + $x * $y % 3) % 2) === 0;
                }
                if ($inv) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function penalty(): int
    {
        $n = $this->size;
        $score = 0;
        $lines = [];
        for ($y = 0; $y < $n; $y++) {
            $row = '';
            $col = '';
            for ($x = 0; $x < $n; $x++) {
                $row .= $this->modules[$y][$x] ? '1' : '0';
                $col .= $this->modules[$x][$y] ? '1' : '0';
            }
            $lines[] = $row;
            $lines[] = $col;
        }
        foreach ($lines as $line) {
            // Rule 1: runs of 5+ same-colour modules
            preg_match_all('/0{5,}|1{5,}/', $line, $m);
            foreach ($m[0] as $run) {
                $score += 3 + strlen($run) - 5;
            }
            // Rule 3: finder-like patterns
            $padded = '0000' . $line . '0000';
            $score += 40 * (substr_count($padded, '00001011101') + substr_count($padded, '10111010000'));
        }
        // Rule 2: 2x2 blocks of the same colour
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $c = $this->modules[$y][$x];
                if ($c) {
                    $dark++;
                }
                if ($x < $n - 1 && $y < $n - 1 && $c === $this->modules[$y][$x + 1]
                    && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        // Rule 4: balance of dark and light modules
        $total = $n * $n;
        $k = (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        return $score + max(0, $k) * 10;
    }

    // ---------------------------------------------------------------- output
    public function png(int $scale = 8, int $border = 4): string
    {
        $px = ($this->size + $border * 2) * $scale;
        $img = imagecreate($px, $px);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 17, 24, 39);
        imagefill($img, 0, 0, $white);
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) {
                    $x0 = ($x + $border) * $scale;
                    $y0 = ($y + $border) * $scale;
                    imagefilledrectangle($img, $x0, $y0, $x0 + $scale - 1, $y0 + $scale - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    public function svg(int $border = 4): string
    {
        $dim = $this->size + $border * 2;
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) {
                    $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges">'
             . '<rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#111827"/></svg>';
    }
}

function qr_can_png(): bool
{
    return function_exists('imagecreate');
}

function qr_png(string $data, int $scale = 8): string
{
    return (new QrCode($data))->png($scale);
}
