<?php
/* StatX 库位置（显式钉住）——本实例是【独立的统计站】，库就在自己的站点根下：
 *   <站点根>/var/statx/statx.db
 * 显式写出来的理由：不依赖任何自动探测/兜底 ⇒ 库位置永远不会因为别处存在同名文件而漂移。
 * 权限：目录 750 www:www，库 640 www:www；站点根 var/ 已被内核拒绝直取。
 * 回滚：删掉本文件 ⇒ 回到「自动落位 <站点根>/var/statx/statx.db」（结果相同）。 */
return '/www/wwwroot/tj-stat/var/statx/statx.db';
