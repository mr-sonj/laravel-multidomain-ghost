# Nội dung chuẩn web theo từng domain — robots.txt, ads.txt, SEO

Ngày: 2026-08-26
Trạng thái: đã chốt thiết kế, chờ lập plan
Phiên bản đích: 2.0.0 (gộp vào release breaking đã ghi CHANGELOG nhưng chưa tag)

---

## 1. Vấn đề

Sau khi `routes.paths` đã được giải theo từng domain
([spec route](2026-08-26-per-domain-routes-design.md)), khối nội dung vẫn nằm nguyên trong
một file config dùng chung cho mọi domain:

```php
'seo' => [
    'default_image' => env('GHOST_SEO_DEFAULT_IMAGE', 'https://{domain}/img/{domain_key}/apple-touch-icon.png'),
],
'robots' => [
    'content_signal' => env('ROBOTS_CONTENT_SIGNAL', ''),
    'sitemap'        => env('GHOST_ROBOTS_SITEMAP', 'https://{domain}/sitemap.xml'),
    'disallow'       => ['/cdn-cgi/'],
],
'ads' => [
    'txt' => env('GHOST_ADS_TXT', ''),
],
```

Ba khiếm khuyết, xếp theo mức độ thật:

**a. `ads.txt` không thể dùng được cho nhiều domain.** Một ads.txt thật dài hàng chục dòng
và gắn với **một** tài khoản publisher. `env('GHOST_ADS_TXT')` là một giá trị một dòng dùng
chung cho mọi domain. Không có cách nào cấu hình hai domain khác publisher. Đây là chỗ hỏng
nặng nhất.

**b. `robots.disallow` và `content_signal` khóa cứng.** `disallow` là mảng literal trong
config gói, không env, không nơi nào tài liệu hóa đường đổi theo domain. `content_signal`
là một env dùng chung — và là env duy nhất trong gói thiếu tiền tố `GHOST_`.

**c. Đường override per-domain có tồn tại nhưng không ai biết.** `config/domains/{key}.php`
đã override được `multidomain-ghost.robots.*` và `seo.*`
([LoadDomainConfiguration.php:26](../../../src/Foundation/Bootstrap/LoadDomainConfiguration.php)),
và ở tầng controller nó chạy đúng vì các key này được đọc tại thời điểm request. README
không nhắc gì. Nhưng kể cả biết, nhét một ads.txt nhiều dòng vào giá trị config vẫn là sai
định dạng: ads.txt là **tệp văn bản**, không phải giá trị cấu hình.

### Điều đã kiểm chứng — và điều không đúng như thoạt nhìn

`GhostRouteRegistrar::adsTxtContent()`
([GhostRouteRegistrar.php:161](../../../src/Routing/GhostRouteRegistrar.php)) đọc
`config('multidomain-ghost.ads.txt')` **một lần**, bên trong vòng lặp qua mọi domain lúc
boot, trong khi `LoadDomainConfiguration` chỉ nạp config của domain đang active. Thoạt nhìn
đây là đúng bug (a)+(b) của spec route.

Kiểm chứng lại thì **nhẹ hơn thế**. Route cache là per-domain
([Application.php:78](../../../src/Foundation/Application.php)) và mọi route đều nằm trong
`Route::domain()`. Khi build cache cho `b.com`, config của `b.com` đang active, nên quyết
định `/ads.txt` **của chính b.com** là đúng. Cái sai còn lại chỉ là:

- mỗi bộ cache chứa route chết của các domain khác, được quyết định theo config sai;
- `route('b_com_ads')` / `Route::has()` gọi từ domain A cho kết quả không đáng tin.

Sai về nguyên tắc, hiếm khi quan sát được. Không phải động lực chính của thay đổi này —
động lực chính là (a) và (b). Nhưng chuyển sang phân giải theo file xóa hẳn lớp vấn đề, nên
vẫn nằm trong phạm vi.

