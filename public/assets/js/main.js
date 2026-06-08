// =====================================================
// 椅子製造 工程管理システム 共通JavaScript
// =====================================================

'use strict';

// =====================================================
// ガントチャート描画
// 引数: containerId (string), data (object)
//   data.dateFrom   - 表示開始日 (YYYY-MM-DD)
//   data.dateTo     - 表示終了日 (YYYY-MM-DD)
//   data.ganttData  - [{order: {...}, processes: [...]}]
// =====================================================
function renderGantt(containerId, data) {
  const container = document.getElementById(containerId);
  if (!container || !data || !data.ganttData) return;

  const dateFrom = new Date(data.dateFrom);
  const dateTo   = new Date(data.dateTo);
  const totalDays = Math.ceil((dateTo - dateFrom) / 86400000) + 1;

  if (totalDays <= 0) {
    container.innerHTML = '<div class="text-muted p-3">日付範囲が無効です</div>';
    return;
  }

  let html = '<div class="gantt-wrapper">';

  // ヘッダー（日付ラベル）
  html += '<div class="gantt-header">';
  html += '<div class="gantt-header-label">作業指示 / 工程</div>';
  html += '<div style="flex:1;display:flex">';
  for (let i = 0; i < totalDays; i++) {
    const d = new Date(dateFrom.getTime() + i * 86400000);
    const mon = d.getMonth() + 1;
    const day = d.getDate();
    const dow = ['日','月','火','水','木','金','土'][d.getDay()];
    const isWeekend = d.getDay() === 0 || d.getDay() === 6;
    html += `<div class="gantt-date-cell" style="flex:1;${isWeekend ? 'background:#555' : ''}">
      <span style="font-size:0.65rem">${mon}/${day}<br>${dow}</span></div>`;
  }
  html += '</div></div>';

  // 各作業指示・工程の行
  data.ganttData.forEach(item => {
    const order = item.order;
    const priorColor = order.priority === 'urgent' ? '#dc3545' : order.priority === 'high' ? '#ffc107' : '#6c757d';

    // 作業指示ヘッダ行
    html += `<div class="gantt-row" style="background:#f0f4ff">
      <div class="gantt-label fw-bold" title="${escHtml(order.order_no)}">
        <span style="color:${priorColor}">●</span> ${escHtml(order.order_no)}
        <small class="text-muted d-block">${escHtml(order.chair_type_name)}</small>
      </div>
      <div class="gantt-timeline"></div>
    </div>`;

    // 工程行
    item.processes.forEach(p => {
      const barColor = getBarColor(p.status, p.delay_status);
      const barStyle = calcBarStyle(p, dateFrom, totalDays);

      html += `<div class="gantt-row">
        <div class="gantt-label" style="padding-left:20px;font-size:0.78rem">
          ${escHtml(p.process_name)}
          <span class="ms-1 badge" style="font-size:0.65rem;background:${barColor}">
            ${statusLabel(p.status)}
          </span>
        </div>
        <div class="gantt-timeline" style="background:#f9f9f9;border-left:1px solid #eee">
          ${barStyle.planned ? `<div class="gantt-bar" style="left:${barStyle.planned.left}%;width:${barStyle.planned.width}%;background:#adb5bd;opacity:0.5" title="予定"></div>` : ''}
          ${barStyle.actual  ? `<div class="gantt-bar" style="left:${barStyle.actual.left}%;width:${barStyle.actual.width}%;background:${barColor}" title="実績">${escHtml(p.process_name)}</div>` : ''}
        </div>
      </div>`;
    });
  });

  html += '</div>';
  container.innerHTML = html;

  // ガントの高さ調整
  const loadingEl = document.getElementById('ganttLoading');
  if (loadingEl) loadingEl.remove();
}

function calcBarStyle(process, dateFrom, totalDays) {
  const style = {};
  const msPerDay = 86400000;

  function toPercent(dateStr) {
    if (!dateStr) return null;
    const d   = new Date(dateStr);
    const diff = (d - dateFrom) / msPerDay;
    return Math.max(0, Math.min(100, (diff / totalDays) * 100));
  }

  if (process.planned_start && process.planned_end) {
    const left  = toPercent(process.planned_start);
    const right = toPercent(process.planned_end);
    if (left !== null && right !== null && right > left) {
      style.planned = { left: left.toFixed(2), width: (right - left).toFixed(2) };
    }
  }

  if (process.actual_start) {
    const left  = toPercent(process.actual_start);
    const end   = process.actual_end ? process.actual_end : new Date().toISOString();
    const right = toPercent(end);
    if (left !== null && right !== null) {
      style.actual = { left: left.toFixed(2), width: Math.max(0.5, right - left).toFixed(2) };
    }
  }

  return style;
}

function getBarColor(status, delayStatus) {
  if (delayStatus === 'critical') return '#6c1a1a';
  if (delayStatus === 'delayed')  return '#dc3545';
  if (status === 'completed')     return '#198754';
  if (status === 'in_progress')   return '#ffc107';
  return '#0d6efd';
}

function statusLabel(status) {
  const labels = {
    not_started: '未着手', in_progress: '作業中',
    completed: '完了', delayed: '遅れ', on_hold: '保留'
  };
  return labels[status] || status;
}

function escHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// =====================================================
// 共通UI: フォーム送信中の二重送信防止
// =====================================================
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function() {
      const btn = form.querySelector('button[type="submit"]');
      if (btn && !btn.dataset.noDisable) {
        setTimeout(() => {
          btn.disabled = true;
          btn.textContent = '処理中...';
        }, 10);
      }
    });
  });

  // 数値入力の負数防止（min=0 のフィールド）
  document.querySelectorAll('input[type="number"][min="0"]').forEach(input => {
    input.addEventListener('change', function() {
      if (parseFloat(this.value) < 0) this.value = 0;
    });
  });

  // フラッシュメッセージの自動消去（5秒後）
  document.querySelectorAll('.alert-success, .alert-info').forEach(el => {
    setTimeout(() => {
      if (el.parentNode) {
        el.style.transition = 'opacity 1s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 1000);
      }
    }, 5000);
  });
});
