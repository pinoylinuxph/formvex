<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\SubmissionReview;

final readonly class SubmissionReviewQuery
{
    public const DEFAULT_PAGE_SIZE = 25;

    public const MAX_PAGE_SIZE = 100;

    /** @param list<string> $errors */
    private function __construct(
        public ?string $formPublicId,
        public string $classification,
        public string $lifecycle,
        public string $delivery,
        public string $recordType,
        public string $sort,
        public int $page,
        public int $pageSize,
        public array $errors,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        /** @var list<string> $errors */
        $errors = [];
        $formPublicId = self::stringValue($input, 'form');

        if ($formPublicId !== null && $formPublicId !== '' && !preg_match('/\A[0-9a-fA-F-]{16,80}\z/', $formPublicId)) {
            $errors[] = 'The selected form filter is invalid.';
            $formPublicId = null;
        }

        $classification = self::allowed($input, 'classification', ['all', 'normal', 'suspected_spam'], 'classification', $errors);
        $lifecycle = self::allowed($input, 'lifecycle', ['active', 'handled', 'trash', 'all'], 'lifecycle', $errors);
        $delivery = self::allowed($input, 'delivery', ['all', 'queued', 'processing', 'sent', 'failed', 'uncertain'], 'delivery', $errors);
        $recordType = self::allowed($input, 'record_type', ['visitor', 'qualification', 'all'], 'record type', $errors);
        $sort = self::allowed($input, 'sort', ['newest', 'oldest'], 'sort order', $errors);
        $page = self::positiveInteger($input, 'page', 1, 'page', $errors, 10000);
        $pageSize = self::positiveInteger($input, 'page_size', self::DEFAULT_PAGE_SIZE, 'page size', $errors, self::MAX_PAGE_SIZE);

        if (!in_array($pageSize, [25, 50, 100], true)) {
            $errors[] = 'The page size must be 25, 50, or 100.';
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }

        return new self(
            $formPublicId === '' ? null : $formPublicId,
            $classification,
            $lifecycle,
            $delivery,
            $recordType,
            $sort,
            $page,
            $pageSize,
            self::uniqueErrors($errors),
        );
    }

    /** @return array<string, string> */
    public function toQuery(?int $page = null): array
    {
        $query = [];

        if ($this->formPublicId !== null) {
            $query['form'] = $this->formPublicId;
        }
        if ($this->classification !== 'all') {
            $query['classification'] = $this->classification;
        }
        if ($this->lifecycle !== 'active') {
            $query['lifecycle'] = $this->lifecycle;
        }
        if ($this->delivery !== 'all') {
            $query['delivery'] = $this->delivery;
        }
        if ($this->recordType !== 'visitor') {
            $query['record_type'] = $this->recordType;
        }
        if ($this->sort !== 'newest') {
            $query['sort'] = $this->sort;
        }
        if (($page ?? $this->page) !== 1) {
            $query['page'] = (string) ($page ?? $this->page);
        }
        if ($this->pageSize !== self::DEFAULT_PAGE_SIZE) {
            $query['page_size'] = (string) $this->pageSize;
        }

        return $query;
    }

    /** @param array<string, mixed> $input */
    private static function stringValue(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return is_string($value) ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $allowed
     * @param list<string> $errors
     */
    private static function allowed(array $input, string $key, array $allowed, string $label, array &$errors): string
    {
        $value = self::stringValue($input, $key);

        if ($value === null || $value === '') {
            return $allowed[0];
        }
        if (in_array($value, $allowed, true)) {
            return $value;
        }

        $errors[] = 'The ' . $label . ' filter is invalid.';

        return $allowed[0];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $errors
     */
    private static function positiveInteger(array $input, string $key, int $default, string $label, array &$errors, int $max): int
    {
        $value = self::stringValue($input, $key);

        if ($value === null || $value === '') {
            return $default;
        }
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > $max) {
            $errors[] = 'The ' . $label . ' is outside the supported range.';

            return $default;
        }

        return (int) $value;
    }

    /**
     * @param list<string> $errors
     * @return list<string>
     */
    private static function uniqueErrors(array $errors): array
    {
        $unique = [];
        foreach ($errors as $error) {
            if (!in_array($error, $unique, true)) {
                $unique[] = $error;
            }
        }

        return $unique;
    }
}
