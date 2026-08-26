# Route theo từng domain — bỏ auto-register route nội dung

Ngày: 2026-08-26
Trạng thái: đã chốt thiết kế, chờ lập plan
Phiên bản đích: 2.0.0 (breaking)

---

## 1. Vấn đề

`multidomain-ghost.routes.paths` khai báo bảy đường dẫn dùng chung cho **mọi** domain:

```php
'paths' => [
    'home' => '/', 'sitemap' => '/sitemap.xml', 'feed' => '/feed',
    'robots' => '/robots.txt', 'blog' => '/blog', 'post' => '/blog/{slug}',
    'ads' => null,
],
```

Ba khiếm khuyết:

**a. Không thể khác nhau theo domain.** `GhostRouteRegistrar::registerAll()` lặp qua mọi domain
nhưng đọc `config('multidomain-ghost.routes.paths')` một lần
(`src/Routing/GhostRouteRegistrar.php:78`). Mọi domain nhận cùng một bảng.

**b. Override trong `config/domains/*.php` sai một cách âm thầm.**
`LoadDomainConfiguration` chỉ nạp file của domain **đang active**
(`src/Foundation/Bootstrap/LoadDomainConfiguration.php:26`). Đặt `routes.paths` ở đó thì
đường dẫn của domain A được áp cho cả B, C, D trong cùng request. README:126 đã phải cảnh
báo điều này và chỉ ra lối thoát duy nhất là tắt `GHOST_ROUTES_AUTO_REGISTER` rồi khai báo
tay — tức bỏ tính năng chính để lấy thứ đáng lẽ là mặc định.

**c. Đó là bài toán không nên giải bằng config.** Mỗi website một kiểu URL; không có bảng
mặc định nào đoán trước được. Chỉ `robots.txt`, `sitemap.xml`, `ads.txt` là do chuẩn web
quy định vị trí, không phải do site chọn.

### Điều đã kiểm chứng

`GhostController` **không phụ thuộc vào `routes.paths` hay route name**. `page()`/`blog()`
tìm nội dung qua `canonicalUrl($request)` — URL hiện tại — rồi đối chiếu `canonical_url` của
Ghost. `sitemapLinks()` dựng từ slug Ghost trả về. `robots()` lấy URL sitemap từ
`multidomain-ghost.robots.sitemap` (config riêng, độc lập).

Nên `home`/`blog`/`post`/`feed` chỉ là đường tắt tiện tay. Bỏ chúng không gãy gì bên trong.

---

## 2. Quyết định

1. `routes.paths` chỉ còn ba khóa chuẩn: `sitemap`, `robots`, `ads`.
2. Route nội dung do người dùng khai trong `routes/domains/{domain_key}.php`, package tự nạp
   theo registry.
3. Không suy đoán `viewPath`. Route nào cần view riêng thì tự đặt `defaults('viewPath', …)`.
4. Không thêm macro mới. `Route::ghostDomain()` giữ nguyên chữ ký.
5. Catch-all chuyển sang pha `booted`, xóa `moveCatchAllLast()`.

---

## 3. Thay đổi chi tiết

### 3.1 Config — `config/multidomain-ghost.php`

```php
'routes' => [
    'auto_register' => filter_var(env('GHOST_ROUTES_AUTO_REGISTER', true), FILTER_VALIDATE_BOOL),
    'catch_all'     => filter_var(env('GHOST_ROUTES_CATCH_ALL', false), FILTER_VALIDATE_BOOL),

    // Chỉ các tệp chuẩn web — vị trí của chúng do crawler quy định, không phải
    // lựa chọn biên tập của site. null = không đăng ký route đó.
    // Route nội dung khai trong routes/domains/{domain_key}.php.
    'paths' => [
        'sitemap' => '/sitemap.xml',
        'robots'  => '/robots.txt',
        'ads'     => '/ads.txt',
    ],

    'middleware'   => ['web'],
    'redirect_www' => filter_var(env('GHOST_ROUTES_REDIRECT_WWW', true), FILTER_VALIDATE_BOOL),
    'webhook'      => [ /* giữ nguyên */ ],
],
```

