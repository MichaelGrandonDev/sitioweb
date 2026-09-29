<?php

declare(strict_types=1);

/**
 * PDF del plan MTC para el paciente (A4), con el estilo de la hoja «INDICACIONES»: recuadro crema del diagnóstico con una rama
 * de ciruelo en flor dibujada en vectores, viñetas ➢ y títulos en serif. Sin dependencias; no incluye imágenes ni fotos.
 */
final class MtcPdf
{
    /** Anchos de Helvetica / Helvetica-Bold (WinAnsi 32–255). Para Times se usan los mismos: quedan un poco más anchos, nunca se desborda. */
    private const W_REGULAR = '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500';
    private const W_BOLD = '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556';
    private const LEFT = 50.0;
    private const RIGHT = 545.0;
    private const TOP = 776.0;
    /** Fuentes: r/b = Helvetica, sb = Times-Bold, si = Times-Italic, sr = Times-Roman. */
    private const FONTS = ['b' => 'F1', 'r' => 'F2', 'sb' => 'F3', 'si' => 'F4', 'sr' => 'F5'];
    private const INK = '0.16 0.11 0.09';
    private const RED = '0.60 0.11 0.12';
    private const CREAM = '0.992 0.965 0.910';
    private const SAND = '0.84 0.73 0.57';

    /** @var array<string, list<int>> */
    private static array $widths = [];
    /** @var list<string> */
    private array $pages = [];
    private string $buf = '';
    private float $y = self::TOP;
    private string $footer = '';
    private string $header = '';

    public function setFooter(string $text): void
    {
        $this->footer = $text;
    }

    /** Texto chico arriba a la derecha de cada página (a la izquierda va «FluxusTerapia»). */
    public function setHeader(string $text): void
    {
        $this->header = $text;
    }

    public function addPage(): void
    {
        if ($this->buf !== '') {
            $this->pages[] = $this->buf;
        }
        $this->buf = '';
        $this->y = self::TOP;
    }

    public function keepTogether(float $h): void
    {
        $this->need($h);
    }

    public function space(float $h): void
    {
        $this->y -= $h;
    }

    /** «INDICACIONES» centrado, y debajo el nombre del paciente y la fecha. */
    public function sheetTitle(string $title, string $name, string $date): void
    {
        $this->need(90);
        $this->y -= 8;
        $this->text($title, 26, 'sb', 'C', self::INK, 3.0);
        $this->y -= 34;
        $this->text(mb_strtoupper($name), 11, 'b', 'L', self::INK);
        if ($date !== '') {
            $label = 'FECHA  ' . $date;
            $this->text($label, 11, 'b', 'R', self::INK);
        }
        $this->y -= 26;
    }

    /** Recuadro crema: título en serif negrita centrado, texto en itálica a la izquierda y la rama de ciruelo abajo a la derecha. */
    public function diagnosisBox(string $title, string $text): void
    {
        $pad = 18.0;
        $w = self::RIGHT - self::LEFT;
        $titleLines = $this->wrapWords($title, 'sb', 15, 330);
        $textLines = $this->wrapWords($text !== '' ? $text : '(a completar)', 'si', 12.5, $w - 2 * $pad - 150);
        $titleH = count($titleLines) * 20;
        $textH = count($textLines) * 17;
        $h = max($pad + $titleH + 14 + $textH + $pad, 172);
        $this->need($h + 16);
        $top = $this->y + 4;
        $bottom = $top - $h;
        $this->buf .= sprintf("q %s rg %.2F %.2F %.2F %.2F re f Q\n", self::CREAM, self::LEFT, $bottom, $w, $h);
        $this->buf .= sprintf("q %s RG 0.8 w %.2F %.2F %.2F %.2F re S Q\n", self::SAND, self::LEFT, $bottom, $w, $h);
        $this->buf .= sprintf("q %s RG 0.4 w %.2F %.2F %.2F %.2F re S Q\n", self::SAND, self::LEFT + 4, $bottom + 4, $w - 8, $h - 8);
        $this->plumBranch(self::RIGHT - 168, $bottom + 8, 0.85);
        $y = $top - $pad - 14;
        foreach ($titleLines as $line) {
            $this->at($line, 15, 'sb', 297.5 - $this->width($line, 'sb', 15) / 2, $y, self::INK);
            $y -= 20;
        }
        $y -= 12;
        foreach ($textLines as $line) {
            $this->at($line, 12.5, 'si', self::LEFT + $pad, $y, self::INK);
            $y -= 17;
        }
        $this->y = $bottom - 20;
    }

