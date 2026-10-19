# StatX · 统计与站点健康（YeCha 插件）

> **一个插件，两个能力**。**本版两个能力都已实现**：能力②站点可访问性健康检测；能力①自建统计采集与看板。

```
plugins/statx/
  ├── 能力①【采集与统计】  站点侧一行 JS → 本插件自带端点（register_file 在 Router 之前拦截）
  │                        → spool 批量落自建 MySQL 表 → 后台看板            —— 本版【已实现】
  └── 能力②【站点可访问性】探针 + 分级目标 + 去抖告警 + 后台健康区块            —— 本版【已实现】
```

---

## 0. 能力① 速览（一行 JS 就够）

站点侧只加**一行**（`<!-- statx:begin -->` 标记块由插件「一键写入」自动加，可一键移除）：

```html
<script async src="https://tj.51puer.com/st.js?v=<8位版本串>" data-site="<该站 site_key>"></script>
```

数据流：**浏览器一行 JS** → `tj.51puer.com/st.js`（采集脚本，长缓存）
→ `tj.51puer.com/st`（上报端点：`no-store` / **零 SQL** / CORS 白名单 / 立即返回）
→ `/tmp/statx-spool/*.log` → `cli.php --task=collect-rollup`（cron 每分钟，**多行批量 INSERT**）
→ `ye_statx_hits` → 汇总进 `ye_statx_daily` / `ye_statx_bots` → 后台「统计看板」。

三个硬指标（均有实测）：
- **端点零 SQL**：`boot.php` / `endpoint.php` / `botlib.php` 里**没有任何 `Db::` 调用**；
  把 `Db` 换成「一调用就抛异常」的桩，端点仍能正常回执并落 spool。
- **不拖慢页面**：非端点请求只做 1 次 `rt.php` include（opcache）+ 2~3 次字符串比较，实测 **0.32 微秒/请求**。
- **不写明文 IP**：库里只有 `visitor_hash`（sha256 掺 **逐日轮换盐** 后取前 16 位），不存 IP、不存完整 Referer。

---

## 1. 为什么 id 叫 `statx`

- 站长的目标架构是「**统计能力做成 YeCha 自己的插件**」，可用性检测是它的**一个功能**（不是独立插件），所以插件名要同时覆盖两件事 → `statx`（统计 + 监测）。
- **不能叫 `stats`**：`core/Plugins.php::defs()` 里已有内置插件 `stats`（"统计代码位"），
  而 `Plugins::extList()` 对与内置重名的第三方 id **直接 skip**（`Plugins.php:64`）。用 `stats` 会导致插件根本不被识别。

## 2. 文件清单

| 文件 | 作用 |
|---|---|
| `plugin.php` | manifest：id/name/ver、`tables`（能力② 4 张表一次声明）、`uninstall`（8 张表）、后台菜单、**`register_file => boot.php`** |
| `lib.php` | **探针引擎**：真实 UA、cache-buster、逐跳重定向链（含环检测）、并发 ≤2（`curl_multi`）、公网 TLS 证书、错误分类 |
| `inc.php` | **共享层**：建表（8 张）、目标播种/增删、落库、状态机（去抖）、合并告警、保留/汇总、后台取数 |
| `admin.php` | 后台页（唯一入口）：`yc_statx_admin()` —— **统计看板（能力①）** + 健康矩阵/事件/P95/TLS/立即检测/目标管理/邮件配置 |
| `migrations/20260914_statx_health.php` | 能力②：建表 + 播种首批目标（幂等） |
| `migrations/20260915_statx_collect.php` | 能力①：建 4 张采集表 + 播种站点登记 + 生成 `rt.php`（幂等） |
| `cli.php` | **统一 cron 入口**：`--task=health\|rollup\|prune\|notify\|list\|migrate\|mailimport\|mailtest\|selftest\|rltest` ＋ **`collect-init\|collect\|collect-rollup\|collect-status\|collect-inject\|collect-remove\|collect-test\|collect-backup\|collect-endpoint`** |
| **`boot.php`** | **能力① 前台端点挂载点**：由 `register_file` 在 Router / 全页缓存**之前** require；命中端点即自行处理并 `exit`；其余请求 1 次 include + 2~3 次比较后立刻返回 |
| **`endpoint.php`** | **能力① 端点实现**：`/st.js` 采集脚本（长缓存 + 版本串 + 可跨域）· `/st` 上报（`no-store` / CORS 白名单 / 预检 204 / 1×1 GIF）· **全程零 SQL** |
| **`botlib.php`** | **能力① 爬虫识别**：三级特征库（`se` / `ai` / `scraper`）—— **优先直接调用 `plugins/guardweb` 的同名判定函数**，未装时退化为同源内嵌副本；另含设备粗分与来源归类（只留域名） |
| **`collect.php`** | **能力① DB/运维层**：4 张采集表、`rt.php` 生成、spool 收件（批量 INSERT）、rollup、prune、「一行 JS」注入/移除/实证校验、跨站注入、看板取数、异地备份邮件 |
| **`rt.php`** | **生成物**（不是手写配置）：端点运行参数（开关 / 路径 / Origin 白名单 / tj token 映射 / 脚本版本）。**端点读它 → 零 SQL**。改配置走后台或 CLI，会被重新生成 |
| **`st.js`** | **采集脚本本体**（约 1.1 KB）：无 Cookie / 无指纹 / 不采集表单与鼠标 / 预渲染与 `navigator.webdriver` 不计数；同时作为 `/plugins/statx/st.js` 静态副本（nginx 直出，零 PHP） |
| `README.md` | 本文件 |

## 3. 表族（能力②：全部 `ye_statx_health_*`；能力① 见 §9.1）

位置：**YeCha 自己的 MySQL**（本站 `gushu_stage`），**不放 tj 的 SQLite**。
原则：**监控不能住在被监控对象里** —— tj 是 14 站统计的单点，如果监控数据放进 tj，tj 一挂连"tj 挂了"的记录都没了。

