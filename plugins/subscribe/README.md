# 邮件订阅 Newsletter(YeCha 插件)

给 YeCha 站点加一个真正可用的邮件订阅:前台订阅表单 → 确认邮件(双确认)→ 后台名单管理 → 周刊一键/定时群发,支持多语言与一键退订。

## 功能

- **前台订阅页** `/subscribe`(以及各语言前缀,如 `/en/subscribe`):邮箱表单 + 蜜罐与频率限制
  - 页面直接跑在**主题外壳**里(与站点其它页同宽容器、同一顶栏/页脚/右栏),界面语言跟随当前语言
  - 文案取自后台独立页 `slug=subscribe`(有译文则用译文);页内写 `<form>` 会被自动忽略,表单由插件渲染
- **双确认**:提交后发确认邮件,点击 `/subscribe/confirm?t=…` 才计入名单(避免垃圾订阅)
- **一键退订**:每封邮件底部带 `/subscribe/unsub?t=…`,无需登录
- **按语言发件人**:中文订阅用中文站名、英文订阅用英文站名(YeCha 多语言站点自动生效)
- **后台管理**(插件 → 邮件订阅):订阅统计、名单表格、测试发信、立即群发、CSV 导出、手动退订/删除
- **定时周刊**:CLI `plugins/subscribe/newsletter.php`,可挂计划任务

## 安装

1. 后台「云市场」安装本插件(或把本包解压到站点 `plugins/subscribe/`);
2. 到「设置 → 邮件通知」填写 SMTP(主机/端口/SSL/用户名/密码/发件人),保存;
3. 插件页点「发送测试」验证通道;
4. 把订阅页入口放到导航或页脚(`/subscribe`,英文站 `/en/subscribe`)。

> 表 `ye_subscribers` 由插件迁移自动创建;卸载插件会连带删除该表(可在 `plugin.php` 的 `uninstall` 里调整)。

## CLI

```bash
php plugins/subscribe/newsletter.php --dry                 # 只打印不发送
php plugins/subscribe/newsletter.php --test you@example.com # 发一封测试
php plugins/subscribe/newsletter.php                       # 给全部已确认订阅者发送
php plugins/subscribe/newsletter.php --limit 5             # 每封邮件包含最近 5 篇
```

计划任务示例(每周日 09:00):

```
0 9 * * 0 /www/server/php/83/bin/php /path/to/site/plugins/subscribe/newsletter.php >/dev/null 2>&1
```

## 发信与合规提示

- SMTP 密码保存在核心选项 `mail.pass`(加密存储,后台不回显);
- 建议发件域名与站点域名一致,并配置 **SPF / DKIM**,降低进垃圾箱概率;
- 免费邮箱服务多有每日发信额度,建议分批发送;
- 邮件为纯文本 UTF-8,含退订链接,符合常见反垃圾要求。

## 版本

1.0.0 · MIT · YeCha
