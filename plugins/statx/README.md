# StatX · 统计与站点健康（YeCha 插件）

> **一个插件，两件事**：①给站点装一套**自带存储**的访问统计（PV/UV/来源/热门页/设备/爬虫）；
> ②站点可访问性健康检测（探针 + 去抖告警 + 后台矩阵）。
>
> **开箱即用**：装上 → 启用 → 后台会给你**一行 `<script>`** → 贴进模板 → 开始收数。
> 数据落在**你自己的站**里（SQLite 单文件），**不发往任何第三方**，后台**零 JavaScript**。

---

## 1. 三分钟装好

### 1.1 装插件

**方式 A（推荐）**：YeCha 后台 →「应用市场」→ 找到 **StatX** → 安装 → 启用。

**方式 B（手动）**：把 zip **解压后的文件**放进 `<站点根>/plugins/statx/`（**不要**多套一层目录，
包根就是 `plugin.php`），然后在「插件」页启用。目录长这样：

```
<站点根>/plugins/statx/plugin.php     ← 包根
<站点根>/plugins/statx/boot.php
<站点根>/plugins/statx/cli.php
...
```

启用后 YeCha 会自动跑迁移（建 4 张健康表），**不需要手工执行 SQL**。

### 1.2 让插件准备好采集（一条命令，30 秒）

```bash
cd <站点根>/plugins/statx
php cli.php --task=collect-init --enable
```

它一次性做完：自动识别站点域名与 site_key → 建 SQLite 库并建表 → 登记本站 →
生成端点配置 → 打印一份**环境自检**（缺什么会直接告诉你）。

> 没有 SSH？在后台 →「统计与站点健康」→「采集与站点管理」→ **保存** 一次，效果相同。

### 1.3 收数：把那一行贴进模板

后台 →「网站概况」最下方（或「统计检测」页）会给出**属于你自己域名**的一行：

```html
<script async src="https://你的域名/st.js?v=xxxxxxxx" data-site="你的域名"></script>
```

放进：**后台 → 主题/模板 → `stats.foot`**（或 `stats.head`，或主题的 `</head>` 之前）。
YeCha 默认主题都有 `stats.foot` 这个统计代码位 —— 贴进去即可，**不改模板文件、不碰主题代码**。

保存后刷新首页，几秒内「实时访客」就有数据了。

### 1.4 装 cron（**必须做，否则数据只进中转文件、不进库**）

`collect-rollup` 是唯一把访客上报写进 SQLite 的任务，**每分钟一次**：

```
* * * * * flock -n /tmp/statx-collect.lock nice -n 19 ionice -c3 <PHP二进制> <站点根>/plugins/statx/cli.php --task=collect-rollup >> /var/log/statx-collect.log 2>&1
*/5 * * * * flock -n /tmp/statx-notify.lock nice -n 19 ionice -c3 <PHP二进制> <站点根>/plugins/statx/cli.php --task=notify >> /var/log/statx-notify.log 2>&1
10 4 * * * flock -n /tmp/statx-prune.lock nice -n 19 ionice -c3 <PHP二进制> <站点根>/plugins/statx/cli.php --task=prune >> /var/log/statx-prune.log 2>&1
```

把 `<PHP二进制>` 换成 `php -r 'echo PHP_BINARY;'` 的输出（宝塔通常是
`/www/server/php/83/bin/php`，Debian 系通常是 `/usr/bin/php`），`<站点根>` 换成真实路径。

> `flock`/`nice`/`ionice` 都只是**防止重叠与让路**，没有它们也能跑（去掉即可）。
> 不想用 cron 也可以用 systemd timer；**只要每分钟跑一次 `--task=collect-rollup` 就行**。

**不装 cron 能看数据吗？** 能到「手动」为止：后台「采集与站点管理」里有「立即汇总」，点一下就把
已收到的数据刷进库。cron 只是让它自动发生。

---

## 2. 可选件（装了更好，不装也能用）

| 可选件 | 作用 | 不装会怎样 |
|---|---|---|
| **地域库** `ip2region.xdb` | 地域分布 / 城市 Top10 / IPv6 | 地域一列**恒为空**，其它统计一切正常（不报错） |
| **PHP `curl` 扩展** | 站点健康检测、注入后实证 | 健康检测不可用（`--task=health` 会明确说明并退出，采集不受影响） |
| **`pdo_sqlite` 扩展** | 统计采集库 | 采集不可用（**硬要求**，见 §5） |
| **`links` 插件** | 后台「链接健康」区块 | 该区块显示「未安装 links 插件」，其它不受影响 |
| **SMTP**（内核 `mail.*` 或本插件 `statx.mail.*`） | 故障告警邮件 | 不告警，其它不受影响 |

### 2.1 地域库怎么放（可选，**不随包分发**）

地域库是 ip2region 的 `.xdb` 文件（IPv4 约 11 MB，IPv6 约 37 MB，开源项目
<https://github.com/lionsoul2014/ip2region> 的 data 目录可下载）。把它放到：

```
<站点根>/var/statx/ip2region.xdb        ← 推荐（站内 var/，内核/nginx 拒绝直接访问）
```

插件按顺序自动找：`<站点根>/var/statx/` → `plugins/statx/data/` → `/usr/share/ip2region/` →（兼容旧部署）`/opt/tj/data/`。
也可以显式指定：