| 表 | 内容 | 保留 |
|---|---|---|
| `ye_statx_health_targets` | 目标：站/site_key/URL/间隔/期望码/必需正则/是否跟随跳转/是否带 buster/启用/tier + 状态 streak | 常驻 |
| `ye_statx_health_checks` | 明细：每跳状态码链/DNS/连接/TLS/TTFB/总耗时/字节数/内容标记/错误类别/是否 429/尝试次数 | **30 天** |
| `ye_statx_health_daily` | 按天汇总：可用率/P95/平均 TTFB/TLS 最小剩余天数 | **24 个月** |
| `ye_statx_health_events` | 事件：开始/恢复/持续/失败原因/**通知状态与失败原因** | **永久** |

`uninstall.tables` 一次声明全部 4 张表 → 卸载时一起清理。

### 跨站复用方案（若将来要在多个站装）
- 现状：**只建在 `gushu_stage`**（= gushuwu.com 的站点根）。
- 若要在别的 YeCha 站装：直接把 `plugins/statx/` 目录复制过去，各站自建一套表（各站自己的 MySQL），
  **不要跨库写**（跨站写会引入跨库凭据与锁竞争）。
- 若希望**一个面板看全部站**：正确做法是"各站探针互不依赖"，由**一台**机器（本例是 YeCha 主站）用 HTTP 探测所有站的公网 URL —— 这正是本插件的做法，所以**多站复用其实不需要装多份**。

## 4. 探针红线（逐条来自 recon 实测，代码里都有对应实现）

| # | 红线 | 实现位置 |
|---|---|---|
| ① | **绝不请求 `/p.gif`**（会写 tj 的库、污染统计、虚增流量） | 代码里没有任何 `/p.gif`；目标表里也禁止添加写操作路径 |
| ② | **`/healthz` 是"假绿陷阱"**（guardweb 显式跳过 + 浅检查，不碰 DB/不渲染模板） | 只允许作为 `kind='healthz'` 的辅助目标，**不作为主判据**（后台有明确提示） |
| ③ | **必须带真实浏览器 UA**；撞到 guard 标记要报「**探针配置故障**」而不是「站点故障」 | `statx_ua()` + `statx_forbid_rules()` 首条 `Access denied by guard → probe_config`，`is_probe=1` **不计入失败**、不触发 down 事件 |
| ④ | URL 必须带 **cache-buster** | 目标级 `buster` 字段；`statx_bust()`；重试时**换新 buster** |
| ⑤ | **429 必须单独识别**为「被限频」 | `err_class='rate_limited'`、`is_429=1`、**不计失败**、间隔翻倍（`rl_until`） |
| ⑥ | 每站每轮 **≤3 请求**、间隔 **≥60s**、并发 **≤2** | `STATX_MAX_ATTEMPTS=3`（1 首探 + 2 重试）、`interval_s` 下限 60、`STATX_MAX_CONCURRENCY=2`（`statx_chain_many` 里 clamp） |
| ⑦ | **TLS 测公网边缘证书**，不是源站证书 | `statx_tls_days()` 用 `capture_peer_cert` 直连公网 hostname:443（源站是 CF Origin CA 2041 到期，无意义） |
| ⑧ | 有副作用 / 需凭据的路径一律不探 | 无 `/admin`、无任何写操作、无凭据 |
| ⑨ | 中间产物写 `/tmp`，webroot 零残留 | 无临时文件；无 `.bak`/`.new` |

## 5. 检测项

DNS 解析耗时 · TCP/TLS 建连耗时 · **每一跳状态码** · **完整重定向链（含重定向环检测）** ·
**内容标记**（必需正则 + 内置禁止正则：`Access denied by guard` / `Error 52x` / `Just a moment` /
`SQLSTATE` / `Fatal error` / 站点未安装）· TTFB + 总耗时 · 页面字节数（含**突降检测**，
对比近 7 天中位数）· **公网 TLS 到期天数** · **429 单独识别** · 慢（连续 N 轮才告警）。

**超时/重试**：connect 5s / total 15–20s；失败重试 2 次（20s / 60s 退避 + 换 cache-buster）；
429 与"探针被拦"**不重试**（重试无用且会加重限频）。

## 6. 告警

- 通道：**邮件**，复用 `core/Mail.php::Mail::send()`（成功 `true` / 失败返回错误字符串）。
- 密码：`yc_opt_set('statx.mail.pass', …)` 加密存储 → 发送时 `yc_opt()` 取出交给 `Mail::send()`。
  **与 `plugins/subscribe/inc.php::sub_smtp_send()` 完全同一套做法**：不自造解密、不落明文、不进日志。
- 配置优先级：`statx.mail.*`（插件自己的） → 本站 `mail.*` → 空（**优雅降级**）。
  ⚠️ **不做运行时跨站读**：各站 `SECRET` 不同 → `yc_decrypt()` 派生密钥不同 → 跨站读到的密文解不开。
  跨站搬运用 `cli.php --task=mailimport`（JSON 走 stdin 管道，**不落盘**）。
- 去抖：**连续 3 轮失败**才建 `down` 事件并告警；**连续 2 轮成功**才发 `recovered`；
  同一 `target+kind` 事件未关闭前不重复发；**一轮内多目标失败合并成一封邮件**；
  慢告警需连续 6 轮；TLS ≤21 天建 `tls_expiring` 事件。
- **失败可见**：`notify_state='failed'` + `notify_err` 在后台**红标**（"告警发不出去"本身就是故障），
  并提供「立即补发」；失败重试最多 3 次、间隔 ≥10 分钟。
- 维护窗口：`statx.maint_until` 之后到点前只记录不告警（`notify_state='muted'`）。

## 7. 数据生命周期

| 步骤 | 行为 |
|---|---|
| 每轮探测后 | `statx_rollup(3)`：把**已完整过去的日子**（`DATE(ts) < CURDATE()`）汇总进 daily，`INSERT … ON DUPLICATE KEY UPDATE`（幂等可重算） |
| 保留任务 | `cli.php --task=prune`：先 rollup 兜底 → `DELETE … LIMIT 5000` **分批**删除超 30 天明细（避免长事务锁表）→ daily 只留 24 个月；events 永久保留 |

## 8. cron 建议（绝对路径 + 退出码落日志 + 自带轮转）

```cron
# 能力② 站点健康探测（每分钟）
* * * * * L=/root/cron-statx-health.log; flock -n /tmp/statx-health.lock nice -n 19 ionice -c3 /usr/bin/php /www/wwwroot/xuecha-yecha-stage/plugins/statx/cli.php --task=health >> $L 2>&1; r=$?; echo "$(date -Is) rc=$r" >> $L; tail -n 200 $L > $L.t && mv $L.t $L # statx-health
# 能力① 收 spool + 汇总（每分钟；能力①「批量写」就靠这一条）
* * * * * L=/root/cron-statx-collect.log; flock -n /tmp/statx-collect.lock nice -n 19 ionice -c3 /usr/bin/php /www/wwwroot/xuecha-yecha-stage/plugins/statx/cli.php --task=collect-rollup >> $L 2>&1; r=$?; echo "$(date -Is) rc=$r" >> $L; tail -n 200 $L > $L.t && mv $L.t $L # statx-collect
# 能力② 告警补发（每 5 分钟）
*/5 * * * * nice -n 19 ionice -c3 /usr/bin/php /www/wwwroot/xuecha-yecha-stage/plugins/statx/cli.php --task=notify >> /root/cron-statx-notify.log 2>&1 # statx-notify
# 保留清理 + 汇总（每天 04:10）：能力② 明细 30 天 + 能力① 明细 30 天，都是分批删
10 4 * * * L=/root/cron-statx-prune.log; nice -n 19 ionice -c3 /usr/bin/php /www/wwwroot/xuecha-yecha-stage/plugins/statx/cli.php --task=prune >> $L 2>&1; r=$?; echo "$(date -Is) rc=$r" >> $L; tail -n 200 $L > $L.t && mv $L.t $L # statx-prune
# 能力① 按天汇总异地备份邮件（每天 04:40；只发汇总、不含明细与 IP）
40 4 * * * nice -n 19 ionice -c3 /usr/bin/php /www/wwwroot/xuecha-yecha-stage/plugins/statx/cli.php --task=collect-backup --to=ynwuse@qq.com >> /root/cron-statx-backup.log 2>&1 # statx-backup
```

`flock -n` 保证一轮没跑完不会被下一分钟叠上来。
⚠️ **是否真装由站长决定**；不装的话，后台「立刻收件 + 汇总」按钮可以手工跑，但明细不会自动落库。

## 9. 能力①【自建统计采集】—— 已实现

### 9.1 表族（`ye_statx_hits` / `_daily` / `_sites` / `_bots`）

位置：**本站自己的 MySQL**（`gushu_stage`）。与能力② 的 `ye_statx_health_*` **同族**，一次在 `uninstall.tables` 里声明 8 张。

| 表 | 关键列 | 保留 |
|---|---|---|
| `ye_statx_hits` | `ts,d,site_key,host,path,is_landing,ref_domain,ref_kind,device,is_bot,bot_cls,bot_name,visitor_hash` | **30 天** |
| `ye_statx_daily` | `site_key,d,pv,uv,landing,ref_ext,bot_hits,ref_top,page_top,dev_top,bot_top`（后四个是 JSON） | **24 个月** |
| `ye_statx_sites` | `site_key,label,host,enabled,local_root,tj_token,inject_where,inject_tag,inject_at,verify_at,verify_ok,verify_note,script_url,endpoint_url` | 常驻 |
| `ye_statx_bots` | `site_key,d,bot_cls,bot_name,hits`（用于「被谁刷」） | **24 个月** |

> ⚠️ 这 4 张表**刻意没有**写进 `plugin.php` 的 `tables`：`core/Plugins.php::boot()` 会对 `tables` 里
> **每一张表、每一个前台请求**执行一次 `Plugins::tableExists()`，而它内部是**未缓存**的 `SHOW TABLES LIKE`。
> 声明 8 张 = 每个请求多 4 次 `SHOW TABLES`。这 4 张表由 `statx_collect_ensure_schema()`
> （admin / cli 入口都会调用）幂等创建，卸载时按 `uninstall.tables` 一起删 —— 两不耽误，且**零逐请求开销**。

### 9.2 端点怎么挂（关键结论，已实测）

YeCha **没有"任意路由注册"接口**，但 `manifest.register_file` 指定的文件会在 `Plugins::boot()`
阶段被 `require_once` —— 那时**早于 Router、早于全页缓存**（`index.php:14`，缓存查询在 260 行之后），
因此插件可以在自己的文件里判 `$_SERVER['REQUEST_URI']`，命中就自行处理并 `exit`。
**现成先例**：`plugins/recite/boot.php` 拦 `/tts`、`plugins/reader`、`plugins/guardweb`。

本插件提供：
| 路径 | 作用 | 缓存 |
|---|---|---|
| `/st.js` | 采集脚本（读同目录 `st.js`，单一来源） | `?v=<hash>` → `max-age=31536000, immutable`；无 `v` → `3600, must-revalidate`；带 `ETag` |
| `/s3.js` | **tj 老脚本路径的兼容入口**（返回同一份脚本） | 同上 |
| `/st` | 上报端点（GET / POST / OPTIONS） | `no-store, no-cache, must-revalidate, max-age=0` + `Expires: 0` |
| `/plugins/statx/st.js` | **静态副本**（nginx 直出，零 PHP） | 交 nginx（多数站 12h expires） |
| `/index.php?st=h` · `?st=js` | **查询串形态**（凡是能把 `/index.php` 交给 PHP 的站都能用） | 同上 |

⚠️ **部署现实**：`/st.js`、`/st` 这种自定义路径要能用，前提是**该站的 nginx 会把未知路径交给 `index.php`**
（即存在 `location / { try_files $uri $uri/ /index.php?$query_string; }`）。实测：
`gushuwu.com` ✅✅、`cntales.com`/`yecha.org` 的 `/st` ✅ 而 `/st.js` ✗（被 BT 默认的
`location ~ .*\.(js|css)?$ { expires 12h; }` 抢走）、`laolv.org`/`senlincha.com`/`herontool.com`/`xuecha.org` ✗。
→ 因此「一键写入」会**自检候选地址**；同时 `/plugins/statx/st.js`（静态）与 `/index.php?st=h`（查询串）
在**任何** YeCha 站上都可用，是最稳的兜底。

### 9.3 采集什么（够用即止）与**明确不采集**

采集：PV / UV（每日去重）· 来源域名 + 站内/站外/直达 · 落地页与热门页（`path`，**已剥查询串**）·
按天/小时的时间 · 设备类型（桌面/移动/平板/爬虫，UA 粗分）· 爬虫分类与名称 · 站点与 Host。

**明确不做**：热图 / 漏斗 / 会话回放 ✗ · 跨站或跨天身份打通 ✗ · 表单内容、鼠标轨迹、屏幕指纹 ✗ ·
国家/地区（需离线 geo 库，本期不引入重依赖）✗ · 重型依赖（Redis / ClickHouse / 任何 composer 包）✗ ·
**不碰 tj、不请求 `/p.gif`** ✗（代码里没有任何 `/p.gif`）。

### 9.4 隐私（红线：不明文存 IP）

| 项 | 做法 |
|---|---|
| 访客 IP | **绝不落库、绝不落盘**。只进哈希：`visitor_hash = substr(sha256(SECRET \| 日期 \| site_key \| IP \| UA), 0, 16)` |
| 盐 | **每天轮换**（掺入 `date('Y-m-d')`）→ 跨天**不可关联**，天然实现「不打通身份」 |
| Referer | 只留**域名**（小写、去 `www.`），完整 URL 与查询串一律丢弃 |
| 页面 | 只留 `path`，**剥掉查询串**（可能含搜索词/token） |
| Cookie | 端点**不设置任何 Cookie**（`header_remove('Set-Cookie')` + `session_abort()`，也不销毁访客已有会话） |
| 保留期 | 明细 **30 天** → 按天汇总 **24 个月** → 爬虫表 24 个月；`--task=prune` 分批删除 |
| 异地备份 | 只发**按天汇总 CSV**（计数），**不含明细、不含 IP、不含访客标识原文** |

### 9.5 爬虫排除（复用 guardweb 三级特征库）

`statx_bot_classify()` 的顺序：空 UA → `se`（搜索引擎）→ `ai`（大模型爬虫）→ `scraper`（脚本客户端）
→ 无任何浏览器内核标记 → `other`；否则 `human`。
**判定函数优先直接调用 `plugins/guardweb`**（`yc_guardweb_is_se` / `_is_ai` / `_is_scraper`），
guardweb 未装时才用同源内嵌副本 —— 名单以 guardweb 为准。实测 13/13 与 guardweb 三级并集一致。
「已知爬虫只计数不混入 UV」：爬虫行进 `is_bot=1`，`pv/uv` 一律只算 `is_bot=0`。
另外两层防线：**nginx 边缘**（UA 黑名单 403）与 **guardweb**（PHP 层 403）。

### 9.6 性能纪律

| 机制 | 做法 |
|---|---|
| 上报不拖慢页面 | 脚本 `async` + ~1.1 KB；脚本走静态/长缓存；上报用 `navigator.sendBeacon`（失败才退化 `<img>`） |
| 端点零 DB 短路 | 运行参数固化在 `rt.php`；命中先判路径/开关，**不做任何 SQL**；`probe=1` 连 spool 都不写 |
| 批量写 | 端点只 `fopen('ab') + flock + fwrite` 追加一行到 `/tmp/statx-spool/<site>-<Ymd>.log`；<br>`--task=collect-rollup` 每分钟把 spool `rename` 抢占后**多行 VALUES 批量 INSERT**（每批 ≤500 行） |
| 绕开全页缓存 | 端点在 `Plugins::boot()` 里 `exit`，**早于** `Cache::get('pages')`；响应自带 `no-store` |
| 抗上报洪水 | 单 spool 文件上限 `collect.spool_max`（默认 32 MB）；site_key 必须在本表且 `enabled=1`，否则丢弃 |
| 时间边界 | **绝不用 MySQL 的 `NOW()`/`CURDATE()` 做边界**：会话级 `SET time_zone`（`statx_sync_tz()`）+ 所有边界在 PHP 侧算好传参（见《A11Y 报告》§4.12 的时区缺陷） |

### 9.7 站点侧注入（「一行 JS」）

- 写法：`<!-- statx:begin -->` + `<script async src="…/st.js?v=…" data-site="<key>"></script>` + `<!-- statx:end -->`
  （注入单元**自带前导换行**，所以「移除」= **逐字节**回到注入前的 option 值）。
- 写进 `stats.foot`；若该主题**不输出 foot**，自动改投 `stats.head` —— 判据是**真的抓一次首页看 HTML 里有没有**
  （历史坑：gushu 主题原本完全不输出 `stats.foot`，站长填的统计代码因此一直没生效）。
- **只追加自己的标记块**：`stats.foot` 里原有的旁站标签（如 `tj.51puer.com/s3.js` + `p.gif`）**一个字节都不动**，
  也不减不加。移除时只认自己的标记块（兜底正则只匹配 `<本插件 origin>/st.js`，**刻意不匹配 `/s3.js`**）。
- 一键写入后会**清该站全页缓存**（缓存里那份 HTML 必须也带标记），并落 `verify_ok/verify_note`。
- 跨站注入：同机站点用**该站自己的 `config.inc.php` 凭据**（正则解析，**绝不 `require` 别站 config** —— 常量重定义会静默沿用本站值）。

### 9.8 迁移与并存（tj → statx）

- **口径分界日**：`statx.collect.cutover_note`（后台可见）。并存期两套口径不同，**不要把新旧数字拼成一条曲线**。
- 站点侧可**两行并存**（tj 一行 + statx 一行），互不干扰；**回滚 = 删一行**。
- **最平滑的迁移**：新端点同时提供 `/s3.js`，并把各站 `stats.foot` 里的 tj token 自动发现进
  `ye_statx_sites.tj_token` → `rt.php` 的 `tjmap` 把 token 映射成 `site_key`。
  ⇒ **各站连 HTML 都不用改，只切 DNS 就完成迁移**。
- 订阅域名 `tj.51puer.com` 的 vhost / 证书 / 端点已在 `.106` **准备到可切换状态**（DNS 归站长）。

## 10. 回滚

| 动作 | 效果 |
|---|---|
| **停用采集** | 后台开关 或 `--task=collect-init --disable` → 重写 `rt.php`（`on=0`），端点整体不存在；**已注入的那行 JS 变成 404（无害）**，建议同时「一键移除」 |
| **一键移除注入** | 后台「移除」或 `--task=collect-remove --site=<key>` → 只摘自己的标记块（**逐字节**回到注入前），清该站页面缓存；旁站标签不动 |
| 后台「插件」页停用 | 前台零影响（`register_file` 不再被 include）；后台菜单消失；**数据保留** |
| 删除 `plugins/statx/` 目录 | 代码消失；表与选项保留（与内核/其它插件一致） |
| 后台卸载 | 按 `uninstall` 声明删 **8 张表** + `statx.*` / `plugin.statx.*` 选项 |
| 只回滚采集（保留健康） | `DROP TABLE ye_statx_hits, ye_statx_daily, ye_statx_sites, ye_statx_bots;` + 删 `plugins/statx/rt.php` + 移除注入 |
| 回滚 tj.51puer.com vhost | `rm /www/server/panel/vhost/nginx/tj.51puer.com.conf && nginx -t && nginx -s reload` |
| 恢复 | 目录放回 + 启用；8 张表由 migrations/ensure_schema 幂等重建 |

### 10.1 是否需要改主题/内核

**不需要，也没有改。** 能力① 完全靠 `register_file` + `stats.foot/stats.head` 选项 + 自建表实现。
唯一的站点外改动是**新增一个独立 vhost**（`tj.51puer.com`）与它的证书目录 —— 不涉及任何既有站点的配置、主题或内核文件。

---

## ★ 迁移硬要求：统计绝不能给页面留下可见痕迹（2026-09-13 补）

### 背景（真实事故，不是假设）
主题（`teadoc` / `yecha` / `octern` 各一份、逐字相同）有一段全局 error 捕获：

```js
document.addEventListener('error', …, true)
```

任何 `<img>` 加载失败 ⇒ 就地替换成 `<span class="img-miss">alt || '图片暂缺'</span>`。

而每页的非 logo 图片**只有那个 1×1 统计像素**：

```html
<img src="https://tj.51puer.com/p.gif?site=…" width="1" height="1" alt="">
```

★ 它的 `alt` 是**空的** ⇒ 一旦加载失败，就会渲染出**可见的「图片暂缺」文字**。
2026-09-13 11:44 切换 `tj.51puer.com` 入口的那个窗口里 `/p.gif` 变成 404，
结果**6 个站的每一页都出现了「图片暂缺」**（站长就是这样看到的）。

### 硬要求

1. **绝不用 `<img>` 像素做采集** —— statx 一律用 `navigator.sendBeacon()`。
   **判定标准：统计失败时页面上不能出现任何可见痕迹。** 这是验收标准，不是"尽量"。
2. **迁移时必须删掉老 tj 的 `<img …/p.gif>` 那一行**（它 `alt` 为空 ⇒ 迟早复发），
   **同时删掉老 tj 的 `<script …/s3.js>` 标签**：`/s3.js` 切成 statx 兼容脚本之后，
   那个老标签会变成**第二个 statx 采集器**，与本站 statx 标签**重复上报** ——
   实测同一次浏览落库 **2 行、`visitor_hash` 相同** ⇒ **PV 翻倍、UV 不变**。
   迁移后一个站只应保留**恰好一个**采集器：
   `<!-- statx:begin -->…<!-- statx:end -->`。
3. **端点不可达时必须静默失败**：不回显错误、不写可见 DOM、不影响页面渲染
   （`st.js` 整体包在 `try/catch` 内 —— 这是验收标准）。
4. `/st` 返回的 43B GIF 只是**给非 JS 客户端的上报回执**；
   **不得**把 `/st` 当作 `<img src>` 使用（否则同一个坑必然重演）。
   已实测：所有站点及其 option 中均无 `<img …/st>`。

### 迁移收尾清单（执行 `cli.php --task=collect-inject --site=<key>` 之后逐条核对）

- [ ] 该站 HTML 中 `<img …/p.gif>` 计数 = **0**
- [ ] 该站 HTML 中 `<script …/s3.js` 计数 = **0**
- [ ] `<!-- statx:begin -->` 计数 = **1**，且 `src` 指向集中端点域名
- [ ] 用真实浏览器打开首页 ⇒ `ye_statx_hits` 新增**恰好 1 行**（≥2 行 = 有重复采集器）
- [ ] 该站健康探测目标的 `require_re` 由 `data-site="<tj token>"` 改为 `statx:begin`
      （否则迁移完成后会**永久误报 down** 并反复发告警邮件）

> 参考实现：`POST /s3.js`（过渡期兼容入口）与 `/p.gif` / `/s.js`（过渡期反代回 tj 的
> 两条 location）都写在 41 的 `tj.51puer.com.conf` 里，并注明**删除条件**：
> 6 个站全部 `collect-inject` 到 `tj.51puer.com` 之后，那两条 location 一并删除。
---

# 批次 1（基础层）：采集字段补齐 · 存储切到插件自带 SQLite · 隐私模型（选项 C）

> 本批目标：给「热图 / 流向 / 漏斗 / 深度」把**必需的字段先采齐**，并把采集侧的存储从
> YeCha MySQL 的 `ye_statx_*` 表**改成插件自带的 SQLite**（与内容库彻底解耦）。
> 本批**不做**这些功能的界面；本批交付的是**数据地基 + 落位安全 + 隐私口径**。

## 1. 存储层：插件自带的 SQLite

### 1.1 落位与权限（红线：绝不能被 HTTP 取到）

| 项 | 值 |
|---|---|
| 库文件 | **`/www/gushu-statx/statx.db`**（**站点根 `/www/wwwroot/...` 之外**，先例 `/www/gushu-recite-tts`） |
| 目录 | `/www/gushu-statx/`，`0750`，属主 `www:www` |
| 文件 | `0640`，属主 `www:www`（Web 走 www；cron 走 root，root 天然可写；以 root 运行时本层会把库与 `-wal`/`-shm` **自动 chown 回 www**） |
| WAL 的两个附属文件 | `statx.db-wal` / `statx.db-shm` —— **备份/迁移时必须一起带上**（只拷主库会丢最近事务，本项目已实测过） |
| 迁移到别的机器/站点 | 写 `plugins/statx/sdb_path.php`（`<?php return '/新/路径/statx.db';`）即可，**不用改代码** |
| 临时/测试用库 | CLI 下可用环境变量 `STATX_SQLITE=/tmp/x.db` 覆盖（**只在 `PHP_SAPI==='cli'` 生效**，Web 侧无法被请求改写落库位置） |

**nginx 层同步加固（本批实测发现老名单盖不住 `-wal`/`-shm`）**

| URL（诱饵文件实测） | 加固前 | 加固后 |
|---|---|---|
| `/plugins/statx/x.db` · `x.sqlite` · `x.sqlite3` | 403 | 403 |
| **`/plugins/statx/x.db-wal`** · **`x.db-shm`** · **`x.sqlite-wal`** | **200 + 文件内容原样返回** ✗ | **403** ✓ |

改法（`gushuwu.com.conf`，已 `cp -a` 备份 + `nginx -t` 通过 + **graceful reload**，master PID 未变）：

```nginx
location ~* \.(md|log|sql|sqlite|sqlite3|db|ini|sh|py|pyc|swp|orig)(-wal|-shm|-journal)?$ { deny all; }
```

> 注：库文件本身在站点根之外，HTTP 无论如何都取不到（`/statx.db`、`/gushu-statx/statx.db`、
> `/plugins/statx/statx.db` 实测**全部 403**）；上面的加固是**纵深防御**，防的是"将来有人把库放到
> webroot 里"这类误操作。

### 1.2 PRAGMA（实际采用值 + 理由）

| PRAGMA | 采用值 | 理由 |
|---|---|---|
| `journal_mode` | **WAL** | 多读单写：cron 批量写的同时后台可读，不必互等（tj 同款） |
| `synchronous` | **NORMAL** | WAL 下只有断电可能丢最近若干事务（进程崩溃不丢）；统计数据的价值远低于每次 fsync 的写放大 |
| `busy_timeout` | **5000** | SQLite 只有一个写者；写冲突要**等待**而不是立刻 `SQLITE_BUSY`（**必须留**） |
| `temp_store` | **MEMORY** | GROUP BY / ORDER BY 的临时表进内存；本库 ≤ 数十 MB，代价可控 |
| `cache_size` | **-8000**（8 MiB/连接） | **偏离 tj 的 -16000**：本机是 1 vCPU/小内存，稳态库 ≤30 MB，8 MiB 页缓存足够；不为统计多占内存 |
| `wal_autocheckpoint` | **1000**（≈4 MiB） | WAL 稳态 ≤8 MB（预算值；tj 实测 3.99 MB 同量级） |
| `mmap_size` | **0** | **偏离 tj 的 256 MiB**：WAL 下内存映射收益有限，且连接可能来自常驻 php-fpm worker，按 256 MiB/连接预留地址空间不合算 |
| `foreign_keys` | **OFF** | 本库没有外键（显式写出，避免依赖 SQLite 默认值） |
| `auto_vacuum` | **INCREMENTAL** | **建表前**设置（空库时用一次 `VACUUM` 落地）⇒ 保留清理后可以 `PRAGMA incremental_vacuum` 增量回收，**不必跑整库重写 + 独占锁的 VACUUM** |
| `page_size` | 4096（默认） | 未改 |
| `user_version` | **1** | 迁移版本号（不额外建表；后续加表/加列时 +1） |

### 1.3 表与索引（**索引刻意克制**）

本批只建**本批要写的 3 张表**（其余表留到后续批次）：

- **`hits`** —— 明细。**点击与事件复用本表**，用 `kind` 区分 `pv|cl|ev`
  （本批体量小：一张表 = 一条写入路径 + 一套索引；将来点击量涨起来再按 tj 拆出 `clicks`/`events`）
- **`hits_rollup`** —— 按天汇总（**永久保留**）
- **`sites`** —— 站点登记（列与老 MySQL 版一一对应，另加 `domains` 备用）

**索引只有 2 个**（都对应本批就会执行的查询）+ 两个主键自带索引：

```sql
CREATE INDEX idx_hits_site_d   ON hits(site_key, d);    -- rollup / KPI / 趋势 / 热门页 / 备份
CREATE INDEX idx_hits_site_uid ON hits(site_key, uid);  -- uid 跨天可关联：新老访客 / 留存 / 轨迹
```

刻意**不建**（tj 有 7 个索引，占该表 38.3%）：`url` / `ref` / `title` / `sid` / `ip` 上的索引 ——
前四个对应的查询本批还没有，`sid` 相关的流向/深度在**下一批**再加（`CREATE INDEX` 在 SQLite 上是廉价的在线操作）。

**单行字节（`dbstat` 实测，9,266 行明细）**

| 表 / 索引 | 占用 | 单行 |
|---|---|---|
| `hits` 表体 | 2,723,840 B | **294.0 B/行** |
| `idx_hits_site_d` | 385,024 B | 41.5 B/行 |
| `idx_hits_site_uid` | 417,792 B | 45.1 B/行 |
| **`hits` 含索引合计** | 3,526,656 B | **≈381.5 B/行**（索引占 **22.7%**） |
| `sites`（11 行） | 4,096 B | 372 B/行（页粒度，无参考意义） |

> 对照 tj：`hits` 含索引 **810.9 B/行**、索引占 **38.3%**（7 个索引 + 明文 IP/UA 列）。
> **本插件行内含的字段比 tj 多**（sid/uid/utm×5/完整 ref/屏幕与视口/坐标/事件名），单行反而只有它的一半：
> 少掉的是 5 个用不上的索引与 ip/ua 两列。
> ⚠️ 口径说明：上表来自**合成负载**（路径短且整齐，B-tree 压缩偏好），真实站点路径/来源更长，
> 实务估算请按 **≈420–440 B/行**（含索引）预留。

### 1.4 老 MySQL 采集表怎么办

`ye_statx_hits / _daily / _sites / _bots` **停止写入**（数据保留作历史，卸载时按 `uninstall.tables` 一并删）。
`--task=collect-init` 会把**站点登记 + 保留窗口内的明细**一次性搬进 SQLite（幂等：只在 SQLite 侧为空时执行；
加 `--no-import` 可跳过）。能力② 的健康检测仍留在 MySQL `ye_statx_health_*`（本批未动）。

## 2. 采集字段（`st.js` v2）

站点侧**仍然只是一行 JS、仍然 1 次上报/页**（**不新增请求**；点击才多一次）：

```html
<script async src="https://tj.51puer.com/st.js?v=4e088c0b" data-site="gushuwu.com" data-ep="https://tj.51puer.com/st"></script>
```

| 线上参数 | 落库列 | 说明 |
|---|---|---|
| `s` / `h` / `p` | `site_key` / `host` / `path` | 站点 key / 主机 / **路径（查询串在服务端剥掉）** |
| `k` | `kind` | `pv`（页面）/ `cl`（点击）/ `ev`（事件） |
| `si` | `sid` | **会话 ID**：写 `localStorage`，**30 分钟无活动即换新** |
| `ui` | `uid` | **长期随机访客 ID**：写 `localStorage`，**不由 IP 派生、不含任何个人信息** |
| `r` | `ref` / `ref_domain` / `ref_kind` | **完整 referrer URL**（≤500）+ 域名 + 分类（`none` 直达 / `in` 站内 / `out` 站外） |
| `utm_*` | `utm_source/medium/campaign/term/content` | 渠道参数（从落地 URL 的 query 解析） |
| `sw` / `sh` | `width` / `height` | **screen 尺寸**（与 tj 同口径，用于分辨率分布） |
| `vw` / `vh` | `vw` / `vh` | **viewport 尺寸**（本批新增：热图坐标必须按视口归一化，`clientX` 是视口坐标） |
| `lg` | `lang` | 浏览器语言 |
| `en` | `en` | **事件名**：先找 `data-event`（**逐级向上查找**），找不到退回标签名 |
| `x` / `y` | `x` / `y` | **点击坐标**（仅 `kind=cl`） |

- **点击节流 2000 ms**（与 tj 一致）：实测"连点 5 次只落 1 条 + 4 秒后再点落第 2 条"
- **脚本卫生**（保留 statx 原有优点）：`navigator.webdriver` 与 `document.visibilityState==='prerender'` 不计数；
  用 **`sendBeacon`**（不是图片信标）＋ `Image` 兜底；**不写任何 Cookie**；版本串 `?v=` 随脚本内容变化
- **体积：1912 B（gzip 1087 B）** —— 预算 ≤2 KB ✓（旧版 1089 B）
- 用了 `localStorage`（两个键：`_sxu` 访客 ID / `_sxs` 会话 ID / `_sxt` 最后活动时间）——
  **localStorage 不是 Cookie**：不进请求头、站点侧读不到、清空浏览器数据即消失

## 3. 隐私模型（选项 C：客户端随机访客 ID + 不存 IP）

**采集了什么**

- 页面路径（**不含查询串**）、站点 host、时间、来源 URL（完整 referrer）、utm 渠道、语言、
  屏幕与视口尺寸、设备/浏览器/爬虫三级分类、点击坐标与事件名
- 客户端生成的**随机** `uid`（长期）与 `sid`（会话）

**不采集什么（与 tj 的关键差异）**

| 项 | tj | 本插件 |
|---|---|---|
| 原始 IP | **明文存 `hits.ip`** ✗ | **完全不存**（只作为**逐日轮换盐**的 sha256 输入，得到 16 位不可逆 `visitor_hash`，用于**当日去重/限流**） |
| User-Agent | 明文存 | **不存原文**，只存三级分类结果（`device` / `is_bot` / `bot_cls` / `bot_name`） |
| 跨天可关联 | 靠明文 IP ✗ | 靠**客户端随机 `uid`**（原样存下来；它是随机值、不含个人信息） ⇒ **留存 D+1/D+3/D+7 · 新老访客 · 访客轨迹**都能做 |
| 查询串 | 存（含搜索词） | **不存**（只存 path；utm 单独、白名单 5 个键） |

**保留期限**

| 数据 | 保留 | 说明 |
|---|---|---|
| 人类明细（`hits` 且 `is_bot=0`） | **30 天** | `collect.keep_days` |
| 爬虫明细（`hits` 且 `is_bot=1`） | **10 天** | `collect.keep_bot_days`（tj 实测爬虫占写入 34%、价值最低） |
| 按天汇总（`hits_rollup`） | **永久** | 只有计数与 TOP 榜，不含任何访客标识 |
| 中间产物（spool） | 收件后**立即删除** | 只在 `/tmp/statx-spool`，入库即 `unlink` |

**访客自己的控制**：清空浏览器数据（或只清 `localStorage`）⇒ `uid`/`sid` 立刻重置、此前的历史无法再关联到本人；
站点侧可随时用 `--task=collect-remove --site=<key>` 摘掉整行 JS 停止采集。

**与 tj 的关系**：tj 的明文 IP 与本插件的"不存 IP"是两种取向。**运维注意事项**：不存 IP ⇒ **不能**做 IP 黑名单/封禁，
限流只能靠 `visitor_hash`（逐日轮换）与 nginx 层限速。

## 4. 保留与清理（cron）

```cron
# 收件 + 按天汇总：每分钟（已在跑）
* * * * * … cli.php --task=collect-rollup
# 保留清理：每天 04:20（本批新增）
20 4 * * * L=/root/cron-statx-collect-prune.log; /usr/bin/flock -n /tmp/statx-collect-prune.lock \
  /usr/bin/nice -n 19 /usr/bin/ionice -c3 /www/server/php/83/bin/php \
  /www/wwwroot/xuecha-yecha-stage/plugins/statx/cli.php --task=collect-prune >> $L 2>&1; \
  r=$?; echo "$(date -Is) rc=$r" >> $L; tail -n 200 $L > $L.t && mv $L.t $L # statx-collect-prune
