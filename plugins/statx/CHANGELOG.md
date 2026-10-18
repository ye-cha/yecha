## 1.3.2.r12 — 2026-09-18「蜘蛛口径说清 + 来路存/显完整地址（含隐私加固）+ 修 r12 初版致命 SQL」

> ★ 站长原话：「google 蜘蛛和 bing 蜘蛛是不是显示不全？」「来路统计只有根域名，要统计到带参数的页面地址，
>   例：要能看到 `https://www.bing.com/search?q=…&FORM=PERE1`」⇒ 真需求 = **要能看到搜索词 `q=普洱茶`**。
> 本批**未删改任何统计数据行**（只新增 6 行验证数据，逐条登记在报告附录 A）；
> `st.js` / `endpoint.php` 的采集契约**向后兼容** —— 旧客户端参数集、旧 spool v1 格式**实测仍能收**。

### ① 蜘蛛「显示不全」到底是哪一种？—— 是「没收」，且是架构决定的

三种可能逐一定位（**不是猜，是数出来的**）：

| 候选 | 裁定 | 判据 |
|---|---|---|
| ⒜ **没收**（压根没上报） | ✅ **主因** | `gushuwu.com` 的 access log：Applebot **7,449** 次 / 库里 **0** 行；`atsvg.com`：Amazonbot **1,386**、SemrushBot **1,063**、Bytespider **511**、Applebot **460**、MJ12bot **347** / 库里**全 0** |
| ⒝ **分类错** | ⚠️ 真实但量小 | `/st` 上报流 976 条里只有 **7 条**该判爬虫却算成真人（ShapBot）。**已修** |
| ⒞ **看板 TopN 挤掉** | ❌ 不成立 | 库里 `is_bot=1` 的**不同 `bot_name` 只有 9 个**，而看板的 `LIMIT` 是 60 / 40 ⇒ 远没截断 |

**根因**：本插件的采集是**一行 JS**（`st.js` 读 `document.referrer` → `sendBeacon`）。
**Googlebot / bingbot / Applebot 抓 HTML 时都不执行 JS** ⇒ 它们**根本不会发这个上报**。
日志里有几百上千次，这里也**不会有行** —— **这不是漏采、不是算错，是这种采集方式的固有边界。**

### ② 蜘蛛：修了什么

| # | 改动 | 依据 |
|---|---|---|
| ① | `scraper` 名单新增 `shapbot`、`aionbot` | 真实日志 4,597 次 / 83 次，旧名单**完全识别不出**，100% 算成真人 PV |
| ② | `ai` 名单新增 `exasearchbot`、`reflectionbot` | 真实 `/st` 上报流实测各 12 / 11 条被判成真人 |
| ③ | **新增 `statx_bot_selfclaimed()` 兜底规则** | 认 `…(compatible; XxxBot/1.0; +https://…)` 这一**自证形态**。名单永远滞后，加名字只能修今天看到的 4 个；这条规则修的是"这一形态"。**刻意放在三张名单之后** ⇒ 既有爬虫的 `bot_name`/`bot_cls` 与 r11 逐字节一致 |
| ④ | 去掉 r12 初版重复列的 2 条名单项 | `yisouspider`、`applebot-extended` 各出现了两遍 |
| ⑤ | 澄清 `statx_ref_split()` 的**误导性注释** | 旧注释写「只保留域名，**绝不存完整 Referer**」，与实现不符 —— 排查时会被它带偏 |

**真实数据回放的前后对照**（把 r11 的 `botlib.php` 整体改名后与线上新版**同进程并排跑**）：

| 样本 | r11 旧：爬虫/真人 | **r12 新：爬虫/真人** | 变化 |
|---|---|---|---|
| 三站 access log（61 个 UA / 17,516 行） | 11,120 / 5,842 | **15,656 / 1,306** | **+4,536**（4 个 UA，全部"真人→爬虫"） |
| 生产 `/st` 上报流（35 个 UA / 976 条） | 622 / 354 | **629 / 347** | **+7**（ShapBot） |

**不误伤真人**：对全部真实样本全量回归，`human → bot` 误判 **0 条**；Chrome/Edge/Safari/iPhone/MSIE/微信/钉钉/WPS
等真实浏览器 UA 判定**全部不变**；r11 已知爬虫的 `cls/name` **逐项不变**。

> ⚠️ **必须一起说清**：蜘蛛表的口径是「**自称是谁**」，不是「真的是谁」。
> 库里最大的 `sogou` 13,459 行全部来自 liulanqi.com 的 `/st` 上报，
> 而这两个 UA 在您给的 access log 里出现 **0 次** —— 它们是**冒用 Sogou/百度 UA 的采集器**。
> 要"点名真蜘蛛"需要另开一条**服务端解析 access log（＋官方 IP 段核验）**的链路，
> 本批**只报了方案、未实施**（报告 §7.1）。

### ③ 来路：`query` 从来没有"被丢"，是**没显示**

把链路逐行读完的结论：`st.js:14` 发的是**完整 referrer**；`endpoint.php::statx_ep_ref()` 只做
「http/https 校验 + 去控制字符 + 截断 500」；`collect.php:705/756/767` 原样入库并派生 `ref_type`/`kw`。
**没有任何一行在入库时丢掉 query。** 丢的是**显示**：

| 页面 | r11 原版 | 后果 |
|---|---|---|
| 仪表盘「来路 Top10」 | `dash.php:135-138` 只 `SELECT … ref_domain …` | 只显示域名 |
| 来源分析→搜索引擎 | `agg.php:92-97` 只 `SELECT ref_domain k …` | 只显示域名 |
| 来源分析→关键词 / 关键词表 | `agg.php:110-117` + `pages2.php:50` 的 SELECT/列定义里没有 `ref` | **根本没有来路列** |

⇒ 本批新增/修正：

1. **修掉 r12 初版留下的致命 SQL**：`statx_q_keyword()` 里 `, MAX(ref) ref_full` 被拼在 `WHERE` **之后**
   ⇒ SQLite 报 `near ",": syntax error`，异常被吞 ⇒ **关键词页永远"暂无数据"**（实证：某站库里有 25 行 `kw`，
   页面显示「0 关键词条数」）。
2. `MAX(ref)` → **`MAX(id) mx, ref`**：`MAX()` 作用在文本列上是**字典序最大**，不是"最近一条"；
   改用 SQLite bare-column 语义取 `max(id)` 那一行的 `ref`，**"最近一条"这个说法现在才真的成立**。
3. 「搜索引擎」表**新增「完整来路地址」列**（截断 72 字符 + `title` 悬停看全文，**零 JS**）——
   站长要的是"**看到**带参数的页面地址"，**只给悬停不够**。
4. `pages4.php` / `pages2.php` 的口径说明同步更新。

**端到端验收（真浏览器 + 站长给的那条 Bing 地址）**：用 CDP 在网络层拦截并就地应答一个小页面
（带 `Referrer-Policy: unsafe-url`）再 `location.replace(被测站)` ⇒ 下一跳的 Referer 由**浏览器自己的
referrer 机制**算出，不是手工塞的头。结果：

```
document.referrer = https://www.bing.com/search?q=%e6%99%ae%e6%b4%b1%e8%8c%b6&sp=-1&lq=0&pq=…&cvid=5F2A3B&FORM=PERE1
真实 POST /st     = …&r=https%3A%2F%2Fwww.bing.com%2Fsearch%3Fq%3D%25e6%2599%25ae…%26FORM%3DPERE1   → 200
库中该行          = ref 含 `?q=…`，ref_domain=bing.com，ref_type=search，kw=普洱茶，is_bot=0
看板              = 关键词页显示「普洱茶 | 引擎 | https://www.bing.com/search?q=…&FORM=PERE1（悬停看全文）」
```

### ④ 隐私与体积（红线）

| 项 | 做法 |
|---|---|
| 长度上限 | **500 字符**（任务要求 ≤512，与既有入库截断一致；实测库内 `MAX(LENGTH(ref))`=282，上限不是瓶颈） |
| **敏感参数剥离（新增）** | `derive.php::statx_ref_strip_sensitive()`：**只看参数名**，剥 60 个（token/access_token/api_key/secret/key/jwt/auth/password/session/sid/csrf/signature/otp/code/email/phone/username/account/uid…）。**关键词参数一个都不剥**（唯一的交集 `key` 按凭据处理）。**两处调用**：端点（`statx_ep_ref`）+ 入库（深度防御，幂等）。**两处都没命中时逐字节原样返回** |
| 不存 UA / 不存 IP | **未改动**（UA 只进每日轮换盐的 `visitor_hash`；IP 只在端点一瞬间用于算 region，不落盘） |
| 既有数据 | 查过：现存 20,369 行中含敏感参数名的 **= 0 行** ⇒ 纯预防，**无历史债**；且**既有行一行未动** |
| 体积 | 本批**没有新增列/表**，对体积影响 ≈ 0；隐私加固的净效果是**减小**。库增长实测 ≈ **345 B/行**、**3,395 行/天** ⇒ **≈ 1.17 MB/天 ≈ 35 MB/月**（明细保留 30 天 + 按天汇总永久保留） |

### ⑤ 回归（全部通过）

- **页面**：左栏 38 项 + 下钻 `visitor` = **39 页 × 4 上下文（今日/昨日/单日/7天）= 156 次**，
  另加 2 个不在左栏的诊断页（`admin`/`sites_grid`）× 4 = 8 次，**合计 164 次**：
  `http != 200` **0** · 缺 `.stx-app` **0** · 缺 `.stx-foot` **0** · 异常 **0**。
- **导航上下文审计**（复用 `nav_sweep.py` + `nav_audit_v2.py`）：122 请求 / 117 次页面加载，
  **真丢上下文 C 类 = 0 条规则 / 0 次**，全量规则 **207**、page flag `{DAYPICK_R: 60}` —— **与 r11 基线逐项相同**。
- **零 JS 未破**：`<script>` 1 条（**Cloudflare 自己注入的 beacon**，与 r11 相同）、内联脚本块 0、内联 `on*` 0；
  164 次页面的 `script_src` 取值集合 **`[0,1,2]`**、`inline_on` 取值集合 **`[0,1]`** —— **与 r11 基线同集合**。
- **导航 38 项不动**、左栏 7 分组 38 项、`<title>` 单值、全屏 CSS（`hide` / `top0`）在位。
- **采集未受影响**：`/st.js` 200/1912 B · `/st` 200/43 B · `OPTIONS` 204 · hits 持续增长 · cron `rc=0` ·
  **未碰边缘缓存与 CF 规则**。
- **向后兼容**：旧客户端最小参数集上报 **200/43 B** 且入库；旧 spool **v1（10 列）**格式实测 `dropped=0` 并入库。

### ⑥ 与 r11 的关系 / 回滚

r12 = r11 + 上一条线未完成的「显示完整来路 + 补识别清单」（方向正确，予以保留）
+ 本批修掉的**致命 SQL**、**`MAX(ref)` 语义错**、**隐私加固**、**自称爬虫兜底规则**、**去重**、**文案澄清**。
`plugin.php` 版本号 `1.3.2.r11` → `1.3.2.r12`。
回滚：`cp -a /root/r12x/pre/<file> <live>`（本批改前原样）或解 `yecha-plugin-statx-r11-20260917a.zip` 覆盖。

---

## 1.3.2.r11 — 2026-09-17「隐藏宿主后台外壳顶部（**选项控制，默认关**）」

> 本批只改**代码/文档**，**未改任何统计数据行**；默认行为与 r10 **逐字节一致**。
> ★ 站长原话：「统计系统顶部，欢迎回来，查看站点，这些 yecha.org 后台的顶部不需要」。

### 做了什么

独立统计站的后台顶部一直留着**内核**那条外壳：`欢迎回来，站长`（其实是 `$msg` 落地提示）、
`查看站点 ↗`（本实例里指向 `/`，是 404）、站点 Logo 块、`博主：站长`，外加浏览器标题
`站长 · YeCha 后台`。这些全是内核 `admin/index.php::adm_render()` **硬编码**输出的，
内核**没有给插件任何过滤器/变量**。

新增一个选项 **`statx.ui.hide_host_chrome`**（默认 **`0` = 关**），打开后插件层做三件事：

| # | 做法 | 机制 | 零 JS |
|---|------|------|-------|
| ① | 收起内核顶部整条 `<header>` | `boot.php` 给 `<body>` 加作用域类 `stx-hidechrome`，`stxui.php::stx_css()` 用 `body.stx-hidechrome > header{display:none}` 隐藏 | ✔ |
| ② | 浏览器标题换成自己的 | 输出缓冲里 `preg_replace_callback` **只替换唯一那一个 `<title>`** | ✔ |
| ③ | 去掉「欢迎回来，…」 | 会话里**只匹配这一条前缀**的 flash 并清掉（其它提示一律保留） | ✔ |

