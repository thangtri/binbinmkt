// Gọi API bên ngoài: có giới hạn thời gian, lỗi trả về thông báo dễ đọc
class ConnectorError extends Error {
  constructor(message, { status, needsReconnect } = {}) {
    super(message);
    this.status = status;
    this.needsReconnect = Boolean(needsReconnect);
  }
}

async function request(url, { method = 'GET', headers = {}, body, form, json, timeout = 20000 } = {}) {
  const opts = { method, headers: { accept: 'application/json', ...headers }, signal: AbortSignal.timeout(timeout) };
  if (form) {
    opts.body = new URLSearchParams(form).toString();
    opts.headers['content-type'] = 'application/x-www-form-urlencoded';
  } else if (json !== undefined) {
    opts.body = JSON.stringify(json);
    opts.headers['content-type'] = 'application/json';
  } else if (body !== undefined) {
    opts.body = body;
  }
  let res;
  try {
    res = await fetch(url, opts);
  } catch (err) {
    throw new ConnectorError(err.name === 'TimeoutError' ? 'Máy chủ của kênh phản hồi quá chậm, thử lại sau.' : `Không kết nối được tới ${new URL(url).host}.`);
  }
  const text = await res.text();
  let data = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = null;
  }
  return { ok: res.ok, status: res.status, data, text };
}

module.exports = { request, ConnectorError };