```

`--task=collect-prune` 做三件事：① `rollup` 兜底（保证被删的日子已汇总）
② **分批删**（`DELETE … WHERE rowid IN (SELECT rowid … LIMIT 5000)`；本机 SQLite 其实**支持** `DELETE … LIMIT`，
但本层统一用等价且可移植的写法）③ `PRAGMA incremental_vacuum` + `PRAGMA optimize`。
**绝不在代码里跑整库 `VACUUM`**（需 2× 磁盘 + 独占锁）；确需收缩文件时手动跑一次即可。

## 5. 链接体检（第 ④ 项：本批只做**只读接口**）

- **不新写探针**：yecha.org 的 `plugins/links` + `tools/links-probe.php`（cron 每 6h）**已经在跑**，本批只读它的结果
- 新增 `links.php`：`statx_links_health($siteKey)` —— **零出站请求、零写入**，输出四态
  `ok / err / down / blocked（被 WAF/限频拦，**不算死链**）` + 一维活跃度 **`stale`（可达但长期停更）**
- CLI 自检：`--task=links-health [--site=yecha.org]`（实测 8 条友链：`ok=7 · stale=1`，
  最长停更 **472 天** —— 正是"可达 ≠ 活着"）
- 已备好但**本批未接线**：`statx_links_extract_md()`（**按 Markdown 抽外链**：`**網址**：<https://…>` 与 `](https://…)`；
  实测 `href="http…"` 在这些条目上**命中 0**）与 `statx_links_dedupe_host()`（域名去重）。
  后台区块 / 扩目标类别 / 探测频率（每天 1 次、并发 ≤2、429 冻结 7 天）留到下一批。