**Placeholder không phải vấn đề.** `{domain}` / `{domain_key}` trong `robots.sitemap` và
`seo.default_image` nở ra đúng theo từng domain tại thời điểm request. Đó là một mẫu
(template) hợp lệ, không phải hardcode. Giữ nguyên.

---

## 2. Quyết định

1. Thêm quy ước thư mục thứ ba: `resources/domains/{domain_key}/`, gương với
   `config/domains/{domain_key}.php` và `routes/domains/{domain_key}.php`.
2. `ads.txt` **chỉ** phân giải theo file. Bỏ `multidomain-ghost.ads.txt` và fallback legacy
   `services.adsense.ads_txt`.
3. `robots.txt`: có file thì **thay thế hoàn toàn**; không có thì sinh từ config như hiện nay.
4. SEO: **không đổi code**. Chỉ tài liệu hóa đường override qua `config/domains/{key}.php`.
5. Giữ lớp fallback global cho `robots.*` và `seo.*` — chúng là mặc định hợp lý, và nay
   được ghi rõ là override được theo domain.

---

## 3. Cấu trúc và thứ tự phân giải

```
config/domains/example_com.php      ← đã có (1.x)
routes/domains/example_com.php      ← đã có (2.0.0)
resources/domains/example_com/      ← mới
    ├── ads.txt
    └── robots.txt
```

### `/ads.txt`

| Trạng thái | Kết quả |
|---|---|
| `resources/domains/{key}/ads.txt` tồn tại và không rỗng | Route được đăng ký; trả **nguyên văn** |
| Không có, hoặc rỗng | Route **không tồn tại** → 404 |

Điều kiện đăng ký là `is_file()` trên đường dẫn của **chính domain đang lặp**, không đọc
config. Nên đúng theo từng domain ở mọi lần boot và mọi lần build route cache.

Nội dung trả nguyên văn, **không** nở placeholder: ads.txt là định dạng IAB, mọi biến đổi
nội dung đều là rủi ro.

Rỗng và không tồn tại quy về cùng một trạng thái. Một ads.txt rỗng trả 200 mang nghĩa
"domain này không cấp phép cho seller nào" — một khẳng định khác hẳn với "domain này không
có ads.txt". Quy tắc này đã tồn tại trong gói; thay đổi này giữ nguyên nó.

### `/robots.txt`

| Trạng thái | Kết quả |
|---|---|
| `resources/domains/{key}/robots.txt` tồn tại | Trả **nguyên văn**, package không chèn gì — kể cả dòng `Sitemap:` |
| Không có | Sinh từ `robots.disallow` / `robots.sitemap` / `robots.content_signal` như hiện nay |

Route `/robots.txt` **luôn** được đăng ký, vì luôn sinh được nội dung.

Chọn "thay thế hoàn toàn" thay vì "nối thêm": nối thêm thì các dòng package sinh ra vẫn bị
áp cho mọi domain — tức vẫn còn đúng cái khóa cứng đang muốn gỡ. Đánh đổi: người đặt file
tự chịu trách nhiệm dòng `Sitemap:`. Ghi rõ trong README và trong dòng hướng dẫn của
`domain:add`.

Ba key config trên đọc tại thời điểm request nên đã đúng domain active — chỉ cần tài liệu
hóa rằng `config/domains/{key}.php` override được chúng.

### SEO

Không đổi cấu trúc, không thêm key. Lý do:

- `app.name`, `app.url` đã per-domain qua `config/domains/{key}.php`.
- `og:site_name`, `locale`, `twitter:site` lấy từ JSON trong `description` của Ghost primary
  tag ([GhostController.php:418](../../../src/Http/Controllers/GhostController.php)) — đã
  per-domain theo cách tốt hơn config, vì biên tập viên sửa được trong Ghost.
- `seo.default_image` là template nở đúng theo domain, và override được per-domain.

Phần SEO cần **tài liệu**, không cần code.

---

## 4. Thay đổi chi tiết

### 4.1 `config/multidomain-ghost.php`

