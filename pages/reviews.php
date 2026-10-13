<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Ratings & reviews';
$nav = 'reviews';
$back = 'dashboard.php';
$headExtra = ['assets/css/reviews.css'];
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device rv" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="text-[21px] font-extrabold tracking-tight">Ratings &amp; reviews</h1>
        <p class="text-[12px] text-brand-50/90">Tell us how Setlo is working for you</p>
      </div>
    </div>
  </div>

  <div class="flex-1 px-5 pb-6 pt-4">
    <spinner v-if="loading"></spinner>
    <div v-else class="rv-stack">

      <!-- Summary -->
      <section class="rv-card rv-summary" aria-label="Rating summary">
        <div class="rv-score">
          <b>{{ r.count ? r.average.toFixed(1) : '–' }}</b>
          <span class="rv-stars" aria-hidden="true">{{ stars(Math.round(r.average)) }}<span class="off">{{ stars(5 - Math.round(r.average)) }}</span></span>
          <small>{{ r.count ? r.count.toLocaleString() + (r.count === 1 ? ' rating' : ' ratings') : 'No ratings yet' }}</small>
        </div>
        <div class="rv-bars">
          <button v-for="n in [5, 4, 3, 2, 1]" :key="n" type="button" class="rv-bar" :class="{ on: filter === n }" :aria-pressed="filter === n ? 'true' : 'false'" :aria-label="'Show ' + n + ' star reviews'" @click="setFilter(n)">
            <span>{{ n }}</span><i><span :style="{ width: pct(n) + '%' }"></span></i><em>{{ r.distribution[n] }}</em>
          </button>
        </div>
      </section>

      <!-- Rate this app -->
      <section class="rv-card rv-rate" aria-label="Rate Setlo">
        <h2>{{ r.mine ? 'Your rating' : 'Rate this app' }}</h2>
        <p class="sub">{{ r.mine ? 'Tap a different star to change it.' : 'Tell others what you think. Your review is posted right away.' }}</p>
        <div class="rv-pick" role="radiogroup" aria-label="Your rating" @mouseleave="hover = 0">
          <button v-for="n in 5" :key="n" type="button" role="radio" :aria-checked="myRating === n ? 'true' : 'false'" :aria-label="n + (n === 1 ? ' star' : ' stars')" :class="{ on: n <= (hover || myRating) }" :disabled="saving" @mouseenter="hover = n" @click="rate(n)">★</button>
        </div>
        <div class="rv-pick-hint" aria-live="polite">{{ hintFor(hover || myRating) }}</div>

        <template v-if="r.mine">
          <div v-if="!writing" class="rv-mine">
            <span class="rv-stars" aria-hidden="true">{{ stars(r.mine.rating) }}<span class="off">{{ stars(5 - r.mine.rating) }}</span></span>
            <p v-if="r.mine.comment">{{ r.mine.comment }}</p>
            <p v-else style="color:var(--rv-muted)">No written review yet.</p>
            <div class="rv-row" style="margin-top:12px">
              <span>{{ r.mine.status === 'hidden' ? 'Hidden by an admin: it still counts toward the rating.' : 'Posted ' + timeAgo(r.mine.updated_at) }}</span>
              <span style="display:flex;gap:8px">
                <button type="button" class="rv-btn ghost" @click="startWriting">{{ r.mine.comment ? 'Edit review' : 'Write a review' }}</button>
                <button type="button" class="rv-btn danger" @click="removeMine">Delete</button>
              </span>
            </div>
          </div>
        </template>

        <form v-if="writing" @submit.prevent="saveComment" novalidate>
          <textarea class="rv-text" v-model="draft" maxlength="500" placeholder="Describe your experience (optional)" aria-label="Your review" ref="box"></textarea>
          <div class="rv-row"><span>{{ draft.length }}/500</span>
            <span style="display:flex;gap:8px">
              <button type="button" class="rv-btn ghost" @click="writing = false">Cancel</button>
              <button class="rv-btn" :disabled="saving">{{ saving ? 'Saving…' : (r.mine && r.mine.comment ? 'Update' : 'Post') }}</button>
            </span>
          </div>
        </form>
        <p v-if="err" class="rv-err" role="alert">{{ err }}</p>
      </section>

      <!-- Reviews -->
      <section class="rv-stack" aria-label="Reviews">
        <div class="rv-tools">
          <h2>Reviews</h2>
          <button v-if="filter" class="rv-chip on" @click="setFilter(0)">{{ filter }}★ ✕</button>
          <select class="rv-select" v-model="sort" @change="loadList(true)" aria-label="Sort reviews">
            <option value="recent">Most recent</option>
            <option value="high">Highest rated</option>
            <option value="low">Lowest rated</option>
          </select>
        </div>

        <div v-if="listLoading" class="rv-empty">Loading…</div>
        <div v-else-if="!others.length" class="rv-card rv-empty">{{ filter ? 'No ' + filter + '-star reviews yet.' : 'No written reviews yet. Be the first to share yours.' }}</div>
        <article v-for="(v, i) in others" :key="v.name + v.created_at + i" class="rv-card rv-review">
          <div class="rv-avatar" :style="{ background: v.color }" aria-hidden="true"><img v-if="v.photo" :src="v.photo" alt="" loading="lazy" /><template v-else>{{ v.initials }}</template></div>
          <div>
            <div class="rv-name">{{ v.name }}</div>
            <div class="rv-meta"><span class="rv-stars" aria-hidden="true">{{ stars(v.rating) }}<span class="off">{{ stars(5 - v.rating) }}</span></span><span>{{ timeAgo(v.created_at) }}</span></div>
            <p>{{ v.comment }}</p>
          </div>
        </article>
        <button v-if="r.has_more && !listLoading" class="rv-chip rv-more" :disabled="loadingMore" @click="loadList(false)">{{ loadingMore ? 'Loading…' : 'Show more reviews' }}</button>
      </section>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
