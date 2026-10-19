const api = require('../../utils/request.js');

Page({
  data: { list: [], tabs: ['全部', '待付款', '待发货', '待收货', '已完成'], tab: 0 },
  onLoad(q) {
    if (q.status !== undefined) { this.setData({ tab: parseInt(q.status) }); }
  },
  onShow() { this.load(); },
  switchTab(e) {
    this.setData({ tab: parseInt(e.currentTarget.dataset.i) });
    this.load();
  },
  load() {
    const status = this.data.tab === 0 ? 'all' : (this.data.tab - 1);
    api.get('/order', { status }).then((r) => {
      if (r.code === 0) { this.setData({ list: r.data.list }); }
    });
  },
  goDetail(e) { wx.navigateTo({ url: '/pages/order/detail?no=' + e.currentTarget.dataset.no }); }
});
