# Per-Domain robots.txt, ads.txt and SEO Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let every domain own its own `robots.txt` and `ads.txt` as real files under `resources/domains/{domain_key}/`, and remove the single global `ads.txt` configuration value that no multi-domain install can use.

**Architecture:** Add `resources/domains/{domain_key}/` as the third per-domain convention, alongside the existing `config/domains/{domain_key}.php` and `routes/domains/{domain_key}.php`. A new `DomainAssets` support class reads one file for one domain. `GhostController` serves those files; `GhostRouteRegistrar` decides whether `/ads.txt` exists for a domain by testing that domain's own file rather than by reading the active domain's configuration. The `robots` and `seo` configuration blocks stay as global defaults, overridable per domain through `config/domains/`, and are documented as such.

**Tech Stack:** PHP 8.3/8.4, Laravel 11, Pest (PHPUnit-style classes), Orchestra Testbench, Pint.

**Spec:** [docs/superpowers/specs/2026-08-26-per-domain-seo-assets-design.md](../specs/2026-08-26-per-domain-seo-assets-design.md)

## Global Constraints

- **PHP binary:** the bare `php` on PATH is 7.4 and cannot run this suite. Every PHP, Composer, Pest and Pint invocation must use `"/Users/sonjj/Library/Application Support/Herd/bin/php84"`.
- **Test command:** `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest`
- **Lint command:** `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pint`
- **Baseline:** 160 tests / 352 assertions pass before this plan starts. Every task must end with the full suite green.
- **Namespace:** `MrSonj\MultiDomainGhost\`; tests live in `MrSonj\MultiDomainGhost\Tests\`.
- **Style:** `declare(strict_types=1);` on every `src/` file, `final` on new `Support` classes. Test classes do **not** declare strict types (follow the existing test files).
- **Target release:** 2.0.0, already drafted in `CHANGELOG.md` and not yet tagged. Breaking changes fold into that section — do not create a 3.0.0.
- **Directory key:** a domain's directory-safe key comes from `DomainName::dirKey()` — `example.com` → `example_com`, `my-sample-blog.co.uk` → `my-sample-blog_co_uk`.

## File Structure

| File | Responsibility |
| --- | --- |
| `src/Support/DomainAssets.php` (create) | Locate and read one static file belonging to one domain. The only place that knows the `resources/domains/{key}/` layout. |
| `src/Http/Controllers/GhostController.php` (modify) | `robots()` and `ads()` serve the domain's file when it exists. |
| `src/Routing/GhostRouteRegistrar.php` (modify) | Register `/ads.txt` per domain, based on that domain's file. |
| `config/multidomain-ghost.php` (modify) | Drop the `ads` block; rename the content-signal env key. |
| `src/Console/Commands/GhostDomainAddCommand.php` (modify) | Scaffold the empty assets directory and point at it. |
| `tests/TestCase.php` (modify) | Shared `setDomainAssets()` helper and temp-file cleanup. |
| `tests/Unit/DomainAssetsTest.php` (create) | Unit coverage for the new class. |
| `tests/Unit/PublishedConfigTest.php` (create) | Locks the shape of the published config file. |
| `README.md`, `CHANGELOG.md` (modify) | Document the convention and the upgrade path. |

---

### Task 1: `DomainAssets` and the test helper

**Files:**
- Create: `src/Support/DomainAssets.php`
- Create: `tests/Unit/DomainAssetsTest.php`
- Modify: `tests/TestCase.php`

**Interfaces:**
- Consumes: `MrSonj\MultiDomainGhost\Support\DomainName::dirKey(string $domain): string` (existing).
- Produces:
  - `DomainAssets::path(string $domain, string $file): string`
  - `DomainAssets::contents(string $domain, string $file): ?string` — trimmed contents, or `null` when the file is missing **or** empty.
  - `TestCase::setDomainAssets(array $files): void` — keys are paths relative to `resources/domains` (e.g. `'example_com/ads.txt'`), values are file contents. Files and the directories created for them are removed in `tearDown`.

- [ ] **Step 1: Add the test helper to `tests/TestCase.php`**

Replace the `private array $temporaryRouteFiles = [];` property declaration with:

```php
    private array $temporaryFiles = [];

    private array $temporaryDirectories = [];
