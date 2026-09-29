<?php

declare(strict_types=1);

/**
 * Codificador QR mínimo (modo byte, corrección de errores nivel M, versiones 1 a 10:
 * hasta 213 bytes). Se genera en el servidor porque el link lleva el token del turno
 * y no debe pasar por servicios de terceros.
 */
final class FluxusQr
{
    /** Por versión (1..10), nivel M: codewords de corrección por bloque y cantidad de bloques. */
    private const ECC_PER_BLOCK = [10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    private const NUM_BLOCKS = [1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    private const FORMAT_BITS_M = 0;

    /** @var array<int, array<int, bool>> */
    private array $modules = [];
    /** @var array<int, array<int, bool>> */
    private array $isFunction = [];
    private int $size = 0;

    private function __construct(private int $version)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
    }

    /** Matriz de módulos (true = oscuro). */
    public static function matrix(string $data): array
    {
        $len = strlen($data);
        $version = 0;
        for ($v = 1; $v <= 10; $v++) {
            $capacityBits = self::numDataCodewords($v) * 8;
            if (4 + ($v < 10 ? 8 : 16) + $len * 8 <= $capacityBits) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new InvalidArgumentException('Texto demasiado largo para el QR.');
        }

        $bits = [];
        $push = static function (int $value, int $count) use (&$bits): void {
            for ($i = $count - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $push(0b0100, 4);
        $push($len, $version < 10 ? 8 : 16);
        for ($i = 0; $i < $len; $i++) {
            $push(ord($data[$i]), 8);
        }
        $capacityBits = self::numDataCodewords($version) * 8;
        $push(0, min(4, $capacityBits - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            $push($pad, 8);
        }
        $codewords = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $codewords[] = bindec(implode('', $byte));
        }

        $qr = new self($version);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->addEccAndInterleave($codewords));

        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $qr->applyMask($mask);
            $qr->drawFormatBits($mask);
            $penalty = $qr->penalty();
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
            }
            $qr->applyMask($mask);
        }
        $qr->applyMask($bestMask);
        $qr->drawFormatBits($bestMask);
        return $qr->modules;
    }

    /** PNG en blanco y negro, o null si el servidor no tiene GD. */
    public static function png(string $data, int $scale = 6, int $margin = 4): ?string
    {
        if (!function_exists('imagecreate') || !function_exists('imagepng')) {
            return null;
        }
        $m = self::matrix($data);
        $n = count($m);
        $px = ($n + 2 * $margin) * $scale;
        $img = imagecreate($px, $px);
        if ($img === false) {
            return null;
        }
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $x0 = ($x + $margin) * $scale;
                    $y0 = ($y + $margin) * $scale;
                    imagefilledrectangle($img, $x0, $y0, $x0 + $scale - 1, $y0 + $scale - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    public static function svg(string $data, int $px = 220, int $margin = 4): string
    {
        $m = self::matrix($data);
        $n = count($m) + 2 * $margin;
        $path = '';
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . ($x + $margin) . ',' . ($y + $margin) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '" width="' . $px . '" height="' . $px
            . '" shape-rendering="crispEdges" role="img"><rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    private static function numRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function numDataCodewords(int $ver): int
    {
        return intdiv(self::numRawDataModules($ver), 8) - self::ECC_PER_BLOCK[$ver - 1] * self::NUM_BLOCKS[$ver - 1];
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $dist = max(abs($dx), abs($dy));
                    $xx = $cx + $dx;
                    $yy = $cy + $dy;
                    if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                        $this->setFunction($xx, $yy, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }
        $align = $this->alignmentPositions();
        $num = count($align);
        for ($i = 0; $i < $num; $i++) {
            for ($j = 0; $j < $num; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $num - 1) || ($i === $num - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->setFunction($align[$i] + $dx, $align[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0);
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->setFunction($a, $b, $bit);
                $this->setFunction($b, $a, $bit);
            }
        }
    }

    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $num = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $num * 3 + 5, $num * 4 - 4) * 2;
        $result = [];
        for ($i = 0, $pos = $this->size - 7; $i < $num - 1; $i++, $pos -= $step) {
            array_unshift($result, $pos);
        }
        array_unshift($result, 6);
        return $result;
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::FORMAT_BITS_M << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $bit($i));
        }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFunction(8, $this->size - 8, true);
    }

    private function addEccAndInterleave(array $data): array
    {
        $numBlocks = self::NUM_BLOCKS[$this->version - 1];
        $eccLen = self::ECC_PER_BLOCK[$this->version - 1];
        $raw = intdiv(self::numRawDataModules($this->version), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $divisor = self::rsDivisor($eccLen);

        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $take = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $chunk = array_slice($data, $k, $take);
            $k += $take;
            $ecc = self::rsRemainder($chunk, $divisor);
            if ($i < $numShort) {
                $chunk[] = null;
            }
            $blocks[] = array_merge($chunk, $ecc);
        }
        $out = [];
        for ($i = 0; $i <= $shortLen; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
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
        return $z;
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

    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
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
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function penalty(): int
    {
        $n = $this->size;
        $m = $this->modules;
        $score = 0;
        $dark = 0;
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $lines[] = $m[$i];
            $lines[] = array_column($m, $i);
        }
        foreach ($lines as $line) {
            $run = 1;
            for ($i = 1; $i <= $n; $i++) {
                if ($i < $n && $line[$i] === $line[$i - 1]) {
                    $run++;
                    continue;
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
                $run = 1;
            }
            $s = implode('', array_map('intval', $line));
            $score += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
        }
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $c = $m[$y][$x];
                if ($c) {
                    $dark++;
                }
                if ($x < $n - 1 && $y < $n - 1 && $c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $total = $n * $n;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        return $score + max(0, $k) * 10;
    }
}
