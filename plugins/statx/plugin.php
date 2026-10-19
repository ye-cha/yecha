<?php
declare(strict_types=1);
/**
 * StatX · 统计与站点健康（YeCha 插件）
 *
 * 一个插件，两个能力：
 *   ①【自建统计采集与看板】站点侧**一行 JS** → 本插件自带端点（register_file 在 Router 之前拦截）
 *                  → spool 批量落 ye_statx_hits / _daily / _bots → 后台看板。   —— 本版已实现
 *   ②【站点可访问性健康检测】探针 + 分级目标 + 去抖告警 + 后台健康区块。          —— 已实现
 *
 * 表族：
 *   能力②（**仍在 MySQL**）：ye_statx_health_targets / _checks / _daily / _events
 *   能力①（**插件自带的 SQLite**，默认 `<站点根>/var/statx/statx.db` —— 站内 `var/`，内核/nginx 已拒绝直取）：
 *     hits（明细，含 sid/uid/完整 ref/utm/屏幕与视口尺寸/点击坐标/事件名）· hits_rollup（按天汇总，永久保留）· sites
 *     ⚠️ 老的 MySQL 采集表 ye_statx_hits / _daily / _sites / _bots **停止写入**（数据保留作历史，
 *        它们仍列在下面 uninstall.tables 里，卸载时一并删除）
 *
 * ⚠️ 为什么能力② 的 4 张表**没有**写进下面的 `tables`：略（见 git 历史）；
 *    现行做法：能力② 的 4 张由 migrations 创建并在 `tables` 声明（缺表时内核自动补），
 *    能力① 的 SQLite 库由 `statx_collect_ensure_schema()`（admin.php / cli.php 入口都会调用）幂等创建。
 *    ★ 卸载提醒：SQLite 库文件在内核的卸载流程之外，**不会被自动删除** ——
 *      要彻底清除请手动 `rm -rf <站点根>/var/statx`（README 已写明；库位置可用
 *      `php plugins/statx/cli.php --task=env` 打印，也能在后台「系统环境」页看到）。
 *
 * ★ 2026-09-14「可移植化」要点（让下载的人真正装得上）：
 *   · 不写死任何路径/域名：库位置、spool、地域库、外部端点域名、同机站点根全部「可配 + 自动探测」；
 *   · 包里带的是**未初始化**的 rt.php（on=0）—— 解压不会让数据流向任何地方；
 *   · 默认只监控**本站自己**，不再把统计服务器自己的 20 个目标种进别人的库；
 *   · 缺 curl / pdo_sqlite / 地域库 / links 插件 / 备份看守 全部**优雅降级**，并说明原因。
 *
 * 注：id 不能叫 `stats` —— core/Plugins.php::defs() 里已有内置插件 `stats`（统计代码位），
 *     extList() 对与内置重名的 id 直接 skip（Plugins.php:136）。
 */
return [
    'id'      => 'statx',
    'name'    => '统计与站点健康',
    'ver'     => '1.3.2.r12',
    'desc'    => '自带存储的站点统计与健康检测（零外部依赖、零 JS 后台）：①一行 JS 采集 PV/UV/来源/热门页/设备/爬虫，数据落在**本站自己的 SQLite**（不存 IP）；②站点可访问性健康矩阵 + 去抖告警。开箱即用：自动识别站点、自动建表、缺什么降级什么。'
              . '★ 2026-09-15 起另有「站点管理」（增/改/删站 + 当场给可复制统计代码 + 一键检测各站）'
              . '与「账号」（改登录名 / 口令，复用内核 Auth，不自己造认证）。',
    'author'  => 'YeCha',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0-beta.2'],
    'toggle'  => true,

    // 本插件声明的全部自有表（不带 ye_ 前缀）；缺表时 Plugins::boot() 自动应用 migrations（幂等）
    // ⚠️ 只声明能力② 的 4 张（每张 = 每个请求一次 SHOW TABLES，见文件头说明）
    'tables'  => [
        'statx_health_targets',
        'statx_health_checks',
        'statx_health_daily',
        'statx_health_events',
    ],

    // 卸载时一次性删掉 8 张 MySQL 表（uninstall 只在点「卸载」时读取，不产生逐请求开销）
    // ★ 其中后 4 张是**批次 1 之前的采集表**（现行采集已改到插件自带 SQLite，这里删的是历史遗留）
    'uninstall' => [
        'tables' => [
            'statx_health_targets',
            'statx_health_checks',
            'statx_health_daily',
            'statx_health_events',
            'statx_hits',
            'statx_daily',
            'statx_sites',
            'statx_bots',
        ],
        'options' => [],
        'like'    => ['statx.%', 'plugin.statx.%'],
        // ⚠️ SQLite 库在 `<站点根>/var/statx/`（站内 var/，内核/nginx 已拒绝直取），内核卸载流程**不会**动它
        //    —— 需手动 rm -rf <站点根>/var/statx（位置可用 cli.php --task=env 打印）
    ],

    'admin'      => ['label' => '统计与站点健康', 'desc' => '统计看板（PV/UV/来源/热门页/设备/爬虫 + 一行 JS 注入状态）/ 站点健康矩阵 / 未恢复事件 / P95 / TLS 倒计时'],
    'admin_file' => 'admin.php',
    'admin_fn'   => 'yc_statx_admin',

    // ★ 能力①：前台端点挂载点。Plugins::boot() 会在 **Router 之前、全页缓存之前** require 它，
    //   命中 /st、/st.js、/s3.js（或 ?st=h|js）即自行处理并 exit。
    //   先例：plugins/recite/boot.php 拦 /tts、plugins/reader、plugins/guardweb。
    'register_file' => 'boot.php',
];
