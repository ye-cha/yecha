# 地域库放这里（可选）

本目录是**空的**：地域库（ip2region 的 `.xdb`，IPv4 约 11 MB / IPv6 约 37 MB）**不随插件分发**
（太大，而且每位用户的版本/覆盖范围未必相同）。

想要的用户自行获取后，把文件放在**下面任一处**，插件会自动找到（按顺序）：

1. `<站点根>/var/statx/ip2region.xdb`   ← **推荐**（站内 `var/`，内核/nginx 拒绝 HTTP 直取）
2. `<站点根>/plugins/statx/data/ip2region.xdb`   ← 就是本目录
3. `/usr/share/ip2region/ip2region.xdb`
4. `/opt/tj/data/ip2region.xdb`（兼容旧部署）

也可以显式指定：后台「采集与站点管理」→「地域库路径」，或 CLI：

```bash
php ../cli.php --task=geo                 # 看当前找到了哪个库、结构版本、命中率
php ../cli.php --task=geo --ip=8.8.8.8    # 验证单个 IP
```

**找不到库会怎样？** 地域一列恒为空，**其它一切照常**（不报错、不影响采集）。
历史行不会回填地域 —— 因为插件**从不存 IP**。
