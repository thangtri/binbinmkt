// Lớp truy cập dữ liệu dùng chung cho MySQL (Hostinger) và SQLite (chạy thử trên máy).
// Chỉ dùng SQL chuẩn với placeholder `?` để chạy được trên cả hai.
const fs = require('fs');
const path = require('path');
const config = require('../config');

let impl = null;

function normalize(params) {
  return params.map((v) => {
    if (v === undefined) return null;
    if (typeof v === 'boolean') return v ? 1 : 0;
    return v;
  });
}

async function init() {
  if (impl) return impl;
  if (config.db.client === 'mysql') {
    const mysql = require('mysql2/promise');
    const pool = mysql.createPool({
      ...config.db.mysql,
      charset: 'utf8mb4',
      dateStrings: true,
      connectionLimit: 5,
      waitForConnections: true,
    });
    impl = {
      dialect: 'mysql',
      async all(sql, params = []) {
        const [rows] = await pool.query(sql, normalize(params));
        return rows;
      },
      async run(sql, params = []) {
        const [r] = await pool.query(sql, normalize(params));
        return { insertId: r.insertId, changes: r.affectedRows };
      },
      async exec(sql) {
        await pool.query(sql);
      },
    };
  } else {
    const { DatabaseSync } = require('node:sqlite');
    fs.mkdirSync(path.dirname(config.db.sqlitePath), { recursive: true });
    const db = new DatabaseSync(config.db.sqlitePath);
    db.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');
    impl = {
      dialect: 'sqlite',
      async all(sql, params = []) {
        return db.prepare(sql).all(...normalize(params));
      },
      async run(sql, params = []) {
        const r = db.prepare(sql).run(...normalize(params));
        return { insertId: Number(r.lastInsertRowid), changes: Number(r.changes) };
      },
      async exec(sql) {
        db.exec(sql);
      },
    };
  }
  return impl;
}

module.exports = {
  init,
  get dialect() {
    return impl.dialect;
  },
  all: (sql, params) => impl.all(sql, params),
  async get(sql, params) {
    const rows = await impl.all(sql, params);
    return rows[0] || null;
  },
  run: (sql, params) => impl.run(sql, params),
  exec: (sql) => impl.exec(sql),
  // Tạo chuỗi "?, ?, ?" cho mệnh đề IN
  placeholders: (n) => new Array(n).fill('?').join(', '),
};