```

Replace the whole existing `setDomainRouteFiles()` method with these three methods:

```php
    protected function setDomainRouteFiles(array $files): void
    {
        $this->writeTemporaryFiles(base_path('routes/domains'), $files);
    }

    /**
     * @param  array<string, string>  $files  Paths relative to resources/domains.
     */
    protected function setDomainAssets(array $files): void
    {
        $this->writeTemporaryFiles(base_path('resources/domains'), $files);
    }

    private function writeTemporaryFiles(string $root, array $files): void
    {
        $fs = new Filesystem;

        foreach ($files as $name => $content) {
            $path = "{$root}/{$name}";

            foreach ([$root, dirname($path)] as $directory) {
                if (! is_dir($directory)) {
                    $fs->makeDirectory($directory, 0755, true);
                    $this->temporaryDirectories[] = $directory;
                }
            }

            $fs->put($path, $content);
            $this->temporaryFiles[] = $path;
        }
    }
```

Replace the `$this->temporaryRouteFiles` block inside `tearDown()` with:

```php
        $fs = new Filesystem;

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                $fs->delete($file);
            }
        }

        $this->temporaryFiles = [];

        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            if (is_dir($directory) && $fs->isEmptyDirectory($directory)) {
                $fs->deleteDirectory($directory);
            }
        }

        $this->temporaryDirectories = [];
```

Note: Testbench's `base_path()` points into the skeleton inside `vendor/`, and Laravel has no `useResourcePath()` to redirect it the way `setRegisteredDomains()` uses `useConfigPath()`. This helper therefore writes into that skeleton and deletes exactly what it created — the same trade-off already accepted for `setDomainRouteFiles()`. `resource_path('domains')` and `base_path('resources/domains')` resolve to the same directory, which is why the helper and the production code agree.

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/DomainAssetsTest.php`:

```php
<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Support\DomainAssets;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class DomainAssetsTest extends TestCase
{
    public function test_it_reads_a_file_the_domain_owns(): void
    {
        $this->setDomainAssets(['example_com/ads.txt' => "google.com, pub-1, DIRECT\n"]);

        $this->assertSame(
            'google.com, pub-1, DIRECT',
            DomainAssets::contents('example.com', 'ads.txt'),
        );
    }

    public function test_it_returns_null_when_the_domain_has_no_such_file(): void
    {
        $this->assertNull(DomainAssets::contents('example.com', 'ads.txt'));
    }

    public function test_an_empty_file_is_indistinguishable_from_a_missing_one(): void
    {
        $this->setDomainAssets(['example_com/ads.txt' => "   \n\n"]);

        $this->assertNull(DomainAssets::contents('example.com', 'ads.txt'));
    }

    public function test_each_domain_reads_its_own_file(): void
    {
        $this->setDomainAssets([
            'example_com/ads.txt' => 'google.com, pub-1, DIRECT',
            'other_com/ads.txt' => 'google.com, pub-2, DIRECT',
        ]);

        $this->assertSame('google.com, pub-1, DIRECT', DomainAssets::contents('example.com', 'ads.txt'));
        $this->assertSame('google.com, pub-2, DIRECT', DomainAssets::contents('other.com', 'ads.txt'));
    }

    public function test_the_path_uses_the_directory_safe_domain_key(): void
    {
        $this->assertSame(
            resource_path('domains/my-sample-blog_co_uk/robots.txt'),
            DomainAssets::path('my-sample-blog.co.uk', 'robots.txt'),
        );
    }

    public function test_a_host_that_is_not_a_hostname_cannot_escape_the_assets_directory(): void
    {
        $this->assertStringNotContainsString('..', DomainAssets::path('../../etc', 'passwd'));
        $this->assertNull(DomainAssets::contents('../../etc', 'passwd'));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest --filter=DomainAssetsTest`
Expected: FAIL with `Class "MrSonj\MultiDomainGhost\Support\DomainAssets" not found`.

- [ ] **Step 4: Write the implementation**

Create `src/Support/DomainAssets.php`:

```php
<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

/**
 * The static files a single domain owns, under resources/domains/{domain_key}/.
 *
 * The third of this package's three per-domain conventions, alongside
 * config/domains/ and routes/domains/. Kept apart from configuration because
 * robots.txt and ads.txt are text files with formats of their own, not values.
 */
final class DomainAssets
{
    public static function path(string $domain, string $file): string
    {
        return resource_path('domains/'.DomainName::dirKey($domain).'/'.$file);
    }

    /**
     * The file's trimmed contents - null when the domain has no such file, and
     * null when the file is empty.
     *
     * The two collapse deliberately. An empty ads.txt served with a 200 reads as
     * "this domain authorises no sellers", which is not the claim a domain
     * without an ads.txt is making, so an empty file must not produce a response.
     */
    public static function contents(string $domain, string $file): ?string
    {
        $path = self::path($domain, $file);

        if (! is_file($path)) {
            return null;
        }

        $contents = trim((string) file_get_contents($path));

        return $contents === '' ? null : $contents;
    }
}
```