## 6. 回滚（每条都是一条命令）

```bash
# ① 代码回滚（本批前的整目录备份）
cp -a /root/statx-b1-backup-<时间戳>/plugins-statx/. /www/wwwroot/xuecha-yecha-stage/plugins/statx/
# ② nginx deny 回滚（如需）
cp -a /root/statx-b1-nginx2-<时间戳>/gushuwu.com.conf.before /www/server/panel/vhost/nginx/gushuwu.com.conf && nginx -t && nginx -s reload
# ③ 注入标签回滚（摘掉整行 JS）
php plugins/statx/cli.php --task=collect-remove --site=gushuwu.com
# ④ 存储回滚（SQLite 库在站点根之外；删掉即可，MySQL 老表数据仍在）
rm -rf /www/gushu-statx
# ⑤ 采集 cron 回滚
crontab -l | grep -v statx-collect-prune | crontab -
```

## 7. 与 tj 的差异（本批相关部分）

| 维度 | tj | 本插件（批次 1 之后） |
|---|---|---|
| 存储 | 独立 SQLite（`/opt/tj/tj.db`），**一个进程一个库** | **插件自带 SQLite**，与内容库解耦；cron 批量写、端点零 SQL |
| 索引 | `hits` 7 个（占 38.3%） | **2 个**（占 22.7%） |
| 会话/访客 | `sid`（客户端）+ `uid`（客户端）+ **明文 IP** | `sid` + 客户端随机 `uid`；**不存 IP**（`visitor_hash` 仅当日去重） |
| 点击/事件 | 独立 `clicks`/`events` 表 | 复用 `hits`（`kind=cl/ev`）—— 体量涨了再拆 |
| 保留 | 无条件 `DELETE`（无分批）+ `VACUUM` 在请求里跑（风险点） | **分批删** + `incremental_vacuum`；VACUUM 只手动 |
| 端点写库 | **每请求 1 次 INSERT + commit** | 端点**零 SQL**（spool）→ cron 事务批量入账 |
| 采集脚本 | 2057 B，`new Image()`，无 webdriver/prerender 排除 | 1912 B（gzip 1087 B），`sendBeacon`，两者都排除 |
| 出站链接巡检 | **完全没有** | 本批接入只读接口（复用 links 体检），下一批做后台区块 |
| 后台 | Python 拼 HTML | YeCha 插件后台页（沿用内核会话/权限） |

