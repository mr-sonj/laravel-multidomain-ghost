# Danh sách cần fix — Routing tự động của laravel-multidomain-ghost

Tài liệu bàn giao cho agent xử lý. **Mỗi mục làm riêng một lần**, không gộp.

---

## Quy ước đọc tài liệu này

Mỗi khẳng định được gắn nhãn:

- `[ĐÃ KIỂM CHỨNG]` — người viết doc đã chạy lệnh/đọc code và xác nhận tại thời điểm 2026-08-26.
- `[CẦN KIỂM CHỨNG]` — suy luận hợp lý nhưng **chưa** chạy thử. Agent **phải** tự xác minh trước khi sửa. Nếu sai, ghi lại và bỏ qua mục đó.

Không tin doc này một cách mù quáng. Mỗi mục đều có phần **Cách tự kiểm chứng** — chạy nó trước, sửa sau.

## Môi trường

```bash
# `php` trên PATH là 7.4 và KHÔNG chạy được test suite của package này.
PHP="/Users/sonjj/Library/Application Support/Herd/bin/php84"

# Test suite của package
cd /Volumes/Workspaces/Projects/packages/laravel-multidomain-ghost
"$PHP" vendor/bin/pest
"$PHP" vendor/bin/pint --test

# App thật tiêu thụ package này (autoload trỏ thẳng vào src/, sửa là ăn ngay,
# không cần composer update). Dùng để verify end-to-end.
cd /Volumes/Workspaces/Projects/herd_sites/multi_domain
"$PHP" artisan route:list --path=blog
```

App thật hiện có 2 domain đăng ký: `10mailbox.com`, `chacathaclac.com` `[ĐÃ KIỂM CHỨNG]`.

## Bối cảnh kiến trúc (cần nắm trước khi sửa bất cứ gì)

`[ĐÃ KIỂM CHỨNG]` — đọc `src/Foundation/Http/Kernel.php:18-27`:

```
DetectDomain → LoadEnvironmentVariables → LoadConfiguration
→ LoadDomainConfiguration → HandleExceptions → RegisterFacades
→ RegisterProviders → BootProviders
```

Hệ quả quan trọng: **config override của domain đang active được nạp XONG trước khi
service provider đăng ký route.** Nghĩa là route registrar đọc được config riêng của domain
đang phục vụ request. Đây là nền tảng cho phương án sửa ở mục **A4**.

Hệ quả thứ hai: provider đăng ký route cho **tất cả** domain (`GhostRouteRegistrar::registerAll()`),
nhưng chỉ route của domain đang active mới match được nhờ ràng buộc `Route::domain()`.
Nên việc config của domain active "rò rỉ" sang định nghĩa route của domain khác là **vô hại
trong đường request**. `[CẦN KIỂM CHỨNG]` — hãy tự xác nhận điều này trước khi dựa vào nó,
đặc biệt với `route:cache` (được build riêng cho từng domain qua `domain:optimize`) và với
việc sinh URL theo tên route trỏ sang domain khác.

---

# NHÓM A — Lỗi và bất nhất độc lập (làm trước, không phụ thuộc gì)

## A1. `/ads.txt` trả HTTP 200 với body rỗng thay vì 404

**Mức độ:** Cao — đây là lỗi thật, không phải tranh luận thiết kế.

