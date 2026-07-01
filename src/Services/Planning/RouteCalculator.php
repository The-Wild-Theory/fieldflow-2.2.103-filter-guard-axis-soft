<?php
namespace RoutesPro\Services\Planning;

if (!defined('ABSPATH')) exit;

class RouteCalculator {

    public function reorderStops(array $items, array $startPoint = [], array $endPoint = []): array {
        $resolved = [];
        foreach ($items as $item) {
            $lat = is_numeric($item['lat'] ?? null) ? (float)$item['lat'] : null;
            $lng = is_numeric($item['lng'] ?? null) ? (float)$item['lng'] : null;
            if ($lat === null || $lng === null) {
                $resolved[] = $item;
                continue;
            }
            $resolved[] = $item;
        }
        if (count($resolved) <= 2) return array_values($resolved);

        $start = [
            'lat' => is_numeric($startPoint['lat'] ?? null) ? (float)$startPoint['lat'] : null,
            'lng' => is_numeric($startPoint['lng'] ?? null) ? (float)$startPoint['lng'] : null,
        ];
        $end = [
            'lat' => is_numeric($endPoint['lat'] ?? null) ? (float)$endPoint['lat'] : null,
            'lng' => is_numeric($endPoint['lng'] ?? null) ? (float)$endPoint['lng'] : null,
        ];

        // Proteção de performance para planos mensais grandes: evita 2-opt pesado em dias muito carregados.
        // Mantém uma ordenação coerente por nearest-neighbor/corredor, mas impede timeouts no BO.
        if (count($resolved) > 14) {
            $candidates = [];
            $candidates[] = $this->nearestNeighbor($resolved, $start);
            $projection = $this->projectionOrder($resolved, $start, $end, false);
            if ($projection) $candidates[] = $projection;
            $projectionReverse = $this->projectionOrder($resolved, $start, $end, true);
            if ($projectionReverse) $candidates[] = $projectionReverse;
            $best = $candidates[0];
            $bestDistance = $this->pathDistance($best, $start, $end);
            foreach ($candidates as $candidate) {
                $distance = $this->pathDistance($candidate, $start, $end);
                if ($distance + 0.0001 < $bestDistance) {
                    $best = $candidate;
                    $bestDistance = $distance;
                }
            }
            return array_values($best);
        }

        $candidates = [];
        $candidates[] = $this->twoOpt($this->nearestNeighbor($resolved, $start), $start, $end);

        $projection = $this->projectionOrder($resolved, $start, $end, false);
        if ($projection) $candidates[] = $this->twoOpt($projection, $start, $end);
        $projectionReverse = $this->projectionOrder($resolved, $start, $end, true);
        if ($projectionReverse) $candidates[] = $this->twoOpt($projectionReverse, $start, $end);

        $best = $candidates[0];
        $bestDistance = $this->pathDistance($best, $start, $end);
        foreach ($candidates as $candidate) {
            $distance = $this->pathDistance($candidate, $start, $end);
            if ($distance + 0.0001 < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }
        return array_values($best);
    }


    private function projectionOrder(array $items, array $start, array $end, bool $reverse = false): array {
        if (!$items) return [];
        if (!is_numeric($start['lat'] ?? null) || !is_numeric($start['lng'] ?? null) || !is_numeric($end['lat'] ?? null) || !is_numeric($end['lng'] ?? null)) return [];
        $sx = (float)$start['lng'];
        $sy = (float)$start['lat'];
        $ex = (float)$end['lng'];
        $ey = (float)$end['lat'];
        $vx = $ex - $sx;
        $vy = $ey - $sy;
        $len2 = ($vx * $vx) + ($vy * $vy);
        if ($len2 <= 0.000001) return [];
        $ordered = array_values($items);
        usort($ordered, function($a, $b) use ($sx, $sy, $vx, $vy, $len2, $reverse) {
            $alng = is_numeric($a['lng'] ?? null) ? (float)$a['lng'] : $sx;
            $alat = is_numeric($a['lat'] ?? null) ? (float)$a['lat'] : $sy;
            $blng = is_numeric($b['lng'] ?? null) ? (float)$b['lng'] : $sx;
            $blat = is_numeric($b['lat'] ?? null) ? (float)$b['lat'] : $sy;
            $ta = (($alng - $sx) * $vx + ($alat - $sy) * $vy) / $len2;
            $tb = (($blng - $sx) * $vx + ($blat - $sy) * $vy) / $len2;
            if (abs($ta - $tb) > 0.0001) return $reverse ? ($tb <=> $ta) : ($ta <=> $tb);
            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        return $ordered;
    }

    private function nearestNeighbor(array $items, array $start): array {
        if (!$items) return [];
        $pool = array_values($items);
        $route = [];
        $current = $start;
        if (!is_numeric($current['lat'] ?? null) || !is_numeric($current['lng'] ?? null)) {
            $route[] = array_shift($pool);
            $current = $route[0];
        }
        while (!empty($pool)) {
            $closestIndex = 0;
            $closestDist = PHP_FLOAT_MAX;
            foreach ($pool as $i => $item) {
                $dist = $this->distance($current, $item);
                if ($dist < $closestDist) {
                    $closestDist = $dist;
                    $closestIndex = $i;
                }
            }
            $route[] = $pool[$closestIndex];
            $current = $pool[$closestIndex];
            unset($pool[$closestIndex]);
            $pool = array_values($pool);
        }
        return $route;
    }

    private function twoOpt(array $route, array $start, array $end): array {
        $n = count($route);
        if ($n < 4) return $route;
        $improved = true;
        $guard = 0;
        while ($improved && $guard < 8) {
            $improved = false;
            $guard++;
            for ($i = 0; $i < $n - 2; $i++) {
                for ($j = $i + 1; $j < $n - 1; $j++) {
                    $candidate = $route;
                    $segment = array_reverse(array_slice($candidate, $i, $j - $i + 1));
                    array_splice($candidate, $i, $j - $i + 1, $segment);
                    if ($this->pathDistance($candidate, $start, $end) + 0.0001 < $this->pathDistance($route, $start, $end)) {
                        $route = $candidate;
                        $improved = true;
                    }
                }
            }
        }
        return $route;
    }

    private function pathDistance(array $route, array $start, array $end): float {
        $total = 0.0;
        $prev = $start;
        if (!is_numeric($prev['lat'] ?? null) || !is_numeric($prev['lng'] ?? null)) {
            $prev = $route[0] ?? $start;
        }
        foreach ($route as $item) {
            $total += $this->distance($prev, $item);
            $prev = $item;
        }
        if (is_numeric($end['lat'] ?? null) && is_numeric($end['lng'] ?? null)) {
            $total += $this->distance($prev, $end);
        }
        return $total;
    }

    private function distance(array $a, array $b): float {
        $alat = is_numeric($a['lat'] ?? null) ? (float)$a['lat'] : null;
        $alng = is_numeric($a['lng'] ?? null) ? (float)$a['lng'] : null;
        $blat = is_numeric($b['lat'] ?? null) ? (float)$b['lat'] : null;
        $blng = is_numeric($b['lng'] ?? null) ? (float)$b['lng'] : null;
        if ($alat === null || $alng === null || $blat === null || $blng === null) return 999999.0;
        $earth = 6371.0;
        $dLat = deg2rad($blat - $alat);
        $dLng = deg2rad($blng - $alng);
        $aa = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($alat)) * cos(deg2rad($blat)) * sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($aa), sqrt(1-$aa));
        return $earth * $c;
    }
}
