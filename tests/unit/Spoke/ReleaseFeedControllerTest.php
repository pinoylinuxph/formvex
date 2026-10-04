<?php

declare(strict_types=1);

namespace FormvexTests\Unit\Spoke;

use Formvex\Spoke\Http\Release\ReleaseFeedController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

final class ReleaseFeedControllerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-release-feed-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700, true);
        file_put_contents($this->root . '/metadata.json', '{"schema_version":1,"product":"formvex-spoke"}');
        file_put_contents($this->root . '/formvex-spoke-1.0.1.zip', 'package');
        file_put_contents($this->root . '/1.0.1-notes.txt', 'notes');
        file_put_contents($this->root . '/secret.txt', 'private');
    }

    protected function tearDown(): void
    {
        foreach (['metadata.json', 'formvex-spoke-1.0.1.zip', '1.0.1-notes.txt', 'secret.txt'] as $file) {
            @unlink($this->root . '/' . $file);
        }
        @rmdir($this->root);
    }

    public function testMetadataIsReturnedAsBoundedJson(): void
    {
        $response = new ReleaseFeedController($this->root . '/metadata.json', $this->root)->metadata();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('{"schema_version":1,"product":"formvex-spoke"}', $response->getContent());
    }

    public function testOnlyReleaseArtifactsAndNotesCanBeDownloaded(): void
    {
        $controller = new ReleaseFeedController($this->root . '/metadata.json', $this->root);

        self::assertInstanceOf(BinaryFileResponse::class, $controller->artifact('formvex-spoke-1.0.1.zip'));
        self::assertInstanceOf(BinaryFileResponse::class, $controller->artifact('1.0.1-notes.txt'));
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->artifact('secret.txt')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->artifact('../secret.txt')->getStatusCode());
    }

    public function testMissingFeedAndDirectoryFailClosed(): void
    {
        self::assertSame(Response::HTTP_NOT_FOUND, new ReleaseFeedController($this->root . '/missing.json', $this->root)->metadata()->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, new ReleaseFeedController($this->root . '/metadata.json', $this->root . '/missing')->artifact('formvex-spoke-1.0.1.zip')->getStatusCode());
    }
}