---

## 12. 批次 2（界面层）：极简看板 · 四个附属视图 · 热图/流向/转化路径 · 地域（2026-09-13）

> 站长的硬要求是「**界面越简单越好**」，且这是**首要设计约束**。本节写清它是怎么落地的、以及每个口径的定义。

### 12.1 页面清单（**5 个**，默认只有主看板一屏）

| # | 页面 | 地址 | 一屏内容 |
|---|---|---|---|
| 1 | **主看板**（默认） | `/admin/?s=plug_statx` | 核心数字 → 趋势（内联 SVG 柱）→ 热门页 Top10 → 来路 Top10 → 关键词 Top10 → 地域 Top10 → 链接健康 |
| 2 | **页面** | `?v=pages` | 入口页 Top10 · 退出页 Top10 · 访问深度分布（含跳出率） |
| 3 | **来源** | `?v=src` | 来源类型四分类+直达 · 外链来源 Top10 · utm 渠道 Top10 |
| 4 | **访客** | `?v=vis` | 设备 · 浏览器 · 操作系统 · 新老访客 · 分辨率 · 语言 · IP 版本与地域覆盖 · 地域 Top15 |
| 5 | **深入** | `?v=deep` | 点击热图（归一化 24×14）· 点击元素 Top10 · 页面流向 Top12 · 转化会话 · 转化路径 Top10 |

