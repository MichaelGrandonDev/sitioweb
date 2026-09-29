<?php

declare(strict_types=1);

/**
 * Generador PDF mínimo sin dependencias (A4, Helvetica).
 */
final class FluxusPdf
{
    /** Anchos (1/1000 em) de los bytes 32–255 en WinAnsi: Helvetica y Helvetica-Bold. */
    private const WIDTHS_REGULAR = '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500';
    private const WIDTHS_BOLD = '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556';
    private const LEFT = 50.0;
    private const RIGHT = 545.0;

    /** @var array<string, list<int>> */
    private static array $widths = [];

    /** @var list<string> */
    private array $pages = [];
    private string $buf = '';
    private float $y = 790;
    private string $footer = '';

    public function addPage(): void
    {
        if ($this->buf !== '') {
            $this->pages[] = $this->buf;
        }
        $this->buf = '';
        $this->y = 790;
    }

    public function title(string $text): void
    {
        $this->writeText($text, 24, true, 'C');
        $this->y -= 8;
    }

    public function subtitle(string $text): void
    {
        $this->writeText($text, 12, false, 'C');
        $this->y -= 10;
    }

    public function heading(string $text): void
    {
        $this->y -= 10;
        $this->writeText($text, 13, true, 'L');
        $this->y -= 4;
    }

    public function paragraph(string $text): void
    {
        foreach ($this->wrap($text, 90) as $line) {
            $this->need(18);
            $this->writeText($line, 11, false, 'L');
        }
        $this->y -= 4;
    }

    public function bullet(string $text): void
    {
        $clean = ltrim($text, "•\t *-");
        $this->paragraph('- ' . $clean);
    }

    /** Renglón centrado (títulos dentro del documento). */
    public function centered(string $text, float $size = 13, bool $bold = true): void
    {
        $this->y -= 6;
        $this->need($size + 10);
        $this->writeText($text, $size, $bold, 'C');
        $this->y -= 4;
    }

    /** Pasa a una página nueva si no quedan $h puntos (para no partir un bloque, ej. las firmas). */
    public function keepTogether(float $h): void
    {
        $this->need($h);
    }

    /** Texto chico al pie de cada página, con "Página N de M" a la derecha. */
    public function setFooter(string $text): void
    {
        $this->footer = $text;
    }

