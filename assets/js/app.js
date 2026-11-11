/* MyDentist – shared front-end behaviour */
(function () {
  'use strict';

  // Mobile sidebar
  document.querySelectorAll('[data-sidebar-toggle]').forEach(function (b) {
    b.addEventListener('click', function () { document.body.classList.toggle('sidebar-open'); });
  });
  document.querySelectorAll('[data-sidebar-close]').forEach(function (b) {
    b.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); });
  });

  // Keep the browser from filling in saved emails, usernames and passwords:
  // these fields start read-only (so the browser skips them) and unlock the moment you use them.
  document.querySelectorAll('input[data-noautofill]').forEach(function (inp) {
    function unlock() { inp.removeAttribute('readonly'); }
    ['pointerdown', 'touchstart', 'focus', 'keydown'].forEach(function (ev) { inp.addEventListener(ev, unlock); });
    if (document.activeElement === inp) unlock();
  });
  document.querySelectorAll('label[for]').forEach(function (lb) {
    lb.addEventListener('click', function () {
      var t = document.getElementById(lb.htmlFor);
      if (t && t.hasAttribute('data-noautofill')) t.removeAttribute('readonly');
    });
  });

  // Show / hide password
  document.querySelectorAll('.toggle-pass').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = btn.parentElement.querySelector('input');
      if (input.hasAttribute('data-noautofill')) input.removeAttribute('readonly');
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
    });
  });

  // Confirmation prompts: <form data-confirm="Are you sure?">
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    var msg = f.getAttribute('data-confirm') || (ev.submitter && ev.submitter.getAttribute('data-confirm'));
    if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
    // prevent double submit
    if (!ev.defaultPrevented && f.method && f.method.toLowerCase() === 'post' && !f.hasAttribute('data-allow-resubmit')) {
      setTimeout(function () {
        f.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; });
      }, 0);
    }
  });

  // Client-side Bootstrap validation
  document.querySelectorAll('form.needs-validation').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!form.checkValidity()) { ev.preventDefault(); ev.stopImmediatePropagation(); }
      form.classList.add('was-validated');
    }, true);
  });

  // Password rules (uppercase, number, special character, 8+ chars) and confirmation
  var pw = document.querySelector('[data-password]');
  var pw2 = document.querySelector('[data-password-confirm]');
  var rulesBox = document.querySelector('[data-pw-rules]');
  var matchBox = document.querySelector('[data-pw-match]');
  var RULES = {
    length: function (v) { return v.length >= 8; },
    upper: function (v) { return /[A-Z]/.test(v); },
    number: function (v) { return /[0-9]/.test(v); },
    special: function (v) { return /[^A-Za-z0-9\s]/.test(v); }
  };
  function checkPassword() {
    if (!pw) return;
    var v = pw.value, allOk = true;
    Object.keys(RULES).forEach(function (k) {
      var ok = RULES[k](v);
      if (!ok) allOk = false;
      if (rulesBox) {
        var li = rulesBox.querySelector('[data-rule="' + k + '"]');
        if (li) {
          li.classList.toggle('ok', ok);
          li.classList.toggle('bad', !ok && v.length > 0);
        }
      }
    });
    if (rulesBox) pw.setCustomValidity(allOk ? '' : 'Password does not meet the requirements');
    // Field colour: red while a rule is missing, green when every rule is met
    if (rulesBox) {
      pw.classList.toggle('is-invalid', v !== '' && !allOk);
      pw.classList.toggle('is-valid', v !== '' && allOk);
    }
    if (pw2) {
      var mismatch = pw2.value !== '' && pw2.value !== v;
      pw2.setCustomValidity(mismatch ? 'Passwords do not match' : '');
      // Confirm field: red when it does not match, green when it matches
      pw2.classList.toggle('is-invalid', mismatch);
      pw2.classList.toggle('is-valid', pw2.value !== '' && !mismatch);
      if (matchBox) {
        matchBox.textContent = pw2.value === '' ? '' : (mismatch ? matchBox.dataset.bad : matchBox.dataset.ok);
        matchBox.className = 'pw-match small mt-1 ' + (pw2.value === '' ? '' : (mismatch ? 'text-danger' : 'text-success'));
      }
    }
  }
  if (pw) { pw.addEventListener('input', checkPassword); }
  if (pw2) { pw2.addEventListener('input', checkPassword); }
  checkPassword();

  // Auto-hide success alerts
  setTimeout(function () {
    document.querySelectorAll('.alert-success.alert-dismissible').forEach(function (a) {
      if (window.bootstrap) { bootstrap.Alert.getOrCreateInstance(a).close(); }
    });
  }, 6000);
})();

/**
 * Slot picker used by booking, rescheduling and staff booking.
 * opts: { root, serviceInput(fn), dentistInput(fn), dateInput, timeInput, exclude, mode, preselectDate, onChange }
 */