- **时间范围只有三档**：`?r=today|7d|30d`（默认今日）。**没有筛选表单、没有站点下拉、没有设置页、没有二级菜单。**
- 四个视图之间用**一行文字链接**切换；「手动刷新」是全页唯一的 JS（`location.reload()`）。
- 原有的「站点健康检测」与「采集与站点管理」两块界面**功能一字未减**，折叠成两个 `<details>`（点一下展开）。
- **每张表右上角一个 `CSV`**（共 26 张表 → 26 个链接）。CSV 为统计口径，**不含 IP**（本插件不存 IP）。

### 12.2 极简自检（数出来的）

| 约束 | 计数 | 约束 | 计数 |
|---|---|---|---|
| 页面数 | **5** | JS 库（`<script src>`） | **0** |
| 筛选表单 `<form>` | **0** | Canvas | **0** |
| `<select>` / `<input>` / `<button>` | **0 / 0 / 0** | iframe | **0** |
| 设置页 | **0** | 内联 JS | **1**（`location.reload`） |
| 二级菜单 | **0** | 每表 CSV | **26/26** |
| 时间范围档位 | **3** | 图表实现 | 内联 SVG + CSS 条 + `<table>` |

### 12.3 三个深入功能的定义（站长不用配）

- **热图**：`x/vw`、`y/vh` 归一化到 **24×14=336 格**，**只统计落在视口内的点击**
  （`vw>0 AND vh>0 AND 0<=x<vw AND 0<=y<vh`，避免把滚动后超出可视区的点击画到页面顶部）。
  **归一化用每个访客自己的 viewport，不是屏幕分辨率** —— tj 用的是 `screen`，窗口一窄就横向失真。
