/**
 * أسطر إدخال الدين — إضافة / حذف بدون ريفرش
 * يحتاج: #debtRows, #addDebtRowBtn
 * و window.DEBT_ENTRY = { month: '2026-07', years: [2025,2026,2027], labels: {...} }
 */
(function () {
  function cfg() {
    return window.DEBT_ENTRY || {};
  }

  function labels() {
    var L = cfg().labels || {};
    return {
      type: L.type || 'النوع',
      month: L.month || 'الشهر',
      amount: L.amount || 'المبلغ',
      notes: L.notes || 'ملاحظات',
      monthOpt: L.monthOpt || 'شهر اشتراك',
      itemOpt: L.itemOpt || 'غرض / حاجة',
      remove: L.remove || 'حذف السطر'
    };
  }

  function yearsList() {
    var ys = cfg().years;
    if (ys && ys.length) return ys;
    var y = parseInt(String(cfg().month || '').split('-')[0], 10) || (new Date()).getFullYear();
    return [y - 1, y, y + 1];
  }

  function pad2(n) {
    n = parseInt(n, 10) || 1;
    return (n < 10 ? '0' : '') + n;
  }

  function syncYmPicker(box) {
    if (!box) return;
    var mSel = box.querySelector('.ym-month');
    var ySel = box.querySelector('.ym-year');
    var hid = box.querySelector('.ym-value');
    if (!mSel || !ySel || !hid) return;
    var m = parseInt(mSel.value, 10) || 1;
    var y = parseInt(ySel.value, 10) || (new Date()).getFullYear();
    hid.value = y + '-' + pad2(m);
    var i;
    for (i = 0; i < mSel.options.length; i++) {
      var opt = mSel.options[i];
      opt.text = '1-' + opt.value + '-' + y;
    }
  }

  function bindYmPicker(box) {
    if (!box || box.getAttribute('data-ym-bound') === '1') return;
    box.setAttribute('data-ym-bound', '1');
    var mSel = box.querySelector('.ym-month');
    var ySel = box.querySelector('.ym-year');
    if (!mSel || !ySel) return;
    mSel.addEventListener('change', function () { syncYmPicker(box); });
    ySel.addEventListener('change', function () { syncYmPicker(box); });
    syncYmPicker(box);
  }

  function bindAllYmPickers(root) {
    var scope = root || document;
    var list = scope.querySelectorAll ? scope.querySelectorAll('.ym-picker') : [];
    for (var i = 0; i < list.length; i++) bindYmPicker(list[i]);
  }

  function monthPickerHtml(selected) {
    var ym = (selected != null && selected !== '') ? String(selected) : (cfg().month || '');
    var parts = ym.split('-');
    var y = parseInt(parts[0], 10) || (new Date()).getFullYear();
    var m = parseInt(parts[1], 10) || ((new Date()).getMonth() + 1);
    var years = yearsList();
    if (years.indexOf(y) < 0) {
      years = years.concat([y]);
      years.sort(function (a, b) { return a - b; });
    }
    var html = '<div class="ym-picker">';
    html += '<select class="ym-month" aria-label="month">';
    for (var i = 1; i <= 12; i++) {
      html += '<option value="' + i + '"' + (i === m ? ' selected' : '') + '>1-' + i + '-' + y + '</option>';
    }
    html += '</select>';
    html += '<select class="ym-year" aria-label="year">';
    for (var j = 0; j < years.length; j++) {
      html += '<option value="' + years[j] + '"' + (years[j] === y ? ' selected' : '') + '>' + years[j] + '</option>';
    }
    html += '</select>';
    html += '<input type="hidden" class="ym-value" name="debt_month[]" value="' + y + '-' + pad2(m) + '">';
    html += '</div>';
    return html;
  }

  function resetMonthPicker(row) {
    var box = row.querySelector('.ym-picker');
    if (!box) return;
    var want = cfg().month || '';
    var parts = String(want).split('-');
    var y = parseInt(parts[0], 10) || (new Date()).getFullYear();
    var m = parseInt(parts[1], 10) || 1;
    var mSel = box.querySelector('.ym-month');
    var ySel = box.querySelector('.ym-year');
    if (mSel) mSel.value = String(m);
    if (ySel) ySel.value = String(y);
    syncYmPicker(box);
  }

  function buildRow(prefillMonth) {
    var L = labels();
    var monthVal = (prefillMonth != null && prefillMonth !== '') ? String(prefillMonth) : (cfg().month || '');
    var wrap = document.createElement('div');
    wrap.className = 'debt-entry-row';

    var grid = document.createElement('div');
    grid.className = 'form-grid';

    grid.innerHTML =
      '<div><label>' + L.type + '</label>' +
      '<select name="debt_kind[]">' +
      '<option value="month">' + L.monthOpt + '</option>' +
      '<option value="item">' + L.itemOpt + '</option>' +
      '</select></div>' +
      '<div><label>' + L.month + '</label>' +
      monthPickerHtml(monthVal) + '</div>' +
      '<div><label>' + L.amount + '</label>' +
      '<input type="number" name="debt_amount[]" min="0" step="1" value="" autocomplete="off"></div>' +
      '<div><label>' + L.notes + '</label>' +
      '<input name="debt_notes[]" value="" autocomplete="off"></div>';

    var actions = document.createElement('div');
    actions.className = 'debt-row-actions';
    var rm = document.createElement('button');
    rm.type = 'button';
    rm.className = 'btn ghost sm danger-text';
    rm.textContent = L.remove;
    rm.addEventListener('click', function () {
      var box = document.getElementById('debtRows');
      if (!box) return;
      if (box.querySelectorAll('.debt-entry-row').length <= 1) {
        var inputs = wrap.querySelectorAll('input:not(.ym-value)');
        for (var i = 0; i < inputs.length; i++) {
          inputs[i].value = '';
        }
        var kindSel = wrap.querySelector('select[name="debt_kind[]"]');
        if (kindSel) kindSel.selectedIndex = 0;
        resetMonthPicker(wrap);
        return;
      }
      wrap.parentNode.removeChild(wrap);
    });
    actions.appendChild(rm);

    wrap.appendChild(grid);
    wrap.appendChild(actions);
    bindAllYmPickers(wrap);
    return wrap;
  }

  function bindRemove(row, box) {
    if (row.querySelector('.debt-row-actions')) return;
    var actions = document.createElement('div');
    actions.className = 'debt-row-actions';
    var rm = document.createElement('button');
    rm.type = 'button';
    rm.className = 'btn ghost sm danger-text';
    rm.textContent = labels().remove;
    rm.addEventListener('click', function () {
      if (box.querySelectorAll('.debt-entry-row').length <= 1) {
        var inputs = row.querySelectorAll('input:not(.ym-value)');
        for (var j = 0; j < inputs.length; j++) {
          inputs[j].value = '';
        }
        var kindSel = row.querySelector('select[name="debt_kind[]"]');
        if (kindSel) kindSel.selectedIndex = 0;
        resetMonthPicker(row);
        return;
      }
      row.parentNode.removeChild(row);
    });
    actions.appendChild(rm);
    row.appendChild(actions);
  }

  function bind() {
    bindAllYmPickers(document);
    var box = document.getElementById('debtRows');
    var btn = document.getElementById('addDebtRowBtn');
    if (!box) return;

    if (!box.querySelector('.debt-entry-row')) {
      box.appendChild(buildRow(cfg().month || ''));
    } else {
      var existing = box.querySelectorAll('.debt-entry-row');
      for (var i = 0; i < existing.length; i++) {
        bindRemove(existing[i], box);
        bindAllYmPickers(existing[i]);
      }
    }

    if (btn) {
      btn.addEventListener('click', function () {
        box.appendChild(buildRow(cfg().month || ''));
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }

  window.bindYmPickers = bindAllYmPickers;
})();
