/* قطعه‌رسان — اسکریپت رابط کاربری */
(function () {
  'use strict';

  // ── منوی موبایل ───────────────────────────────────────
  var btn = document.getElementById('menuBtn');
  var side = document.querySelector('.side');
  if (btn && side) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      side.classList.toggle('open');
    });
    document.addEventListener('click', function (e) {
      if (side.classList.contains('open') &&
          !side.contains(e.target) && e.target !== btn) {
        side.classList.remove('open');
      }
    });
  }

  // ── تبدیل ارقام فارسی به لاتین در ورودی‌های عددی ──────
  var FA = '۰۱۲۳۴۵۶۷۸۹', AR = '٠١٢٣٤٥٦٧٨٩';
  function toEn(s) {
    return String(s).replace(/[۰-۹٠-٩]/g, function (c) {
      var i = FA.indexOf(c);
      if (i > -1) return i;
      return AR.indexOf(c);
    });
  }

  // ── فرمت مبلغ با جداکننده ─────────────────────────────
  function fmt(v) {
    v = toEn(v).replace(/[^\d]/g, '');
    if (!v) return '';
    return v.replace(/\B(?=(\d{3})+(?!\d))/g, '\u066C');
  }
  function unfmt(v) {
    return toEn(v).replace(/[^\d]/g, '');
  }

  document.querySelectorAll('input.money').forEach(function (inp) {
    // مقدار اولیه
    if (inp.value) inp.value = fmt(inp.value);
    inp.setAttribute('inputmode', 'numeric');
    inp.classList.add('money-in');

    inp.addEventListener('input', function () {
      var pos = inp.value.length - inp.selectionStart;
      inp.value = fmt(inp.value);
      var np = inp.value.length - pos;
      try { inp.setSelectionRange(np, np); } catch (e) {}
      recalc();
    });
    // قبل از ارسال، فرمت را بردار
    if (inp.form) {
      inp.form.addEventListener('submit', function () {
        inp.value = unfmt(inp.value);
      });
    }
  });

  // ── محاسبهٔ زندهٔ سود در فرم کالا ─────────────────────
  function recalc() {
    var buy = document.querySelector('[data-calc="buy"]');
    var sell = document.querySelector('[data-calc="sell"]');
    var out = document.querySelector('[data-calc="out"]');
    if (!buy || !sell || !out) return;

    var b = parseInt(unfmt(buy.value) || '0', 10);
    var s = parseInt(unfmt(sell.value) || '0', 10);
    if (!b && !s) { out.innerHTML = '<span class="muted small">قیمت خرید و فروش را وارد کنید</span>'; return; }

    var g = s - b;
    var p = b > 0 ? (g / b) * 100 : 0;
    var cls = g < 0 ? 'neg' : (g > 0 ? 'pos' : 'muted');
    var sign = g < 0 ? '−' : '';
    var abs = Math.abs(g).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u066C');

    var html = '<span class="' + cls + '">سود: ' + sign + faD(abs) + ' تومان</span>';
    if (b > 0) html += ' <span class="muted small">· حاشیه ' + faD(p.toFixed(1)) + '٪</span>';
    if (g < 0) html += '<div class="tiny neg" style="margin-top:3px">قیمت فروش از خرید کمتر است</div>';
    out.innerHTML = html;
  }
  function faD(s) {
    return String(s).replace(/[0-9]/g, function (d) { return FA[+d]; });
  }
  recalc();

  // ── محاسبهٔ زندهٔ سود سفارش ───────────────────────────
  function recalcOrder() {
    var box = document.getElementById('orderCalc');
    if (!box) return;
    var rev = 0, cost = 0;
    document.querySelectorAll('[data-item]').forEach(function (row) {
      var qty = parseInt(unfmt((row.querySelector('[data-f=qty]') || {}).value || '0'), 10) || 0;
      var sp = parseInt(unfmt((row.querySelector('[data-f=sell]') || {}).value || '0'), 10) || 0;
      var bp = parseInt(unfmt((row.querySelector('[data-f=buy]') || {}).value || '0'), 10) || 0;
      rev += qty * sp; cost += qty * bp;
    });
    function val(id) {
      var el = document.getElementById(id);
      return el ? (parseInt(unfmt(el.value) || '0', 10) || 0) : 0;
    }
    var exp = cost + val('gateway_fee') + val('shipping_cost') + val('packaging_cost');
    var net = rev - exp;
    var mg = rev > 0 ? (net / rev * 100) : 0;
    function f(n) {
      var s = Math.abs(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u066C');
      return (n < 0 ? '−' : '') + faD(s);
    }
    box.innerHTML =
      '<div class="stats g4" style="margin:0">' +
      '<div class="stat"><div class="lb">فروش</div><div class="vl sm">' + f(rev) + '</div></div>' +
      '<div class="stat"><div class="lb">بهای کالا</div><div class="vl sm">' + f(cost) + '</div></div>' +
      '<div class="stat"><div class="lb">جمع هزینه</div><div class="vl sm">' + f(exp) + '</div></div>' +
      '<div class="stat ' + (net < 0 ? 'neg' : 'pos') + '"><div class="lb">سود خالص</div><div class="vl sm">' +
        f(net) + '</div><div class="hint">حاشیه ' + faD(mg.toFixed(1)) + '٪</div></div>' +
      '</div>' +
      (net < 0 ? '<div class="note n-red" style="margin:12px 0 0"><b>این سفارش ضررده است</b>' +
                 'مجموع هزینه‌ها از مبلغ فروش بیشتر است.</div>' : '');
  }
  document.addEventListener('input', function (e) {
    if (e.target.closest('#orderForm')) recalcOrder();
  });
  recalcOrder();

  // ── افزودن ردیف قلم سفارش ─────────────────────────────
  var addBtn = document.getElementById('addItem');
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      var tpl = document.getElementById('itemTpl');
      var body = document.getElementById('itemsBody');
      if (!tpl || !body) return;
      var idx = body.querySelectorAll('[data-item]').length;
      var html = tpl.innerHTML.replace(/__i__/g, idx);
      var tr = document.createElement('tr');
      tr.setAttribute('data-item', '');
      tr.innerHTML = html;
      body.appendChild(tr);
      bindRow(tr);
      recalcOrder();
    });
  }

  function bindRow(row) {
    // انتخاب کالا → پر کردن خودکار قیمت
    var sel = row.querySelector('[data-f=product]');
    if (sel) {
      sel.addEventListener('change', function () {
        var o = sel.options[sel.selectedIndex];
        if (!o || !o.value) return;
        var b = row.querySelector('[data-f=buy]');
        var s = row.querySelector('[data-f=sell]');
        if (b && o.dataset.buy) b.value = fmt(o.dataset.buy);
        if (s && o.dataset.sell) s.value = fmt(o.dataset.sell);
        recalcOrder();
      });
    }
    // حذف ردیف
    var del = row.querySelector('[data-f=del]');
    if (del) {
      del.addEventListener('click', function () {
        row.remove();
        recalcOrder();
      });
    }
    // فرمت مبلغ
    row.querySelectorAll('input.money').forEach(function (inp) {
      inp.classList.add('money-in');
      inp.setAttribute('inputmode', 'numeric');
      if (inp.value) inp.value = fmt(inp.value);
      inp.addEventListener('input', function () {
        inp.value = fmt(inp.value);
      });
    });
  }
  document.querySelectorAll('[data-item]').forEach(bindRow);

  // قبل از ارسال فرم سفارش، همهٔ مبلغ‌ها را بدون فرمت کن
  var of = document.getElementById('orderForm');
  if (of) {
    of.addEventListener('submit', function () {
      of.querySelectorAll('input.money').forEach(function (i) { i.value = unfmt(i.value); });
    });
  }

  // ── تأیید حذف ─────────────────────────────────────────
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!window.confirm(el.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // ── چک‌باکس استایل‌دار ────────────────────────────────
  document.querySelectorAll('.chk input').forEach(function (cb) {
    function sync() { cb.closest('.chk').classList.toggle('on', cb.checked); }
    cb.addEventListener('change', sync); sync();
  });

  // ── ارسال خودکار فرم فیلتر ────────────────────────────
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form && el.form.submit(); });
  });

  // ── جست‌وجوی زنده با تأخیر ────────────────────────────
  var sb = document.querySelector('[data-livesearch]');
  if (sb) {
    var t = null;
    sb.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(function () { sb.form && sb.form.submit(); }, 550);
    });
  }
})();
