(function () {
  'use strict';

  // Menu trên điện thoại
  var toggle = document.querySelector('[data-toggle-nav]');
  var side = document.getElementById('side');
  if (toggle && side) {
    toggle.addEventListener('click', function () {
      var open = side.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', function (e) {
      if (side.classList.contains('is-open') && !side.contains(e.target) && !toggle.contains(e.target)) {
        side.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // Xác nhận trước khi xoá / gửi; nút có data-busy đổi chữ và khoá khi đang chạy
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
      return;
    }
    var busy = e.target.querySelector('[data-busy]');
    if (busy) {
      busy.textContent = busy.getAttribute('data-busy');
      setTimeout(function () {
        busy.disabled = true;
      }, 0);
    }
  });

  // Bộ lọc tự gửi khi đổi lựa chọn
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () {
      el.form.submit();
    });
  });

  // Sao chép nội dung để dán lên kênh
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var label = btn.textContent;
      navigator.clipboard.writeText(text).then(
        function () {
          btn.textContent = 'Đã sao chép';
          setTimeout(function () {
            btn.textContent = label;
          }, 1800);
        },
        function () {
          window.prompt('Sao chép nội dung:', text);
        }
      );
    });
  });

  // Form soạn bài: đổi kênh → đổi định dạng, gợi ý, giới hạn ký tự
  var form = document.querySelector('[data-post-form]');
  if (form) {
    var formats = JSON.parse(form.getAttribute('data-formats'));
    var limits = JSON.parse(form.getAttribute('data-limits'));
    var tips = JSON.parse(form.getAttribute('data-tips'));
    var caption = form.querySelector('[data-caption]');
    var counter = form.querySelector('[data-counter]');
    var formatSel = form.querySelector('[data-format]');
    var tip = form.querySelector('[data-tip]');

    var channel = function () {
      var c = form.querySelector('input[name="channel"]:checked');
      return c ? c.value : '';
    };

    var updateCounter = function () {
      var n = caption.value.length;
      var limit = limits[channel()] || 0;
      counter.textContent = limit ? n.toLocaleString('vi-VN') + ' / ' + limit.toLocaleString('vi-VN') + ' ký tự' : n.toLocaleString('vi-VN') + ' ký tự';
      counter.classList.toggle('is-over', Boolean(limit && n > limit));
    };

    var updateChannel = function () {
      var ch = channel();
      var keep = formatSel.value || formatSel.getAttribute('data-current');
      formatSel.innerHTML = '';
      (formats[ch] || []).forEach(function (f) {
        var o = document.createElement('option');
        o.textContent = f;
        if (f === keep) o.selected = true;
        formatSel.appendChild(o);
      });
      tip.textContent = tips[ch] || '';
      updateCounter();
    };

    form.querySelectorAll('input[name="channel"]').forEach(function (r) {
      r.addEventListener('change', updateChannel);
    });
    caption.addEventListener('input', updateCounter);
    updateCounter();

    var mediaInput = form.querySelector('[data-media-input]');
    var preview = form.querySelector('[data-media-preview]');
    if (mediaInput && preview) {
      mediaInput.addEventListener('change', function () {
        preview.innerHTML = '';
        var file = mediaInput.files[0];
        if (!file) {
          preview.hidden = true;
          return;
        }
        var url = URL.createObjectURL(file);
        var el = document.createElement(file.type.indexOf('video') === 0 ? 'video' : 'img');
        el.src = url;
        if (el.tagName === 'VIDEO') el.controls = true;
        else el.alt = 'Xem trước file đã chọn';
        preview.appendChild(el);
        preview.hidden = false;
      });
    }
  }

  // Bảng kênh trên màn hình hẹp: cuộn tới cột hôm nay
  var scroller = document.querySelector('.board-scroll');
  var todayCol = scroller && scroller.querySelector('.b-day.is-today');
  if (todayCol && window.matchMedia('(max-width: 860px)').matches) {
    var label = scroller.querySelector('.b-corner');
    scroller.scrollLeft =
      todayCol.getBoundingClientRect().left - scroller.getBoundingClientRect().left - (label ? label.offsetWidth : 0);
  }

  // Lịch: kéo bài sang ngày khác
  var board = document.querySelector('[data-board]');
  if (board) {
    var csrf = board.getAttribute('data-csrf');
    var dragging = null;

    board.addEventListener('dragstart', function (e) {
      var chip = e.target.closest && e.target.closest('[data-post]');
      if (!chip) return;
      dragging = chip;
      chip.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', chip.getAttribute('data-post'));
    });
    board.addEventListener('dragend', function () {
      if (dragging) dragging.classList.remove('is-dragging');
      dragging = null;
      board.querySelectorAll('.is-drop').forEach(function (c) {
        c.classList.remove('is-drop');
      });
    });

    var cellOf = function (el) {
      return el.closest && el.closest('[data-date]');
    };
    board.addEventListener('dragover', function (e) {
      var cell = cellOf(e.target);
      if (!dragging || !cell) return;
      // Bảng kênh: chỉ đổi ngày, không đổi kênh
      var ownRow = dragging.closest('[data-channel]');
      if (cell.hasAttribute('data-channel') && ownRow && cell.getAttribute('data-channel') !== ownRow.getAttribute('data-channel')) return;
      e.preventDefault();
      board.querySelectorAll('.is-drop').forEach(function (c) {
        if (c !== cell) c.classList.remove('is-drop');
      });
      cell.classList.add('is-drop');
    });
    board.addEventListener('drop', function (e) {
      var cell = cellOf(e.target);
      if (!dragging || !cell) return;
      e.preventDefault();
      var chip = dragging;
      var from = cellOf(chip);
      if (from === cell) return;
      var add = cell.querySelector('.b-add');
      var more = cell.querySelector('.m-more');
      cell.insertBefore(chip, more || add || null);
      fetch('/posts/' + chip.getAttribute('data-post') + '/reschedule', {
        method: 'POST',
        headers: { 'content-type': 'application/json', 'x-csrf-token': csrf, accept: 'application/json' },
        body: JSON.stringify({ date: cell.getAttribute('data-date') }),
      })
        .then(function (r) {
          return r.json().then(function (data) {
            if (!r.ok) throw new Error(data.error || 'Không đổi được ngày.');
          });
        })
        .catch(function (err) {
          from.insertBefore(chip, from.querySelector('.m-more') || from.querySelector('.b-add') || null);
          window.alert(err.message);
        });
    });
  }
})();
