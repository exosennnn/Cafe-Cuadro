<?php
/**
 * SimplePDF - A minimal, dependency-free PDF generator for HRMS reports.
 * Works without Composer / external libraries - pure PHP, XAMPP-compatible.
 *
 * Supports: multi-page A4 documents, text, simple tables, lines/rectangles.
 * Not a full-featured PDF engine (no images, no unicode fonts) - sufficient
 * for tabular HR reports (employee lists, payroll, attendance, etc).
 */
class SimplePDF
{
    private array $pages = [];
    private string $buffer = '';
    private float $pageWidth = 595.28;  // A4 width in points
    private float $pageHeight = 841.89; // A4 height in points
    private float $margin = 40;
    private float $y;
    private string $title;

    public function __construct(string $title = 'Report') {
        $this->title = $title;
        $this->addPage();
    }

    public function addPage(): void {
        if ($this->buffer !== '') {
            $this->pages[] = $this->buffer;
        }
        $this->buffer = '';
        $this->y = $this->pageHeight - $this->margin;
        $this->writeHeader();
    }

    private function esc(string $s): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private function textAt(float $x, float $y, string $text, int $size = 10, bool $bold = false): void {
        $font = $bold ? '/F2' : '/F1';
        $this->buffer .= "BT $font $size Tf $x $y Td (" . $this->esc($text) . ") Tj ET\n";
    }

    private function line(float $x1, float $y1, float $x2, float $y2): void {
        $this->buffer .= "0.5 w $x1 $y1 m $x2 $y2 l S\n";
    }

    private function writeHeader(): void {
        $this->textAt($this->margin, $this->y, APP_NAME ?? 'Café Cuadro', 8, false);
        $this->textAt($this->pageWidth - $this->margin - 100, $this->y, date('M d, Y g:i A'), 8, false);
        $this->y -= 14;
        $this->line($this->margin, $this->y, $this->pageWidth - $this->margin, $this->y);
        $this->y -= 20;
        $this->textAt($this->margin, $this->y, $this->title, 16, true);
        $this->y -= 26;
    }

    private function checkPageBreak(float $needed = 20): void {
        if ($this->y - $needed < $this->margin + 20) {
            $this->addPage();
        }
    }

    public function h2(string $text): void {
        $this->checkPageBreak(30);
        $this->textAt($this->margin, $this->y, $text, 13, true);
        $this->y -= 18;
    }

    public function paragraph(string $text): void {
        $this->checkPageBreak(16);
        $this->textAt($this->margin, $this->y, $text, 10, false);
        $this->y -= 16;
    }

    /**
     * Render a simple table.
     * @param array $headers  column titles
     * @param array $rows     array of arrays (row => [col1, col2, ...])
     * @param array $widths   relative widths (sum should roughly equal available width)
     */
    public function table(array $headers, array $rows, array $widths = []): void {
        $available = $this->pageWidth - (2 * $this->margin);
        if (empty($widths)) {
            $widths = array_fill(0, count($headers), $available / count($headers));
        } else {
            $totalUnits = array_sum($widths);
            $widths = array_map(fn($w) => ($w / $totalUnits) * $available, $widths);
        }

        $this->checkPageBreak(24);
        // Header row
        $x = $this->margin;
        foreach ($headers as $i => $h) {
            $this->textAt($x + 2, $this->y, (string)$h, 9, true);
            $x += $widths[$i];
        }
        $this->y -= 4;
        $this->line($this->margin, $this->y, $this->pageWidth - $this->margin, $this->y);
        $this->y -= 14;

        foreach ($rows as $row) {
            $this->checkPageBreak(16);
            $x = $this->margin;
            foreach ($row as $i => $cell) {
                $cellText = (string)$cell;
                if (strlen($cellText) > 40) $cellText = substr($cellText, 0, 37) . '...';
                $this->textAt($x + 2, $this->y, $cellText, 9, false);
                $x += $widths[$i] ?? 60;
            }
            $this->y -= 15;
        }
        $this->y -= 6;
    }

    /**
     * Render one full-width signature block (e.g. a lone Witness signature,
     * where signatureRow()'s two-column layout would leave an empty line
     * on the unused side).
     * @param array $lines lines of text under the signature line
     */
    public function singleSignature(array $lines): void {
        $this->checkPageBreak(30 + (count($lines) * 14));
        $available = $this->pageWidth - (2 * $this->margin);

        $this->line($this->margin, $this->y, $this->margin + $available, $this->y);
        $this->y -= 16;

        foreach ($lines as $i => $text) {
            $this->textAt($this->margin, $this->y - ($i * 14), (string)$text, 10, false);
        }
        $this->y -= (count($lines) * 14) + 10;
    }

    public function spacer(float $h = 10): void {
        $this->y -= $h;
    }

