<?xml version="1.0" encoding="UTF-8"?>
<!-- ChaWiki feed 可视化样式：浏览器直接看 RSS 时给出可读列表 -->
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
  <xsl:output method="html" encoding="UTF-8" indent="yes"/>
  <xsl:template match="/rss/channel">
<html lang="zh-CN"><head><meta charset="utf-8"/>
<title><xsl:value-of select="title"/> · RSS</title>
<style>
body{margin:0;font:15px/1.8 -apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:#fff;color:#22301F}
.wrap{max-width:860px;margin:36px auto;padding:0 20px}
h1{font-size:22px;margin:0 0 4px;color:#1C2A19}
.sub{color:#6B7A66;font-size:13px;margin:0 0 20px}
.item{display:block;padding:13px 4px;border-bottom:1px solid #E3EAE0;text-decoration:none;color:inherit}
.item:last-child{border-bottom:0}
.item:hover{background:#EEF6EC}
.t{font-size:16px;font-weight:600;color:#2F7D46;margin:0 0 3px}
.meta{color:#93A08E;font-size:12px;margin:0 0 4px}
.d{color:#5A6655;font-size:13.5px;line-height:1.8;margin:0;overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical}
</style></head><body><div class="wrap">
<h1><xsl:value-of select="title"/></h1>
<p class="sub"><a href="{link}"><xsl:value-of select="link"/></a> · <xsl:value-of select="lastBuildDate"/></p>
<xsl:for-each select="item">
<a class="item" href="{link}">
<p class="t"><xsl:value-of select="title"/></p>
<p class="meta"><xsl:value-of select="pubDate"/></p>
<p class="d"><xsl:value-of select="description"/></p>
</a>
</xsl:for-each>
</div></body></html>
  </xsl:template>
</xsl:stylesheet>
