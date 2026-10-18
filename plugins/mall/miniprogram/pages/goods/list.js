const api = require('../../utils/request.js');

Page({
  data: { list: [], cat: 0, kw: '', page: 1, hasMore: true },
  onLoad(q) {
    this.setData({ cat: parseInt(q.category_id || 0), kw: q.keyword || '' });
    wx.setNavigationBarTitle({ title: this.data.cat ? '商品列表' : '全部商品' });
    this.load(true);
  },
  onSearch(e) {
    this.setData({ kw: e.detail.value });
  },
  doSearch() {
    this.load(true);
  },
  load(reset) {
    if (reset) { this.data.page = 1; }
    const page = this.data.page;
    api.get('/goods', { category_id: this.data.cat, keyword: this.data.kw, page }).then((r) => {
      if (r.code === 0) {
        this.setData({
          list: reset ? r.data.list : this.data.list.concat(r.data.list),
          hasMore: r.data.has_more,
          page: page + 1
        });
      }
    });
  },
  onReachBottom() { if (this.data.hasMore) { this.load(false); } },
  onPullDownRefresh() { this.load(true); wx.stopPullDownRefresh(); },
  goDetail(e) { wx.navigateTo({ url: '/pages/goods/detail?id=' + e.currentTarget.dataset.id }); }
});
