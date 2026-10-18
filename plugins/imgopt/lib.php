<?php
declare(strict_types=1);
/**
 * ImgOpt —— 51普洱茶网 图片优化核心
 *
 * 能力：
 *   1) localizeExternal(): 把外部（http/https）图片下载到本地 uploads/mall，转 WebP。
 *   2) toWebp(): 任意常见位图（jpg/png/gif/bmp）→ WebP（优先 cwebp，回退 GD，再回退拷贝）。
 *   3) makeThumb(): 生成 _t.webp 缩略图（默认宽 400，等比）。
 *   4) thumb($url): 给商城卡片用的缩略图 URL 推导（仅对本地 .webp 生效，否则原样返回）。
 *
 * 存储约定（相对站点根）：
 *   uploads/mall/goods/{gid}/cover.webp  + cover_t.webp
 *   uploads/mall/goods/{gid}/g1.webp     + g1_t.webp   （图册）
 *   uploads/mall/brand/{bid}.webp
 *   uploads/mall/cat/{cid}.webp
 *   uploads/mall/review/{rid}/{idx}.webp
 * URL 前缀：/uploads/mall/...
 */

class ImgOpt
{
    /** 站点根目录（lib.php 在 plugins/imgopt/，上两级即 src 根） */
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** 本地图片根目录（文件系统） */
    public static function storeDir(): string
    {
        return self::root() . '/uploads/mall';
    }

    /** 本地图片 URL 前缀 */
    public static function urlBase(): string
    {
        return '/uploads/mall';
    }

    private static ?string $cwebp = null;
    private static ?string $gif2webp = null;
    private static bool $toolProbed = false;

    public static function thumbWidth(): int { return 400; }

    /** 探测可用编码器（cwebp / gif2webp），仅探测一次 */
    public static function probeTools(): void
    {
        if (self::$toolProbed) { return; }
        self::$toolProbed = true;
        foreach (['/usr/bin/cwebp', '/usr/local/bin/cwebp', 'cwebp'] as $p) {
            $r = trim((string)@shell_exec("command -v " . escapeshellarg($p) . " 2>/dev/null"));
            if ($r !== '' && is_file($r)) { self::$cwebp = $r; break; }
        }
        foreach (['/usr/bin/gif2webp', '/usr/local/bin/gif2webp', 'gif2webp'] as $p) {
            $r = trim((string)@shell_exec("command -v " . escapeshellarg($p) . " 2>/dev/null"));
            if ($r !== '' && is_file($r)) { self::$gif2webp = $r; break; }
        }
    }

    public static function cwebpPath(): ?string { self::probeTools(); return self::$cwebp; }
    public static function gif2webpPath(): ?string { self::probeTools(); return self::$gif2webp; }

    /** 确保目录存在 */
    public static function ensureDir(string $rel): void
    {
        $d = self::storeDir() . '/' . $rel;
        if (!is_dir($d)) { @mkdir($d, 0755, true); }
    }

    /** 下载外部 URL 到本地临时文件；成功返回 true */
    public static function download(string $url, string $tmp): bool
    {
        if ($url === '' || !preg_match('#^https?://#i', $url)) { return false; }
        // 优先 curl（最稳，支持重定向/超时）
        $cmd = 'curl -sSL --max-time 40 -o ' . escapeshellarg($tmp) . ' ' . escapeshellarg($url) . ' 2>/dev/null';
        @exec($cmd, $out, $rc);
        if ($rc === 0 && is_file($tmp) && filesize($tmp) > 0) { return true; }
        // 回退 file_get_contents
        $ctx = stream_context_create(['http' => ['timeout' => 40, 'follow_location' => true], 'https' => ['timeout' => 40, 'follow_location' => true]]);
        $bin = @file_get_contents($url, false, $ctx);
        if ($bin !== false && $bin !== '') { return (bool)@file_put_contents($tmp, $bin); }
        return false;
    }

