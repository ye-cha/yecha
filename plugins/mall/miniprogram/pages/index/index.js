const api = require('../../utils/request.js');

Page({
  data: { cats: [], recommend: [], articles: [] },
  onShow() { this.load(); },
  load() {
    api.get('/home').then((r) => {
      if (r.code === 0) {
        this.setData({ cats: r.data.categories, recommend: r.data.recommend, articles: r.data.articles });
      }
    });
  },
  goGoods(e) { wx.navigateTo({ url: '/pages/goods/list?category_id=' + e.currentTarget.dataset.id }); },
  goDetail(e) { wx.navigateTo({ url: '/pages/goods/detail?id=' + e.currentTarget.dataset.id }); },
  goArticle(e) { wx.navigateTo({ url: '/pages/article/detail?id=' + e.currentTarget.dataset.id }); }
});
