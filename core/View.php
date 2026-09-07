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
            throw new RuntimeException('模板不存在: ' . $template);
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