    /** 位图 → WebP。返回目标文件是否生成成功 */
    public static function toWebp(string $src, string $dst, int $quality = 82): bool
    {
        if (!is_file($src) || filesize($src) <= 0) { return false; }
        self::probeTools();
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $isGif = ($ext === 'gif');
        // 1) gif2webp
        if ($isGif && self::$gif2webp) {
            @exec(escapeshellarg(self::$gif2webp) . " -q {$quality} " . escapeshellarg($src) . ' -o ' . escapeshellarg($dst) . ' 2>/dev/null', $o, $rc);
            if ($rc === 0 && is_file($dst) && filesize($dst) > 0) { return true; }
        }
        // 2) cwebp
        if (self::$cwebp) {
            @exec(escapeshellarg(self::$cwebp) . " -q {$quality} " . escapeshellarg($src) . ' -o ' . escapeshellarg($dst) . ' 2>/dev/null', $o, $rc);
            if ($rc === 0 && is_file($dst) && filesize($dst) > 0) { return true; }
        }
        // 3) GD 回退
        if (function_exists('imagewebp')) {
            $im = null;
            if ($ext === 'jpg' || $ext === 'jpeg') { $im = @imagecreatefromjpeg($src); }
            elseif ($ext === 'png') { $im = @imagecreatefrompng($src); }
            elseif ($ext === 'gif') { $im = @imagecreatefromgif($src); }
            elseif ($ext === 'bmp') { $im = @imagecreatefrombmp($src); }
            elseif ($ext === 'webp') { $im = @imagecreatefromwebp($src); }
            if ($im) {
                imagepalettetotruecolor($im);
                $ok = @imagewebp($im, $dst, $quality);
                @imagedestroy($im);
                if ($ok && is_file($dst) && filesize($dst) > 0) { return true; }
            }
        }
        // 4) 实在没有编码器：至少把原文件拷过来（保证本地化，牺牲 WebP）
        return @copy($src, $dst) && is_file($dst) && filesize($dst) > 0;
    }

    /** 生成缩略图（默认宽 400 等比），输出 _t.webp（或同名 + _t.原扩展） */
    public static function makeThumb(string $webp, string $thumb, int $w = 400, int $q = 75): bool
    {
        if (!is_file($webp)) { return false; }
        self::probeTools();
        $ext = strtolower(pathinfo($webp, PATHINFO_EXTENSION));
        if (self::$cwebp && $ext === 'webp') {
            // cwebp 支持 -resize 宽 高（高为 0 表示等比）
            @exec(escapeshellarg(self::$cwebp) . " -q {$q} -resize {$w} 0 " . escapeshellarg($webp) . ' -o ' . escapeshellarg($thumb) . ' 2>/dev/null', $o, $rc);
            if ($rc === 0 && is_file($thumb) && filesize($thumb) > 0) { return true; }
        }
        // GD 回退
        if (function_exists('imagewebp')) {
            $im = @imagecreatefromwebp($webp);
            if ($im) {
                $ow = imagesx($im); $oh = imagesy($im);
                if ($ow > 0) {
                    $nh = (int)round($oh * $w / $ow);
                    $th = imagecreatetruecolor($w, max(1, $nh));
                    imagefill($th, 0, 0, imagecolorallocate($th, 255, 255, 255));
                    imagecopyresampled($th, $im, 0, 0, 0, 0, $w, max(1, $nh), $ow, $oh);
                    $ok = @imagewebp($th, $thumb, $q);
                    @imagedestroy($im); @imagedestroy($th);
                    if ($ok && is_file($thumb) && filesize($thumb) > 0) { return true; }
                }
            }
        }
        // 没有编码器：缩略图直接复用原图（至少保证有图）
        return @copy($webp, $thumb) && is_file($thumb) && filesize($thumb) > 0;
    }

