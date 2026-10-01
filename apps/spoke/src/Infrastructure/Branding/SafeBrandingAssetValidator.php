<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Branding;

use DOMDocument;
use DOMElement;
use Formvex\Spoke\Domain\Branding\Contract\BrandingAssetValidator;
use Formvex\Spoke\Domain\Branding\StagedBrandingAsset;
use Formvex\Spoke\Domain\Branding\ValidatedBrandingAsset;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;

final class SafeBrandingAssetValidator implements BrandingAssetValidator
{
    private const LOGO_MAX_BYTES = 2097152;

    private const LOGO_MAX_WIDTH = 2000;

    private const LOGO_MAX_HEIGHT = 1000;

    private const FAVICON_MAX_BYTES = 262144;

    private const FAVICON_MAX_WIDTH = 256;

    private const FAVICON_MAX_HEIGHT = 256;

    /** @var array<string, array<string, string>> */
    private const FORMATS = [
        'logo' => [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
        ],
        'favicon' => [
            'png' => 'image/png',
            'ico' => 'image/x-icon',
        ],
    ];

    public function validate(StagedBrandingAsset $asset): ValidatedBrandingAsset
    {
        $formats = self::FORMATS[$asset->type] ?? null;
        $extension = strtolower(pathinfo($asset->originalName, PATHINFO_EXTENSION));

        if ($formats === null || !isset($formats[$extension])) {
            throw $this->failure($asset->type, 'Use a PNG, JPEG, or static SVG logo; favicons must be PNG or ICO files.', 'branding_' . $asset->type . '_format_invalid');
        }

        if (!is_file($asset->path) || is_link($asset->path)) {
            throw $this->failure($asset->type, 'The uploaded file is not available for validation. Choose the file again.', 'branding_' . $asset->type . '_unavailable');
        }

        $bytes = filesize($asset->path);
        $maximum = $asset->type === 'logo' ? self::LOGO_MAX_BYTES : self::FAVICON_MAX_BYTES;

        if ($bytes === false || $bytes < 1 || $bytes > $maximum) {
            throw $this->failure($asset->type, 'The ' . $asset->type . ' exceeds the allowed ' . ($asset->type === 'logo' ? '2 MiB' : '256 KiB') . ' file size.', 'branding_' . $asset->type . '_size_invalid');
        }

        $contents = file_get_contents($asset->path);

        if ($contents === false) {
            throw $this->failure($asset->type, 'The ' . $asset->type . ' could not be read for validation. Check the file and try again.', 'branding_' . $asset->type . '_read_failed');
        }

        if ($extension === 'png') {
            $result = $this->validateRaster($asset->type, $contents, IMAGETYPE_PNG, $formats[$extension]);
        } elseif ($extension === 'jpg' || $extension === 'jpeg') {
            $result = $this->validateRaster($asset->type, $contents, IMAGETYPE_JPEG, $formats[$extension]);
        } elseif ($extension === 'ico') {
            $result = $this->validateIco($asset->type, $contents, $formats[$extension]);
        } elseif ($extension === 'svg') {
            $result = $this->validateSvg($asset->type, $contents, $formats[$extension]);
        } else {
            throw $this->failure($asset->type, 'The uploaded file format is not supported.', 'branding_' . $asset->type . '_format_invalid');
        }

        return new ValidatedBrandingAsset(
            $asset->type,
            $extension === 'jpg' ? 'jpg' : $extension,
            $result['mediaType'],
            hash_file('sha256', $asset->path) ?: throw $this->failure($asset->type, 'The file could not be fingerprinted safely. Choose the file again.', 'branding_' . $asset->type . '_hash_failed'),
            $bytes,
            $result['width'],
            $result['height'],
        );
    }

    /** @return array{mediaType: string, width: ?int, height: ?int} */
    private function validateRaster(string $type, string $contents, int $expectedType, string $mediaType): array
    {
        $info = @getimagesizefromstring($contents);

        if (!is_array($info) || $info[2] !== $expectedType) {
            throw $this->failure($type, 'The uploaded file is not a valid ' . strtoupper($mediaType === 'image/png' ? 'PNG' : 'JPEG') . ' image.', 'branding_' . $type . '_content_invalid');
        }

        $width = $info[0];
        $height = $info[1];
        $maxWidth = $type === 'logo' ? self::LOGO_MAX_WIDTH : self::FAVICON_MAX_WIDTH;
        $maxHeight = $type === 'logo' ? self::LOGO_MAX_HEIGHT : self::FAVICON_MAX_HEIGHT;

        if ($width < 1 || $height < 1 || $width > $maxWidth || $height > $maxHeight) {
            throw $this->failure($type, 'The ' . $type . ' dimensions must not exceed ' . $maxWidth . ' by ' . $maxHeight . ' pixels.', 'branding_' . $type . '_dimensions_invalid');
        }

        return ['mediaType' => $mediaType, 'width' => $width, 'height' => $height];
    }