- **改动文件（5 个，全部锚点式补丁，锚点唯一才写）**：`inc.php`（默认值）、`boot.php`（①②③）、
  `stxui.php`（CSS）、`cli.php`（写入接口）、`plugin.php`（版本号 r10 → r11）。
- **内核一个字节都没改**（红线：`admin/index.php` 未触碰）。
- 作用域：只认 `/admin/` 前缀，且只在 `s=plug_statx*` 那批页面上体现；前台/端点/CLI **零成本**。

### 怎么开 / 怎么关

**方式 A（推荐，站长要求）：在后台里点着设置** —— `后台 → 系统 → 设置`（`/admin/?s=plug_statx&p=settings`）
最上面新增一张卡片「**隐藏宿主后台的外壳顶部（欢迎回来 / 查看站点）**」，
勾选/取消勾选后点「保存」即可；**保存后当页给出提示，刷新任意本插件页面即见效**。
纯 HTML `<form>` + 复选框 + 提交按钮，**零 JavaScript**。

**方式 B（命令行，等价）**：

```bash
php plugins/statx/cli.php --task=ui                      # 看当前值
php plugins/statx/cli.php --task=ui --hide-host-chrome=1  # 独立统计站：开
php plugins/statx/cli.php --task=ui --hide-host-chrome=0  # 恢复默认（回滚一条命令）
```

两条路都走插件自己的选项接口 `statx_opt_set()`（`ye_options` 表 `statx.ui.hide_host_chrome`），**不裸 SQL**。

### ★★ 适用场景（重要，别照抄）

- **独立统计站**（整站只有统计，`/admin/` 就是统计后台）：**建议开 `1`**。
  这时内核顶部没有任何别的东西要看，`查看站点 ↗` 往往还指向 404 的站根。
- **把 statx 嵌在别人自己的博客后台里**：**保持默认 `0`**。
  那个顶部是**站长自己的**品牌/身份信息，收起来只会让人找不到出口。

### 验收（本实例实证）

- `/admin/?s=plug_statx`：`欢迎回来` = **0**、`查看站点` = **0**、`<title>` = `统计 · StatX 统计`；
  统计自己的 `.stx-topbar` / `.stx-side`（7 分组 37 页）/ `.stx-foot` **全在**。
- **反例（默认不影响别人）**：选项设回 `0` ⇒ 顶部照旧（`欢迎回来`/`查看站点`/内核 `<title>` 全部回来）。
- **零 JS 未破**：156 个页面（39 页 × 4 组上下文）`<script src>` 与内联 `on*` 数与 r10 基线**逐项相同**。
- ★ **说明：那两个元素是「被 CSS 隐藏」，不是「没输出」** —— DOM 里 `<header>` 仍在（1 处），
  只是 `display:none`。内核顶部是硬编码输出，插件层无法阻止它进入 HTML（除非改内核）。

### 必须改内核才能做到的部分（**本批不做，仅出方案**）

如果要让那 4 个元素**根本不进入 HTML**（而不是被 CSS 隐藏），只能改内核
`/www/wwwroot/tj-stat/admin/index.php`：

- `adm_render()`，**第 681 行**：`'</style></head><body><header>…</header>'` 这一整段字面量；
- 同函数**第 642 行**：`'<title>' . $name . ' · YeCha 后台</title>'`；
- **第 1421–1423 行**：`$msg` 落地提示的拼装。

影响面：`adm_render()` 是**内核后台的唯一出口**，被 `admin/index.php:1424` 一处调用，
但那一条调用覆盖**内核后台的全部页面**（dashboard / posts / pages / comments / users /
media / themes / tools / plugins **以及所有第三方插件后台页**）⇒ 改它等于改**所有站**的
后台外观，并会让"插件想保留内核顶部"的场景失去可能。**风险远大于收益**，故本批用
CSS 隐藏 + 选项开关实现，**内核保持零改动**。
## 1.3.2.r10 — 2026-09-17「修 4 条根因（都会咬到『别人装上去』的用户）」

> 本批只改**代码/脚本/文档**，**未改任何统计数据行**（跨库逐行 diff：`hits` 内容 sha 与改前完全相同）。
> 4 条根因的共同点：**在统计服务器自己的实例上全都看不出来**，但**用户下载装到自己的站上就会踩**。

### ①【根因①】自定义 shell 必须输出统计代码位 —— `svg` 插件从不输出 `stats.foot`

**现象**：任何用 `svg` 插件的站（如 atsvg.com）**永远收不到统计**；五套主题的 `layout.php` 都有
`stat_html('stats.foot')`，**唯独自定义 shell 没有**。

**根因**：内核 `core/bootstrap.php` 只**定义**了 `stat_html()`，**内核里 0 个调用点** ——
调用责任在主题 `layout.php` / 自定义 shell。`plugins/svg/lib.php::svg_shell()` 自己拼整页
`<!DOCTYPE html>`（不经过主题 layout），于是**整个统计位都丢了**。

**修法（治本，不只补那一处）**：
- `svg_shell()` 改为统一输出 **`stats.head` + `stats.foot` 两个位**（口径与内核/五套主题一致），
  并加注释说明「自定义 shell 必须自己输出」这一约定。两份副本都已修：
  `tj-stat`（主源）与 `t.atsvg.com`（live）。
- ★ **内核不改**：内核没有「通用渲染出口」，要兜底就得改内核 —— 按纪律**只出方案不动手**（见下）。
- ★ **文档化约定**（本批强制）：README 新增「自定义 shell 的硬约定」一节，明写
  「任何自定义 shell / 主题渲染的页面，必须输出 `stat_html('stats.head')` 与 `stat_html('stats.foot')`，
   否则该站**收不到统计**」。

**内核兜底方案（已评估，未实施）**：可在 `core/Front.php` 的输出前挂一个统一后置钩子，
把 `stats.head/foot` 注入到 `</head>` / `</body>` 之前。**未做**，因为：
(i) 需要改内核三个文件的渲染出口；(ii) 与「主题自己输出」会重复注入（需去重）；
(iii) 风险面覆盖全站所有页面，超出本批「最小改动 + 幂等」的范围。**留给站长决定。**

**验收（裸 URL 实证）**：`https://atsvg.com/` 源站直连与公网均 === 94206 B，
且 HTML 含 `data-site="atsvg.com"`（`svg_shell` 渲染的页面已带上统计位）。
`svg` 插件在应用市场**没有包也没有 manifest 条目 ⇒ 不是市场可下载插件**，故无「重新打包上架」动作；
其 live 副本已修（如需分发可另行打包）。

### ②【根因②】`collect-inject` 必须**同时重建 home-static 静态首页**，`hs_fetch()` 必须带正确 Host

**现象**：对启用 home-static 的站「一键写入」会**写成功 → 实证失败 → 自动回滚**
（r9 实测：清了 52,250 个页面缓存后**仍然回滚**）。回滚行为本身是对的，**是流程缺一步**。

**根因**：启用 home-static 的站在静态文件**新鲜（TTL 内）**时由 `hs_front()` **直出磁盘文件**，
**动态渲染根本不执行** ⇒ 只「写 `stats.foot` + 清页缓存」只是**写成功**，裸 URL 仍命中旧静态 HTML。

**修法**：
- `statx_collect_inject()` 新增第 ④ 步：**检测该站是否启用 home-static，是则额外重建静态首页**
  （`statx_collect_rebuild_home_static()`）。移除路径 `statx_collect_remove()` 同样补上重建
  —— 否则 TTL 内裸 URL 仍直出「还带着统计代码」的旧静态页，**移除当场不生效**。
- ★ **`hs_fetch()` 已修**（`plugins/home-static/inc.php`）：
  - 原来用 `https://127.0.0.1` + `Host:` 头抓；多站 nginx 上有 `default_server` 且**无 SNI**
    ⇒ 请求被路由到**默认站（抓错站）**，CLI 下 Host 还退化成 `localhost`。
  - 现在改 **`CURLOPT_RESOLVE` 把域名钉到 127.0.0.1** —— **SNI 与 Host 都是正确域名**，
    真正落到本站 server 块；不改 `/etc/hosts`、不影响其它进程。
  - 新增 `hs_site_host()`：① 显式传入 ② `site.url` ③ `SERVER_NAME` ④ Web 的 `HTTP_HOST`
    ⑤ 站点根目录名 ⇒ **仍取不到就返回 '' 并拒绝生成**（宁可不生成，也不抓错站）。
  - `hs_generate(string $host = '')` 接受显式 host；原有两个调用点（`hs_front`/后台）零改动兼容。
  - `hs_langs()` 声明为 public：statx 复用它**权威**的「语言→路径」映射，避免两份逻辑漂移。
- ★ 实现方式不用子进程：实测「`require` 目标站的 `config.inc.php`/`bootstrap.php`」在 CLI 下
  会**直接触发前台渲染并吐出整页 HTML**（要求子进程输出 JSON，结果收到 94206 B 的首页）。
  改为**进程内正规 HTTP 回环**：只 `require` home-static 的 `inc.php`（纯函数库、无副作用），
  用 `hs_fetch()` 抓动态 HTML 再落盘。

**★ 验收判据改成「裸 URL 三方一致」**（写进代码注释与 README）：
```
源站裸 /  ==  公网裸 /  ==  磁盘静态文件
判据：字节数 + sha256 + 是否含 data-site="<key>"  三者全等
```
只看带查询串的动态渲染会漏 —— **这正是 r9 误判的根因**。
未启用 home-static 的站本来就没有静态文件，此时只比「源站 == 公网」，静态记为**不适用**（不是失败）。

**验收（在真正启用 home-static 的站 atsvg.com 上实测）**：
- 启用状态：`home_static.enabled` 未设置 ⇒ 与 `hs_front()` 同口径视为**启用**；
  `var/home-static/{index.html,en/index.html}` 均存在。
- 备份 → `--task=collect-remove` → 重新 `--task=collect-inject`：
  - **`collect-inject: ok=yes`，退出码 0，不再回滚**；
  - 日志：`已重建 home-static 静态首页 {"zh":true,"en":true} 文件=2` + `裸 URL 三方一致 → 通过`；
  - 三方均为 **94206 B / sha256 `f978b4c2…bf82a` / 含 `data-site="atsvg.com"`**。
- 全站复扫（用**已出货的实现**校验，不另写一套判据）：
  `atsvg.com`、`yecha.org`（启用 home-static）三方一致 ✅；
  `cntales/gushuwu/laolv/senlincha` 无静态文件 ⇒ 源站 == 公网 ✅。

### ③【根因③】`collect-init` 播种会插一行 `tj-stat`（host 不带点 ⇒ 无效登记）

**根因（真凶已定位）**：`statx_collect_seed_sites()` 的「本站实例**一定可写**」兜底块里写着
```php
statx_collect_site_save($self, ['label' => $self, 'host' => $self, ...]);
```
把 **site_key 当成 host** 写进去。本站 site_key 是 `tj-stat`（**不带点**）⇒ 播种出一行无效登记；
而 `rt.php` 的 origins 白名单要求 host 含点（`~^[a-z0-9.\-]+$~` 之外还有含点约束），
该行被**静默丢弃** ⇒ 既污染站点表、又让「本站实例」在白名单里查无此人。

**修法（两条都做，更干净）**：
- 新增 `statx_collect_valid_host()`：把任意输入规整成**合法 host（必须含点）**，不合法返回 `''`。
- **播种不再写脏值**：本站实例的 `host` 只在**能推导出合法域名**时才设置（`site_key` 或 `site.url`），
  推不出来**就不设置该字段**（宁缺勿错）—— 而不是回退成 site_key。
- 新增 `statx_collect_purge_invalid_sites()`：播种时顺手**清掉历史遗留的无效登记**
  （host 为空或不含点），走插件登记表接口，**不裸 SQL**。

**验收**：
- 播种后 `sites` 表 16 行，**不带点/空的 host = 0**；
- `tj-stat` 行的 host 为 **`tj.51puer.com`**（合法域名，来自 MySQL→SQLite 迁移），**不再是 site_key**；
- 重建 `rt.php` 后 origins 白名单 **30 条，无效项 = 0**（含 `https://tj.51puer.com`）。
- 说明：本批测试期间播种新增的 `tj-stat` 行已用插件登记表接口清理并复核（该行历史上即由迁移带入）。

### ④【根因④】发布脚本固化 `var/market/` + 发布后可达性自检

**踩过两次的坑（r7、r8）**：包被写到 `/www/wwwroot/yecha.org/market/` —— **那个路径没有任何路由**。
真路由是 `location ^~ /market/ { alias …/yecha.org/var/market/; }`。
另：CF 会缓存 404（文件就位前被请求过 ⇒ `cf-cache-status: HIT` + `max-age=14400`，且**无凭据 purge**），
规避办法是**用全新文件名**。

**修法**：新增 `publish.py`（本次实际用于 r10 上架），把三件事固化：
1. **目标目录硬编码 + 强校验**：只接受 `/www/wwwroot/yecha.org/var/market`；
   一旦目标是 `/www/wwwroot/yecha.org/market`（无路由）**立即拒绝执行**并打印正确路径。
