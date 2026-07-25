# Laravel Multi-Domain Ghost

A lightweight Laravel package combining **Multi-Domain Architecture** with **Headless Ghost CMS integration**. It enables a single Laravel application to serve multiple isolated domains, fetching and caching content from Ghost CMS per domain.

---

## 🌟 Key Features

- 🌐 **Multi-Domain Isolation**: Per-domain storage (`storage/{domain_com}`), config overrides (`config/domains/{domain_com}.php`), and CLI `--domain` context.
- ⚡ **Zero-Manual-Config Automation**: Automated package setup (`php artisan ghost:install`) and 100% automated domain scaffolding (`php artisan domain:add {domain}`).
- 👻 **Headless Ghost CMS Integration**: Ghost Content API client (with optional local Admin API) filtering content by domain tag (`#domain-com`) and `canonical_url`.
- 🏷️ **Posts, Pages & `#page` Filtering**: Automatic fallback between Ghost `posts` and `pages` by `canonical_url`, plus auto-exclusion of `#page` tagged items from blog lists.
- ⚡ **Domain-Aware Caching & Webhooks**: Signed webhook (`POST /webhook/ghost/post`) for instant cache invalidation upon Ghost updates.
- 🎨 **Flexible Routing & Views**: Route Ghost pages directly to Blade views (`defaults('viewPath', '...')`) with neutral SEO metadata arrays.
- 🔌 **Extension Hooks**: Inject custom data into pages via `DomainEnricher` or transform Ghost HTML via `ContentTransformer`.

---

## 🚀 Quick Start

### 1. Install Package

```bash
composer require mr-sonj/laravel-multidomain-ghost
```

### 2. Run Automated Setup

Run the single setup command to automate all configuration steps:

```bash
php artisan ghost:install
```

**What `ghost:install` automates:**
- 📄 Publishes `config/multidomain-ghost.php`.
- 🚀 Patches `bootstrap/app.php` to use the package's multi-domain `Application` class.
- 🔑 Appends required `GHOST_URL`, `GHOST_CONTENT_KEY`, and `GHOST_WEBHOOK_SECRET` stubs to `.env` and `.env.example`.
- 🌐 Creates the `config/domain.php` domain registry file.

---

## ⚙️ How It Works

### Domain Keying, Ghost Tags & Canonical URLs

Every post or page authored in Ghost CMS requires 3 key attributes:

| Ghost Attribute | Type / Format | Example | Purpose |
| :--- | :--- | :--- | :--- |
| **`canonical_url`** | Full Target URL | `https://example.com/about` | Used by `GhostController::page` to match the exact Laravel request URL. |
| **Domain Tag** | Internal Tag (`#`) | `#example-com` (slug: `hash-example-com`) | Scopes content exclusively to `example.com` (dots ➔ hyphens). |
| **Type Tag** | Internal Tag (`#`) | `#page` (slug: `hash-page`) | Marks static pages (About, Terms, FAQ). Automatically excluded from blog lists. |

#### Posts vs Pages & Blog Listings
- **Universal Lookup**: `GhostController::page` searches both Ghost `posts` and `pages` endpoints automatically using the request's `canonical_url`.
- **Blog Feed Exclusion**: `GhostContentService::dataBlog()` filters out content tagged with `#page` (`tag:-hash-page`), ensuring static pages do not clutter your blog post listings.

---

## 🛠️ Usage

### 1. Adding a New Domain (100% Automated)

To add a new domain to your application, run:

```bash
php artisan domain:add example.com
```

**What `domain:add` automates automatically:**
- 📁 **Storage**: Creates `storage/example_com/` subdirectories for logs, views, sessions, and cache.
- ⚙️ **Config Override**: Generates `config/domains/example_com.php` for per-domain overrides.
- 🎨 **Views Scaffold**: Creates `resources/views/example_com/` directory along with `main.blade.php` layout and `home.blade.php` views.
- 💅 **CSS**: Generates `resources/css/example_com.css`.
- ⚡ **Vite Integration**: Auto-injects `'resources/css/example_com.css'` into the `input` array of `vite.config.js`.
- 🛣️ **Route Scaffolding**: Auto-injects a complete `Route::domain('example.com')` route group with Ghost system routes (`/robots.txt`, `/sitemap.xml`, `/feed`, `/ads.txt`, `/`) into `routes/web.php`.
- 🌐 **Local Web Server**: Updates `server_name` in `_setup/multi_domain_local_herd.conf` (if present).
- 📑 **Registry**: Registers the domain in `config/domain.php`.

