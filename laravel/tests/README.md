# Tests

```bash
composer test                                   # Unit + Feature (from laravel/)
vendor/bin/phpunit --testsuite Feature --filter RulesTest
python -m pytest parser/tests/test_rule_contract.py   # from the repo root
```

`composer test` needs a PHP with `pdo_mysql` and `intl` enabled in php.ini (the
Docker images have them). With a bare Windows PHP, load them per run instead:
`php -d extension=intl -d extension=pdo_mysql -d extension=zip vendor/bin/phpunit`.

## Database

Feature tests run on a local MySQL database **`carbot_test`** only (MySQL, not
sqlite: the app relies on JSON functions, `REGEXP_LIKE` and `_ci`/`_bin`
collations). `phpunit.xml` forces the connection (127.0.0.1:3318, user
`carbot`), `.env.testing` repeats it, and `Tests\TestCase` refuses to start on
any other database or host, so a run can never reach prod or the dev copy.

One-time setup with the dev container:

```bash
docker exec carbot-filament-mysql mysql -uroot -proot -e "CREATE DATABASE carbot_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON carbot_test.* TO 'carbot'@'%';"
```

Migrations run (≈100 s) only when `carbot_test` does not hold exactly the
repo's migrations; set `TESTS_FRESH_DB=1` to force `migrate:fresh`. Each test
runs in a transaction that is rolled back.

## External services

`Http::preventStrayRequests()` is on in every test: Telegram, FloppyData,
DeepSeek and the AI query parser must be faked with `Http::fake()`; the
configured hosts in `phpunit.xml` are `*.test` placeholders and the bot token
is a fake one (tests check it never shows up in errors).

## What is where (tests/Feature/Admin)

| File | Covers |
|------|--------|
| AccessTest | login, roles, page permissions (pages, menu, tab components), super-only actions, self/last-super delete, old-address redirects |
| SmokeTest | every panel page and resource page (read from the panel) opens with data and on an empty DB; every widget renders |
| ParseRuleContractTest | builder → parse_filters row and preview count, against `parser/tests/fixtures/parse_filters_contract.json` (pytest checks the same file through the parser's loader) |
| RulesTest | generated names, drag order → priority, scope, toggle/delete, hits, skip log link and cleanup |
| RunsTest | launch modes → parse_jobs rows, proxy estimate, running job, cancel, lifecycle «засчитан» |
| ReparseTest | LotReparseQueue row format, split by source, no duplicates, 500 cap, bulk action, permission |
| TranslationReviewTest | accept, correct (only matching lots, chunked), reject, «Без перевода», page accept, keys |
| SearchSettingsTest | autosave, tolerance «±1 год» / «±10 %» round trip, reset, the AI parse check and its fallback |
| NotificationsTest | recipients, types, thresholds, test message success/refusal/connection error without the token |
| FieldCoverageTest | `fields:compute-coverage` on the current schema |
| OverviewTest | health numbers, incidents, balances fresh / stale / API failing or timing out |
| LogsTest | tail and filters, only known files (no path traversal), download, clearing (super only) |
| LotsTest | search by id prefix / VIN / plate, saved-tab counts, filters, lot page (₩, raw_data, pre_norm, no inspection) |