2. **强制全新文件名**：若 market 里已存在同名文件则拒绝（除非显式 `--allow-existing`），
   并在说明里写明 CF 404 缓存的原因。
3. **★ 发布后自检（硬性门槛，不通过就不算发布成功）**：
   - 裸 GET（**不带查询串**）发布 URL ⇒ 必须 **HTTP 200**；
   - **响应字节数必须等于本地文件大小**，且 **sha256 必须等于本地 sha256**；
   - 再用 **`--resolve` 直连源站**复核同样条件 ⇒ **证明不是 CF 缓存骗我们**；
   - 报告 `cf-cache-status` / `age` / `server`。

**验收（r10 实跑输出）**：
```
local_bytes=318576  local_sha256=0952700640e5de29ca514b7073da45eeed97e8a529ade9f3d8b2583a5688e0a5
check_public: code=200 bytes=318576 sha_match=true cf=MISS  server=cloudflare
check_origin: code=200 bytes=318576 sha_match=true server=nginx   (--resolve 127.0.0.1，绕 CF)
PASS: true
✅ 发布自检通过：URL 200 且【字节数 + sha256 均与本地一致】
```
独立复核（`curl --resolve yecha.org:443:127.0.0.1`）下载后与本地 `cmp` **逐字节相同**；
守门演示：把目标写成无路由目录 ⇒ **被拒绝**（提示正确路径）。

### ⑤ 打包 r10

- **包名每次换新**（避开 CF 对旧名的 404 缓存）：`yecha-plugin-statx-r10-<日期><序号>.zip`。
  r10 的候选依次为 `…20260917c` → `…20260917d` → `…20260917e`，**最终发布的是最后一个**。
- **权威元数据以 `var/market/manifest.json` 的 statx 条目为准**（`package` / `sha256` / `size` / `files_sha256`）。
  ⚠️ 本 CHANGELOG 是**包内文件**，把包自己的 sha256 写进来会构成「写 sha ⇒ 包变 ⇒ sha 变」的自指矛盾，
  所以这里不写死 hash；要核对就查 manifest + 发布自检输出（`/root/r10-publish*.out`）。
- 每次打包后都做**包内 vs live 逐文件 sha256 比对**：
  `zip_files=37 live_files=37 only_in_zip=[] only_in_live=[] sha_mismatch=[]  ⇒ ALL_EQUAL=true`。
- 插件 `plugin.php` 的 `ver` 同步为 **`1.3.2.r10`**（该字段只用于后台/市场显示，无逻辑依赖）。
- manifest **只改 statx 一项**；其余 **15 项逐项比对 `ALL_UNCHANGED=true`**。
  manifest 改动前备份为 `manifest.json.bak-r10-<ts>`（600）。
- 更早的候选包（`c`/`d`）仍留在 market 目录（它们的发布自检也都通过了），但 **manifest 只指向最终包**，
  旧候选不参与市场分发。
- ★ **`svg` 插件没有「重新打包上架」这一步**，因为它**不是市场可下载插件**：
  market 目录里既没有它的 zip 包，manifest 里也没有它的条目
  （market 里的 `tales-hue.svg`/`tales-ink.svg` 是**主题用的图片**，不是插件包）。
  它的 live 副本（atsvg）已修好；若将来要分发，需另行建包并加 manifest 条目。

### 回滚（一条命令级别）

| 改动 | 回滚 |
| --- | --- |
| ② home-static `inc.php`（`hs_fetch`/`hs_generate`/`hs_site_host`） | `cp -a /www/wwwroot/yecha.org/plugins/home-static/inc.php.bak-r10fin-<ts> /www/wwwroot/yecha.org/plugins/home-static/inc.php` |
| ②③ statx `collect.php` | `cp -a /www/wwwroot/tj-stat/plugins/statx/collect.php.bak-r10<最新步骤>-<ts> …/collect.php`（各步均留 `.bak-r10*`，按时间取最近） |
| ① `svg` live | `cp -a /www/wwwroot/t.atsvg.com/plugins/svg/lib.php.bak-r10-<ts> …/lib.php` |
| ① `svg` 主源 | `cp -a /www/wwwroot/tj-stat/plugins/svg/lib.php.bak-r10-<ts> …/lib.php` |
| ⑤ manifest | `cp -a /www/wwwroot/yecha.org/var/market/manifest.json.bak-r10-<ts> …/manifest.json` |
| ② atsvg 注入/静态 | 备份目录 `/root/r10-atsvg-backup-<ts>/`（含 `home-static/` + options 快照 + `config.inc.php`） |
| 整包回滚到 r9 | 把 manifest 里 statx 的 `package` 改回 `yecha-plugin-statx-r9-20260917b.zip`（旧包仍在 market 可下载） |

## 删站操作记录 — 2026-09-16「按站长要求删除 t.atsvg.com 站点」

> 操作记录（**非插件代码改动**）：站长要求「把 statx 里的 `t.atsvg.com` 这个站点删掉」。
> 本次**未改任何插件代码**，只按插件自己的删除路径处理数据，并清理了该站前台的废弃注入。

### ① 删除（走插件自己的「站点管理 → 删除」两步流程）
- 真登录后台 → `GET ?p=sites_mgr&del=2fba18d8de8f32f8` **只读预览**（报「将删 hits 7 / hits_rollup 2 / sites 1」，打开后回查库一行未少）
  → `POST do=statx-site-delete` + `confirm=yes` + `confirm_key` 手打 + `_csrf` ⇒ 服务端二次确认通过。
- 插件自动 `VACUUM INTO` 删前备份：`var/statx/backup/statx-before-site-del-2fba18d8de8f32f8-20260916-014352.db`（integrity ok、内含该站 7 行）。
- 结果：`sites 19→18`、`enabled 14→13`、`hits 11298→11292`、`hits_rollup 29→27`、**目标 key 在 sites/hits/rollup 三处 0/0/0**、`integrity_check=ok`。
- ★ 其它 **18 个站逐站全量比对：减少的站数 = 0**（唯一 +1 是 liulanqi 的正常采集增长）。

### ② 页面缓存清理（`t.atsvg.com/var/cache/pages`）
- 只删 `*.cache` + `.gc_stat`（**与内核 `Cache::flush('pages')` 同口径**）：**57,177 → 8 个文件、1.8G → 5.4M**；`blocks/misc/throttle` 与站点代码配置**未动**。

### ③ ★ 发现并（经授权）修复了老像素的真正源头
- 清缓存后重建的页面**仍带**废弃 key ⇒ 源头**不在文件里**，而在 **atsvg 站点库 `t_atsvg`.`ye_options` 的 `stats.foot`**（内核 `Plugins.php` 的「统计代码位」页脚注入，179 B）。
- 该 key 已从 statx 删除 ⇒ 这些页面持续打点会被 `collect.php` **静默丢弃**（`dropped_site`，不报错也不落表），所以必须连源头一起清。
- 做法（**先备份、最小改动、可精确回滚**）：原值备份到 `/root/sxdel/yeoptions-stats-foot-ORIGINAL-<ts>.json`（600）后，`UPDATE ye_options SET value='' WHERE name='stats.foot'`（**影响 1 行**，`ye_options` 仍 35 行；同族 `stats.head` 本来就是空串），随后再清一次页面缓存。
- 回滚 = 一条命令 `php /root/sxdel/ROLLBACK-stats-foot.php`（已 dry-run 实测，逐字节恢复原值）。

### ④ 验收
- 公开 4 个 URL 全部 **200 且该 key = 0**：`/`(94,019 B，与改前逐字节相同)、`/en/`、`/hot`、`/search`；缓存自然重建后（1,505 文件）**含 key 仍为 0**。
- **观测 10 分钟**：`hits_total 11324→11344(+20)` 而**该 key 全程 0 行、spool 0** ⇒ **已停止收数**。
- 全盘兜底：`.106` 除 `CHANGELOG.md` 的历史文字外**再无该 key 的运行时来源**。


## 1.3.2.r9 — 2026-09-17「atsvg.com 接入统计 + 站点卡片跟随当前档」

> 起因两件：① 站长要求给 **atsvg.com**（真站，webroot `/www/wwwroot/t.atsvg.com`，库 `t_atsvg`）
> 配上统计 —— 它此前**一行都没有**，且前台曾是废弃老像素 token `2fba18d8de8f32f8` 的来源（已在
> 2026-09-16 的删站批次里清空 `stats.foot`，于是彻底没有统计）。② r8 审计复跑后**还剩最后 2 条
> 规则 / 4 次**：仪表盘站点卡片的「进入仪表盘」把档位**硬编码成 7d**，点进去会把用户当前的档位改掉。
> 本次**未改任何统计明细行**（下见"数据不动"实证）、**未改采集管线**、**38 项导航与 38 页结构未动**。

### ① atsvg.com 接入统计（新 key = `atsvg.com`，不是老式 hex token）
- **site_key 口径**：直接用 **`atsvg.com`**。理由：它**本来就已经登记在 `sites` 表**（label `ATSvg`、
  `host=atsvg.com`、`enabled=1`）—— 那是"本站式"域名 key 的登记行，不是老式 16 位 hex token；
  老 token（`2fba18d8de8f32f8`）已随 `t.atsvg.com` 站点一起删除，**这次不生成任何新 token**。
- **注入**：写内核「统计代码位」`ye_options['stats.foot']`，文本由插件自己的 `statx_collect_tag()`
  生成（`collect.inject_mode='pub'`），与全站其它站**字节级同构**：
  `<script async src="https://tj.51puer.com/st.js?v=4e088c0b" data-site="atsvg.com" data-ep="https://tj.51puer.com/st"></script>`
  其中 `?v=` 沿用插件既有口径 = `substr(md5_file(st.js),0,8)` = **`4e088c0b`**（与 cntales/laosv 等现有值一致）。
  原值备份：`/root/atsvg-stats-foot.BACKUP-20260917-022720.txt`（600，含 foot/head 的 len/hex/autoload）。
- **★ 摸到两个真根因（都不是"贴一行就完事"）：**
  1. **`svg_shell()` 从来没输出过 `stats.foot`。** 该站首页由**插件 `svg` 的 `svg_shell()`** 渲染，
     而 `themes/{svgicon,yecha,octern,forest,teadoc}/views/layout.php` 五套主题布局**都有**
     `stat_html('stats.foot')` 这一行，**唯独 `svg_shell()` 没有** ⇒ 写进 option 也永远不会上页面。
     补丁：在 `plugins/svg/lib.php` 的 `svg_shell()` 尾部 `</body>` 前补上与各主题**逐字同款**的那一行
     （锚点唯一性已断言；备份 `plugins/svg/lib.php.bak-statx-atsvg-<ts>`，sha256 见报告）。
  2. **首页是 home-static 静态直出。** 插件 `home-static` 把首页渲染成
     `var/home-static/index.html`（TTL 600s）并**直接 echo 文件**，绕过模板 ⇒ 只清
     `Cache::flush('pages')` 对首页**无效**（这也是插件自带 `--task=collect-inject` 在该站会
     "写成功但实证失败并自动回滚"的原因）。做法：写 option + 清 pages 缓存 + **用插件自己的
     `hs_generate()` 口径重建静态首页**（实测 `hs_fetch()` 的 loopback 因 nginx 有
     `default_server`（xuecha.org）且无 SNI 而打到错站，故重建走 `--resolve atsvg.com:443:127.0.0.1` 口径抓动态渲染后原子替换）。
- **CORS 白名单**：`st.js` 是**跨域** `sendBeacon` 到 `tj.51puer.com`，端点按 `rt.php` 的 `origins`
  回显 `Access-Control-Allow-Origin`。原白名单只有 `t.atsvg.com`（老域名），**没有 `atsvg.com`**
  ⇒ 重生成 `rt.php`（origins 28 条，含 `https://atsvg.com` / `https://www.atsvg.com`）。
- **★ 真浏览器打点实证**：headless Chromium（`--disable-blink-features=AutomationControlled`，
  `navigator.webdriver=false`）打开 `https://atsvg.com/` ⇒ 60 秒后 spool 落 1 行、采集器入库：
  `id=17142 · ts=2026-09-17 10:38:05 · site_key=atsvg.com · host=atsvg.com · path=/ · kind=pv ·
  is_landing=1 · vw/vh=1440x900 · lang=en-US · device=bot · bot_name=headlesschrome · browser=Chrome · os=Linux`
  （`device=bot` 是**正确行为**：无头浏览器本来就该被识破并标成爬虫；该行仍然证明"脚本执行了、端点收到了、库里有站了"）。
- **首页未破坏**：标题仍是「ATSvg 矢量素材库」；首页 94,019 B → 94,206 B（+187 B = 统计单元 166 B + 换行等），
  其它结构未变；`/hot`、`/en/` 均 200 且同样带上新代码。

