<?php
declare(strict_types=1);

/**
 * Lang：界面语言包（lang/ 与 themes、plugins 同级）。
 * 用法：__('site.name')；未命中的 key 原样返回，方便开发期排查。
 * 语言文件：lang/{lang}.php → ['site.name' => '…']，语言包可整文件翻译。
 * 多语版：bootstrap 先按 SITE_LANG 预载默认语言；index.php 识别到 /{lang}/ 前缀后
 * 调用 Lang::switch() 换成当前语言（zh_CN / en_US），界面文案即全随当前语言。
 */
final class Lang
{
    private static array $messages = [];
    private static string $current = 'zh_CN';

    public static function load(string $lang = 'zh_CN'): void
    {
        if (self::$current !== '') { self::switch($lang); }
    }

    /** 运行期切换到指定语言（可多次调用） */
    public static function switch(string $lang): void
    {
        self::$current = basename($lang);
        $file = dirname(__DIR__) . '/lang/' . self::$current . '.php';
        self::$messages = [];
        if (is_file($file)) {
            $arr = require $file;
            if (is_array($arr)) {
                self::$messages = $arr;
            }
        }
    }

    public static function code(): string
    {
        return self::$current;
    }

    /** 短语言码（zh/en/ja…，从语言包文件名推导；zh_CN→zh、zh_TW→tw 需区分） */
    public static function short(): string
    {
        if (self::$current === 'zh_TW') { return 'tw'; }
        if (self::$current === 'zh_CN') { return 'zh'; }
        return strtok(self::$current, '_') ?: 'zh';
    }

    /** 是否英文界面（多语版判断用） */
    public static function isEn(): bool
    {
        return self::$current === 'en_US';
    }

    public static function t(string $key): string
    {
        return self::$messages[$key] ?? $key;
    }
}

function __(string $key): string
{
    return Lang::t($key);
}