    /** Título de sección en serif, con una línea corta roja debajo. */
    public function heading(string $text): void
    {
        $this->need(70);
        $this->y -= 10;
        $this->text($text, 15, 'sb', 'L', self::RED);
        $y = $this->y - 7;
        $this->buf .= sprintf("q %s RG 0.9 w %.2F %.2F m %.2F %.2F l S Q\n", self::SAND, self::LEFT, $y, self::LEFT + 60, $y);
        $this->y -= 25;
    }

    /**
     * Párrafo justificado con tramos: [['Texto', 'r'|'b'|'si'|'sb'|'sr'], ...].
     * $bullet: '' | 'arrow' (➢ dibujada) | 'dot'.
     *
     * @param list<array{0: string, 1: string}> $runs
     */
    public function paragraph(array $runs, string $bullet = '', float $indent = 0, float $size = 10.5, string $color = self::INK): void
    {
        $words = [];
        foreach ($runs as [$text, $font]) {
            foreach (preg_split('/\s+/u', trim((string) $text)) ?: [] as $w) {
                if ($w !== '') {
                    $words[] = [$this->toWin($w), $font];
                }
            }
        }
        if (!$words) {
            return;
        }
        $hang = $bullet !== '' ? 16.0 : 0.0;
        $left = self::LEFT + $indent + $hang;
        $avail = self::RIGHT - $left;
        $lines = [];
        $line = [];
        $lineW = 0.0;
        foreach ($words as $word) {
            $w = $this->width($word[0], $word[1], $size);
            $add = $line ? $this->width(' ', $word[1], $size) + $w : $w;
            if ($line && $lineW + $add > $avail) {
                $lines[] = [$line, $lineW];
                $line = [$word];
                $lineW = $w;
            } else {
                $line[] = $word;
                $lineW += $add;
            }
        }
        $lines[] = [$line, $lineW];
        $leading = $size * 1.42;
        $last = count($lines) - 1;
        foreach ($lines as $i => [$lineWords, $width]) {
            $this->need($leading);
            $tw = $i < $last && count($lineWords) > 1 ? ($avail - $width) / (count($lineWords) - 1) : 0.0;
            if ($i === 0 && $bullet === 'arrow') {
                $this->arrow(self::LEFT + $indent + 1, $this->y, $size);
            } elseif ($i === 0 && $bullet === 'dot') {
                $this->buf .= sprintf("q %s rg %s f Q\n", self::RED, $this->circle(self::LEFT + $indent + 4, $this->y + $size * 0.33, 1.7));
            }
            $ops = '';
            $font = null;
            $segment = '';
            foreach ($lineWords as $j => [$word, $f]) {
                if ($font !== null && $f !== $font) {
                    $ops .= sprintf('/%s %.2F Tf (%s) Tj ', self::FONTS[$font], $size, $this->escape($segment));
                    $segment = '';
                }
                $font = $f;
                $segment .= ($j > 0 ? ' ' : '') . $word;
            }
            $ops .= sprintf('/%s %.2F Tf (%s) Tj ', self::FONTS[$font], $size, $this->escape($segment));
            $this->buf .= sprintf("BT %s rg %.3F Tw %.2F %.2F Td %sET\n", $color, $tw, $left, $this->y, $ops);
            $this->y -= $leading;
        }
        $this->y -= $size * 0.5;
    }

    /** Texto chico y gris (aclaraciones). */
    public function note(string $text): void
    {
        $this->paragraph([[$text, 'r']], '', 0, 9, '0.38 0.33 0.30');
    }

    /** Línea fina color arena a todo el ancho. */
    public function rule(): void
    {
        $this->need(16);
        $y = $this->y + 6;
        $this->buf .= sprintf("q %s RG 0.6 w %.2F %.2F m %.2F %.2F l S Q\n", self::SAND, self::LEFT, $y, self::RIGHT, $y);
        $this->y -= 12;
    }

