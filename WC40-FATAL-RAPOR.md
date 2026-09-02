# BrikPanel - WooCommerce 4.0 fatal raporu

**Tarih:** 2026-08-26
**Surum:** 3.2.81 -> **3.2.82**
**Durum:** duzeltildi, olculdu, **YAYINLANMADI**

Bu belgede gecen her rakam gercek bir konteynerde uretildi. Gercek kullanici verisi
yoktur; konteyner bos bir WordPress kurulumudur.

---

## 1. Kusur

`as_has_scheduled_action()` Action Scheduler'a **3.3.0** ile eklendi. Eklenti basligi
`WC requires at least: 4.0` diyor, WooCommerce 4.0 ise Action Scheduler **3.1.2** ile
geliyor ve o surumde bu fonksiyon **yok**.

Cagri `init` uzerinde oldugu icin sonuc bozuk bir ozellik degil, **bozuk bir site**.
Ve kilit nokta: fatal WordPress yuklenirken oldugu icin **WP-CLI de yuklenemiyor**,
yani magaza sahibi eklentiyi ne panelden ne komut satirindan kapatabiliyor.

### Kapi neden tutmuyordu

`Brikpanel_Cron::is_available()` yalnizca uc fonksiyona bakiyordu:

```php
return function_exists( 'as_enqueue_async_action' )
    && function_exists( 'as_schedule_single_action' )
    && function_exists( 'as_schedule_recurring_action' );
```

Ucu de AS 3.1.2'de **var**. Kapi ACIK donuyor, sonra dorduncu cagri patliyor.
Kapi yanlis degildi, **eksikti**: sinif yedegi olmadan yedi `as_*` fonksiyonu
cagiriyor, kapi ucunu sayiyordu.

### AS 3.1.2'de ne var, ne yok (olculdu, varsayilmadi)

| Fonksiyon | AS 3.1.2 |
|---|---|
| `as_enqueue_async_action` | VAR |
| `as_schedule_single_action` | VAR |
| `as_schedule_recurring_action` | VAR |
| `as_schedule_cron_action` | VAR |
| `as_get_scheduled_actions` | VAR |
| `as_next_scheduled_action` | VAR |
| `as_unschedule_action` | VAR |
| `as_unschedule_all_actions` | VAR |
| **`as_has_scheduled_action`** | **YOK** |

---

## 2. Tam cagri envanteri

Tarama: `includes/`, `front-end/`, `back-end/`, `brikpanel.php` (139 PHP dosyasi),
docblock metinleri haric.

**Sevk edilen kodda 10 `as_*` cagrisi, 2 dosyada. 3'u korumasizdi.**

| # | Konum | Cagri | Durumu (once) |
|---|---|---|---|
| 1 | `includes/cron/class-brikpanel-cron.php:276` | `as_has_scheduled_action` | **KORUMASIZ - fatal** |
| 2 | `includes/cron/class-brikpanel-cron.php:380` | `as_has_scheduled_action` | **KORUMASIZ - fatal** |
| 3 | `front-end/google-sheets/brikpanel-google-sheets.php:154` | `as_has_scheduled_action` | **KORUMASIZ - fatal** |
| 4 | `class-brikpanel-cron.php:363` | `as_unschedule_action` | kapida adi yok |
| 5 | `class-brikpanel-cron.php:352` | `ActionScheduler_Store::STATUS_PENDING` | `class_exists` yok, on-yuzden erisilebilir |
| 6 | `class-brikpanel-cron.php:235` | `as_enqueue_async_action` | kapi var, istisna korumasi yok |
| 7 | `class-brikpanel-cron.php:253` | `as_schedule_single_action` | kapi var, istisna korumasi yok |
| 8 | `class-brikpanel-cron.php:289` | `as_schedule_recurring_action` | kapi var, istisna korumasi yok |
| 9 | `class-brikpanel-cron.php:307` | `as_get_scheduled_actions` | kendi `function_exists`'i vardi |
| 10 | `class-brikpanel-cron.php:406` | `as_get_scheduled_actions` | kendi `function_exists`'i vardi |
| 11 | `class-brikpanel-cron.php:545` | `as_enqueue_async_action` | zaten `try/catch` icinde |

