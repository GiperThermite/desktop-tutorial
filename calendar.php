<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stats.php';

$user = auth_require();
stats_log_visit('/calendar.php');
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Календарь — GTR Repository</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@700;800&display=swap" rel="stylesheet">
<style>
:root{
  --header-blue:#2b3e85;--blue:#3f5ea9;--green:#4caa5a;--magenta:#d4468a;
  --orange:#f38c3a;--red:#c93a3a;--text-color:#222;--weekend-red:#d13e3e;
  --grid-line-color:#b0b0b0;--weekend-bg:#ffe6ea;--today-bg:#2e7d32;
  --font-main:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
  --font-mono:'JetBrains Mono','Courier New',monospace;--accent:#3f5ea9;
}
*{box-sizing:border-box}
body{font-family:var(--font-main);background-color:#f0f0f0;margin:0;padding:20px 10px;display:flex;justify-content:center;min-height:100vh;-webkit-font-smoothing:antialiased}
.calendar-container{position:relative;max-width:680px;width:100%;background:#fff;padding:20px;box-shadow:0 4px 12px rgba(0,0,0,.08);height:fit-content}
.top-left-controls{position:absolute;top:15px;left:15px;display:flex;gap:8px;z-index:10}
.top-btn{background-color:#f4f5f8;color:var(--header-blue);border:none;padding:6px 12px;border-radius:999px;font-family:var(--font-main);font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:4px;transition:all .2s;text-decoration:none}
.top-btn:hover{background-color:var(--accent);color:#fff;transform:translateY(-1px)}
.clock-widget{position:absolute;top:15px;right:15px;display:flex;align-items:center;gap:10px;background-color:#f4f5f8;padding:6px 12px 6px 10px;border-radius:999px;z-index:10}
.clock-dot{width:8px;height:8px;border-radius:50%;background-color:#2e7d32;flex-shrink:0;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(46,125,50,.5)}50%{box-shadow:0 0 0 5px rgba(46,125,50,0)}}
.clock-text{display:flex;flex-direction:column;line-height:1.1;text-align:left}
.clock-time{font-family:var(--font-mono);font-size:15px;font-weight:800;color:var(--header-blue)}
.clock-date{font-size:10px;font-weight:600;color:#6b7280;margin-top:1px;text-transform:capitalize}
.logo-container{display:flex;justify-content:center;margin-top:25px;margin-bottom:2px}
.logo{width:48px;height:48px}
.year-controls{display:flex;align-items:center;justify-content:center;gap:14px;margin-bottom:18px}
.year-btn{background-color:#f4f5f8;color:var(--header-blue);border:none;font-size:22px;font-weight:600;width:36px;height:36px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s}
.year-btn:hover{background-color:var(--accent);color:#fff}
#yearInput{font-family:var(--font-main);font-size:34px;font-weight:900;color:var(--header-blue);border:none;background:transparent;width:130px;text-align:center;outline:none;-moz-appearance:textfield}
.calendar-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px 8px}
.month{display:flex;flex-direction:column;height:100%}
.month-header{color:#fff;font-weight:800;text-align:center;padding:4px 0;font-size:11px;margin-bottom:4px;text-transform:uppercase}
.month-header.blue{background-color:var(--blue)}
.month-header.green{background-color:var(--green)}
.month-header.magenta{background-color:var(--magenta)}
.month-header.orange{background-color:var(--orange)}
.month-header.red{background-color:var(--red)}
.weekdays{display:grid;grid-template-columns:repeat(7,1fr);text-align:center;font-size:8.5px;font-weight:700;margin-bottom:2px}
.weekdays div:nth-child(6),.weekdays div:nth-child(7){color:var(--weekend-red)}
.days{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background-color:var(--grid-line-color);border:1px solid var(--grid-line-color);flex-grow:1}
.day-btn{position:relative;background-color:#fff;border:none;padding:0;font-size:11px;font-weight:700;color:var(--text-color);cursor:pointer;display:flex;align-items:center;justify-content:center;aspect-ratio:1/1;transition:background .15s}
.day-btn.weekend{background-color:var(--weekend-bg)}
.day-btn.today{background-color:var(--today-bg)!important;color:#fff!important}
.day-btn:hover{background-color:#e0e0e0}
.day-btn.has-note::after{content:'';position:absolute;bottom:2px;right:2px;width:4px;height:4px;border-radius:50%;background-color:var(--orange);box-shadow:0 0 0 1px #fff}
.note-modal,.notes-list-modal{position:fixed;top:0;right:0;bottom:0;left:0;background-color:rgba(20,25,45,.35);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;z-index:1000;opacity:0;visibility:hidden;transition:.25s;padding:20px}
.note-modal.open,.notes-list-modal.open{opacity:1;visibility:visible}
.note-modal-content,.notes-list-content{width:460px;max-width:100%;background-color:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden;transform:translateY(15px);transition:.3s}
.note-modal.open .note-modal-content,.notes-list-modal.open .notes-list-content{transform:translateY(0)}
.note-modal-header{display:flex;justify-content:space-between;align-items:flex-start;padding:18px 20px 14px;border-bottom:1px solid #eef0f4}
.note-modal-date{font-size:16px;font-weight:800;color:var(--header-blue)}
.note-modal-subtitle{font-size:10px;font-weight:700;color:#9aa0ad;text-transform:uppercase;margin-top:3px}
.note-modal-close{background:#f4f5f8;border:none;width:32px;height:32px;border-radius:50%;font-size:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.2s}
.note-modal-close:hover{background:#ff5a5a;color:#fff;transform:rotate(90deg)}
.note-modal-textarea{flex:1;min-height:190px;border:none;outline:none;resize:none;padding:16px 20px;font-family:var(--font-main);font-size:14px;background:#fafbfc}
.note-modal-footer{display:flex;align-items:center;justify-content:space-between;padding:12px 20px 16px;border-top:1px solid #eef0f4}
.note-modal-status{font-size:11px;font-weight:700;color:#2e7d32;opacity:0;transition:.3s}
.note-modal-status.show{opacity:1}
.note-modal-actions{display:flex;gap:8px;margin-left:auto}
.note-btn{font-family:var(--font-main);font-size:12px;font-weight:700;padding:8px 16px;border-radius:8px;border:none;cursor:pointer;transition:.2s}
.note-btn-save{background:var(--accent);color:#fff}
.note-btn-delete{background:transparent;color:#d13e3e}
.notes-list{flex:1;overflow-y:auto;max-height:60vh;padding:0;margin:0;list-style:none}
.note-list-item{padding:14px 20px;border-bottom:1px solid #eef0f4;cursor:pointer}
.note-list-item:hover{background:#fafbfc}
.note-list-date{font-size:13px;font-weight:800;color:var(--header-blue)}
.note-list-text{font-size:12px;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.empty-notes-message{padding:40px;text-align:center;color:#9aa0ad;font-weight:600;font-size:13px}
@media (max-width:600px){
  .calendar-container{padding:12px}
  .calendar-grid{grid-template-columns:repeat(3,1fr);gap:6px 4px}
  .year-input{font-size:26px}
}
</style>
</head>
<body>

<div class="calendar-container">
    <div class="top-left-controls">
        <button class="top-btn" id="allNotesBtn"><span>📋</span> Всі</button>
        <button class="top-btn" id="unknownNotesBtn"><span>🤷</span> х.з.</button>
        <a class="top-btn" href="/"><span>🏠</span> Репозиторій</a>
		<a class="top-btn" href="/logout" style="color:#d13e3e"><span>🚪</span> Вихід</a>
    </div>

    <div class="clock-widget">
        <div class="clock-dot"></div>
        <div class="clock-text">
            <div class="clock-time" id="clockTime">00:00:00</div>
            <div class="clock-date" id="clockDate">—</div>
        </div>
    </div>

    <div class="logo-container">
        <div class="logo">
            <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                <defs><linearGradient id="lg" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#d60000"></stop><stop offset="100%" stop-color="#446633"></stop></linearGradient></defs>
                <circle cx="100" cy="100" r="88" fill="none" stroke="url(#lg)" stroke-width="4"></circle>
                <circle cx="100" cy="100" r="78" fill="none" stroke="#555" stroke-width="1"></circle>
                <text x="100" y="72" text-anchor="middle" font-size="22" fill="#d60000" font-weight="bold" font-family="sans-serif" letter-spacing="2">GTR</text>
                <text x="100" y="130" text-anchor="middle" font-size="56" fill="#d60000">🖤</text>
            </svg>
        </div>
    </div>

    <div class="year-controls">
        <button class="year-btn" id="prevYear">‹</button>
        <input type="number" id="yearInput" min="1900" max="2100" value="<?= (int)date('Y') ?>">
        <button class="year-btn" id="nextYear">›</button>
    </div>

    <div class="calendar-grid" id="calendar"></div>
</div>

<!-- Модалка редактирования заметки -->
<div class="note-modal" id="noteModal">
    <div class="note-modal-content">
        <div class="note-modal-header">
            <div>
                <div class="note-modal-date" id="noteModalDate">—</div>
                <div class="note-modal-subtitle">Нотатка</div>
            </div>
            <button class="note-modal-close" id="noteModalClose">×</button>
        </div>
        <textarea class="note-modal-textarea" id="noteModalTextarea" placeholder="Напишіть щось..."></textarea>
        <div class="note-modal-footer">
            <span class="note-modal-status" id="noteModalStatus">✓ Збережено</span>
            <div class="note-modal-actions">
                <button class="note-btn note-btn-delete" id="noteModalDelete">Видалити</button>
                <button class="note-btn note-btn-save" id="noteModalSave">Зберегти</button>
            </div>
        </div>
    </div>
</div>

<!-- Модалка списка записей -->
<div class="notes-list-modal" id="notesListModal">
    <div class="notes-list-content">
        <div class="note-modal-header">
            <div>
                <div class="note-modal-date" id="notesListTitle">Всі записи</div>
                <div class="note-modal-subtitle" id="notesCount">0 записів</div>
            </div>
            <button class="note-modal-close" id="notesListClose">×</button>
        </div>
        <ul class="notes-list" id="notesList"></ul>
    </div>
</div>

<script>
'use strict';

const API_URL = '/api/notes.php';
const CSRF = <?= json_encode(csrf_token()) ?>;

let notesCache = {};
let currentNoteDate = null;
let autoSaveTimer = null;
let currentYear = parseInt(document.getElementById('yearInput').value, 10);

// ═══════════════════════════════════════════════════════
// API helper с CSRF
// ═══════════════════════════════════════════════════════
async function apiCall(method, body) {
    const opts = {
        method,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        cache: 'no-store'
    };
    if (method === 'POST') {
        opts.headers['Content-Type'] = 'application/json';
        opts.headers['X-CSRF-Token'] = CSRF;
        opts.body = JSON.stringify(body || {});
    }
    const res = await fetch(API_URL, opts);
    if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.error || ('HTTP ' + res.status));
    }
    return res.json();
}

// ═══════════════════════════════════════════════════════
// Загрузка заметок за год
// ═══════════════════════════════════════════════════════
async function loadAllNotes(year) {
    try {
        const res = await fetch(API_URL + '?year=' + year, {
            credentials: 'same-origin',
            cache: 'no-store'
        });
        if (res.ok) {
            const data = await res.json();
            notesCache = data.notes || {};
        }
    } catch (e) {
        console.warn('loadAllNotes', e);
    }
}

function getNote(key) { return notesCache[key] || ''; }

async function saveNote(key, text) {
    if (text.trim()) notesCache[key] = text;
    else delete notesCache[key];
    
    try {
        await apiCall('POST', { key: key, text: text });
        return { ok: true, finalDate: key };
    } catch (e) {
        console.error('saveNote', e);
        return { ok: false };
    }
}

// ═══════════════════════════════════════════════════════
// Утилиты дат
// ═══════════════════════════════════════════════════════
const weekdays = ['пн','вт','ср','чт','пт','сб','нд'];
const monthNamesGenitive = ['січня','лютого','березня','квітня','травня','червня','липня','серпня','вересня','жовтня','листопада','грудня'];

function getDaysInMonth(year, month) { return new Date(year, month + 1, 0).getDate(); }
function getFirstWeekday(year, month) {
    const day = new Date(year, month, 1).getDay();
    return day === 0 ? 6 : day - 1;
}

function formatDateForDisplay(key) {
    if (key.startsWith('empty-')) {
        const m = key.match(/-(\d{13})$/);
        if (m) {
            const d = new Date(parseInt(m[1], 10));
            return `[Порожня] ${String(d.getDate()).padStart(2,'0')}.${String(d.getMonth()+1).padStart(2,'0')}.${d.getFullYear()}`;
        }
        return '[Порожня клітинка]';
    }
    const [y, mo, d] = key.split('-').map(Number);
    const dt = new Date(y, mo - 1, d);
    return `${weekdays[(dt.getDay()+6)%7]}, ${d} ${monthNamesGenitive[mo-1]} ${y}`;
}

// ═══════════════════════════════════════════════════════
// Рендер календаря
// ═══════════════════════════════════════════════════════
const monthsData = [
    {name:'Січень',cls:'blue'},{name:'Лютий',cls:'blue'},
    {name:'Березень',cls:'green'},{name:'Квітень',cls:'green'},{name:'Травень',cls:'green'},
    {name:'Червень',cls:'magenta'},{name:'Липень',cls:'magenta'},
    {name:'Серпень',cls:'orange'},{name:'Вересень',cls:'orange'},{name:'Жовтень',cls:'orange'},
    {name:'Листопад',cls:'red'},{name:'Грудень',cls:'red'}
];

function generateCalendar(year) {
    const container = document.getElementById('calendar');
    container.innerHTML = '';
    const today = new Date();

    monthsData.forEach((month, idx) => {
        const monthDiv = document.createElement('div');
        monthDiv.className = 'month';
        monthDiv.innerHTML = `<div class="month-header ${month.cls}">${month.name}</div>`;

        const wdDiv = document.createElement('div');
        wdDiv.className = 'weekdays';
        weekdays.forEach(w => { wdDiv.innerHTML += `<div>${w}</div>`; });
        monthDiv.appendChild(wdDiv);

        const daysDiv = document.createElement('div');
        daysDiv.className = 'days';
        const offset = getFirstWeekday(year, idx);
        const total = getDaysInMonth(year, idx);

        // Пустые в начале
        for (let i = 0; i < offset; i++) {
            const btn = document.createElement('button');
            btn.className = 'day-btn empty';
            const baseId = `empty-${year}-${String(idx).padStart(2,'0')}-start-${i}`;
            const existing = Object.keys(notesCache).find(k => k.startsWith(baseId + '-'));
            const key = existing || baseId;
            btn.dataset.date = key;
            if (getNote(key).trim()) btn.classList.add('has-note');
            btn.addEventListener('click', () => openNoteModal(key));
            daysDiv.appendChild(btn);
        }
        // Дни
        for (let day = 1; day <= total; day++) {
            const btn = document.createElement('button');
            btn.className = 'day-btn';
            btn.textContent = day;
            const dt = new Date(year, idx, day);
            if (dt.getDay() === 0 || dt.getDay() === 6) btn.classList.add('weekend');
            if (year === today.getFullYear() && idx === today.getMonth() && day === today.getDate()) btn.classList.add('today');
            const key = `${year}-${String(idx+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
            btn.dataset.date = key;
            if (getNote(key).trim()) btn.classList.add('has-note');
            btn.addEventListener('click', () => openNoteModal(key));
            daysDiv.appendChild(btn);
        }
        // Пустые в конце
        const filled = offset + total;
        for (let i = filled; i < 42; i++) {
            const btn = document.createElement('button');
            btn.className = 'day-btn empty';
            const baseId = `empty-${year}-${String(idx).padStart(2,'0')}-end-${i}`;
            const existing = Object.keys(notesCache).find(k => k.startsWith(baseId + '-'));
            const key = existing || baseId;
            btn.dataset.date = key;
            if (getNote(key).trim()) btn.classList.add('has-note');
            btn.addEventListener('click', () => openNoteModal(key));
            daysDiv.appendChild(btn);
        }

        monthDiv.appendChild(daysDiv);
        container.appendChild(monthDiv);
    });
}

function updateCellIndicator(key) {
    document.querySelectorAll(`.day-btn[data-date="${CSS.escape(key)}"]`).forEach(b => {
        b.classList.toggle('has-note', getNote(key).trim().length > 0);
    });
}

// ═══════════════════════════════════════════════════════
// Модалка заметки
// ═══════════════════════════════════════════════════════
const noteModal = document.getElementById('noteModal');
const noteModalDate = document.getElementById('noteModalDate');
const noteModalTextarea = document.getElementById('noteModalTextarea');
const noteModalSave = document.getElementById('noteModalSave');
const noteModalDelete = document.getElementById('noteModalDelete');
const noteModalClose = document.getElementById('noteModalClose');
const noteModalStatus = document.getElementById('noteModalStatus');

function openNoteModal(key) {
    currentNoteDate = key;
    noteModalDate.textContent = formatDateForDisplay(key);
    noteModalTextarea.value = getNote(key);
    noteModalDelete.disabled = !getNote(key).trim();
    noteModal.classList.add('open');
    setTimeout(() => noteModalTextarea.focus(), 250);
}
function closeNoteModal() {
    noteModal.classList.remove('open');
    currentNoteDate = null;
}
noteModalClose.addEventListener('click', closeNoteModal);
noteModal.addEventListener('click', e => { if (e.target === noteModal) closeNoteModal(); });

noteModalSave.addEventListener('click', async () => {
    if (!currentNoteDate) return;
    const res = await saveNote(currentNoteDate, noteModalTextarea.value);
    if (res.ok) {
        updateCellIndicator(res.finalDate);
        noteModalStatus.classList.add('show');
        setTimeout(() => noteModalStatus.classList.remove('show'), 1500);
    } else {
        alert('Ошибка сохранения');
    }
});

noteModalDelete.addEventListener('click', async () => {
    if (!currentNoteDate || !confirm('Видалити нотатку?')) return;
    const res = await saveNote(currentNoteDate, '');
    if (res.ok) {
        noteModalTextarea.value = '';
        noteModalDelete.disabled = true;
        updateCellIndicator(currentNoteDate);
    }
});

noteModalTextarea.addEventListener('input', () => {
    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(async () => {
        if (currentNoteDate) {
            const res = await saveNote(currentNoteDate, noteModalTextarea.value);
            if (res.ok) updateCellIndicator(res.finalDate);
        }
    }, 1200);
});

// ═══════════════════════════════════════════════════════
// Списки
// ═══════════════════════════════════════════════════════
document.getElementById('allNotesBtn').addEventListener('click', () => openList(false));
document.getElementById('unknownNotesBtn').addEventListener('click', () => openList(true));

function openList(isUnknown) {
    const list = document.getElementById('notesList');
    const title = document.getElementById('notesListTitle');
    const count = document.getElementById('notesCount');
    list.innerHTML = '';

    let keys = Object.keys(notesCache);
    keys = isUnknown ? keys.filter(k => k.startsWith('empty-')) : keys.filter(k => !k.startsWith('empty-'));
    keys.sort();

    title.textContent = isUnknown ? 'Записи х.з.' : 'Всі записи';
    count.textContent = `${keys.length} записів`;

    if (keys.length === 0) {
        list.innerHTML = '<li class="empty-notes-message">Немає записів</li>';
    } else {
        keys.forEach(k => {
            const li = document.createElement('li');
            li.className = 'note-list-item';
            li.innerHTML = `<div class="note-list-date">${escapeHtml(formatDateForDisplay(k))}</div><div class="note-list-text">${escapeHtml(getNote(k))}</div>`;
            li.addEventListener('click', () => {
                document.getElementById('notesListModal').classList.remove('open');
                setTimeout(() => openNoteModal(k), 300);
            });
            list.appendChild(li);
        });
    }
    document.getElementById('notesListModal').classList.add('open');
}
document.getElementById('notesListClose').addEventListener('click', () => {
    document.getElementById('notesListModal').classList.remove('open');
});

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ═══════════════════════════════════════════════════════
// Переключение года
// ═══════════════════════════════════════════════════════
const yearInput = document.getElementById('yearInput');

yearInput.addEventListener('input', async e => {
    const y = parseInt(e.target.value, 10);
    if (y && y >= 1900 && y <= 2100) {
        currentYear = y;
        await loadAllNotes(y);
        generateCalendar(y);
    }
});
document.getElementById('prevYear').addEventListener('click', async () => {
    if (currentYear > 1900) {
        currentYear--;
        yearInput.value = currentYear;
        await loadAllNotes(currentYear);
        generateCalendar(currentYear);
    }
});
document.getElementById('nextYear').addEventListener('click', async () => {
    if (currentYear < 2100) {
        currentYear++;
        yearInput.value = currentYear;
        await loadAllNotes(currentYear);
        generateCalendar(currentYear);
    }
});

// ═══════════════════════════════════════════════════════
// Часы
// ═══════════════════════════════════════════════════════
function updateClock() {
    const now = new Date();
    document.getElementById('clockTime').textContent =
        `${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}:${String(now.getSeconds()).padStart(2,'0')}`;
    document.getElementById('clockDate').textContent =
        `${weekdays[(now.getDay()+6)%7]}, ${now.getDate()} ${monthNamesGenitive[now.getMonth()]} ${now.getFullYear()}`;
}
setInterval(updateClock, 1000);

// ═══════════════════════════════════════════════════════
// Старт
// ═══════════════════════════════════════════════════════
(async function init() {
    updateClock();
    await loadAllNotes(currentYear);
    generateCalendar(currentYear);
})();
</script>

</body>
</html>