```bash
php cli.php --task=geo                      # 看当前找到哪个库、能不能读、结构版本
php cli.php --task=geo --ip=8.8.8.8         # 手工验证一个 IP 的归属
```

或后台 →「采集与站点管理」→ **地域库路径** 填绝对路径（留空 = 自动）。

**放好后立刻生效，不需要重启任何服务。** 已收的历史行不会回填地域（因为**我们从没存过 IP**）。

---

## 3. 隐私说明（可直接抄进你的隐私政策）

- **不存 IP**：访客 IP 只在**收到上报的那一瞬间**用于①算地域（可选）②参与访客指纹哈希，**随即丢弃**；
  数据库里**没有任何 IP 字段**。`--task=collect-fields` 与包内 `STATX-FIELDS.md` 列出全部落库字段。
- **访客标识**：用「每天轮换的盐 + IP + UA」算出的短哈希（`visitor_hash`），**不可逆**、
  **跨天不可关联**（同一人昨天与今天算两个 UV）。
- **中间产物**：访客上报先在 `spool` 目录按行追加（默认系统临时目录），cron 汇总后**立即删除**该文件。
- **保留期**：明细默认 **30 天**，按天汇总默认 **24 个月**（后台「数据保留」页可调并手动清理；
  清理是 **POST + 二次勾选确认**，用 GET 打开那一页**不会删任何东西**）。
- **不出站**：数据只写进**你自己的 SQLite**；插件默认**不做任何**对外请求（健康检测探测的是你自己配的目标）。

---

## 3.5 可选：隐藏宿主后台的「外壳顶部」（`statx.ui.hide_host_chrome`）

**默认关闭**。开启后，statx 后台页会把**内核后台那条顶部**（欢迎语 /「查看站点 ↗」/ 站点 Logo 块 /
「博主：xxx」/「退出」）整条收起，并把浏览器标题换成插件自己的 **`统计 · <站点名>`**。

**在后台里设置**：`后台 → 系统 → 设置`（`/admin/?s=plug_statx&p=settings`）最上面的卡片
「**隐藏宿主后台的外壳顶部（欢迎回来 / 查看站点）**」—— 勾选/取消 + 点「保存」，**当页给提示**，
刷新任意本插件页面即见效。纯 HTML 表单，**零 JavaScript**。

等价的命令行写法：

```bash
php plugins/statx/cli.php --task=ui                       # 查看当前值
php plugins/statx/cli.php --task=ui --hide-host-chrome=1   # 开启
php plugins/statx/cli.php --task=ui --hide-host-chrome=0   # 关闭（= 默认，回滚就这一条）
```

### 什么时候该开、什么时候别开

| 你的情况 | 建议 | 为什么 |
|---|---|---|
| **独立统计站**（整站只跑统计，打开 `/admin/` 就是统计后台） | **开（1）** | 内核顶部在这里没有别的东西要看；「查看站点 ↗」通常还指向一个 404 的站根 |
| **把 statx 装进你自己的博客后台** | **关（0，默认）** | 那个顶部是**你自己的**品牌与身份信息，收起后你会找不到返回前台/退出的出口 |

### 它到底改了什么（三个动作，**零 JavaScript**）

| # | 动作 | 机制 |
|---|------|------|
| ① | 收起内核 `<header>` | 给 `<body>` 加作用域类 `stx-hidechrome`，样式表里 `body.stx-hidechrome > header{display:none}` |
| ② | 浏览器标题 | 输出缓冲里只替换**唯一那个** `<title>`（严格匹配，不中即不动） |
| ③ | 去掉登录后的「欢迎回来，…」 | **只匹配这一条前缀**的会话提示并清掉；「设置已保存」等其它提示一律保留 |

### 三条边界（照着放心用）

- **默认不影响别人**：选项 = `0` 时这三个动作**一个都不执行** —— 你的后台与装插件之前逐字节相同。
- **只作用于自己这几个页面**：只认 `/admin/` 且只对 `s=plug_statx*`；同机其它站点、其它后台、前台、采集端点**零影响**。
- **不是"删元素"，是"隐藏"**：内核顶部是内核**硬编码**输出的，插件层没法阻止它进入 HTML，
  所以 DOM 里 `<header>` 仍然存在（1 处），只是 `display:none`。
  想让它**根本不输出**必须改内核（见 CHANGELOG `1.3.2.r11` 末尾的方案与影响面），本插件**不碰内核**。

### 回滚

**在后台**：`后台 → 系统 → 设置` → 取消勾选「隐藏宿主后台的外壳顶部」→ 保存。
**命令行**：

```bash
php plugins/statx/cli.php --task=ui --hide-host-chrome=0
```

两条路一条命令即可全量还原（本选项不写任何表、不动任何数据）。

---
## 4. 后台在哪、能看到什么

后台 →「统计与站点健康」（`/admin/?s=plug_statx`）。左侧 **36 项导航**（7 个分组）+ 1 个隐藏的
「访客轨迹」下钻页 = **37 页**。全部服务端渲染：

- **零 JavaScript**：没有一个 `<script>` 标签、没有外部 CSS/字体/图表库，图表是内联 SVG。
  唯一的内联 JS 是日期选择器的 `onchange="this.form.submit()"`，而它旁边**始终有「查看」按钮**，
  所以就算浏览器禁用 JS 也能完整使用。
