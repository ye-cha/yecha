const api = require('../../utils/request.js');

Page({
  data: { source: 'cart', items: [], addr: null, remark: '', total: 0, buyNow: null },
  onShow() {
    const p = wx.getStorageSync('pickAddr');
    if (p) { this.setData({ addr: p }); wx.removeStorageSync('pickAddr'); }
  },
  onLoad() {
    const buy = wx.getStorageSync('buyNow');
    if (buy && buy.sku_id) {
      this.setData({
        source: 'buy', buyNow: buy,
        items: [{ title: buy.title, cover: buy.cover, spec_text: buy.spec_text, price: buy.price, num: buy.num }],
        total: (parseFloat(buy.price) * parseInt(buy.num)).toFixed(2)
      });
    }
    this.loadAddr();
    if (this.data.source === 'cart') { this.loadCart(); }
  },
  loadAddr() {
    api.get('/user/address').then((r) => {
      if (r.code === 0) {
        const list = r.data.list;
        this.setData({ addresses: list, addr: list.find((a) => a.is_default) || list[0] || null });
      }
    });
  },
  loadCart() {
    api.get('/cart').then((r) => {
      if (r.code === 0) {
        this.setData({
          items: r.data.list.map((x) => ({ title: x.title, cover: x.cover, spec_text: x.spec_text, price: x.price, num: x.num })),
          total: parseFloat(r.data.total).toFixed(2)
        });
      }
    });
  },
  chooseAddr() { wx.navigateTo({ url: '/pages/user/address?pick=1' }); },
  onRemark(e) { this.setData({ remark: e.detail.value }); },
  submit() {
    const addr = this.data.addr;
    if (!addr) { wx.showToast({ title: '请选择收货地址', icon: 'none' }); return; }
    const base = { address_id: addr.id, remark: this.data.remark };
    const payload = this.data.source === 'buy'
      ? Object.assign({ sku_id: this.data.buyNow.sku_id, num: this.data.buyNow.num }, base)
      : base;
    api.post('/order', payload).then((r) => {
      if (r.code !== 0) { wx.showToast({ title: r.msg, icon: 'none' }); return; }
      wx.removeStorageSync('buyNow');
      const no = r.data.order_no;
      api.post('/order/pay', { order_no: no }).then((pr) => {
        if (pr.code !== 0) { wx.showToast({ title: pr.msg, icon: 'none' }); wx.redirectTo({ url: '/pages/order/detail?no=' + no }); return; }
        const d = pr.data;
        if (d.sandbox) {
          api.get('/order/sim?no=' + no).then(() => { wx.redirectTo({ url: '/pages/order/detail?no=' + no }); });
        } else {
          const p = d.pay_params;
          wx.requestPayment({
            timeStamp: p.timeStamp, nonceStr: p.nonceStr, package: p.package, signType: p.signType, paySign: p.paySign,
            success: () => { wx.redirectTo({ url: '/pages/order/detail?no=' + no }); },
            fail: () => { wx.redirectTo({ url: '/pages/order/detail?no=' + no }); }
          });
        }
      });
    });
  }
});