#### Additional CLI Commands

```bash
# List all registered domains & storage status
php artisan domain:list

# Check current active domain
php artisan domain --domain=example.com

# Unregister a domain without deleting its config override
php artisan domain:remove example.com

# Run queue workers or commands scoped to a specific domain
php artisan queue:work --domain=example.com
php artisan optimize --domain=example.com
```

### 2. Route Configuration (`routes/web.php`)

Each domain registered in `config/domain.php` gets its own domain group in `routes/web.php`. Use `GhostController::page` with `.defaults('viewPath', '...')` to map Ghost CMS pages to specific Blade views:

```php
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;
use App\Http\Controllers\example_com\ExampleController;

Route::domain('example.com')->group(function () {
    // 🌐 System & SEO Routes
    Route::get('/ads.txt', [GhostController::class, 'ads']);
    Route::get('/robots.txt', [GhostController::class, 'robots'])->name('example-robots');
    Route::get('/sitemap.xml', [GhostController::class, 'sitemap'])->name('example-sitemap');
    Route::get('/feed', [GhostController::class, 'feed'])->name('example-feed');

    // 📄 Ghost Content Routes (mapped to Blade views via viewPath)
    Route::get('/', [GhostController::class, 'page'])->defaults('viewPath', 'example_com/home')->name('example-home');
    Route::get('/about', [GhostController::class, 'page'])->defaults('viewPath', 'example_com/page')->name('example-about');
    Route::get('/contact', [GhostController::class, 'page'])->defaults('viewPath', 'example_com/contact')->name('example-contact');

    // 📰 Blog Listing & Post Detail Routes
    Route::prefix('/blog')->group(function () {
        Route::get('/', [GhostController::class, 'page'])->defaults('viewPath', 'example_com/blog')->name('example-blog');
        Route::get('/{slug}', [GhostController::class, 'page'])->defaults('viewPath', 'example_com/post')->name('example-post');
    });

    // ⚡ Custom Domain-Specific Application Routes
    Route::prefix('/app')->group(function () {
        Route::post('/submit', [ExampleController::class, 'submit']);
    });
});
```

*Note: If `viewPath` is omitted, `GhostController::page` renders the package's default fallback view (`multidomain-ghost::page`).*

---

### 3. Multi-Domain Directory Structure & Layout Inheritance

#### Multi-Domain Application Directory Tree

The multi-domain architecture isolates views, configuration overrides, storage, CSS assets, controllers, and enrichers per domain key (domain dots converted to underscores, e.g. `example.com` ➔ `example_com`):

```text
my-laravel-app/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       ├── GhostController.php            # Universal Ghost content controller
│   │       └── example_com/                   # Domain controller namespace: App\Http\Controllers\example_com
│   │           └── ExampleController.php
│   └── Services/
│       └── example_com/                       # Domain service namespace: App\Services\example_com
│           └── ExampleComEnricher.php         # Custom DomainEnricherInterface implementation
├── config/
│   ├── domain.php                             # Domain registry file
│   └── domains/                               # Per-domain configuration overrides
│       ├── example_com.php                    # Flat dot-notation config overrides for example.com
│       └── seconddomain_com.php
├── resources/
│   ├── css/                                   # Per-domain CSS files (Vite inputs)
│   │   ├── example_com.css
│   │   └── seconddomain_com.css
│   └── views/                                 # Per-domain Blade views
│       ├── example_com/                       # Views for example.com
│       │   ├── main.blade.php                 # Domain layout wrapper
│       │   ├── home.blade.php                 # Home view (viewPath: 'example_com/home')
│       │   ├── page.blade.php                 # Generic static page view (About, Terms, FAQ)
│       │   ├── blog.blade.php                 # Blog listing view
│       │   ├── post.blade.php                 # Single post view
│       │   └── contact.blade.php              # Contact view
│       └── seconddomain_com/                  # Views for seconddomain.com
│           ├── main.blade.php
│           └── home.blade.php
├── routes/
│   └── web.php                                # Route::domain('example.com')->group(...)
└── storage/
    ├── example_com/                           # Per-domain isolated storage (logs, views, sessions, cache)
    └── seconddomain_com/
```

