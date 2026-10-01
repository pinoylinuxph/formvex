<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

final readonly class DeliveryReviewQuery
{
    public const DEFAULT_PAGE_SIZE = 25;

    public const MAX_PAGE_SIZE = 100;

    /** @param list<string> $errors */
    private function __construct(
        public string $state,
        public string $outcome,
        public ?string $formPublicId,
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
        $form = self::stringValue($input, 'form');
        if ($form !== null && $form !== '' && !preg_match('/\A[0-9a-fA-F-]{16,80}\z/', $form)) {
            $errors[] = 'The selected form filter is invalid.';
            $form = null;
        }

        $state = self::allowed($input, 'state', ['all', 'queued', 'processing', 'sent', 'failed', 'uncertain'], 'delivery state', $errors);
        $outcome = self::allowed($input, 'outcome', ['all', 'accepted', 'temporary_failure', 'permanent_failure', 'uncertain'], 'delivery outcome', $errors);
        $sort = self::allowed($input, 'sort', ['newest', 'oldest'], 'sort order', $errors);
        $page = self::integer($input, 'page', 1, 'page', $errors, 10000);
        $pageSize = self::integer($input, 'page_size', self::DEFAULT_PAGE_SIZE, 'page size', $errors, self::MAX_PAGE_SIZE);
        if (!in_array($pageSize, [25, 50, 100], true)) {
            $errors[] = 'The page size must be 25, 50, or 100.';
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }

        return new self($state, $outcome, $form === '' ? null : $form, $sort, $page, $pageSize, self::unique($errors));
    }

    /** @return array<string, string> */
    public function toQuery(?int $page = null): array
    {
        $query = [];
        if ($this->state !== 'all') {
            $query['state'] = $this->state;
        }
        if ($this->outcome !== 'all') {
            $query['outcome'] = $this->outcome;
        }
        if ($this->formPublicId !== null) {
            $query['form'] = $this->formPublicId;
        }
        if ($this->sort !== 'newest') {
            $query['sort'] = $this->sort;
        }
        $currentPage = $page ?? $this->page;
        if ($currentPage !== 1) {
            $query['page'] = (string) $currentPage;
        }
        if ($this->pageSize !== self::DEFAULT_PAGE_SIZE) {
            $query['page_size'] = (string) $this->pageSize;
        }

        return $query;
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
    private static function integer(array $input, string $key, int $default, string $label, array &$errors, int $max): int
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

    /** @param array<string, mixed> $input */
    private static function stringValue(array $input, string $key): ?string
    {
        return is_string($input[$key] ?? null) ? trim($input[$key]) : null;
    }

    /**
     * @param list<string> $errors
     * @return list<string>
     */
    private static function unique(array $errors): array
    {
        return array_values(array_unique($errors));
    }
}