`$file` is always a literal supplied by this package, never user input. `$domain` may originate from an unvalidated `Host` header, but `DomainName::normalize()` (called inside `dirKey()`) returns an empty string for anything that is not shaped like a hostname — `../../etc` becomes `''` and `a/../../b` becomes `a` — so no traversal reaches the filesystem.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest`
Expected: PASS — 160 previous tests plus 6 new ones.

- [ ] **Step 6: Lint and commit**

```bash
"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pint
git add src/Support/DomainAssets.php tests/Unit/DomainAssetsTest.php tests/TestCase.php
git commit -m "feat: add DomainAssets for per-domain static files"
```

---

### Task 2: `/ads.txt` resolves from the domain's own file

**Files:**
- Modify: `src/Http/Controllers/GhostController.php` (the `ads()` method, currently at lines 183-190)
- Modify: `src/Routing/GhostRouteRegistrar.php` (the group closure at line 65 and the `adsTxtContent()` method at lines 153-165)
- Modify: `tests/Feature/GhostDomainRoutesTest.php`
- Modify: `tests/Feature/GhostControllerConfigurationTest.php`

**Interfaces:**
- Consumes: `DomainAssets::contents(string $domain, string $file): ?string` and `TestCase::setDomainAssets(array $files): void` from Task 1.
- Produces: `GhostController::ads(): Response` now aborts with 404 instead of returning an empty body. `GhostRouteRegistrar::adsTxtContent()` no longer exists.

Controller and registrar change together: they share the configuration key being removed, so splitting them would leave the suite red between tasks.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/GhostControllerConfigurationTest.php`, add the import:

```php
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
```

Delete these two methods entirely:

```php
    public function test_ads_txt_reads_from_the_package_config(): void
    public function test_ads_txt_still_honours_the_legacy_services_key(): void
```

Add in their place:

```php
    public function test_ads_txt_reads_the_domains_own_file(): void
    {
        $this->setDomainAssets(['example_com/ads.txt' => "google.com, pub-1, DIRECT, f08c\n"]);

        $this->assertSame('google.com, pub-1, DIRECT, f08c', $this->controller()->ads()->getContent());
    }

    public function test_ads_txt_is_not_found_when_the_domain_has_no_file(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->ads();
    }

    public function test_ads_txt_files_are_isolated_per_domain(): void
    {
        $this->setDomainAssets(['example_com/ads.txt' => 'google.com, pub-1, DIRECT']);

        $this->assertSame('google.com, pub-1, DIRECT', $this->controller('example.com')->ads()->getContent());

        $this->expectException(NotFoundHttpException::class);
        $this->controller('other.com')->ads();
    }
```

In `tests/Feature/GhostDomainRoutesTest.php`, make these edits.

In `test_macro_registers_all_ghost_routes_for_domain` (line 37), replace:

```php
        config()->set('multidomain-ghost.ads.txt', 'test');
```

with:

```php
        $this->setDomainAssets(['example_com/ads.txt' => 'google.com, pub-1, DIRECT']);
```

In `test_macro_handles_domains_with_hyphens` (line 53), replace the same line with:

```php
        $this->setDomainAssets(['my-sample-blog_co_uk/ads.txt' => 'google.com, pub-1, DIRECT']);
```

Replace `test_ads_txt_route_is_not_registered_when_config_is_empty` with:

```php
    public function test_ads_txt_route_is_not_registered_when_the_domain_has_no_ads_file(): void
    {
        $this->setRegisteredDomains(['example_com' => []]);
        GhostRouteRegistrar::registerAll();

        $this->get('https://example.com/ads.txt')->assertNotFound();
    }
```

Replace `test_ads_txt_route_is_registered_when_package_config_is_present` with:

```php
    public function test_ads_txt_route_is_registered_when_the_domain_has_an_ads_file(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $this->setDomainAssets(['example_com/ads.txt' => 'google.com, pub-123, DIRECT']);

        $this->setRegisteredDomains(['example_com' => []]);
        GhostRouteRegistrar::registerAll();

        $response = $this->get('https://example.com/ads.txt');

        $response->assertOk();
        $response->assertSee('google.com, pub-123, DIRECT', false);
        $this->assertSame('text/plain;charset=UTF-8', $response->headers->get('Content-Type'));
    }
```

Delete `test_ads_txt_route_is_registered_when_legacy_config_is_present` entirely — the `services.adsense.ads_txt` fallback is gone.

Replace `test_ads_txt_route_is_not_registered_when_an_explicit_path_has_no_content` with:

```php
    public function test_ads_txt_route_is_not_registered_when_an_explicit_path_has_no_file(): void
    {
        config()->set('multidomain-ghost.routes.paths.ads', '/ads.txt');

        $this->setRegisteredDomains(['example_com' => []]);
        GhostRouteRegistrar::registerAll();

        $this->assertFalse(Route::has('example_com_ads'));
        $this->get('https://example.com/ads.txt')->assertNotFound();
    }
```

Replace `test_ads_txt_route_honours_an_explicit_path_when_content_is_present` with:

```php
    public function test_ads_txt_route_honours_an_explicit_path_when_a_file_is_present(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('multidomain-ghost.routes.paths.ads', '/app-ads.txt');
        $this->setDomainAssets(['example_com/ads.txt' => 'google.com, pub-789, DIRECT']);

        $this->setRegisteredDomains(['example_com' => []]);
        GhostRouteRegistrar::registerAll();

        $this->assertSame('app-ads.txt', Route::getRoutes()->getByName('example_com_ads')->uri());
        $this->get('https://example.com/app-ads.txt')->assertOk()->assertSee('pub-789', false);
    }
```

In `test_a_path_set_to_null_disables_only_that_route`, replace:

```php
        // ads.txt is registered only when it has a body, so give it one: without this
        // the loop below would pass for the wrong reason.
        config()->set('multidomain-ghost.ads.txt', 'google.com, pub-123, DIRECT');
```

with:

```php
        // ads.txt is registered only for a domain that owns the file, so give it one:
        // without this the loop below would pass for the wrong reason.
        $this->setDomainAssets(['example_com/ads.txt' => 'google.com, pub-123, DIRECT']);
```

In `test_ads_path_set_to_null_disables_the_route`, replace:

```php
        config()->set('multidomain-ghost.ads.txt', 'google.com, pub-123, DIRECT');
```

with:

```php
        $this->setDomainAssets(['example_com/ads.txt' => 'google.com, pub-123, DIRECT']);
```

Finally add the test that proves the per-domain fix — place it directly after `test_ads_txt_route_is_registered_when_the_domain_has_an_ads_file`:

```php
    public function test_ads_route_registration_is_independent_per_domain(): void
    {
        $this->setDomainAssets(['example_com/ads.txt' => 'google.com, pub-123, DIRECT']);

        $this->setRegisteredDomains(['example_com' => [], 'other_com' => []]);
        GhostRouteRegistrar::registerAll();

        $this->assertTrue(Route::has('example_com_ads'));
        $this->assertFalse(Route::has('other_com_ads'));
    }
```

