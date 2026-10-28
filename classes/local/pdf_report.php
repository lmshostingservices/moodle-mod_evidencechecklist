<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_evidencechecklist\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/pdflib.php');

/**
 * Practical Evidence Report PDF, laid out by hand: cards, chips, centred QR tiles, embedded font.
 *
 * @package    mod_evidencechecklist
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_report extends \pdf {
    /** @var float Left margin (mm). */
    const ML = 16;
    /** @var float Content width (mm). */
    const CW = 178;
    /** @var float Bottom limit for content (mm). */
    const BOTTOM = 276;

    /** @var array Colours. */
    protected $c = [];
    /** @var string Footer left text. */
    public $footerleft = '';
    /** @var string Running header text. */
    public $headerleft = '';
    /** @var string Temp dir. */
    protected $dir;
    /** @var float Layout cursor (mm); drawing never moves it, only reserving space does. */
    public $cy = 22;

    /**
     * Set up fonts and colours.
     *
     * @param string $green
     * @param string $magenta
     */
    public function setup_style(string $green, string $magenta): void {
        global $CFG;
        $hex = fn($h) => [hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2))];
        $this->c = [
            'ink' => [16, 24, 40], 'ink2' => [71, 84, 103], 'muted' => [152, 162, 179], 'faint' => [208, 213, 221],
            'border' => [234, 236, 240], 'subtle' => [249, 250, 251], 'canvas' => [247, 248, 250], 'white' => [255, 255, 255],
            'green' => $hex($green), 'greentint' => [233, 249, 239], 'greenink' => [6, 110, 52],
            'magenta' => $hex($magenta), 'magentatint' => [252, 232, 243],
        ];
        $dir = $CFG->dirroot . '/mod/evidencechecklist/pdffonts/';
        foreach (['pjs', 'pjsm', 'pjss', 'pjsb', 'pjsx'] as $f) {
            $this->AddFont($f, '', $dir . $f . '.php');
        }
        $this->dir = make_request_directory();
        $this->SetMargins(self::ML, 22, self::ML);
        $this->SetAutoPageBreak(false);
        $this->setPrintHeader(true);
        $this->setPrintFooter(true);
        $this->SetCellPadding(0);
        $this->setCellHeightRatio(1.35);
    }

    /**
     * Font helper.
     *
     * @param string $weight r, m, s, b, x
     * @param float $size
     * @param string $colour
     */
    public function f(string $weight, float $size, string $colour = 'ink'): void {
        $map = ['r' => 'pjs', 'm' => 'pjsm', 's' => 'pjss', 'b' => 'pjsb', 'x' => 'pjsx'];
        $this->SetFont($map[$weight], '', $size);
        $this->SetTextColor(...$this->c[$colour]);
    }

    /**
     * Fill and draw colours.
     *
     * @param string|null $fill
     * @param string|null $draw
     * @param float $lw
     */
    public function colours(?string $fill, ?string $draw, float $lw = 0.25): void {
        if ($fill) {
            $this->SetFillColor(...$this->c[$fill]);
        }
        if ($draw) {
            $this->SetDrawColor(...$this->c[$draw]);
        }
        $this->SetLineWidth($lw);
    }

    /**
     * Running header (pages 2+).
     */
    public function Header() { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
        if ($this->getPage() <= 1) {
            return;
        }
        $this->f('s', 8, 'muted');
        $this->SetXY(self::ML, 10);
        $this->Cell(self::CW, 4, $this->headerleft, 0, 0, 'L');
        $this->colours(null, 'border', 0.2);
        $this->Line(self::ML, 16, self::ML + self::CW, 16);
    }

    /**
     * Footer with page numbers.
     */
    public function Footer() { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
        $this->colours(null, 'border', 0.2);
        $this->Line(self::ML, 283, self::ML + self::CW, 283);
        $this->f('m', 7.5, 'muted');
        $this->SetXY(self::ML, 285);
        $this->Cell(self::CW / 2, 4, $this->footerleft, 0, 0, 'L');
        $this->Cell(
            self::CW / 2,
            4,
            get_string(
                'pagexofy',
                'mod_evidencechecklist',
                (object)[
                'x' => $this->getAliasNumPage(), 'y' => $this->getAliasNbPages()]
            ),
            0,
            0,
            'R'
        );
    }

    /**
     * Start a new page if the block will not fit.
     *
     * @param float $h
     */
    public function need(float $h): void {
        if ($this->cy + $h > self::BOTTOM) {
            $this->AddPage();
            $this->cy = 22;
        }
    }

    /**
     * Draw a tick or cross icon.
     *
     * @param string $kind S or NYS
     * @param float $x centre x
     * @param float $y centre y
     * @param float $s size
     * @param string $colour
     */
    public function icon(string $kind, float $x, float $y, float $s, string $colour): void {
        $this->SetDrawColor(...$this->c[$colour]);
        $this->SetLineStyle(['width' => $s * 0.16, 'cap' => 'round', 'join' => 'round', 'color' => $this->c[$colour]]);
        if ($kind === 'S') {
            $this->PolyLine([$x - $s * 0.4, $y + $s * 0.02, $x - $s * 0.1, $y + $s * 0.32, $x + $s * 0.42, $y - $s * 0.3]);
        } else if ($kind === 'NYS') {
            $this->Line($x - $s * 0.3, $y - $s * 0.3, $x + $s * 0.3, $y + $s * 0.3);
            $this->Line($x + $s * 0.3, $y - $s * 0.3, $x - $s * 0.3, $y + $s * 0.3);
        } else {
            $this->Line($x - $s * 0.3, $y, $x + $s * 0.3, $y);
        }
        $this->SetLineStyle(['width' => 0.25, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0]);
    }

    /**
     * Rounded pill with a dot or icon.
     *
     * @param float $x
     * @param float $y top
     * @param string $text
     * @param string $kind S, NYS or plain
     * @param float $size font size
     * @param bool $center centre on x instead of starting at x
     * @return float width
     */
    public function pill(float $x, float $y, string $text, string $kind = 'plain', float $size = 8, bool $center = false): float {
        $this->f('b', $size);
        $h = $size * 0.62;
        $w = $this->GetStringWidth($text) + $h * 1.55 + 1.2;
        if ($center) {
            $x -= $w / 2;
        }
        [$fill, $border, $ink] = $kind === 'S' ? ['green', 'green', 'white'] :
            ($kind === 'NYS' ? ['magenta', 'magenta', 'white'] : ['subtle', 'border', 'ink2']);
        $this->colours($fill, $border, 0.2);
        $this->SetAlpha(1);
        $this->RoundedRect($x, $y, $w, $h, $h / 2, '1111', 'DF');
        $this->SetFillColor(...$this->c[$kind === 'plain' ? 'muted' : 'white']);
        $this->Circle($x + $h * 0.55, $y + $h / 2, $h * 0.14, 0, 360, 'F');
        $this->f('b', $size, $ink);
        $this->SetXY($x + $h * 0.95, $y);
        $this->Cell($w - $h * 1.15, $h, $text, 0, 0, 'L', false, '', 0, false, 'T', 'M');
        return $w;
    }

    /**
     * Section heading with optional number chip.
     *
     * @param string $title
     * @param string $number
     * @param string $right muted text on the right
     * @param float $keep space the first block needs, kept on the same page
     */
    public function section(string $title, string $number = '', string $right = '', float $keep = 30): void {
        $this->need(15 + $keep);
        $y = $this->cy + 4;
        if ($right !== '') {
            $this->f('s', 8.5, 'muted');
            $this->SetXY(self::ML, $y);
            $this->Cell(self::CW, 8, $right, 0, 0, 'R', false, '', 0, false, 'T', 'M');
        }
        $x = self::ML;
        if ($number !== '') {
            $this->colours('white', 'border', 0.25);
            $this->RoundedRect($x, $y, 8, 8, 2, '1111', 'DF');
            $this->f('x', 10);
            $this->SetXY($x, $y);
            $this->Cell(8, 8, $number, 0, 0, 'C', false, '', 0, false, 'T', 'M');
            $x += 11;
        }
        $this->f('x', 13);
        $this->SetXY($x, $y);
        $this->Cell(self::CW - ($x - self::ML) - 40, 8, $title, 0, 0, 'L', false, '', 0, false, 'T', 'M');
        $this->cy = $y + 11;
    }

    /**
     * Card background.
     *
     * @param float $y
     * @param float $h
     * @param string $border
     * @param string $fill
     */
    public function card(float $y, float $h, string $border = 'border', string $fill = 'white'): void {
        $this->colours($fill, $border, 0.3);
        $this->RoundedRect(self::ML, $y, self::CW, $h, 3.5, '1111', 'DF');
    }

    /**
     * Height of a MultiCell.
     *
     * @param float $w
     * @param string $text
     * @param string $weight
     * @param float $size
     * @return float
     */
    public function th(float $w, string $text, string $weight, float $size): float {
        $this->f($weight, $size);
        return $this->getStringHeight($w, $text, false, true, 0, 0);
    }

    /**
     * Write wrapped text.
     *
     * @param float $x
     * @param float $y
     * @param float $w
     * @param string $text
     * @param string $weight
     * @param float $size
     * @param string $colour
     * @param string $align
     * @return float bottom y
     */
    public function txt(
        float $x,
        float $y,
        float $w,
        string $text,
        string $weight,
        float $size,
        string $colour = 'ink',
        string $align = 'L'
    ): float {
        $this->f($weight, $size, $colour);
        $this->SetXY($x, $y);
        $this->MultiCell($w, 0, $text, 0, $align, false, 1, $x, $y, true, 0, false, true, 0, 'T');
        return $this->GetY();
    }

    /**
     * Prepare an image file as a JPEG TCPDF can embed; returns [path, w, h].
     *
     * @param \stored_file $file
     * @param int $maxpx
     * @param bool $whiten turn the near-white background of a signature pure white
     * @return array|null
     */
    public function prep_image(\stored_file $file, int $maxpx = 1400, bool $whiten = false): ?array {
        $src = $this->dir . '/' . $file->get_id() . '-src';
        $file->copy_content_to($src);
        $img = @imagecreatefromstring(file_get_contents($src));
        if (!$img) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $maxpx / max($w, $h));
        $nw = (int)round($w * $scale);
        $nh = (int)round($h * $scale);
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if ($whiten) {
            $white = imagecolorallocate($out, 255, 255, 255);
            for ($py = 0; $py < $nh; $py++) {
                for ($px = 0; $px < $nw; $px++) {
                    $rgb = imagecolorat($out, $px, $py);
                    if ((($rgb >> 16) & 255) > 225 && (($rgb >> 8) & 255) > 225 && ($rgb & 255) > 225) {
                        imagesetpixel($out, $px, $py, $white);
                    }
                }
            }
        }
        $path = $src . '.jpg' . ($whiten ? 'w' : '');
        imagejpeg($out, $path, 86);
        return [$path, $nw, $nh];
    }

    /** @var float|null Top of the open box. */
    protected $boxy = null;
    /** @var int Rows drawn in the open box segment. */
    protected $boxrows = 0;

    /**
     * Open an outlined box at the current y; rows are reserved with box_row().
     */
    public function box_start(): void {
        $this->boxy = $this->cy;
        $this->boxrows = 0;
    }

    /**
     * Break the box onto a new page now if a block of this height will not fit.
     *
     * @param float $h
     * @param callable|null $onbreak
     */
    public function box_keep(float $h, ?callable $onbreak = null): void {
        if ($this->cy + $h > self::BOTTOM && $this->boxrows > 1 && $h < 230) {
            $this->box_end(0);
            $this->AddPage();
            $this->cy = 22;
            $this->box_start();
            if ($onbreak) {
                $onbreak();
            }
        }
    }

    /**
     * Reserve a row inside the box, breaking the page (and the box) when needed.
     *
     * @param float $h
     * @param bool $separator hairline above the row
     * @param callable|null $onbreak redraws a header after a page break
     * @return float top y of the row
     */
    public function box_row(float $h, bool $separator = true, ?callable $onbreak = null): float {
        if ($this->cy + $h > self::BOTTOM && $this->boxrows > 0) {
            $this->box_end(0);
            $this->AddPage();
            $this->cy = 22;
            $this->box_start();
            if ($onbreak) {
                $onbreak();
            }
            $separator = $this->boxrows > 0;
        }
        $y = $this->cy;
        if ($separator && $this->boxrows > 0) {
            $this->colours(null, 'border', 0.25);
            $this->Line(self::ML, $y, self::ML + self::CW, $y);
        }
        $this->boxrows++;
        $this->cy = $y + $h;
        return $y;
    }

    /**
     * Close the box with a thin rounded outline.
     *
     * @param float $after space after
     */
    public function box_end(float $after = 6): void {
        if ($this->boxy === null) {
            return;
        }
        $this->colours(null, 'border', 0.3);
        $this->RoundedRect(self::ML, $this->boxy, self::CW, $this->cy - $this->boxy, 3.5, '1111', 'D');
        $this->boxy = null;
        $this->cy += $after;
    }

    /**
     * Header fill for the first row of a box (rounded top corners).
     *
     * @param float $y
     * @param float $h
     */
    public function box_headfill(float $y, float $h): void {
        $this->colours('subtle', null);
        $this->RoundedRect(self::ML, $y, self::CW, $h, 3.5, '1001', 'F');
        $this->colours(null, 'border', 0.25);
        $this->Line(self::ML, $y + $h, self::ML + self::CW, $y + $h);
    }

    /**
     * Image scaled to fit inside a box, centred.
     *
     * @param array $img
     * @param float $x
     * @param float $y
     * @param float $w
     * @param float $h
     * @param string $align C or L
     */
    public function contain(array $img, float $x, float $y, float $w, float $h, string $align = 'C'): void {
        [$path, $iw, $ih] = $img;
        $scale = min($w / $iw, $h / $ih);
        $dw = $iw * $scale;
        $dh = $ih * $scale;
        $dx = $align === 'C' ? $x + ($w - $dw) / 2 : $x;
        $this->Image($path, $dx, $y + ($h - $dh) / 2, $dw, $dh, 'JPG', '', '', false, 200);
    }

    /**
     * Round play button centred at a point.
     *
     * @param float $cx
     * @param float $cy
     * @param float $r
     */
    public function play(float $cx, float $cy, float $r): void {
        $this->SetAlpha(0.92);
        $this->colours('white', null);
        $this->Circle($cx, $cy, $r, 0, 360, 'F');
        $this->SetAlpha(1);
        $this->SetFillColor(...$this->c['ink']);
        $this->Polygon([$cx - $r * 0.3, $cy - $r * 0.42, $cx - $r * 0.3, $cy + $r * 0.42, $cx + $r * 0.45, $cy], 'F');
    }

    /**
     * QR code exactly centred in a box.
     *
     * @param string $url
     * @param float $cx centre x
     * @param float $y top
     * @param float $size
     */
    public function qr(string $url, float $cx, float $y, float $size): void {
        $this->write2DBarcode(
            $url,
            'QRCODE,M',
            $cx - $size / 2,
            $y,
            $size,
            $size,
            ['border' => false, 'padding' => 0,
            'fgcolor' => $this->c['ink'], 'bgcolor' => false, 'position' => ''],
            'N'
        );
    }

    /**
     * Image cropped to fill a rounded box.
     *
     * @param array $img [path, w, h]
     * @param float $x
     * @param float $y
     * @param float $w
     * @param float $h
     * @param float $r
     * @param string $link
     */
    public function cover(array $img, float $x, float $y, float $w, float $h, float $r = 2.5, string $link = ''): void {
        [$path, $iw, $ih] = $img;
        $scale = max($w / $iw, $h / $ih);
        $dw = $iw * $scale;
        $dh = $ih * $scale;
        $this->StartTransform();
        $this->RoundedRect($x, $y, $w, $h, $r, '1111', 'CNZ');
        $this->Image($path, $x - ($dw - $w) / 2, $y - ($dh - $h) / 2, $dw, $dh, 'JPG', $link, '', false, 200);
        $this->StopTransform();
        $this->colours(null, 'border', 0.3);
        $this->RoundedRect($x, $y, $w, $h, $r, '1111', 'D');
    }
}