- **流向**：`lag(id) OVER (PARTITION BY sid ORDER BY id)` + 自连接（排除同页），一条 SQL 出 Top12。
- **转化**：**一次会话（sid）内浏览 ≥ 3 个页面**。页面上一句话写明；「转化路径」取每个转化会话的**最后三页**。
  要换口径（例如某目标 URL）只需改那一句 SQL，不必动数据结构。

### 12.4 地域是怎么在"不存 IP"的前提下做出来的

地域只能在**唯一还看得到 IP 的那一刻**算 —— 采集端点：算完只把**地域字符串**写进 spool，**IP 永不落盘**。

| 项 | 值 |
|---|---|
| IPv4 库 | `/opt/tj/data/ip2region.xdb`（11,122,036 B · md5 `c30f0c57e4ba14cab6aa5a15e976ac1c`，**从 41 只读拷来**） |
| IPv6 库 | `/opt/tj/data/ip2region_v6.xdb`（37,277,813 B · md5 `9ce5c8433b069211200e312bc77e7b04`，与 41 上那份一致） |
| 联网下载 | **没有**（两份都是本机已有 / 从 41 只读复制） |
| 读取器 | `geo.php` 纯 PHP 手写 xdb 读取器；file-only（**不把 37 MB 读进内存**）；单次 0.016 ms(v4)/0.032 ms(v6) |
| 可用环境变量覆盖 | `STATX_GEO_DB=/path/to/x.xdb`（**只在 CLI**，与 `STATX_SQLITE` 同一套路） |
| 失败行为 | 库缺失/结构不认识/任何异常 ⇒ 返回空串（地域未知）；**不抛错、不重试、不写日志**，采集不受影响 |

> 覆盖范围：IPv4（4 字节地址）与 IPv6（16 字节地址）分别走两份库；IPv6 实测能拿到真实地域
> （`240e:34c:…→中国|北京市|北京市|电信`、`2606:4700:4700::1111→United Kingdom|England|London|Cloudflare`）。
> **历史行无法回填**（那时没有 IP）—— 建库时的回填只会补 `ref_type`/`kw`。

### 12.5 数据库 schema（v2）

`hits` 新增 6 列（**都不带索引**，批次 1 的 2 个索引保持不变）：

| 列 | 含义 | 在哪算 |
|---|---|---|
| `region` | `国家|省|市|ISP|CC`（≤64 B） | **端点**（IP 现算，IP 不落盘） |
| `browser` / `os` | 浏览器/操作系统族（Chrome·Safari·Edge·Firefox·WeChat·UC…/Windows·Android·iOS·macOS·Linux·HarmonyOS…） | **端点**（UA） |
| `ipver` | 4 / 6 / 0（未知/老行） | **端点** |
| `ref_type` | direct / internal / social / search / external | **入库侧**（完整 ref） |
| `kw` | 关键词（外部引擎 + **站内搜索词**） | **入库侧**（完整 ref） |

> ⚠️ `endpoint.php` 传进来的 UA 是**已小写**的（`statx_bot_ua()` 的行为）⇒ `statx_derive_ua()` 内部
> **必须全部小写匹配**。这条已写进 `derive.php` 的注释（第一次上线时正是这个原因导致 `browser=Other`）。

### 12.6 spool 格式（v3，28 列）

在 v2（24 列）之后追加 4 列：`24 region` · `25 browser` · `26 os` · `27 ipver`。
入库侧同时识别 **v3 / v2 / v1**（列数不足即丢弃，**绝不退化误解析**），所以升级期不会丢数据。

### 12.7 关键词口径（务必看，别误读）

- **站内搜索词（100% 可靠，本插件把它做成核心）**：来自**站内结果页 → 点进内容**这条链路
  （`ref_kind='in'` 且 referrer 带 `q=`/`wd=`/`s=` 等白名单参数）。看板上会标出覆盖率。
- **外部引擎关键词（只有一部分）**：Google 早已不把 `q=` 放进 referrer；Bing/百度/搜狗/360 仍会带。
  看板同时标出覆盖率 —— **"关键词少"不是系统不行**。
- 直接落地在 `/search?q=…` 的那一次访问（没有 referrer）拿不到词：`path` 剥掉查询串是批次 1 的隐私取舍，
  本批**未改 `st.js`**。
- 关键词表下会打印「站内搜索词 X%（a/b 个会话）· 外部引擎 Y%（c/b 个会话）」。

### 12.8 回滚（本批）

```bash
cp -a /root/statx-b2-backup-20260913-150851/plugins-statx/. /www/wwwroot/xuecha-yecha-stage/plugins/statx/
cp -a /root/statx-b2-backup-20260913-150851/sqlite/statx.db /www/gushu-statx/statx.db   # 回到 v1 / 32 列
rm -rf /opt/tj/data                                                                     # 只是让地域一列变空
```

> ⚠️ **不要整体覆盖 `cli.php` 回滚**：另一条并行线在同一文件里加了 8 行（`selfcheck.php` 备份看守自检）。
> 只还原本批的 8 个文件：`derive.php geo.php dash.php collect.php sdb.php admin.php inc.php endpoint.php`。

---

# 批次 3（界面层重做）：按老版 tj 做左栏导航树 + 三件套 + 零 JS 纯 SSR

> 站长要求：「**界面按老版来**」。照抄依据是老版**每页的 HTML 骨架**（`.106:/root/tj-ui-dump/`，56 文件），
> 不是报告的文字描述。**本批全程没有访问过线上 tj**。

## 13.1 怎么用（页面与 URL）

唯一入口：`/admin/?s=plug_statx`（进去就是仪表盘）。页面靠查询串切换：

| 参数 | 含义 | 取值 |
|---|---|---|
| `p` | 页面 | 见 13.2 的页面清单（37 个） |
| `r` | 时间档 | `today`(今日) / `yesterday`(昨日) / `7d` / `30d` / `90d` / `day` |
| `d` | 具体某一天 | `YYYY-MM-DD`（与 `r=day` 搭配；表单会回填） |
| `site` | 站点 | 站点 `site_key`（省略时自动选"区间内记录最多的站"） |
| `csv` | 导出 | 表 id（如 `page` / `trend` / `users` / `heat` …） |
| `uid` | 访客 | 访客轨迹页用（由「用户列表」的「轨迹 →」进入） |
| `url` | 页面 | 点击图用（候选页分页器点选） |
| `h` | 来源站 | 外部链接下钻用 |

★ **所有筛选都在查询串里** ⇒ 复制 URL 就能分享同一个视图（老版的好习惯，本批照做）。
★ **日期会继承**：单日模式下点左栏任何菜单、切站点，都会带着那一天过去。
★ **超范围不静默**：未来日期、早于"有数据的第一天"、早于明细保留边界的日期，页面上都会给一句人话说明。