This is the test that cannot pass against the old `adsTxtContent()`: that method read one configuration value once and handed the same answer to every domain in the loop.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest --filter="GhostDomainRoutesTest|GhostControllerConfigurationTest"`
Expected: FAIL — `test_ads_route_registration_is_independent_per_domain` fails because `other_com_ads` is registered too, and the file-backed tests 404 because the controller still reads configuration.

- [ ] **Step 3: Change the registrar**

In `src/Routing/GhostRouteRegistrar.php`, add the import next to the other `Support` imports:

```php
use MrSonj\MultiDomainGhost\Support\DomainAssets;
```

Give the route group access to the domain it is registering — replace:

```php
            ->group(function () use ($routeNamePrefix, $routes) {
```

with:

```php
            ->group(function () use ($domain, $routeNamePrefix, $routes) {
```

Replace the ads registration condition:

```php
                if (isset($paths['ads']) && is_string($paths['ads']) && self::adsTxtContent() !== '') {
```

with:

```php
                // Decided from this domain's own file rather than from configuration:
                // configuration only ever reflects the domain active in this process,
                // so reading it here would hand one domain's answer to all the others.
                if (isset($paths['ads']) && is_string($paths['ads'])
                    && DomainAssets::contents($domain, 'ads.txt') !== null) {
```

Delete the entire `adsTxtContent()` method together with its docblock (the last member of the class, lines 153-165).

- [ ] **Step 4: Change the controller**

In `src/Http/Controllers/GhostController.php`, add the import next to `use MrSonj\MultiDomainGhost\Support\Domain;`:

```php
use MrSonj\MultiDomainGhost\Support\DomainAssets;
```

Replace the whole `ads()` method:

```php
    public function ads(): Response
    {
        $ads = config('multidomain-ghost.ads.txt')
            ?: config('services.adsense.ads_txt', '');

        return response(trim((string) $ads))->header('Content-Type', 'text/plain;charset=UTF-8');
    }
```

with:

```php
    /**
     * The domain's own ads.txt, served verbatim.
     *
     * Verbatim because ads.txt is an IAB format: any rewriting of it is a risk.
     * 404 rather than an empty 200 because an empty ads.txt is itself a claim -
     * that the domain authorises no sellers - which a domain without the file is
     * not making. The registrar leaves the route unregistered in that case; this
     * guard covers a route the application declared for itself.
     */
    public function ads(): Response
    {
        $ads = DomainAssets::contents($this->domain, 'ads.txt');

        if ($ads === null) {
            abort(404);
        }

        return response($ads)->header('Content-Type', 'text/plain;charset=UTF-8');
    }
```

- [ ] **Step 5: Run the full suite**

Run: `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest`
Expected: PASS. `config/multidomain-ghost.php` still declares the `ads` block at this point, but nothing reads it — Task 4 removes it.

- [ ] **Step 6: Lint and commit**

```bash
"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pint
git add src/Routing/GhostRouteRegistrar.php src/Http/Controllers/GhostController.php tests/Feature/GhostDomainRoutesTest.php tests/Feature/GhostControllerConfigurationTest.php
git commit -m "feat!: resolve ads.txt from resources/domains/{key}/ads.txt per domain"
```

---

### Task 3: A domain's `robots.txt` replaces the generated body

**Files:**
- Modify: `src/Http/Controllers/GhostController.php` (the `robots()` method, currently at lines 155-181)
- Modify: `tests/Feature/GhostControllerConfigurationTest.php`

**Interfaces:**
- Consumes: `DomainAssets::contents()` and `TestCase::setDomainAssets()` from Task 1; the `DomainAssets` import added to the controller in Task 2.
- Produces: no signature change. `GhostController::robots(): Response` keeps returning `text/plain;charset=UTF-8`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/GhostControllerConfigurationTest.php`:

```php
    public function test_a_domain_robots_file_replaces_the_generated_body(): void
    {
        $this->setDomainAssets([
            'example_com/robots.txt' => "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nAllow: /\n",
        ]);

        $robots = $this->controller()->robots()->getContent();

        $this->assertSame("User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nAllow: /", $robots);
        $this->assertStringNotContainsString('Sitemap:', $robots);
        $this->assertStringNotContainsString('/cdn-cgi/', $robots);
    }

    public function test_robots_falls_back_to_the_generated_body_without_a_file(): void
    {
        $robots = $this->controller()->robots()->getContent();

        $this->assertStringContainsString('User-agent: *', $robots);
        $this->assertStringContainsString('Disallow: /cdn-cgi/', $robots);
        $this->assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $robots);
    }

    public function test_robots_files_are_isolated_per_domain(): void
    {
        $this->setDomainAssets(['example_com/robots.txt' => "User-agent: GPTBot\nDisallow: /"]);

        $this->assertStringNotContainsString('Sitemap:', $this->controller('example.com')->robots()->getContent());
        $this->assertStringContainsString('Sitemap:', $this->controller('other.com')->robots()->getContent());
    }

    public function test_a_domain_robots_file_keeps_the_plain_text_content_type(): void
    {
        $this->setDomainAssets(['example_com/robots.txt' => 'User-agent: *']);

        $this->assertSame(
            'text/plain;charset=UTF-8',
            $this->controller()->robots()->headers->get('Content-Type'),
        );
    }
```

The first test asserts the exact body, including the absence of the `Sitemap:` line. That absence is the whole point of "replaces": a domain that supplies its own robots.txt owns every line of it.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest --filter=GhostControllerConfigurationTest`
Expected: FAIL — the generated body is returned regardless of the file, so `assertSame` and the `Sitemap:` assertions fail.

- [ ] **Step 3: Write the implementation**

In `src/Http/Controllers/GhostController.php`, insert the file check at the top of `robots()`. Replace:

```php
    public function robots(): Response
    {
        $lines = ['User-agent: *'];
```

with:

```php
    /**
     * The domain's robots policy.
     *
     * A resources/domains/{domain_key}/robots.txt replaces this method's output
     * wholesale rather than being appended to it: appending would keep imposing
     * the package's own lines on every domain, which is the coupling the file is
     * there to break. The file's author owns the Sitemap: line too.
     */
    public function robots(): Response
    {
        $file = DomainAssets::contents($this->domain, 'robots.txt');

        if ($file !== null) {
            return response($file)->header('Content-Type', 'text/plain;charset=UTF-8');
        }

        $lines = ['User-agent: *'];
```

Leave the rest of the method exactly as it is.

- [ ] **Step 4: Run the full suite**

Run: `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest`
Expected: PASS, including the untouched `LegacyConfigCompatibilityTest`, which replaces the whole `robots` config block and still expects a generated body.

- [ ] **Step 5: Lint and commit**

```bash
"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pint
git add src/Http/Controllers/GhostController.php tests/Feature/GhostControllerConfigurationTest.php
git commit -m "feat: let a domain's robots.txt replace the generated policy"
```

---

### Task 4: Clean up the published configuration

**Files:**
- Modify: `config/multidomain-ghost.php` (lines 72-87)
- Create: `tests/Unit/PublishedConfigTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `multidomain-ghost.ads` no longer exists. `multidomain-ghost.robots.content_signal` now reads `GHOST_ROBOTS_CONTENT_SIGNAL` instead of `ROBOTS_CONTENT_SIGNAL`.

Nothing has read `multidomain-ghost.ads.txt` since Task 2, so removing it now cannot break a test.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/PublishedConfigTest.php`:

```php
<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Tests\TestCase;

class PublishedConfigTest extends TestCase
{
    private function publishedConfig(): array
    {
        return require __DIR__.'/../../config/multidomain-ghost.php';
    }

    public function test_it_no_longer_ships_a_shared_ads_block(): void
    {
        $this->assertArrayNotHasKey('ads', $this->publishedConfig());
    }

    public function test_the_content_signal_env_key_carries_the_package_prefix(): void
    {
        putenv('GHOST_ROBOTS_CONTENT_SIGNAL=search=yes,ai-train=no');

        try {
            $this->assertSame(
                'search=yes,ai-train=no',
                $this->publishedConfig()['robots']['content_signal'],
            );
        } finally {
            putenv('GHOST_ROBOTS_CONTENT_SIGNAL');
        }
    }

    public function test_the_unprefixed_content_signal_env_key_is_no_longer_read(): void
    {
        putenv('ROBOTS_CONTENT_SIGNAL=search=yes');

        try {
            $this->assertSame('', $this->publishedConfig()['robots']['content_signal']);
        } finally {
            putenv('ROBOTS_CONTENT_SIGNAL');
        }
    }

    public function test_the_robots_and_seo_defaults_are_still_shipped(): void
    {
        $config = $this->publishedConfig();

        $this->assertSame(['/cdn-cgi/'], $config['robots']['disallow']);
        $this->assertSame('https://{domain}/sitemap.xml', $config['robots']['sitemap']);
        $this->assertSame(
            'https://{domain}/img/{domain_key}/apple-touch-icon.png',
            $config['seo']['default_image'],
        );
    }
}
```

`putenv()` reaches Laravel's `env()` helper here — verified against this repository's `config/multidomain-ghost.php`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest --filter=PublishedConfigTest`
Expected: FAIL — the `ads` key is still present and `GHOST_ROBOTS_CONTENT_SIGNAL` is not read.

- [ ] **Step 3: Edit the config file**

In `config/multidomain-ghost.php`, replace this block:

```php
    // {domain} and {domain_key} expand to the active hostname and its
    // directory-safe form (example.com / example_com).
    'seo' => [
        'default_image' => env(
            'GHOST_SEO_DEFAULT_IMAGE',
            'https://{domain}/img/{domain_key}/apple-touch-icon.png',
        ),
    ],
    'robots' => [
        'content_signal' => env('ROBOTS_CONTENT_SIGNAL', ''),
        'sitemap' => env('GHOST_ROBOTS_SITEMAP', 'https://{domain}/sitemap.xml'),
        'disallow' => ['/cdn-cgi/'],
    ],
    'ads' => [
        'txt' => env('GHOST_ADS_TXT', ''),
    ],
```

with:

```php
    // {domain} and {domain_key} expand to the active hostname and its
    // directory-safe form (example.com / example_com). Every key below is a
    // default: override any of them per domain in config/domains/{domain_key}.php.
    'seo' => [
        'default_image' => env(
            'GHOST_SEO_DEFAULT_IMAGE',
            'https://{domain}/img/{domain_key}/apple-touch-icon.png',
        ),
    ],
    // Consulted only when the domain has no resources/domains/{domain_key}/robots.txt.
    // That file, when present, replaces this whole block.
    'robots' => [
        'content_signal' => env('GHOST_ROBOTS_CONTENT_SIGNAL', ''),
        'sitemap' => env('GHOST_ROBOTS_SITEMAP', 'https://{domain}/sitemap.xml'),
        'disallow' => ['/cdn-cgi/'],
    ],
    // There is deliberately no 'ads' block. An ads.txt belongs to one publisher
    // account, so no value here could be shared across domains honestly. Each
    // domain's file lives at resources/domains/{domain_key}/ads.txt.
```

- [ ] **Step 4: Run the full suite**

Run: `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pint
git add config/multidomain-ghost.php tests/Unit/PublishedConfigTest.php
git commit -m "feat!: drop the shared ads config block and prefix the content-signal env key"
```

---

### Task 5: `domain:add` scaffolds the assets directory

**Files:**
- Modify: `src/Console/Commands/GhostDomainAddCommand.php` (between the view step at lines 128-136 and the CSS step that follows)
- Modify: `tests/Feature/DomainCommandsTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks — the command only creates a directory.
- Produces: `resources/domains/{domain_key}/` exists after `php artisan domain:add {domain}`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DomainCommandsTest.php`:

```php
    public function test_domain_add_creates_the_per_domain_assets_directory(): void
    {
        $this->artisan('domain:add', ['domain' => 'example.com'])->assertSuccessful();

        $assetsDirectory = $this->basePath.'/resources/domains/example_com';

        $this->assertDirectoryExists($assetsDirectory);

        // Deliberately no stubs: a stub robots.txt would silently switch the domain
        // off generated output and lose its Sitemap: line, and an empty ads.txt is a
        // false claim about seller authorisation.
        $this->assertFileDoesNotExist("{$assetsDirectory}/robots.txt");
        $this->assertFileDoesNotExist("{$assetsDirectory}/ads.txt");
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest --filter=test_domain_add_creates_the_per_domain_assets_directory`
Expected: FAIL — "Failed asserting that directory ... exists".

- [ ] **Step 3: Add the step to the command**

In `src/Console/Commands/GhostDomainAddCommand.php`, immediately after this existing block:

```php
        // 4. Create view folder & scaffold views
        $viewDir = resource_path("views/{$sanitized}");
        if (! is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
            $this->line("<info>✓ View folder created:</info> resources/views/{$sanitized}");
        }
        $this->scaffoldViews($sanitized, $domain);
```

insert:

```php
        // 5. Create the per-domain assets folder for robots.txt and ads.txt.
        // No stub files: a stub robots.txt would switch this domain off generated
        // output - Sitemap: line included - without anyone noticing, and an empty
        // ads.txt claims the domain authorises no sellers.
        $assetsDir = resource_path("domains/{$sanitized}");
        if (! is_dir($assetsDir)) {
            mkdir($assetsDir, 0755, true);
            $this->line("<info>✓ Assets folder created:</info> resources/domains/{$sanitized}");
        }
        $this->line('  <comment>Put ads.txt there to publish it; a robots.txt there replaces the generated one, Sitemap: line included.</comment>');
```

Then renumber the three comments that follow so the sequence stays readable:

- `// 5. Create CSS file` → `// 6. Create CSS file`
- `// 6. Auto-inject CSS entry into vite.config.js` → `// 7. Auto-inject CSS entry into vite.config.js`
- `// 7. Auto-update local Herd config if present` → `// 8. Auto-update local Herd config if present`

- [ ] **Step 4: Run the full suite**

Run: `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest`
Expected: PASS.

- [ ] **Step 5: Lint and commit**

```bash
"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pint
git add src/Console/Commands/GhostDomainAddCommand.php tests/Feature/DomainCommandsTest.php
git commit -m "feat: scaffold resources/domains/{key} in domain:add"
```

---

### Task 6: Document the convention and the upgrade path

**Files:**
- Modify: `README.md` (lines 55-57, the "What you get" table at lines 79-88, and the "Per-domain configuration" section starting at line 175)
- Modify: `CHANGELOG.md` (the `## 2.0.0 - 2026-08-26` section)

**Interfaces:**
- Consumes: the behaviour built in Tasks 1-5.
- Produces: nothing code depends on.

- [ ] **Step 1: Correct the quick-start paragraph**

In `README.md`, replace:

```markdown
The standard files
(`/robots.txt`, `/sitemap.xml`, `/ads.txt`) are registered for every domain without you declaring
them. See [Route customization](#route-customization--explicit-declaration).
```

with:

```markdown
The standard files
(`/robots.txt`, `/sitemap.xml`) are registered for every domain without you declaring them, and
`/ads.txt` is registered for every domain that owns one. See
[Route customization](#route-customization--explicit-declaration) and
[Per-domain robots.txt and ads.txt](#per-domain-robotstxt-and-adstxt).
```

- [ ] **Step 2: Correct the "What you get" table**

Replace these two rows:

```markdown
| `/robots.txt` | `robots` | Plain-text robots policy. |
```

```markdown
| `/ads.txt` | `ads` | Plain-text ads configuration. |
```

with:

```markdown
| `/robots.txt` | `robots` | `resources/domains/{domain_key}/robots.txt` verbatim, or a generated policy. |
```

```markdown
| `/ads.txt` | `ads` | `resources/domains/{domain_key}/ads.txt` verbatim. Registered only for domains that have one. |
```

- [ ] **Step 3: Document the new convention**

In `README.md`, in the `## Per-domain configuration` section, replace:

```markdown
Route paths are the one thing this cannot override — see
[Route customization](#route-customization--explicit-declaration).
```

with:

```markdown
Route paths are the one thing this cannot override — see
[Route customization](#route-customization--explicit-declaration).

### Per-domain robots.txt and ads.txt

`resources/domains/{domain_key}/` is the third per-domain convention, alongside `config/domains/`
and `routes/domains/`. `php artisan domain:add` creates the directory; you add the files you want:

```
resources/domains/example_com/
├── ads.txt
└── robots.txt
```

**`ads.txt`** is served verbatim, and comes from this file only — there is no shared configuration
value, because an ads.txt belongs to one publisher account. `/ads.txt` is registered only for
domains that own the file. A missing or empty file leaves the route unregistered rather than
serving an empty body: an empty ads.txt returned with a 200 claims the domain authorises no
sellers, which is not the claim a domain without an ads.txt is making.

**`robots.txt`**, when present, **replaces** the generated policy entirely — the `Sitemap:` line
included, which you then write yourself:

```
User-agent: GPTBot
Disallow: /

User-agent: *
Allow: /
Disallow: /admin/

Sitemap: https://example.com/sitemap.xml
```

Without that file, robots.txt is generated from configuration, and those keys are per-domain
overridable like any other — as is the SEO fallback image:

```php
// config/domains/example_com.php
return [
    'multidomain-ghost.robots.disallow' => ['/cdn-cgi/', '/internal/'],
    'multidomain-ghost.robots.content_signal' => 'search=yes,ai-train=no',
    'multidomain-ghost.robots.sitemap' => 'https://example.com/sitemap.xml',
    'multidomain-ghost.seo.default_image' => 'https://cdn.example.net/example_com/social.png',
];
```

The rest of a domain's SEO needs no configuration: `app.name` and `app.url` come from the file
above, and `og:site_name`, `twitter:site` and the locale are read from the JSON in the description
of the domain's Ghost primary tag, so editors change them without a deploy.
```

- [ ] **Step 4: Record the breaking changes**

In `CHANGELOG.md`, under `## 2.0.0 - 2026-08-26`, add to the existing `### Breaking` list:

```markdown
- `ads.txt` is now read from `resources/domains/{domain_key}/ads.txt` only. The `multidomain-ghost.ads.txt` config value (`GHOST_ADS_TXT`) and the legacy `services.adsense.ads_txt` fallback are gone, and `/ads.txt` is registered only for domains that own the file. Move each domain's content into its own file, or the route returns 404.
- `multidomain-ghost.robots.content_signal` now reads `GHOST_ROBOTS_CONTENT_SIGNAL` instead of `ROBOTS_CONTENT_SIGNAL`. Rename the variable in `.env` or the `Content-Signal:` line disappears.
```

Add a new `### Added` section directly after `### Breaking`:

```markdown
### Added

- `resources/domains/{domain_key}/` for a domain's own static files, alongside `config/domains/` and `routes/domains/`. A `robots.txt` there replaces the generated policy in full, `Sitemap:` line included; an `ads.txt` there is served verbatim. `php artisan domain:add` creates the directory.
- `MrSonj\MultiDomainGhost\Support\DomainAssets` for reading those files.
```

Add to the existing `### Removed` list:

```markdown
- Removed the `ads` block from `config/multidomain-ghost.php`.
- Removed the private method `GhostRouteRegistrar::adsTxtContent()`.
```

- [ ] **Step 5: Verify the docs match the code**

Run: `"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest`
Expected: PASS — 176 tests: the 160 baseline, plus 19 added across Tasks 1-5, minus the 3 deleted in Task 2 (6 + 1 + 4 + 4 + 1 net across the five tasks).

Then confirm no stale references survive:

```bash
grep -rn "GHOST_ADS_TXT\|adsense\|ROBOTS_CONTENT_SIGNAL\|multidomain-ghost.ads" README.md CHANGELOG.md config/ src/ tests/
```

Expected: only the `GHOST_ROBOTS_CONTENT_SIGNAL` occurrences in `config/multidomain-ghost.php`, `tests/Unit/PublishedConfigTest.php` and `CHANGELOG.md`, plus the upgrade notes in `CHANGELOG.md` that mention the removed names on purpose.

- [ ] **Step 6: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "docs: per-domain robots.txt and ads.txt convention"
```

---

## Verification

After Task 6, confirm the whole change with a fresh full run before proposing a merge:

```bash
"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pest
"/Users/sonjj/Library/Application Support/Herd/bin/php84" vendor/bin/pint --test
```

Both must pass. Report the actual test count rather than asserting success from memory.