```php
// {domain} và {domain_key} nở thành hostname đang active và dạng an toàn cho thư mục
// (example.com / example_com). Mọi key dưới đây override được theo từng domain trong
// config/domains/{domain_key}.php.
'seo' => [
    'default_image' => env(
        'GHOST_SEO_DEFAULT_IMAGE',
        'https://{domain}/img/{domain_key}/apple-touch-icon.png',
    ),
],
// Chỉ dùng khi domain không có resources/domains/{domain_key}/robots.txt. Có file thì
// file thay thế toàn bộ khối này.
'robots' => [
    'content_signal' => env('GHOST_ROBOTS_CONTENT_SIGNAL', ''),
    'sitemap' => env('GHOST_ROBOTS_SITEMAP', 'https://{domain}/sitemap.xml'),
    'disallow' => ['/cdn-cgi/'],
],
// Khối 'ads' bị xóa: ads.txt gắn với một tài khoản publisher, không có mặc định dùng chung
// nào hợp lý. Đặt tại resources/domains/{domain_key}/ads.txt.
```

### 4.2 `src/Support/DomainAssets.php` (mới)

```php
<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

/**
 * Static files a single domain owns, under resources/domains/{domain_key}/.
 *
 * The third of the three per-domain conventions, alongside config/domains/ and
 * routes/domains/. Kept apart from config because robots.txt and ads.txt are text
 * files with their own formats, not configuration values.
 */
final class DomainAssets
{
    public static function path(string $domain, string $file): string
    {
        return resource_path('domains/'.DomainName::dirKey($domain).'/'.$file);
    }

    /**
     * The file's trimmed contents - null when the domain has no such file, and null
     * when the file is empty.
     *
     * The two collapse deliberately. An empty ads.txt served with a 200 reads as
     * "this domain authorises no sellers", which is not the claim a missing file
     * makes, so an empty file must not produce a response.
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

`$file` luôn là literal do gói truyền vào (`'ads.txt'`, `'robots.txt'`), không bao giờ là
đầu vào người dùng. `$domain` thì có thể đến từ header `Host` chưa kiểm chứng, nhưng
`DomainName::normalize()` loại mọi chuỗi không có hình dạng hostname — kể cả mọi chuỗi chứa
`/` hoặc `..` — và `dirKey()` đổi `.` thành `_`. Không có đường path traversal.

Không cache. `/robots.txt` và `/ads.txt` là hai route lưu lượng thấp nhất trong gói; một
`is_file()` cộng một `file_get_contents()` mỗi request rẻ hơn một tầng cache phải invalidate.

### 4.3 `GhostRouteRegistrar`

- Xóa `adsTxtContent()`.
- Closure của group nhận thêm `$domain`: `function () use ($domain, $routeNamePrefix, $routes)`.
- Điều kiện đăng ký ads:

```php
if (isset($paths['ads']) && is_string($paths['ads'])
    && DomainAssets::contents($domain, 'ads.txt') !== null) {
    Route::name("{$routeNamePrefix}_ads")->get($paths['ads'], [GhostController::class, 'ads']);
}
```

Ngữ nghĩa `paths.ads = null` (tắt route) giữ nguyên từ 2.0.0.

### 4.4 `GhostController`

```php
public function robots(): Response
{
    $file = DomainAssets::contents($this->domain, 'robots.txt');

    if ($file !== null) {
        return response($file)->header('Content-Type', 'text/plain;charset=UTF-8');
    }

    // ... phần sinh hiện tại, giữ nguyên từng dòng ...
}

