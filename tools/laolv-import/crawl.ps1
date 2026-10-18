# laolv.org 全站文章抓取：sitemap → 清单 → 下载 raw HTML
$root = 'C:\Users\netbe\Documents\blog\data\laolv'
$raw = Join-Path $root 'raw'
New-Item -ItemType Directory -Force -Path $raw | Out-Null

$xml = [xml](Get-Content (Join-Path $root 'sitemap.xml') -Raw)
$urls = @()
foreach ($u in $xml.urlset.url) {
    $loc = $u.loc
    if ($loc -match '/post/([A-Za-z0-9\-_]+)$') {
        $slug = $Matches[1]
        $lastmod = ''
        if ($u.lastmod) { $lastmod = [string]$u.lastmod }
        $urls += [PSCustomObject]@{ Slug = $slug; Url = $loc; Lastmod = $lastmod }
    }
}
$urls = $urls | Sort-Object Slug -Unique
$manifest = $urls | ForEach-Object { "$($_.Slug)`t$($_.Url)`t$($_.Lastmod)" }
Set-Content -Path (Join-Path $root 'manifest.txt') -Value $manifest -Encoding utf8
Write-Output ("manifest posts: " + $urls.Count)

$i = 0; $ok = 0; $fail = 0
foreach ($u in $urls) {
    $file = Join-Path $raw ($u.Slug + '.html')
    if (Test-Path $file) { $ok++; continue }
    $i++
    try {
        Invoke-WebRequest -Uri $u.Url -UseBasicParsing -TimeoutSec 40 -Headers @{ 'User-Agent' = 'Mozilla/5.0 (YeCha-import/1.0)' } -OutFile $file
        $ok++
        Write-Output ("ok $i $($u.Slug)")
    } catch {
        $fail++
        Write-Output ("FAIL $i $($u.Slug) :: " + $_.Exception.Message)
    }
    Start-Sleep -Milliseconds 350
}
Write-Output ("download done ok=$ok fail=$fail total=" + $urls.Count)