    /**
     * Párrafo justificado con tramos en negrita o normal: [['PRIMERO:', true], ['La Medicina…', false]].
     * $gutter se escribe a la izquierda del texto (a), i.-) con sangría francesa de $hang puntos.
     *
     * @param list<array{0: string, 1: bool}> $runs
     */
    public function richParagraph(array $runs, string $gutter = '', float $indent = 0, float $hang = 0, float $size = 10): void
    {
        $words = [];
        foreach ($runs as [$text, $bold]) {
            foreach (preg_split('/\s+/u', trim((string) $text)) ?: [] as $w) {
                if ($w !== '') {
                    $words[] = [$this->toWin($w), (bool) $bold];
                }
            }
        }
        if (!$words) {
            return;
        }
        $left = self::LEFT + $indent + $hang;
        $avail = self::RIGHT - $left;
        $space = fn (bool $bold): float => $this->width(' ', $bold, $size);

        $lines = [];
        $line = [];
        $lineW = 0.0;
        foreach ($words as $word) {
            $w = $this->width($word[0], $word[1], $size);
            $add = $line ? $space($word[1]) + $w : $w;
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

        $leading = $size * 1.38;
        $last = count($lines) - 1;
        foreach ($lines as $i => [$lineWords, $width]) {
            $this->need($leading);
            $tw = 0.0;
            if ($i < $last && count($lineWords) > 1) {
                $tw = ($avail - $width) / (count($lineWords) - 1);
            }
            if ($i === 0 && $gutter !== '') {
                $this->buf .= sprintf(
                    "BT /F2 %.2F Tf 0.07 0.24 0.21 rg 0 Tw %.2F %.2F Td (%s) Tj ET\n",
                    $size,
                    self::LEFT + $indent,
                    $this->y,
                    $this->encode($gutter)
                );
            }
            $ops = '';
            $font = null;
            $segment = '';
            foreach ($lineWords as $j => [$word, $bold]) {
                if ($font !== null && $bold !== $font) {
                    $ops .= sprintf('%s %.2F Tf (%s) Tj ', $font ? '/F1' : '/F2', $size, $this->escape($segment));
                    $segment = '';
                }
                $font = $bold;
                $segment .= ($j > 0 ? ' ' : '') . $word;
            }
            $ops .= sprintf('%s %.2F Tf (%s) Tj ', $font ? '/F1' : '/F2', $size, $this->escape($segment));
            $this->buf .= sprintf("BT 0.07 0.24 0.21 rg %.3F Tw %.2F %.2F Td %sET\n", $tw, $left, $this->y, $ops);
            $this->y -= $leading;
        }
        $this->y -= $size * 0.55;
    }

    public function rule(): void
    {
        $this->need(20);
        $y = $this->y + 6;
        $this->buf .= sprintf("0.06 0.24 0.21 RG 1.2 w 50 %.2F m 545 %.2F l S\n", $y, $y);
        $this->y -= 16;
    }

    public function small(string $text): void
    {
        foreach ($this->wrap($text, 105) as $line) {
            $this->need(14);
            $this->writeText($line, 9, false, 'L');
        }
        $this->y -= 2;
    }

    /** Líneas para completar a mano (firma, aclaración, DNI…), de a dos por renglón. */
    public function signatureRow(string $left, string $right = ''): void
    {
        $this->need(60);
        $this->y -= 30;
        $y = $this->y + 12;
        $this->buf .= sprintf("0.2 0.2 0.2 RG 0.7 w 50 %.2F m 270 %.2F l S\n", $y, $y);
        if ($right !== '') {
            $this->buf .= sprintf("0.2 0.2 0.2 RG 0.7 w 325 %.2F m 545 %.2F l S\n", $y, $y);
        }
        $this->buf .= sprintf("BT /F2 9 Tf 0 Tw 0.3 0.3 0.3 rg 50 %.2F Td (%s) Tj ET\n", $this->y, $this->encode($left));
        if ($right !== '') {
            $this->buf .= sprintf("BT /F2 9 Tf 0 Tw 0.3 0.3 0.3 rg 325 %.2F Td (%s) Tj ET\n", $this->y, $this->encode($right));
        }
        $this->y -= 22;
    }

    public function output(string $filename): never
    {
        $pdf = $this->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $pdf;
        exit;
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
        if ($this->footer !== '') {
            $total = count($this->pages);
            foreach ($this->pages as $n => $content) {
                $num = 'Página ' . ($n + 1) . ' de ' . $total;
                $this->pages[$n] = $content . sprintf(
                    "BT /F2 8 Tf 0 Tw 0.4 0.45 0.43 rg %.2F 28 Td (%s) Tj ET\nBT /F2 8 Tf 0 Tw 0.4 0.45 0.43 rg %.2F 28 Td (%s) Tj ET\n",
                    self::LEFT,
                    $this->encode($this->footer),
                    self::RIGHT - $this->width($this->toWin($num), false, 8),
                    $this->encode($num)
                );
            }
        }

        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $id = 4;
        $pageMeta = [];
        foreach ($this->pages as $content) {
            $pageId = $id++;
            $contentId = $id++;
            $kids[] = $pageId . ' 0 R';
            $pageMeta[] = [$pageId, $contentId, $content];
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pageMeta) . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $reg = $id++;
        $objs[$reg] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        foreach ($pageMeta as [$pageId, $contentId, $content]) {
            $objs[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',
                $reg,
                $contentId
            );
            $objs[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }

        ksort($objs);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= $n . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($max + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    private function need(float $h): void
    {
        if ($this->y < 50 + $h) {
            $this->addPage();
        }
    }

    private function writeText(string $text, float $size, bool $bold, string $align): void
    {
        $font = $bold ? '/F1' : '/F2';
        $win = $this->toWin($text);
        $x = self::LEFT;
        if ($align === 'C') {
            $x = max(self::LEFT, 297.5 - $this->width($win, $bold, $size) / 2);
        }
        $this->buf .= sprintf(
            "BT %s %.2F Tf 0 Tw 0.07 0.24 0.21 rg %.2F %.2F Td (%s) Tj ET\n",
            $font,
            $size,
            $x,
            $this->y,
            $this->escape($win)
        );
        $this->y -= ($size + 6);
    }

    /** Ancho en puntos de un texto ya convertido a Windows-1252. */
    private function width(string $win, bool $bold, float $size): float
    {
        $key = $bold ? 'b' : 'r';
        if (!isset(self::$widths[$key])) {
            self::$widths[$key] = array_map('intval', explode(',', $bold ? self::WIDTHS_BOLD : self::WIDTHS_REGULAR));
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

    /** @return list<string> */
    private function wrap(string $text, int $max): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $out = [];
        foreach (explode("\n", $text) as $para) {
            $para = trim($para);
            if ($para === '') {
                $out[] = '';
                continue;
            }
            $words = preg_split('/\s+/u', $para) ?: [];
            $line = '';
            foreach ($words as $w) {
                $try = $line === '' ? $w : $line . ' ' . $w;
                if (mb_strlen($try) > $max) {
                    if ($line !== '') {
                        $out[] = $line;
                    }
                    $line = $w;
                } else {
                    $line = $try;
                }
            }
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out ?: [''];
    }

    /**
     * UTF-8 → Windows-1252 (WinAnsiEncoding de las fuentes). Acentos, ñ, °, comillas “ ” y rayas – — existen en
     * cp1252; lo que no existe se aproxima antes de convertir para no depender del //TRANSLIT de cada servidor.
     */
    private function toWin(string $text): string
    {
        $text = strtr($text, [
            "\u{00A0}" => ' ', "\u{2002}" => ' ', "\u{2003}" => ' ', "\u{2009}" => ' ', "\u{202F}" => ' ',
            "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2212}" => '-',
            "\u{2032}" => "'", "\u{2033}" => '"', "\u{2192}" => '->', "\u{2713}" => 'v', "\u{00AD}" => '',
        ]);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? $text;
        if (function_exists('mb_convert_encoding')) {
            $converted = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        } else {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        }
        if (!is_string($converted) || $converted === '') {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '?';
        }
        return $converted;
    }

    private function escape(string $win): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $win);
    }

    private function encode(string $text): string
    {
        return $this->escape($this->toWin($text));
    }
}
