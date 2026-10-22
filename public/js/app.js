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

  // In trang (báo cáo → Lưu PDF)
  document.querySelectorAll('[data-print]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      window.print();
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

  // Form soạn bài: đếm ký tự, nhắc giới hạn 1.500 ký tự của Google Maps
  var form = document.querySelector('[data-post-form]');
  if (form) {
    var googleLimit = Number(form.getAttribute('data-google-limit')) || 1500;
    var caption = form.querySelector('[data-caption]');
    var counter = form.querySelector('[data-counter]');

    var updateCounter = function () {
      var n = caption.value.length;
      var over = n > googleLimit;
      counter.textContent =
        n.toLocaleString('vi-VN') + ' ký tự' + (over ? '. Google Maps chỉ nhận ' + googleLimit.toLocaleString('vi-VN') + ' ký tự: khi đăng lên Google, hãy rút gọn.' : '');
      counter.classList.toggle('is-over', over);
    };

    caption.addEventListener('input', updateCounter);
    updateCounter();

    var mediaInput = form.querySelector('[data-media-input]');
    var preview = form.querySelector('[data-media-preview]');
    if (mediaInput && preview) {
      mediaInput.addEventListener('change', function () {
        preview.innerHTML = '';
        var files = Array.prototype.slice.call(mediaInput.files);
        preview.hidden = !files.length;
        files.forEach(function (file, i) {
          var el = document.createElement(file.type.indexOf('video') === 0 ? 'video' : 'img');
          el.src = URL.createObjectURL(file);
          if (el.tagName === 'VIDEO') el.muted = true;
          else el.alt = 'File mới số ' + (i + 1);
          preview.appendChild(el);
        });
      });
    }

    // Nhờ AI viết bài: gửi ý chính, nhận 3 phương án, bấm để điền vào form
    var writer = form.querySelector('[data-ai-writer]');
    if (writer) {
      var runBtn = writer.querySelector('[data-ai-run]');
      var status = writer.querySelector('[data-ai-status]');
      var box = writer.querySelector('[data-ai-options]');
      var field = function (name) {
        return form.querySelector('[name="' + name + '"]');
      };
      runBtn.addEventListener('click', function () {
        var brief = writer.querySelector('[data-ai-brief]').value.trim();
        if (!brief) {
          status.textContent = 'Hãy nhập ý chính của bài trước.';
          return;
        }
        runBtn.disabled = true;
        runBtn.textContent = 'AI đang viết…';
        status.textContent = 'Đang viết 3 phương án, thường mất 20–40 giây.';
        box.innerHTML = '';
        fetch('/posts/ai/draft', {
          method: 'POST',
          headers: { 'content-type': 'application/json', 'x-csrf-token': writer.getAttribute('data-csrf'), accept: 'application/json' },
          body: JSON.stringify({
            brief: brief,
            tone: writer.querySelector('[data-ai-tone]').value,
            format: field('format').value,
            event_id: field('event_id').value,
            offer_id: field('offer_id') ? field('offer_id').value : '',
            current: caption.value,
          }),
        })
          .then(function (r) {
            return r.json().then(function (data) {
              if (!r.ok) throw new Error(data.error || 'AI chưa viết được, thử lại sau.');
              return data;
            });
          })
          .then(function (data) {
            status.textContent = 'Chọn một phương án để điền vào bài. Bạn vẫn sửa được sau khi điền.';
            data.options.forEach(function (o, i) {
              var card = document.createElement('div');
              card.className = 'ai-option';
              var h = document.createElement('p');
              h.className = 'ai-option-title';
              h.textContent = 'Phương án ' + (i + 1) + ': ' + o.title;
              var body = document.createElement('p');
              body.className = 'ai-option-text';
              body.textContent = o.caption;
              var tags = document.createElement('p');
              tags.className = 'tags';
              tags.textContent = o.hashtags;
              var use = document.createElement('button');
              use.type = 'button';
              use.className = 'btn btn-small';
              use.textContent = 'Dùng phương án này';
              use.addEventListener('click', function () {
                if (!field('title').value.trim()) field('title').value = o.title;
                caption.value = o.caption;
                field('hashtags').value = o.hashtags;
                updateCounter();
                status.textContent = 'Đã điền phương án ' + (i + 1) + '. Nhớ kiểm tra lại giá, ngày và thông tin liên hệ.';
                caption.focus();
              });
              card.appendChild(h);
              card.appendChild(body);
              card.appendChild(tags);
              card.appendChild(use);
              box.appendChild(card);
            });
          })
          .catch(function (err) {
            status.textContent = err.message;
          })
          .then(function () {
            runBtn.disabled = false;
            runBtn.textContent = 'Viết lại 3 phương án';
          });
      });
    }

    // Sắp xếp ảnh đã tải lên bằng nút mũi tên
    var grid = form.querySelector('[data-media-grid]');
    var orderInput = form.querySelector('[data-media-order]');
    if (grid && orderInput) {
      var renumber = function () {
        var items = grid.querySelectorAll('.media-item');
        orderInput.value = Array.prototype.map.call(items, function (li) {
          return li.getAttribute('data-id');
        }).join(',');
        items.forEach(function (li, i) {
          li.querySelector('[data-media-num]').textContent = i + 1;
        });
      };
      grid.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-move]');
        if (!btn) return;
        var li = btn.closest('.media-item');
        if (btn.getAttribute('data-move') === '-1' && li.previousElementSibling) grid.insertBefore(li, li.previousElementSibling);
        if (btn.getAttribute('data-move') === '1' && li.nextElementSibling) grid.insertBefore(li.nextElementSibling, li);
        renumber();
        btn.focus();
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