### ② 仪表盘站点卡片「进入仪表盘」改为跟随当前档
- 改动（`pages3.php` 两处，字节级最小）：把 `statx_ui_url($ctx, ['p'=>'overview','site'=>$sk,'r'=>'7d'])`
  里的 **`'r' => '7d'` 去掉**，其余一字不动（文件 21,178 → 21,152 B，正好 -2×13 B）。
  `statx_ui_url()` 的语义是"上下文继承、显式覆盖优先"，去掉覆盖后它自动继承当前页的档位（单日自动带 `d`）。
- **「系统页没有当前档」怎么定**：**不写兜底覆盖**。因为 `statx_ctx()` 里
  `if ($r === '') { $r = statx_default_range(); }` —— **任何请求都会落到一个明确的档**（默认 `today`），
  并不存在真空；审计器对系统页（`p=check`/`p=selfcheck`/`p=adminops`）也是**带 `r` 请求**的。
  实测裸开 `?p=dash`（URL 里没有 `r`/`site`）时，卡片链接给到 `…&r=today&site=atsvg.com`，
  进去后档位高亮 = **今日** —— 与"进入仪表盘后的默认档 = 今日（站长指定）"完全一致，不自相矛盾。
- **审计复跑**（复用 r8 的 `nav_sweep.py` + `nav_audit_v2.py`）：122 次请求、9,264 条 `<a>`、
  117 次页面加载、**http!=200 = 0 / 缺 `.stx-app` = 0 / 缺 `.stx-foot` = 0**，
  **C 类「真丢上下文」= 0 条规则 / 0 次**（r8 时此处即为最后剩的 2 条 / 4 次）。
  另做 A/B（把 `pages3.php` 临时还原成 r8 前状态再跑一轮）：全量规则 207 = 207、页级 flag 60 = 60，两者一致
  ⇒ 本次改动**没有引入任何新 flag**。（`DAYPICK_R: 60` 是审计器对"日期选择器恒带 `r=day`"这一**有意**设计的
  既有提示，与档位继承无关，r8 前就已存在。）
- **点着走一遍**：`?p=overview&r=yesterday&site=ec3c884f5dd0819e` 上点 atsvg.com 卡片 ⇒
  `…&p=overview&r=yesterday&site=atsvg.com`，H1=ATSvg、档位高亮=**昨日**；`r=7d` 上下文 ⇒ 目标仍是 `r=7d`／高亮 7 天；
  `r=day&d=2026-09-16` ⇒ 目标 `r=day&d=2026-09-16&site=atsvg.com`（单日与站点都带上了）。

### ③ 回归 + 打包
- **39 页 × 4 组上下文（昨日 / 单日 / 近 7 天 / 全站）= 39/39 HTTP 200、`.stx-app` 39/39、`.stx-foot` 39/39**。
- **零 JS 未破**：`<script src>` = **1**、`<script` 标签 = **1**、内联 `on*` = **30**，与 r8 基线**逐项相同**
  （`含 <script> 的页: ['check']`）。**38 项导航清单逐项 diff：一字未改、顺序一致**。**短名生效**（侧栏无带括号长名）。
- **全屏**：浏览器实测 1920×1080 ⇒ `.stx-side` = **212**、`.stx-main` = **1649**、右栏 right=1883、
  `scrollWidth 1905 <= innerWidth 1920`（**无横向溢出**），三页（overview/dash/users）一致。
- **采集未受影响**：`hits` 持续增长（16,468 → 16,509+），`spool` 正常轮转。
- **数据不动（实证，不是口头保证）**：把当前 `hits` 与 2026-09-16 的删前备份做**跨库逐行比对** ——
  两边都有但**内容不同**的行 = **0**；备份里缺的行**全部**属于其它批次已删的 `2fba18d8de8f32f8`（7 行）；
  当前比备份**多**的行里，`atsvg.com` 恰好 **1 行**（就是上面那次真浏览器打点），其余全是各站正常采集增长。
- **r9 包**：从 live 目录平铺打包；**包内每一个文件与 live 目录逐文件 sha256 全等**；
  manifest **只改 statx 一项**，其余 15 项逐项比对未变（见报告 sha256）。

### 仍存在的问题（未在本批处理）
1. **`DAYPICK_R: 60`**（审计器页级 flag）：日期选择器恒带 `r=day`，在 `r=yesterday/7d` 页面上与当前档不一致。
   这是 r8 的**有意设计**（它是"选具体某一天"的控件），r8 前就已存在，本批未动；审计器把它当提示不算失败。
2. **`svg_shell()` 只在本站补了 `stats.foot` 输出点**：内核/插件层面尚未把这行做成模板约定
   （其它用 `svg` 插件的站点若也配 `stats.foot`，同样不会输出）。建议后续把该输出点并入 svg 插件正式版本。
3. **`statx_collect_inject()` 不会重建 home-static 静态首页** ⇒ 对启用了 `home-static` 的站点，
   "一键写入"会**写成功但实证失败并自动回滚**（本批靠手工补第 3 步绕过）。建议在插件里把
   `hs_generate()` 纳入注入流程。
4. `collect-init` 的 `statx_collect_seed_sites()` 会新插一行 `tj-stat`（host 不带点，进 `origins` 会产出
   `https://tj-stat` 这种无效项）。本批已把该行按"零统计、本次新加"回退干净（sites 15 行，与改动前逐行一致）。
5. **上架路径再一次踩坑（r7 记过、r8 又踩）**：真正对外可下载的目录是
   `yecha.org/var/market/`（vhost `location ^~ /market/ { alias …/var/market/; }`），
   而 r8 收尾把包写到了 `yecha.org/market/`（**无任何路由**）。本批已把 r9 放到正确目录。
6. **Cloudflare 会缓存 404 且 TTL 很长**：`/market/yecha-plugin-statx-r9.zip` 在文件就位**之前**
   被请求过 ⇒ CF 缓存了一条 404（`cf-cache-status: HIT`、`max-age=14400`；源站 `max-age=300` 被 CF 覆盖），
   本机无 CF API 凭据无法 purge ⇒ 定稿改用全新文件名 `yecha-plugin-statx-r9-20260917.zip`（CF 无历史），
   **裸 GET 源站与公网均 200 且 sha256 一致**。**建议**：发布流程统一"先放文件、再改 manifest"，
   且包名带日期，避免再撞 CF 的 404 缓存。
7. **陈旧页面缓存会盖住正确的静态首页**（本批收尾抓到）：`stats.foot` 写入**之前**渲染的 `/` 页缓存留在
   `var/cache/pages/` 里，响应头 `x-cache: HIT` ⇒ 即使 `var/home-static/index.html` 已是对的，
   公网拿到的仍是不含代码的旧版。**处置**（已做）：再清一次页面缓存（210 个）后
   **源站裸 `/` = 公网裸 `/` = 磁盘静态文件**，三者同为 94,206 B / sha256 `f978b4c2dba1…` / `data-site=1`。
   ⇒ **教训**：给启用 `home-static` 的站做注入，收尾必须"清页面缓存 + 重建静态文件"**两件都做**，且以
   **裸 URL 的三方一致**为验收判据（只看带查询串的动态渲染会漏掉这条）。

## 1.3.2.r8 — 2026-09-15「导航上下文修复（下钻/内链不再丢 站点·档位·日期）」

> 起因：站长报告 —— 在 **用户列表（档=昨日 · 站=liulanqi/浏览器大全）** 里点【轨迹 →】，
> 跳到了 `/admin/?s=plug_statx&p=visitor`，**`site=` 与 `r=` 全丢**，很可能连 `uid=` 也没带上
> ⇒ 轨迹页只能用自己的默认值渲染（看到的不是刚点的那个站/那一档/那个访客）。
> 本次**只改页面链接参数与 URL 构造**：未改任何数据行、未改采集、未动 38 页导航与既有页结构。

### ① 先做「逐页逐链接审计」（比只修一处更重要）
- 审计器（真实登录 + 真浏览器 UA + 间隔 0.75s，走 `--resolve` 到本机 nginx + 真 Host）把
  **38 页 × 3 档（昨日 / 单日 / 近 7 天）**渲染出的**每一条 `<a href>`** 抽出来，
  按硬判据逐条判断「点进去之后 站点 / 档位 / 单日日期 / 访客标识 是否还在」：
  站内链接 8,854 条、扫描 `<a>` 9,214 个、页面加载 117 次（http 非 200 = 0）。
- **改前判定：真丢上下文 278 条规则 / 425 次**（下钻参数 uid 丢了 **1,295 次**），代表：
  | 来源页 | 目标页 | 丢了什么 | 次数 |
  |---|---|---|---|
  | `users` / `day` | `visitor` | `r` + `site` + `uid` | 各 368（+单日 149） |
  | `events` | `visitor` | `r` + `site` + `uid` | 100 |
  | `live` | `visitor` | `r` + `site` + `uid` | 72 |
  | `sites_mgr` | `sites_mgr` / `collect` / `check` | `r` + `site` | 62 + 2 |
  | `insights` | 9 个下钻入口 | `r` + `site` | 18 |
  | 仪表盘 / 任一系统页 → 站点卡片 | `overview` | 当前档被硬编码成 `7d` | 4 |
- 页面级：**所有 38 页的日期选择器 form 都没带 `r`**（90 次），单日页还缺 `d`（30 次）。

### ② 改了什么（6 个文件，全部锚点式 patch；改前备份在 `/root/statx-nav-r8-backup-<ts>/`）
- **`stxui.php` · `statx_ui_url()`（一处收口）**：URL 构造统一**继承当前上下文** ——
  有 `r` 带 `r`；单日（`r=day`）带 `d=$ctx['single']`；有 `site` 带 `site`；
  `$over` 里显式给的值仍然优先。这样**所有**用 `statx_ui_url()` 造出来的链接（含左栏菜单、
  时间胶囊、分页器、CSV、表单 action）一次性都不再丢上下文；`csv` 链接还会把页面级下钻参数
  （`uid/url/h/…`）剔掉，避免导出与页面口径分叉。
- **`ui.php` · `stx_lk($text, $q, $ctx = [])`**：下钻胶囊新增第 3 个参数 `$ctx`
  （不传时行为与从前逐字节相同，向后兼容）；6 处「轨迹 →」与「分析结论下钻」调用点全部传 `$ctx`。
- **`ui.php` · 日期选择器**：form 里补 `<input type="hidden" name="r" value="day">`
  与（有选中日期时的）`<input type="hidden" name="d">` —— 单日上下文不再靠"有 `d` 才补 `r`"兜底。
- **`ui.php` · 站点切换条**：在系统页（仪表盘等）上点某个站胶囊时，**不再把当前档位硬编码成 7d**，
  改为沿用当前 `r`（单日时已带 `d`）；站点卡片的"进入仪表盘"仍显式 7d（那是卡片自己的口径，未动）。
- **`pages4.php` · 访客轨迹页**：顶部新增 **「← 返回用户列表」**（带当前 `site` + `r`/`d`；
  无 `uid` 时的那句提示也换成同一个带上下文的返回入口）。
- **`adminops.php` · `statx_ao_url()`**：站点管理/账号页的内链（刷新本页 / 回到列表 / 取消 /
  修改 / 删除…）把当前 `r`/`d`/`site` 一并带回；footer 两条硬编码内链改用上下文感知的 URL 构造。
- **时间档与日期二选一的口径（写清并已实现）**：`d=` **只在** `r=day` 时存在与生效；
  点任何时间胶囊（今日/昨日/7/30/90）时 URL 里**不会出现 `d`**（URL 构造里 `r!=='day'` 就 unset `d`）；
  有 `d=` 且没给 `r=` 时按"那一天"解释（`statx_ctx()` 的既有行为，未改）。
- 修完立刻实测抓到两个自己的问题并当场修掉：① 第一版只读 `$ctx['d']`（该键根本不存在，
  单日日期在 `$ctx['single']`）⇒ 轨迹 URL 仍缺 `d`；② footer 补丁里把变量名写成 `$aoCtx`（未定义）
  ⇒ `sites_mgr` 整页抛异常、页脚消失。两处都已修并复测。

### ③ 逐条验收（都是"点着走一遍"，不是看代码）
- **站长的原场景**：`?p=users&r=yesterday&site=ec3c884f5dd0819e` 第一行的「轨迹 →」href
  = `/admin/?s=plug_statx&p=visitor&r=yesterday&site=ec3c884f5dd0819e&uid=b2675a3eda7f59c9`
  ⇒ 点进去：HTTP 200、H1 `访客轨迹 · 浏览器大全`、侧栏站名 `浏览器大全`、高亮档位 `昨日`。
- **数字与裸 SQL 对账（访客当日）**：页面「访问次数(PV)=1 / 访问页面数=1 / 会话数=1」
  ＝ 裸 SQL `kind='pv'` 1 行 / `COUNT(DISTINCT path)` 1 / `COUNT(DISTINCT sid)` 1；
  轨迹卡片「1 条记录」＝ 裸 SQL 明细 1 行。
