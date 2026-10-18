<?php
/** 404（★ 精仿第 2 批 2026-10-05）
 * ===========================================================================
 * 参照侧：主域 docroot **没有** 404.html —— 它由 nginx 默认错误页兜底（9,496 B 的裸 nginx 页，
 * 无站点 header/footer）。实例侧是**站点自己的 404 页**（有完整外壳）。
 * ★ 站主裁决："参照那些是坏的/没有的"一律**只记录、不照抄**（报告 §⑤）。
 * ⇒ 本页保留实例自己的 404 语义与外壳，只把**文案与按钮形状**接到 www 的设计令牌上
 *   （`rounded-xl border bg-card shadow-sm` + `text-primary`），不再用老站的 `.empty` 老皮肤。
 * ★ 保留：HTTP 404 状态码（index.php 已发）· 首页回链 · 站头/页脚/搜索全部在场。
 */
?>
<div class="mx-auto max-w-3xl px-4 py-16 text-center">
  <p class="text-6xl font-bold tracking-tight text-muted-foreground/40">404</p>
  <h1 class="mt-4 text-2xl font-bold"><?= e(__('e404.msg')) ?></h1>
  <p class="mt-3 text-muted-foreground">
    <a class="text-primary hover:underline" href="/"><?= e(__('e404.back')) ?></a>
  </p>
  <div class="mt-8 flex flex-wrap items-center justify-center gap-2">
    <a class="inline-flex items-center gap-1.5 rounded-md border bg-card px-4 py-2 text-sm font-medium text-muted-foreground transition hover:border-primary hover:text-primary" href="/tool">在线工具箱</a>
    <a class="inline-flex items-center gap-1.5 rounded-md border bg-card px-4 py-2 text-sm font-medium text-muted-foreground transition hover:border-primary hover:text-primary" href="/tips">浏览器技巧</a>
    <a class="inline-flex items-center gap-1.5 rounded-md border bg-card px-4 py-2 text-sm font-medium text-muted-foreground transition hover:border-primary hover:text-primary" href="/bookmark">书签导航</a>
    <a class="inline-flex items-center gap-1.5 rounded-md border bg-card px-4 py-2 text-sm font-medium text-muted-foreground transition hover:border-primary hover:text-primary" href="/learn">浏览器学堂</a>
  </div>
</div>
