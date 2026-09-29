<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormDiscovery;

use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\FormDiscovery\Exception\FormDiscoveryFailure;

final readonly class DiscoveryPayload
{
    public const SCHEMA_VERSION = 1;

    /** @param list<DiscoveryForm> $forms */
    public function __construct(
        public PageIdentity $page,
        public array $forms,
    ) {
        if (count($this->forms) > 25) {
            throw new FormDiscoveryFailure('form_count_exceeded', 'The page contains more than 25 detected forms. Review the page and discover a smaller page.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $host, int $payloadBytes): self
    {
        if ($payloadBytes < 1) {
            throw new FormDiscoveryFailure('discovery_payload_empty', 'The discovery script sent no form metadata.');
        }

        $allowed = ['schema_version', 'page_path', 'forms'];

        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new FormDiscoveryFailure('discovery_unknown_property', 'The discovery metadata contains an unsupported property. Update the installed script and try again.');
            }
        }

        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new FormDiscoveryFailure('discovery_schema_unsupported', 'The installed Formvex discovery script is not compatible with this Spoke. Update the website script and try again.');
        }

        if (!is_string($data['page_path'] ?? null)) {
            throw new FormDiscoveryFailure('discovery_page_invalid', 'The discovery script did not provide a valid page path.');
        }

        $forms = $data['forms'] ?? [];

        if (!is_array($forms) || !array_is_list($forms)) {
            throw new FormDiscoveryFailure('forms_invalid', 'The discovery script did not provide a valid form list.');
        }

        $normalizedForms = [];

        foreach ($forms as $form) {
            if (!is_array($form) || array_is_list($form)) {
                throw new FormDiscoveryFailure('forms_invalid', 'Each discovered form must be a JSON object.');
            }

            $normalizedForms[] = DiscoveryForm::fromArray(self::object($form));
        }

        return new self(PageIdentity::fromInput($host, $data['page_path'], 'discovery'), $normalizedForms);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'page_path' => $this->page->path,
            'forms' => array_map(static fn (DiscoveryForm $form): array => $form->toArray(), $this->forms),
        ];
    }

    /**
     * @param array<mixed, mixed> $value
     * @return array<string, mixed>
     */
    private static function object(array $value): array
    {
        $object = [];

        foreach ($value as $key => $property) {
            if (!is_string($key)) {
                throw new FormDiscoveryFailure('forms_invalid', 'Discovered form properties must use string names.');
            }

            $object[$key] = $property;
        }

        return $object;
    }
}
