<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormDiscovery;

use Formvex\Spoke\Domain\FormDiscovery\Exception\FormDiscoveryFailure;

final readonly class DiscoveryControl
{
    /**
     * @param list<array{value: string, label: string}> $choices
     * @param list<string> $suggestedParameters
     */
    public function __construct(
        public string $discoveryKey,
        public string $controlName,
        public string $controlType,
        public string $displayLabel,
        public bool $labelResolved,
        public bool $required,
        public int $maxLength,
        public array $choices = [],
        public ?string $choiceGroupKey = null,
        public array $suggestedParameters = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]{0,119}$/', $this->discoveryKey) !== 1) {
            throw new FormDiscoveryFailure('discovery_key_invalid', 'A discovered control has an invalid stable key. Run discovery again after checking the page markup.');
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:\-\[\]]{0,119}$/', $this->controlName) !== 1) {
            throw new FormDiscoveryFailure('control_name_invalid', 'A discovered control has no usable HTML name. Add a stable name to the control and run discovery again.');
        }

        if (!in_array($this->controlType, ['text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkbox'], true)) {
            throw new FormDiscoveryFailure('control_type_invalid', 'The page contains a control type that Formvex does not support.');
        }

        if ($this->displayLabel === '' || mb_strlen($this->displayLabel, 'UTF-8') > 256) {
            throw new FormDiscoveryFailure('control_label_invalid', 'A discovered control label is empty or longer than 256 characters.');
        }

        if ($this->maxLength < 1 || $this->maxLength > 10000) {
            throw new FormDiscoveryFailure('control_length_invalid', 'A discovered control has an invalid maximum length.');
        }

        if (count($this->choices) > 100) {
            throw new FormDiscoveryFailure('choice_count_exceeded', 'A discovered choice group contains more than 100 choices.');
        }

        foreach ($this->choices as $choice) {
            if (mb_strlen($choice['value'], 'UTF-8') > 256 || mb_strlen($choice['label'], 'UTF-8') > 256 || $choice['value'] === '' || $choice['label'] === '') {
                throw new FormDiscoveryFailure('choice_invalid', 'A discovered choice must have a value and label of no more than 256 characters.');
            }
        }

        foreach ($this->suggestedParameters as $parameter) {
            if (preg_match('/^[a-z][a-z0-9_]{0,79}$/', $parameter) !== 1) {
                throw new FormDiscoveryFailure('suggestion_invalid', 'A discovered mapping suggestion is invalid.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        self::assertKeys($data, ['discovery_key', 'control_name', 'control_type', 'display_label', 'label_resolved', 'required', 'max_length', 'choices', 'choice_group_key', 'suggested_parameters']);

        $choices = $data['choices'] ?? [];

        if (!is_array($choices) || !array_is_list($choices)) {
            throw new FormDiscoveryFailure('choices_invalid', 'Discovered choices must be a JSON list.');
        }

        $normalizedChoices = [];

        foreach ($choices as $choice) {
            if (!is_array($choice) || array_is_list($choice) || !is_string($choice['value'] ?? null) || !is_string($choice['label'] ?? null)) {
                throw new FormDiscoveryFailure('choices_invalid', 'Each discovered choice must contain a string value and label.');
            }

            $choiceObject = self::object($choice);

            if (!is_string($choiceObject['value'] ?? null) || !is_string($choiceObject['label'] ?? null)) {
                throw new FormDiscoveryFailure('choices_invalid', 'Each discovered choice must contain a string value and label.');
            }

            $normalizedChoices[] = ['value' => $choiceObject['value'], 'label' => $choiceObject['label']];
        }

        $suggested = $data['suggested_parameters'] ?? [];

        if (!is_array($suggested) || !array_is_list($suggested) || array_filter($suggested, static fn (mixed $value): bool => !is_string($value)) !== []) {
            throw new FormDiscoveryFailure('suggestions_invalid', 'Discovered mapping suggestions must be string keys.');
        }

        $suggestedParameters = [];

        foreach ($suggested as $parameter) {
            if (!is_string($parameter)) {
                throw new FormDiscoveryFailure('suggestions_invalid', 'Discovered mapping suggestions must be string keys.');
            }

            $suggestedParameters[] = $parameter;
        }

        $maxLength = $data['max_length'] ?? 255;
        $labelResolved = $data['label_resolved'] ?? false;
        $required = $data['required'] ?? false;

        if (!is_string($data['discovery_key'] ?? null) || !is_string($data['control_name'] ?? null) || !is_string($data['control_type'] ?? null) || !is_string($data['display_label'] ?? null) || !is_bool($labelResolved) || !is_bool($required) || !is_int($maxLength)) {
            throw new FormDiscoveryFailure('control_invalid', 'A discovered control contains an invalid property type.');
        }

        $choiceGroupKey = $data['choice_group_key'] ?? null;

        if ($choiceGroupKey !== null && !is_string($choiceGroupKey)) {
            throw new FormDiscoveryFailure('choice_group_invalid', 'A discovered choice group key is invalid.');
        }

        return new self($data['discovery_key'], $data['control_name'], $data['control_type'], $data['display_label'], $labelResolved, $required, $maxLength, $normalizedChoices, $choiceGroupKey, $suggestedParameters);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'discovery_key' => $this->discoveryKey,
            'control_name' => $this->controlName,
            'control_type' => $this->controlType,
            'display_label' => $this->displayLabel,
            'label_resolved' => $this->labelResolved,
            'required' => $this->required,
            'max_length' => $this->maxLength,
            'choices' => $this->choices,
            'choice_group_key' => $this->choiceGroupKey,
            'suggested_parameters' => $this->suggestedParameters,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    private static function assertKeys(array $data, array $allowed): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new FormDiscoveryFailure('discovery_unknown_property', 'The discovery metadata contains an unsupported property. Update the installed script and try again.');
            }
        }
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
                throw new FormDiscoveryFailure('choices_invalid', 'Each discovered choice must use named properties.');
            }

            $object[$key] = $property;
        }

        return $object;
    }
}