---

#### Sample Code & Component Patterns

##### A. Per-Domain Config Override (`config/domains/example_com.php`)

```php
<?php

return [
    'app.name' => 'Example Website',
    'app.url' => 'https://example.com',
    'cache.prefix' => 'example_com_cache',
];
```

##### B. Per-Domain Controller (`app/Http/Controllers/example_com/ExampleController.php`)

```php
<?php

namespace App\Http\Controllers\example_com;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ExampleController extends Controller
{
    public function submit(Request $request)
    {
        // Custom domain logic (e.g. process contact form, api submission)
        return response()->json(['success' => true]);
    }
}
```

##### C. Dynamic Layout Inheritance (`App\Helper::dir()`)

Every domain view inherits from its domain layout using `App\Helper::dir()`. This helper dynamically evaluates to the current domain's folder key (e.g. `example_com`), maintaining domain isolation seamlessly:

```blade
{{-- resources/views/example_com/home.blade.php --}}
@extends(App\Helper::dir().'/main')

@section('content')
    <main class="container mx-auto px-4 py-8">
        <h1>{{ $content['title'] }}</h1>
        <article class="prose max-w-none">
            {!! $content['html'] !!}
        </article>
    </main>
@endsection
```

##### D. Data Passed to Domain Views

The `GhostController::page` handler automatically passes two key arrays to every rendered Blade view:

- **`$content`**: Cleaned Ghost post/page dataset containing `id`, `title`, `slug`, `html`, `excerpt`, `feature_image`, `published_at`, `tags`, `authors`, and any custom enricher attributes.
- **`$seo`**: Neutral metadata array containing `title`, `description`, `canonical_url`, `og` (OpenGraph), `twitter`, and `json_ld`.

---

### 4. Webhook & Automatic Cache Invalidation

Set up a webhook in Ghost Admin pointing to:
`https://your-laravel-app.com/webhook/ghost/post`

When a post/page is published, updated, or deleted, Ghost triggers this endpoint. The package verifies the `X-Ghost-Signature` HMAC and automatically clears the cache for affected domains.

> 🔒 **Zero CSRF Configuration Required**: The webhook route is registered automatically outside the standard `web` middleware group. You **do not** need to manually add `'webhook/ghost/post'` to `$middleware->validateCsrfTokens(except: ...)` in `bootstrap/app.php`. Security is enforced via HMAC SHA-256 signature verification (`X-Ghost-Signature` header + `GHOST_WEBHOOK_SECRET`).

---

## 🔌 Extension Hooks

### Custom Domain Enricher (`DomainEnricherInterface`)

Attach additional data (e.g. products, dynamic pricing, Airtable items) to `$content` before rendering.

Create `app/Services/example_com/ExampleComEnricher.php`:

```php
namespace App\Services\example_com;

use MrSonj\MultiDomainGhost\Contracts\DomainEnricherInterface;

class ExampleComEnricher implements DomainEnricherInterface
{
    public function enrich(array $content, string $canonicalUrl): array
    {
        $content['products'] = Product::latest()->take(5)->get();
        return $content;
    }
}
```

The package auto-discovers enrichers matching `App\Services\{domain_com}\{StudlyDomain}Enricher`.

### Custom Content Transformer (`ContentTransformerInterface`)

Modify raw HTML from Ghost (e.g. inject Alpine attributes, clean up links):

Create `app/Services/GhostContentTransformer.php`:

```php
namespace App\Services;

use MrSonj\MultiDomainGhost\Contracts\ContentTransformerInterface;

class GhostContentTransformer implements ContentTransformerInterface
{
    public function transform(array $content, string $domain): array
    {
        if (isset($content['html'])) {
            // Apply custom HTML transformations
        }
        return $content;
    }
}
```

---

## 📄 License

MIT
