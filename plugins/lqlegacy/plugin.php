<?php
declare(strict_types=1);
/**
 * lqlegacy · 老站口令透明升级（辅助插件）
 * ===========================================================================
 * 职责：只做一件事 —— 让**老站 PBKDF2 口径的口令**在首次登录时被验过，
 *       然后由 `core/Auth.php` 用 `setPassword()` 改存现代哈希（透明升级，用户无感）。
 * ★ 老站算法与 salt **只出现在本插件里**；`core/` 保持通用（只认"某个函数说这对口令有效"）。
 * ★ 本插件**不注册任何路由**、不认领任何 URL。
 * ★ 本插件**只读写两张东西**：自己的旁表 `ye_user_legacy`（方案 B）· 只读 `ye_users` 的 id/email（判断是否合成邮箱）。
 * ★ 不读明文口令以外的任何秘密；不读含口令的配置（老站 .env 一律不碰）。
 * 开关：`toggle=false` ⇒ Plugins::isOn() 恒真。
 */
return [
    'id'      => 'lqlegacy',
    'name'    => '老站口令透明升级',
    'ver'     => '1.0.0',
    'desc'    => '为「老站 PBKDF2 口令」提供旁存校验（旁表 ye_user_legacy）：首次登录验过即自动改存现代哈希（bcrypt/argon2），用户无感。'
               . '只新增一张旁表、不改任何既有表；不注册任何路由；不含任何密钥。',
    'author'  => 'liulanqi',
    'license' => 'MIT',
    'toggle'  => false,
    'tables'  => ['user_legacy'],
    'uninstall' => [
        'tables'  => ['user_legacy'],
        'options' => [],
        'like'    => [],
    ],
    'register_file' => 'boot.php',
];
