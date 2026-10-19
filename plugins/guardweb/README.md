# 防采集（YeCha 通用插件）

拦截 AI 训练爬虫与采集器，**不影响正常搜索引擎蜘蛛与真人访客**。

## 功能

| 能力 | 说明 |
|---|---|
| AI 爬虫识别 | GPTBot / ChatGPT-User / OAI-SearchBot / ClaudeBot / Claude-Web / PerplexityBot / CCBot / Amazonbot / cohere-ai / Bytespider / PetalBot / Google-Extended / Applebot-Extended / meta-externalagent / FirecrawlBot / Crawl4AI / Diffbot / AI2Bot 等 |
| 搜索引擎放行 | Googlebot / Bingbot / Baiduspider / Sogou / 360Spider / Yandex / DuckDuckBot / Applebot / Naverbot / Slurp / 社交分享预览（facebookexternalhit / LinkedInBot / Twitterbot）/ 收录与监控服务商（Ahrefs / Semrush / MJ12 / Pingdom / UptimeRobot） |
| 采集脚本识别 | python-requests / urllib / aiohttp / httpx / scrapy / curl / wget / Go-http-client / okhttp / java / headless 浏览器 / playwright / puppeteer / 漏洞扫描器 等 |
| 空 UA 拦截 | 无浏览器 UA 的页面抓取直接拒绝 |
| 频率限制 | 可选：非浏览器 UA 按 IP 滑动窗口限速（默认关） |
| robots.txt 增强 | 可选：对 AI 爬虫逐条输出 `Disallow: /`，搜索引擎正常读 sitemap |
| 命中日志 | 按 日/IP/原因 聚合计数（ai_bot / scraper / no_ua / rate），后台可查可清 |
| 响应策略 | 403 拒绝 / 200 空壳 / 410 Gone / 429 限速（可切换） |

## 安装

1. 后台 → 应用市场 / 插件 → 安装并启用；
2. 后台 → 访客防线 → 打开「总开关」→ 保存。

插件**默认关闭**，开箱不影响任何流量；开启后搜索引擎蜘蛛仍自动放行（无需额外配置）。

## 注意

- 若站点套了 Cloudflare 等反代，开启「信任 CF-Connecting-IP」，频率限制与日志才会按真实访客 IP 统计；
- 建议在「白名单 IP」加入自己的运维出口 / 监控 IP，避免自测被拦；
- 命中响应 403 为纯文本，不渲染页面，性能开销可忽略（路由前拦截、命中才落库）。

## 判定顺序（防止误伤）

1. 非前台页面 GET/HEAD、已登录、后台/安装/静态资源 → 一律放行；
2. 搜索引擎/社交/收录蜘蛛白名单 → 放行；
3. AI 训练爬虫 → 拦截；
4. 采集脚本特征 → 拦截；
5. 空 UA → 拦截；
6. 可选频率限制。

## 许可

MIT · 由 YeCha 发布
