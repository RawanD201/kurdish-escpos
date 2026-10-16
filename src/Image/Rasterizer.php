<?php

declare(strict_types=1);

namespace Rawand\KurdishEscpos\Image;

use GdImage;
use Rawand\KurdishEscpos\Text\Line;

/**
 * Draws shaped text into a GD image.
 *
 * GD's text functions map characters rather than glyph IDs, which loses
 * contextual letter forms. This class therefore reads each glyph outline
 * from the font and fills it with GD, using the non-zero winding rule.
 *
 * @internal
 */
final class Rasterizer
{
    /** @var list<array{float, float, float, float, int}> edges: [top y, bottom y, x at top, dx per y, direction] */
    private array $edges = [];

    private float $scale = 1.0;

    private float $originX = 0.0;

    private float $originY = 0.0;

    /**
     * Draws a line with its left end at $x and its baseline at $y. All
     * coordinates are multiplied by $zoom, which is used for supersampling.
     *
     * @return array{int, int, int, int}|null the inked pixels [left, top, right, bottom], or null if none
     */
    public function drawLine(GdImage $image, Line $line, float $x, float $y, float $zoom, int $color): ?array
    {
        $this->edges = [];
        foreach ($line->glyphs as [$glyph, $gx, $gy, , $font]) {
            $this->scale = $line->size * $zoom / $font->unitsPerEm;
            $this->originX = ($x + $gx) * $zoom;
            $this->originY = ($y - $gy) * $zoom;
            foreach ($font->outline($glyph) as $contour) {
                $this->addContour($contour);
            }
        }

        return $this->fill($image, $color);
    }

    /** @param  list<array{float, float, bool}>  $points  TrueType contour: on-curve points and quadratic control points */
    private function addContour(array $points): void
    {
        $n = count($points);
        if ($n < 2) {
            return;
        }
        // Start at an on-curve point, or at the midpoint of the first two points if there is none.
        $first = 0;
        while ($first < $n && ! $points[$first][2]) {
            $first++;
        }
        if ($first === $n) {
            $start = [($points[0][0] + $points[1][0]) / 2, ($points[0][1] + $points[1][1]) / 2];
            $first = 0;
            $count = $n;
        } else {
            $start = $points[$first];
            $count = $n - 1;
        }

        $current = $start;
        $control = null;
        for ($k = 1; $k <= $count; $k++) {
            $p = $points[($first + $k) % $n];
            if ($p[2]) {
                $control === null ? $this->addLine($current, $p) : $this->addQuad($current, $control, $p);
                [$current, $control] = [$p, null];
            } elseif ($control === null) {
                $control = $p;
            } else {
                $mid = [($control[0] + $p[0]) / 2, ($control[1] + $p[1]) / 2];
                $this->addQuad($current, $control, $mid);
                [$current, $control] = [$mid, $p];
            }
        }
        $control === null ? $this->addLine($current, $start) : $this->addQuad($current, $control, $start);
    }

    /**
     * Flattens a quadratic curve into line segments.
     *
     * @param  array{0: float, 1: float, 2?: bool}  $p0
     * @param  array{0: float, 1: float, 2?: bool}  $p1
     * @param  array{0: float, 1: float, 2?: bool}  $p2
     */
    private function addQuad(array $p0, array $p1, array $p2): void
    {
        $dx = ($p0[0] - 2 * $p1[0] + $p2[0]) * $this->scale;
        $dy = ($p0[1] - 2 * $p1[1] + $p2[1]) * $this->scale;
        $steps = max(1, (int) ceil(sqrt(sqrt($dx * $dx + $dy * $dy) * 2)));
        $previous = $p0;
        for ($s = 1; $s <= $steps; $s++) {
            $t = $s / $steps;
            $u = 1 - $t;
            $point = [
                $u * $u * $p0[0] + 2 * $u * $t * $p1[0] + $t * $t * $p2[0],
                $u * $u * $p0[1] + 2 * $u * $t * $p1[1] + $t * $t * $p2[1],
            ];
            $this->addLine($previous, $point);
            $previous = $point;
        }
    }

    /**
     * Adds an edge, converting from font units (y up) to image pixels (y down).
     *
     * @param  array{0: float, 1: float, 2?: bool}  $a
     * @param  array{0: float, 1: float, 2?: bool}  $b
     */
    private function addLine(array $a, array $b): void
    {
        $x0 = $this->originX + $a[0] * $this->scale;
        $y0 = $this->originY - $a[1] * $this->scale;
        $x1 = $this->originX + $b[0] * $this->scale;
        $y1 = $this->originY - $b[1] * $this->scale;
        if ($y0 === $y1) {
            return;
        }
        if ($y0 < $y1) {
            $this->edges[] = [$y0, $y1, $x0, ($x1 - $x0) / ($y1 - $y0), 1];
        } else {
            $this->edges[] = [$y1, $y0, $x1, ($x0 - $x1) / ($y0 - $y1), -1];
        }
    }

    /**
     * Fills the collected edges. A pixel is inside when its center has a
     * non-zero winding number; each horizontal span is drawn with one GD call.
     *
     * @return array{int, int, int, int}|null the inked pixels [left, top, right, bottom]
     */
    private function fill(GdImage $image, int $color): ?array
    {
        if ($this->edges === []) {
            return null;
        }
        $bounds = null;
        usort($this->edges, fn ($a, $b) => $a[0] <=> $b[0]);
        $height = imagesy($image);
        $top = max(0, (int) floor($this->edges[0][0]));
        $bottom = min($height - 1, (int) ceil(max(array_column($this->edges, 1))));

        $next = 0;
        $count = count($this->edges);
        $active = [];
        for ($row = $top; $row <= $bottom; $row++) {
            $y = $row + 0.5;
            while ($next < $count && $this->edges[$next][0] <= $y) {
                $active[] = $this->edges[$next++];
            }
            $crossings = [];
            foreach ($active as $k => $e) {
                if ($e[1] <= $y) {
                    unset($active[$k]);
                } elseif ($e[0] <= $y) {
                    $crossings[] = [$e[2] + ($y - $e[0]) * $e[3], $e[4]];
                }
            }
            if ($crossings === []) {
                continue;
            }
            usort($crossings, fn ($a, $b) => $a[0] <=> $b[0]);
            $winding = 0;
            $spanStart = 0.0;
            foreach ($crossings as [$x, $direction]) {
                if ($winding === 0) {
                    $spanStart = $x;
                }
                $winding += $direction;
                if ($winding === 0) {
                    $from = (int) ceil($spanStart - 0.5);
                    $to = (int) floor($x - 0.5);
                    if ($to >= $from) {
                        imagefilledrectangle($image, $from, $row, $to, $row, $color);
                        $bounds = $bounds === null
                            ? [$from, $row, $to, $row]
                            : [min($bounds[0], $from), $bounds[1], max($bounds[2], $to), $row];
                    }
                }
            }
        }

        return $bounds;
    }
}