Đổi ngữ nghĩa của `paths.ads`: trước đây `null` nghĩa là "dùng `/ads.txt`"
(`$paths['ads'] ?? '/ads.txt'`), nay `null` nghĩa là **tắt**, thống nhất với `robots` và
`sitemap`. Mặc định thành chuỗi tường minh `'/ads.txt'`.

Giữ nguyên quy tắc hiện có: route `ads` chỉ đăng ký khi `ads.txt` **có nội dung**. Một
ads.txt rỗng trả 200 mang nghĩa "domain này không cấp phép cho seller nào" — khác hẳn với
không có file.

Các khối `robots`, `ads`, `views`, `seo`, `cache`, webhook: **không đổi**.

### 3.2 `GhostRouteRegistrar`

`DEFAULT_PATHS` rút còn ba khóa (vẫn cần: config đã publish trước đây có thể thiếu key).

`registerDomain(string $domain, ?Closure $routes = null)`:
- Đăng ký `robots`, `sitemap`, `ads` theo `paths`, mỗi khóa `null` thì bỏ qua.
- Bỏ hẳn khối đăng ký `home`, `blog`, `post`, `feed`.
- Gọi `$routes()` nếu có.
- **Không** đăng ký catch-all nữa.
- Giữ `www` redirect và cơ chế chống trùng `$registeredDomains`.

Thêm `registerCatchAlls(): void`:
- Không làm gì nếu `routes.catch_all` tắt.
- Với mỗi domain trong `$registeredDomains`, đăng ký `/{path}` (`where('path', '.*')`,
  `viewPath` = `{domain_key}/page`, tên `{prefix}_catch_all`), bỏ qua nếu tên đó đã tồn tại.

Xóa `moveCatchAllLast()` cùng toàn bộ trò rebuild `RouteCollection` + `Router::setRoutes()`
đi kèm (`src/Routing/GhostRouteRegistrar.php:183-219` cùng lời gọi ở dòng 65, ~37 dòng). Catch-all đứng cuối do
cấu trúc, không do vá.

Giữ chốt `$routeCollection` trong `registerDomain()`: nó vẫn có việc — dọn static state khi
app được refresh (test, Octane).

### 3.3 Service provider — hai pha

```php
private function registerDomainRoutes(): void
{
    if ($this->app->routesAreCached()) {
        return;
    }

    if ((bool) config('multidomain-ghost.routes.auto_register', true)) {
        Route::middleware('web')->group(static function (): void {
            foreach (DomainRegistry::all() as $domain) {
                $file = base_path('routes/domains/'.DomainName::dirKey($domain).'.php');

                GhostRouteRegistrar::registerDomain(
                    $domain,
                    is_file($file)
                        ? static function () use ($file): void { require $file; }
                        : null,
                );
            }
        });
    }

    // Đăng ký cả khi auto_register tắt: routes/web.php vẫn có thể gọi
    // Route::ghostDomain(), và catch-all của nó vẫn phải đứng sau cùng.
    $this->app->booted(static function (): void {
        GhostRouteRegistrar::registerCatchAlls();
    });
}
```