- **返回入口**：轨迹页「← 返回用户列表」→ `?p=users&r=yesterday&site=…&uid=…` ⇒ 回到用户列表，
  站/档未变。
- **单日**：`?p=users&r=day&d=2026-09-15&site=…` 的「轨迹 →」= `?p=visitor&r=day&d=2026-09-15&site=…&uid=…`
  ⇒ 落在该访客当天轨迹页；日期选择器 hidden 实测 = `{s,p=users,r=day,d=2026-09-15,site=…}`。
- **左栏菜单**：源页 38 条菜单里 28 条单站菜单**全部**带 `site` 与 `r`（丢站/丢档 = 0）。
- **审计复跑**：真丢上下文 **278 条规则/425 次 → 2 条规则/4 次**（仅剩仪表盘站点卡片"进入仪表盘"
  故意用 7 天），**下钻 uid 丢失 1,295 次 → 0 次**，日期选择器 flag 90+30 → 0。
- **回归**：39 页（38 导航页 + visitor 下钻页）× 4 组上下文（昨日/单日/近 7 天/全站）
  **全部 200 且 `.stx-app`/`.stx-foot` 齐全（39/39 × 4）**；零 JS 未破（`<script src>`：check 页 1 处为
  **既有**的 `<td>` 文本示例 `<script src="https://tj.51puer.com/st.js">`，非本次引入；内联 `on*` =
  30 个单站页各 1 处 `onchange="this.form.submit()"`，与老版一致；系统页 0）；
  全屏布局（`.wrap{max-width:100%}` / `--stx-side:212px` / `minmax(0,1fr)`，1920 下左 212 / 右 1649）
  与站点短名（`stx_site_short_name()`，4 处调用）均未被本批触碰；38 页导航表一字未改。
- **"全部站点（汇总）"胶囊**在单日页上会**保留 `d`**（= "那一天的全部站点汇总"），与其余单站链接口径一致，
  不是丢失（`r=day` 与 `d` 共存合法）。

### 回滚（一条命令级）
```bash
BK=$(cat /root/.statx-nav-r8-backup-dir)
cp -a $BK/ui.php $BK/stxui.php $BK/pages2.php $BK/pages3.php $BK/pages4.php $BK/adminops.php \
      /www/wwwroot/tj-stat/plugins/statx/ && php -l /www/wwwroot/tj-stat/plugins/statx/ui.php
```
（只回滚本批的 6 个文件；备份里另有 `before.sha256` / `after.sha256` / dry+apply 记录可逐字节比对。）

## 1.3.2.r7 — 2026-09-15「09-14 数据核查 + 站点短名 + 默认档今日 + 页面全屏」

> 起因：站长四件事 **①「昨天的数据不对」②「站点名称过长，参考老站」③「默认时间档改回今天」④「页面全屏：左栏固定宽度、右栏占剩下的 99%」**。
> ① 只查不改（**没有动任何数据行**）；②③④ 是插件层改动。

### ① 「昨天（2026-09-14）」核查结论：**我们的数字是对的，不存在少收/时间空档；老 tj 库那天本来就是空的**

三方对照（A 新 statx 库 / B 老 tj 库 / C nginx 访问日志，全部按**北京时**口径）：

| | 09-14（北京时） |
|---|---|
| A 新库 `hits`（`d='2026-09-14'`）| **3,194 行**（pv 2,670 / ev 253 / cl 271）|
| B 老 tj 库 `/opt/tj/tj.db` | **0 行** —— `ts` 最大 **2026-09-13 15:38:02 UTC** = 北京 **09-13 23:38**，早于 09-14 00:00 共 22 分钟 |
| C nginx `.106 /www/wwwlogs/tj.51puer.com.log.1` | **2,880 条 `k=pv` 打点**（另 39 条无 `k=` 的像素式打点）|

- **不是时间空档**：老 tj 的 21 条尾部记录停在 09-13 23:38（北京），新库 09-14 00:00:30 就有第一条 —— 切换发生在 **09-13 夜里**，09-14 全天新库都在收，**没有任何一小时两边都不收**。
- **不是少收**：C 2,880 vs A 2,670（pv 口径）差 **-7.3%**，其中 liulanqi 一家占 231；逐站看除 liulanqi 外几乎精确（xuecha.org 132 vs 133、houyue 36 vs 36、laulv 8 vs 12、thewebp 7 vs 7…）。
- **"差很多"的真实来源是口径**：09-14 打到 `liulanqi.com` 的 **2,390/2,666 = 89.6% 是 Sogou/宜搜蜘蛛**（UA 证据），入库时被 `is_bot=1` 正确标记 ⇒ 界面「今日 PV」只算人类 = **274**，与裸行数 2,435 差 9 倍。**这是设计如此，不是故障。**
- **能否补**：不需要。老 tj 在 09-14 一行都没有，**没有可导数据**；09-13 及更早的老库数据与新库**口径不同**（老库存明文 IP、UA 拼人；新库客户端 uid、不存 IP），不建议混导（方案见报告，未动手）。

### ② 站点名过长 → 短名（参照老站 tj 的短名习惯）

- `ui.php` 新增 `stx_site_short_name($label, $fallback)`：**从第一个左括号（`（` 或 `(`）截断**，只留短名；空则退回原值。
  - ★ 初稿还按 ` · ` / ` / ` 截断，实测把 `老像素站（Node/Express，…）` 里的 `Node/Express` 当成分隔符，9 个站全截成同一个"老像素站" ⇒ **已撤掉该规则**（只按明确的括号切，不猜）。
  - 只改**显示位**：站点切换条胶囊、侧栏站点块、topbar H1、系统页"当前站点"提示。
  - **完整 label 仍保留在 `title`（悬停可见）**，格式：`短名 · 完整label · site_key · 区间 N 条记录`。
- 数据侧：19 个站的 `sites.label` 改成"人话短名"，**走插件自己的写入口 `statx_collect_site_save()`**（`UPDATE sites SET label=?`，只动 label 一列），**没有写任何裸 SQL、没动 hits/hits_rollup 一行**；改前 `VACUUM INTO` 全库备份 + `sites-label-before.tsv` 逐行留底。
- 效果实测（1920×1080，`?p=dash`）：**20 个胶囊 = 1 行**（改动前是 2 行；老 7 字带路径名时更长）。

### ③ 默认时间档改回「今日」

- `pages.php::statx_default_range()` 由 `'7d'` 改回 `'today'`（**仅此一处**，回滚就是改回 `'7d'`）。
- 上一版默认 7d 的理由是"刚过零点打开几乎每站都是 0，看着像统计坏了" —— 但那个 **"全是 0" 的真因是仪表盘缓存 bug**（已在 1.3.2 r6 修掉：缓存按 `$withStats` 分两份）。
  真因既已修掉，「今日」档本身没问题 ⇒ 按站长指令改回。
- 配套：`r=today` 且今日无数据时的提示文案原来写着"后台默认档已经是近 7 天"（现在不成立）⇒ 改为"本页默认档是「今日」，正是站长指定；今日为 0 时点上面那个链接即可"。

### ④ 页面全屏：左栏固定 212px、右栏占剩下的全部

- **真因**：内核 `admin/index.php:647` 给 `.wrap` 写死 `max-width:1240px`，**而且 `.wrap` 是 `.stx-app` 的父元素**（`<div class="wrap"><main><div class="stx-app">`）⇒ 只把内层 `.stx-app` 设 `width:100%` 毫无作用。改 `.wrap{max-width:100%;width:100%;margin:0;padding:0 22px;box-sizing:border-box}`。
  - 作用域取舍已写进 CSS 注释：`:has()` 方案老浏览器失效、提到 `.wrap` 外要动内核渲染顺序 ⇒ 只能覆盖 `.wrap`；本实例是**独立统计站**，`YC_ADMIN_FOCUS` 只由 `statx/boot.php` 对 `s=plug_statx*` 设置，裸 `/admin/` 已 302 到插件页。
- 两个**实测才发现的**细节（不改就做不到"左栏固定 212 / 右栏占满"）：
  1. `.stx-side{width:212px;padding:14px 10px}` 在无全局 `border-box` 的页面里实际占 **234px** > 网格轨道 212px ⇒ 左栏溢出 12px 并把右栏挤窄。加 `box-sizing:border-box` 后精确 212px。
  2. 右栏轨道写 `minmax(0, calc(100% - 212px - 2*pad))` 时，**Chrome 把其中的 `100%` 解析得比网格容器还小 44px**（实测 1920 下算出 1605 而非 1649）⇒ 右边凭空空 44px。改成 `minmax(0,1fr)` 后正确。
- 超宽屏可读性：`.stx-muted/.stx-empty/.stx-note/.stx-block{max-width:78ch}`、`.stx-fld>input/select{max-width:760px}`（**放在样式表最后**，否则被同优先级的后置规则盖掉）。表格不设上限，宽表沿用既有 `.stx-scroll{overflow-x:auto}`。
- 实测（Chromium 152 无头 + CDP，真登录）：1920 下 `.wrap`=1905、`.stx-app`=1861、左栏=**212**、右栏=**1649**（右边缘 1883 与容器右边缘重合）；1440/1280 同构；**960/390 折叠为单列且无横向溢出**。

### 顺手修（两处早就记在册的"已知遗留"）

- **提示条里的字面 `**`**：`$ctx['notice']` 文案一直按 Markdown 写 `**加粗**`，模板从不解析 ⇒ 页面上显示成一对星号（1.3.2 r6 的"仍存在的问题"里记过）。新增 `pages.php::statx_notice_md()`，在 `statx_ctx()` 收口，只处理**成对**的 `**…**`（不跨行、不跨越既有标签）。
- 同上，站点切换条 20 个胶囊一行放不下（r6 也记过）—— ② 的短名 + ④ 的全屏一起解决了。

### 验收

- **38/38 页**（带 `s=plug_statx&p=...` 的侧栏全部菜单项）登录态实测：HTTP **200** + `.stx-app` + `.stx-foot` 全在。
- 浏览器（CDP）逐页 1440×900：**38/38** 渲染出 `.stx-app` 且 **无横向溢出**。
- 默认档：不带 `r=` 打开 → 当前档 **`*今日`**；`?r=7d` → **`*7 天`**（URL 可分享）；`site=teayear.com` 今日 0 → 出现"今日暂无数据…切到近 7 天"提示。
- **零 JS 未破**：本版只改 CSS/HTML/PHP，未引入任何 `<script src>` 或内联 `on*`。

### 回滚（hunk 级，不整文件覆盖）

```bash
# 三个 PHP 文件：从 r7 备份目录取回"改前"原件（内容级恢复，非整包回滚）
BK=$(cat /root/sx-r7-backup-dir.txt)
cd /www/wwwroot/tj-stat/plugins/statx
cp -a "$BK/stxui.php.before" stxui.php
cp -a "$BK/pages.php.before"  pages.php
cp -a "$BK/ui.php.before"     ui.php
php -l stxui.php && php -l pages.php && php -l ui.php
# ④ 只想回滚布局：删掉 stxui.php 里那一行  .wrap{max-width:100%;...}
# ③ 只想回滚默认档：pages.php::statx_default_range() 的 return 'today'; → return '7d';
# ② 只想回滚短名显示：把 ui.php 里 stx_site_short_name(...) 的三处调用还原为原 label 变量
# ②’ 数据侧 label 回滚（走同一插件写入口，不写裸 SQL）：
#   php /root/labels-revert.php        # 依据 $BK/sites-label-before.tsv
```

### 仍未做 / 仍存在的问题

- **09-14 liulanqi 的 231 条差异未逐条归因**（C 2,666 vs A 2,435）。已排除：站点未启用、`v2/v3` 列数不对、`maxRows` 上限（200,000）三条丢弃规则；spool 当前 1 文件 6 行属正常在途。**不影响"我们的数字是对的"这个结论**（2,670 与 2,628~2,880 同量级），但要 100% 归因需复现当时的 spool，产物已不在。
- **老 tj 09-13 及更早的数据没有导入新库**（口径不同：老库存明文 IP）；若要，方案见报告，**本次未动手**。
- 站点切换条 20 个胶囊在 **1440 及以下仍是 2 行**（老站也是胶囊条，未做折叠/下拉 —— 那需要一次专门的界面决策，且插件坚持零 JS）。
- 两个站重名：`2fba18d8de8f32f8`(t.atsvg.com) 与 `atsvg.com` 都叫「ATSvg」；`a4a52d81838e7286`(herontool.com) 与 `herontool.com` 都叫「苍鹭工具」——同站不同入口，语义正确，但列表里看着像重复。

### 打包（r7）

- 版本号：`plugin.php` 的 `'ver' => '1.3.2.r7'`（`version_compare('1.3.2.r7','1.0.0-beta.2','>=')` 实测 **true**，内核 `requires.core` 校验不受影响）。
- 包：`/root/yecha-plugin-statx-r7.zip`（flat 布局，与 r6 同构；从**live 插件目录**打，排除 `var/`、`*.wal`、`*.shm`）
  - **sha256 = `87052f18c75fbf9148bd09ecfdaa2667050e4a86500d832be52d9b4de8d21766`**，size = 301475
