<?php

declare(strict_types=1);

namespace FormvexTestsUnitPlatformUi;

use Formvex\Spoke\Admin\Portal\PaginationView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PaginationViewTest extends TestCase
{
    public function testCalculatesDisplayedRangeAndBoundsTheRequestedPage(): void
    {
        $view = PaginationView::fromRequest(
            Request::create('/', 'GET', ['page' => '99', 'page_size' => '50']),
            range(1, 395),
        );

        self::assertSame(395, $view['total']);
        self::assertSame(351, $view['firstItem']);
        self::assertSame(395, $view['lastItem']);
        self::assertSame(8, $view['page']);
        self::assertSame(8, $view['pageCount']);
        self::assertSame('page', $view['pageParameter']);
        self::assertSame('page_size', $view['pageSizeParameter']);
        self::assertCount(45, $view['items']);
    }

    public function testEmptyResultsHaveNoDisplayedRangeOrNavigation(): void
    {
        $view = PaginationView::fromRequest(Request::create('/'), []);

        self::assertSame(0, $view['total']);
        self::assertSame(0, $view['firstItem']);
        self::assertSame(0, $view['lastItem']);
        self::assertSame([], $view['pages']);
        self::assertNull($view['previous']);
        self::assertNull($view['next']);
    }
}
