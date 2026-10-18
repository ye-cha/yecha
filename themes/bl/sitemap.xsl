<?xml version="1.0" encoding="UTF-8"?>
<!-- YeCha sitemap 可视化样式 -->
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9"
                xmlns:xhtml="http://www.w3.org/1999/xhtml">
  <xsl:output method="html" encoding="UTF-8" indent="yes"/>
  <xsl:template match="/">
<html lang="zh-CN"><head><meta charset="utf-8"/>
<title>站点地图 · YeCha</title>
<style>
body{margin:0;font:15px/1.8 -apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:#f5f5f3;color:#1f2328}
.wrap{max-width:900px;margin:34px auto;padding:0 20px}
h1{font-size:22px;border-bottom:2px solid #1f2328;padding-bottom:10px}
.note{color:#6f767e;font-size:13px;margin:-4px 0 18px}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e6e6e2;border-radius:10px;overflow:hidden;font-size:13.5px}
td,th{padding:7px 12px;border-bottom:1px solid #f1f1ee;text-align:left;vertical-align:top}
th{background:#f7f7f4;color:#6f767e;font-size:12px;font-weight:500}
a{color:#b3541e;text-decoration:none;word-break:break-all}
a:hover{text-decoration:underline}
.lang{font-size:11px;color:#2e6b34;white-space:nowrap}
.muted{color:#9a9a90}
</style></head><body><div class="wrap">
<h1>站点地图 · Sitemap</h1>
<p class="note">共 <xsl:value-of select="count(/sm:urlset/sm:url)"/> 个网址。</p>
<table><tr><th>URL</th><th>优先级</th><th>更新</th><th>语言版本（hreflang）</th></tr>
<xsl:for-each select="/sm:urlset/sm:url">
<tr>
<td><a href="{sm:loc}"><xsl:value-of select="sm:loc"/></a></td>
<td><xsl:value-of select="sm:priority"/></td>
<td><span class="muted"><xsl:value-of select="sm:lastmod"/></span></td>
<td><span class="lang">
<xsl:for-each select="xhtml:link">
<xsl:value-of select="@hreflang"/><xsl:if test="position() != last()"> · </xsl:if>
</xsl:for-each>
</span></td>
</tr>
</xsl:for-each>
</table>
</div></body></html>
  </xsl:template>
</xsl:stylesheet>