- 发布：`/www/wwwroot/yecha.org/market/yecha-plugin-statx-r7.zip`（与源包 sha256 相同）
- 市场 manifest：`/www/wwwroot/yecha.org/var/market/manifest.json` —— **只改 statx 一项**（CAS 读-改-写，改前备份 `manifest.json.bak-r7-20260916-012432`），`package` 从 `…-r6.zip` 指向 `…-r7.zip`，`sha256`/`size`/`files_sha256`/`desc` 同步。
- 交叉校验：从**已发布 zip** 解出的 `ui.php/pages.php/stxui.php/plugin.php` 与 live 树 sha256 **完全一致**。

## 1.3.2.r7 — 2026-09-15「09-14 数据核查 + 站点短名 + 默认档今日 + 页面全屏」

> 起因：站长四件事 **①「昨天的数据不对」②「站点名称过长，参考老站」③「默认时间档改回今天」④「页面全屏：左栏固定宽度、右栏占剩下的 99%」**。
> ① 只查不改（**没有动任何数据行**）；②③④ 是插件层改动。

### ① 「昨天（2026-09-14）」核查结论：**我们的数字是对的，不存在少收/时间空档；老 tj 库那天本来就是空的**

三方对照（A 新 statx 库 / B 老 tj 库 / C nginx 访问日志，全部按**北京时**口径）：

| | 09-14（北京时） |
|---|---|
| A 新库 `hits`（`d='2026-09-14'`）| **3,194 行**（pv 2,670 / ev 253 / cl 271）|
| B 老 tj 库 `/opt/tj/tj.db` | **0 行** —— `ts` 最大 **2026-09-13 15:38:02 UTC** = 北京 **09-13 23:38**，早于 09-14 00:00 共 22 分钟 |
| C nginx `.106 /www/wwwlogs/tj.51puer.com.log.1` | **2,880 条 `k=pv` 打点**（另 39 条无 `k=` 的像素式打点）|

- **不是时间空档**：老 tj 的 21 条尾部记录停在 09-13 23:38（北京），新库 09-14 00:00:30 就有第一条 —— 切换发生在 **09-13 夜里**，09-14 全天新库都在收，**没有任何一小时两边都不收**。
- **不是少收**：C 2,880 vs A 2,670（pv 口径）差 **-7.3%**，其中 liulanqi 一家占 231；逐站看除 liulanqi 外几乎精确（xuecha.org 132 vs 133、houyue 36 vs 36、laulv 8 vs 12、thewebp 7 vs 7…）。
- **"差很多"的真实来源是口径**：09-14 打到 `liulanqi.com` 的 **2,390/2,666 = 89.6% 是 Sogou/宜搜蜘蛛**（UA 证据），入库时被 `is_bot=1` 正确标记 ⇒ 界面「今日 PV」只算人类 = **274**，与裸行数 2,435 差 9 倍。**这是设计如此，不是故障。**
- **能否补**：不需要。老 tj 在 09-14 一行都没有，**没有可导数据**；09-13 及更早的老库数据与新库**口径不同**（老库存明文 IP、UA 拼人；新库客户端 uid、不存 IP），不建议混导（方案见报告，未动手）。

### ② 站点名过长 → 短名（参照老站 tj 的短名习惯）

- `ui.php` 新增 `stx_site_short_name($label, $fallback)`：**从第一个左括号（`（` 或 `(`）截断**，只留短名；空则退回原值。
  - ★ 初稿还按 ` · ` / ` / ` 截断，实测把 `老像素站（Node/Express，…）` 里的 `Node/Express` 当成分隔符，9 个站全截成同一个"老像素站" ⇒ **已撤掉该规则**（只按明确的括号切，不猜）。
  - 只改**显示位**：站点切换条胶囊、侧栏站点块、topbar H1、系统页"当前站点"提示。
  - **完整 label 仍保留在 `title`（悬停可见）**，格式：`短名 · 完整label · site_key · 区间 N 条记录`。
- 数据侧：19 个站的 `sites.label` 改成"人话短名"，**走插件自己的写入口 `statx_collect_site_save()`**（`UPDATE sites SET label=?`，只动 label 一列），**没有写任何裸 SQL、没动 hits/hits_rollup 一行**；改前 `VACUUM INTO` 全库备份 + `sites-label-before.tsv` 逐行留底。
- 效果实测（1920×1080，`?p=dash`）：**20 个胶囊 = 1 行**（改动前是 2 行；老 7 字带路径名时更长）。

### ③ 默认时间档改回「今日」

- `pages.php::statx_default_range()` 由 `'7d'` 改回 `'today'`（**仅此一处**，回滚就是改回 `'7d'`）。
- 上一版默认 7d 的理由是"刚过零点打开几乎每站都是 0，看着像统计坏了" —— 但那个 **"全是 0" 的真因是仪表盘缓存 bug**（已在 1.3.2 r6 修掉：缓存按 `$withStats` 分两份）。
  真因既已修掉，「今日」档本身没问题 ⇒ 按站长指令改回。
- 配套：`r=today` 且今日无数据时的提示文案原来写着"后台默认档已经是近 7 天"（现在不成立）⇒ 改为"本页默认档是「今日」，正是站长指定；今日为 0 时点上面那个链接即可"。

### ④ 页面全屏：左栏固定 212px、右栏占剩下的全部

- **真因**：内核 `admin/index.php:647` 给 `.wrap` 写死 `max-width:1240px`，**而且 `.wrap` 是 `.stx-app` 的父元素**（`<div class="wrap"><main><div class="stx-app">`）⇒ 只把内层 `.stx-app` 设 `width:100%` 毫无作用。改 `.wrap{max-width:100%;width:100%;margin:0;padding:0 22px;box-sizing:border-box}`。
  - 作用域取舍已写进 CSS 注释：`:has()` 方案老浏览器失效、提到 `.wrap` 外要动内核渲染顺序 ⇒ 只能覆盖 `.wrap`；本实例是**独立统计站**，`YC_ADMIN_FOCUS` 只由 `statx/boot.php` 对 `s=plug_statx*` 设置，裸 `/admin/` 已 302 到插件页。
- 两个**实测才发现的**细节（不改就做不到"左栏固定 212 / 右栏占满"）：
  1. `.stx-side{width:212px;padding:14px 10px}` 在无全局 `border-box` 的页面里实际占 **234px** > 网格轨道 212px ⇒ 左栏溢出 12px 并把右栏挤窄。加 `box-sizing:border-box` 后精确 212px。
  2. 右栏轨道写 `minmax(0, calc(100% - 212px - 2*pad))` 时，**Chrome 把其中的 `100%` 解析得比网格容器还小 44px**（实测 1920 下算出 1605 而非 1649）⇒ 右边凭空空 44px。改成 `minmax(0,1fr)` 后正确。
- 超宽屏可读性：`.stx-muted/.stx-empty/.stx-note/.stx-block{max-width:78ch}`、`.stx-fld>input/select{max-width:760px}`（**放在样式表最后**，否则被同优先级的后置规则盖掉）。表格不设上限，宽表沿用既有 `.stx-scroll{overflow-x:auto}`。
- 实测（Chromium 152 无头 + CDP，真登录）：1920 下 `.wrap`=1905、`.stx-app`=1861、左栏=**212**、右栏=**1649**（右边缘 1883 与容器右边缘重合）；1440/1280 同构；**960/390 折叠为单列且无横向溢出**。

### 顺手修（两处早就记在册的"已知遗留"）

- **提示条里的字面 `**`**：`$ctx['notice']` 文案一直按 Markdown 写 `**加粗**`，模板从不解析 ⇒ 页面上显示成一对星号（1.3.2 r6 的"仍存在的问题"里记过）。新增 `pages.php::statx_notice_md()`，在 `statx_ctx()` 收口，只处理**成对**的 `**…**`（不跨行、不跨越既有标签）。
- 同上，站点切换条 20 个胶囊一行放不下（r6 也记过）—— ② 的短名 + ④ 的全屏一起解决了。

### 验收

- **38/38 页**（带 `s=plug_statx&p=...` 的侧栏全部菜单项）登录态实测：HTTP **200** + `.stx-app` + `.stx-foot` 全在。
- 浏览器（CDP）逐页 1440×900：**38/38** 渲染出 `.stx-app` 且 **无横向溢出**。
- 默认档：不带 `r=` 打开 → 当前档 **`*今日`**；`?r=7d` → **`*7 天`**（URL 可分享）；`site=teayear.com` 今日 0 → 出现"今日暂无数据…切到近 7 天"提示。
- **零 JS 未破**：本版只改 CSS/HTML/PHP，未引入任何 `<script src>` 或内联 `on*`。

### 回滚（hunk 级，不整文件覆盖）

```bash
# 三个 PHP 文件：从 r7 备份目录取回"改前"原件（内容级恢复，非整包回滚）
BK=$(cat /root/sx-r7-backup-dir.txt)
cd /www/wwwroot/tj-stat/plugins/statx
cp -a "$BK/stxui.php.before" stxui.php
cp -a "$BK/pages.php.before"  pages.php
cp -a "$BK/ui.php.before"     ui.php
php -l stxui.php && php -l pages.php && php -l ui.php
# ④ 只想回滚布局：删掉 stxui.php 里那一行  .wrap{max-width:100%;...}
# ③ 只想回滚默认档：pages.php::statx_default_range() 的 return 'today'; → return '7d';
# ② 只想回滚短名显示：把 ui.php 里 stx_site_short_name(...) 的三处调用还原为原 label 变量
# ②’ 数据侧 label 回滚（走同一插件写入口，不写裸 SQL）：
#   php /root/labels-revert.php        # 依据 $BK/sites-label-before.tsv
```

### 仍未做 / 仍存在的问题

- **09-14 liulanqi 的 231 条差异未逐条归因**（C 2,666 vs A 2,435）。已排除：站点未启用、`v2/v3` 列数不对、`maxRows` 上限（200,000）三条丢弃规则；spool 当前 1 文件 6 行属正常在途。**不影响"我们的数字是对的"这个结论**（2,670 与 2,628~2,880 同量级），但要 100% 归因需复现当时的 spool，产物已不在。
- **老 tj 09-13 及更早的数据没有导入新库**（口径不同：老库存明文 IP）；若要，方案见报告，**本次未动手**。
- 站点切换条 20 个胶囊在 **1440 及以下仍是 2 行**（老站也是胶囊条，未做折叠/下拉 —— 那需要一次专门的界面决策，且插件坚持零 JS）。
- 两个站重名：`2fba18d8de8f32f8`(t.atsvg.com) 与 `atsvg.com` 都叫「ATSvg」；`a4a52d81838e7286`(herontool.com) 与 `herontool.com` 都叫「苍鹭工具」——同站不同入口，语义正确，但列表里看着像重复。

### 打包（r7）

- 版本号：`plugin.php` 的 `'ver' => '1.3.2.r7'`（`version_compare('1.3.2.r7','1.0.0-beta.2','>=')` 实测 **true**，内核 `requires.core` 校验不受影响）。
- 包：`/root/yecha-plugin-statx-r7.zip`（flat 布局，与 r6 同构；从**live 插件目录**打，排除 `var/`、`*.wal`、`*.shm`）
  - **sha256 / size：见 `/root/statx-daydata-layout-REPORT.md` 与市场 manifest 的 `sha256`/`size` 字段**（★ 刻意不写在本文里：CHANGELOG 自身在包里，写了就会"改包→改哈希→再改包"自锁）
- 发布：`/www/wwwroot/yecha.org/market/yecha-plugin-statx-r7.zip`（与源包 sha256 相同）
- 市场 manifest：`/www/wwwroot/yecha.org/var/market/manifest.json` —— **只改 statx 一项**（CAS 读-改-写，改前备份 `manifest.json.bak-r7-20260916-012432`），`package` 从 `…-r6.zip` 指向 `…-r7.zip`，`sha256`/`size`/`files_sha256`/`desc` 同步。
- 交叉校验：从**已发布 zip** 解出的 `ui.php/pages.php/stxui.php/plugin.php` 与 live 树 sha256 **完全一致**。

### ★ 版本说明（谁在 1.3.2 里）—— 2026-09-15

## 1.3.2 — 2026-09-15「仪表盘站点卡片全 0：缓存分档 + 默认口径按域名分流」

> 本版由**两条线并行**贡献；**本条 = 统计看板线**（另一条线贡献「站点管理」「账号」两页与 `adminops.php`）。
> 起因：站长报告「独立统计站上，仪表盘默认显示**全 0**」。

### 修复 1（本次主修）：`statx_ui_sites()` 的 static 缓存不区分 `$withStats`