window.SlotPicker = function (opts) {
  var root = opts.root;
  var strip = root.querySelector('[data-days]');
  var grid = root.querySelector('[data-times]');
  var note = root.querySelector('[data-slot-note]');
  var dateOther = root.querySelector('[data-date-other]');
  var state = { date: opts.dateInput.value || opts.preselectDate || '', time: opts.timeInput.value || '' };
  var I = window.I18N || { days: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], daysLong: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], monthsLong: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'], s: {} };
  function tr(k) { return (I.s && I.s[k]) || k; }

  function api(params) {
    var q = new URLSearchParams(params);
    q.set('service_id', opts.serviceInput());
    q.set('dentist_id', opts.dentistInput());
    if (opts.exclude) q.set('exclude', opts.exclude);
    if (opts.mode) q.set('mode', opts.mode);
    return fetch(window.APP.base + '/api/slots.php?' + q.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  function fmtTime(t) {
    var p = t.split(':'), h = +p[0], ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    return h + ':' + p[1] + ' ' + ampm;
  }

  // Month calendar: click a date to see its open times underneath
  var month = '';
  function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function loadDays(from) {
    var target = (from || state.date || '').slice(0, 7) || month || ymd(new Date()).slice(0, 7);
    month = target;
    strip.className = 'cal';
    strip.innerHTML = '<div class="text-muted small py-3"><span class="spinner-border spinner-border-sm me-2"></span>' + tr('Checking availability…') + '</div>';
    return api({ action: 'month', month: target }).then(function (res) {
      month = res.month || target;
      var first = new Date(month + '-01T00:00:00');
      var head = '<div class="cal-head">'
        + '<button type="button" class="cal-nav" data-cal="' + (res.prev || '') + '"' + (res.prev ? '' : ' disabled') + ' aria-label="' + tr('Previous month') + '">&#8249;</button>'
        + '<div class="cal-title">' + I.monthsLong[first.getMonth()] + ' ' + first.getFullYear() + '</div>'
        + '<button type="button" class="cal-nav" data-cal="' + (res.next || '') + '"' + (res.next ? '' : ' disabled') + ' aria-label="' + tr('Next month') + '">&#8250;</button></div>';
      var html = '<div class="cal-grid">';
      I.days.forEach(function (d) { html += '<div class="cal-dow">' + d + '</div>'; });
      for (var i = 0; i < first.getDay(); i++) html += '<div></div>';
      (res.days || []).forEach(function (d) {
        var open = d.state === 'open';
        html += '<button type="button" class="cal-day ' + d.state + (d.date === state.date ? ' selected' : '') + (d.date === ymd(new Date()) ? ' today' : '') + '"'
          + ' data-date="' + d.date + '"' + (open ? '' : ' disabled')
          + ' title="' + (open ? d.slots + ' ' + tr('open') : (d.state === 'full' ? tr('Full') : (d.state === 'closed' ? tr('Closed') : ''))) + '">'
          + '<span class="num">' + (+d.date.slice(8)) + '</span>'
          + (open ? '<span class="dot"></span>' : '') + '</button>';
      });
      html += '</div>';
      var legend = '<div class="cal-legend"><span><i class="dot"></i> ' + tr('Has open times') + '</span><span><i class="sq"></i> ' + tr('Full or closed') + '</span></div>';
      strip.innerHTML = head + html + legend;
      strip.querySelectorAll('.cal-nav').forEach(function (b) {
        b.addEventListener('click', function () { if (b.dataset.cal) loadDays(b.dataset.cal); });
      });
      strip.querySelectorAll('.cal-day.open').forEach(function (b) {
        b.addEventListener('click', function () { selectDate(b.dataset.date); });
      });
      var sel = (res.days || []).find(function (d) { return d.date === state.date; });
      if (sel && sel.state === 'open') selectDate(state.date, true);
      else if (state.date && state.date.slice(0, 7) === month) { state.date = ''; opts.dateInput.value = ''; state.time = ''; opts.timeInput.value = ''; showPrompt(); if (opts.onChange) opts.onChange(state); }
      else if (!state.date) showPrompt();
    });
  }
  function showPrompt() {
    if (note) note.textContent = '';
    grid.innerHTML = '<div class="cal-prompt"><i class="bi bi-hand-index"></i> ' + tr('Click a date to see the open times.') + '</div>';
  }

  function selectDate(date, keepTime) {
    state.date = date;
    opts.dateInput.value = date;
    if (!keepTime) { state.time = ''; opts.timeInput.value = ''; }
    strip.querySelectorAll('.cal-day').forEach(function (c) { c.classList.toggle('selected', c.dataset.date === date); });
    if (dateOther) dateOther.value = date;
    grid.innerHTML = '<div class="text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>' + tr('Loading times…') + '</div>';
    api({ action: 'slots', date: date }).then(function (res) {
      var dt = new Date(date + 'T00:00:00');
      grid.innerHTML = '<div class="times-head">' + tr('Open times on') + ' ' + I.daysLong[dt.getDay()] + ', ' + I.monthsLong[dt.getMonth()] + ' ' + dt.getDate() + '</div>';
      if (note) note.textContent = res.message || '';
      (res.slots || []).forEach(function (s) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'time-btn' + (s.time === state.time ? ' selected' : '');
        b.textContent = fmtTime(s.time);
        b.dataset.time = s.time;
        b.addEventListener('click', function () {
          state.time = s.time;
          opts.timeInput.value = s.time;
          grid.querySelectorAll('.time-btn').forEach(function (x) { x.classList.toggle('selected', x === b); });
          if (opts.onChange) opts.onChange(state);
        });
        grid.appendChild(b);
      });
      if (!res.slots || !res.slots.length) {
        grid.innerHTML = '<div class="empty-state py-3"><i class="bi bi-calendar-x"></i>' + tr('No available times on this date. Please choose another day.') + '</div>';
        state.time = ''; opts.timeInput.value = '';
      }
      if (state.time && !(res.slots || []).some(function (s) { return s.time === state.time; })) { state.time = ''; opts.timeInput.value = ''; }
      if (opts.onChange) opts.onChange(state);
    });
  }

  if (dateOther) {
    dateOther.addEventListener('change', function () {
      if (!dateOther.value) return;
      state.date = dateOther.value;
      loadDays(dateOther.value);
    });
  }

  return {
    reload: function (from) { return loadDays(from); },
    state: state,
    fmtTime: fmtTime
  };
};