const HINTS = { 1: 'Awful', 2: 'Poor', 3: 'Okay', 4: 'Good', 5: 'Excellent!' };

Setlo.mount({
  data: () => ({
    loading: true, listLoading: false, loadingMore: false, saving: false, writing: false, err: '', hover: 0,
    r: { average: 0, count: 0, distribution: { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 }, reviews: [], has_more: false, mine: null },
    sort: 'recent', filter: 0, draft: '', offset: 0,
  }),
  computed: {
    myRating() { return this.r.mine ? this.r.mine.rating : 0; },
    /** The list shows other people's reviews; yours is the card above. */
    others() { return this.r.reviews.filter((v) => !v.is_mine); },
  },
  async mounted() {
    try { await this.fetch(true); } catch (e) { Setlo.toast(e.message); }
    this.loading = false;
    this.applyPendingRating();
  },
  methods: {
    /** A star tapped on the landing page before logging in: save it now (if they haven't rated yet). */
    async applyPendingRating() {
      let n = 0;
      try { n = Number(sessionStorage.getItem('setlo-pending-rating')) || 0; sessionStorage.removeItem('setlo-pending-rating'); } catch (e) { return; }
      if (n >= 1 && n <= 5 && !this.r.mine) await this.rate(n);
    },
    stars(n) { return '★'.repeat(Math.max(0, n)); },
    hintFor(n) { return HINTS[n] || ''; },
    pct(n) { return this.r.count ? Math.round((this.r.distribution[n] / this.r.count) * 100) : 0; },
    async fetch(reset) {
      const r = await api.get('feedback.php', { sort: this.sort, stars: this.filter || '', offset: reset ? 0 : this.offset, limit: 10 });
      this.offset = (reset ? 0 : this.offset) + r.reviews.length;
      this.r = reset ? r : { ...r, reviews: [...this.r.reviews, ...r.reviews] };
    },
    async loadList(reset) {
      if (reset) this.listLoading = true; else this.loadingMore = true;
      try { await this.fetch(reset); } catch (e) { Setlo.toast(e.message); }
      this.listLoading = false; this.loadingMore = false;
    },
    setFilter(n) { this.filter = this.filter === n ? 0 : n; this.loadList(true); },

    /** Tapping a star saves it straight away; the written part is optional and comes after. */
    async rate(n) {
      this.err = '';
      this.saving = true;
      try {
        const res = await api.post('feedback.php', { rating: n, comment: this.r.mine ? this.r.mine.comment : '' });
        this.r.mine = res.mine;
        await this.fetch(true);
        if (!res.mine.comment) { this.draft = ''; this.writing = true; this.$nextTick(() => this.$refs.box && this.$refs.box.focus()); }
        Setlo.toast('Thanks for rating Setlo!', 'ok');
      } catch (e) { this.err = e.message || 'Couldn’t save your rating.'; }
      this.saving = false;
    },
    startWriting() { this.draft = this.r.mine ? this.r.mine.comment : ''; this.writing = true; this.$nextTick(() => this.$refs.box && this.$refs.box.focus()); },
    async saveComment() {
      this.err = '';
      this.saving = true;
      try {
        const res = await api.post('feedback.php', { rating: this.myRating || 5, comment: this.draft.trim() });
        this.r.mine = res.mine;
        this.writing = false;
        await this.fetch(true);
        Setlo.toast('Your review is live', 'ok');
      } catch (e) { this.err = e.message || 'Couldn’t save your review.'; }
      this.saving = false;
    },
    async removeMine() {
      if (!(await Setlo.confirm({ title: 'Delete your review?', text: 'Your rating and review will be removed.', confirmText: 'Delete', danger: true }))) return;
      try {
        await api.post('feedback.php', { action: 'delete' });
        this.writing = false;
        await this.fetch(true);
        Setlo.toast('Review deleted', 'ok');
      } catch (e) { Setlo.toast(e.message); }
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
