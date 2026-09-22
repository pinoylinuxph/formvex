<?php

declare(strict_types=1);

namespace Formvex\Tests\System\Spoke;

use Formvex\Spoke\Console\BootstrapAdministratorCommand;
use Formvex\Spoke\Console\InstallCommand;
use Formvex\Spoke\Kernel;
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
