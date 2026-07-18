<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Calendar - CEFI Reservation</title>
    <link rel="stylesheet" href="../style/calendar.css">
    <link rel="stylesheet" href="../style/navbar.css?v=2">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <h1>Calendar</h1>
        <div class="header-user">Admin</div>
    </div>

    <div class="container">
    <!-- Header row -->
    <div class="calendar-header">
        <h1><i class="fas fa-calendar-days"></i> Reservation Calendar</h1>

        <div class="filter-bar">
            <button class="filter-btn active" data-filter="all">All</button>
            <button class="filter-btn" data-filter="reservation">Reservations</button>
            <button class="filter-btn" data-filter="occasion">Occasions</button>
        </div>

        <div class="month-nav">
            <button id="prev-month" title="Previous Month">&#8249;</button>
            <span id="month-label"></span>
            <button id="next-month" title="Next Month">&#8250;</button>
        </div>

        <button class="today-btn" id="go-today">Today</button>

        <div class="legend">
            <div class="legend-item">
                <span class="legend-dot approved"></span> Approved
            </div>
            <div class="legend-item">
                <span class="legend-dot pending"></span> Pending
            </div>
            <div class="legend-item">
                <span class="legend-dot rejected"></span> Rejected
            </div>
            <div class="legend-item">
                <span class="legend-dot holiday"></span> Holiday
            </div>
            <div class="legend-item">
                <span class="legend-dot school_event"></span> School Event
            </div>
        </div>
    </div>

    <!-- Calendar -->
    <div class="calendar-grid">
        <div class="weekday-labels">
            <div class="weekday-label">Sun</div>
            <div class="weekday-label">Mon</div>
            <div class="weekday-label">Tue</div>
            <div class="weekday-label">Wed</div>
            <div class="weekday-label">Thu</div>
            <div class="weekday-label">Fri</div>
            <div class="weekday-label">Sat</div>
        </div>
        <div class="days-grid" id="days-grid">
            <div id="calendar-status">Loading calendar…</div>
        </div>
    </div>
</div>

<!-- Day Detail Modal -->
<div class="modal-overlay" id="modal-overlay">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-header">
            <h3 id="modal-title">Reservations</h3>
            <button class="modal-close" id="modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body" id="modal-body"></div>
        <div class="modal-footer">
            <a href="occasions.php" style="float: left; color: #8e44ad;">Manage Occasions</a>
            <a href="../reservations/create.php">+ Create Reservation</a>
        </div>
    </div>
</div>

    </div>
</div>

<div class="footer">
    © 2026 CEFI ONLINE FACILITY RESERVATION. All rights reserved. | Calayan Educational Foundation Inc., Philippines | Contact: info@cefi.website
</div>