- **真因**：`stxui.php` 原来是 `static $cache = null; if ($cache !== null) { return $cache; }`。
  一个请求里的调用顺序是固定的：`adv.php` 先取站点切换条 ⇒ `statx_ui_sites_ordered()` ⇒
  `statx_ui_sites(false)`（这一版把 `pv/uv/bot` 写成 0）**先把缓存填满**；随后仪表盘的
  `statx_pg_sites_grid()` 调 `statx_ui_sites(true)`，于是拿到的是**全 0 那一版**
  ⇒ **19 张站点卡片的「今日 PV / 今日 UV / 今日爬虫」全部渲染成 0**。
- **不是**「默认站点选错」：默认站点一直由数据推出（区间内记录最多的站 = `ec3c884f5dd0819e`），
  且它**有数据**。同一秒的「设置」页（走另一条查询）显示 `xuecha.org 72/72`、`liulanqi.com 54/49`
  ⇒ 数据一直在，只有仪表盘读到 0。
- **修法**：缓存**按 `$withStats` 分档**（键 `plain` / `with`）。只改开头 4 行 + 末尾 1 行，
  函数体其余逻辑一字未动；两个调用方各自行为不变。
- **实测（同一函数、只改调用顺序的对照实验）**：`false-first`（= 真实顺序）⇒ 19 行 PV 之和 **0**；
  `true-first`（对照组）⇒ 之和 **151**，**恰好等于裸 SQL 的全库今日人类 PV 151**。

### 修复 2：默认口径按「请求域名是否已登记为站点」分流

- 新增 `pages.php::statx_host_site_key(host)`：只认 `enabled=1` 的站，命中 `sites.host` 或
  `sites.domains` 任一个即算命中（两边都去端口与 `www.`）。
- `statx_ctx()` 的默认站点：
  | 条件 | 默认站点 |
  |---|---|
  | 带 `?site=<key>` | 用显式值（不变） |
  | 不带，**当前域名已登记为站点** | **就是它自己**（别人把插件装到自己博客上：行为不变，且绝不会被「记录最多的站」顶掉） |
  | 不带，**当前域名未登记**（统计服务器场景） | 「区间内记录最多的站」兜底 **+ 页面上显著提示当前是哪个站、为什么、怎么回汇总** |
- 对照实测（同一 `statx_ctx()`，只改 `HTTP_HOST`）：`tj.51puer.com` ⇒ 未登记 ⇒ 兜底
  `ec3c884f5dd0819e`；`xuecha.org` / `www.xuecha.org` / `cntales.com` / `liulanqi.com` ⇒ 命中 ⇒ 该站自己。
  **`xuecha.org` 是关键对照**：它不是数据最多的站（liulanqi 才是）⇒ 老口径给 liulanqi、新口径给
  xuecha.org ⇒ 证明「未登记才走兜底」这个条件真的生效。

### 修复 3：站点选择器新增「全部站点（汇总）」+ 修好系统页切站

- 切换条**第一个胶囊固定是「全部站点」**，URL = `?p=dash`（**不带 `site`**）⇒ 可分享（零 JS）。
- 系统级页面（仪表盘/设置/数据保留/蜘蛛汇总/采集预警/统计检测）上点某个站胶囊，
  现在进**那个站自己的看板**（原来点了等于没反应，因为仪表盘不按站筛）。
- 修掉一处误导文案：胶囊 tooltip 原写「今日 N PV」，实际显示的是**当前区间内记录条数**
  ⇒ 改为「`<site_key> · <区间名> N 条记录`」。

### 修复 4（顺手，另一个真 bug）：网站概况页脚被浏览器吞掉

- 「统计代码」区块的口径说明里 `<pre>` / `<script>` 是**说明文字却写成了真标签**，
  而页面里**没有 `</script>`** ⇒ 浏览器把其后内容当脚本数据处理。
  实测该字面标签出现在**页面 99.1% 处，其后就是页脚口径那一行** ⇒ **页脚在浏览器里不显示**。
- 改为实体 `&lt;pre&gt;` / `&lt;script&gt;`（副作用：本页 `<script` 字面量 1 → 0，更符合零 JS 口径）。

### 验收（渲染 HTML vs 另写一遍裸 SQL）

- 不带参数打开看板：今日 PV **156** / UV **143** / 爬虫 **2886** / 30 天 PV **714** / 30 天 UV **492** /
  站点数 **19**，全部与裸 SQL 相等；19 张卡片逐张**精确相等 19/19**；
  **卡片「今日 PV」之和 156 = 全库 156**、卡片爬虫之和 **2886 = 全库 2886**（汇总 = 各站之和）。
- 带 site 的「网站概况」：PV 332 / UV 218 / 会话 212 / 点击 298（逐条与 SQL 相等）。
- **已封口的一天 `2026-09-14` 上 7 个站 × 7 个数字 = 49 个数字全部精确相等**。
- **零 JS 未破**：改动前后 24 个页面逐页对照，`<script src>` 恒为 0、内联 `on*` 数量未变。
- **采集不受影响**：hits 6,296 → 6,358 → 6,380 → 6,392 → 6,409 → 6,427 持续增长，
  `max_ts` 前进到 `09:54:01`，`integrity_check = ok`；**本次零 SQL 写入、未删任何一行**。

### 未做（附理由）

- **未实现「默认 = 全部站点区间聚合」**：站点维度取数散在 77 处 `site_key=?` 参数化查询里，
  改造面过大且当时有并行写者 ⇒ 采用「默认到数据最多的站 + 显著提示」，
  并把「全部站点汇总」做成**一等入口**（仪表盘本身就是全站汇总）。
- **未改 vhost / nginx / MySQL / php-fpm**；未重启也未 reload 任何服务
  （`opcache.validate_timestamps=1`、`revalidate_freq=0`，文件 mtime 变化即失效）。
- **未产出完整 r5 包**：当时插件树混有**并行线未完成的 r6 内容**、`ver` 已被对方改成 1.3.2，
  此时打整包只会标错版本或发出半成品 ⇒ 只交付 delta + 逐文件 sha256，
  待 r6 定稿后由 r6 线一起打。

### 回滚（hunk 级，不整文件覆盖 —— 避免抹掉并行线的改动）

```bash
cd /www/wwwroot/tj-stat/plugins/statx
patch -p0 --batch --forward stxui.php  < /root/statx-dashfix-20260915/revert-stxui.patch
patch -p0 --batch --forward pages.php  < /root/statx-dashfix-20260915/revert-pages.patch
patch -p0 --batch --forward ui.php     < /root/statx-dashfix-20260915/revert-ui.patch
patch -p0 --batch --forward pages3.php < /root/statx-dashfix-20260915/revert-pages3.patch
```

（已在当前 live 文件的副本上实测：4 个文件反打均 OK、`php -l` 均 OK、本次改动标记全部消失、
并行线的标记全部保留。回滚不需要重启任何服务。）

---

## 1.3.2 — 2026-09-15「一个正常用户进来能自己搞定：站点管理 + 账号」（r6）

> 站长原话：「**要有添加和修改站点，还需要修改用户名和密码。你就想，这是一个正常用户，他进来后要有哪些操作**」。
> 本版就补这两件，并把「**到底有没有在收数**」在站点管理页顶部用一句话说清。
>
> ★ 本包（r6）的基线说明：以「2026-09-15 01:32 UTC 线上插件树」为干净基线（该状态已线上验证），
> **不含**当时另一处并发改动正在整文件重写的 `ui.php / pages.php / pages3.php / stxui.php` 的中间态。

### 新增：左栏「系统」组两页（导航 37 → 39 页）

| 页面 | 干什么 |
|---|---|
| `?p=sites_mgr` **站点管理** | 列表（名称 · 域名 · site_key · 启用 · **最近上报** · **近 24h 行数** —— 近 24h 为 0 标红「没通」）+ 添加 + 修改 + 启停 + 删除（预览→确认）+ 一键「检测这个站」 |
| `?p=account` **账号** | 改登录名 / 改口令（**复用内核 `Auth::verifyPassword()` / `Auth::setPassword()`**，不自己造一套认证） |

实现落在新文件 `adminops.php`；对既有文件的改动只有 **7 处锚点**（`ui.php`×3、`pages.php`×1、`adv.php`×1、`admin.php`×2、`plugin.php`×2），全部是「新增一行」级别，**没有整文件覆盖**。

### 站点管理

- **添加**：只填「名称 + 域名」⇒ `site_key` 按**既有口径**自动生成（小写、去 `www.`、只留 `[a-z0-9.-]`、≤64，
  与 `statx_collect_site_key()` / `statx_ep_key()` 一致），**保存后当场给出可复制的统计代码**。
- **统计代码**用 `<pre>` 纯文本（**刻意不加 JS 复制按钮** —— 零 JS 约束），并写清**贴在哪**：
  YeCha → `stats.foot`；静态页 / 别的 CMS → `</body>` 之前。
  端点域名沿用既有 `collect.pub_origin` 解析链：本站解析为 `https://tj.51puer.com`，
  **别人把插件装到自己的站上时，这一串里就是他自己的域名** —— 插件从不把访客数据指向第三方域名。
- **修改**：名称 / 域名(host)。★ **site_key 刻意不变** ⇒ 历史数据不断链；
  改 host 只影响端点 Origin 白名单与「抓首页实证」。
- **启停**：**停用 = 不再统计，但一条历史都不删**（页面明确写出来）。
- **删除（两步 + 服务端强制二次确认）**：
  ① **GET 预览（只读）**：显示「将删 N 行明细 + M 行按天汇总 + 1 行登记」、时间范围、
     **该站最近 10 行明细样本**、以及**不可恢复**与备份落点；
  ② **显式确认**后才执行：**先 `VACUUM INTO` 做整库一致性备份**（写 `<站点根>/var/statx/backup/`，
     HTTP 取不到；页面回执给出完整路径与 `integrity_check` 结果），再**分批**删明细（5000 行/批，不长时间独占写锁）。
  **缺勾选 / 手打 site_key 打错 / 无 CSRF token / 用 GET 打开 —— 四种情况都一行不删。**
- **一键「检测这个站」**：① 端点是否被内核/插件接住（看 **`X-Statx` 内容标记**，不只看状态码）
  ② 该站 HTML 里是否**已实证**到那行 JS ③ 最近有没有上报 ⇒ **一句话结论 + 怎么办**，
  并把三条检查的原始证据（HTTP 码 / 字节数 / cache 头 / 找不到什么）逐项列出。
  取不到首页或该站不在本机时，**明说「无法自动确认」并给出真实原因**（如 DNS 解析不了），不假装通过。

### 账号

- **改登录名**：要**验一次当前密码**；命名规则沿用内核注册时那条正则
  `/^[A-Za-z0-9_\-\x{4e00}-\x{9fa5}]{2,30}$/u`；
  且不能与任何用户的**用户名或邮箱**重复（登录框两者都认）。
- **改口令**：要**验一次当前密码** + 两次输入一致 + **至少 6 位**
  （与内核 `do=admin-password` **逐字一致**，本插件不另立标准）；bcrypt 存储。
- ★ **改完当前会话不会失效**（内核会话存的是 `$_SESSION['uid']`，不是登录名），
  但**下次登录必须用新凭据**。实测：改完当次响应、以及之后的新请求，都仍在后台内。
- 页面写明口径：这里改的是**本统计站自己的后台账号**（`ye_users`），
  与站点统计数据无关；并给出「**已经登不出来时**」的 root 侧重置命令（用的就是内核同一个 `password_hash`）。

### ③ 顺手补的三件（刻意克制，各只占一行/一块）

- ⒜「**我在看哪个站 / 切到别的站 / 看全部**」：**本版未动**。
  左栏站点块与顶部站点切换条已由**当时并发进行的另一处改动**扩成含「**全部站点**」的形态
  （`stx_page_is_all_sites()`）—— 按「谁先做完就照谁」的约定，本版**沿用它，不重复实现**。
- ⒝ **采集一句话结论**：站点管理页顶部一条（tone = ok / warn / bad），
  例如「尚未收到数据（库里 0 条明细）。**怎么办**：① 拿到统计代码 → ② 贴到站点 → ③ 回来点『检测』」；
  并给出跳往「统计检测」页的入口。
- ⒞ **版本/更新提示**：页脚一行 —— `插件 StatX <ver>（本包 <日期> 构建）· 内核 <YC_VERSION> · 看有没有新版：云市场`。
  **不发起任何网络请求**（不在页面渲染里做出站请求）。

### 并发说明（如实记录，影响交付物）

本插件在 .106 上**同时有两条作业线在改**。另一条线用**整文件覆盖**改
`ui.php / pages.php / ui.php / stxui.php`（实测 01:41:30 与 01:44:08 两次），
每次都把本作业刚加进 `ui.php` 的导航登记与 `pages.php` 的路由登记**冲掉**。
本版因此：① 全程只在**副本**上空跑、**锚点不唯一就整体不写**、写前 `cp -a` 备份；
② 加了一个**渲染兜底** `adminops.php::statx_ao_render_direct()` —— 即使那两个文件把登记冲掉，
这两页仍能直接渲染（登记在位时它是死代码，返回 `null` 走正常路径）；
③ r6 包以**干净基线**构建，**不含**另一条线的中间态文件。

