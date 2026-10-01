<?php

declare(strict_types=1);

namespace Formvex\Tests\System\Spoke;

use Formvex\Spoke\Console\BootstrapAdministratorCommand;
use Formvex\Spoke\Console\InstallCommand;
use Formvex\Spoke\Kernel;
use PDO;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdministratorHttpTest extends KernelTestCase
{
    private string $temporaryRoot;

    private string $temporaryPassword;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-admin-http-' . bin2hex(random_bytes(8));
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
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        putenv('FORMVEX_APPLICATION_ROOT');
        unset($_ENV['FORMVEX_APPLICATION_ROOT'], $_SERVER['FORMVEX_APPLICATION_ROOT']);
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testTemporaryLoginRequiresPasswordChangeAndLogoutRevokesAccess(): void
    {
        $loginPage = $this->request('GET', '/formvex/login');
        self::assertSame(Response::HTTP_OK, $loginPage->getStatusCode());
        $loginCsrf = $this->cookieValue($loginPage, 'formvex_login_csrf');
        self::assertSame($loginCsrf, $this->hiddenToken($loginPage));

        $login = $this->request(
            'POST',
            '/formvex/login',
            [
                'login_identifier' => 'admin',
                'password' => $this->temporaryPassword,
                '_token' => $loginCsrf,
            ],
            ['formvex_login_csrf' => $loginCsrf],
        );
        self::assertSame(Response::HTTP_FOUND, $login->getStatusCode());
        self::assertSame('/formvex/password/change', $login->headers->get('Location'));
        $sessionCookie = $this->cookie($login, 'formvex_session');
        self::assertTrue($sessionCookie->isSecure());
        self::assertTrue($sessionCookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $sessionCookie->getSameSite());
        self::assertSame('/formvex', $sessionCookie->getPath());
        $session = $this->cookieValue($login, 'formvex_session');
        $csrf = $this->cookieValue($login, 'formvex_admin_csrf');

        $home = $this->request('GET', '/formvex', [], [
            'formvex_session' => $session,
            'formvex_admin_csrf' => $csrf,
        ]);
        self::assertSame(Response::HTTP_FOUND, $home->getStatusCode());
        self::assertSame('/formvex/password/change', $home->headers->get('Location'));

        $change = $this->request(
            'POST',
            '/formvex/password/change',
            [
                'new_password' => 'correct horse battery staple',
                'confirmation' => 'correct horse battery staple',
                '_token' => $csrf,
            ],
            [
                'formvex_session' => $session,
                'formvex_admin_csrf' => $csrf,
            ],
        );
        self::assertSame(Response::HTTP_FOUND, $change->getStatusCode());
        self::assertSame('/formvex', $change->headers->get('Location'));
        $newSession = $this->cookieValue($change, 'formvex_session');
        $newCsrf = $this->cookieValue($change, 'formvex_admin_csrf');

        $authenticatedHome = $this->request('GET', '/formvex', [], [
            'formvex_session' => $newSession,
            'formvex_admin_csrf' => $newCsrf,
        ]);
        self::assertSame(Response::HTTP_OK, $authenticatedHome->getStatusCode());

        $logout = $this->request(
            'POST',
            '/formvex/logout',
            ['_token' => $newCsrf],
            [
                'formvex_session' => $newSession,
                'formvex_admin_csrf' => $newCsrf,
            ],
        );
        self::assertSame(Response::HTTP_FOUND, $logout->getStatusCode());

        $revokedHome = $this->request('GET', '/formvex', [], [
            'formvex_session' => $newSession,
            'formvex_admin_csrf' => $newCsrf,
        ]);
        self::assertSame(Response::HTTP_FOUND, $revokedHome->getStatusCode());
        self::assertSame('/formvex/login', $revokedHome->headers->get('Location'));
    }

    public function testEveryPortalDestinationUsesTheProtectedResponsiveShell(): void
    {
        [$session, $csrf] = $this->authenticateAdministrator();
        $destinations = [
            '/formvex',
            '/formvex/forms',
            '/formvex/submissions',
            '/formvex/delivery',
            '/formvex/diagnostics',
            '/formvex/maintenance',
            '/formvex/settings',
        ];

        foreach ($destinations as $destination) {
            $response = $this->request('GET', $destination, [], [
                'formvex_session' => $session,
                'formvex_admin_csrf' => $csrf,
            ]);

            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $destination);
            self::assertStringContainsString('Primary navigation', $response->getContent(), $destination);
            self::assertStringContainsString('Local Spoke', $response->getContent(), $destination);
            self::assertStringContainsString('data-theme="light"', $response->getContent(), $destination);
            self::assertStringContainsString('class="fv-nav-icon"', $response->getContent(), $destination);
            self::assertStringNotContainsString('fv-nav-mark', $response->getContent(), $destination);
        }

        $darkPreference = $this->request(
            'POST',
            '/formvex/preferences/theme',
            [
                '_token' => $csrf,
                'theme' => 'dark',
                'return_route' => 'spoke_admin_forms',
            ],
            [
                'formvex_session' => $session,
                'formvex_admin_csrf' => $csrf,
            ],
        );

        self::assertSame(Response::HTTP_FOUND, $darkPreference->getStatusCode());
        self::assertSame('/formvex/forms', $darkPreference->headers->get('Location'));
        $themeCookie = $this->cookie($darkPreference, 'formvex_theme');
        self::assertSame('dark', $themeCookie->getValue());
        self::assertTrue($themeCookie->isSecure());
        self::assertFalse($themeCookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $themeCookie->getSameSite());
        self::assertSame('/formvex', $themeCookie->getPath());

        $darkPage = $this->request('GET', '/formvex/forms', [], [
            'formvex_session' => $session,
            'formvex_admin_csrf' => $csrf,
            'formvex_theme' => 'dark',
        ]);
        self::assertSame(Response::HTTP_OK, $darkPage->getStatusCode());
        self::assertStringContainsString('data-theme="dark"', $darkPage->getContent());
        self::assertStringContainsString('aria-current="page"', $darkPage->getContent());
    }

    public function testPortalDestinationsRemainProtectedAndInvalidThemeRequestsAreRejected(): void
    {
        foreach (['/formvex', '/formvex/forms', '/formvex/settings'] as $destination) {
            $response = $this->request('GET', $destination);

            self::assertSame(Response::HTTP_FOUND, $response->getStatusCode(), $destination);
            self::assertSame('/formvex/login', $response->headers->get('Location'), $destination);
        }

        [$session, $csrf] = $this->authenticateAdministrator();

        $invalidCsrf = $this->request(
            'POST',
            '/formvex/preferences/theme',
            [
                '_token' => 'invalid-token',
                'theme' => 'dark',
                'return_route' => 'spoke_admin_home',
            ],
            [
                'formvex_session' => $session,
                'formvex_admin_csrf' => $csrf,
            ],
        );
        self::assertSame(Response::HTTP_BAD_REQUEST, $invalidCsrf->getStatusCode());

        $invalidRoute = $this->request(
            'POST',
            '/formvex/preferences/theme',
            [
                '_token' => $csrf,
                'theme' => 'dark',
                'return_route' => 'unsafe_external_route',
            ],
            [
                'formvex_session' => $session,
                'formvex_admin_csrf' => $csrf,
            ],
        );
        self::assertSame(Response::HTTP_BAD_REQUEST, $invalidRoute->getStatusCode());
        self::assertCount(0, array_filter(
            $invalidRoute->headers->getCookies(),
            static fn (Cookie $cookie): bool => $cookie->getName() === 'formvex_theme',
        ));
    }

    public function testSettingsPageUsesDefaultsAndPersistsWebsiteIdentity(): void
    {
        [$session, $csrf] = $this->authenticateAdministrator();
        $settings = $this->request('GET', '/formvex/settings', [], [
            'formvex_session' => $session,
            'formvex_admin_csrf' => $csrf,
        ]);

        self::assertSame(Response::HTTP_OK, $settings->getStatusCode());
        self::assertStringContainsString('Local installation readiness', $settings->getContent());
        self::assertStringContainsString('Submission protection', $settings->getContent());
        self::assertStringContainsString('aria-current="page"', $settings->getContent());
        self::assertStringNotContainsString('Configure outgoing email', $settings->getContent());

        $emailSettings = $this->request('GET', '/formvex/settings?tab=email', [], [
            'formvex_session' => $session,
            'formvex_admin_csrf' => $csrf,
        ]);

        self::assertSame(Response::HTTP_OK, $emailSettings->getStatusCode());
        self::assertStringContainsString('Configure outgoing email', $emailSettings->getContent());
        self::assertStringContainsString('SMTPS (implicit TLS)', $emailSettings->getContent());
        self::assertStringContainsString('value="465"', $emailSettings->getContent());
        self::assertStringContainsString('value="10"', $emailSettings->getContent());
        self::assertStringNotContainsString('Identify this website', $emailSettings->getContent());

        $saved = $this->request(
            'POST',
            '/formvex/settings/identity?tab=website',
            [
                '_token' => $csrf,
                'website_display_name' => 'Logoslab Production',
                'bare_domain' => 'logoslab.xyz',
                'www_alias' => 'www.logoslab.xyz',
                'operational_alert_email' => 'admin@logoslab.xyz',
            ],
            [
                'formvex_session' => $session,
                'formvex_admin_csrf' => $csrf,
            ],
        );

        self::assertSame(Response::HTTP_OK, $saved->getStatusCode());
        self::assertStringContainsString('The identity settings were saved successfully.', $saved->getContent());
        self::assertStringContainsString('Logoslab Production', $saved->getContent());
    }

    public function testSubmissionReviewIsAuthenticatedBoundedAndEscapesStoredValues(): void
    {
        $this->seedSubmission();
        [$session, $csrf] = $this->authenticateAdministrator();

        $list = $this->request('GET', '/formvex/submissions?page_size=101&sort=unsafe', [], [
            'formvex_session' => $session,
            'formvex_admin_csrf' => $csrf,
        ]);
        self::assertSame(Response::HTTP_OK, $list->getStatusCode());
        self::assertStringContainsString('Filter corrected', (string) $list->getContent());
        self::assertStringContainsString('Contact form', (string) $list->getContent());
        self::assertStringNotContainsString('owner@example.com', (string) $list->getContent());

        $detail = $this->request('GET', '/formvex/submissions/22222222-2222-4222-8222-222222222222', [], [
            'formvex_session' => $session,
            'formvex_admin_csrf' => $csrf,
        ]);
        self::assertSame(Response::HTTP_OK, $detail->getStatusCode());
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', (string) $detail->getContent());
        self::assertStringNotContainsString('owner@example.com', (string) $detail->getContent());
        preg_match('/action="\/formvex\/submissions\/[^\"]+\/handled".*?name="action_token" value="([^\"]+)"/s', (string) $detail->getContent(), $matches);
        $actionToken = $matches[1] ?? '';
        self::assertNotSame('', $actionToken);

        $handled = $this->request('POST', '/formvex/submissions/22222222-2222-4222-8222-222222222222/handled', [
            '_token' => $csrf,
            'action_token' => $actionToken,
            'return_query' => '',
        ], [
            'formvex_session' => $session,
            'formvex_admin_csrf' => $csrf,
        ]);
        self::assertSame(Response::HTTP_FOUND, $handled->getStatusCode());
        self::assertStringContainsString('notice=handled', (string) $handled->headers->get('Location'));
        $connection = new PDO('sqlite:' . $this->temporaryRoot . '/formvex/database/formvex.sqlite');
        self::assertSame('handled', $connection->query("SELECT state FROM submissions WHERE public_id = '22222222-2222-4222-8222-222222222222'")->fetchColumn());
    }

    public function testAdministratorCanSaveBrandingAndItAppearsOnAuthAndPortalSurfaces(): void
    {
        [$session, $csrf] = $this->authenticateAdministrator();
        $saved = $this->request(
            'POST',
            '/formvex/settings/branding?tab=website',
            [
                '_token' => $csrf,
                'brand_name' => 'Acme Portal',
                'slogan' => 'Reliable forms for every team',
                'show_slogan' => '1',
            ],
            [
                'formvex_session' => $session,
                'formvex_admin_csrf' => $csrf,
            ],
        );

        self::assertSame(Response::HTTP_OK, $saved->getStatusCode());
        self::assertStringContainsString('Branding settings were saved successfully.', (string) $saved->getContent());
        self::assertStringContainsString('Acme Portal', (string) $saved->getContent());
        self::assertStringContainsString('Reliable forms for every team', (string) $saved->getContent());

        $login = $this->request('GET', '/formvex/login');
        self::assertSame(Response::HTTP_OK, $login->getStatusCode());
        self::assertStringContainsString('Sign in to Acme Portal', (string) $login->getContent());
        self::assertStringContainsString('Reliable forms for every team', (string) $login->getContent());
        self::assertStringNotContainsString('Sign in to Formvex', (string) $login->getContent());
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function authenticateAdministrator(): array
    {
        $loginPage = $this->request('GET', '/formvex/login');
        $loginCsrf = $this->cookieValue($loginPage, 'formvex_login_csrf');
        $login = $this->request(
            'POST',
            '/formvex/login',
            [
                'login_identifier' => 'admin',
                'password' => $this->temporaryPassword,
                '_token' => $loginCsrf,
            ],
            ['formvex_login_csrf' => $loginCsrf],
        );
        $session = $this->cookieValue($login, 'formvex_session');
        $csrf = $this->cookieValue($login, 'formvex_admin_csrf');
        $change = $this->request(
            'POST',
            '/formvex/password/change',
            [
                'new_password' => 'correct horse battery staple',
                'confirmation' => 'correct horse battery staple',
                '_token' => $csrf,
            ],
            [
                'formvex_session' => $session,
                'formvex_admin_csrf' => $csrf,
            ],
        );

        return [$this->cookieValue($change, 'formvex_session'), $this->cookieValue($change, 'formvex_admin_csrf')];
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, string> $cookies
     */
    private function request(string $method, string $path, array $parameters = [], array $cookies = []): Response
    {
        $request = Request::create(
            $path,
            $method,
            $parameters,
            $cookies,
            [],
            [
                'HTTPS' => 'on',
                'HTTP_HOST' => 'localhost',
                'REMOTE_ADDR' => '127.0.0.1',
            ],
        );
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

    private function seedSubmission(): void
    {
        $connection = new PDO('sqlite:' . $this->temporaryRoot . '/formvex/database/formvex.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $timestamp = '2026-09-30T12:00:00.000000Z';
        $connection->exec("INSERT INTO form_configurations (id, public_id, display_name, created_at, updated_at) VALUES (1, '11111111-1111-4111-8111-111111111111', 'Contact form', '$timestamp', '$timestamp')");
        $connection->exec("INSERT INTO form_configuration_versions (id, form_id, version_number, state, revision, recipient, subject, created_at, updated_at) VALUES (1, 1, 1, 'active', 1, 'owner@example.com', 'Contact message', '$timestamp', '$timestamp')");
        $connection->exec("INSERT INTO form_configuration_version_pages (version_id, host, path, form_marker) VALUES (1, 'example.com', '/', 'contactForm')");
        $connection->exec("INSERT INTO form_configuration_version_fields (id, version_id, field_key, control_name, control_type, display_label, parameter_key, ordinal, is_required, max_length) VALUES (1, 1, 'message', 'message', 'textarea', 'Message', 'message', 0, 0, 10000)");
        $connection->exec("INSERT INTO submissions (id, public_id, form_id, configuration_version_id, configuration_version, page_path, form_marker, recipient, subject, fields_json, classification, created_at, updated_at) VALUES (1, '22222222-2222-4222-8222-222222222222', 1, 1, 1, '/', 'contactForm', 'owner@example.com', 'Contact message', '{\"message\":\"<script>alert(1)</script>\"}', 'normal', '$timestamp', '$timestamp')");
        $connection->exec("INSERT INTO submission_attempts (public_form_id, attempt_id, submission_id, payload_hash, receipt_id, accepted_at, expires_at) VALUES ('11111111-1111-4111-8111-111111111111', 'attempt-0001', 1, 'hash', 'receipt-0001', '$timestamp', '2026-10-01T12:00:00.000000Z')");
        $connection->exec("INSERT INTO delivery_jobs (id, job_id, submission_id, state, attempt_count, due_at, created_at, updated_at) VALUES (1, 'job-0001', 1, 'sent', 1, '$timestamp', '$timestamp', '$timestamp')");
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

    private function cookie(Response $response, string $name): Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        self::fail('Expected cookie ' . $name . ' was not set.');
    }

    private function hiddenToken(Response $response): string
    {
        $matches = [];
        $matchCount = preg_match('/name="_token" value="([^"]+)"/', $response->getContent(), $matches);
        self::assertSame(1, $matchCount);

        return $matches[1] ?? '';
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
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
