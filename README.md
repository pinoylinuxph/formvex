# Formvex

Formvex is a self-hosted form-processing and SMTP-delivery application for existing websites.

## Development baseline

- PHP 8.4 or 8.5 with `ctype`, `curl`, `dom`, `iconv`, `intl`, `mbstring`, `PDO`,
  `pdo_sqlite`, `sqlite3`, `xml`, and `zip`
- Composer 2
- Node.js 24 LTS with npm 11

Install the exact locked development dependencies:

```shell
composer install --no-interaction
npm ci
npx playwright install chromium
```

Run the complete local quality gate:

```shell
composer qa
```

The JavaScript build, Composer, npm, and test tools are development requirements. A production
Formvex Spoke release will contain compiled browser assets and production PHP dependencies so that
the hosting account does not need Composer, Node.js, npm, Docker, or a persistent service.

Copy `.env.example` to `.env` only for local runtime work and replace every example value. Unit 01
quality checks use synthetic test configuration and require no live credential or production data.