    /**
     * Render one signature block that reproduces an actual captured
     * signature (pen strokes) above the line, instead of only a blank line
     * to sign by hand. $strokes is the same normalized-stroke format used on
     * screen: an array of strokes, each an array of [x, y] points in 0..1.
     * Pass an empty array to fall back to a blank signature line.
     *
     * @param array $strokes  decoded signature_data, or [] if unsigned
     * @param array $lines    lines of text under the signature line
     */
    public function signatureBlock(array $strokes, array $lines, float $boxW = 170, float $boxH = 44): void {
        $needed = $boxH + 10 + (count($lines) * 14) + 20;
        $this->checkPageBreak($needed);

        $boxX = $this->margin;
        $boxY = $this->y - $boxH;

        if (!empty($strokes)) {
            $this->buffer .= "0.9 w\n";
            foreach ($strokes as $stroke) {
                if (!is_array($stroke) || count($stroke) < 2) continue;
                $started = false;
                foreach ($stroke as $pt) {
                    if (!is_array($pt) || count($pt) < 2) continue;
                    $px = $boxX + ((float)$pt[0] * $boxW);
                    $py = $boxY + ($boxH - ((float)$pt[1] * $boxH)); // flip: canvas y-down -> PDF y-up
                    $this->buffer .= sprintf('%.2F %.2F %s ', $px, $py, $started ? 'l' : 'm');
                    $started = true;
                }
                $this->buffer .= "S\n";
            }
        }

        $this->line($boxX, $boxY, $boxX + $boxW, $boxY);
        $this->y = $boxY - 16;

        foreach ($lines as $i => $text) {
            $this->textAt($boxX, $this->y - ($i * 14), (string)$text, 10, false);
        }
        $this->y -= (count($lines) * 14) + 10;
    }

    /**
     * Render two signature blocks side by side at the current position -
     * e.g. Employer on the left, Employee on the right. Each block gets its
     * own signature line, followed by its label lines (name/role, "Date:", etc).
     * @param array $leftLines  lines of text under the left signature line
     * @param array $rightLines lines of text under the right signature line
     */
    public function signatureRow(array $leftLines, array $rightLines): void {
        $lineCount = max(count($leftLines), count($rightLines));
        $this->checkPageBreak(30 + ($lineCount * 14));

        $available = $this->pageWidth - (2 * $this->margin);
        $gap = 30;
        $colWidth = ($available - $gap) / 2;
        $leftX = $this->margin;
        $rightX = $this->margin + $colWidth + $gap;

        $this->line($leftX, $this->y, $leftX + $colWidth, $this->y);
        $this->line($rightX, $this->y, $rightX + $colWidth, $this->y);
        $this->y -= 16;

        $rowY = $this->y;
        foreach ($leftLines as $i => $text) {
            $this->textAt($leftX, $rowY - ($i * 14), (string)$text, 10, false);
        }
        foreach ($rightLines as $i => $text) {
            $this->textAt($rightX, $rowY - ($i * 14), (string)$text, 10, false);
        }
        $this->y -= ($lineCount * 14) + 10;
    }

    /**
     * Print a long block of text wrapped to fit the page width (approx.
     * $width characters per line at default 10pt paragraph size). Existing
     * line breaks in $text are preserved; blank lines add a small spacer.
     */
    public function wrappedParagraph(string $text, int $width = 95): void {
        foreach (explode("\n", $text) as $line) {
            $line = rtrim($line);
            if ($line === '') { $this->spacer(8); continue; }
            foreach (explode("\n", wordwrap($line, $width, "\n", true)) as $wrapped) {
                $this->paragraph($wrapped);
            }
        }
    }

    /** Output the PDF. $dest: 'D' = download, 'F' = save to file path in $path */
    public function output(string $filename = 'report.pdf', string $dest = 'D', string $path = '') {
        if ($this->buffer !== '') {
            $this->pages[] = $this->buffer;
        }

        $objects = [];
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";

        $kids = [];
        $pageObjStart = 4; // object 3 = Pages, objects start at 4 for pages+content, font objects appended after
        $numPages = count($this->pages);

        // We will assign: 1=Catalog, 2=Pages, 3.. = alternating Page/Content, then Font objects
        $pdfObjects = [];
        $pdfObjects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        $pageRefs = [];
        $nextObjId = 3;
        $pageContentPairs = [];
        foreach ($this->pages as $content) {
            $pageObjId = $nextObjId++;
            $contentObjId = $nextObjId++;
            $pageContentPairs[] = [$pageObjId, $contentObjId, $content];
            $pageRefs[] = "$pageObjId 0 R";
        }

        $fontF1Id = $nextObjId++;
        $fontF2Id = $nextObjId++;

        $pdfObjects[2] = "<< /Type /Pages /Kids [" . implode(' ', $pageRefs) . "] /Count " . count($pageRefs) . " >>";

        foreach ($pageContentPairs as [$pageObjId, $contentObjId, $content]) {
            $pdfObjects[$pageObjId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}] "
                . "/Resources << /Font << /F1 $fontF1Id 0 R /F2 $fontF2Id 0 R >> >> /Contents $contentObjId 0 R >>";
            $len = strlen($content);
            $pdfObjects[$contentObjId] = "<< /Length $len >>\nstream\n$content\nendstream";
        }

        $pdfObjects[$fontF1Id] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $pdfObjects[$fontF2Id] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        ksort($pdfObjects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($pdfObjects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxId = max(array_keys($pdfObjects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            if (isset($offsets[$i])) {
                $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
            } else {
                $pdf .= "0000000000 00000 f \n";
            }
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n$xrefOffset\n%%EOF";

        if ($dest === 'F') {
            file_put_contents($path, $pdf);
            return $path;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}
