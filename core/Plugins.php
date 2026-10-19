<?php
declare(strict_types=1);
/**
 * Plugins：插件注册表（轻量"能力包"思路）+ 第三方目录插件
 *
 * 分层：
 *  1) 核心（内核）：不可卸载。
 *  2) 内置插件（builtin，随核心）：见 defs()，toggle 可停用，数据保留。
 *  3) 第三方插件：plugins/<id>/plugin.php 声明 manifest（return 数组），随核心发布或经「云市场」安装。
 *     manifest 字段：id/name/ver/desc/author/requires.core/toggle(默认 true)/tables(自有表)
 *      uninstall(['tables','options','like'])  admin(['label'])  blocks([['id','label']])  pages([['slug','title']])
 *      register_file(启用时 include，用于注册 Hooks)、admin_file、block_file、page_file。
 *     迁移：plugins/<id>/migrations/*.sql|php（Upgrade 组件 id 同名，启用时自动应用）。
 */
final class Plugins
{
    public static function defs(): array
    {
        return [
            'notes'  => ['name' => '记事本',     'ver' => '1.0.0', 'toggle' => true,  'admin' => 'notes',    'desc' => '后台私人随手记（P-3）· 表 ye_notes'],
            'marks'  => ['name' => '收藏·划线·注释', 'ver' => '1.0.0', 'toggle' => false, 'admin' => 'users', 'desc' => '读者收藏、私有划线批注、公开段评（E2）'],
            'guard'  => ['name' => '评论防骚扰',   'ver' => '1.0.0', 'toggle' => false, 'admin' => 'settings', 'desc' => '关键词 / 链接上限 / 昵称邮箱黑名单 / 频率拦截（P-4）'],
            'notify' => ['name' => '@提及与通知',  'ver' => '1.0.0', 'toggle' => false, 'admin' => 'comments', 'desc' => '评论 @提及、站内通知、可选 SMTP 邮件（E10）'],
            'stats'  => ['name' => '统计代码位',   'ver' => '1.0.0', 'toggle' => false, 'admin' => 'settings', 'desc' => '页头 / 页脚注入任意统计代码（百度 / 51la / 自建）'],
            'backup' => ['name' => '备份与还原',   'ver' => '1.0.0', 'toggle' => false, 'admin' => 'tools',    'desc' => '全库 SQL 备份 / 还原（E9）'],
        ];
    }

    public static function extDir(): string
    {
        return dirname(__DIR__) . '/plugins';
    }

    private static ?array $_ext = null;
    private static bool $_booted = false;

    public static function def(string $id): ?array
    {
        $d = self::defs()[$id] ?? null;
        if ($d !== null) { return $d; }
        $e = self::ext($id);
        return $e !== null ? array_merge([
            'name' => $e['name'] ?? $id, 'ver' => $e['ver'] ?? '0.0.0', 'toggle' => true,
            'desc' => $e['desc'] ?? '', 'external' => true,
        ], $e) : null;
    }

