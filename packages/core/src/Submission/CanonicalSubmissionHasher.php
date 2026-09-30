<?php

declare(strict_types=1);

namespace Formvex\Core\Submission;

use JsonException;
use RuntimeException;

final class CanonicalSubmissionHasher
{
    /** @param array<string, mixed> $payload */
    public function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode($this->canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new RuntimeException('The submission payload could not be canonicalized.');
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $canonical = [];

        foreach ($keys as $key) {
            $canonical[$key] = $this->canonicalize($value[$key]);
        }

        return $canonical;
    }
}
