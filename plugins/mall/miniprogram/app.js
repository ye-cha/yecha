// 51普洱茶网 · 微信小程序
// 本地联调：baseUrl 指向 yecha-dev（https://yecha.org/mall/api）；微信开发者工具需勾选「不校验合法域名」。
// 上线时改为 https://你的域名/mall/api，并把登录的 dev:1 去掉改走真实 wx.login code。
const api = require('./utils/request.js');

App({
  globalData: {
    baseUrl: 'https://yecha.org/mall/api'
  },
  onLaunch() {
    this.ensureLogin();
  },
  // 静默登录：本地无商户号走 dev 回退；真实环境去掉 dev 用 code 换 openid
  ensureLogin() {
    const token = wx.getStorageSync('token');
    if (token) { return; }
    wx.login({
      success: (res) => {
        api.post('/login', { code: res.code, dev: 1 }).then((r) => {
          if (r.code === 0) {
            wx.setStorageSync('token', r.data.token);
            wx.setStorageSync('user', r.data.user);
          }
        }).catch(() => {});
      }
    });
  }
});