    /** 扫描 plugins 目录下各子目录里的 plugin.php 清单（include-return；每请求一次）
     *  已卸载(removed=1)的目录跳过：防止卸载/停用后其顶层代码仍被执行（安全 M4） */
    public static function extList(): array
    {
        if (self::$_ext !== null) { return self::$_ext; }
        self::$_ext = [];
        $dir = self::extDir();
        if (!is_dir($dir)) { return self::$_ext; }
        foreach ((array)scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || !is_dir($dir . '/' . $f)) { continue; }
            if (self::removed($f)) { continue; }
            $pf = $dir . '/' . $f . '/plugin.php';
            if (!is_file($pf)) { continue; }
            $m = @include $pf;
            if (!is_array($m)) { continue; }
            $id = (string)($m['id'] ?? $f);
            if (isset(self::defs()[$id])) { continue; } // 与内置重名忽略
            $m['id'] = $id;
            $m['dir'] = $f;
            $m['toggle'] = $m['toggle'] ?? true;
            self::$_ext[$id] = $m;
        }
        return self::$_ext;
    }

    public static function extAll(): array
    {
        return self::extList();
    }

    public static function ext(string $id): ?array
    {
        return self::extList()[$id] ?? null;
    }

    public static function isExternal(string $id): bool
    {
        return isset(self::extList()[$id]);
    }

    /** 全部（内置 + 第三方）注册表，供后台「插件」页 */
    public static function all(): array
    {
        $out = self::defs();
        foreach (self::extList() as $id => $m) {
            $out[$id] = array_merge([
                'name' => $m['name'] ?? $id, 'ver' => $m['ver'] ?? '0.0.0',
                'toggle' => $m['toggle'] ?? true, 'desc' => $m['desc'] ?? '', 'external' => true,
            ], $m);
        }
        return $out;
    }

    /** 引导：扫描并加载（启用中）第三方插件的 register_file 以注册 Hooks 等 */
    public static function boot(): void
    {
        if (self::$_booted) { return; }
        self::$_booted = true;
        foreach (self::extList() as $id => $m) {
            if (!self::isOn($id)) { continue; }
            if (!self::compat($m)) { continue; }
            // 自有表缺失时自动应用 plugins/<id>/migrations（幂等，启用即可用）
            foreach (($m['tables'] ?? []) as $tb) {
                if (!self::tableExists($tb)) {
                    Upgrade::apply($id, fn(string $s) => null);
                    break;
                }
            }
            $rf = $m['register_file'] ?? null;
            if ($rf !== null) {
                $path = self::extDir() . '/' . $m['dir'] . '/' . $rf;
                if (is_file($path)) { require_once $path; }
            }
        }
    }

    public static function compat(array $m): bool
    {
        $needCore = (string)($m['requires']['core'] ?? '');
        if ($needCore === '' || !defined('YC_VERSION')) { return true; }
        // 忽略预发布/构建后缀比较：'1.0.0-beta' 视同 1.0.0 满足 requires 1.0.0
        /* 预发布后缀可能是**空格**分隔（本构建 YC_VERSION = '1.0 Beta Build20260910'）：
           原正则 [-+] 去不掉空格后缀 → ersion_compare('1.0 Beta Build20260910','1.0.0','>=') = false，
           于是**所有第三方插件的 register_file 一律不被 include**（/subscribe 404、autosave 钩子不注册）。
           2026-09-11 修：空白 / 连字符 / 加号三种都切。见 themes/gushu/CORE-PATCH.md。 */
        /* 版本归一（2026-09-11 修，见 themes/gushu/CORE-PATCH.md）：
           ① 预发布/构建后缀可能是**空格**分隔（本构建 YC_VERSION = '1.0 Beta Build20260910'），
              原正则 `[-+]` 去不掉空格后缀 → version_compare('1.0 Beta Build20260910','1.0.0','>=') = false
              → **所有第三方插件的 register_file 一律不被 include**（/subscribe 404、autosave 钩子不注册）。
           ② 只按空白切还会剩 '1.0'，而 version_compare('1.0','1.0.0','>=') **仍是 false**（段数少即视作更小），
              故必须**补足到三段**：'1.0' → '1.0.0'。两条一起才真正修好。 */
        $norm = static function (string $v): string {
            if (preg_match('/^\s*(\d+(?:\.\d+)*)/', $v, $m)) { $v = $m[1]; }
            else { $v = (string)preg_replace('/[\s\-+].*$/', '', $v); }
            $p = explode('.', $v);
            while (count($p) < 3) { $p[] = '0'; }
            return implode('.', $p);
        };
        return version_compare($norm((string)YC_VERSION), $norm($needCore), '>=');
    }

    public static function isOn(string $id): bool
    {
        if (self::removed($id)) { return false; }
        $d = self::def($id);
        if ($d === null) { return false; }
        if (empty($d['toggle'])) { return true; }
        return (string)get_option('plugin.' . $id . '.enabled', '1') !== '0';
    }

    public static function uninstallInfo(string $id): array
    {
        $map = [
            // 注：多语言(poly)已迁移为云市场插件 plugins/poly（卸载/清数据语义由其 manifest 声明）
            'notes' => ['tables' => ['notes'], 'options' => ['plugin.notes.enabled']],
        ];
        $base = $map[$id] ?? [];
        $e = self::ext($id);
        if ($e !== null) {
            $ui = $e['uninstall'] ?? [];
            $base = [
                'tables' => array_merge($ui['tables'] ?? ($e['tables'] ?? [])),
                'options' => array_merge(['plugin.' . $id . '.enabled', 'plugin.' . $id . '.removed'], $ui['options'] ?? []),
                'like' => array_values(array_unique(array_merge(['plugin.' . $id . '.%'], $ui['like'] ?? []))),
            ];
        }
        return $base;
    }

    public static function removed(string $id): bool
    {
        return (string)get_option('plugin.' . $id . '.removed', '0') === '1';
    }

    public static function tableExists(string $table): bool
    {
        $t = Db::t($table);
        $row = Db::rows("SHOW TABLES LIKE '{$t}'");
        return (bool)$row;
    }

    /* ---------- 第三方插件能力：后台菜单 / 侧栏块 / 独立页 / 后台管理页 ---------- */

    /** 已启用且带后台菜单的第三方插件：id => ['label','desc'] */
    public static function activeMenus(): array
    {
        $out = [];
        foreach (self::extList() as $id => $m) {
            if (!self::isOn($id) || empty($m['admin'])) { continue; }
            $out[$id] = ['label' => (string)($m['admin']['label'] ?? $m['name']), 'desc' => (string)($m['desc'] ?? '')];
        }
        return $out;
    }

    /** 已启用第三方插件声明的侧栏块 id => label（供「页面布局」勾选与前台渲染） */
    public static function sideBlocks(): array
    {
        $out = [];
        foreach (self::extList() as $id => $m) {
            if (!self::isOn($id)) { continue; }
            foreach (($m['blocks'] ?? []) as $b) {
                $out['plug:' . $id . ':' . $b['id']] = (string)($b['label'] ?? $b['id']);
            }
        }
        return $out;
    }

    public static function renderSideBlock(string $blockId): string
    {
        if (!preg_match('#^plug:([a-z0-9_]+):([a-z0-9_]+)$#', $blockId, $mm)) { return ''; }
        [$_, $id, $bid] = $mm;
        $m = self::ext($id);
        if ($m === null || !self::isOn($id)) { return ''; }
        $fn = $m['block_fn'] ?? null;
        $file = $m['block_file'] ?? null;
        $dir = self::extDir() . '/' . $m['dir'];
        if ($file !== null && is_file($dir . '/' . $file)) {
            require_once $dir . '/' . $file;
        }
        if ($fn !== null && function_exists($fn)) {
            $html = (string)($fn)($bid);
            return $html;
        }
        return '';
    }

    /** 第三方插件声明的独立页（slug => title），供前台路由 */
    public static function pages(): array
    {
        $out = [];
        foreach (self::extList() as $id => $m) {
            if (!self::isOn($id)) { continue; }
            foreach (($m['pages'] ?? []) as $p) {
                $out[(string)($p['slug'] ?? '')] = ['id' => $id, 'title' => (string)($p['title'] ?? '')];
            }
        }
        return $out;
    }

    public static function renderPage(string $slug): ?array
    {
        $pgs = self::pages();
        if (!isset($pgs[$slug])) { return null; }
        $id = $pgs[$slug]['id'];
        $m = self::ext($id);
        if ($m === null) { return null; }
        $dir = self::extDir() . '/' . $m['dir'];
        $file = $m['page_file'] ?? null;
        if ($file !== null && is_file($dir . '/' . $file)) {
            require_once $dir . '/' . $file;
        }
        $fn = $m['page_fn'] ?? null;
        if ($fn === null || !function_exists($fn)) { return null; }
        $res = ($fn)($slug);
        if (!is_array($res)) { $res = ['html' => (string)$res, 'title' => $pgs[$slug]['title']]; }
        $res['title'] = (string)($res['title'] ?? $pgs[$slug]['title']);
        return $res;
    }

    /** 第三方插件后台管理页（GET 渲染 html / POST 自行处理） */
    public static function adminPage(string $id): string
    {
        $m = self::ext($id);
        if ($m === null || empty($m['admin'])) { return ''; }
        $dir = self::extDir() . '/' . $m['dir'];
        $file = $m['admin_file'] ?? 'admin.php';
        $path = $dir . '/' . $file;
        if (!is_file($path)) { return '<div class="card"><h3>' . e($m['name'] ?? $id) . '</h3><div class="stub">该插件未提供后台页面（' . $file . '）。</div></div>'; }
        require_once $path;
        $fn = $m['admin_fn'] ?? ('yc_' . $id . '_admin');
        if (!function_exists($fn)) { return '<div class="card"><h3>' . e($m['name'] ?? $id) . '</h3><div class="stub">缺少管理函数 ' . $fn . '。</div></div>'; }
        return (string)$fn();
    }

    /** 文档解析钩子：内容未命中时把 {slug} 交给插件（如超大型动态目录 svg_items），供 /{slug}.html 路由回退
     *  插件 manifest：doc_file（文件名，可选）/ doc_fn（函数名，可选，默认 yc_<id>_doc）。
     *  函数签名：fn(string $slug): ?array 返回 ['html'=>..,'title'=>..,'description'=>..(可选),'page'=>bool(可选)] 或 null。 */
    public static function resolveDoc(string $slug): ?array
    {
        foreach (self::extList() as $id => $m) {
            if (!self::isOn($id) || !self::compat($m)) { continue; }
            if (empty($m['doc_fn']) && empty($m['doc_file'])) { continue; }
            $dir = self::extDir() . '/' . $m['dir'];
            $file = $m['doc_file'] ?? null;
            if ($file !== null && is_file($dir . '/' . $file)) {
                require_once $dir . '/' . $file;
            }
            $fn = $m['doc_fn'] ?? ('yc_' . $id . '_doc');
            if (!function_exists($fn)) { continue; }
            $r = ($fn)($slug);
            if (is_array($r)) { return $r; }
        }
        return null;
    }
}
