<?php
declare(strict_types=1);

/** View：主题模板渲染（themes/{theme}/views/…），变量隔离、捕获输出 */
final class View
{
    public static function render(string $template, array $vars = []): string
    {
        $theme = (string)get_option('theme.default', 'yecha');
        $base = dirname(__DIR__) . '/themes/' . $theme . '/views/';
        $file = $base . $template . '.php';
        if (!is_file($file)) {
            /* 2026-09-27 加：当前主题缺该模板时，**回落到默认主题（yecha）的同名模板**，而不是直接抛异常。
             *
             * 起因（可复现路径）：`index.php` 在 `home.landing=1` 时**无条件**渲染 `landing`；若站点启用了
             * 一套**不含 views/landing.php** 的主题（例如演示主题 `zhiku`），首页会直接 Fatal 500 ——
             * **少一个模板文件就把首页打死**，这个机制本身太脆。
             *
             * 回落安全性（已逐条核对）：
             *  · 内核按名请求的模板共 13 个（404 / landing / layout / partials/{archives,list,list-media,
             *    meta-index,page,pages-list,post,search,sidebar,sidebar-lang,toc-side}），
             *    默认主题 `yecha` **全部齐备** ⇒ 「比旧行为更差」不存在：旧行为是抛异常（500），
             *    新行为是渲染出可看的页面并留一条日志。
             *  · **主题自己的 partial**（`partials/nav`、`partials/footer`、`partials/comments`，以及
             *    `kk-entry`/`ys-item`/`kj-card` 这类主题专属件）**仍优先取当前主题**，
             *    只有当前主题确实没有时才回落 ⇒ 不会把默认主题的部件串进别的主题。
             *  · 回落**留日志**（error_log 带当前主题名），不静默 ⇒ 便于发现"某套主题缺文件"。
             *
             * 不改基线主题 `themes/yecha` 一个字节；回落目标就是它。 */
            $def = 'yecha';
            $alt = dirname(__DIR__) . '/themes/' . $def . '/views/' . $template . '.php';
            if ($theme !== $def && is_file($alt)) {
                @error_log('[view] 主题 "' . $theme . '" 缺模板 ' . $template . '.php，已回落到默认主题 "' . $def . '"');
                $file = $alt;
            } else {
                throw new RuntimeException('模板不存在: ' . $template);
            }
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;
        return (string)ob_get_clean();
    }

    /** 主题静态资源路径（自动带文件 mtime 版本号，避免浏览器缓存旧样式/脚本） */
    public static function asset(string $p): string
    {
        $theme = (string)get_option('theme.default', 'yecha');
        $file = dirname(__DIR__) . '/themes/' . $theme . '/' . ltrim($p, '/');
        $v = is_file($file) ? '?v=' . filemtime($file) : '';
        return '/themes/' . $theme . '/' . ltrim($p, '/') . $v;
    }
}