**Brief'in verdigi baslangic listesi iki noktada duzeltildi:**
`front-end/brikcontrol/class-brikpanel-brikcontrol-runner.php:306` ve `:345` ile
`class-brikpanel-cron.php:277` **gercek cagri degil, docblock metnidir**. Gercek
korumasiz cagri sayisi 6 degil **3**'tur.

### Fatal'a giden yollar (hepsi on-yuz `init`)

```
init:20 -> brikpanel_cron_register -> Sheets/cart-abandonment/customer-analytics/
           ads/brikcontrol schedule_recurring() -> class-brikpanel-cron.php:276
init:20 -> ads-sync.php:105 -> is_scheduled() -> class-brikpanel-cron.php:380
init:30 -> brikpanel-google-sheets.php:140 -> :154
```

Konteynerdeki gercek yigin izi:

```
PHP Fatal error: Uncaught Error: Call to undefined function as_has_scheduled_action()
  in .../includes/cron/class-brikpanel-cron.php:276
Stack trace:
#0 .../includes/cron/customer-analytics-jobs.php(536):
     Brikpanel_Cron::schedule_recurring('brikpanel_recom...', 86400, Array, 42283)
#3 /var/www/html/wp-includes/plugin.php(470): WP_Hook->do_action(Array)
#4 .../includes/cron/brikpanel-cron.php(48): do_action('brikpanel_cron_...')
```

---

## 3. Secilen cozum

Alti parca, bagimlilik sirasiyla:

1. **`REQUIRED_FUNCTIONS` sabiti** - sinifin **yedegi olmadan** cagirdigi her
   `as_*` fonksiyonu. Kurali yapisal yapar: yeni bir cagri ya listeye girer ya
   kendi yedegiyle gelir. `as_has_scheduled_action` **bilerek listede degil**
   (bkz. reddedilen alternatif 1).
2. **`is_available()`** artik bu liste uzerinde doner.
3. **`LIVE_STATUSES = ['pending','in-progress']`** - literal, `ActionScheduler_Store::`
   sabiti degil; cunku tam da store sinifinin bekledigimiz sinif olmayabilecegi anda
   okunuyor.