    /** @return array{mediaType: string, width: ?int, height: ?int} */
    private function validateIco(string $type, string $contents, string $mediaType): array
    {
        if (strlen($contents) < 22 || substr($contents, 0, 4) !== "\x00\x00\x01\x00") {
            throw $this->failure($type, 'The favicon is not a valid ICO file.', 'branding_favicon_content_invalid');
        }

        $count = unpack('v', substr($contents, 4, 2))[1] ?? 0;
        $width = ord($contents[6] ?? "\x00") ?: 256;
        $height = ord($contents[7] ?? "\x00") ?: 256;

        if ($count < 1) {
            throw $this->failure($type, 'The favicon dimensions must not exceed 256 by 256 pixels.', 'branding_favicon_dimensions_invalid');
        }

        return ['mediaType' => $mediaType, 'width' => $width, 'height' => $height];
    }

    /** @return array{mediaType: string, width: ?int, height: ?int} */
    private function validateSvg(string $type, string $contents, string $mediaType): array
    {
        if ($type !== 'logo' || preg_match('/\x00|<!DOCTYPE|<!ENTITY|<script\b|\bon[a-z]+\s*=|foreignObject|(?:xlink:)?href\s*=|javascript:|url\s*\(/iu', $contents) === 1) {
            throw $this->failure($type, 'The SVG contains unsupported or executable content. Upload a static SVG containing only drawing elements.', 'branding_logo_svg_invalid');
        }

        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;

        if (@$document->loadXML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) !== true || !$document->documentElement instanceof DOMElement || strtolower($document->documentElement->localName ?: $document->documentElement->tagName) !== 'svg') {
            throw $this->failure($type, 'The SVG could not be parsed as a safe image.', 'branding_logo_svg_invalid');
        }

        $allowedElements = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'title', 'desc', 'clipPath'];
        $allowedAttributes = ['xmlns', 'viewBox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'fill-rule', 'clip-rule', 'd', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'points', 'transform', 'preserveAspectRatio', 'aria-label', 'role', 'id', 'clip-path'];

        foreach ($document->getElementsByTagName('*') as $element) {
            $name = strtolower($element->localName ?: $element->tagName);

            if (!in_array($name, $allowedElements, true)) {
                throw $this->failure($type, 'The SVG contains an unsupported element. Remove filters, embedded content, and external references.', 'branding_logo_svg_invalid');
            }

            foreach ($element->attributes as $attribute) {
                if (!in_array($attribute->name, $allowedAttributes, true)) {
                    throw $this->failure($type, 'The SVG contains an unsupported attribute or external reference.', 'branding_logo_svg_invalid');
                }
            }
        }

        $root = $document->documentElement;
        [$width, $height] = $this->svgDimensions($root);

        if ($width < 1 || $height < 1 || $width > self::LOGO_MAX_WIDTH || $height > self::LOGO_MAX_HEIGHT) {
            throw $this->failure($type, 'The logo dimensions must not exceed 2,000 by 1,000 pixels.', 'branding_logo_dimensions_invalid');
        }

        return ['mediaType' => $mediaType, 'width' => $width, 'height' => $height];
    }

    /** @return array{0: int, 1: int} */
    private function svgDimensions(DOMElement $root): array
    {
        $viewBox = trim($root->getAttribute('viewBox'));

        if (preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s+[0-9]+(?:\.[0-9]+)?\s+([0-9]+(?:\.[0-9]+)?)\s+([0-9]+(?:\.[0-9]+)?)\s*$/', $viewBox, $matches) === 1) {
            return [(int) ceil((float) $matches[2]), (int) ceil((float) $matches[3])];
        }

        return [$this->svgLength($root->getAttribute('width')), $this->svgLength($root->getAttribute('height'))];
    }

    private function svgLength(string $value): int
    {
        return preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)(?:px)?\s*$/', $value, $matches) === 1
            ? (int) ceil((float) $matches[1])
            : 0;
    }

    private function failure(string $type, string $message, string $code): InstallationSettingsFailure
    {
        return new InstallationSettingsFailure($code, $message, ['branding_' . $type => $message]);
    }
}