- 所有筛选都在 URL 里，**可以直接把看板某个视图发给别人**。

---

## 5. 服务器要求

| 项目 | 要求 |
|---|---|
| YeCha 内核 | `>= 1.0.0-beta.2`（需要 `Plugins::boot()` 在路由/全页缓存**之前**载入 `register_file`） |
| PHP | **≥ 8.1**（Web 与 CLI **同一个版本**，CLI 也必须带下面这些扩展） |
| PHP 扩展 | **必须**：`pdo_sqlite`、`pdo_mysql`、`mbstring`、`json`；**建议**：`curl`（健康检测）、`posix`（库文件属主自愈） |
| SQLite | **≥ 3.24**（按天汇总用了 `ON CONFLICT … DO UPDATE`） |
| 权限 | PHP 用户对**插件目录可写**（要生成 `rt.php`）、对库目录与 spool 目录可写 |
| 目录布局 | 插件必须在 `<站点根>/plugins/statx/`（`cli.php` 靠它推导站点根） |
| 定时任务 | 每分钟 `--task=collect-rollup`（见 §1.4） |
| nginx | 未知路径要交给 `index.php`（`try_files … /index.php?$query_string`）。<br>兜底：`/index.php?st=js` 与 `/index.php?st=h` 在任何配置下都能用 |

**一条命令看这台机器缺什么**：

```bash
php <站点根>/plugins/statx/cli.php --task=env
```

---

## 6. 数据存在哪 & 怎么备份

默认：**`<站点根>/var/statx/statx.db`**（+ WAL 模式的 `-wal` / `-shm`）。

- 放在站点根的 `var/` 下是刻意的：这个目录内核本来就不让 HTTP 直接访问（`var/cache`、`var/publish` 同理），
  所以**任何主机上都装得下、权限天然正确、又不会被下载走**。
- 想换地方：后台填「SQLite 库位置」，或写 `plugins/statx/sdb_path.php`
  （`<?php return '/绝对/路径/statx.db';`），或 CLI 临时用环境变量 `STATX_SQLITE=/tmp/x.db`。
- 备份就是**一条 `cp`**（SQLite 单文件）。更稳可用 `sqlite3 statx.db ".backup 备份文件"`。
  后台「数据保留」页会显示**当前真实库路径**，并给出可直接复制的备份命令。

**不要**把库放进可被 HTTP 取到的目录：`statx.db-wal` 这种名字**盖不住**老式 `\.(db)$` 拒绝规则。
（默认路径不受影响；只有手动改路径时才需要注意。）

---

## 7. 卸载 / 回滚

1. 后台 →「插件」→ 停用 StatX。
2. 后台 →「采集与站点管理」→ 对每个站点点 **移除注入**（它只摘掉本插件自己的
   `<!-- statx:begin -->…<!-- statx:end -->` 单元，**旁站标签一个字节都不动**）。
3. 「插件」→ 卸载（内核会删掉 4 张 `ye_statx_health_*` 表与 `statx.*` 选项）。
4. 手动收尾（内核不会碰这两个东西）：
   ```bash
   rm -rf <站点根>/var/statx          # 统计库（先确认不需要备份！）
   rm -rf <站点根>/plugins/statx      # 插件目录
   ```
5. 从 crontab 删掉 §1.4 那几行。

---

## 8. 排错（按症状查）

| 症状 | 原因 | 怎么办 |
|---|---|---|
| 后台看板全是 0，但站点有流量 | ① 汇总 cron 没装 ② 没注入那一行 ③ 库不可写 | `cli.php --task=collect-status`（打印库路径/可写/spool/最近上报），再手工跑一次 `--task=collect-rollup` |
| `/st.js` 返回 404 | 采集总开关没开，或 nginx 把 `.js` 当静态文件抢走了（宝塔默认 `location ~ .*\.(js|css)$` 会） | 跑 `cli.php --task=collect-init --enable`；被抢走的站加一条 `location = /st.js { try_files $uri /index.php?$query_string; }` |
| `/st` 返回 204 或空白 | 采集关闭（端点整体不存在是**刻意**设计） | 同上开总开关 |
| 地域一列永远为空 | 没装地域库 | 见 §2.1（**不影响**其它统计） |
| 后台老是提示「探针配置故障」 | 那是**可选**的备份看守自检；默认已关闭，只有显式配了 `statx.selfcheck.watch_state` 才跑 | 不用管；或把该选项清空 |
| 健康检测不跑 | 缺 `curl` | `cli.php --task=env` 会直接告诉你 |
| 保存设置后报「rt.php written=no」 | 插件目录对 PHP 用户不可写 | `chown -R <PHP用户> <站点根>/plugins/statx` |

**自检三连**（把输出贴给开发者就能定位）：

```bash
php cli.php --task=env              # 环境：扩展 / 库位置 / spool / 权限
php cli.php --task=collect-status   # 采集：开关 / 端点 / spool / 站点登记 / 最近上报
php cli.php --task=collect-endpoint # 端点：真实请求一次 /st.js 与 /st，看状态码与 CORS
```

---

## 9. 包里有什么

