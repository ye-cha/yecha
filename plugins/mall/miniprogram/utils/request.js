// 统一请求封装：自动带 Bearer token，401 跳登录
function request(path, method, data) {
  return new Promise((resolve, reject) => {
    const token = wx.getStorageSync('token') || '';
    wx.request({
      url: getApp().globalData.baseUrl + path,
      method: method || 'GET',
      data: data || {},
      header: {
        'Content-Type': 'application/json',
        'Authorization': token ? 'Bearer ' + token : ''
      },
      success: (res) => {
        const body = res.data || {};
        if (body.code === 401) {
          wx.removeStorageSync('token');
          wx.showToast({ title: '请先登录', icon: 'none' });
          wx.navigateTo({ url: '/pages/user/user' });
          reject(body);
          return;
        }
        resolve(body);
      },
      fail: (err) => {
        wx.showToast({ title: '网络错误', icon: 'none' });
        reject(err);
      }
    });
  });
}

module.exports = {
  request,
  get: (p, d) => request(p, 'GET', d),
  post: (p, d) => request(p, 'POST', d),
  put: (p, d) => request(p, 'PUT', d),
  del: (p, d) => request(p, 'DELETE', d)
};
