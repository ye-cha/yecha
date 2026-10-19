const api = require('../../utils/request.js');

Page({
  data: { id: 0, g: {}, skus: [], skuIdx: 0, num: 1 },
  onLoad(q) {
    this.setData({ id: parseInt(q.id) });
    this.load();
  },
  load() {
    api.get('/goods/' + this.data.id).then((r) => {
      if (r.code === 0) {
        this.setData({ g: r.data, skus: r.data.skus });
        wx.setNavigationBarTitle({ title: r.data.title });
      } else {
        wx.showToast({ title: r.msg, icon: 'none' });
      }
    });
  },
  selSku(e) { this.setData({ skuIdx: parseInt(e.currentTarget.dataset.i) }); },
  changeNum(e) {
    let n = this.data.num + parseInt(e.currentTarget.dataset.d);
    if (n < 1) { n = 1; }
    if (n > 99) { n = 99; }
    this.setData({ num: n });
  },
  addCart() {
    const sku = this.data.skus[this.data.skuIdx];
    if (!sku) { return; }
    api.post('/cart', { sku_id: sku.id, num: this.data.num }).then((r) => {
      wx.showToast({ title: r.msg || '已加入购物车', icon: 'none' });
    });
  },
  buyNow() {
    const sku = this.data.skus[this.data.skuIdx];
    if (!sku) { return; }
    wx.setStorageSync('buyNow', {
      sku_id: sku.id, num: this.data.num,
      title: this.data.g.title, cover: this.data.g.cover,
      price: sku.price, spec_text: sku.spec_text
    });
    wx.navigateTo({ url: '/pages/order/checkout' });
  }
});