```
plugin.php            插件声明（id/版本/表/卸载规则/端点挂载点）
boot.php              前台挂载：在路由与全页缓存**之前**拦下 /st、/st.js、/s3.js
endpoint.php          上报端点 + 采集脚本（**零 SQL**，只追加写 spool）
collect.php           采集侧：库表/站点登记/spool 收件/按天汇总/清理/一行 JS 注入
sdb.php               插件自带的 SQLite 存储层（路径解析、PRAGMA、自检）
geo.php               ip2region xdb 读取器（**可选**，找不到就返回空）
inc.php               共享层：选项默认值、建表、状态机、告警、保留
lib.php               探针引擎（curl_multi，仅健康检测用）
botlib.php            爬虫识别（有 guardweb 就复用它的规则，没有就用内置表）
selfcheck.php         **可选**的内部自检（默认关闭）
links.php             **可选**：只读复用 links 插件的体检结果
derive.php            来源四分类 / 关键词 / 浏览器 / 操作系统（纯函数）
dash.php agg.php pages*.php ui.php stxui.php adv.php admin.php
                      **零 JS 后台**：37 页看板 / 分析 / 检测 / 设置
cli.php               命令行：collect-init / collect-rollup / collect-status / env / migrate …
migrations/*.php      健康检测 4 张 MySQL 表的迁移（幂等）
rt.php                **未初始化的占位配置**（on=0）—— 装好前端点不生效、数据不外流
data/                 放 ip2region xdb 的位置（**包里为空**，地域库不分发）
STATX-FIELDS.md       落库字段清单（隐私自证）
DEVELOPER-NOTES.md    开发/演进/历史事故记录（给维护者，可删）
CHANGELOG.md          版本变更
```

---

## 10. 许可

MIT。作者：YeCha。

---

## 11. 必读：内核版本坑（**装完端点 302/404 的头号原因**）

> 这一条是 2026-09-14 在「干净站从 zip 装插件」的实测里踩到的，**不修就永远收不到数据**，
> 而且症状会骗人（看着像 nginx 抢静态、像路由问题、像 seo 配置问题）。

### 症状

装好插件、启用、`rt.php` 里 `on=1`，但：

```
GET /st.js   → 302 Location: /     （或 404）
GET /st      → 302 Location: /
GET /index.php?st=js → 首页 HTML
```

**关键鉴别点**：返回的**不是**插件的脚本，而是首页 HTML 或重定向 ——
说明请求进了内核路由，`register_file`（`boot.php`）**从头到尾没被 `require`**。

### 根因（内核 `core/Plugins.php::compat()` 的版本归一 bug）

内核 `compat()` 用这条正则剥预发布/构建后缀：

```php
$cur = (string)preg_replace('/[-+].*$/', '', (string)YC_VERSION);
```

但本内核构建的版本串是 **`1.0 Beta Build20260910`** —— 后缀是**空格**分隔，`[-+]` 去不掉，于是：

```php
version_compare('1.0 Beta Build20260910', '1.0.0', '>=')   // === false  ★
```

`compat()` 返回 `false` ⇒ `Plugins::boot()` 里的 `continue` 把插件跳过
⇒ **所有第三方插件的 `register_file` 一律不被 `include`**。

> ★ 注意：只把后缀按空白切掉也**不够** —— `version_compare('1.0','1.0.0','>=')` **仍是 false**
> （段数少即视作更小）。必须**同时**做「按空白切」+「补足三段」。

**为什么老站没事、新装的才踩**：这套归一补丁是 2026-09-11 给线上站单独打的
（见 `themes/gushu/CORE-PATCH.md`），**没有随插件包分发**。老站的内核已经修好，新下的内核还是旧版。

### 怎么快速确认（一条命令，不发任何 HTTP）

```bash
php plugins/statx/cli.php --task=collect-endpoint
```

看第一段「内核引导预检」：

```
--- 内核引导预检（本插件能不能被 Plugins::boot() 载入）---
  ✔ Plugins::ext('statx')  ⇒  dir=statx ver=1.2.0
  ✔ Plugins::isOn('statx')  ⇒  plugin.statx.enabled='1' removed='0'
  ✘ Plugins::compat()  ⇒  YC_VERSION='1.0 Beta Build20260910' requires.core='1.0.0-beta.2' → version_compare(...) = false
--- ★ 已定位根因：boot.php 未被载入（原因：compat），端点不可能通 ---
```

看到 `✘ Plugins::compat()` 就是这个坑。（命令会直接打印修法。）

### ★ 修法 A（推荐 · **不必改内核**）：启用插件的「独立入口」

内核的 `compat()` 是内核的事，**本插件自带一条不经 `Plugins::boot()` 的通道**，
所以下面这条路**不需要改任何内核文件**，一条命令就够：

```bash
cd <站点根>/plugins/statx
php install-standalone.php            # 在站点根放一份 st.php 副本（可改名）
php install-standalone.php --nginx    # 顺带写入 nginx 两行（会先 nginx -t，失败自动还原）
```

`--nginx` 写的其实只有这两行（也可以自己贴进该站 `server{}`）：

```nginx
location = /st    { try_files $uri /stx.php?$query_string; }
location = /st.js { try_files $uri /stx.php?$query_string; }
```