## 13.2 页面清单（导航结构）

```
├─ 系统      仪表盘 · 蜘蛛与 AI(全局) · 采集预警(全局) · 统计检测 · 设置
├─ 分析      网站概况 · 流量分析 · 实时访客 · 趋势分析 · 单日详情
├─ 来源分析  全部来源 · 搜索引擎 · 关键词 · 外部链接 · 站内来源
├─ 访问分析  受访页面 · 入口页面 · 退出页面 · 受访域名 · 路径分析 · 访问深度 ·
│           用户列表 · 页面点击图 · 页面上下游 · 事件分析 · 分析结论
├─ 转化分析  转化概况 · 转化路径
├─ 访客分析  地域分布 · 系统环境 · 新老访客
└─ 监测      蜘蛛与 AI(单站) · 采集预警(单站) · 统计检测 · 站点健康 · 数据保留

下钻页（不进导航）：访客轨迹 ?p=visitor&uid=…
诊断页（不进导航）：目标管理与事件流水 ?p=admin
```

## 13.3 零 JS 约束（本插件的非功能要求，**请勿破坏**）

实测（9 个页面合计）：

| 指标 | 值 |
|---|---|
| `<script src>` | **0** |
| `<canvas>` | **0** |
| 引入的 JS 图表库 | **0** |
| 内联 JS | **每页恰好 1 处** = `onchange="this.form.submit()"`（日期选择器自动提交，与老版逐字相同） |
| `<script>` 标签总数 | **每页 0**（「统计代码」区块里的示例是**转义后的文本**，不是标签） |
| 自动刷新 | `<meta http-equiv="refresh" content="15">`，**只在实时页** |

**图表全部是 PHP 拼字符串手绘的内联 SVG**（照老版 Python 的做法）：折线 `stx_svg_line()` 760×210 / 5 档网格 / PV 绿 `#1d9e75` + UV 橙 `#e8a33d`；柱状 `stx_svg_bars()` 760×170（24 小时分布 24 根柱）；热力网格 `stx_heat_grid()` 24×14 共 5 档绿。
**新增 UI 时请继续遵守**：不加外链、不加 JS 库、不用 Canvas、内联事件不超过 1 处/页。

## 13.4 外壳零件的组织（改界面时看这里）

| 文件 | 放什么 |
|---|---|
| `ui.php` | **视觉零件**：`stx_nav()` 导航树定义 · `stx_titles()` 页面标题 · `stx_ico()` 内联 SVG 图标 · `stx_kpis()` 数字墙 · `stx_tag()` 三色 tag · `stx_lk()` 查看胶囊 · `stx_bar()` 占比条 · `stx_timeline()` 时间线 · `stx_days()` / `stx_daypick()` / `stx_range_notice()` 三件套 · `stx_site_switcher()` · `stx_shell()` 外壳 · `stx_table()` 通用表格 · `stx_svg_line/bars()` / `stx_heat_grid()` |
| `stxui.php` | 样式表 `stx_css()`（**选择器全部 `stx-` 前缀**）· `statx_ui_url()` · `statx_ui_csv_handle()` · `statx_ui_sites_ordered()` |
| `pages.php` | **时间维度** `statx_ctx()` / `statx_bounds()` · `statx_pages_registry()`（CSV id → 取数函数）· `statx_page_builder()`（页面 id → builder）· `statx_footer()` |
| `pages2/3/4/5.php` · `adv.php` | 各页面 builder（每个返回 `['kind'=>'page','body'=>html]`） |
| `dash.php` | **只有取数**（每个 `statx_dash_*()` 返回一张表的 cols/rows/bar/title/note） |
| `agg.php` | 批次 3 新增的聚合查询 |

**加一个新页面只要三步**：① 在 `ui.php::stx_titles()` 加标题；② 在 `ui.php::stx_nav()` 挑一个分组加菜单项；③ 在 `pages.php::statx_page_builder()` 加 `'页面id' => 'builder函数名'`，然后在任意 `pages*.php` 里写一个返回 `['kind'=>'page','body'=>$h]` 的函数。**表格要带 CSV 就在 `stx_table()` 的数组里给 `csv` 键**，并把取数函数登记进 `statx_pages_registry()`。

## 13.5 时间维度口径（一份权威定义）

- `statx_ctx()` 解析 `?p=&r=&d=&site=`，**只有这一处**决定"今天/昨天/区间/某一天"的边界（一处改处处改）。
- 与老版的对应：`r=today` ← `days=1` · `r=yesterday` ← `days=2` · `r=7d` ← `days=7` · `r=30d` ← `days=30` · `r=90d` ← `days=90` · `r=day&d=` ← `/day?d=`。
- `statx_bounds()` 给出：有数据的最早一天、明细保留边界、日期选择器可选范围（`ui_min` / `ui_max`）。日期选择器的 `min` 取"明细窗口 ∩ 有数据的第一天"，所以**选不到注定空的范围**。
- 超范围提示由 `stx_range_notice()` 统一渲染（未来日期 / 早于有数据第一天 / 早于明细窗口 三种）。

## 13.6 数据保留页的安全设计（★ 请勿改成 GET）

老版 tj 的 `/admin/retention` 是"**GET 即真删数据**"（`app.py` L2016 在 GET 处理函数里直接
`db.run_retention(keep_days=30, vacuum=True)`）—— 任何人、任何爬虫、任何浏览器的预渲染
打开那个 URL 就会真删，还会做一次整库 VACUUM（长时间独占写锁）。

**本插件的四条设计（改这一页时必须全部保住）**：

1. 删数据**只接受 POST**；
2. 必须带 **CSRF token**（`Csrf::check()`）；
3. **服务端强制二次确认**：`$_POST['confirm'] !== 'yes'` ⇒ 什么都不做（**不能只靠前端 `required`**）；
4. **默认不做整库 VACUUM**：只 `PRAGMA incremental_vacuum` 增量回收空闲页。

**GET 是纯只读**（只显示边界与"如果现在执行会删多少行"的预览）。实测：GET ×10 + GET 老版形状的 URL +
POST 缺确认 ⇒ **四份快照逐项相同、库文件 size/mtime 未变**。

## 13.7 回滚（本批）

```bash
TS=$(sed -n 's/^TS=//p' /root/statx-b3-BACKUP-PATH.txt)
# ① 整目录回滚
rm -rf /www/wwwroot/xuecha-yecha-stage/plugins/statx
cp -a /root/statx-b3-backup-$TS/statx /www/wwwroot/xuecha-yecha-stage/plugins/statx
chown -R www:www /www/wwwroot/xuecha-yecha-stage/plugins/statx

# ② 只回滚本批改动的 3 个既有文件（推荐：避开并行线在 cli.php 上加的 8 行）
P=/www/wwwroot/xuecha-yecha-stage/plugins/statx
cp -a /root/statx-b3-backup-$TS/statx/admin.php $P/
cp -a /root/statx-b3-backup-$TS/statx/dash.php  $P/
cp -a /root/statx-b3-cli.php.bak2-<时间戳>       $P/cli.php
rm -f $P/ui.php $P/stxui.php $P/pages.php $P/pages2.php $P/pages3.php $P/pages4.php $P/pages5.php $P/adv.php $P/agg.php
chown www:www $P/*.php
```

> ⚠️ **`dash.php` 与 `admin.php` 必须成对回滚**：批次 2 的 `admin.php` 调批次 2 `dash.php` 的页面层函数
> （`statx_dash_page()` 等），混搭会 500。
> ⚠️ **不要整体覆盖 `cli.php` 回滚**：另一条并行线在同一文件里加了 8 行（`selfcheck.php` 备份看守自检）；
> 本批对 `cli.php` 只有 3 处小补丁（dash 任务的 require / 视图与时间档 / `--range=` 选项），
> 改前版本另存为 `/root/statx-b3-cli.php.bak-<TS>` 与 `/root/statx-b3-cli.php.bak2-<TS>`。
> **数据侧无需回滚**：本批**没有对 SQLite 做任何 schema 变更**（`user_version` 仍为 2，列数不变），
> 也未新增/删除任何行。

