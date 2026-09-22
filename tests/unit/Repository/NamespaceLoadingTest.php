<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Repository;

use Formvex\Contracts\V1\Foundation\ContractsPackage;
use Formvex\Core\Foundation\CorePackage;
use Formvex\Hub\Kernel as HubKernel;
use Formvex\Spoke\Kernel as SpokeKernel;
use PHPUnit\Framework\TestCase;

final class NamespaceLoadingTest extends TestCase
{
    public function test_it_loads_every_approved_php_namespace(): void
    {
        self::assertTrue(class_exists(SpokeKernel::class));
        self::assertTrue(class_exists(HubKernel::class));
        self::assertSame('formvex/core', CorePackage::NAME);
        self::assertSame('formvex/contracts-v1', ContractsPackage::NAME);
    }
}