> **连 nginx 都不想动**？那就用查询串形式，功能完全相同：
> `<script async src="https://你的域名/stx.php?st=js" data-site="你的域名"></script>`

**原理**：`plugins/statx/st.php` 只做三件事 —— ① 读同目录/站点根的 `config.inc.php`；
② 只 `require` 本插件的**纯函数**文件（`botlib / derive / geo / sdb / collect`）；
③ 命中端点就交给 `endpoint.php` 输出。
它**完全不碰** `core/bootstrap.php`、`Db`、`Plugins::boot()`，因此：

- 内核那个 `compat()` bug 与它**无关** —— 不改内核也能收数据；
- ★ **顺带更快**：内核 bootstrap 每个请求都要起 PDO 并读选项表，而采集端点本来零 SQL；
  走独立入口时这份开销**整个不付**（2026-09-14 实测：同一台机上端点到 **10ms 级**，见本文档末尾实测表）。

### 修法 B（治本）：给内核 `core/Plugins.php::compat()` 打版本归一补丁

**这一条能一次修好所有第三方插件**，适合愿意动内核的人。把 `compat()` 方法体换成：

```php
public static function compat(array $m): bool
{
    $needCore = (string)($m['requires']['core'] ?? '');
    if ($needCore === '' || !defined('YC_VERSION')) { return true; }
    $norm = static function (string $v): string {
        if (preg_match('/^\s*(\d+(?:\.\d+)*)/', $v, $mm)) { $v = $mm[1]; }
        else { $v = (string)preg_replace('/[\s\-+].*$/', '', $v); }
        $pp = explode('.', $v);
        while (count($pp) < 3) { $pp[] = '0'; }        // '1.0' → '1.0.0'（★ 必须做）
        return implode('.', $pp);
    };
    return version_compare($norm((string)YC_VERSION), $norm($needCore), '>=');
}
```

改完 `php -l core/Plugins.php`，然后**用 reload 而不是 restart** 清 opcache：

```bash
systemctl reload php-fpm-83     # 宝塔：面板 → 软件商店 → PHP → 重载配置
```

> ★ **2026-09-14 起这条补丁已合回「上游发行包」**（`yecha-blog-1.0-beta-build20260911.zip` 起）。
> 也就是说：**从新发行包装的新站不会再踩这个坑**；你之所以还要看本节，
> 是因为本站装的是 `20260910` 或更早的发行包。

### ✘ 不要再试：放宽 `plugin.php` 的 `requires`

网上最容易想到的「绕过」是把 `plugin.php` 里的 `'requires' => ['core' => '1.0.0-beta.2']`
改小。**这一招没用**，两个理由：

1. 内核是在**读 `requires` 之前**就因为 `compat()` 把插件 `continue` 掉的 —— 改值改变不了结果；
2. 就算改成 `'1.0'`，`version_compare('1.0', '1.0', '>=')` 仍是 `false`（同理 `'1.0.0-beta.2'` 亦然）。

### 修完应该看到

```
GET /st.js  → 200  application/javascript  X-Statx: js
GET /st     → 200  image/gif (43B, GIF89a)  X-Statx: bad-site   ← 裸请求没带 s= 时的正常值
GET /st?s=<site_key>&probe=1 → 200  43B  GIF89a  X-Statx: probe-ok
OPTIONS /st（带 Origin）     → 204  ACAO=<本站 origin>
```

### 顺带：`seo.404_home=1` 会**掩盖**这个错误

若该站开了「未知路由跳首页」，上面那些 404 会被替换成 `302 → /`，
于是你看到的是「怎么都跳首页」，更容易误诊。**本插件不会去改这个设置**，
但排错时请先关掉它（或在自检里看 `X-Statx` 头），否则真实原因看不见。

---

## 12. 删数据前的「先预览后确认」（2026-09-14 起默认开启）

> 背景教训：曾把老部署里 **16 位随机 hex 的 data-site token 误判成测试脏数据**，
> 删掉了 361 行真实数据。老部署的 token 就是那个长相，**看着像测试数据 ≠ 是测试数据**。

因此所有**删行**的 CLI 入口默认进入**预览模式**：不加 `--yes` 时只打印
「将删 N 行 + 前 10 行样本」，**绝不执行删除**；只有显式 `--yes` 才真删。

| 命令 | 不加 `--yes` | 加 `--yes` |
|---|---|---|
| `--task=collect-prune` | 打印人类/爬虫明细各将删多少行 + 前 10 行样本（ts/site_key/path/kind） | 真删（分批 + 增量回收） |
| `--task=collect-selftest --purge --site=X` | 打印 site_key=X 的明细/汇总将删多少行 + 前 10 行样本 | 真删该 site_key |
| `--task=prune` | 打印能力②两张 MySQL 表各将删多少行 | 真删 |

```
============================================================
== collect-selftest --purge · 预览模式（未加 --yes，**不会删除任何行**）==
============================================================
[明细 hits] site_key=e2e-preview-test.invalid
  → 将删 39 行
  前 10 行样本（★ 逐行确认；site_key 看着像测试数据也**不要**擅自认定）：
    {"id":2,"ts":"2026-09-14 15:48:22","site_key":"e2e-preview-test.invalid","path":"/selftest/0.html","kind":"cl","is_bot":1}
    ...
★ 重跑并加 --yes 才会真删：php plugins/statx/cli.php --task=collect-selftest --site=... --purge --yes
```

