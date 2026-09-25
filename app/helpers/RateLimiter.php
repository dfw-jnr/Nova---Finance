<?php
declare(strict_types=1);

namespace Nova\Helpers;

final class RateLimiter
{
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $dir = dirname(__DIR__, 2) . '/storage/rate';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $file = $dir . '/' . hash('sha256', $key) . '.json';
        $now = time();
        $data = ['hits' => [], 'window' => $windowSeconds];

        if (is_file($file)) {
            $raw = file_get_contents($file);
            $decoded = json_decode($raw ?: '{}', true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $hits = array_values(array_filter(
            $data['hits'] ?? [],
            static fn ($t) => is_int($t) && ($now - $t) < $windowSeconds
        ));
        $hits[] = $now;
        $data['hits'] = $hits;
        file_put_contents($file, json_encode($data), LOCK_EX);

        return count($hits) <= $max;
    }
}
