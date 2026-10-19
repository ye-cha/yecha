<?php
declare(strict_types=1);
/**
 * 文章自动保存 (Autosave) · YeCha 通用插件
 * - 编辑器侧:按间隔自动留底 / 切走页面时留底 / 点「保存」前留底(含富文本原文)
 * - 多版本:每篇按策略保留 N 个版本;超过保留天数的自动清理;后台可手工删除
 * - 恢复:后台或编辑器里一键把某个版本载回编辑器(只填内容,不直接写库,核对后再保存)
 * - 后台:版本列表 / 预览 / 载回 / 单条删除 / 按篇清空 / 清空全部 / 保留策略设置
 */
return [
    'id' => 'autosave',
    'name' => '文章自动保存',
    'ver' => '1.0.0',
    'desc' => '编辑器自动保存:写作过程中自动留底成多版本快照(点保存前与离页时也留底,含富文本原文),可在后台查看、一键载回、单条删除或清空,防止编辑器异常或误操作丢失正文。',
    'author' => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0-beta.2'],
    'toggle' => true,
    'tables' => ['autosaves'],
    'uninstall' => [
        'tables' => ['autosaves'],
        'options' => [],
        'like' => ['autosave.%', 'plugin.autosave.%'],
    ],
    'admin' => ['label' => '文章自动保存', 'desc' => '快照版本 / 载回 / 删除 / 保留策略'],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_autosave_admin',
    'register_file' => 'boot.php',
];