4. **`has_scheduled()` dagiticisi** - modern fonksiyon varsa o, yoksa yedek. Tamami
   `try/catch` icinde ve **hata halinde `true`** doner ("bilemiyorum, ustune
   yigmayayim"), ki bu `has_live_action()` ve `recurring_interval_matches()` ile ayni
   ev davranisi.
5. **`has_scheduled_fallback()`** - uc kademe: (a) `as_get_scheduled_actions`,
   **status basina ayri skaler sorgu**; (b) `as_next_scheduled_action`; (c) `false`.
6. **`guarded()`** - her AS yazmasini sarar, `Throwable` -> `false`.

Ayrica: `cancel()` artik `'pending'` literalini kullaniyor, `as_unschedule_action`
`guarded()` icinde, ve yeni public `has_any_scheduled( $hook )` args-kor sorusu
Google Sheets supurgesine hizmet ediyor.

---

## 4. Reddedilen alternatifler

### 4.1 Tek sorguda status DIZISI (en onemlisi)

Yedegin bariz hali su:

```php
as_get_scheduled_actions( [ ..., 'status' => [ 'pending', 'in-progress' ] ] );
```

Bu bir fatal'i **ayni magazalarda baska bir fatal'la** degistirirdi. Konteynerde
dogrudan uretildi:

```
=== REDDEDILEN TASARIM: tek sorguda status DIZISI ===
  FIRLATTI: InvalidArgumentException: Invalid action status: "Array".

=== SECILEN TASARIM: status basina AYRI skaler sorgu ===
  status=pending      TAMAM -> 1 satir
  status=in-progress  TAMAM -> 0 satir

=== UCUNCU KADEME: as_next_scheduled_action (3.1.2de var mi) ===
  ayni args      -> 1787760608
  FARKLI args    -> false
```

### 4.2 Kapiyi `function_exists` ile kapatmak (sert kapi)

`as_has_scheduled_action`'i `REQUIRED_FUNCTIONS`'a koymak fatal'i giderirdi, ama
WC 4.0 magazasinda `is_available()` `false` donerdi ve **hicbir sey zamanlanmazdi**:
cokme yerine sessiz olum. Kurtarmak istedigimiz magazalardan duzeltmeyi saklamak
olurdu.

### 4.3 Yalnizca `as_next_scheduled_action` kullanmak

Yalnizca `pending` gorur, calismakta olan tekrarli bir isi kaciririr ve mukerrer
kayit uretebilir. Ucuncu care olarak birakildi.

### 4.4 `store_ready()` (BrikMentor'daki multisite tablo probe'u)

Alinmadi. Ayri bir olay (eksik tablo), bu fatal degil; istek basina bir
`SHOW TABLES` maliyeti getirir ve yazma tarafini zaten `guarded()` kapatiyor.
Kullanici karari.

---

## 5. Mukerrer is analizi

**Sorunun sekli:** yedek "pending" gorup "in-progress"i kacirirsa ayni is ikinci kez
zamanlanabilir.

**BrikPanel'de idempotent OLMAYAN tek is:** BrikControl **manuel taramasi**
(`brikpanel_brikcontrol_scan`, args `['manual'=>1]`). Iki satir yan yana gelince
`runner.php:117` birbirinin batch zincirini iptal ediyor; 3.2.70'teki CPU olayinin
sekli tam olarak budur. AS'in `unique` bayragi hook+group esledigi icin ise yaramaz,
dedupe'u `is_scheduled()` yapar.

**Sonuc: risk sifir.** Birinci kademe (`as_get_scheduled_actions` x 2 skaler status)
`pending` VE `in-progress` gordugu icin modern fonksiyonla **birebir ayni** cevabi
verir. Yalnizca `pending` goren ucuncu kademe ise `as_get_scheduled_actions` artik
`REQUIRED_FUNCTIONS`'ta oldugu icin **hicbir public giristen erisilemez**: o fonksiyon
yoksa `is_available()` zaten `false` doner ve `is_scheduled()` / `schedule_recurring()`
cagriya hic ulasmaz.

Diger tekrarli isler idempotent: `recompute_customer_metrics` /
`cohort_retention` (`INSERT ... ON DUPLICATE KEY UPDATE`), `cartab_flip_abandoned`
(cutoff'a gore UPDATE), `ads_daily_sync`, Sheets push/pull (PUSH_LOCK / PULL_LOCK).

**Ve bu sahada olculdu:** duzeltmeden sonra konteynerde 20 ek istek atildi, kuyruk
**5 iste sabit kaldi**. `as_has_scheduled_action` yokken mukerrer kaydi engelleyen
sey yedegin ta kendisi.

---

## 6. Konteynerin uc ciktisi

**Yigin:** WordPress **6.0.9** / WooCommerce **4.0.0** / PHP **7.4.27** /
Action Scheduler **3.1.2**. Imajlar: `wordpress:5.8-php7.4-apache` (WP 6.0.9'a
guncellendi), `mysql:8.0`, `wordpress:cli-php7.4`.
PHP production ayari (`display_errors=Off`), BrikMentor konteynerde yok.

### (1) ONCE - fatal

```
  on-yuz  /                              HTTP 500
  on-yuz  /?post_type=product            HTTP 500
  on-yuz  /wp-login.php                  HTTP 500
  admin   /wp-admin/                     HTTP 500
  admin   /wp-admin/plugins.php          HTTP 500
  admin   /admin.php?page=brikpanel-cron HTTP 500

  'wp plugin deactivate' cikis kodu: 255
  Call to undefined function as_has_scheduled_action()
```

Magaza sahibinin cikis yolu yok: giris sayfasi bile 500, WP-CLI de oluyor.

### (2) SONRA - ayakta

```
  on-yuz  /                              HTTP 200
  on-yuz  /?post_type=product            HTTP 200
  on-yuz  /wp-login.php                  HTTP 200
  on-yuz  /?feed=rss2                    HTTP 200
  admin   /wp-admin/plugins.php          HTTP 200
  admin   /admin.php?page=brikpanel-cron HTTP 200
  admin   /admin.php?page=wc-settings&tab=brikpanel  HTTP 200

  WP-CLI: brikpanel-...,3.2.81  (cikis kodu 0)
  PHP hatasi: LOG BOS - tek bir PHP hatasi yok
```

### (3) CALISIYOR - is gercekten kuyruga giriyor

```
AS 3.1.2 | as_has_scheduled_action: YOK | is_available(): true

brikpanel grubunda BEKLEYEN is: 5
  #23    brikpanel_brikcontrol_scan_batch           tek atislik
  #8     brikpanel_cartab_flip_abandoned            her 600sn
  #9     brikpanel_brikcontrol_scan                 her 86400sn
  #6     brikpanel_recompute_customer_metrics       her 86400sn
  #7     brikpanel_recompute_cohort_retention       her 86400sn

20 istek daha sonrasi bekleyen is: 5     <- yedek mukerrer kaydi engelliyor
```

Ve `tools/test-as-floor.php` ayni konteynerde **32/0** (yedegin GERCEK yol oldugu
yerde, reflection ile degil).

---

## 7. Ilan edilen taban karari

**`WC requires at least: 4.0` KALIYOR.** Varsayilmadi, olculdu.

Duzeltmeden sonra ayni konteynerde `Brikpanel_Cron`'un 12 yuzeyi ve kayitli
**17 hook'un 17'si** tek tek kosturuldu:

```
WP 6.0.9 | WC 4.0.0 | PHP 7.4.33 | AS 3.1.2
  is_available / is_scheduled / has_any_scheduled / query / count /
  get_registered_hooks / enqueue_async / schedule_single / schedule_recurring /
  get_action_args / get_logs / cancel .................... 12/12 OK
  17 kayitli hook (do_action) ............................ 17/17 OK
  brikpanel_gs_unschedule_when_disabled() ................ OK
```

Tabani yukseltmek **mevcut kurbanlari kurtarmaz** (zaten cokmus durumdalar, sadece
guncelleme almazlar), o yuzden koruma her halukarda zorunluydu; ve olcum tabani
yukseltmek icin bir sebep de vermedi.

### WP 5.8.3 notu (durustluk gerektiriyor)

Ilk gecis brief'in verdigi WP 5.8.3 ile yapildi ve orada **ikinci** bir fatal cikti:
`Call to undefined function str_starts_with()` (PHP 8.0 fonksiyonu, PHP 7.4'te
cagriliyor; `brikpanel-navigation.php` 5 yer, `brikpanel-products-list.php` 2 yer).

Bu bir kusur **degil**: WordPress cekirdegi `str_starts_with` / `str_contains` /
`str_ends_with` polyfill'lerini **5.9** ile ekledi ve BrikPanel
`Requires at least: 6.0` diyor. Olculdu: WP 5.8.3 `compat.php`'de polyfill **yok**,
WP 6.0.9'da **var**. Yani bu cokme eklentinin ilan ettigi WP tabaninin **altinda**
kaliyor. Bu yuzden nihai kanit WP 6.0.9 uzerinde uretildi.

---

## 8. Olcum sirasinda bulunan ve DUZELTILEN iki sey (planda yoktu)

### 8.1 AS migrasyon istisnasi `init`'e ciiyordu

AS kendi post-store / table-store migrasyonu penceresindeyken `HybridStore`
`RuntimeException: Error saving action: Incorrect table name ''` atiyor. AS'in kendi
kusuru (o pencerede kendi migrasyon isini de zamanlayamiyor), ama istisnayi tasiyan
biziz: `init` uzerinden cikip yeni kurulan magazanin her sayfasini goturuyordu.

**Dort yazma cagrisi** (`as_enqueue_async_action`, `as_schedule_single_action`,
`as_schedule_recurring_action`, `as_unschedule_action`) `guarded()` icine alindi ve
kapinin zaten dondugu `false`'a cevriliyor. Is bir sonraki istekte aliniyor, cunku
kayitlar idempotent ve `init` uzerinde.

### 8.2 Scheduled Tasks ekrani WC 4.0'da oluyordu

AS 3.1.2 **iptal edilmis** bir aksiyonu hidrate edemiyor: iptal satirinin schedule
tarihi `null` ve factory tip hatasi atiyor.

```
TypeError: Argument 1 passed to ActionScheduler_Abstract_Schedule::__construct()
must be an instance of DateTime, null given
```

`class-brikpanel-cron-page.php:309`'daki `fetch_action()` dongusu korumasizdi, yani
**tek bir iptal satiri butun ekrani goturuyordu**. Bu, bir Google Sheets senkronunu
kapatan her magazada olan sey: olculdu, **19 satirin 5'i** hidrate edilemiyordu.

Once / sonra, ayni konteynerde:

```
GERI ALINMIS:  status=''  -> HTTP 500  (TypeError)
DUZELTILMIS:   status=''         HTTP 200  cizilen=18  toplam=25
               status='pending'  HTTP 200  cizilen=5   toplam=5
               status='canceled' HTTP 200  cizilen=13  toplam=20
```

Cizilemeyen satir artik atlaniyor. Sayfanin toplam sayaci store'dan geldigi icin
WC 4.0'da cizilen satirdan fazla gosterebilir; bu bilincli bir takas, alternatifi
ekranin hic acilmamasi.

---

### 8.3 OKUMALAR da `init` uzerinde ve onlar da korumasizdi

`guarded()` yazmalari kapatiyor, ama migrasyon istisnasi cagrinin yazma olup
olmadigina bakmiyor. Iki OKUMA da `init` uzerinde kosuyor:

- `recurring_interval_matches()` - her `schedule_recurring()` cagrisinda,
- `query()` - `cancel()` uzerinden, KAPALI her Sheets senkronu icin, her on-yuz
  sayfa gosteriminde.

Ikisi de korumasiz `as_get_scheduled_actions()` cagiriyordu, yani ayni HTTP 500
baska bir yoldan. Ikisi de `try/catch` icine alindi: `recurring_interval_matches()`
zaten "bilemiyorum -> dokunma" davranisina sahip oldugu icin `true`, `query()` ise
AS'e hic ulasilamadiginda her cagiranin zaten aldigi `[]` doner. Suite bunu yapisal
olarak zorluyor (32. iddia) ve geri alindiginda `query()`'yi adiyla isaret ederek
KIRMIZI oluyor.

---

## 9. Dogrulama araci

`tools/test-as-floor.php`, `wp eval-file` ile kosar, **32 iddia**, kalici hicbir sey
yazmaz (probe aksiyonlarini teardown'da iptal eder ve kuyrugun temiz oldugunu
dogrular).

1. **Yapisal:** her `as_*` cagrisi ya `REQUIRED_FUNCTIONS`'ta, ya 3 satirlik
   `function_exists` penceresinde, ya da yedek sarmalayici **metotlarinin** icinde.
   Muafiyet dosya/fonksiyon adina degil metoda baglandi, cunku ad bazli muafiyet
   orijinal kazanin cagri yerlerini (`schedule_recurring`, `is_scheduled`) temiz
   okurdu.
2. **Dizi-status yasagi:** AS sorgu cagri yerlerinin **iki yanindaki** pencere
   taranir, ayrica `has_scheduled_fallback()` govdesinin sekli dogrulanir.
3. **Davranissal:** yedek `ReflectionMethod` ile DOGRUDAN cagrilir (modern AS'te
   dagitici oraya hic ulasmaz; hic kosmayan yedek, ihtiyac duyuldugu gun bozuk olan
   yedektir), modern fonksiyonla ayni cevabi verdigi ve farkli args'i ayirt ettigi
   olculur.
4. **Yazma korumasi:** atan yazma `false` doner, saglikli yazma degerini gecirir,
   ve dort yazma cagrisinin `guarded()` icinde oldugu yapisal olarak dogrulanir.

### Suitin kendisi iki kez yaniltiyordu, ikisi de duzeltildi

- Ilk hali sessizce **"PASS 0 FAIL 0, all clear"** diyordu: WP-CLI `eval-file` kodu
  fonksiyon kapsaminda kostugu icin `global $bpaf` bos bagliyordu. Yesil gorunen
  sahte bir sonuc, en tehlikeli tur. `$GLOBALS` ile duzeltildi ve **sifir iddia artik
  basarisizlik sayiliyor**.
- Dizi-status geri-almasini **yakalamiyordu**: tarama yalnizca cagrinin altina
  bakiyordu, sorgu dizisi ise cagrinin ustunde kuruluyor. Pencere iki yanli yapildi
  ve sekil-farkindali ikinci bir iddia eklendi.

### Kirmizi oldugu olculen dort geri-alma senaryosu

| Geri alinan | Suite sonucu |
|---|---|
| Eski uc-fonksiyonlu kapi + ciplak cagrilar | **KIRMIZI** (iki cagri yerini isimlendirdi) |
| Reddedilen dizi-status yedegi | **KIRMIZI** (2 iddia) |
| `guarded()` sarmalayicisinin kaldirilmasi | **KIRMIZI** |
| Cron sayfasi `fetch_action` duzeltmesi | **KIRMIZI** (konteynerde HTTP 500) |
| `query()` okuma korumasi | **KIRMIZI** (`query()` adiyla isaretlendi) |

### Nerede kosturuldu

| Ortam | Sonuc |
|---|---|
| vermond-test (WP 7.0.4 / WC 10.8.1 / PHP 8.3) | **32/0** |
| multisite ana site (WC 10.9.1) | **32/0** |
| multisite alt site (`/shop2/`) | **32/0** |
| Konteyner (WP 6.0.9 / WC 4.0.0 / PHP 7.4) | **32/0** |

---

## 10. Kapsam disi birakilan gozlemler (duzeltilmedi)

1. **`Brikpanel_Cron::cancel()` on kontrolsuz kosuyor.** Sheets senkronlari kapali
   bir magazada, anonim sayfa gosterimi basina ~5 AS store sorgusu. Yeni
   `has_any_scheduled()` bunu tek satirda kapatir. Kullanici karari: bu surumde
   yapilmadi.
2. **`Brikpanel_Cron::query()` status filtresi olmadan** AS 3.1.2'de cokuyor
   (madde 8.2 ile ayni kok neden). Sevk edilen dort cagri yerinin dordu de filtre
   gectigi icin gercek bir yol degil; docblock'a yazildi.
3. **`describe_status()`** icindeki `ActionScheduler_Store::` sabitleri korumasiz.
   Yalnizca admin cagricisi var ve o `class_exists` arkasinda.
4. **`brikpanel-google-sheets.php` supurme listesi eksik**: `_order_realtime_flush`,
   `_order_update_rows`, `_customers_snapshot` hic iptal edilmiyor. Ucu de tek
   atislik, dolayisiyla sinirli.
5. **`WC tested up to: 9.4`** guncel degil (sahada WC 10.8+).
6. **WP 5.9 altinda `str_starts_with`** (madde 7). Ilan edilen WP tabaninin altinda,
   kusur sayilmadi.
7. **`store_ready()`** alinmadi (madde 4.4).
10. **`.playwright-mcp/` klasoru eklenti agacinda duruyor.** Onceki bir oturumdan
   kalma 4 test artigi (2026-08-24 tarihli, 48 KB) eklenti klasorunun icinde ve
   paketlenirse wp.org'a gider. Bu is sirasinda yanlislikla silindi ve yedekten
   **geri yuklendi**, cunku benim degisiklik kumemin parcasi degil. Silinmesi
   kullanicinin karari.
8. **`dbDelta` "Multiple primary key defined" uyarilari.** Surum yukseltme rutini
   (`brikpanel_maybe_upgrade_db`) kostugunda 13 tablo icin WordPress veritabani
   uyarisi yaziliyor; sema tanimi sutunun kendisinde `AUTO_INCREMENT PRIMARY KEY`
   diyor (`brikpanel.php:892` vd.). Fatal degil, uyari; her surum artisinda olan
   onceden var olan bir durum ve bu is kapsaminda semaya dokunulmadi (diff:
   `brikpanel.php`'de yalnizca iki surum satiri degisti).
9. **Ayni hook'tan iki bekleyen satir yaniltici gorunebilir.** Surum artisindan
   sonra `brikpanel_recompute_customer_metrics` ve `_cohort_retention` icin biri
   TEKRARLI biri TEK ATISLIK olmak uzere iki satir olusuyor. Mukerrer degil:
   tek atislik olan, yukseltme rutininin bilincli ilk hesaplama tetigi
   (`brikpanel.php:1481-1482`, `unique => true`, iki handler de idempotent UPSERT).

---

## 11. Regresyon turu: "baska bir seye carpti mi"

Duzeltmenin dogru olmasi yetmez, MEVCUT davranisi bozmamis olmasi da gerekiyor.
Olculenler:

### 11.1 Diferansiyel esdegerlik (en guclu kanit)

Yeni yol ile eski yolun ayni cevabi verdigi VARSAYILMADI, kombinasyon kombinasyon
karsilastirildi: 19 hook x 5 args sekli = **95 kombinasyon**, her birinde hem
`has_scheduled()` dagiticisi hem `has_scheduled_fallback()` yedegi
`as_has_scheduled_action()` ile karsilastirildi.

```
PASS  95 kombinasyonda yedek ve dagitici, as_has_scheduled_action ile BIREBIR ayni cevabi verdi
```

Ayrica literallerin dogrulugu olculdu, varsayilmadi:

```
PASS  ActionScheduler_Store::STATUS_PENDING === 'pending'
PASS  STATUS_RUNNING === 'in-progress'
```

### 11.2 Kapi daralmadi

`is_available()` 3 yerine 5 fonksiyon istiyor. Bu, daha once acik olan bir kapiyi
kapatabilirdi. Eklenen ikisi (`as_get_scheduled_actions`, `as_unschedule_action`)
hem dev sitede hem WC 4.0 / AS 3.1.2 konteynerinde MEVCUT olculdu, yani kapi hicbir
desteklenen surumde daralmiyor.

### 11.3 Cagiranlar: donus degeri sozlesmesi

Degistirdigim metotlarin butun cagiranlari tarandi (is_available 27, enqueue_async 15,
schedule_recurring 13, cancel 24, is_scheduled 8, query 4). Donus degerini gercekten
KULLANAN bes yer var ve besi de `false`'i zaten bekliyor - docblock'lari birebir
"or false when nothing could be queued" diyor, `enqueue_next_batch()` bunun icin
yedek yol + kilit birakma yapiyor, `:435` ise "Keep the original row rather than end
up with none" diyor. Yani istisna yerine `false` donmek bu cagiranlar icin yeni bir
durum degil, zaten yazili olan sozlesme.

`is_scheduled()`'in bes tuketicisinin hepsi boolean kapisi ve throw halindeki `true`
fail-safe'i besinde de muhafazakar dala gidiyor (ustune is yigmamak).

### 11.4 cancel() davranisi bire bir ayni

```
PASS  iki farkli args -> iki satir
PASS  cancel(args) yalnizca eslesen satiri iptal etti ve 1 dondu
PASS  geriye 1 satir kaldi
PASS  cancel(null) kalani iptal etti ve 1 dondu
PASS  bos hook uzerinde cancel 0 doner
```

### 11.5 Grup izolasyonu (baska eklentiye carpmiyor)

```
PASS  cancel() BrikPanel grubu disina cikamiyor
PASS  query() group parametresi zorla brikpanel kaliyor
```

Ve HTTP uzerinden, WooCommerce'in kendi aksiyonu hedeflenerek:

```
cancel  -> {"success":false,"message":"Action does not belong to BrikPanel."}
retry   -> {"success":false,"message":"Action does not belong to BrikPanel."}
logs    -> HTTP 404 {"message":"Action not found."}
```

### 11.6 Gercek kullanici akislari (dev site, tarayici + HTTP)

- **Store Health manuel tarama dedupe'u** (tek idempotent olmayan is):
  1. tetikleme -> id dondu, 2. ve 3. tetikleme -> `0` (dedupe), kuyrukta **tek** satir.
  3.2.70'teki CPU olayinin senaryosu korunuyor.
- **Google Sheets ac/kapa tam turu**: modul kapatildi -> supurge 3 tekrarli isi
  temizledi (fatal yok, degistirdigim cagri yeri), geri acildi -> 3'u de geri geldi,
  ayar orijinal degerinde birakildi.
- **Scheduled Tasks ekrani**: 6 status filtresinin hepsi HTTP 200, KPI ucu 200,
  hook filtresi 200, `logs` / `cancel` / `retry` uclari calisiyor. Guvenlik tuttu:
  nonce'suz **403**, oturumsuz **400**.
- **Ekran taramasi**: 8 BrikPanel ekrani + 7 WP/WC ekrani + 4 on-yuz adresi, hepsi
  200 (302'ler BrikPanel'in kendi yonlendirmeleri, takip edildi, 200'de bitiyor).
  Bu tur boyunca `debug.log`'a **0 bayt** eklendi.
- **Basarisiz isler**: 173 adet var ama **hepsi calisma penceresinden ONCE**;
  sebepleri "Not connected to Google Sheets" / "HTTP 400" gibi baglanti hatalari.
  Bu turda **sifir yeni** basarisizlik.
- **Multisite**: ag genelinde etkin; ana site ve `/shop2/` alt sitesinde
  `is_available()` true, 3 kayit turunda kuyruk sismedi, suite 32/0.

### 11.7 Performans

`is_available()` istek basina cok cagriliyor, o yuzden olculdu:

```
ESKI (3 kontrol): 0.0712 us/cagri
YENI (5 kontrol): 0.1495 us/cagri
fark            : +0.078 us/cagri
istek basina ~30 cagri -> +0.0023 ms
```

290 ms'lik bir on-yuz isteginin **%0.0008'i**. Olculebilir bir etki yok.

### 11.8 Modern magazalarda sifir etki

Cron sayfasindaki yeni hidrate korumasi yalnizca AS'in satiri kuramadigi durumda
devreye giriyor. Dev sitede (AS 3.9) her status icin `cizilen == sayfa boyutu`, yani
**hicbir satir atlanmiyor**. Koruma sadece eski AS'te is yapiyor.

### 11.9 Sevk edilen yeni dosya web'den zarar veremiyor

`tools/test-as-floor.php` dogrudan HTTP ile cagrilirsa artik **HTTP 200 / 0 bayt**
donuyor. Ilk hali 500 veriyordu, cunku `STDERR` sabiti yalnizca CLI SAPI'de tanimli;
`defined('STDERR')` kontrolu eklendi. (Mevcut `tools/i18n-audit.php` ve
`tools/option-prime-audit.php` hala 500 donuyor - ayni ev kalibi, kapsam disi
birakildi, bkz. madde 10.)

### 11.10 Lint, tum eklenti

PHP **8.3** ve PHP **7.4** ile eklentideki her PHP dosyasi: **0 hata**.

### 11.11 Artik birakilmadi

Uc sitede de sifir probe/test aksiyonu, birakilmis secenek/transient yok, Sheets
ayari orijinal degerinde, dev site kuyrugunda her hook'tan tek satir. Degisiklik
kumesi tam olarak **9 dosya** (2 yeni + 7 degisen), **0 dosya kaybi** (313 -> 315).

---

## 12. Degisen dosyalar

| Dosya | Ne |
|---|---|
| `includes/cron/class-brikpanel-cron.php` | Kapi, `REQUIRED_FUNCTIONS`, `LIVE_STATUSES`, `has_scheduled()`, `has_scheduled_fallback()`, `guarded()`, `has_any_scheduled()`, `cancel()` literal status |
| `includes/cron/class-brikpanel-cron-page.php` | `fetch_action()` hidrate korumasi (madde 8.2) |
| `front-end/google-sheets/brikpanel-google-sheets.php` | Ciplak cagri -> `has_any_scheduled()` |
| `tools/test-as-floor.php` | **YENI** - 32 iddialik dogrulama suiti |
| `brikpanel.php` | Surum 3.2.82 (baslik + `BRIKPANEL_VERSION`) |
| `readme.txt` | `Stable tag: 3.2.82`, 3.2.82 ve 3.2.81 changelog girdileri |
| `changelog.txt` | 3.2.80 / 3.2.81 / 3.2.82 arsivlendi |
| `liste.md` | Girdi |

**Yeni ayar yok. Arayuzde degisiklik yok. Yeni kullaniciya gorunen metin yok**
(`php tools/i18n-audit.php` exit 0, .pot degismedi).

**Yedek:** degisiklik oncesi eklenti agacinin disina byte-exact yedek alindi
(313 dosya) ve geri yuklenebilirligi md5 manifest karsilastirmasiyla kanitlandi.

**YAYINLANMADI.** wp.org'a gonderilmedi, zip uretilmedi, dagitim yapilmadi.
Yayin kullanicinin adimidir.