★ **cron 不受影响**：`collect-prune` 在 cron 里请照旧带 `--yes`
（例如 `--task=collect-prune --keep=30 --yes`），否则每天只会打印预览、不会清理。

---

## 13. 装完的验收清单（照着做，5 分钟）

```bash
# ① 内核引导预检 + 端点自检（应全 ✔，且 script/endpoint 都 200）
php plugins/statx/cli.php --task=collect-endpoint

# ② 采集侧总状态（开关 / rt.php / 积压 / 存储层 / 各站注入实证）
php plugins/statx/cli.php --task=collect-status

# ③ 存储层一览（库路径/权限/PRAGMA/表索引字节）
php plugins/statx/cli.php --task=sdb
```

然后**用真实浏览器**打开一个贴了那一行 JS 的页面，再看：

```bash
php plugins/statx/cli.php --task=collect   # 收 spool → SQLite
php plugins/statx/cli.php --task=collect-fields   # 看最新落库明细
```

★ 注意：`st.js` 第 5 行有 `navigator.webdriver` 自杀开关 ——
**headless/自动化浏览器默认不会上报**，验收时请用真实浏览器（或用 `--disable-blink-features=AutomationControlled`）。

---

## 14. 本次实测证据（2026-09-14 · 干净站 xuecha.org）

| 项 | 修复前 | 修复后 |
|---|---|---|
| `GET /st.js` | 302 → `/` | **200 · 1912 B · application/javascript · `X-Statx: js`** |
| `GET /st`（裸） | 302 → `/` | 200 · 43 B · image/gif（`GIF89a`）· `X-Statx: bad-site`（未带 `s=` 的正常值） |
| `GET /st?s=xuecha.org&probe=1` | 302 → `/` | **200 · 43 B · `X-Statx: probe-ok`** |
| `OPTIONS /st`（Origin=本站） | — | **204 · ACAO=https://xuecha.org** |
| 真实浏览器打点 | 无 | **POST /st → 200**，spool 落一行 → `collect` 入库，`hits` **0 → 1** |
| 数据落点 | 空 | `/www/wwwroot/xuecha.org/var/statx/statx.db`（**本站自己的库**，只有 `site_key=xuecha.org` 一条） |


---

## 附：站点管理 / 账号（**1.3.2 起新增**）

装好之后，「一个正常用户进来要做的事」就集中在左栏 **系统** 组这两页里。

### 一、站点管理（`后台 → 系统 → 站点管理`）

**1) 加站** —— 填「名称 + 域名」→ 点「**添加并生成统计代码**」。
`site_key` 由域名自动生成（例：`https://www.XueCha.org/abc` → `xuecha.org`），
**一旦生成不再变**（历史数据全靠它）。

**2) 拿代码** —— 保存后页面**当场**给出这一行（纯文本，自己选中复制；本插件后台零 JS，**没有复制按钮**是故意的）：

```
<script async src="https://你的端点域名/st.js?v=xxxxxxxx" data-site="你的site_key" data-ep="https://你的端点域名/st"></script>
```

贴在哪：

- **YeCha 站点** → 后台「设置」里塞进 **`stats.foot`**（或者在本页点「一键写入该站的 `stats.foot`」，
  它会顺带**抓该站首页实证**；只有本机能找到该站站点根目录时才出现这个按钮）
- **静态页 / 别的 CMS** → 每个页面 **`</body>` 之前**放一次（脚本自己判断，重复放也没事）
- `data-site` 必须**逐字**等于生成的 `site_key` —— 改一个字就统计到别的站去了

> 端点域名跟随插件既有配置：本站是 `https://tj.51puer.com`；**你把插件装到自己的站上时，它就是你自己的域名**。
> 插件**从不**把访客数据指向第三方域名。

**3) 看有没有在收数** —— 列表里看 **最近上报** 与 **近 24h 行数**；
**近 24h 为 0 会标红「没通」**，页面顶部还有一条汇总提示告诉你「有几站没通」。
不放心就点那站的「**检测这个站**」：它会分别检查 ①端点通不通 ②该站 HTML 里有没有这行代码 ③最近有没有上报，
然后给**一句话结论 + 怎么办**。

**4) 改名 / 换域名 / 停用** —— 改域名**不影响历史数据**（历史挂在 `site_key` 上）；
**停用 = 不再统计，但一条历史都不删**。想彻底不统计又不想删数据，用「停用」就够了。

**5) 删站（不可恢复）** —— 点「删除…」：

1. 先看到**预览**：将删多少行明细、多少行按天汇总、这些数据的时间范围、**该站最近 10 行样本**，
   以及备份会写到哪个路径；
2. 想真删，要**勾选确认框**并且**把手打的 `site_key` 输对**（服务端强制的两道确认）；
3. 删之前插件会**自动做一次整库一致性备份**（`VACUUM INTO`，写在 `<站点根>/var/statx/backup/`，
   HTTP 取不到），回执里会把完整路径与 `integrity_check` 结果告诉你 —— **请把那个备份另存一份到别处**。

> 打开预览页、不勾选、打错 key、用 GET 请求 —— **这几种情况都不会删任何数据**。

### 二、账号（`后台 → 系统 → 账号`）