**Vì sao phải là `booted`.** `withRouting()` đăng ký RouteServiceProvider của app bên trong
một callback `booting` (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php:172`).
Callback đó chạy trước vòng lặp boot provider nên provider ấy được **nối vào cuối** danh
sách → `routes/web.php` nạp **sau** khi provider của package boot xong. Catch-all đăng ký
lúc boot như hiện nay chắc chắn đứng trước route người dùng và nuốt hết. `booted` callback
chạy sau toàn bộ provider, nên catch-all luôn là cái cuối cùng.

`route:cache` vẫn đúng: `RouteCacheCommand` bootstrap một app mới, `BootProviders` gọi
`$app->boot()`, `booted` callback vì thế nằm trong bộ route được serialize.

Thứ tự cuối cùng:

```
route chuẩn (robots/sitemap/ads) → routes/domains/{key}.php → routes/web.php → catch-all
```

### 3.4 `routes/domains/{domain_key}.php`

Quy ước gương với `config/domains/{domain_key}.php`: tên file dùng `DomainName::dirKey()`
(`example.com` → `example_com.php`). File không tồn tại thì bỏ qua, không lỗi.

File được `require` **bên trong** group của domain đó, nên nội dung khai trực tiếp — không
`Route::domain()`, không middleware, không `if ($host == …)`:

```php
<?php

use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;
use App\Http\Controllers\PricingController;

Route::get('/', [GhostController::class, 'page'])
    ->name('example_com_home')
    ->defaults('viewPath', 'example_com/home');

Route::get('/news', [GhostController::class, 'blog'])
    ->name('example_com_blog')
    ->defaults('viewPath', 'example_com/blog');

Route::get('/news/{slug}', [GhostController::class, 'page'])
    ->name('example_com_post')
    ->defaults('viewPath', 'example_com/post')
    ->where('slug', '[A-Za-z0-9\-_]+');

Route::get('/rss', [GhostController::class, 'feed'])
    ->name('example_com_feed');

// Route của chính app, cùng một chỗ:
Route::get('/pricing', [PricingController::class, 'index'])->name('example_com_pricing');
```

Khác pattern `require __DIR__.'/domains/…'` kiểu Laravel 8 ở hai điểm: **mọi** domain đều
được nạp chứ không chỉ host hiện tại (nên `route('example_com_home')` gọi từ domain khác
vẫn ra URL đúng, và route cache per-domain vẫn đúng), và `routes/web.php` không bị đụng tới.

### 3.5 `domain:add`

Thêm bước sinh `routes/domains/{key}.php`. Stub dùng **đúng bốn đường dẫn mặc định cũ** —
`/`, `/blog`, `/blog/{slug}`, `/feed` — chứ không phải `/news`, `/rss` như ví dụ minh họa ở
mục 3.4, để người nâng cấp từ 1.x có lại y nguyên hành vi trước đó rồi tự sửa sau. `viewPath`
khớp đúng view mà lệnh vừa scaffold (`home`, `blog`, `post`).

Đã có file thì báo `! Route file already exists` và không ghi đè; `--force` thì ghi đè,
giống cách lệnh xử lý view và CSS.

Tên route giữ nguyên quy ước cũ (`{prefix}_home`…) để `route('example_com_home')` trong view
người dùng không gãy sau khi nâng cấp.

---

## 4. Không thay đổi

- `GhostController` — mọi method giữ nguyên, kể cả `feed()`, `sitemap()`, `robots()`, `ads()`.
- Các khối config `robots`, `ads`, `views`, `seo`, `cache`, `webhook`, `enrichers`, `transformer`.
- `Route::ghostDomain()` — chữ ký và hành vi giữ nguyên.
- `DomainRegistry`, `LoadDomainConfiguration`, `Application`, cache per-domain.
- Webhook, middleware `EnsureRegisteredDomain`, redirect `www`.

---

## 5. Breaking changes → 2.0.0

| Thay đổi | Ảnh hưởng |
|---|---|
| Bỏ `paths.home`/`blog`/`post`/`feed` | Route nội dung biến mất khỏi auto-register. Phải khai trong `routes/domains/{key}.php`. |
| `paths.ads = null` nay nghĩa là **tắt** | Config đã publish với `'ads' => null` sẽ mất route ads.txt. Đổi thành `'/ads.txt'`. |
| Catch-all đăng ký ở pha `booted` | Code gọi `GhostRouteRegistrar::registerAll()` thủ công phải gọi thêm `registerCatchAlls()`. |
| Xóa `moveCatchAllLast()` | Là private, không nằm trong API công khai. |

### Đường nâng cấp

1. Cập nhật `config/multidomain-ghost.php`: `routes.paths` còn ba khóa, `ads` đặt tường minh.
2. Với mỗi domain, chạy lại `php artisan domain:add {domain}` — lệnh idempotent, chỉ sinh
   thêm `routes/domains/{key}.php` còn thiếu, không đụng config/view đã có.
3. Sửa đường dẫn trong file route vừa sinh cho khớp `routes.paths` cũ của mình.

Ghi vào CHANGELOG mục `## 2.0.0` với tiểu mục `### Breaking` + `### Removed`, và viết lại
mục "Route customization & explicit declaration" trong README (hiện README:95-140).

---

## 6. Kiểm thử (TDD)

Chỉ `tests/Feature/GhostDomainRoutesTest.php` thực sự phụ thuộc `routes.paths` (34 chỗ).
`GhostControllerTest` gọi controller qua `$this->call()`, không qua route name — không đổi.

**Sửa:**
- `test_macro_registers_all_ghost_routes_for_domain` → đổi thành *chỉ* route chuẩn:
  khẳng định có `_robots`, `_sitemap`, `_ads`, `_www_redirect`; khẳng định **không** có
  `_home`, `_blog`, `_post`, `_feed`.
- `test_macro_handles_domains_with_hyphens` → bỏ assert `_home`/`_blog`/`_post`.
- `test_a_path_set_to_null_disables_only_that_route` → dùng `sitemap` thay `blog`.
- `test_a_relocated_path_keeps_its_route_name_and_view_path` → dùng `sitemap` →
  `/sitemap-index.xml`, khẳng định tên route giữ nguyên.
- Ba test catch-all → gọi thêm `registerCatchAlls()` cho đúng hai pha mới.

**Xóa:**
- `test_post_route_rejects_paths_that_are_not_slugs` — ràng buộc slug nay do người dùng đặt.
- `test_reordering_the_catch_all_does_not_duplicate_existing_routes` — `moveCatchAllLast()`
  không còn.

**Thêm:**
- `test_domain_route_file_is_loaded_inside_the_domain_group` — tạo tạm
  `base_path('routes/domains/example_com.php')`, khẳng định route trong đó nhận đúng
  `example.com` và đúng middleware.
- `test_a_missing_domain_route_file_is_ignored` — không có file thì không lỗi.
- `test_catch_all_is_registered_after_routes_declared_post_boot` — `registerAll()`, thêm
  route rời, rồi `registerCatchAlls()`; khẳng định route rời khớp trước catch-all.
- `test_catch_all_is_not_duplicated_when_registered_twice`.
- `test_catch_all_covers_domains_registered_only_through_the_macro`
  (`auto_register = false`).
- `test_ads_path_set_to_null_disables_the_route` — chốt ngữ nghĩa `null` mới.

**Helper mới trong `tests/TestCase.php`:** `setDomainRouteFiles(array $files)` — ghi vào
`base_path('routes/domains')` và dọn trong `tearDown`.

Đánh đổi đã biết: `base_path()` của Testbench trỏ vào skeleton trong `vendor/`, nên helper
này ghi tạm vào đó. Chỉ xóa đúng những file nó tạo. Không dùng được `useConfigPath()` như
`setRegisteredDomains()` vì Laravel không có setter tương ứng cho thư mục `routes`.

---

## 7. Rủi ro

- **Static state qua các request Octane.** `$registeredDomains` và `$routeCollection` là
  static; vấn đề này đã tồn tại từ trước và không nằm trong phạm vi thay đổi này.
- **Người dùng quên tạo file route.** Sau nâng cấp, domain không có
  `routes/domains/{key}.php` sẽ chỉ còn robots/sitemap/ads — trang chủ 404. Giảm thiểu bằng
  bảng nâng cấp ở mục 5 và bằng `domain:add` sinh sẵn file.
- **Trùng tên route giữa hai domain.** Trước đây registrar tự thêm tiền tố domain. Nay
  người dùng tự đặt tên; đặt trùng thì Laravel lấy route sau. Stub của `domain:add` giữ
  quy ước tiền tố để dẫn hướng.

---

## 8. Ngoài phạm vi

- Suy đoán `viewPath` theo domain (đã cân nhắc, đã loại).
- Macro `ghostPage()`/`ghostBlog()` (đã cân nhắc, đã loại).
- Per-domain paths trong `config/domains/*.php` (đã cân nhắc, đã loại — chính bài toán này
  nói rằng config không phải chỗ giải).
- Dọn `docs/routing-fixes.md`.