### 打包纪律（与 r4 同）

包根直接是插件文件 · **不含** `sdb_path.php`（站点私有配置）· `rt.php` 是 `on=0` 占位（`_stub`）·
**不含** `*.xdb`（37MB 地域库）· 无 `*.bak` / `*.tmp` 残留 · 28 个 php 全部 `php -l` 通过。

## 1.3.1 — 2026-09-14「公共目录红线：插件永不改 /tmp 这类目录」（r4）

> 起因：**当天真实生产事故**（08:17:14 → 08:35:11，共 **1,077 秒**）。
> 本插件落库路径命中「临时目录兜底」时，`statx_sdb_own()` 会对 `dirname($file)`
> —— 也就是 **`/tmp` 本身** —— **无条件** `chmod 0750` + `chown www:www`。
> 于是同一台机器上的 MySQL（当时 `tmpdir=/tmp`）无法在 /tmp 建临时文件，
> **启动即 signal 11 崩溃且起不来**，4 个站返回 500。
> 指纹：`/var/tmp`、`/dev/shm`、`/run/lock` 全是 1777 未动，**只有 `/tmp` 被改成 0750 www:www**。

### 修复：三条闸门（缺一不可）

| # | 闸门 | 位置 |
|---|---|---|
| ① | **只对自己「刚刚新建」的目录 chmod/chown**；本来就存在的目录一律不碰 | `sdb.php::statx_sdb_own(?string $file, bool $created)` |
| ② | **公共/系统目录一律不碰**（`/tmp` · `/var/tmp` · `/dev/shm` · `/run/…` · 祖先含 `/tmp` 的路径 · 无粘滞位的世界可写目录）—— **即使有人显式把库路径指过去** | `sdb.php::statx_sdb_public_dir_reason()` |
| ③ | 目录不可用/属公共目录时**不静默改权限**，改为返回 `warn` 给后台与 CLI 显示，由运维决定 | 同上 + `--task=env` |

### 移除：临时目录兜底（事故根因）

- 落库路径第 ⑤ 档 `<临时目录>/statx/statx.db` **已删除**。站点根 `var/statx` 不可写时
  **明确报错**（`source` 标为 `error:…`），而不是偷偷落到 `/tmp`。
- 红线写进代码注释：**插件绝不把库落在公共临时目录，也绝不对公共目录改权限。**

### 新增：安全自检（启动 + `--task=env`）

- `sdb.php::statx_sdb_security_audit()` —— 只读四件事：
  ① 库路径是否落在公共目录；② `/tmp`/`/var/tmp`/`/dev/shm`/`/run/lock` 是否仍是 1777；
  ③ 库目录与 `statx.db`/`-wal`/`-shm` 是否对外可读；④ 回归测试闸门本身（公共目录必须判为「不碰」）。
- `cli.php --task=env` 打印完整自检（PASS/FAIL 逐项）；`boot.php` 在**端点请求**上每 60 秒
  最多跑一次并把结果写 `var/statx/sdb-safety.json`（异常时另写 `sdb-safety-WARN.txt`）。
  **普通页面零成本**（自检排在「非端点请求 return」之后）。

### 实测验收（.106 现场）

- 故意把 `/tmp` 设成 `0750 www:www` → 修复版跑完 **`/tmp` 仍 0750（完全没碰）**；以 root 跑同样不碰。
- **反向对照**：同一实验换用修复前版本，`/tmp` 从 `1777 root:root` 变成 **`750 www:www`**
  = **与事故现场指纹完全一致** ⇒ 实验有效。
- 回归：插件**自建**的目录仍被正确收紧为 `0750`；真实 cron 命令（`--task=env`）后 `/tmp` 不变。

---

## 1.3.0 — 2026-09-14「新站开箱即用：不依赖内核补丁也能收数据」

> 起因：站长指出最后一个缺口 —— **用户下载要真正能使用**。
> 1.2.0 把「可移植化」做完了（库落在本站、不发数据去别人家、默认只监控本站），
> 但**装完之后端点仍然是 404/302**：内核 `core/Plugins.php::compat()` 的版本归一 bug
> 会把**所有第三方插件**在 `Plugins::boot()` 里 `continue` 掉，
> 插件的 `register_file`（`boot.php`）从头到尾没被 `require`。

### 新增：独立入口（standalone entry）—— 本版最重要的一件事

| 文件 | 作用 |
|---|---|
| `st.php` | **不经内核**的端点入口：只读 `config.inc.php` + 本插件的纯函数文件，然后交给 `endpoint.php`。**完全不碰** `core/bootstrap.php` / `Db` / `Plugins::boot()` |
| `install-standalone.php` | 安装器：在站点根放一份副本；`--nginx` 还替你写 nginx 两行（`nginx -t` 失败会自动还原） |

⇒ 内核那个 `compat()` bug 与本通道**无关**：不改内核也能收数据。
⇒ ★ **一石二鸟：顺带更快** —— 内核 bootstrap 每个请求都要起 PDO 并读选项表，
而采集端点本来零 SQL；走独立入口时这份开销**整个不付**（实测见 README 末尾）。

### 自检更诚实

- `cli.php --task=collect-endpoint` 的「内核引导预检」新增一行：**独立入口是否已就位**
  （扫描站点根含 `YC_STATX_STANDALONE` 的 php 文件）。
- 根因命中时，把修法**按推荐顺序**重排：
  **(A) 装独立入口（不必改内核）** → **(B) 给内核打版本归一补丁（治本）**。
- ★ 明确标注 **「放宽 `plugin.php` 的 `requires`」是死路**：内核在**读 `requires` 之前**
  就已经把插件 `continue` 掉了；而且改成 `'1.0'` 之后 `version_compare('1.0','1.0','>=')` 仍是 `false`。
  （上一个版本曾把这招列为「临时绕过 (b)」，实测**根本无效**，本版移除。）

### 文档

- README 第 11 节重写：**修法 A（不必改内核，推荐）** 在前，**修法 B（改内核，治本）** 在后，
  并新增「✘ 不要再试：放宽 requires」小节。
- 上游侧同步修好：`core/Plugins.php::compat()` 的版本归一补丁**已合回发行包**
  （`yecha-blog-1.0-beta-build20260911.zip` 起），所以**新装的站不会再踩**。

### 包内容

- 包根直接是插件文件（`plugin.php` 在根），**不含** 37MB 地域库、**不含**本机专属的
  `sdb_path.php`；`rt.php` 仍是**未初始化占位**（`on=0`），解压不会让任何数据流向任何地方。

## 1.2.0 — 2026-09-14「可移植化：让下载的人真正装得上」

> 起因：站长要求「**要真实环境，这个统计系统是一个插件，用户下载要真正能使用**」。
> 上一版（1.1.0）功能齐全，但**绑死在做这个插件的这台机器上**：硬编码路径、硬编码域名、
> 首次运行会把统计服务器自己的 20 个监控目标种进别人的库，唯一给出的安装片段指向别人家的域名。

### 破坏性/行为变更（老安装升级后请读一遍）

| # | 变更 | 老行为 | 新行为 |
|---|---|---|---|
| 1 | **采集端点默认关闭** | `rt.php` 在包里是**已启用 + 带统计服务器站点 key 与 28 条来源白名单** | 包里是**未初始化占位**（`on=0`）：解压后端点不生效，**不会让任何数据流向任何地方**；首次配置（后台保存 / `collect-init`）才生成真配置 |
| 2 | **SQLite 库默认位置** | `/www/gushu-statx/statx.db`（站点根之外，别的机器上 `/www` 可能不存在或不可写 ⇒ 直接失败） | `<站点根>/var/statx/statx.db`（站内 `var/`，内核/nginx 已拒绝直取）<br>★ **老安装无缝**：该旧文件**已存在就继续用它**，数据与权限都不动 |
| 3 | **外部端点域名回退** | `collect.pub_origin` 空 → 硬回退 `https://tj.51puer.com` | 空 → 自动识别**本站**（`site.url` → 当前 Host → 站点根目录名）；**识别不到就明确报错**，绝不指向第三方 |
| 4 | **注入模式默认值** | `pub`（外部域名） | `auto`（= 站内 `/st.js`，数据不出站；拿不到域名时也退回站内） |
| 5 | **首装的监控目标** | 每次打开后台无条件播种 **20 个统计服务器自己的目标**（十几个别人的域名） | 只播种**本站自己**（首页 + sitemap + robots；robots 默认停用），全用最小断言不会误告警；想要示例清单显式配 `statx.health.seed_file` |
| 6 | **备份看守自检** | 默认读 `/root/tj-backup-watch/*`，→ 别人机器上**每轮报一次「探针配置故障」并可能发邮件** | 默认**空 = 关闭**；显式配置 `statx.selfcheck.watch_state` 才启用，未配置时不建目标行、不落库、不发事件 |
| 7 | **spool 目录** | 写死 `/tmp/statx-spool`（两处各写一份）；`/tmp` 不可写时**访客数据静默丢失** | 选项可配；默认系统临时目录，不可写则自动退到 `<站点根>/var/statx-spool` |
| 8 | **库文件属主** | 写死 `chown www:www` | 自动用**本机真正的 Web 用户**（站点根目录的属主）；只在兼容旧部署时才回退 `www` |
| 9 | **`--task=links-health`** | 默认站点 `yecha.org`，读别人站的 `ye_links` | 默认本站（可配 `statx.links.source_site_key`）；没有 links 插件时显示「未安装」而不是报错 |
| 10 | **健康矩阵置顶** | 写死把 `tj` 置顶 | 可配 `statx.health.pin_site_key`；空 = 不置顶 |

### 新增可配置项（都能留空 = 自动）

`collect.db_path`（库位置）、`collect.roots`（同机其它站点根的父目录）、
`collect.cache_glob`（目标站全页缓存通配）、`collect.db_owner`（库属主）、
`geo.db`（地域库路径）、`health.seed_file`（示例目标清单）、`health.pin_site_key`。

### 新增命令 / 输出

- `cli.php --task=env`：环境自检（PHP/扩展/SQLite 版本/库位置与来源/spool 可写/插件目录可写）。
- `collect-init` 末尾打印**装机自检**与「下一步是什么」，缺什么直接给修法。
- `collect-status` 打印**库位置来源**与可写性。

### 降级（不再 fatal / 不再假装正常）

- 缺 **curl**：`--task=health` 干净退出并说明（原来会 `Call to undefined function curl_init()` 致命错误）。
- 缺 **pdo_sqlite**：明确告知采集不可用（原来看板显示「0 PV」这种**假象**）。
- SQLite **< 3.24**：提示 UPSERT 不支持（按天汇总会失败）。
- 缺 **地域库**：地域列留空并在后台说明「地域库未安装」，不再让用户对着空列猜。
- 地域库查找顺序新增 `<站点根>/var/statx/` 与 `/usr/share/ip2region/`（原先是写死的 `/opt/tj/data/`）。

### 界面/文案

- 品牌名从 `yecha stat` 改为 `statx`（原来每个用户的看板都写着别人的产品名）。
- 移除「打开 tj 后台 →」外链（改为：只有你自己配了外部端点域名时才显示）。
- 告警邮件里的后台链接不再指向 `gushuwu.com`，改为本站域名。
- 「数据保留」页给出的备份命令用**当前真实库路径**，不再印 `/www/gushu-statx/...` 与 `/root/...`。
- 「地域 Top10」的说明用**实际找到的库路径**，没装库时说清楚。
- 「注入模式」下拉新增 `auto（推荐）`；「采集与站点管理」新增「SQLite 库位置」输入框。
- 修正 `admin.php` 里「33 个导航项」的错误描述（实际 36 项 + 1 个隐藏页 = 37）。

### 保持不变（刻意）

- **后台零 JavaScript**（唯一内联 `onchange` 旁边始终有「查看」按钮）；37 页导航。
- **数据保留页 POST + CSRF + 服务端强制二次确认**；GET 打开只读。
- 隐私模型：**不存 IP**、访客 id 为客户端随机串、按天汇总永久保留。
- 只追加自己的 `<!-- statx:begin -->…<!-- statx:end -->` 标记块，旁站统计标签一个字节都不动。

### 文档

- `README.md` 重写为**面向下载用户**的安装/配置/排错手册（三分钟装好、可选件、隐私说明、
  服务器要求、备份、卸载回滚、症状→原因→怎么办）。
- 原来的长篇内部开发记录移到 `DEVELOPER-NOTES.md`。
- 新增 `STATX-FIELDS.md`（逐字段隐私自证）与 `data/README.md`（地域库放哪）。

---

## 1.1.0 — 2026-09-13

- 采集改落到插件自带的 SQLite；爬虫三级排除；一行 JS 注入与实证；后台零 JS 看板。

## 1.0.x

- 站点可访问性健康检测（探针 / 分级目标 / 去抖告警 / 后台矩阵）。