**Vị trí:** [`src/Http/Controllers/GhostController.php:183-189`](../src/Http/Controllers/GhostController.php#L183-L189), route đăng ký tại [`src/Routing/GhostRouteRegistrar.php:69`](../src/Routing/GhostRouteRegistrar.php#L69)

**Vấn đề:**
`config('multidomain-ghost.ads.txt')` mặc định là chuỗi rỗng (xem `config/multidomain-ghost.php`,
khoá `ads.txt` → `env('GHOST_ADS_TXT', '')`). Controller làm `response(trim((string) $ads))`
→ HTTP **200**, `Content-Type: text/plain`, body rỗng. Route luôn được đăng ký bất kể có
nội dung hay không.

**Vì sao quan trọng:**
Theo spec ads.txt của IAB Tech Lab, một file **tồn tại nhưng rỗng** mang ý nghĩa ngữ nghĩa
khác hẳn file **không tồn tại**:
- 404 = "domain này không khai báo ads.txt" → crawler bỏ qua, không ảnh hưởng gì.
- 200 + rỗng = "domain này khai báo rằng KHÔNG uỷ quyền cho seller nào" → crawler có thể
  hiểu là mọi inventory rao bán dưới tên domain này đều không hợp lệ.

Với một package multi-domain phục vụ site có quảng cáo, đây là hành vi mặc định gây hại.
`[CẦN KIỂM CHỨNG]` — agent nên tự đối chiếu lại spec IAB ads.txt hiện hành để xác nhận diễn
giải này trước khi sửa, vì nó là lý do duy nhất biện minh cho thay đổi.

**Cách tự kiểm chứng:**
```bash
# Đọc trực tiếp
sed -n '183,189p' src/Http/Controllers/GhostController.php
grep -n "ads" config/multidomain-ghost.php

# Viết một test tạm trong tests/Feature/ khẳng định hành vi HIỆN TẠI, chạy nó,
# thấy nó PASS -> xác nhận lỗi tồn tại:
#   $this->get('http://example.com/ads.txt')->assertOk()->assertSee('', false);
# rồi kiểm tra response->getContent() === ''
```

**Hướng sửa đề xuất:**
Không đăng ký route `/ads.txt` khi nội dung rỗng. Tức là điều kiện hoá ở tầng registrar,
không phải trả 404 từ controller (route không tồn tại thì Laravel tự 404, sạch hơn).
Lưu ý fallback `config('services.adsense.ads_txt')` ở dòng 186 cũng phải được tính đến khi
quyết định "có nội dung hay không".

**Xong khi:**
- `GHOST_ADS_TXT` rỗng và `services.adsense.ads_txt` rỗng → `GET /ads.txt` trả 404.
- Có nội dung ở một trong hai nguồn → trả 200 với đúng nội dung đó, `Content-Type: text/plain;charset=UTF-8`.
- Có test phủ cả hai nhánh.

**Rủi ro:** Thấp. Không ai có thể đang phụ thuộc vào một file ads.txt rỗng.

---

## A2. Route `/ads.txt` là route duy nhất không có tên

**Mức độ:** Thấp — bất nhất, không gây lỗi.

**Vị trí:** [`src/Routing/GhostRouteRegistrar.php:69`](../src/Routing/GhostRouteRegistrar.php#L69)

**Vấn đề:** `[ĐÃ KIỂM CHỨNG]`
Sáu route còn lại trong group đều có `->name("{$routeNamePrefix}_...")`: `_robots`, `_sitemap`,
`_feed`, `_home`, `_blog`, `_post`. Riêng dòng 69 gọi thẳng `Route::get('/ads.txt', ...)`
không đặt tên. Hệ quả: không `route()` được, không `Route::has()` để assert được, và
`tests/Feature/GhostDomainRoutesTest.php` không hề phủ route này (test chỉ assert 6 tên kia).

**Cách tự kiểm chứng:**
```bash
grep -n "Route::name\|Route::get" src/Routing/GhostRouteRegistrar.php
grep -n "ads" tests/Feature/GhostDomainRoutesTest.php   # kỳ vọng: không có kết quả
```

**Hướng sửa đề xuất:** Thêm `->name("{$routeNamePrefix}_ads")` cho nhất quán.

**Xong khi:** `Route::has('example_com_ads')` trả `true` (khi route được đăng ký theo điều kiện
ở mục A1), và test phủ nó.

**Rủi ro:** Không có. Thêm tên là thay đổi cộng thêm.

**Phụ thuộc:** Nên làm **sau** A1 để tránh sửa cùng một dòng hai lần.

---

## A3. `/blog/{slug}` không có ràng buộc tham số

**Mức độ:** Thấp.

**Vị trí:** [`src/Routing/GhostRouteRegistrar.php:79-81`](../src/Routing/GhostRouteRegistrar.php#L79-L81)

**Vấn đề:** `[ĐÃ KIỂM CHỨNG]`
Route khai báo `->get('/blog/{slug}', ...)` không kèm `->where()`. Mặc định Laravel khớp
`{slug}` với mọi thứ trừ dấu `/`, nên `/blog/foo.txt`, `/blog/wp-admin`, `/blog/.env` đều
match và đi thẳng vào `GhostController@page` → gọi Ghost API để tra canonical URL.

**Vì sao quan trọng:**
Mỗi URL rác là một lượt tra Ghost. Có cache "miss" (`GHOST_CACHE_MISS_TTL`, mặc định 300s)
làm giảm nhẹ, nhưng scanner quét đường dẫn ngẫu nhiên vẫn tạo ra vô số cache key riêng biệt.
`[CẦN KIỂM CHỨNG]` — đọc `GhostContentService`/`GhostCache` để xác nhận mỗi URL miss thực sự
sinh một cache entry riêng; nếu không, mức độ nghiêm trọng thấp hơn và có thể bỏ qua mục này.

**Cách tự kiểm chứng:**
```bash
sed -n '75,82p' src/Routing/GhostRouteRegistrar.php
# Trong app thật:
cd /Volumes/Workspaces/Projects/herd_sites/multi_domain
"$PHP" artisan route:list --path=blog -v   # xem có where constraint không
```

**Hướng sửa đề xuất:** `->where('slug', '[A-Za-z0-9\-_]+')` — nhưng **kiểm tra trước** xem
slug thực tế trong Ghost của app có ký tự nào ngoài tập này không (tiếng Việt có dấu đã được
Ghost slugify sẵn thành ASCII, nhưng phải xác nhận).

**Xong khi:** `/blog/hop-le-123` vẫn 200; `/blog/foo.txt` trả 404 mà không chạm Ghost API.

**Rủi ro:** Trung bình — ràng buộc quá chặt sẽ làm 404 các bài đang chạy. Bắt buộc phải
liệt kê slug thật từ Ghost trước khi chốt regex.

---

# NHÓM B — Vấn đề thiết kế (B1 là nền tảng, B2–B4 phụ thuộc B1)

## B1. `auto_register` là công tắc nhị phân — không bật/tắt được từng route

**Mức độ:** Cao. **Đây là gốc rễ chung của mọi phàn nàn còn lại.**

**Vị trí:**
- [`src/Routing/GhostRouteRegistrar.php:57-84`](../src/Routing/GhostRouteRegistrar.php#L57-L84)
- [`src/MultiDomainGhostServiceProvider.php:116-127`](../src/MultiDomainGhostServiceProvider.php#L116-L127)
- `config/multidomain-ghost.php`, khoá `routes.auto_register`

**Vấn đề:** `[ĐÃ KIỂM CHỨNG]`
Registrar đăng ký cứng 7 route trong một khối duy nhất. Không có tham số nào chọn tập con.
Closure truyền vào `Route::ghostDomain($domain, $closure)` chạy ở **dòng 83**, tức là *bên trong*
group nhưng *sau khi* cả 7 route đã đăng ký — nên closure chỉ **thêm** được route, không
**bớt** hay **thay** được route nào.

Hệ quả thực tế: muốn tự quản mỗi `robots.txt`, hoặc muốn đổi `/blog` thành `/tin-tuc`, người
dùng buộc phải đặt `GHOST_ROUTES_AUTO_REGISTER=false` rồi **viết tay lại toàn bộ** home,
blog, post, sitemap, feed, robots, ads và cả nhóm redirect `www`. Chi phí của một sai lệch
nhỏ là mất trọn bộ scaffold.

**Cách tự kiểm chứng:**
```bash
sed -n '55,95p' src/Routing/GhostRouteRegistrar.php
grep -rn "auto_register" src/ config/ README.md
# Thử: có cách nào lấy 6/7 route không? Đọc kỹ signature registerDomain().
```

**Hướng sửa đề xuất:**
Chuyển `routes` sang bản đồ đường dẫn, **giữ nguyên mặc định hiện tại** để không breaking:

```php
'routes' => [
    'auto_register' => true,
    'paths' => [
        'home'    => '/',
        'sitemap' => '/sitemap.xml',
        'feed'    => '/feed',
        'robots'  => '/robots.txt',
        'blog'    => '/blog',          // null = không đăng ký
        'post'    => '/blog/{slug}',   // null = không đăng ký
        'ads'     => null,             // mặc định tắt — xem A1
    ],
],
```

Registrar duyệt bản đồ này thay vì hardcode. `null` = bỏ qua route đó.

**Xong khi:**
- Mặc định (không cấu hình gì) sinh **đúng y hệt** 7 route như hiện tại, cùng tên, cùng
  `viewPath` defaults → `tests/Feature/GhostDomainRoutesTest.php` phải **pass nguyên trạng,
  không sửa một dòng nào**. Đây là tiêu chí chống hồi quy quan trọng nhất của mục này.
- Đặt `paths.blog = null` → `Route::has('example_com_blog')` là `false`, 6 route kia còn nguyên.
- Đặt `paths.blog = '/tin-tuc'` → route tên `example_com_blog` phục vụ `/tin-tuc`, `viewPath`
  vẫn là `example_com/blog`.

**Rủi ro:** Trung bình. Đây là thay đổi cấu trúc config công khai. Phải giữ tương thích ngược
tuyệt đối cho người đang không cấu hình gì. Cập nhật `README.md` phần "What you get" (dòng
71-81) và "Explicit route declaration" (dòng 101-119).

---

## B2. `/blog` và `/blog/{slug}` bị hardcode — áp cấu trúc URL lên người dùng

**Mức độ:** Cao. **Phụ thuộc B1** (B1 xong thì mục này gần như tự giải quyết).

**Vị trí:** [`src/Routing/GhostRouteRegistrar.php:75-81`](../src/Routing/GhostRouteRegistrar.php#L75-L81)

**Vấn đề:**
Package tự nhận là "multi-domain", nhưng ép mọi domain phải dùng đúng tiền tố `/blog`.
Site tiếng Việt muốn `/tin-tuc`, site tin tức muốn `/news`, landing page không muốn blog nào
cả. Không có đường thoát nào ngoài tắt sạch (xem B1).

**Vì sao quan trọng — lý do sâu hơn chỉ là "thiếu linh hoạt":**
Package resolve nội dung bằng cách ghép `https://` + host + path rồi đối chiếu với
`canonical_url` trong Ghost (xem `GhostController::content()` và `GhostContentService`).
Nhưng permalink mặc định của Ghost là `/{slug}/` ở **gốc**, không phải `/blog/{slug}`.
Nên route `/blog/{slug}` **bắt người dùng phải sửa canonical URL trong Ghost Admin** cho khớp
với cấu trúc mà Laravel áp đặt.

Điều này **ngược với triết lý của chính package**: mọi phần còn lại đều theo hướng "Ghost
quyết định URL, Laravel khớp theo". Riêng route blog thì làm ngược lại.

`[CẦN KIỂM CHỨNG]` — agent phải tự xác nhận hai điều này:
1. Permalink mặc định của Ghost hiện tại thực sự là `/{slug}/` ở gốc.
2. Trong Ghost của app thật, các bài đang dùng canonical URL dạng nào — `/blog/...` hay `/...`?
   Nếu tất cả đã là `/blog/...` thì lập luận vẫn đúng về nguyên tắc nhưng ít cấp bách hơn.

**Cách tự kiểm chứng:**
```bash
sed -n '74,82p' src/Routing/GhostRouteRegistrar.php
grep -n "canonical_url" -r src/ | head -20
# Xem canonical URL thật từ Ghost:
cd /Volumes/Workspaces/Projects/herd_sites/multi_domain
"$PHP" artisan tinker --execute="dd(app(\MrSonj\MultiDomainGhost\Services\GhostContentService::class)->slugs());"
```

**Hướng sửa đề xuất:** Không cần code riêng nếu B1 đã xong — chỉ cần `paths.blog` và
`paths.post` đọc từ config. Việc còn lại là **tài liệu hoá**: README phải nói rõ đổi đường
dẫn ở đâu, và nói rõ canonical URL trong Ghost phải khớp với đường dẫn đã chọn.

**Xong khi:** README có ví dụ đổi `/blog` → `/tin-tuc` cho một domain cụ thể, và ví dụ đó
chạy được thật trong app.

**Rủi ro:** Thấp nếu B1 đã làm đúng.

---

## B3. Không có route catch-all cho page tuỳ ý — ưu tiên bị đảo ngược

**Mức độ:** Trung bình–Cao. **Phụ thuộc B1.** Đây là mục cần bàn bạc trước, không nên tự quyết.

**Vị trí:** Registrar không có route nào cho mục đích này; README dòng 121-133 hướng dẫn viết tay.

**Vấn đề:**
Thứ **luôn luôn** cần cho một CMS multi-domain là: bất kỳ đường dẫn nào cũng resolve được về
Ghost page theo canonical URL — `/about`, `/lien-he`, `/pricing`, `/chinh-sach-bao-mat`.
Package **không** tự động hoá thứ đó; README bắt khai báo tay từng route một.

Ngược lại, `/blog` — thứ chỉ một phần domain cần — thì lại được auto-register.

Tức là package đang **tự động hoá cái tuỳ chọn và bỏ ngỏ cái phổ quát**.

Một route catch-all `/{path}` → `GhostController@page` (với `->where('path', '.*')`, đăng ký
**cuối cùng** để không che các route cụ thể) phủ được cả `/blog/{slug}` lẫn `/about` mà không
áp đặt cấu trúc gì — và đúng với mô hình canonical URL của package.

**Vì sao cần bàn trước khi làm:**
Catch-all đụng tới ít nhất ba thứ, phải đánh giá từng cái:
1. **Cache 404.** Mọi URL rác đều trở thành một lượt tra Ghost + một cache miss entry
   (xem A3 — cùng một loại rủi ro nhưng ở phạm vi toàn site).
2. **Sitemap.** `sitemapLinks()` liệt kê theo canonical URL từ Ghost nên có thể không đổi,
   nhưng cần xác nhận `[CẦN KIỂM CHỨNG]`.
3. **Thứ tự đăng ký.** Catch-all phải đăng ký sau closure tuỳ biến của người dùng, nếu không
   nó nuốt hết route riêng của họ. Hiện closure chạy ở dòng 83 — cuối group.

**Cách tự kiểm chứng:**
```bash
sed -n '121,140p' README.md    # xem hướng dẫn viết tay hiện tại
grep -n "sitemapLinks" -A 25 src/Http/Controllers/GhostController.php
```

**Hướng sửa đề xuất:** Thêm `routes.catch_all` (mặc định `false`, opt-in) vào bản đồ config
của B1. Chỉ triển khai sau khi đã thống nhất 3 điểm trên.

**Xong khi:** Có quyết định rõ ràng — hoặc triển khai kèm test phủ thứ tự ưu tiên route và
hành vi cache 404, hoặc ghi lại lý do từ chối vào doc này.

**Rủi ro:** Cao nếu bật mặc định. Giữ opt-in.

---

# NHÓM C — Tài liệu và trải nghiệm cài đặt

## C1. `robots.txt` có thể bị file tĩnh trong `public/` nuốt im lặng

**Mức độ:** Trung bình. **Giả định gốc của mục này ĐÃ SAI một phần — đọc kỹ.**

**Vấn đề:**
Nếu tồn tại `public/robots.txt`, web server (nginx `try_files $uri`, hoặc Apache) phục vụ file
tĩnh **trước khi** request chạm `index.php`. Khi đó route `example_com_robots` vẫn hiện trong
`route:list` nhưng **không bao giờ chạy**, và toàn bộ config `multidomain-ghost.robots.*` bị
bỏ qua. Không có cảnh báo nào. Với multi-domain thì càng tệ: một file tĩnh dùng chung sẽ trả
cùng một nội dung cho mọi domain — đúng thứ mà route sinh ra để tránh.

**Đính chính quan trọng:** `[ĐÃ KIỂM CHỨNG]`
App thật `/Volumes/Workspaces/Projects/herd_sites/multi_domain/public/` **KHÔNG** có
`robots.txt` (chỉ có `.htaccess`, `favicon.ico`, `index.php`, `build/`, `img/`, `home.html`).
Nên vấn đề này **hiện không xảy ra** trong app đang chạy. Nó là rủi ro cho cài đặt mới.

`[CẦN KIỂM CHỨNG]` — khẳng định "Laravel skeleton ship sẵn `public/robots.txt`" **chưa được
xác minh**. Agent phải tự kiểm tra `laravel/laravel` phiên bản 11/12/13 xem file đó có trong
skeleton không. **Nếu không có, toàn bộ mục C1 này mất căn cứ và nên đóng lại, không sửa gì.**

**Cách tự kiểm chứng:**
```bash
ls -la /Volumes/Workspaces/Projects/herd_sites/multi_domain/public/
# Kiểm tra skeleton Laravel:
composer create-project laravel/laravel /tmp/fresh-laravel --no-install --quiet 2>/dev/null
ls /tmp/fresh-laravel/public/
```

**Hướng sửa đề xuất (chỉ khi xác minh được):**
`GhostInstallCommand` cảnh báo khi phát hiện `public/robots.txt` hoặc `public/ads.txt`,
kèm hướng dẫn xoá/đổi tên. Không tự động xoá file của người dùng.
Vị trí: [`src/Console/Commands/GhostInstallCommand.php:19`](../src/Console/Commands/GhostInstallCommand.php#L19) (`handle()`).

**Xong khi:** Chạy `ghost:install` trong app có `public/robots.txt` → in cảnh báo rõ ràng;
không có file đó → im lặng. Có test phủ.

**Rủi ro:** Thấp — chỉ là cảnh báo.

---

## C2. Cơ chế override robots/ads theo từng domain đã chạy được nhưng không ai biết

**Mức độ:** Trung bình. Đây là **thiếu tài liệu**, không phải thiếu tính năng.

**Vấn đề:** `[ĐÃ KIỂM CHỨNG]`
Vì `LoadDomainConfiguration` chạy trước `BootProviders`, và `GhostController::robots()`/`ads()`
đọc `config()` tại **thời điểm request**, nên file `config/domains/{domain}.php` đã có thể
override nội dung robots/ads cho từng domain ngay hôm nay:

```php
// config/domains/example_com.php
return [
    'app.name' => 'Example',
    'multidomain-ghost.robots.disallow' => ['/admin', '/gio-hang'],
    'multidomain-ghost.robots.sitemap'  => 'https://example.com/sitemap.xml',
    'multidomain-ghost.ads.txt'         => "google.com, pub-123, DIRECT, f08c47fec0942fa0",
];
```

`README.md` **không hề nhắc** khả năng này ở bất kỳ đâu — mục "Per-domain configuration"
(dòng 137-150) chỉ ví dụ `app.name`, `app.url`, `cache.prefix`.

Bằng chứng nó chưa được dùng: cả hai file domain trong app thật đều không có khoá
`multidomain-ghost.*` nào `[ĐÃ KIỂM CHỨNG]`.

Đây chính là nửa còn lại của cảm giác "mù mờ": người dùng thấy `robots.txt` do package sinh
ra, không thấy đường nào để mỗi domain có nội dung riêng, nên kết luận là không kiểm soát được.

**Cách tự kiểm chứng:**
```bash
sed -n '18,27p' src/Foundation/Http/Kernel.php          # thứ tự bootstrap
sed -n '156,189p' src/Http/Controllers/GhostController.php  # đọc config lúc request
grep -n "multidomain-ghost" /Volumes/Workspaces/Projects/herd_sites/multi_domain/config/domains/*.php
# Thực chứng: thêm khoá override vào 1 domain, curl robots.txt của cả 2 domain, so sánh.
```

**Hướng sửa đề xuất:** Bổ sung mục README ví dụ override robots/ads (và bất kỳ khoá
`multidomain-ghost.*` nào) theo từng domain. Cân nhắc đưa ví dụ dạng comment vào stub sinh bởi
`domain:add` ([`GhostDomainAddCommand.php:76-86`](../src/Console/Commands/GhostDomainAddCommand.php#L76-L86)).

**Xong khi:** README có ví dụ đã chạy thật; hai domain trong app trả `robots.txt` khác nhau,
kiểm chứng bằng `curl`.

**Rủi ro:** Không có — chỉ là tài liệu.

---

# Thứ tự thực hiện đề xuất

| Bước | Mục | Lý do xếp trước |
| --- | --- | --- |
| 1 | **A1** | Lỗi thật, độc lập, rủi ro thấp |
| 2 | **A2** | Cùng chạm dòng 69, làm liền sau A1 |
| 3 | **C2** | Chỉ tài liệu, gỡ ngay phần lớn cảm giác "mù mờ" |
| 4 | **A3** | Độc lập, nhưng cần khảo sát slug thật trước |
| 5 | **B1** | Thay đổi cấu trúc — làm khi các mục nhỏ đã ổn định |
| 6 | **B2** | Gần như tự xong sau B1, chủ yếu là tài liệu |
| 7 | **C1** | Chỉ làm nếu xác minh được giả định skeleton |
| 8 | **B3** | Bàn bạc trước, đừng tự triển khai |

**Sau mỗi bước:** chạy `"$PHP" vendor/bin/pest` và `"$PHP" vendor/bin/pint --test`,
rồi verify end-to-end trong `/Volumes/Workspaces/Projects/herd_sites/multi_domain`
(package được autoload thẳng từ `src/`, không cần `composer update`).

**Nguyên tắc chống hồi quy xuyên suốt:** `tests/Feature/GhostDomainRoutesTest.php` khoá cứng
7 tên route và các `viewPath` mặc định. Trừ khi một mục **nói rõ** là đổi hành vi mặc định,
file test đó phải pass mà không cần sửa.

**Điểm thuận lợi:** view scaffold sinh bởi `domain:add` dùng `$post['canonical_url']` để tạo
link, **không** dùng helper `route()` `[ĐÃ KIỂM CHỨNG —` `GhostDomainAddCommand.php:218]`.
Nên đổi tên hoặc gỡ route blog **không phá view nào**. Chỉ có test là ràng buộc.
