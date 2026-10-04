<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http\Release;

use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReleaseFeedController extends AbstractController
{
    public function __construct(
        private readonly ?string $metadataFile = null,
        private readonly ?string $releaseDirectory = null,
    ) {
    }

    #[Route('/formvex/metadata.json', name: 'spoke_release_metadata', methods: ['GET'])]
    public function metadata(): Response
    {
        if ($this->metadataFile === null || $this->metadataFile === '' || is_link($this->metadataFile) || !is_file($this->metadataFile)) {
            return $this->notFound();
        }

        $contents = file_get_contents($this->metadataFile);
        if ($contents === false || strlen($contents) > 65536) {
            return $this->notFound();
        }

        try {
            $payload = json_decode($contents, true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return $this->notFound();
        }
        if (!is_array($payload) || array_is_list($payload)) {
            return $this->notFound();
        }

        return new Response($contents, Response::HTTP_OK, [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route('/formvex/releases/{artifact}', name: 'spoke_release_artifact', requirements: ['artifact' => '[A-Za-z0-9][A-Za-z0-9._-]{0,127}'], methods: ['GET'])]
    public function artifact(string $artifact): Response
    {
        if (!preg_match('/\A(?:formvex-spoke-\d+\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?\.zip|\d+\.\d+\.\d+-notes\.txt)\z/', $artifact)) {
            return $this->notFound();
        }
        if ($this->releaseDirectory === null || $this->releaseDirectory === '' || is_link($this->releaseDirectory) || !is_dir($this->releaseDirectory)) {
            return $this->notFound();
        }

        $directory = realpath($this->releaseDirectory);
        if ($directory === false || is_link($directory)) {
            return $this->notFound();
        }
        $candidate = $directory . DIRECTORY_SEPARATOR . $artifact;
        if (is_link($candidate) || !is_file($candidate)) {
            return $this->notFound();
        }
        $path = realpath($candidate);
        if ($path === false || !str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return $this->notFound();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Cache-Control', 'public, max-age=300');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . basename($path) . '"');

        return $response;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => 'release_resource_unavailable'], Response::HTTP_NOT_FOUND, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