这一页改的是**本统计站自己的后台账号**（YeCha 的 `ye_users` 表里那一行），**与站点统计毫无关系**：
改它不会动任何一个站的数据，也不会动站点侧那行 JS。

| 操作 | 要求 |
|---|---|
| **改登录名** | 要输入**当前密码**；2–30 位（中英文、数字、`_`、`-`）；不能与任何用户的**用户名或邮箱**重复 |
| **改密码** | 要输入**当前密码** + 新密码两次；**至少 6 位**（与 YeCha 内核同一条规则）；bcrypt 存储 |

★ **改完当前会话不会掉线**（内核的会话按用户 id 存，不按登录名），但**下次登录请用新凭据**。
★ **已经登不出来了怎么办**：这一页帮不了你（进不来）。在服务器上用 root 走内核同一个哈希函数重置即可 ——
命令就写在「账号」页的「忘了密码怎么办」一节里，可以整段复制。

---

# README 追加章节（r10）— 两条必须遵守的约定

> 以下两节由 r10 加入。两条都是**「别人装上去才会踩、在统计服务器本机看不出来」**的坑，
> 因此写成硬约定放在这里。

---

## ★ 自定义 shell 的硬约定：必须输出 `stat_html('stats.head'/'stats.foot')`

**内核只提供函数，不提供调用点。** `core/bootstrap.php` 里只有：

```php
function stat_html(string $key): string { ... }   // 读 stats.head / stats.foot 并归一历史双重转义
```

内核对 `stat_html()` 的调用点是 **0 个**。真正决定「统计代码贴上去有没有用」的是**渲染那一页的人**：

| 渲染方 | 现状 |
| --- | --- |
| 五套主题 `themes/*/views/layout.php` | ✅ 各自已有 `stat_html('stats.head')` + `stat_html('stats.foot')` |
| **自定义 shell（插件自己拼 `<!DOCTYPE html>`）** | ⚠️ **必须自己输出** —— 否则该站**收不到统计** |

### 硬约定（写插件时必须遵守）

任何**自己拼整页 HTML、不经过主题 `layout.php`** 的插件/页面，都必须在 `<head>` 里输出
`stats.head`、在 `</body>` 前输出 `stats.foot`：

```php
// 在 <head> 内（例如 </head> 之前）
$__st = stat_html('stats.head');
if ($__st !== '') { echo "\n<!-- stats.head -->\n" . $__st; }

// 在 </body> 之前
$__st = stat_html('stats.foot');
if ($__st !== '') { echo "\n<!-- stats.foot -->\n" . $__st; }
```

两个位都要输出（不要只输出一个）：后台「一键写入」会先试 `stats.foot`，失败自动改投 `stats.head`；
只输出一个位会让其中一半情况失效。建议用统一实现一次把两个位都拿出来：

```php
. (static function (): string {
    $__out = '';
    foreach (['stats.head', 'stats.foot'] as $__k) {
        if (!function_exists('stat_html')) { continue; }
        $__st = stat_html($__k);
        if ($__st !== '') { $__out .= "\n<!-- " . $__k . " -->\n" . $__st; }
    }
    return $__out;
})()
```

### 真实事故（r10 根因①）

`plugins/svg/lib.php::svg_shell()` 用自定义 shell 渲染**整站所有页面**，但**从不输出 `stats.foot`**。
后果：**任何用 `svg` 插件的站（如 atsvg.com）永远收不到统计**，而统计后台里那站看起来「注入成功」。
r10 已修（两份副本统一输出 head + foot）。

### 自检「这个站到底收到没有」

别只看状态码，看**内容标记**：

```bash
curl -sS -A 'Mozilla/5.0 …Chrome/128…' https://<你的域名>/ | grep -o 'data-site="[^"]*"'
```

裸 URL 必须能 grep 到 `data-site="<你的 site_key>"`。**grep 不到就没有统计代码位**，
去检查该页的渲染方（主题 or 自定义 shell）有没有按上面的约定输出。

> **内核兜底方案（已评估，未实施）**：可在渲染出口挂统一后置钩子，自动把 `stats.head/foot`
> 注入 `</head>` / `</body>` 之前。代价是要改内核渲染出口，且与「主题自己输出」会重复注入（需去重）。
> 需要它的话请站长明确指示。

---

## ★ 启用 home-static 的站：注入后必须「清页缓存 **+** 重建静态文件」，验收用**裸 URL 三方一致**

### 为什么会踩

内核 `home-static` 插件（`plugins/home-static`）在静态文件**新鲜（TTL 内，默认 600s）**时，
由 `hs_front()` **直接把 `var/home-static/index.html` 吐出去**，**动态渲染根本不执行**。

因此只做「写 `stats.foot` + 清 `var/cache/pages`」时：
**写入是成功的，但裸 URL 仍然命中旧静态 HTML** ⇒ 实证失败 ⇒ 自动回滚。
（r9 实测：清了 52,250 个页面缓存**仍然回滚**。回滚行为本身是对的，**是流程缺一步**。）

### SOP（必须两件都做）

