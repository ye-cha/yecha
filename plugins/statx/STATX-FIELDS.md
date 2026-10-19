# StatX 落库字段清单（隐私自证）

> 用途：让站长能**逐字段**核对「这个统计到底存了什么、有没有存访客身份」。
> 生成方式：从 `sdb.php` 的建表语句逐列抄出（`hits` / `hits_rollup` / `sites`），
> 可用 `php cli.php --task=collect-fields` 打印本机实际库的列（以实际库为准）。

## 1. 结论先行

- **没有任何 IP 字段** —— IP 只在收到上报的那一刻用一次，之后**不落库**。
- **没有任何 Cookie/邮箱/账号明文**；`uid` 是**访客浏览器自己生成**的随机串（存 localStorage），
  `visitor_hash` 是「每日轮换盐 + IP + UA」的短哈希，**不可逆**且**跨天不可关联**。
- 明细默认**保留 30 天**，按天汇总默认**保留 24 个月**。

## 2. 表 `hits`（访问明细）

| 列 | 含义 | 是否与「识别访客」有关 |
|---|---|---|
| `id` | 自增主键 | 否 |
| `ts` | 上报时间（服务器时区，`Y-m-d H:i:s`） | 否 |
| `d` | 冗余日期列（`Y-m-d`），供按天索引查询 | 否 |
| `site_key` | 站点标识 | 否 |
| `host` | 访问用的主机名 | 否 |
| `path` | 页面路径 | 否 |
| `kind` | 事件类型：`pv` / `cl`(点击) / `ev`(自定义事件) | 否 |
| `is_landing` | 是否本次会话落地页 | 否 |
| `ref` | 来源完整 URL（可能含来源站的查询串） | 间接（是来源站的信息，不是访客的） |
| `ref_domain` | 来源域名 | 否 |
| `ref_kind` | 来源四分类（站内/搜索/社交/外链） | 否 |
| `utm_source` `utm_medium` `utm_campaign` `utm_term` `utm_content` | 你投的推广参数 | 否 |
| `sid` | **会话 id**：浏览器端随机生成，30 分钟无活动即换新 | 是（会话级，不跨天） |
| `uid` | **访客 id**：浏览器端随机生成并存入该站的 localStorage | 是（长期，但**是随机串不是身份**；清空浏览器数据即重置） |
| `is_new` | 本次会话是否新访客 | 是（布尔） |
| `visitor_hash` | `sha256(每日盐 + IP + UA)` 前若干位 | 是（**不可逆、每日换盐**） |
| `width` `height` | 屏幕分辨率 | 弱（设备指纹的一部分，仅分辨率） |
| `vw` `vh` | 视口尺寸 | 弱（同上） |
| `lang` | `navigator.language` | 弱 |
| `x` `y` | 点击坐标（仅 `kind=cl`） | 否 |
| `en` | 自定义事件名（仅 `kind=ev`） | 否 |
| `device` | 设备类别（桌面/手机/平板/爬虫） | 否 |
| `is_bot` `bot_cls` `bot_name` | 爬虫判定与分类（搜索引擎/社交/AI/采集器） | 否 |
| `region` | 地域（**可选**：需要 ip2region 库；IP 用完即弃） | 否（城市级，无精确地址） |
| `browser` `os` | 浏览器 / 操作系统 | 弱 |
| `ipver` | 4 / 6（仅记录协议版本，不记地址） | 否 |
| `ref_type` `kw` | 来源细分 / 搜索关键词 | 否 |

## 3. 表 `hits_rollup`（按天汇总，永久保留）

`site_key` `d` `pv` `uv` `sess` `newv` `landing` `ref_ext` `bot_hits`
`ref_top` `page_top` `dev_top` `bot_top` `built_at`

全是**计数与 Top-N 字符串**（如 `"/post/1|120,/post/2|88"`），**不含任何逐访客信息**。

## 4. 表 `sites`（站点登记）

`site_key` `label` `host` `domains` `enabled` `local_root` `tj_token`
`inject_where` `inject_tag` `inject_at` `verify_at` `verify_ok` `verify_note`
`script_url` `endpoint_url` `first_hit_at` `last_hit_at` `hits_total` `sort` `note` `created_at`

描述的是**站点自己**（域名、库路径、注入状态），与访客无关。

## 5. 健康检测（能力②，MySQL）

`ye_statx_health_targets` / `_checks` / `_daily` / `_events`：
存的是**你自己配的探测目标**（你的域名/路径）与探测结果（状态码、耗时、TLS 剩余天数）。
不涉及访客。

## 6. 中间产物（不在库里）

`spool` 目录下按行追加的文本（默认在系统临时目录），每行是一次上报；cron 汇总进库后
**立即删除该文件**。它包含与 `hits` 同样的字段（含来源 URL），**不含 IP**。
