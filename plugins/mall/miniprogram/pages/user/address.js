const api = require('../../utils/request.js');

Page({
  data: {
    list: [], pick: false,
    form: { id: 0, name: '', mobile: '', province: '', city: '', county: '', address_detail: '', is_default: 0 },
    editing: false
  },
  onLoad(q) { this.setData({ pick: q.pick === '1' }); },
  onShow() { this.load(); },
  load() {
    api.get('/user/address').then((r) => { if (r.code === 0) { this.setData({ list: r.data.list }); } });
  },
  pickAddr(e) {
    if (!this.data.pick) { return; }
    const id = e.currentTarget.dataset.id;
    const a = this.data.list.find((x) => x.id === id);
    wx.setStorageSync('pickAddr', a);
    wx.navigateBack();
  },
  add() {
    this.setData({ editing: true, form: { id: 0, name: '', mobile: '', province: '', city: '', county: '', address_detail: '', is_default: 0 } });
  },
  edit(e) {
    const id = e.currentTarget.dataset.id;
    const a = this.data.list.find((x) => x.id === id);
    this.setData({ editing: true, form: a });
  },
  onField(e) {
    const f = e.currentTarget.dataset.f;
    this.setData({ ['form.' + f]: e.detail.value });
  },
  save() {
    const f = this.data.form;
    if (!f.name || !f.mobile) { wx.showToast({ title: '请填写姓名与手机号', icon: 'none' }); return; }
    api.post('/user/address', f).then((r) => {
      wx.showToast({ title: r.msg, icon: 'none' });
      if (r.code === 0) { this.setData({ editing: false }); this.load(); }
    });
  },
  del(e) {
    const id = e.currentTarget.dataset.id;
    wx.showModal({
      title: '提示', content: '删除该地址？', success: (res) => {
        if (res.confirm) { api.del('/user/address', { id }).then(() => this.load()); }
      }
    });
  },
  setDefault(e) {
    api.put('/user/address', { id: e.currentTarget.dataset.id }).then(() => this.load());
  },
  back() { this.setData({ editing: false }); }
});
