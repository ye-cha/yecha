const api = require('../../utils/request.js');

Page({
  data: { list: [], page: 1, hasMore: true, tag: '' },
  onLoad(q) {
    this.setData({ tag: q.tag || '' });
    this.load(true);
  },
  load(reset) {
    if (reset) { this.data.page = 1; }
    const page = this.data.page;
    api.get('/article', { tag: this.data.tag, page }).then((r) => {
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
  goDetail(e) { wx.navigateTo({ url: '/pages/article/detail?id=' + e.currentTarget.dataset.id }); }
});