```
① 清页面缓存        清 <站根>/var/cache/pages/*.cache（内核 Cache::flush('pages') 同口径）
② 重建静态首页      重新渲染并覆盖 <站根>/var/home-static/<lang>/index.html
                    ★ 必须带**正确的 Host**：用 --resolve / CURLOPT_RESOLVE 把域名钉回环，
                      或用显式 Host。**不能裸抓 loopback** —— 多站 nginx 有 default_server 且无 SNI，
                      那样会**打到别的站**（这正是老 hs_fetch() 的坑，r10 已修）。
```

`statx` 的 `--task=collect-inject` 从 r10 起会**自动做这两件事**：
检测到该站启用 home-static 就额外重建静态首页，然后按下面的判据验收。`--task=collect-remove` 同样重建
（否则 TTL 内裸 URL 仍直出「还带着统计代码」的旧静态页，**移除当场不生效**）。

### ★ 验收判据：裸 URL **三方一致**

```
源站裸 /  ==  公网裸 /  ==  磁盘静态文件
判据：字节数  +  sha256  +  HTML 里含 data-site="<site_key>"   三者全等
```

- **裸 URL** = 不带任何查询串（`https://<域名>/`）。带查询串会走动态渲染，**看不到静态直出**。
- ⚠️ **只看带查询串的动态渲染会漏** —— **这正是 r9 误判的根因**：动态渲染已经带上了新标签，
  但用户实际访问的裸 URL 还在吃旧静态文件。
- 未启用 home-static 的站**本来就没有静态文件**，此时静态比对记为**不适用**（只比「源站 == 公网」），
  不是失败。

一条命令自检（源站直连 + 公网 + 磁盘静态，比字节数/sha256/data-site）：

```bash
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
curl -sSk --resolve <域名>:443:127.0.0.1 -A "$UA" https://<域名>/ | tee /tmp/o.html | wc -c
curl -sSk                            -A "$UA" https://<域名>/ | wc -c          # 应与上一行相同
wc -c < <站根>/var/home-static/index.html                                      # 应与上一行相同
grep -c 'data-site="<site_key>"' /tmp/o.html                                   # 应 >= 1
sha256sum /tmp/o.html <站根>/var/home-static/index.html                        # 应相同
```

### 平台发布同样适用

应用/插件包上架也要做「发布后可达性自检」：**裸 GET（不带查询串）**发布 URL ⇒ 必须 200
且**字节数 == 本地包大小**；再用 `--resolve` 直连源站复核一次（证明不是 CDN 缓存骗我们）。
目标目录只允许 `…/yecha.org/var/market/`（`location ^~ /market/` 指向它）；
**每次用全新文件名** —— CDN 会在文件就位前缓存 404（`cf-cache-status: HIT` + `max-age=14400`）。


---

## 15. r12 补充：来路存的是什么、蜘蛛表能回答什么（2026-09-18）

### 15.1 来路：`ref` 列存的是**完整 URL**，`ref_domain` 只是它的域名

`hits` 表的 `ref` 列一直是**清洗后的完整 referrer URL**（只接受 `http/https`、去掉控制字符、**截断 500**、
并按下面的规则**剥离敏感参数**）。`ref_domain` / `ref_kind` / `ref_type` 都是从它派生出来的**辅助列**。
所以「只看到根域名」是**看板某张表只 SELECT 了域名**，不是数据没存 —— 想看来路原文就看
「分析 → 来源分析 → 搜索引擎」的**「完整来路地址」列**（截断显示，**鼠标悬停看全文**，零 JS）。

### 15.2 隐私：referrer 里的敏感参数会被剥掉

入库前会按**参数名**剥离凭据/会话/身份类参数（共 60 个，例如
`token` `access_token` `api_key` `secret` `key` `jwt` `auth` `password` `session` `sid` `csrf`
`signature` `otp` `code` `email` `phone` `username` `account` `uid` …）。
**只删这些参数**，路径、其余参数与参数顺序**原样保留** ——
所以引擎关键词（`?q=…` / `?wd=…`）**不受影响**。
**一个都不命中时返回值与入参逐字节相同**（不会平白改写你的来路），并且**幂等**。

长度上限 **500 字符**。本插件**不存 IP、不存 UA**（UA 只参与每日轮换盐的访客哈希）。

### 15.3 蜘蛛表能回答什么、不能回答什么

**本插件的采集是"一行 JS"**（`st.js` 读 `document.referrer` 后 `sendBeacon`）。
这意味着：

| 能统计到 | 统计不到 |
|---|---|
| **会执行 JS 的客户端**：真实访客、以及一部分会跑 JS 的采集器 / AI 爬虫（AhrefsBot、Sogou 系列、Bytespider…） | **抓 HTML 时不执行 JS 的爬虫**：**Googlebot / bingbot / Applebot / Amazonbot / SemrushBot…** |

⇒ **Googlebot 与 bingbot 在蜘蛛表里常年是个位数甚至 0，是正常的**：
它们在 nginx access log 里可能每天几百次，但**不会**出现在这里。
这不是漏采、不是分类错，是这种采集方式的边界。

**想知道"真蜘蛛每天来抓了多少"，权威口径是服务器 access log**（按 UA 计数）。
本插件**不自动读日志**（那需要额外开一条服务端链路与一张新表）—— 如需，见插件仓库 CHANGELOG `1.3.2.r12` §①。
另外蜘蛛表的「次数」应读作「**自称是谁**」：库里最大的那一行很可能是**冒用知名 UA 的采集器**。