    /**
     * 本地化单张外部图 → 返回本地 WebP 的 URL（失败返回 null）。
     * $relBase 形如 "goods/4112/cover" 或 "goods/4112/g1"（不含扩展名/后缀）。
     * 同时生成 _t.webp 缩略图。幂等：已存在且有效则跳过下载。
     */
    public static function localizeOne(string $url, string $relBase): ?string
    {
        if (!preg_match('#^https?://#i', $url)) { return $url ?: null; }
        $webpRel = $relBase . '.webp';
        $webpFs = self::storeDir() . '/' . $webpRel;
        $thumbFs = self::storeDir() . '/' . $relBase . '_t.webp';
        $urlOut = self::urlBase() . '/' . $webpRel;

        // 已存在且有效 → 直接返回（缩略图也补一下）
        if (is_file($webpFs) && filesize($webpFs) > 0) {
            if (!is_file($thumbFs) || filesize($thumbFs) <= 0) {
                self::ensureDir(dirname($relBase));
                self::makeThumb($webpFs, $thumbFs);
            }
            return $urlOut;
        }

        self::ensureDir(dirname($relBase));
        $tmp = tempnam(sys_get_temp_dir(), 'imgopt_');
        if (!self::download($url, $tmp)) { @unlink($tmp); return null; }
        // 校正扩展名后转换
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION)) ?: 'jpg';
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'], true)) { $ext = 'jpg'; }
        $src = $tmp . '.' . $ext;
        @rename($tmp, $src) or @copy($tmp, $src);
        $ok = self::toWebp($src, $webpFs);
        if ($ok) { self::makeThumb($webpFs, $thumbFs); }
        @unlink($src); @unlink($tmp);
        return $ok ? $urlOut : null;
    }

    /** 商城卡片缩略图推导：本地 .webp → _t.webp；其它（外链/非 webp）原样返回 */
    public static function thumb(string $url, int $w = 400): string
    {
        if ($url === '') { return $url; }
        if (str_starts_with($url, '/') && str_ends_with($url, '.webp')) {
            return substr($url, 0, -5) . '_t.webp';
        }
        return $url;
    }

    /** 收集仍指向外部的图片引用统计（用于后台展示） */
    public static function remainingExternal(): array
    {
        $r = ['goods_cover' => 0, 'goods_gallery' => 0, 'brand' => 0, 'cat' => 0, 'review' => 0];
        $r['goods_cover'] = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('mall_goods') . " WHERE cover LIKE 'http%' AND status=1 AND is_delete_time=0");
        // 图册里含 http 的商品数
        $r['goods_gallery'] = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('mall_goods') . " WHERE images LIKE '%http%' AND status=1 AND is_delete_time=0");
        $r['brand'] = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('mall_brand') . " WHERE logo LIKE 'http%'");
        $r['cat'] = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('mall_goods_category') . " WHERE icon LIKE 'http%'");
        $r['review'] = (int)Db::one("SELECT COUNT(*) FROM " . Db::t('mall_review') . " WHERE images LIKE '%http%'");
        return $r;
    }

    /**
     * 执行全量本地化（商品 cover/图册、品牌 logo、分类 icon、评价图）。
     * $stat 回填计数。返回是否整体成功。
     */
    /** 全量（单线程）：后台「开始优化」按钮用。等价于 runRange(全) + runExtras()。 */
    public static function run(array &$stat = []): bool
    {
        self::probeTools();
        $stat = [
            'goods' => 0, 'goods_imgs' => 0, 'brand' => 0, 'cat' => 0, 'review' => 0,
            'failed' => 0, 'bytes' => 0,
        ];
        self::runRange(0, 2147483647, $stat);
        self::runExtras($stat);
        $stat['bytes'] = self::dirSize(self::storeDir());
        return true;
    }

    /** 商品 cover + 图册（按 id 区间切片，供并行多 worker）。已本地化的商品自动跳过（cover 不再是 http）。 */
    public static function runRange(int $lo, int $hi, array &$stat): void
    {
        $stat += ['goods' => 0, 'goods_imgs' => 0, 'failed' => 0];
        $tGoods = Db::t('mall_goods');
        $rows = Db::rows(
            "SELECT id, cover, images FROM {$tGoods} WHERE id BETWEEN ? AND ? AND (cover LIKE 'http%' OR images LIKE 'http%') AND status=1 AND is_delete_time=0",
            [$lo, $hi]
        );
        foreach ($rows as $g) {
            $gid = (int)$g['id'];
            $upd = [];
            if (preg_match('#^https?://#i', (string)$g['cover'])) {
                $loc = self::localizeOne((string)$g['cover'], "goods/{$gid}/cover");
                if ($loc) { $upd['cover'] = $loc; $stat['goods']++; }
                else { $stat['failed']++; }
            }
            if (!empty($g['images']) && str_contains((string)$g['images'], 'http')) {
                $imgs = json_decode((string)$g['images'], true);
                if (is_array($imgs)) {
                    $new = [];
                    $i = 1;
                    foreach ($imgs as $u) {
                        if (preg_match('#^https?://#i', (string)$u)) {
                            $loc = self::localizeOne((string)$u, "goods/{$gid}/g{$i}");
                            $new[] = $loc ?: (string)$u;
                            if ($loc) { $stat['goods_imgs']++; } else { $stat['failed']++; }
                        } else {
                            $new[] = (string)$u;
                        }
                        $i++;
                    }
                    $upd['images'] = json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
            if ($upd) {
                $sets = [];
                $args = [];
                foreach ($upd as $k => $v) { $sets[] = "`{$k}`=?"; $args[] = $v; }
                $args[] = $gid;
                Db::run("UPDATE {$tGoods} SET " . implode(',', $sets) . " WHERE id=?", $args);
            }
        }
    }

    /** 品牌 logo / 分类 icon / 评价图（少量，单 worker 即可） */
    public static function runExtras(array &$stat): void
    {
        $stat += ['brand' => 0, 'cat' => 0, 'review' => 0, 'failed' => 0];
        $tBrand = Db::t('mall_brand');
        $tCat = Db::t('mall_goods_category');
        $tReview = Db::t('mall_review');

        // 2) 品牌 logo
        $brands = Db::rows("SELECT id, logo FROM {$tBrand} WHERE logo LIKE 'http%'");
        foreach ($brands as $b) {
            $loc = self::localizeOne((string)$b['logo'], 'brand/' . (int)$b['id']);
            if ($loc) { Db::run("UPDATE {$tBrand} SET logo=? WHERE id=?", [$loc, (int)$b['id']]); $stat['brand']++; }
            else { $stat['failed']++; }
        }

        // 3) 分类 icon
        $cats = Db::rows("SELECT id, icon FROM {$tCat} WHERE icon LIKE 'http%'");
        foreach ($cats as $c) {
            $loc = self::localizeOne((string)$c['icon'], 'cat/' . (int)$c['id']);
            if ($loc) { Db::run("UPDATE {$tCat} SET icon=? WHERE id=?", [$loc, (int)$c['id']]); $stat['cat']++; }
            else { $stat['failed']++; }
        }

        // 4) 评价图（JSON 数组）
        $revs = Db::rows("SELECT id, images FROM {$tReview} WHERE images LIKE '%http%'");
        foreach ($revs as $rv) {
            $imgs = json_decode((string)$rv['images'], true);
            if (!is_array($imgs)) { continue; }
            $new = []; $i = 1; $okAny = false;
            foreach ($imgs as $u) {
                if (preg_match('#^https?://#i', (string)$u)) {
                    $loc = self::localizeOne((string)$u, 'review/' . (int)$rv['id'] . '/' . $i);
                    $new[] = $loc ?: (string)$u;
                    if ($loc) { $okAny = true; } else { $stat['failed']++; }
                } else { $new[] = (string)$u; }
                $i++;
            }
            if ($okAny) {
                Db::run("UPDATE {$tReview} SET images=? WHERE id=?", [json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (int)$rv['id']]);
                $stat['review']++;
            }
        }
    }

/* ================= ★ 上传即转 WebP（本次新增） ================= */

    /**
     * 本地图片原地转 WebP：把 uploads 下的 jpg/png/gif/bmp 转成同名 .webp。
     * 返回新的相对 URL（成功）或原 URL（失败/本来就是 webp）。
     * 原文件保留（不删），避免历史页面或外链引用失效。
     */
    public static function webpInPlace(string $url): string
    {
        $u = trim($url);
        if ($u === '') { return $u; }
        // 已是 webp → 直接返回
        if (preg_match('#\.webp(\?|$)#i', $u)) { return $u; }
        // 站外链接交给 localizeOne 处理
        if (!preg_match('#^/#', $u)) {
            $loc = self::localizeOne($u, 'uploads/' . date('Y/m'));
            return $loc ?: $u;
        }
        $root = self::root();
        $rel = ltrim(parse_url($u, PHP_URL_PATH) ?: $u, '/');
        $src = $root . '/' . $rel;
        if (!is_file($src)) { return $u; }
        $dstRel = preg_replace('#\.[a-z0-9]+$#i', '.webp', $rel);
        if ($dstRel === $rel) { $dstRel = $rel . '.webp'; }
        $dst = $root . '/' . $dstRel;
        // 已转过且更新 → 直接复用
        if (is_file($dst) && filemtime($dst) >= filemtime($src)) {
            return '/' . $dstRel;
        }
        self::ensureDir(dirname($dstRel));
        if (self::toWebp($src, $dst)) { return '/' . $dstRel; }
        return $u;
    }

    /**
     * 把一段富文本（商品 detail）里的所有 <img src> 就地转 WebP 并替换。
     * 只替换本站图片，站外图片保持原样（详情里的站外图已由 mall 的清洗逻辑去掉）。
     */
    public static function webpInHtml(string $html): string
    {
        if ($html === '' || stripos($html, '<img') === false) { return $html; }
        return (string)preg_replace_callback(
            '#(<img[^>]+src=["\'])([^"\']+)(["\'])#i',
            function (array $m): string {
                $new = self::webpInPlace((string)$m[2]);
                return $m[1] . $new . $m[3];
            },
            $html
        );
    }

    /**
     * 商品保存后的自动化：cover / images / detail 内嵌图 全部转 WebP。
     * 由 mall 后台 ml_goods_save() 直接调用（不走 Hooks —— 宿主 Hooks::run 在生产是死代码）。
     * 返回 [cover, images[], detail, changed]
     */
    public static function onGoodsSaved(string $cover, array $images, string $detail): array
    {
        $changed = 0;
        $c2 = self::webpInPlace($cover);
        if ($c2 !== $cover) { $changed++; }
        $im2 = [];
        foreach ($images as $u) {
            $n = self::webpInPlace((string)$u);
            if ($n !== $u) { $changed++; }
            $im2[] = $n;
        }
        $d2 = self::webpInHtml($detail);
        if ($d2 !== $detail) { $changed++; }
        return ['cover' => $c2, 'images' => $im2, 'detail' => $d2, 'changed' => $changed];
    }

    /** 插件是否可用（mall 侧调用前的守卫） */
    public static function available(): bool
    {
        try {
            return class_exists('ImgOpt') && class_exists('Plugins') && \Plugins::isOn('imgopt');
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) { return 0; }
        $sz = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { if ($f->isFile()) { $sz += $f->getSize(); } }
        return $sz;
    }
}