<script>
(function () {
    /* ── State ── */
    const today = new Date();
    let currentYear  = today.getFullYear();
    let currentMonth = today.getMonth(); // 0-based
    let eventsByDate = {}; // keyed by "YYYY-MM-DD"
    let currentFilter = 'all';

    const MONTH_NAMES = [
        'January','February','March','April','May','June',
        'July','August','September','October','November','December'
    ];

    /* ── DOM refs ── */
    const daysGrid    = document.getElementById('days-grid');
    const monthLabel  = document.getElementById('month-label');
    const overlay     = document.getElementById('modal-overlay');
    const modalTitle  = document.getElementById('modal-title');
    const modalBody   = document.getElementById('modal-body');
    const calStatus   = document.getElementById('calendar-status');

    /* ── Helpers ── */
    function pad(n) { return String(n).padStart(2, '0'); }

    function monthKey(y, m) {
        return `${y}-${pad(m + 1)}`;
    }

    function dateKey(y, m, d) {
        return `${y}-${pad(m + 1)}-${pad(d)}`;
    }

    function formatTime(t) {
        if (!t) return '';
        const [h, m] = t.split(':');
        const hour = parseInt(h, 10);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const h12  = hour % 12 || 12;
        return `${h12}:${m} ${ampm}`;
    }

    function formatDate(dateStr) {
        const [y, m, d] = dateStr.split('-');
        return `${MONTH_NAMES[parseInt(m,10)-1]} ${parseInt(d,10)}, ${y}`;
    }

    /* ── Fetch events ── */
    async function fetchEvents(y, m) {
        const key = monthKey(y, m);
        try {
            const res  = await fetch(`../api/get_calendar_events.php?month=${key}`);
            const data = await res.json();
            eventsByDate = {};
            data.forEach(ev => {
                const dates = [ev.reservation_date || ev.occasion_date];
                
                // Handle multi-day occasions
                if (ev.type === 'occasion' && ev.end_date) {
                    let curr = new Date(ev.occasion_date);
                    let end = new Date(ev.end_date);
                    while (curr < end) {
                        curr.setDate(curr.getDate() + 1);
                        dates.push(curr.toISOString().split('T')[0]);
                    }
                }

                dates.forEach(d => {
                    if (!eventsByDate[d]) eventsByDate[d] = [];
                    eventsByDate[d].push(ev);
                });
            });
        } catch (e) {
            console.error('Failed to fetch calendar events:', e);
            eventsByDate = {};
        }
    }

    /* ── Render calendar grid ── */
    function renderCalendar() {
        daysGrid.innerHTML = '';

        const firstDay  = new Date(currentYear, currentMonth, 1).getDay(); // 0=Sun
        const daysInMon = new Date(currentYear, currentMonth + 1, 0).getDate();
        const prevDays  = new Date(currentYear, currentMonth, 0).getDate();

        monthLabel.textContent = `${MONTH_NAMES[currentMonth]} ${currentYear}`;

        const todayKey = dateKey(today.getFullYear(), today.getMonth(), today.getDate());

        // Leading cells
        for (let i = firstDay - 1; i >= 0; i--) {
            addCell(prevDays - i, true);
        }

        // Current month cells
        for (let d = 1; d <= daysInMon; d++) {
            const key    = dateKey(currentYear, currentMonth, d);
            const events = eventsByDate[key] || [];
            
            // Apply filtering
            const filteredEvents = events.filter(ev => {
                if (currentFilter === 'all') return true;
                return ev.type === currentFilter;
            });

            addCell(d, false, key, filteredEvents, key === todayKey);
        }

        // Trailing cells
        const totalCells  = Math.ceil((firstDay + daysInMon) / 7) * 7;
        const trailingNum = totalCells - firstDay - daysInMon;
        for (let d = 1; d <= trailingNum; d++) {
            addCell(d, true);
        }
    }

    function addCell(dayNum, otherMonth, key, events, isToday) {
        const cell = document.createElement('div');
        cell.className = 'day-cell' +
            (otherMonth ? ' other-month' : '') +
            (isToday    ? ' today'       : '') +
            (events && events.length ? ' has-events' : '');

        const numEl = document.createElement('span');
        numEl.className = 'day-number';
        numEl.textContent = dayNum;
        cell.appendChild(numEl);

        if (!otherMonth && events && events.length) {
            // Sort: Occasions first, then Reservations by time
            events.sort((a,b) => {
                if (a.type !== b.type) return a.type === 'occasion' ? -1 : 1;
                if (a.type === 'reservation') return a.start_time.localeCompare(b.start_time);
                return 0;
            });

            const maxChips = 3;
            events.slice(0, maxChips).forEach(ev => {
                const chip = document.createElement('span');
                if (ev.type === 'reservation') {
                    chip.className = `event-chip ${ev.status.toLowerCase()}`;
                    chip.textContent = `${formatTime(ev.start_time)} ${ev.facility_name}`;
                } else {
                    chip.className = `event-chip occasion-chip type-${ev.occ_type.toLowerCase()}`;
                    chip.style.backgroundColor = ev.color + '22'; // subtle bg
                    chip.style.borderLeft = `3px solid ${ev.color}`;
                    chip.style.color = ev.color;
                    chip.textContent = `★ ${ev.title}`;
                }
                cell.appendChild(chip);
            });

            if (events.length > maxChips) {
                const more = document.createElement('span');
                more.className = 'more-events';
                more.textContent = `+${events.length - maxChips} more`;
                cell.appendChild(more);
            }

            cell.addEventListener('click', () => openModal(key, events));
        }

        daysGrid.appendChild(cell);
    }

    /* ── Modal ── */
    function openModal(key, events) {
        modalTitle.innerHTML = `<i class="fas fa-calendar-days"></i> ${escHtml(formatDate(key))}`;
        modalBody.innerHTML = '';

        if (!events.length) {
            modalBody.innerHTML = `<p class="modal-no-events">No events on this day.</p>`;
        } else {
            const occasions = events.filter(e => e.type === 'occasion');
            const reservations = events.filter(e => e.type === 'reservation');

            if (occasions.length > 0) {
                modalBody.insertAdjacentHTML('beforeend', `<h4 class="modal-section-title"><i class="fas fa-star"></i> Special Occasions</h4>`);
                occasions.forEach(occ => {
                    const card = document.createElement('div');
                    card.className = 'modal-event-card occasion';
                    card.style.borderLeftColor = occ.color;
                    card.innerHTML = `
                        <div class="modal-event-info">
                            <div class="event-name" style="color:${occ.color}">${escHtml(occ.title)}</div>
                            <div class="event-meta"><i class="fas fa-tag"></i> ${occ.occ_type.replace('_',' ')}</div>
                            ${occ.description ? `<div class="event-meta"><i class="fas fa-pen-to-square"></i> ${escHtml(occ.description)}</div>` : ''}
                        </div>
                    `;
                    modalBody.appendChild(card);
                });
            }

            if (reservations.length > 0) {
                modalBody.insertAdjacentHTML('beforeend', `<h4 class="modal-section-title" style="margin-top:20px;"><i class="fas fa-clipboard-list"></i> Reservations</h4>`);
                reservations.forEach(ev => {
                    const status = ev.status.toLowerCase();
                    const card   = document.createElement('div');
                    card.className = 'modal-event-card';
                    card.innerHTML = `
                        <div class="modal-status-bar ${status}"></div>
                        <div class="modal-event-info">
                            <div class="event-name">${escHtml(ev.fb_name)}</div>
                            <div class="event-meta"><i class="fas fa-building"></i> ${escHtml(ev.facility_name)}</div>
                            <div class="event-meta"><i class="fas fa-clock"></i> ${formatTime(ev.start_time)} – ${formatTime(ev.end_time)}</div>
                            ${ev.purpose ? `<div class="event-meta"><i class="fas fa-pen-to-square"></i> ${escHtml(ev.purpose)}</div>` : ''}
                        </div>
                        <div style="display:flex; flex-direction:column; gap:5px; align-items:flex-end;">
                            <span class="modal-status-badge ${status}">${ev.status}</span>
                            <a href="../reservations/delete.php?id=${ev.id}" 
                               onclick="return confirm('Are you sure you want to delete this reservation?')" 
                               style="font-size: 0.75rem; color: #e74c3c; text-decoration: none; font-weight: 700;">
                               <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                    `;
                    modalBody.appendChild(card);
                });
            }
        }

        overlay.classList.add('open');
    }

    function closeModal() {
        overlay.classList.remove('open');
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;');
    }

    /* ── Navigation ── */
    async function navigate(delta) {
        currentMonth += delta;
        if (currentMonth > 11) { currentMonth = 0;  currentYear++; }
        if (currentMonth < 0)  { currentMonth = 11; currentYear--; }
        daysGrid.innerHTML = `<div id="calendar-status" style="grid-column:1/-1;text-align:center;padding:30px;color:#7f8c8d;">Loading…</div>`;
        await fetchEvents(currentYear, currentMonth);
        renderCalendar();
    }

    document.getElementById('prev-month').addEventListener('click', () => navigate(-1));
    document.getElementById('next-month').addEventListener('click', () => navigate(+1));
    document.getElementById('go-today').addEventListener('click', async () => {
        currentYear  = today.getFullYear();
        currentMonth = today.getMonth();
        await fetchEvents(currentYear, currentMonth);
        renderCalendar();
    });

    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.onclick = () => {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentFilter = btn.dataset.filter;
            renderCalendar();
        };
    });

    document.getElementById('modal-close').addEventListener('click', closeModal);
    overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

    /* ── Init ── */
    (async () => {
        await fetchEvents(currentYear, currentMonth);
        renderCalendar();
    })();
})();
</script>

</body>
</html>
