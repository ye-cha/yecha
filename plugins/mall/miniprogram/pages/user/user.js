const api = require('../../utils/request.js');

Page({
  data: {
    user: {}, counts: { unpaid: 0, undelivered: 0, unreceived: 0, done: 0 }, fav: 0, addr: 0
  },
  onShow() { this.load(); },
  load() {
    api.get('/user').then((r) => {
      if (r.code === 0) {
        this.setData({ user: r.data.user, counts: r.data.order_counts, fav: r.data.favorite_count, addr: r.data.address_count });
      }
    });
  },
  onName(e) {
    const v = e.detail.value;
    this.setData({ 'user.display_name': v });
    api.put('/user/profile', { display_name: v });
  },
  goOrders(e) { wx.navigateTo({ url: '/pages/order/list?status=' + e.currentTarget.dataset.s }); },
  goAddress() { wx.navigateTo({ url: '/pages/user/address' }); }
});
