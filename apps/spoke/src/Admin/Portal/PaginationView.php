<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Portal;

use Symfony\Component\HttpFoundation\Request;

final class PaginationView
{
    /**
     * @template T
     * @param list<T> $items
     * @return array{items: list<T>, page: int, pageCount: int, pageSize: int, pages: list<array{page: int, href: string, current: bool}>, previous: array{href: string, disabled: bool}|null, next: array{href: string, disabled: bool}|null}
     */
    public static function fromRequest(Request $request, array $items, string $pageParameter = 'page', string $pageSizeParameter = 'page_size'): array
    {
        $rawPage = $request->query->get($pageParameter);
        $page = is_string($rawPage) && ctype_digit($rawPage) ? max(1, (int) $rawPage) : 1;
        $rawPageSize = $request->query->get($pageSizeParameter);
        $pageSize = is_string($rawPageSize) && in_array((int) $rawPageSize, [25, 50, 100], true) ? (int) $rawPageSize : 25;
        $pageCount = max(1, (int) ceil(count($items) / $pageSize));
        $page = min($page, $pageCount);
        $pages = [];

        if (count($items) > 0) {
            $start = max(1, $page - 2);
            $end = min($pageCount, $page + 2);
            for ($number = $start; $number <= $end; $number++) {
                $pages[] = [
                    'page' => $number,
                    'href' => self::href($pageParameter, $pageSizeParameter, $number, $pageSize),
                    'current' => $number === $page,
                ];
            }
        }

        return [
            'items' => array_slice($items, ($page - 1) * $pageSize, $pageSize),
            'page' => $page,
            'pageCount' => $pageCount,
            'pageSize' => $pageSize,
            'pages' => $pages,
            'previous' => count($items) > 0 ? ['href' => self::href($pageParameter, $pageSizeParameter, max(1, $page - 1), $pageSize), 'disabled' => $page <= 1] : null,
            'next' => count($items) > 0 ? ['href' => self::href($pageParameter, $pageSizeParameter, min($pageCount, $page + 1), $pageSize), 'disabled' => $page >= $pageCount] : null,
        ];
    }

    private static function href(string $pageParameter, string $pageSizeParameter, int $page, int $pageSize): string
    {
        return '?' . http_build_query([$pageParameter => $page, $pageSizeParameter => $pageSize], '', '&', PHP_QUERY_RFC3986);
    }
}
