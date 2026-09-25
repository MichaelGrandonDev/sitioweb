<?php

declare(strict_types=1);

/**
 * Generador PDF mínimo sin dependencias (A4, Helvetica).
 */
final class FluxusPdf
{
    /** @var list<string> */
    private array $pages = [];
    private string $buf = '';
    private float $y = 790;

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

    public function rule(): void
    {
        $this->need(20);
        $y = $this->y + 6;
        $this->buf .= sprintf("0.06 0.24 0.21 RG 1.2 w 50 %.2F m 545 %.2F l S\n", $y, $y);
        $this->y -= 16;
    }

    public function output(string $filename): never
    {
        if ($this->buf !== '') {
            $this->pages[] = $this->buf;
        }
        if (!$this->pages) {
            $this->pages[] = '';
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

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $pdf;
        exit;
    }

    private function need(float $h): void
    {
        if ($this->y < 50 + $h) {
            $this->addPage();
        }
    }

    private function writeText(string $text, int $size, bool $bold, string $align): void
    {
        $font = $bold ? '/F1' : '/F2';
        $enc = $this->encode($text);
        $x = 50.0;
        if ($align === 'C') {
            $x = 297.5 - (strlen($enc) * $size * 0.25);
        }
        $this->buf .= sprintf(
            "BT %s %d Tf 0.07 0.24 0.21 rg %.2F %.2F Td (%s) Tj ET\n",
            $font,
            $size,
            $x,
            $this->y,
            $enc
        );
        $this->y -= ($size + 6);
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

    private function encode(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '?';
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }
}
