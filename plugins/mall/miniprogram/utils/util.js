// 通用工具
function fmtPrice(v) {
  return (typeof v === 'number' ? v : parseFloat(v || 0)).toFixed(2);
}
function thumb(url, w) {
  return url || '';
}
// 时间格式化 YYYY-MM-DD HH:mm
function fmtTime(s) {
  if (!s) return '';
  s = s.replace('T', ' ');
  return s.slice(0, 16);
}
module.exports = { fmtPrice, thumb, fmtTime };
