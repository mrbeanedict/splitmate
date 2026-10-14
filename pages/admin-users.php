<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();
$title = 'User Management';
$subtitle = 'Search, suspend or reactivate end-user accounts';
$adminNav = 'users';
$loginPage = 'admin-login';
require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/admin-top.php';
?>
<style>
  /* User activity drawer. Own classes (ua-*), like the bill drawer on admin-bills.php. */
  .ua-backdrop { position: fixed; inset: 0; z-index: 50; display: flex; justify-content: flex-end; background: rgb(15 23 42 / .45); animation: ua-fade .18s ease-out; }
  .ua-panel { display: flex; flex-direction: column; width: 100%; max-width: 36rem; height: 100%; background: #fff; box-shadow: -20px 0 50px -20px rgb(15 23 42 / .45); animation: ua-slide .22s cubic-bezier(.2, .8, .2, 1); }
  @keyframes ua-fade { from { opacity: 0 } to { opacity: 1 } }
  @keyframes ua-slide { from { transform: translateX(32px); opacity: .4 } to { transform: none; opacity: 1 } }
  .ua-head { display: flex; align-items: center; gap: .8rem; padding: 1.1rem 1.5rem .9rem; border-bottom: 1px solid #e8eeee; }
  .ua-body { flex: 1; overflow-y: auto; padding: 1.1rem 1.5rem 2rem; }
  .ua-close { width: 2rem; height: 2rem; border-radius: .6rem; color: #64748b; flex-shrink: 0; }
  .ua-close:hover { background: #f1f5f5; color: #0f172a; }
  .ua-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: .6rem; margin-bottom: 1.4rem; }
  @media (max-width: 560px) { .ua-stats { grid-template-columns: repeat(2, 1fr); } .ua-head, .ua-body { padding-left: 1rem; padding-right: 1rem; } }
  .ua-stat { border: 1px solid #e2e8f0; border-radius: .85rem; padding: .7rem .8rem; }
  .ua-stat span { display: block; font-size: .68rem; font-weight: 700; color: #94a3b8; letter-spacing: .03em; text-transform: uppercase; }
  .ua-stat b { display: block; margin-top: .15rem; font-size: 1.1rem; color: #0f172a; }
  .ua-stat small { display: block; font-size: .7rem; color: #94a3b8; }
  .ua-h { font-size: .72rem; font-weight: 800; letter-spacing: .06em; color: #64748b; text-transform: uppercase; margin-bottom: .6rem; }
  .ua-heat { display: grid; grid-template-rows: repeat(7, 1fr); grid-auto-flow: column; grid-auto-columns: 1fr; gap: 4px; }
  .ua-cell { aspect-ratio: 1; border-radius: 4px; background: #eef2f6; }
  .ua-cell.on { background: #14b8a6; }
  .ua-cell.today { box-shadow: 0 0 0 2px #0f766e; }
  .ua-cell.future { background: transparent; }
  .ua-legend { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-top: .5rem; font-size: .7rem; color: #94a3b8; }
  .ua-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin: 0 .25rem -1px .6rem; background: #eef2f6; }
  .ua-legend i.on { background: #14b8a6; }
  .ua-day { display: flex; justify-content: space-between; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid #f1f5f9; font-size: .85rem; }
  .ua-dot { display: inline-block; width: 7px; height: 7px; border-radius: 999px; background: #10b981; margin-right: .35rem; vertical-align: 1px; }
  /* Row actions: a single ⋯ button that opens a menu (fixed, so the table's scroll box can't clip it). */
  .um-dots { width: 34px; height: 34px; border-radius: 50%; border: 0; background: none; color: #64748b; font-size: 20px; line-height: 1; cursor: pointer; }
  .um-dots:hover, .um-dots[aria-expanded="true"] { background: #f1f5f5; color: #0f172a; }
  .um-dots:focus-visible, .um-item:focus-visible { outline: 3px solid rgb(45 212 191 / .6); outline-offset: 1px; }
  .um-menu { position: fixed; z-index: 60; min-width: 210px; padding: 6px; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 18px 40px -12px rgb(15 23 42 / .35); animation: um-pop .12s ease-out; }
  .um-item { display: flex; align-items: center; gap: 10px; width: 100%; border: 0; background: none; padding: 9px 10px; border-radius: 10px; font-size: 13.5px; font-weight: 600; color: #334155; text-align: left; cursor: pointer; }
  .um-item:hover:not(:disabled), .um-item:focus-visible { background: #f1f5f5; }
  .um-item:disabled { opacity: .5; cursor: not-allowed; }
  .um-item svg { width: 17px; height: 17px; flex: none; }
  .um-item.danger { color: #e11d48; } .um-item.ok { color: #059669; }
  .um-sep { border: 0; border-top: 1px solid #e8eeee; margin: 4px 2px; }
  @keyframes um-pop { from { opacity: 0; transform: translateY(-4px) scale(.98); } to { opacity: 1; transform: none; } }
  @media (prefers-reduced-motion: reduce) { .um-menu { animation: none; } }
</style>
<div id="app" class="space-y-4 p-5 md:p-8" v-cloak>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-slate-500">{{ users.length }} {{ users.length === 1 ? 'user' : 'users' }}{{ q ? ' matching “' + q + '”' : '' }}</p>
    <div class="flex flex-wrap items-center gap-2">
      <div class="seg seg-light w-56 !p-0.5">
        <button :class="{ on: sort === 'new' }" @click="setSort('new')">Newest</button>
        <button :class="{ on: sort === 'active' }" @click="setSort('active')">Recently active</button>
      </div>
      <input v-model="q" @input="queue" class="input !w-72" placeholder="Search by name or email" aria-label="Search users" />
    </div>
  </div>

  <div class="card overflow-x-auto">
    <spinner v-if="loading"></spinner>
    <table v-else class="w-full text-sm" style="min-width: 900px">
      <thead class="bg-slate-50"><tr>
        <th class="th">User</th><th class="th">Email</th><th class="th">Bills Joined</th><th class="th">Last Active</th><th class="th">Days Active (30d)</th><th class="th">Joined</th><th class="th">Status</th><th class="th text-right">Actions</th>
      </tr></thead>
      <tbody class="divide-y divide-slate-100">
        <tr v-for="u in users" :key="u.id" :class="{ 'bg-red-50/40': u.disputes }">
          <td class="td"><div class="flex items-center gap-2.5"><avatar :user="u" :size="28"></avatar><button @click="openActivity(u)" class="font-medium hover:text-brand-700 hover:underline">{{ u.name }}</button></div></td>
          <td class="td text-slate-500">{{ u.email }}</td>
          <td class="td text-slate-500">{{ u.bills }}</td>
          <td class="td text-slate-500" :title="u.last_active ? fmtDateTime(u.last_active) : ''">
            <template v-if="u.last_active"><span v-if="recent(u.last_active)" class="ua-dot"></span>{{ timeAgo(u.last_active) }}</template>
            <span v-else class="text-slate-300">Never</span>
          </td>
          <td class="td text-slate-500"><b class="text-ink">{{ u.days_30 }}</b> / 30</td>
          <td class="td text-slate-500">{{ fmtDate(u.created_at, true) }}</td>
          <td class="td">
            <status-pill v-if="u.status === 'suspended'" status="suspended"></status-pill>
            <status-pill v-else-if="u.disputes" status="disputed" :label="u.disputes + ' Open Dispute' + (u.disputes > 1 ? 's' : '')"></status-pill>
            <status-pill v-else status="active"></status-pill>
          </td>
          <td class="td text-right">
            <button type="button" class="um-dots" :aria-expanded="menu && menu.id === u.id ? 'true' : 'false'" aria-haspopup="menu" :aria-label="'Actions for ' + u.name" @click.stop="openMenu(u, $event)">⋯</button>
          </td>
        </tr>
        <tr v-if="!users.length"><td colspan="8" class="td py-8 text-center text-slate-400">No users found.</td></tr>
      </tbody>
    </table>
    <div v-if="menu" class="um-menu" role="menu" :style="{ top: menu.top + 'px', left: menu.left + 'px' }" @click.stop @keydown="menuKey">
      <button type="button" role="menuitem" class="um-item" @click="pick((u) => openActivity(u))">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12h4l3-8 4 16 3-8h4"/></svg>View activity
      </button>
      <button type="button" role="menuitem" class="um-item" :disabled="busy" @click="pick((u) => resetPassword(u))">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a4 4 0 11-6.9 2.7L3 14.8V18h3.2v-2h2v-2h2l1.2-1.2A4 4 0 0015 7z"/></svg>Reset password
      </button>
      <hr class="um-sep" />
      <button v-if="menu.user.status === 'active'" type="button" role="menuitem" class="um-item danger" :disabled="busy" @click="pick((u) => setStatus(u, 'suspended'))">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M5.6 5.6l12.8 12.8"/></svg>Suspend account
      </button>
      <button v-else type="button" role="menuitem" class="um-item ok" :disabled="busy" @click="pick((u) => setStatus(u, 'active'))">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12.5l2.7 2.7L16 9.8"/></svg>Reactivate account
      </button>
    </div>
  </div>

  <!-- User activity drawer -->
  <div v-if="cur" class="ua-backdrop" @click.self="closeActivity">
    <div class="ua-panel" role="dialog" aria-modal="true" :aria-label="cur.name + ' activity'">
      <div class="ua-head">
        <avatar :user="cur" :size="40"></avatar>
        <div class="min-w-0 flex-1">
          <h2 class="truncate text-lg font-extrabold text-ink">{{ cur.name }}</h2>
          <p class="truncate text-xs text-slate-400">{{ cur.email }} · Joined {{ fmtDate(cur.created_at, true) }}</p>
        </div>
        <button class="ua-close" @click="closeActivity" title="Close (Esc)" aria-label="Close">✕</button>
      </div>
      <div class="ua-body">
        <spinner v-if="!act"></spinner>
        <template v-else>
          <div class="ua-stats">
            <div class="ua-stat"><span>Last active</span><b>{{ act.last_active ? timeAgo(act.last_active) : 'Never' }}</b><small v-if="act.last_active">{{ fmtDateTime(act.last_active) }}</small></div>
            <div class="ua-stat"><span>Last 7 days</span><b>{{ act.days_7 }} / 7</b><small>days used</small></div>
            <div class="ua-stat"><span>Last 30 days</span><b>{{ act.days_30 }} / 30</b><small>days used</small></div>
            <div class="ua-stat"><span>Streak</span><b>{{ act.streak }} {{ act.streak === 1 ? 'day' : 'days' }}</b><small>in a row</small></div>
            <div class="ua-stat"><span>Total</span><b>{{ act.days_total }}</b><small>days ever</small></div>
            <div class="ua-stat"><span>First seen</span><b>{{ act.first_seen ? fmtDate(act.first_seen, true) : '—' }}</b></div>
          </div>

          <p class="ua-h">Last 13 weeks</p>
          <div class="ua-heat">
            <div v-for="c in heat" :key="c.key" class="ua-cell" :class="{ on: c.on, today: c.today, future: c.future }" :title="c.title"></div>
          </div>
          <div class="ua-legend">
            <span>Sun → Sat, oldest week on the left</span>
            <span><i></i>Not used<i class="on"></i>Used</span>
          </div>
          <p class="mt-1 text-[11px] text-slate-400">Days before activity tracking started only show when they created a bill or acted on a payment.</p>

          <p class="ua-h mt-6">Recent days</p>
          <p v-if="!act.days.length" class="text-sm text-slate-400">No activity in the last 90 days.</p>
          <div v-for="d in act.days.slice(0, 14)" :key="d.day" class="ua-day">
            <span class="font-semibold text-ink">{{ dayLabel(d.day) }}</span>
            <span class="text-slate-500">{{ clock(d.first_at) }}{{ d.first_at !== d.last_at ? ' – ' + clock(d.last_at) : '' }}</span>
          </div>
        </template>
      </div>
    </div>
  </div>
</div>

<script>
Setlo.mount({
  data: () => ({ loading: true, busy: false, users: [], q: '', sort: 'new', timer: null, cur: null, act: null, menu: null }),
  async mounted() {
    await Setlo.load(this, 'admin.php', { view: 'users', q: '' }, (r) => { this.users = r.users; });
    this._key = (e) => {
      if (e.key === 'Escape' && this.menu) this.closeMenu(true);
      else if (e.key === 'Escape' && this.cur) this.closeActivity();
    };
    this._close = () => this.closeMenu();
    document.addEventListener('keydown', this._key);
    document.addEventListener('click', this._close);
    window.addEventListener('scroll', this._close, true);
    window.addEventListener('resize', this._close);
  },
  beforeUnmount() {
    document.removeEventListener('keydown', this._key);
    document.removeEventListener('click', this._close);
    window.removeEventListener('scroll', this._close, true);
    window.removeEventListener('resize', this._close);
  },
  computed: {
    /** 13 weeks × 7 days, Sunday-first columns ending with this week; days after today are blank. */
    heat() {
      if (!this.act) return [];
      const byDay = Object.fromEntries(this.act.days.map((d) => [d.day, d]));
      const today = this.dateOf(this.act.today);
      const start = new Date(today);
      start.setDate(start.getDate() - today.getDay() - 12 * 7);
      const cells = [];
      for (let n = 0; n < 13 * 7; n++) {
        const dt = new Date(start);
        dt.setDate(start.getDate() + n);
        const key = this.ymd(dt);
        const d = byDay[key];
        const label = dt.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' });
        cells.push({
          key, on: !!d, today: key === this.act.today, future: dt > today,
          title: d ? label + ' · ' + this.clock(d.first_at) + (d.first_at !== d.last_at ? ' – ' + this.clock(d.last_at) : '') : label + ' · not used',
        });
      }
      return cells;
    },
  },
  methods: {
    async load() { this.users = (await api.get('admin.php', { view: 'users', q: this.q, sort: this.sort })).users; },
    setSort(s) { if (this.sort !== s) { this.sort = s; Setlo.run(this, this.load); } },
    recent: (s) => Date.now() - new Date(String(s).replace(' ', 'T')).getTime() < 86400000,
    dateOf: (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); },
    ymd: (dt) => dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0'),
    clock: (s) => new Date(String(s).replace(' ', 'T')).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }),
    dayLabel(day) {
      const today = this.dateOf(this.act.today);
      const diff = Math.round((today - this.dateOf(day)) / 86400000);
      const label = this.dateOf(day).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' });
      return diff === 0 ? 'Today · ' + label : diff === 1 ? 'Yesterday · ' + label : label;
    },
    async openActivity(u) {
      this.cur = u;
      this.act = null;
      try {
        const r = await api.get('admin.php', { view: 'activity', id: u.id });
        if (this.cur === u) this.act = r;
      } catch (e) {
        this.cur = null;
        Setlo.toast(e.message || 'Could not load activity');
      }
    },
    closeActivity() { this.cur = null; this.act = null; },
    /** The ⋯ menu: placed from the button's position, opening upward when there's no room below. */
    openMenu(u, e) {
      if (this.menu && this.menu.id === u.id) { this.closeMenu(); return; }
      const btn = e.currentTarget;
      const r = btn.getBoundingClientRect();
      const width = 210, height = 150;
      const left = Math.max(8, Math.min(window.innerWidth - width - 8, r.right - width));
      const top = r.bottom + height + 12 > window.innerHeight ? Math.max(8, r.top - height - 6) : r.bottom + 6;
      this.menu = { id: u.id, user: u, top, left, btn };
      this.$nextTick(() => { const first = document.querySelector('.um-menu .um-item'); if (first) first.focus(); });
    },
    closeMenu(refocus) {
      if (!this.menu) return;
      const btn = this.menu.btn;
      this.menu = null;
      if (refocus && btn && btn.focus) btn.focus();
    },
    /** The user is taken before the menu closes (closing clears it), then handed to the action. */
    pick(fn) { const u = this.menu && this.menu.user; this.closeMenu(); if (u) fn(u); },
    menuKey(e) {
      if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
      e.preventDefault();
      const items = [...document.querySelectorAll('.um-menu .um-item:not(:disabled)')];
      const i = items.indexOf(document.activeElement);
      items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus();
    },
    queue() { clearTimeout(this.timer); this.timer = setTimeout(() => Setlo.run(this, this.load), 300); },
    async resetPassword(u) {
      if (!(await Setlo.confirm({ title: 'Reset ' + u.name + '’s password?', text: 'Their current password will stop working right away.', confirmText: 'Reset password', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('admin.php', { action: 'reset_password', user_id: u.id }));
      if (r) Setlo.showCopy('Temporary password', r.temporary_password, 'Give this to ' + u.email + '. They can change it on their Profile after signing in.');
    },
    async setStatus(u, status) {
      if (status === 'suspended' && !(await Setlo.confirm({ title: 'Suspend ' + u.name + '?', text: 'They will be signed out and unable to log in until reactivated.', confirmText: 'Suspend', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('admin.php', { action: 'set_user_status', user_id: u.id, status }));
      if (r) { u.status = status; Setlo.toast(status === 'active' ? 'Account reactivated' : 'Account suspended', 'ok'); }
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/admin-bottom.php'; ?>
<?php require __DIR__ . '/../partials/foot.php'; ?>