    public function render(): string
    {
        if ($this->buf !== '') {
            $this->pages[] = $this->buf;
            $this->buf = '';
        }
        if (!$this->pages) {
            $this->pages[] = '';
        }
        $total = count($this->pages);
        foreach ($this->pages as $n => $content) {
            $head = sprintf("BT /F3 12 Tf 0 Tw %s rg %.2F 806 Td (FluxusTerapia) Tj ET\n", self::RED, self::LEFT);
            if ($this->header !== '') {
                $win = $this->toWin($this->header);
                $head .= sprintf("BT /F2 8 Tf 0 Tw 0.42 0.37 0.33 rg %.2F 807 Td (%s) Tj ET\n", self::RIGHT - $this->width($win, 'r', 8), $this->escape($win));
            }
            $head .= sprintf("q %s RG 0.6 w %.2F 798 m %.2F 798 l S Q\n", self::SAND, self::LEFT, self::RIGHT);
            $foot = '';
            if ($this->footer !== '') {
                $num = $this->toWin('Página ' . ($n + 1) . ' de ' . $total);
                $foot = sprintf(
                    "BT /F2 8 Tf 0 Tw 0.42 0.37 0.33 rg %.2F 28 Td (%s) Tj ET\nBT /F2 8 Tf 0 Tw 0.42 0.37 0.33 rg %.2F 28 Td (%s) Tj ET\n",
                    self::LEFT,
                    $this->escape($this->toWin($this->footer)),
                    self::RIGHT - $this->width($num, 'r', 8),
                    $this->escape($num)
                );
            }
            $this->pages[$n] = $head . $content . $foot;
        }

        $objs = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
        $id = 3;
        $fontIds = [];
        foreach (['F1' => 'Helvetica-Bold', 'F2' => 'Helvetica', 'F3' => 'Times-Bold', 'F4' => 'Times-Italic', 'F5' => 'Times-Roman'] as $name => $base) {
            $fontIds[$name] = $id;
            $objs[$id++] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $base . ' /Encoding /WinAnsiEncoding >>';
        }
        $fonts = implode(' ', array_map(static fn ($n, $i) => '/' . $n . ' ' . $i . ' 0 R', array_keys($fontIds), $fontIds));
        $kids = [];
        foreach ($this->pages as $content) {
            $pageId = $id++;
            $contentId = $id++;
            $kids[] = $pageId . ' 0 R';
            $objs[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << ' . $fonts . ' >> >> /Contents ' . $contentId . ' 0 R >>';
            $objs[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= $n . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        return $pdf . "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    /* ---------- dibujo ---------- */

    /** Punta de flecha ➢ (no existe en WinAnsi): dos triángulos, uno más claro para el relieve. */
    private function arrow(float $x, float $baseline, float $size): void
    {
        $h = $size * 0.66;
        $y0 = $baseline - $size * 0.02;
        $mid = $y0 + $h / 2;
        $tip = $x + $h * 1.25;
        $notch = $x + $h * 0.32;
        $this->buf .= sprintf("q %s rg %.2F %.2F m %.2F %.2F l %.2F %.2F l h f Q\n", self::RED, $x, $y0 + $h, $tip, $mid, $notch, $mid);
        $this->buf .= sprintf("q 0.36 0.07 0.08 rg %.2F %.2F m %.2F %.2F l %.2F %.2F l h f Q\n", $x, $y0, $tip, $mid, $notch, $mid);
    }

    private function circle(float $cx, float $cy, float $r): string
    {
        $k = 0.5523 * $r;
        return sprintf(
            '%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c',
            $cx + $r, $cy,
            $cx + $r, $cy + $k, $cx + $k, $cy + $r, $cx, $cy + $r,
            $cx - $k, $cy + $r, $cx - $r, $cy + $k, $cx - $r, $cy,
            $cx - $r, $cy - $k, $cx - $k, $cy - $r, $cx, $cy - $r,
            $cx + $k, $cy - $r, $cx + $r, $cy - $k, $cx + $r, $cy
        );
    }

    /** Flor de cinco pétalos con centro claro y estambres. */
    private function blossom(float $cx, float $cy, float $r, float $turn = 0.0): string
    {
        $out = '';
        for ($i = 0; $i < 5; $i++) {
            $a = $turn + $i * 2 * M_PI / 5;
            $out .= sprintf("q 0.76 0.12 0.14 rg %s f Q\n", $this->circle($cx + cos($a) * $r * 0.95, $cy + sin($a) * $r * 0.95, $r * 0.72));
        }
        for ($i = 0; $i < 5; $i++) {
            $a = $turn + $i * 2 * M_PI / 5;
            $out .= sprintf("q 0.86 0.30 0.30 rg %s f Q\n", $this->circle($cx + cos($a) * $r * 0.78, $cy + sin($a) * $r * 0.78, $r * 0.34));
        }
        $out .= sprintf("q 0.98 0.86 0.56 rg %s f Q\n", $this->circle($cx, $cy, $r * 0.42));
        for ($i = 0; $i < 7; $i++) {
            $a = $turn + $i * 2 * M_PI / 7;
            $out .= sprintf("q 0.45 0.08 0.09 rg %s f Q\n", $this->circle($cx + cos($a) * $r * 0.52, $cy + sin($a) * $r * 0.52, $r * 0.09));
        }
        return $out;
    }

    /** Rama de ciruelo en flor (vectores propios, en un cuadro de 170 × 120 a escala $s). */
    private function plumBranch(float $x, float $y, float $s): void
    {
        $p = static fn (float $px, float $py): string => sprintf('%.2F %.2F', $x + $px * $s, $y + $py * $s);
        $o = "q 1 J 1 j 0.33 0.20 0.13 RG\n";
        $o .= sprintf("%.2F w %s m %s %s %s c S\n", 4.2 * $s, $p(168, 6), $p(140, 20), $p(118, 30), $p(98, 46));
        $o .= sprintf("%.2F w %s m %s %s %s c S\n", 3.0 * $s, $p(98, 46), $p(80, 60), $p(62, 70), $p(44, 92));
        $o .= sprintf("%.2F w %s m %s %s %s c S\n", 1.8 * $s, $p(44, 92), $p(34, 104), $p(24, 110), $p(12, 114));
        $o .= sprintf("%.2F w %s m %s %s %s c S\n", 2.0 * $s, $p(118, 30), $p(128, 50), $p(126, 66), $p(138, 86));
        $o .= sprintf("%.2F w %s m %s %s %s c S\n", 1.3 * $s, $p(138, 86), $p(144, 96), $p(152, 102), $p(160, 104));
        $o .= sprintf("%.2F w %s m %s %s %s c S\n", 1.5 * $s, $p(74, 64), $p(70, 50), $p(60, 40), $p(48, 36));
        $o .= "Q\n";
        $o .= $this->blossom($x + 44 * $s, $y + 92 * $s, 7.5 * $s, 0.3);
        $o .= $this->blossom($x + 96 * $s, $y + 50 * $s, 8.5 * $s, 1.1);
        $o .= $this->blossom($x + 136 * $s, $y + 82 * $s, 7.0 * $s, 0.7);
        $o .= $this->blossom($x + 56 * $s, $y + 38 * $s, 6.0 * $s, 0.0);
        foreach ([[18, 112, 2.6], [160, 104, 2.8], [72, 66, 2.4], [126, 60, 2.2], [150, 26, 2.0]] as [$bx, $by, $br]) {
            $o .= sprintf("q 0.70 0.10 0.12 rg %s f Q\n", $this->circle($x + $bx * $s, $y + $by * $s, $br * $s));
        }
        $this->buf .= $o;
    }

    /* ---------- texto ---------- */

    private function need(float $h): void
    {
        if ($this->y < 52 + $h) {
            $this->addPage();
        }
    }

    /** Un renglón alineado a L, C o R; $spacing = espacio entre letras (títulos). */
    private function text(string $text, float $size, string $font, string $align, string $color, float $spacing = 0.0): void
    {
        $win = $this->toWin($text);
        $w = $this->width($win, $font, $size) + $spacing * max(0, strlen($win) - 1);
        $x = match ($align) {
            'C' => 297.5 - $w / 2,
            'R' => self::RIGHT - $w,
            default => self::LEFT,
        };
        $this->buf .= sprintf("BT /%s %.2F Tf 0 Tw %.2F Tc %s rg %.2F %.2F Td (%s) Tj 0 Tc ET\n", self::FONTS[$font], $size, $spacing, $color, $x, $this->y, $this->escape($win));
    }

    private function at(string $win, float $size, string $font, float $x, float $y, string $color): void
    {
        $this->buf .= sprintf("BT /%s %.2F Tf 0 Tw %s rg %.2F %.2F Td (%s) Tj ET\n", self::FONTS[$font], $size, $color, $x, $y, $this->escape($win));
    }

    /** @return list<string> renglones ya en Windows-1252 */
    private function wrapWords(string $text, string $font, float $size, float $max): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $w) {
            $try = $line === '' ? $w : $line . ' ' . $w;
            if ($line !== '' && $this->width($this->toWin($try), $font, $size) > $max) {
                $lines[] = $this->toWin($line);
                $line = $w;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $this->toWin($line);
        }
        return $lines ?: [''];
    }

    private function width(string $win, string $font, float $size): float
    {
        $key = in_array($font, ['b', 'sb'], true) ? 'b' : 'r';
        if (!isset(self::$widths[$key])) {
            self::$widths[$key] = array_map('intval', explode(',', $key === 'b' ? self::W_BOLD : self::W_REGULAR));
        }
        $table = self::$widths[$key];
        $sum = 0;
        $len = strlen($win);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($win[$i]);
            $sum += $c >= 32 ? $table[$c - 32] : 0;
        }
        return $sum * $size / 1000;
    }

    /** UTF-8 → Windows-1252 (igual que FluxusPdf). */
    private function toWin(string $text): string
    {
        $text = strtr($text, [
            "\u{00A0}" => ' ', "\u{2002}" => ' ', "\u{2003}" => ' ', "\u{2009}" => ' ', "\u{202F}" => ' ',
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2212}" => '-', "\u{27A2}" => '>',
            "\u{2032}" => "'", "\u{2033}" => '"', "\u{2192}" => '->', "\u{2713}" => 'v', "\u{00AD}" => '',
        ]);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? $text;
        $converted = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($text, 'Windows-1252', 'UTF-8')
            : @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if (!is_string($converted) || $converted === '') {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '?';
        }
        return $converted;
    }

    private function escape(string $win): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $win);
    }
}