public function ads(): Response
{
    $file = DomainAssets::contents($this->domain, 'ads.txt');

    if ($file === null) {
        abort(404);
    }

    return response($file)->header('Content-Type', 'text/plain;charset=UTF-8');
}
```

`abort(404)` thay vì trả chuỗi rỗng: registrar đã không đăng ký route trong trường hợp này,
nhưng nếu người dùng tự khai route trong `routes/domains/{key}.php` thì 404 là câu trả lời
đúng — theo đúng lập luận đã ghi trong gói về ads.txt rỗng.

`config('services.robots.content_signal')` giữ nguyên làm fallback: nó không thuộc bài toán
này.

### 4.5 `domain:add`

Thêm một bước, sau bước tạo thư mục view:

```
resources/domains/{sanitized}/     ← tạo thư mục, không sinh file
```

In một dòng hướng dẫn:

```
ℹ robots.txt / ads.txt riêng cho domain: resources/domains/{sanitized}/
  Đặt robots.txt vào đây sẽ thay thế toàn bộ phần package tự sinh, kể cả dòng Sitemap:.
```

**Không sinh stub.** Một `robots.txt` stub sẽ lập tức vô hiệu hóa phần sinh tự động và làm
domain mới mất dòng `Sitemap:` mà không ai nhận ra. Một `ads.txt` rỗng thì tệ hơn: nó là một
khẳng định sai về quyền của seller.

Đánh đổi đã biết: git không theo dõi thư mục rỗng, nên thư mục vừa tạo có thể không tồn tại
sau khi clone. Không sao — gói dùng `is_file()`, và người dùng tạo file khi cần. Thư mục chỉ
để chỉ đường.

---

## 5. Không thay đổi

- `seoData()` và toàn bộ nhánh SEO trong `GhostController`.
- `sitemap()`, `feed()`, `page()`, `blog()`.
- Placeholder `{domain}` / `{domain_key}` và `expandDomainPlaceholders()`.
- `robots.disallow`, `robots.sitemap`, `seo.default_image` — vẫn là mặc định global,
  override được per-domain.
- `services.robots.content_signal` fallback.
- `routes.paths`, `LoadDomainConfiguration`, `DomainRegistry`, cache per-domain, webhook.

---

## 6. Breaking changes → gộp vào 2.0.0

| Thay đổi | Ảnh hưởng |
|---|---|
| Xóa `multidomain-ghost.ads.txt` (`GHOST_ADS_TXT`) | `/ads.txt` biến mất cho mọi domain cho đến khi tạo file |
| Xóa fallback `services.adsense.ads_txt` | Như trên |
| `ROBOTS_CONTENT_SIGNAL` → `GHOST_ROBOTS_CONTENT_SIGNAL` | Dòng `Content-Signal:` biến mất cho đến khi đổi tên biến trong `.env` |

### Đường nâng cấp

1. Với mỗi domain: `mkdir -p resources/domains/{key}` rồi chép nội dung `GHOST_ADS_TXT` vào
   `resources/domains/{key}/ads.txt`. Hoặc chạy lại `php artisan domain:add {domain}` để có
   sẵn thư mục (lệnh idempotent, không đụng config/view/route đã có).
2. Xóa `GHOST_ADS_TXT` khỏi `.env`, đổi `ROBOTS_CONTENT_SIGNAL` thành
   `GHOST_ROBOTS_CONTENT_SIGNAL`.
3. Xóa khối `'ads'` khỏi `config/multidomain-ghost.php` đã publish.
4. Domain nào cần robots.txt riêng: đặt `resources/domains/{key}/robots.txt`, **nhớ tự viết
   dòng `Sitemap:`**.

---

## 7. Kiểm thử (TDD)

**Helper mới trong `tests/TestCase.php`:** `setDomainAssets(array $files)` — nhận map từ
đường dẫn tương đối (`'example_com/ads.txt'`) sang nội dung, ghi vào
`base_path('resources/domains')`, dọn file và thư mục vừa tạo trong `tearDown`. Gộp chung
danh sách dọn dẹp với `setDomainRouteFiles` (`$temporaryRouteFiles` → `$temporaryFiles`).

Cùng đánh đổi đã chấp nhận cho `setDomainRouteFiles`: `base_path()` của Testbench trỏ vào
skeleton trong `vendor/`, không có `useResourcePath()` tương ứng với `useConfigPath()`. Chỉ
xóa đúng những gì helper tạo ra.

### `tests/Feature/GhostDomainRoutesTest.php`

**Sửa** — mọi `config()->set('multidomain-ghost.ads.txt', …)` chuyển sang `setDomainAssets`:
- `test_macro_registers_all_ghost_routes_for_domain` (dòng 37)
- `test_macro_handles_domains_with_hyphens` (dòng 53, key `my-sample-blog_co_uk`)
- `test_ads_txt_route_is_not_registered_when_config_is_empty`
  → đổi tên `…_when_the_domain_has_no_ads_file`
- `test_ads_txt_route_is_registered_when_package_config_is_present`
  → đổi tên `…_when_the_domain_has_an_ads_file`
- `test_ads_txt_route_is_not_registered_when_an_explicit_path_has_no_content`
- `test_ads_txt_route_honours_an_explicit_path_when_content_is_present`
- `test_a_path_set_to_null_disables_only_that_route` (dòng 316)
- `test_ads_path_set_to_null_disables_the_route` (dòng 447)

**Xóa:**
- `test_ads_txt_route_is_registered_when_legacy_config_is_present` — đường config legacy
  không còn.

**Thêm:**
- `test_ads_route_registration_is_independent_per_domain` — đăng ký hai domain, chỉ một có
  file; khẳng định chỉ domain đó có route `_ads`. Đây là test chốt: nó là thứ không thể pass
  với `adsTxtContent()` cũ.

### `tests/Feature/GhostControllerConfigurationTest.php`

**Xóa:**
- `test_ads_txt_reads_from_the_package_config`
- `test_ads_txt_still_honours_the_legacy_services_key`

**Thêm:**
- `test_ads_txt_reads_the_domains_own_file`
- `test_ads_txt_404s_when_the_domain_has_no_file`
- `test_robots_file_replaces_the_generated_body` — khẳng định nội dung khớp chính xác và
  **không** chứa dòng `Sitemap:` mà package sinh.
- `test_robots_falls_back_to_the_generated_body_without_a_file` — giữ hành vi cũ.
- `test_robots_content_signal_reads_the_renamed_env_key`.

Các test SEO hiện có giữ nguyên, không sửa.

### `tests/Feature/LegacyConfigCompatibilityTest.php`

Giữ nguyên. Nó thay thế nguyên khối `robots` và khẳng định `Sitemap:` vẫn sinh từ mặc định —
hành vi đó không đổi.

### `tests/Feature/DomainCommandsTest.php`

**Thêm:** `test_domain_add_creates_the_per_domain_assets_directory`.

---

## 8. Rủi ro

- **Người nâng cấp mất `/ads.txt` mà không nhận ra.** Route biến mất im lặng thành 404;
  AdSense có thể báo vi phạm trước khi ai kịp để ý. Giảm thiểu: bảng nâng cấp ở mục 6, dòng
  `### Breaking` trong CHANGELOG, và mục README riêng.
- **robots.txt riêng thiếu dòng `Sitemap:`.** Hệ quả trực tiếp của quyết định "thay thế hoàn
  toàn". Giảm thiểu bằng dòng hướng dẫn của `domain:add` và một ví dụ đầy đủ trong README.
- **Test ghi vào skeleton trong `vendor/`.** Đã tồn tại từ `setDomainRouteFiles`, không phát
  sinh mới.

---

## 9. Ngoài phạm vi

- Thêm key SEO mới (`seo.site_name`, `seo.twitter_site`…) — đã cân nhắc, đã loại: Ghost
  primary tag đã giải bài này tốt hơn.
- Nở placeholder trong nội dung file — đã cân nhắc, đã loại: file đã thuộc về một domain.
- Cache nội dung file — đã cân nhắc, đã loại: hai route lưu lượng thấp nhất trong gói.
- Chế độ "nối thêm" cho robots.txt — đã cân nhắc, đã loại ở mục 3.
- `security.txt`, `humans.txt`, `favicon.ico` per-domain. Quy ước `resources/domains/{key}/`
  mở đường cho chúng, nhưng không thêm trong thay đổi này.
