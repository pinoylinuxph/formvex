<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\FormChangeObservation;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\FormChangeObservation\Contract\FormChangeObservationRepository;
use Formvex\Spoke\Domain\FormChangeObservation\FormChangeObservation;
use Formvex\Spoke\Domain\FormChangeObservation\FormChangeObservationFingerprint;
use Formvex\Spoke\Domain\FormChangeObservation\ObservedFormStructure;
use Formvex\Spoke\Domain\FormConfiguration\Contract\FormConfigurationStore;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use JsonException;

final readonly class FormChangeObservationService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private FormConfigurationStore $formConfigurationStore,
        private FormChangeObservationRepository $repository,
        private IdentifierGenerator $identifierGenerator,
        private Clock $clock,
    ) {
    }

    public function observe(string $applicationRoot, string $formPublicId, ObservedFormStructure $request): void
    {
        $paths = $this->paths($applicationRoot);
        $active = $this->formConfigurationStore->findActive($paths, $formPublicId);

        if ($active === null
            || $active->versionNumber !== $request->configurationVersion
            || $active->page->path !== $request->pagePath
            || $active->page->formMarker !== $request->formMarker) {
            return;
        }

        $expected = [];

        foreach ($active->fields as $field) {
            $expected[$field->controlName] = [
                'control_name' => $field->controlName,
                'control_type' => $field->controlType,
                'required' => $field->required,
                'max_length' => $field->maxLength,
                'choice_values' => array_map(static fn ($choice): string => $choice->value, $field->choices),
            ];
        }

        $expectedFingerprint = FormChangeObservationFingerprint::fromFields($active->fields);
        $observedFingerprint = hash('sha256', json_encode($request->controls, JSON_THROW_ON_ERROR));

        if ($observedFingerprint !== $request->sourceFingerprint || $expectedFingerprint === $request->sourceFingerprint) {
            return;
        }

        $observed = [];
        foreach ($request->controls as $control) {
            $observed[$control['control_name']] = $control;
        }

        $differences = [];

        foreach ($expected as $name => $control) {
            if (!isset($observed[$name])) {
                $differences[] = ['kind' => 'removed_control', 'control_key' => $name, 'control_type' => $control['control_type']];
                continue;
            }

            $actual = $observed[$name];

            if ($actual['control_type'] !== $control['control_type']) {
                $differences[] = ['kind' => 'control_type_changed', 'control_key' => $name, 'control_type' => $actual['control_type']];
                continue;
            }

            if ($actual['required'] !== $control['required']) {
                $differences[] = ['kind' => 'required_changed', 'control_key' => $name, 'control_type' => $actual['control_type']];
            }

            if ($actual['max_length'] !== $control['max_length']) {
                $differences[] = ['kind' => 'maxlength_changed', 'control_key' => $name, 'control_type' => $actual['control_type']];
            }

            if ($actual['choice_values'] !== $control['choice_values']) {
                $differences[] = ['kind' => 'choices_changed', 'control_key' => $name, 'control_type' => $actual['control_type']];
            }
        }

        foreach ($observed as $name => $control) {
            if (!isset($expected[$name])) {
                $differences[] = ['kind' => 'added_control', 'control_key' => $name, 'control_type' => $control['control_type']];
            }
        }

        if ($differences === []) {
            return;
        }

        try {
            $fingerprint = hash('sha256', json_encode($request->controls, JSON_THROW_ON_ERROR));
        } catch (JsonException $failure) {
            return;
        }

        $this->repository->record(
            $paths,
            $this->identifierGenerator->uuidV7($this->clock->now()),
            $formPublicId,
            $request->configurationVersion,
            $request->pagePath,
            $request->formMarker,
            $fingerprint,
            array_slice($differences, 0, 100),
            $this->clock->now(),
        );
    }

    /** @return list<FormChangeObservation> */
    public function listOpen(string $applicationRoot): array
    {
        return $this->repository->listOpen($this->paths($applicationRoot));
    }

    public function countOpen(string $applicationRoot): int
    {
        return $this->repository->countOpen($this->paths($applicationRoot));
    }

    public function resolve(string $applicationRoot, string $publicId, string $actor = 'admin'): void
    {
        $this->repository->resolve($this->paths($applicationRoot), $publicId, $actor, $this->clock->now());
    }

    public function discard(string $applicationRoot, string $publicId, string $actor = 'admin'): void
    {
        $this->repository->discard($this->paths($applicationRoot), $publicId, $actor, $this->clock->now());
    }

    private function paths(string $applicationRoot): PrivateStoragePaths
    {
        return $this->storageResolver->resolve($applicationRoot);
    }
}
