<?php
declare(strict_types=1);
// 图片优化（ImgOpt）：本地化外部图片 + 统一转 WebP + 为商品卡生成缩略图。
// 解决的问题：① 商城商品图原本是外链 http://www.51puer.com/...（慢、易失效、盗链风险）；
//            ② 列表/卡片直接加载原图（大图，浪费带宽）；
//            ③ 格式不统一。
// 策略：把 ye_mall_* 里的外部图片下载到本地 uploads/mall，转成 WebP；
//      商品图同时生成 _t.webp 缩略图，列表/卡片用缩略图、详情主图用原图。
return [
    'id' => 'imgopt',
    'name' => '图片优化 ImgOpt',
    'ver' => '0.1.0',
    'desc' => '本地化商城外部图片 → 转 WebP → 生成缩略图（_t.webp），列表/卡片不再加载原图。',
    'author' => '51普洱茶网',
    'license' => 'MIT',
    'requires' => ['core' => '1.0.0'],
    'toggle' => true,
    // 启用即加载：定义 ImgOpt 类，商城卡片缩略图助手（ImgOpt::thumb）依赖它
    'register_file' => 'lib.php',
    'admin' => ['label' => '图片优化', 'desc' => '本地化 / WebP / 缩略图'],
    'admin_file' => 'admin.php',
    'admin_fn' => 'yc_imgopt_admin',
];
