const api = require('../../utils/request.js');

Page({
  data: { list: [], total: 0 },
  onShow() { this.load(); },
  load() {
    api.get('/cart').then((r) => {
      if (r.code === 0) { this.setData({ list: r.data.list, total: r.data.total }); }
    });
  },
  changeNum(e) {
    const id = e.currentTarget.dataset.id;
    const d = parseInt(e.currentTarget.dataset.d);
    const item = this.data.list.find((x) => x.cart_id === id);
    if (!item) { return; }
    api.put('/cart', { cart_id: id, num: item.num + d }).then(() => this.load());
  },
  del(e) {
    const id = e.currentTarget.dataset.id;
    wx.showModal({
      title: '提示', content: '移除该商品？', success: (res) => {
        if (res.confirm) { api.del('/cart', { cart_id: id }).then(() => this.load()); }
      }
    });
  },
  goDetail(e) { wx.navigateTo({ url: '/pages/goods/detail?id=' + e.currentTarget.dataset.id }); },
  goCheckout() {
    if (this.data.list.length === 0) { wx.showToast({ title: '购物车是空的', icon: 'none' }); return; }
    wx.navigateTo({ url: '/pages/order/checkout' });
  }
});
