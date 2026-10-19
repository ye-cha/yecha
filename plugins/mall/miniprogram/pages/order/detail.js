const api = require('../../utils/request.js');

Page({
  data: { no: '', o: {} },
  onLoad(q) {
    this.setData({ no: q.no });
    this.load();
  },
  onShow() { this.load(); },
  load() {
    api.get('/order/' + this.data.no).then((r) => {
      if (r.code === 0) { this.setData({ o: r.data }); }
    });
  },
  pay() {
    api.post('/order/pay', { order_no: this.data.no }).then((r) => {
      if (r.code !== 0) { wx.showToast({ title: r.msg, icon: 'none' }); return; }
      const d = r.data;
      if (d.sandbox) {
        api.get('/order/sim?no=' + this.data.no).then((rr) => {
          wx.showToast({ title: rr.msg || '已支付', icon: 'none' });
          this.load();
        });
      } else {
        const p = d.pay_params;
        wx.requestPayment({
          timeStamp: p.timeStamp, nonceStr: p.nonceStr, package: p.package,
          signType: p.signType, paySign: p.paySign,
          success: () => { wx.showToast({ title: '支付成功', icon: 'success' }); this.load(); },
          fail: () => { wx.showToast({ title: '已取消支付', icon: 'none' }); }
        });
      }
    });
  },
  confirm() {
    api.post('/order/confirm', { order_no: this.data.no }).then((r) => { wx.showToast({ title: r.msg, icon: 'none' }); this.load(); });
  },
  cancel() {
    wx.showModal({
      title: '提示', content: '确认取消该订单？', success: (res) => {
        if (res.confirm) { api.post('/order/cancel', { order_no: this.data.no }).then((r) => { wx.showToast({ title: r.msg, icon: 'none' }); this.load(); }); }
      }
    });
  }
});
