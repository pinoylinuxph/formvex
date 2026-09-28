<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormConfiguration;

use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;

final readonly class PageIdentity
{
    public function __construct(
        public string $host,
        public string $path,
        public string $formMarker,
    ) {
        if ($this->host === '' || $this->path === '' || $this->formMarker === '') {
            throw new FormConfigurationFailure('page_identity_invalid', 'Enter a website host, page path, and form marker.');
        }
    }

    public static function fromInput(string $host, string $path, string $formMarker): self
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');
        $path = trim($path);
        $formMarker = trim($formMarker);

        if ($host === '' || strlen($host) > 253 || preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $host) !== 1 || str_contains($host, '..')) {
            throw new FormConfigurationFailure('page_host_invalid', 'Enter a host such as example.com without https://, a port, a path, or a wildcard.', ['page_host' => 'Use a bare host such as example.com.']);
        }

        if ($path === '') {
            $path = '/';
        }

        $queryPosition = strpos($path, '?');
        $fragmentPosition = strpos($path, '#');
        $cutPosition = null;

        foreach ([$queryPosition, $fragmentPosition] as $position) {
            if ($position !== false && ($cutPosition === null || $position < $cutPosition)) {
                $cutPosition = $position;
            }
        }

        if ($cutPosition !== null) {
            $path = substr($path, 0, $cutPosition);
        }

        if ($path === '') {
            $path = '/';
        }

        if (!str_starts_with($path, '/') || strlen($path) > 2048 || str_contains($path, "\0") || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new FormConfigurationFailure('page_path_invalid', 'Enter a page path beginning with /, such as /contact. Query strings and fragments are ignored.', ['page_path' => 'Use a path beginning with /.']);
        }

        $segments = explode('/', trim($path, '/'));

        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            throw new FormConfigurationFailure('page_path_invalid', 'The page path cannot contain . or .. path segments.', ['page_path' => 'Remove relative path segments.']);
        }

        $path = '/' . trim($path, '/');
        $path = $path === '/' ? '/' : $path;

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,119}$/', $formMarker) !== 1) {
            throw new FormConfigurationFailure('form_marker_invalid', 'Use the existing HTML form id or a Formvex marker containing letters, numbers, dots, colons, underscores, or hyphens.', ['form_marker' => 'Enter a stable form marker.']);
        }

        return new self($host, $path, $formMarker);
    }
}
