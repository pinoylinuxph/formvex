<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormDiscovery;

use Formvex\Spoke\Domain\FormDiscovery\Exception\FormDiscoveryFailure;

final readonly class DiscoveryForm
{
    /**
     * @param list<DiscoveryControl> $controls
     * @param list<array{control_name: string, control_type: string}> $unsupportedControls
     */
    public function __construct(
        public string $formMarker,
        public string $displayName,
        public bool $markerGenerated,
        public bool $ambiguous,
        public array $controls,
        public array $unsupportedControls = [],
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,119}$/', $this->formMarker) !== 1) {
            throw new FormDiscoveryFailure('form_marker_invalid', 'A discovered form has an invalid marker.');
        }

        if ($this->displayName === '' || mb_strlen($this->displayName, 'UTF-8') > 256) {
            throw new FormDiscoveryFailure('form_name_invalid', 'A discovered form name is empty or longer than 256 characters.');
        }

        if (count($this->controls) > 100) {
            throw new FormDiscoveryFailure('control_count_exceeded', 'A discovered form contains more than 100 supported controls.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $allowed = ['form_marker', 'display_name', 'marker_generated', 'ambiguous', 'controls', 'unsupported_controls'];

        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new FormDiscoveryFailure('discovery_unknown_property', 'The discovered form contains an unsupported property.');
            }
        }

        $controls = $data['controls'] ?? [];

        if (!is_array($controls) || !array_is_list($controls)) {
            throw new FormDiscoveryFailure('controls_invalid', 'Discovered controls must be a JSON list.');
        }

        $normalizedControls = [];

        foreach ($controls as $control) {
            if (!is_array($control) || array_is_list($control)) {
                throw new FormDiscoveryFailure('controls_invalid', 'Each discovered control must be a JSON object.');
            }

            $normalizedControls[] = DiscoveryControl::fromArray(self::object($control));
        }

        $unsupported = $data['unsupported_controls'] ?? [];

        if (!is_array($unsupported) || !array_is_list($unsupported)) {
            throw new FormDiscoveryFailure('unsupported_controls_invalid', 'Unsupported controls must be a JSON list.');
        }

        $normalizedUnsupported = [];

        foreach ($unsupported as $control) {
            if (!is_array($control) || array_is_list($control)) {
                throw new FormDiscoveryFailure('unsupported_controls_invalid', 'Each unsupported control must identify its name and type.');
            }

            $controlObject = self::object($control);

            if (!is_string($controlObject['control_name'] ?? null) || !is_string($controlObject['control_type'] ?? null)) {
                throw new FormDiscoveryFailure('unsupported_controls_invalid', 'Each unsupported control must identify its name and type.');
            }

            $normalizedUnsupported[] = ['control_name' => $controlObject['control_name'], 'control_type' => $controlObject['control_type']];
        }

        if (!is_string($data['form_marker'] ?? null) || !is_string($data['display_name'] ?? null) || !is_bool($data['marker_generated'] ?? null) || !is_bool($data['ambiguous'] ?? null)) {
            throw new FormDiscoveryFailure('form_invalid', 'A discovered form contains an invalid property type.');
        }

        return new self($data['form_marker'], $data['display_name'], $data['marker_generated'], $data['ambiguous'], $normalizedControls, $normalizedUnsupported);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'form_marker' => $this->formMarker,
            'display_name' => $this->displayName,
            'marker_generated' => $this->markerGenerated,
            'ambiguous' => $this->ambiguous,
            'controls' => array_map(static fn (DiscoveryControl $control): array => $control->toArray(), $this->controls),
            'unsupported_controls' => $this->unsupportedControls,
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
                throw new FormDiscoveryFailure('form_invalid', 'Discovered form properties must use string names.');
            }

            $object[$key] = $property;
        }

        return $object;
    }
}
