const api = require('../../utils/request.js');

Page({
  data: { id: 0, a: {}, comments: [], input: '' },
  onLoad(q) {
    this.setData({ id: parseInt(q.id) });
    this.load();
  },
  load() {
    api.get('/article/' + this.data.id).then((r) => {
      if (r.code === 0) {
        this.setData({ a: r.data, comments: r.data.comments });
        wx.setNavigationBarTitle({ title: r.data.title });
      }
    });
  },
  onInput(e) { this.setData({ input: e.detail.value }); },
  submit() {
    const body = (this.data.input || '').trim();
    if (body.length < 2) { wx.showToast({ title: '内容太短', icon: 'none' }); return; }
    api.post('/comment', { content_id: this.data.id, body }).then((r) => {
      wx.showToast({ title: r.msg || '已提交', icon: 'none' });
      if (r.code === 0) { this.setData({ input: '' }); this.load(); }
    });
  }
});
