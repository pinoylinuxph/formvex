<?php

declare(strict_types=1);

namespace Formvex\Tests\System\Spoke;

use Formvex\Spoke\Application\InstallationSettings\InstallationSettingsService;
use Formvex\Spoke\Console\BootstrapAdministratorCommand;
use Formvex\Spoke\Console\InstallCommand;
use Formvex\Spoke\Domain\Submission\Contract\SubmissionStore;
use Formvex\Spoke\Domain\Submission\Exception\SubmissionFailure;
use Formvex\Spoke\Kernel;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FormConfigurationHttpTest extends KernelTestCase
{
    private string $temporaryRoot;

    private string $temporaryPassword;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-form-http-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryRoot . '/web', 0o700, true);
        mkdir($this->temporaryRoot . '/formvex', 0o700, true);
        $_ENV['FORMVEX_APPLICATION_ROOT'] = $this->temporaryRoot . '/formvex';
        $_SERVER['FORMVEX_APPLICATION_ROOT'] = $this->temporaryRoot . '/formvex';
        putenv('FORMVEX_APPLICATION_ROOT=' . $this->temporaryRoot . '/formvex');

        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $this->install();
        $command = new CommandTester(self::getContainer()->get(BootstrapAdministratorCommand::class));
        self::assertSame(0, $command->execute(['--application-root' => $this->temporaryRoot . '/formvex']));
        preg_match('/TEMPORARY_PASSWORD: ([A-Za-z0-9_-]+)/', $command->getDisplay(), $matches);
        $this->temporaryPassword = $matches[1] ?? '';
        self::getContainer()->get(InstallationSettingsService::class)->saveIdentity($this->temporaryRoot . '/formvex', [
            'website_display_name' => 'Logoslab',
            'bare_domain' => 'logoslab.xyz',
            'www_alias' => 'www.logoslab.xyz',
            'operational_alert_email' => 'admin@logoslab.xyz',
        ]);
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        putenv('FORMVEX_APPLICATION_ROOT');
        unset($_ENV['FORMVEX_APPLICATION_ROOT'], $_SERVER['FORMVEX_APPLICATION_ROOT']);
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testAdministratorCreatesUpdatesPublishesAndReadsImmutableFormVersions(): void
    {
        [$session, $csrf] = $this->authenticateAdministrator();
        $fields = json_encode([
            [
                'field_key' => 'message',
                'control_name' => 'message',
                'control_type' => 'textarea',
                'display_label' => 'Message',
                'parameter_key' => 'message',
                'ordinal' => 0,
                'required' => true,
                'max_length' => 10000,
                'choices' => [],
            ],
        ], JSON_THROW_ON_ERROR);
        $created = $this->request('POST', '/formvex/forms', [
            '_token' => $csrf,
            'display_name' => 'Contact form',
            'page_host' => 'logoslab.xyz',
            'page_path' => '/contact?source=home',
            'form_marker' => 'contact-form',
            'recipient' => 'owner@logoslab.xyz',
            'subject' => 'Contact message',
            'fields_json' => $fields,
        ], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);

        self::assertSame(Response::HTTP_FOUND, $created->getStatusCode());
        $location = $created->headers->get('Location');
        self::assertIsString($location);
        preg_match('#/formvex/forms/([0-9a-f-]+)$#', $location, $matches);
        $publicFormId = $matches[1] ?? '';
        self::assertNotSame('', $publicFormId);

        $form = $this->request('GET', $location, [], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);
        self::assertSame(Response::HTTP_OK, $form->getStatusCode());
        self::assertStringContainsString('Contact form', $form->getContent());
        self::assertStringContainsString('name="revision" value="1"', $form->getContent());
        self::assertStringContainsString('name="fields_json" rows="14" spellcheck="false" readonly', $form->getContent());

        $published = $this->request('POST', '/formvex/forms/' . $publicFormId . '/publish', [
            '_token' => $csrf,
            'revision' => '1',
        ], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);
        self::assertSame(Response::HTTP_OK, $published->getStatusCode());
        self::assertStringContainsString('Configuration version 1 was published.', $published->getContent());
        self::assertStringContainsString('v1', $published->getContent());
    }

    public function testFormsIndexShowsCreateFormAction(): void
    {
        [$session] = $this->authenticateAdministrator();
        $forms = $this->request('GET', '/formvex/forms', [], ['formvex_session' => $session]);

        self::assertSame(Response::HTTP_OK, $forms->getStatusCode());
        self::assertStringContainsString('Create form configuration', (string) $forms->getContent());
        self::assertStringContainsString('href="/formvex/forms/new"', (string) $forms->getContent());
    }

    public function testPublicResolutionReturnsMinimalMetadataAndSafeNoMatch(): void
    {
        [$session, $csrf] = $this->authenticateAdministrator();
        $fields = json_encode([[
            'field_key' => 'message',
            'control_name' => 'message',
            'control_type' => 'textarea',
            'display_label' => 'Message',
            'parameter_key' => 'message',
            'ordinal' => 0,
            'required' => true,
            'max_length' => 10000,
            'choices' => [],
        ]], JSON_THROW_ON_ERROR);
        $created = $this->request('POST', '/formvex/forms', [
            '_token' => $csrf,
            'display_name' => 'Contact form',
            'page_host' => 'logoslab.xyz',
            'page_path' => '/contact',
            'form_marker' => 'contact-form',
            'recipient' => 'owner@logoslab.xyz',
            'subject' => 'Contact message',
            'fields_json' => $fields,
        ], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);
        $location = (string) $created->headers->get('Location');
        preg_match('#/formvex/forms/([0-9a-f-]+)$#', $location, $matches);
        $publicFormId = $matches[1] ?? '';
        $published = $this->request('POST', '/formvex/forms/' . $publicFormId . '/publish', ['_token' => $csrf, 'revision' => '1'], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);
        self::assertSame(Response::HTTP_OK, $published->getStatusCode());
        $this->activate($publicFormId);

        $resolved = $this->request('GET', '/formvex/api/v1/forms/resolve', [
            'schema_version' => '1',
            'page_path' => '/contact?source=home',
            'form_marker' => 'contact-form',
        ], [], ['HTTP_HOST' => 'LOGOSLAB.XYZ.', 'HTTP_ORIGIN' => 'https://logoslab.xyz']);
        self::assertSame(Response::HTTP_OK, $resolved->getStatusCode());
        $body = json_decode((string) $resolved->getContent(), true, 4, JSON_THROW_ON_ERROR);
        self::assertSame($publicFormId, $body['public_form_id']);
        self::assertSame(1, $body['configuration_version']);
        self::assertArrayNotHasKey('recipient', $body);
        self::assertArrayNotHasKey('subject', $body);
        self::assertSame('https://logoslab.xyz', $resolved->headers->get('Access-Control-Allow-Origin'));

        $notFound = $this->request('GET', '/formvex/api/v1/forms/resolve', [
            'schema_version' => '1',
            'page_path' => '/unknown',
            'form_marker' => 'contact-form',
        ], [], ['HTTP_HOST' => 'logoslab.xyz', 'HTTP_ORIGIN' => 'https://logoslab.xyz']);
        self::assertSame(Response::HTTP_NOT_FOUND, $notFound->getStatusCode());
        $notFoundBody = json_decode((string) $notFound->getContent(), true, 4, JSON_THROW_ON_ERROR);
        self::assertSame('form_unavailable', $notFoundBody['error']['code']);
        self::assertNotSame('', $notFoundBody['error']['request_id']);
        self::assertStringNotContainsString('owner@logoslab.xyz', (string) $notFound->getContent());
    }

    public function testPublicSubmissionAcceptsWithoutAdministratorCookieAndRecoversMatchingRetry(): void
    {
        [$session, $csrf] = $this->authenticateAdministrator();
        $fields = json_encode([[
            'field_key' => 'message',
            'control_name' => 'message',
            'control_type' => 'textarea',
            'display_label' => 'Message',
            'parameter_key' => 'message',
            'ordinal' => 0,
            'required' => true,
            'max_length' => 10000,
            'choices' => [],
        ]], JSON_THROW_ON_ERROR);
        $created = $this->request('POST', '/formvex/forms', [
            '_token' => $csrf,
            'display_name' => 'Contact form',
            'page_host' => 'logoslab.xyz',
            'page_path' => '/',
            'form_marker' => 'contactForm',
            'recipient' => 'owner@logoslab.xyz',
            'subject' => 'Contact message',
            'fields_json' => $fields,
        ], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);
        $location = (string) $created->headers->get('Location');
        preg_match('#/formvex/forms/([0-9a-f-]+)$#', $location, $matches);
        $publicFormId = $matches[1] ?? '';
        $published = $this->request('POST', '/formvex/forms/' . $publicFormId . '/publish', ['_token' => $csrf, 'revision' => '1'], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);
        self::assertSame(Response::HTTP_OK, $published->getStatusCode());
        $this->activate($publicFormId);

        $payload = [
            'schema_version' => 1,
            'page_path' => '/',
            'form_marker' => 'contactForm',
            'configuration_version' => 1,
            'attempt_id' => '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
            'fields' => ['message' => 'A visitor message'],
            'field_shape' => [['control_name' => 'message', 'control_type' => 'textarea']],
        ];
        $headers = ['HTTP_HOST' => 'logoslab.xyz', 'HTTP_ORIGIN' => 'https://logoslab.xyz'];
        $accepted = $this->jsonRequest('POST', '/formvex/api/v1/forms/' . $publicFormId . '/submissions', $payload, $headers);
        self::assertSame(Response::HTTP_ACCEPTED, $accepted->getStatusCode());
        $acceptedBody = json_decode((string) $accepted->getContent(), true, 4, JSON_THROW_ON_ERROR);
        self::assertSame('Your message has been received.', $acceptedBody['acknowledgement']);
        self::assertArrayHasKey('receipt_id', $acceptedBody);
        self::assertStringNotContainsString('A visitor message', (string) $accepted->getContent());
        self::assertSame('https://logoslab.xyz', $accepted->headers->get('Access-Control-Allow-Origin'));

        $retry = $this->jsonRequest('POST', '/formvex/api/v1/forms/' . $publicFormId . '/submissions', $payload, $headers);
        $retryBody = json_decode((string) $retry->getContent(), true, 4, JSON_THROW_ON_ERROR);
        self::assertSame(Response::HTTP_ACCEPTED, $retry->getStatusCode());
        self::assertSame($acceptedBody['receipt_id'], $retryBody['receipt_id']);

        $changed = $payload;
        $changed['fields']['message'] = 'A changed visitor message';
        $conflict = $this->jsonRequest('POST', '/formvex/api/v1/forms/' . $publicFormId . '/submissions', $changed, $headers);
        $conflictBody = json_decode((string) $conflict->getContent(), true, 4, JSON_THROW_ON_ERROR);
        self::assertSame(Response::HTTP_CONFLICT, $conflict->getStatusCode());
        self::assertSame('attempt_conflict', $conflictBody['error']['code']);
        self::assertStringNotContainsString('A changed visitor message', (string) $conflict->getContent());

        $invalidJson = $this->rawRequest('POST', '/formvex/api/v1/forms/' . $publicFormId . '/submissions', '{', array_merge($headers, ['CONTENT_TYPE' => 'application/json']));
        self::assertSame(Response::HTTP_BAD_REQUEST, $invalidJson->getStatusCode());
        self::assertSame('request_invalid', json_decode((string) $invalidJson->getContent(), true, 4, JSON_THROW_ON_ERROR)['error']['code']);

        $invalidField = $payload;
        $invalidField['fields']['message'] = '';
        $invalidResponse = $this->jsonRequest('POST', '/formvex/api/v1/forms/' . $publicFormId . '/submissions', $invalidField, $headers);
        $invalidBody = json_decode((string) $invalidResponse->getContent(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $invalidResponse->getStatusCode());
        self::assertSame('required', $invalidBody['error']['fields'][0]['code']);

        $oversized = $this->rawRequest('POST', '/formvex/api/v1/forms/' . $publicFormId . '/submissions', str_repeat('x', 131073), array_merge($headers, ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '131073']));
        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $oversized->getStatusCode());

        $untrusted = $this->jsonRequest('POST', '/formvex/api/v1/forms/' . $publicFormId . '/submissions', $payload, ['HTTP_HOST' => 'logoslab.xyz', 'HTTP_ORIGIN' => 'https://evil.example']);
        self::assertSame(Response::HTTP_NOT_FOUND, $untrusted->getStatusCode());
        self::assertNull($untrusted->headers->get('Access-Control-Allow-Origin'));

        $connection = new PDO('sqlite:' . $this->temporaryRoot . '/formvex/database/formvex.sqlite');
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM submissions')->fetchColumn());
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM submission_attempts')->fetchColumn());
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM delivery_jobs')->fetchColumn());
    }

    public function testPublicSubmissionMapsStorageFailureToSafe503Response(): void
    {
        $publicFormId = $this->createActiveSubmissionForm();
        $store = self::createMock(SubmissionStore::class);
        $store->expects(self::once())->method('accept')->willThrowException(new SubmissionFailure(
            'storage_unavailable',
            'Formvex could not safely store your message. Your message was not accepted. Please try again later.',
        ));
        self::getContainer()->set(SubmissionStore::class, $store);

        $response = $this->jsonRequest('POST', '/formvex/api/v1/forms/' . $publicFormId . '/submissions', $this->submissionPayload(), [
            'HTTP_HOST' => 'logoslab.xyz',
            'HTTP_ORIGIN' => 'https://logoslab.xyz',
        ]);
        $body = json_decode((string) $response->getContent(), true, 4, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('storage_unavailable', $body['error']['code']);
        self::assertStringContainsString('not accepted', $body['error']['message']);
        self::assertNotSame('', $body['request_id']);
        self::assertStringNotContainsString('visitor message', (string) $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testPublicSubmissionMapsUnexpectedFailureToSafe500Response(): void
    {
        $publicFormId = $this->createActiveSubmissionForm();
        $store = self::createMock(SubmissionStore::class);
        $store->expects(self::once())->method('accept')->willThrowException(new RuntimeException('synthetic secret path /private/database.sqlite'));
        self::getContainer()->set(SubmissionStore::class, $store);

        $response = $this->jsonRequest('POST', '/formvex/api/v1/forms/' . $publicFormId . '/submissions', $this->submissionPayload(), [
            'HTTP_HOST' => 'logoslab.xyz',
            'HTTP_ORIGIN' => 'https://logoslab.xyz',
        ]);
        $body = json_decode((string) $response->getContent(), true, 4, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame('internal_error', $body['error']['code']);
        self::assertNotSame('', $body['request_id']);
        self::assertStringNotContainsString('synthetic secret path', (string) $response->getContent());
        self::assertStringNotContainsString('visitor message', (string) $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testAdministratorCanDiscoverReviewAndApplyAFormCandidate(): void
    {
        [$session, $csrf] = $this->authenticateAdministrator();
        $start = $this->request('POST', '/formvex/forms/new/discovery', [
            '_token' => $csrf,
            'page_host' => 'logoslab.xyz',
            'page_path' => '/contact',
        ], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);

        self::assertSame(Response::HTTP_OK, $start->getStatusCode());
        self::assertStringContainsString('Open page for discovery', (string) $start->getContent());
        preg_match('~https://logoslab\.xyz/contact#formvex_discovery=([^"<]+)~', (string) $start->getContent(), $matches);
        $capability = rawurldecode($matches[1] ?? '');
        self::assertNotSame('', $capability);

        $metadata = json_encode([
            'schema_version' => 1,
            'capability' => $capability,
            'page_path' => '/contact',
            'forms' => [[
                'form_marker' => 'contact-form',
                'display_name' => 'Contact form',
                'marker_generated' => false,
                'ambiguous' => false,
                'controls' => [[
                    'discovery_key' => 'control-1-1',
                    'control_name' => 'email',
                    'control_type' => 'email',
                    'display_label' => 'Email',
                    'label_resolved' => true,
                    'required' => true,
                    'max_length' => 255,
                    'choices' => [],
                    'choice_group_key' => null,
                    'suggested_parameters' => ['email'],
                ], [
                    'discovery_key' => 'control-1-2',
                    'control_name' => 'role',
                    'control_type' => 'text',
                    'display_label' => 'Role',
                    'label_resolved' => true,
                    'required' => false,
                    'max_length' => 255,
                    'choices' => [],
                    'choice_group_key' => null,
                    'suggested_parameters' => [],
                ], [
                    'discovery_key' => 'control-1-3',
                    'control_name' => 'sector',
                    'control_type' => 'select',
                    'display_label' => 'Sub-vertical',
                    'label_resolved' => true,
                    'required' => false,
                    'max_length' => 10000,
                    'choices' => [
                        ['value' => 'engineering', 'label' => 'Engineering'],
                        ['value' => 'other', 'label' => 'Other'],
                    ],
                    'choice_group_key' => null,
                    'suggested_parameters' => [],
                ]],
                'unsupported_controls' => [],
            ]],
        ], JSON_THROW_ON_ERROR);
        $discoveryRequest = Request::create('/formvex/api/v1/discovery/redeem', 'POST', [], [], [], [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'logoslab.xyz',
            'HTTP_ORIGIN' => 'https://logoslab.xyz',
            'CONTENT_TYPE' => 'application/json',
        ], $metadata);
        $discoveryResponse = self::$kernel->handle($discoveryRequest);
        self::$kernel->terminate($discoveryRequest, $discoveryResponse);
        self::assertSame(Response::HTTP_CREATED, $discoveryResponse->getStatusCode());
        $candidateBody = json_decode((string) $discoveryResponse->getContent(), true, 4, JSON_THROW_ON_ERROR);
        $candidateId = $candidateBody['candidate_id'] ?? '';
        self::assertNotSame('', $candidateId);

        $review = $this->request('GET', '/formvex/forms/new/discovery', [], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);
        self::assertSame(Response::HTTP_OK, $review->getStatusCode());
        self::assertStringContainsString('Detected forms', (string) $review->getContent());
        self::assertStringContainsString('contact-form', (string) $review->getContent());
        self::assertStringContainsString('name="fields[control-1-1][parameter_key]"', (string) $review->getContent());
        self::assertStringContainsString('name="fields[control-1-2][custom_parameter_key]"', (string) $review->getContent());
        self::assertStringContainsString('name="fields[control-1-3][choice_labels][0]"', (string) $review->getContent());

        $apply = $this->request('POST', '/formvex/forms/new/discovery/apply', [
            '_token' => $csrf,
            'candidate_id' => $candidateId,
            'selected_form_index' => '0',
            'fields' => [
                'control-1-1' => [
                    'parameter_key' => 'email',
                    'custom_parameter_key' => '',
                    'display_label' => 'Email',
                    'required' => '1',
                    'max_length' => '255',
                    'choice_labels' => [],
                ],
                'control-1-2' => [
                    'parameter_key' => 'custom',
                    'custom_parameter_key' => 'role',
                    'display_label' => 'Role',
                    'required' => '0',
                    'max_length' => '255',
                    'choice_labels' => [],
                ],
                'control-1-3' => [
                    'parameter_key' => 'custom',
                    'custom_parameter_key' => 'sector',
                    'display_label' => 'Sub-vertical',
                    'required' => '0',
                    'max_length' => '10000',
                    'choice_labels' => ['Engineering', 'Other'],
                ],
            ],
        ], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);
        self::assertSame(Response::HTTP_FOUND, $apply->getStatusCode());
        self::assertMatchesRegularExpression('#/formvex/forms/[0-9a-f-]+$#', (string) $apply->headers->get('Location'));
    }

    /** @return array{0: string, 1: string} */
    private function authenticateAdministrator(): array
    {
        $loginPage = $this->request('GET', '/formvex/login');
        $loginCsrf = $this->cookieValue($loginPage, 'formvex_login_csrf');
        $login = $this->request('POST', '/formvex/login', [
            'login_identifier' => 'admin',
            'password' => $this->temporaryPassword,
            '_token' => $loginCsrf,
        ], ['formvex_login_csrf' => $loginCsrf]);
        $session = $this->cookieValue($login, 'formvex_session');
        $csrf = $this->cookieValue($login, 'formvex_admin_csrf');
        $change = $this->request('POST', '/formvex/password/change', [
            'new_password' => 'correct horse battery staple',
            'confirmation' => 'correct horse battery staple',
            '_token' => $csrf,
        ], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);

        return [$this->cookieValue($change, 'formvex_session'), $this->cookieValue($change, 'formvex_admin_csrf')];
    }

    private function activate(string $publicFormId): void
    {
        $connection = new PDO('sqlite:' . $this->temporaryRoot . '/formvex/database/formvex.sqlite');
        $statement = $connection->prepare("UPDATE form_configuration_versions SET state = 'active' WHERE state = 'published' AND form_id = (SELECT id FROM form_configurations WHERE public_id = :public_id)");
        $statement->execute(['public_id' => $publicFormId]);
    }

    private function createActiveSubmissionForm(): string
    {
        [$session, $csrf] = $this->authenticateAdministrator();
        $fields = json_encode([[
            'field_key' => 'message',
            'control_name' => 'message',
            'control_type' => 'textarea',
            'display_label' => 'Message',
            'parameter_key' => 'message',
            'ordinal' => 0,
            'required' => true,
            'max_length' => 10000,
            'choices' => [],
        ]], JSON_THROW_ON_ERROR);
        $created = $this->request('POST', '/formvex/forms', [
            '_token' => $csrf,
            'display_name' => 'Contact form',
            'page_host' => 'logoslab.xyz',
            'page_path' => '/',
            'form_marker' => 'contactForm',
            'recipient' => 'owner@logoslab.xyz',
            'subject' => 'Contact message',
            'fields_json' => $fields,
        ], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);
        $location = (string) $created->headers->get('Location');
        preg_match('#/formvex/forms/([0-9a-f-]+)$#', $location, $matches);
        $publicFormId = $matches[1] ?? '';
        self::assertNotSame('', $publicFormId);
        $published = $this->request('POST', '/formvex/forms/' . $publicFormId . '/publish', ['_token' => $csrf, 'revision' => '1'], ['formvex_session' => $session, 'formvex_admin_csrf' => $csrf]);
        self::assertSame(Response::HTTP_OK, $published->getStatusCode());
        $this->activate($publicFormId);

        return $publicFormId;
    }

    /** @return array<string, mixed> */
    private function submissionPayload(): array
    {
        return [
            'schema_version' => 1,
            'page_path' => '/',
            'form_marker' => 'contactForm',
            'configuration_version' => 1,
            'attempt_id' => '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
            'fields' => ['message' => 'A visitor message'],
            'field_shape' => [['control_name' => 'message', 'control_type' => 'textarea']],
        ];
    }

    /** @param array<string, mixed> $parameters @param array<string, string> $cookies @param array<string, string> $server */
    private function request(string $method, string $path, array $parameters = [], array $cookies = [], array $server = []): Response
    {
        $request = Request::create($path, $method, $parameters, $cookies, [], array_merge([
            'HTTPS' => 'on',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $server));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    /** @param array<string, mixed> $payload @param array<string, string> $server */
    private function jsonRequest(string $method, string $path, array $payload, array $server = []): Response
    {
        $request = Request::create($path, $method, [], [], [], array_merge([
            'HTTPS' => 'on',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/json',
        ], $server), json_encode($payload, JSON_THROW_ON_ERROR));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    /** @param array<string, string> $server */
    private function rawRequest(string $method, string $path, string $body, array $server = []): Response
    {
        $request = Request::create($path, $method, [], [], [], array_merge([
            'HTTPS' => 'on',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
        ], $server), $body);
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);

        return $response;
    }

    private function install(): void
    {
        $command = new CommandTester(self::getContainer()->get(InstallCommand::class));
        self::assertSame(0, $command->execute([
            '--application-root' => $this->temporaryRoot . '/formvex',
            '--web-root' => $this->temporaryRoot . '/web',
        ]));
    }

    private function cookieValue(Response $response, string $name): string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie->getValue();
            }
        }

        self::fail('Expected cookie ' . $name . ' was not set.');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
