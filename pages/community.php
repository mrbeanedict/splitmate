<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
require_once __DIR__ . '/../includes/domain.php'; // public_user()
$title = 'Community';
$nav = 'community';
$back = 'dashboard.php';
$headExtra = ['assets/css/community.css'];
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device cm" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="text-[21px] font-extrabold tracking-tight">Community</h1>
        <p class="text-[12px] text-brand-50/90">Share with other Setlo users</p>
      </div>
    </div>
  </div>

  <div class="flex-1 px-5 pb-6 pt-4">
    <div class="cm-stack">

      <!-- Composer -->
      <form class="cm-card cm-compose" @submit.prevent="submitPost">
        <div class="cm-compose-row">
          <avatar :user="me" :size="40"></avatar>
          <textarea class="cm-text" v-model="compose.body" @input="grow" rows="2" maxlength="2000" placeholder="What’s on your mind?" aria-label="Write a post"></textarea>
        </div>
        <div v-if="compose.files.length" class="cm-thumbs">
          <div v-for="(f, i) in compose.files" :key="f.url" class="cm-thumb">
            <img :src="f.url" :alt="'Photo ' + (i + 1)" />
            <button type="button" @click="removeFile(i)" :aria-label="'Remove photo ' + (i + 1)">×</button>
          </div>
        </div>
        <input ref="files" type="file" multiple accept="image/jpeg,image/png,image/webp" class="hidden" @change="pickFiles" aria-label="Choose photos" />
        <div class="cm-fields">
          <div class="cm-loc">
            <input class="cm-input" v-model="compose.location" @input="onLocation('compose')" @keydown="suggestKey($event, 'compose')" @blur="closeSuggest" maxlength="160" autocomplete="off" role="combobox" :aria-expanded="suggest.key === 'compose' && suggest.items.length ? 'true' : 'false'" aria-autocomplete="list" placeholder="📍 Search a place (optional)" aria-label="Location" />
            <ul v-if="suggest.key === 'compose' && suggest.items.length" class="cm-suggest" role="listbox">
              <li v-for="(s, i) in suggest.items" :key="s.label + i" role="option" :aria-selected="i === suggest.idx ? 'true' : 'false'" :class="{ on: i === suggest.idx }" @mousedown.prevent="pickPlace('compose', s)">📍 {{ s.label }}</li>
            </ul>
            <span v-if="compose.lat !== null" class="cm-pinned">✓ Pinned on the map</span>
          </div>
          <input class="cm-input cm-price" v-model="compose.price" type="number" min="0" max="99999999.99" step="0.01" inputmode="decimal" placeholder="₱ Price (optional)" aria-label="Price" />
        </div>
        <div class="cm-compose-bar">
          <button type="button" class="cm-chip" @click="takePhotos" :disabled="posting || compose.files.length >= MAX_PHOTOS">📷 Camera</button>
          <button type="button" class="cm-chip" @click="$refs.files.click()" :disabled="posting || compose.files.length >= MAX_PHOTOS">🖼 Photos</button>
          <span v-if="compose.files.length" class="cm-meta">{{ compose.files.length }} {{ compose.files.length === 1 ? 'photo' : 'photos' }}</span>
          <select class="cm-select" v-model="compose.audience" aria-label="Who can see this post">
            <option value="public">🌐 Public</option>
            <option value="only_me">🔒 Only me</option>
          </select>
          <button class="cm-post-btn" :disabled="posting || (!compose.body.trim() && !compose.files.length)">{{ posting ? (progress || 'Posting…') : 'Post' }}</button>
        </div>
      </form>

      <div class="cm-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.id" role="tab" :aria-selected="tab === t.id ? 'true' : 'false'" :class="{ on: tab === t.id }" @click="setTab(t.id)">{{ t.label }}</button>
      </div>

      <button v-if="newCount && tab === 'feed'" class="cm-pill" @click="reload">↑ {{ newCount }} new {{ newCount === 1 ? 'post' : 'posts' }}</button>

      <spinner v-if="loading"></spinner>

      <div v-else-if="missing" class="cm-card cm-empty"><b>This post isn’t available</b>It may have been deleted, or its owner made it private.</div>

      <div v-else-if="!posts.length" class="cm-card cm-empty">
        <b>{{ tab === 'saved' ? 'Nothing saved yet' : (tab === 'mine' ? 'You haven’t posted yet' : 'No posts yet') }}</b>
        {{ tab === 'saved' ? 'Tap Save on a post to keep it here.' : 'Be the first to share something with the community.' }}
      </div>

      <!-- Posts -->
      <article v-for="p in posts" :key="p.id" :id="'post-' + p.id" class="cm-card cm-post" :style="focusId === p.id ? 'outline:2px solid rgb(var(--brand-400))' : ''">
        <div class="cm-head">
          <avatar :user="p.author" :size="40"></avatar>
          <div class="cm-who">
            <div class="cm-name">{{ p.author.name }}<span v-if="p.author.badge" :title="p.author.badge.title"> {{ p.author.badge.emoji }}</span><span v-if="p.shared" class="font-medium text-slate-400"> shared a post</span></div>
            <div class="cm-meta">{{ timeAgo(p.created_at) }}<span v-if="p.edited"> · Edited</span> · <span :title="p.audience === 'public' ? 'Public' : 'Only me'">{{ p.audience === 'public' ? '🌐' : '🔒' }}</span></div>
          </div>
          <div class="cm-menu-wrap" @click.stop>
            <button class="cm-icon-btn" @click="menuFor = menuFor === p.id ? null : p.id" aria-label="Post options" aria-haspopup="true">⋯</button>
            <div v-if="menuFor === p.id" class="cm-menu" role="menu">
              <template v-if="p.is_mine">
                <button role="menuitem" @click="startEdit(p)">✏️ Edit post</button>
                <button role="menuitem" @click="setAudience(p, p.audience === 'public' ? 'only_me' : 'public')">{{ p.audience === 'public' ? '🔒 Make it only me' : '🌐 Make it public' }}</button>
              </template>
              <button role="menuitem" @click="toggleSave(p)">{{ p.saved ? '🔖 Unsave post' : '🔖 Save post' }}</button>
              <button role="menuitem" @click="copyLink(p)">🔗 Copy link</button>
              <template v-if="p.can_delete">
                <hr />
                <button role="menuitem" class="danger" @click="removePost(p)">🗑 Delete post</button>
              </template>
            </div>
          </div>
        </div>

        <div v-if="editing && editing.id === p.id" class="cm-edit">
          <textarea class="cm-text" v-model="editing.body" @input="grow" rows="3" maxlength="2000" aria-label="Edit your post"></textarea>
          <div v-if="!p.shared" class="cm-fields">
            <div class="cm-loc">
              <input class="cm-input" v-model="editing.location" @input="onLocation('edit')" @keydown="suggestKey($event, 'edit')" @blur="closeSuggest" maxlength="160" autocomplete="off" role="combobox" :aria-expanded="suggest.key === 'edit' && suggest.items.length ? 'true' : 'false'" aria-autocomplete="list" placeholder="📍 Search a place (optional)" aria-label="Location" />
              <ul v-if="suggest.key === 'edit' && suggest.items.length" class="cm-suggest" role="listbox">
                <li v-for="(s, i) in suggest.items" :key="s.label + i" role="option" :aria-selected="i === suggest.idx ? 'true' : 'false'" :class="{ on: i === suggest.idx }" @mousedown.prevent="pickPlace('edit', s)">📍 {{ s.label }}</li>
              </ul>
              <span v-if="editing.lat !== null" class="cm-pinned">✓ Pinned on the map</span>
            </div>
            <input class="cm-input cm-price" v-model="editing.price" type="number" min="0" max="99999999.99" step="0.01" inputmode="decimal" placeholder="₱ Price (optional)" aria-label="Price" />
          </div>
          <div class="row">
            <button type="button" class="cm-chip" @click="editing = null">Cancel</button>
            <button type="button" class="cm-post-btn" style="margin-left:0" :disabled="busy" @click="saveEdit(p)">Save</button>
          </div>
        </div>
        <p v-else-if="p.body" class="cm-body">{{ p.body }}</p>

        <template v-if="!(editing && editing.id === p.id) && (p.location || p.price !== null)">
          <div class="cm-tags">
            <span v-if="p.location" class="cm-tag">📍 {{ p.location }}</span>
            <span v-if="p.price !== null" class="cm-tag">🏷️ {{ peso(p.price) }}</span>
            <button type="button" class="cm-more" :aria-expanded="open[p.id] ? 'true' : 'false'" @click="open[p.id] = !open[p.id]">{{ open[p.id] ? 'Hide details' : 'View details' }}</button>
          </div>
          <dl v-if="open[p.id]" class="cm-details">
            <div v-if="p.location"><dt>Location</dt><dd>{{ p.location }}</dd></div>
            <div v-if="p.price !== null"><dt>Price</dt><dd>{{ peso(p.price) }}</dd></div>
            <div><dt>Posted by</dt><dd>{{ p.author.name }}</dd></div>
            <div><dt>Posted</dt><dd>{{ fmtDateTime(p.created_at) }}</dd></div>
            <div><dt>Audience</dt><dd>{{ p.audience === 'public' ? 'Public' : 'Only me' }}</dd></div>
          </dl>
          <div v-if="open[p.id] && p.location" class="cm-mapbox">
            <iframe v-if="p.lat !== null" class="cm-map" :src="mapSrc(p)" :title="'Map of ' + p.location" loading="lazy" referrerpolicy="no-referrer"></iframe>
            <a class="cm-more" :href="mapLink(p)" target="_blank" rel="noopener noreferrer">{{ p.lat !== null ? '🗺️ Open in Maps' : '🔎 Search in Maps' }}</a>
          </div>
        </template>

        <div v-if="p.photos.length" class="cm-photos" :class="'n' + Math.min(p.photos.length, 4)">
          <button v-for="(src, i) in p.photos.slice(0, 4)" :key="src" type="button" @click="openPhotos(p.photos, i)" :aria-label="'Open photo ' + (i + 1)">
            <img :src="src" alt="" loading="lazy" />
            <span v-if="i === 3 && p.photos.length > 4" class="cm-more-count">+{{ p.photos.length - 4 }}</span>
          </button>
        </div>

        <div v-if="p.shared" class="cm-embed" :class="{ gone: p.shared.unavailable }">
          <template v-if="p.shared.unavailable">This post isn’t available anymore.</template>
          <template v-else>
            <div class="cm-head">
              <avatar :user="p.shared.author" :size="30"></avatar>
              <div class="cm-who"><div class="cm-name" style="font-size:13.5px">{{ p.shared.author.name }}</div><div class="cm-meta">{{ timeAgo(p.shared.created_at) }}</div></div>
            </div>
            <p v-if="p.shared.body" class="cm-body" style="font-size:14px">{{ p.shared.body }}</p>
            <div v-if="p.shared.photos.length" class="cm-photos" :class="'n' + Math.min(p.shared.photos.length, 4)">
              <button v-for="(src, i) in p.shared.photos.slice(0, 4)" :key="src" type="button" @click="openPhotos(p.shared.photos, i)">
                <img :src="src" alt="" loading="lazy" />
                <span v-if="i === 3 && p.shared.photos.length > 4" class="cm-more-count">+{{ p.shared.photos.length - 4 }}</span>
              </button>
            </div>
          </template>
        </div>

        <div class="cm-counts">
          <span v-if="p.reactions.total" class="cm-rx-summary"><span v-for="e in topEmojis(p)" :key="e">{{ e }}</span> <span style="margin-left:4px">{{ p.reactions.total }}</span></span>
          <span class="push">
            <button v-if="p.comment_count" @click="toggleComments(p)">{{ p.comment_count }} {{ p.comment_count === 1 ? 'comment' : 'comments' }}</button>
            <span v-if="p.share_count">{{ p.share_count }} {{ p.share_count === 1 ? 'share' : 'shares' }}</span>
          </span>
        </div>

        <div class="cm-actions">
          <div class="cm-act" style="padding:0" @mouseenter="hoverPicker(p)" @mouseleave="leavePicker(p)" @touchstart.passive="holdStart(p)" @touchend="holdEnd" @touchcancel="holdEnd">
            <div v-if="pickerFor === p.id" class="cm-picker" role="group" aria-label="Choose a reaction">
              <button v-for="(emo, key) in reactionMap" :key="key" type="button" :title="key" :aria-label="key" @click.stop="react(p, key)">{{ emo }}</button>
            </div>
            <button type="button" class="cm-act" :class="{ on: p.reactions.mine }" style="width:100%" @click="quickLike(p)">
              <span>{{ p.reactions.mine ? reactionMap[p.reactions.mine] : '👍' }}</span><span class="lbl">{{ p.reactions.mine ? labelFor(p.reactions.mine) : 'Like' }}</span>
            </button>
          </div>
          <button class="cm-act" @click="toggleComments(p)"><span>💬</span><span class="lbl">Comment</span></button>
          <button class="cm-act" @click="openShare(p)"><span>↗️</span><span class="lbl">Share</span></button>
          <button class="cm-act" :class="{ on: p.saved }" @click="toggleSave(p)"><span>{{ p.saved ? '🔖' : '🏷️' }}</span><span class="lbl">{{ p.saved ? 'Saved' : 'Save' }}</span></button>
        </div>

        <!-- Comments -->
        <div v-if="cm[p.id]" class="cm-comments">
          <button v-if="cm[p.id].more" class="cm-link" @click="moreComments(p)">View earlier comments</button>
          <spinner v-if="cm[p.id].loading"></spinner>
          <template v-for="it in flatComments(p)" :key="it.key">
            <div v-if="it.type === 'c'" class="cm-comment" :class="{ reply: it.c.parent_id }">
              <avatar :user="it.c.author" :size="it.c.parent_id ? 26 : 32"></avatar>
              <div class="min-w-0 flex-1">
                <div class="cm-bubble">
                  <b>{{ it.c.author.name }}</b>
                  <template v-if="editingComment && editingComment.id === it.c.id">
                    <textarea class="cm-text" v-model="editingComment.body" rows="2" maxlength="1000" aria-label="Edit your comment"></textarea>
                  </template>
                  <p v-else>{{ it.c.body }}</p>
                </div>
                <div v-if="tr[it.c.id] && tr[it.c.id].shown" class="cm-trans" aria-live="polite">
                  <small>{{ tr[it.c.id].source ? 'Translated from ' + tr[it.c.id].source : 'Translation' }} · {{ langName(tr[it.c.id].lang) }}</small>
                  <p>{{ tr[it.c.id].text }}</p>
                  <p v-if="tr[it.c.id].roman" class="roman"><small>Reads as:</small> {{ tr[it.c.id].roman }}</p>
                </div>
                <div v-else-if="tr[it.c.id] && tr[it.c.id].error" class="cm-trans err" role="alert">
                  {{ tr[it.c.id].error }} <a :href="googleUrl(it.c.body)" target="_blank" rel="noopener noreferrer">Try Google Translate</a>
                </div>
                <div class="cm-cmeta">
                  <span>{{ timeAgo(it.c.created_at) }}<span v-if="it.c.edited"> · Edited</span></span>
                  <template v-if="editingComment && editingComment.id === it.c.id">
                    <button @click="saveComment(p, it.c)">Save</button><button @click="editingComment = null">Cancel</button>
                  </template>
                  <template v-else>
                    <button @click="startReply(p, it.c)">Reply</button>
                    <button v-if="it.c.is_mine" @click="editingComment = { id: it.c.id, body: it.c.body }">Edit</button>
                    <button v-if="it.c.can_delete" @click="removeComment(p, it.c)">Delete</button>
                    <button v-if="!tr[it.c.id] || !tr[it.c.id].shown" @click="translate(it.c)" :disabled="tr[it.c.id] && tr[it.c.id].loading">{{ tr[it.c.id] && tr[it.c.id].loading ? 'Translating…' : 'Translate' }}</button>
                    <button v-else @click="tr[it.c.id].shown = false">Hide translation</button>
                  </template>
                </div>
              </div>
            </div>
            <button v-else-if="it.type === 'more'" class="cm-link cm-reply-more" :disabled="it.top.loadingReplies" @click="loadReplies(p, it.top)">{{ it.top.loadingReplies ? 'Loading…' : it.label }}</button>
            <form v-else class="cm-cform cm-reply-box" @submit.prevent="sendReply(p, it.top)">
              <avatar :user="me" :size="26"></avatar>
              <textarea v-model="replyBox.text" @input="grow" rows="1" maxlength="1000" :placeholder="'Reply to ' + replyBox.name + '…'" :aria-label="'Reply to ' + replyBox.name" @keydown.enter.exact.prevent="sendReply(p, it.top)" @keydown.esc="replyBox = null" :ref="focusReply"></textarea>
              <button class="cm-send" :disabled="replyBox.sending || !replyBox.text.trim()">Reply</button>
              <button type="button" class="cm-link" @click="replyBox = null">Cancel</button>
            </form>
          </template>
          <form class="cm-cform" @submit.prevent="sendComment(p)">
            <avatar :user="me" :size="32"></avatar>
            <textarea v-model="cm[p.id].text" @input="grow" rows="1" maxlength="1000" placeholder="Write a comment…" :aria-label="'Comment on ' + p.author.name + '’s post'" @keydown.enter.exact.prevent="sendComment(p)"></textarea>
            <button class="cm-send" :disabled="cm[p.id].sending || !cm[p.id].text.trim()">Send</button>
          </form>
        </div>
      </article>

      <button v-if="hasMore && !loading" class="cm-chip" style="justify-self:center" :disabled="loadingMore" @click="loadMore">{{ loadingMore ? 'Loading…' : 'Load more' }}</button>
    </div>
  </div>

  <!-- Share sheet -->
  <div v-if="sharing" class="sheet-backdrop" @click.self="sharing = null">
    <form class="sheet" @submit.prevent="submitShare">
      <h2 class="text-[17px] font-extrabold text-ink">Share this post</h2>
      <div class="cm-embed" style="margin-top:12px">
        <div class="cm-name" style="font-size:13.5px">{{ sharing.origin.author.name }}</div>
        <p v-if="sharing.origin.body" class="cm-body" style="font-size:14px">{{ sharing.origin.body.length > 160 ? sharing.origin.body.slice(0, 160) + '…' : sharing.origin.body }}</p>
        <p v-if="sharing.origin.photos.length" class="cm-meta">{{ sharing.origin.photos.length }} photo{{ sharing.origin.photos.length === 1 ? '' : 's' }}</p>
      </div>
      <textarea class="cm-text" style="margin-top:12px" v-model="sharing.caption" rows="2" maxlength="2000" placeholder="Say something about this… (optional)" aria-label="Your caption"></textarea>
      <div class="mt-3 flex items-center gap-2">
        <select class="cm-select" v-model="sharing.audience" aria-label="Who can see your share"><option value="public">🌐 Public</option><option value="only_me">🔒 Only me</option></select>
      </div>
      <div class="mt-4 grid grid-cols-2 gap-2.5">
        <button type="button" class="btn-pill btn-pill-soft" @click="sharing = null">Cancel</button>
        <button class="btn-pill btn-pill-primary" :disabled="busy">{{ busy ? 'Sharing…' : 'Share now' }}</button>
      </div>
      <button type="button" class="cm-link" style="margin-top:10px" @click="copyLink(sharing.origin); sharing = null">Or copy the link instead</button>
    </form>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
const REACTIONS = { like: '👍', love: '❤️', haha: '😆', wow: '😮', sad: '😢', angry: '😡' };
const REACTION_LABEL = { like: 'Like', love: 'Love', haha: 'Haha', wow: 'Wow', sad: 'Sad', angry: 'Angry' };
const LANGS = [
  { code: 'en', name: 'English' }, { code: 'tl', name: 'Filipino' }, { code: 'ceb', name: 'Cebuano' }, { code: 'es', name: 'Español' },
  { code: 'ja', name: '日本語' }, { code: 'ko', name: '한국어' }, { code: 'zh-CN', name: '中文' }, { code: 'fr', name: 'Français' },
  { code: 'de', name: 'Deutsch' }, { code: 'id', name: 'Bahasa Indonesia' }, { code: 'ar', name: 'العربية' }, { code: 'hi', name: 'हिन्दी' },
];
/** Translate into the visitor's own language when we support it (browser setting), otherwise English. */
const defaultLang = () => {
  const tag = (navigator.language || 'en').toLowerCase();
  const base = tag === 'fil' ? 'tl' : (tag.startsWith('zh') ? 'zh-CN' : tag.split('-')[0]);
  return LANGS.some((l) => l.code === base) ? base : 'en';
};

// Photos per post (the server allows the same), per upload request, and the size limits (see api/community.php)
const MAX_PHOTOS = 100;
const MAX_PHOTO_BYTES = 20 * 1024 * 1024;
const BATCH_PHOTOS = 10;
const BATCH_BYTES = 30 * 1024 * 1024;

Setlo.mount({
  data: () => ({
    me: <?= json_encode(public_user($user + ['avatar' => $user['avatar'] ?? null])) ?>,
    tab: 'feed', tabs: [{ id: 'feed', label: 'Feed' }, { id: 'saved', label: 'Saved' }, { id: 'mine', label: 'My posts' }],
    posts: [], loading: true, loadingMore: false, hasMore: false, newCount: 0, missing: false, focusId: 0,
    compose: { body: '', location: '', lat: null, lng: null, price: '', audience: 'public', files: [] }, posting: false, busy: false, open: {},
    suggest: { key: '', items: [], idx: -1 }, suggestTimer: null, suggestSeq: 0, progress: '', MAX_PHOTOS,
    menuFor: null, pickerFor: null, editing: null, editingComment: null, sharing: null,
    cm: {}, tr: {}, replyBox: null, lang: defaultLang(), langs: LANGS, reactionMap: REACTIONS,
    poller: null, pickerTimer: null, holdTimer: null, held: false,
  }),
  async mounted() {
    document.addEventListener('click', () => { this.menuFor = null; this.pickerFor = null; });
    const wanted = Number(new URLSearchParams(location.search).get('post')) || 0;
    await this.reload(wanted);
    this.poller = setInterval(this.checkNew, 45000);
  },
  unmounted() { clearInterval(this.poller); },
  methods: {
    async reload(focus) {
      this.loading = true; this.missing = false; this.newCount = 0; this.focusId = 0;
      try {
        const r = await api.get('community.php', { view: this.tab });
        this.posts = r.posts; this.hasMore = r.has_more;
        if (focus && this.tab === 'feed') await this.showPost(focus);
      } catch (e) { Setlo.toast(e.message); }
      this.loading = false;
    },
    /** A post linked from a notification: bring it to the top with its comments open. */
    async showPost(id) {
      try {
        const r = await api.get('community.php', { view: 'post', id });
        this.posts = [r.post, ...this.posts.filter((p) => p.id !== id)];
        this.focusId = id;
        this.toggleComments(r.post, true);
        this.$nextTick(() => { const el = document.getElementById('post-' + id); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
      } catch (e) { this.missing = true; this.posts = []; }
    },
    async setTab(t) { if (this.tab !== t) { this.tab = t; await this.reload(); } },
    async loadMore() {
      if (!this.posts.length) return;
      this.loadingMore = true;
      try {
        const r = await api.get('community.php', { view: this.tab, before: this.posts[this.posts.length - 1].id });
        const have = new Set(this.posts.map((p) => p.id));
        this.posts.push(...r.posts.filter((p) => !have.has(p.id)));
        this.hasMore = r.has_more;
      } catch (e) { Setlo.toast(e.message); }
      this.loadingMore = false;
    },
    async checkNew() {
      if (this.tab !== 'feed' || document.hidden || !this.posts.length) return;
      try { const r = await api.get('community.php', { view: 'newer', since: Math.max(...this.posts.map((p) => p.id)) }); this.newCount = r.count; } catch (e) { /* try again later */ }
    },
    replace(post) { const i = this.posts.findIndex((p) => p.id === post.id); if (i >= 0) this.posts.splice(i, 1, post); },
    grow(e) { const t = e.target; t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 240) + 'px'; },

    /* ---- places: suggestions while typing a location, and the map on a post ---- */
    placeTarget(key) { return key === 'edit' ? this.editing : this.compose; },
    onLocation(key) {
      const t = this.placeTarget(key);
      t.lat = null; t.lng = null; // typing again drops the old pin
      clearTimeout(this.suggestTimer);
      const q = (t.location || '').trim();
      const seq = ++this.suggestSeq;
      if (q.length < 3) { this.closeSuggest(); return; }
      this.suggestTimer = setTimeout(async () => {
        try {
          const r = await api.get('places.php', { q });
          if (seq !== this.suggestSeq) return; // a newer search replaced this one
          this.suggest = { key, items: r.places, idx: -1 };
        } catch (e) { if (seq === this.suggestSeq) this.closeSuggest(); }
      }, 350);
    },
    closeSuggest() { clearTimeout(this.suggestTimer); this.suggestSeq++; this.suggest = { key: '', items: [], idx: -1 }; },
    pickPlace(key, place) {
      const t = this.placeTarget(key);
      t.location = place.label; t.lat = place.lat; t.lng = place.lng;
      this.closeSuggest();
    },
    suggestKey(e, key) {
      const s = this.suggest;
      if (s.key !== key || !s.items.length) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); s.idx = (s.idx + 1) % s.items.length; }
      else if (e.key === 'ArrowUp') { e.preventDefault(); s.idx = (s.idx - 1 + s.items.length) % s.items.length; }
      else if (e.key === 'Enter' && s.idx >= 0) { e.preventDefault(); this.pickPlace(key, s.items[s.idx]); }
      else if (e.key === 'Escape') { this.closeSuggest(); }
    },
    mapSrc(p) {
      const d = 0.004;
      const bbox = [p.lng - d, p.lat - d, p.lng + d, p.lat + d].join(',');
      return 'https://www.openstreetmap.org/export/embed.html?bbox=' + bbox + '&layer=mapnik&marker=' + p.lat + ',' + p.lng;
    },
    mapLink(p) {
      return p.lat !== null
        ? 'https://www.openstreetmap.org/?mlat=' + p.lat + '&mlon=' + p.lng + '#map=17/' + p.lat + '/' + p.lng
        : 'https://www.openstreetmap.org/search?query=' + encodeURIComponent(p.location);
    },

    /* ---- composing ---- */
    async addFiles(files) {
      for (const f of files) {
        if (this.compose.files.length >= MAX_PHOTOS) { Setlo.toast('You can add up to ' + MAX_PHOTOS + ' photos to a post.', 'warn'); break; }
        if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { Setlo.toast(f.name + ' isn’t a JPG, PNG or WebP photo.'); continue; }
        if (f.size > MAX_PHOTO_BYTES) { Setlo.toast(f.name + ' is too large (max 20 MB).'); continue; }
        const small = await Setlo.compressImage(f);
        this.compose.files.push({ file: small, url: URL.createObjectURL(small) });
      }
    },
    async pickFiles(e) { const files = [...e.target.files]; e.target.value = ''; await this.addFiles(files); },
    async takePhotos() { await this.addFiles(await Setlo.captureCamera({ max: MAX_PHOTOS - this.compose.files.length, title: 'Take photos' })); },
    /** Photos in groups the server accepts in one request: at most BATCH_PHOTOS files and BATCH_BYTES in total. */
    photoBatches(files) {
      const batches = [];
      let cur = []; let bytes = 0;
      for (const f of files) {
        if (cur.length && (cur.length >= BATCH_PHOTOS || bytes + f.file.size > BATCH_BYTES)) { batches.push(cur); cur = []; bytes = 0; }
        cur.push(f); bytes += f.file.size;
      }
      if (cur.length) batches.push(cur);
      return batches;
    },
    batchForm(action, batch, offset) {
      const fd = new FormData();
      fd.append('action', action);
      batch.forEach((f, i) => fd.append('photos[]', f.file, f.file.name || 'photo-' + (offset + i + 1) + '.jpg'));
      return fd;
    },
    removeFile(i) { URL.revokeObjectURL(this.compose.files[i].url); this.compose.files.splice(i, 1); },
    async submitPost() {
      const fd = new FormData();
      fd.append('action', 'create');
      fd.append('body', this.compose.body.trim());
      fd.append('location', this.compose.location.trim());
      fd.append('lat', this.compose.lat === null ? '' : String(this.compose.lat));
      fd.append('lng', this.compose.lng === null ? '' : String(this.compose.lng));
      fd.append('price', this.compose.price === null ? '' : String(this.compose.price).trim());
      fd.append('audience', this.compose.audience);
      const batches = this.photoBatches(this.compose.files);
      (batches[0] || []).forEach((f, i) => fd.append('photos[]', f.file, f.file.name || 'photo-' + (i + 1) + '.jpg'));
      const total = this.compose.files.length;
      this.posting = true;
      this.progress = '';
      const r = await Setlo.run(this, () => api.post('community.php', fd), 'posting');
      if (!r) { this.posting = false; return; }
      // The rest of a big photo set goes up in batches onto the post just created
      let sent = batches.length ? batches[0].length : 0;
      this.posting = true; // Setlo.run cleared it when the post itself went through
      for (const batch of batches.slice(1)) {
        this.progress = 'Uploading ' + sent + '/' + total + '…';
        try {
          const fd2 = this.batchForm('add_photos', batch, sent);
          fd2.append('id', r.post.id);
          r.post = (await api.post('community.php', fd2)).post;
          sent += batch.length;
        } catch (e) {
          Setlo.toast(sent + ' of ' + total + ' photos uploaded (' + e.message + '). The post was still shared.', 'warn');
          break;
        }
      }
      this.progress = '';
      this.posting = false;
      this.compose.files.forEach((f) => URL.revokeObjectURL(f.url));
      this.compose = { body: '', location: '', lat: null, lng: null, price: '', audience: this.compose.audience, files: [] };
      this.closeSuggest();
      if (this.tab === 'saved') this.tab = 'feed';
      if (this.tab === 'feed' || this.tab === 'mine') this.posts.unshift(r.post); else await this.reload();
      Setlo.toast(r.post.audience === 'public' ? 'Posted to the community' : 'Saved as a private post', 'ok');
    },

    /* ---- reactions ---- */
    labelFor(k) { return REACTION_LABEL[k] || 'Like'; },
    topEmojis(p) { return Object.keys(p.reactions.counts).slice(0, 3).map((k) => REACTIONS[k]); },
    hoverPicker(p) { clearTimeout(this.pickerTimer); this.pickerTimer = setTimeout(() => { this.pickerFor = p.id; }, 350); },
    leavePicker(p) { clearTimeout(this.pickerTimer); this.pickerTimer = setTimeout(() => { if (this.pickerFor === p.id) this.pickerFor = null; }, 250); },
    holdStart(p) { clearTimeout(this.holdTimer); this.holdTimer = setTimeout(() => { this.pickerFor = p.id; this.held = true; }, 450); },
    holdEnd() { clearTimeout(this.holdTimer); },
    quickLike(p) {
      if (this.held) { this.held = false; return; } // a long press opened the picker; don't also like
      this.react(p, p.reactions.mine ? p.reactions.mine : 'like', !!p.reactions.mine);
    },
    async react(p, type, remove) {
      this.pickerFor = null;
      const r = await Setlo.run(this, () => api.post('community.php', { action: 'react', id: p.id, type: remove || p.reactions.mine === type ? '' : type }));
      if (r) this.replace(r.post);
    },

    /* ---- post menu actions ---- */
    startEdit(p) { this.menuFor = null; this.closeSuggest(); this.editing = { id: p.id, body: p.body, location: p.location || '', lat: p.lat, lng: p.lng, price: p.price === null ? '' : p.price }; },
    async saveEdit(p) {
      const e = this.editing;
      const r = await Setlo.run(this, () => api.post('community.php', {
        action: 'edit', id: p.id, body: e.body, location: (e.location || '').trim(),
        lat: e.lat === null ? '' : String(e.lat), lng: e.lng === null ? '' : String(e.lng),
        price: e.price === null ? '' : String(e.price).trim(),
      }));
      if (r) { this.replace(r.post); this.editing = null; Setlo.toast('Post updated', 'ok'); }
    },
    async setAudience(p, audience) {
      this.menuFor = null;
      const r = await Setlo.run(this, () => api.post('community.php', { action: 'set_audience', id: p.id, audience }));
      if (r) { this.replace(r.post); Setlo.toast(audience === 'public' ? 'Now public' : 'Now only visible to you', 'ok'); }
    },
    async removePost(p) {
      this.menuFor = null;
      if (!(await Setlo.confirm({ title: 'Delete this post?', text: 'It will be removed for everyone, along with its comments.', confirmText: 'Delete', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('community.php', { action: 'delete', id: p.id }));
      if (r) { this.posts = this.posts.filter((x) => x.id !== p.id); Setlo.toast('Post deleted', 'ok'); }
    },
    async toggleSave(p) {
      this.menuFor = null;
      const r = await Setlo.run(this, () => api.post('community.php', { action: p.saved ? 'unsave' : 'save', id: p.id }));
      if (!r) return;
      p.saved = r.saved;
      if (this.tab === 'saved' && !r.saved) this.posts = this.posts.filter((x) => x.id !== p.id);
      Setlo.toast(r.saved ? 'Saved to your list' : 'Removed from saved', 'ok');
    },
    postLink(p) { return new URL('community?post=' + p.id, location.href).href; },
    async copyLink(p) {
      this.menuFor = null;
      const url = this.postLink(p);
      try {
        if (navigator.share) { await navigator.share({ title: 'Setlo Community', url }); return; }
        await navigator.clipboard.writeText(url);
        Setlo.toast('Link copied', 'ok');
      } catch (e) { if (e && e.name !== 'AbortError') Setlo.showCopy('Post link', url, 'Copy this link to share the post.'); }
    },
    openPhotos(srcs, i) { Setlo.photoViewer(srcs, { title: 'Photo', start: i }); },

    /* ---- share ---- */
    openShare(p) {
      if (p.audience !== 'public' && !p.is_mine) { Setlo.toast('Only public posts can be shared.', 'warn'); return; }
      this.sharing = { origin: p.shared && !p.shared.unavailable ? { ...p.shared, author: p.shared.author } : p, caption: '', audience: 'public' };
    },
    async submitShare() {
      const r = await Setlo.run(this, () => api.post('community.php', { action: 'create', share_of: this.sharing.origin.id, body: this.sharing.caption, audience: this.sharing.audience }));
      if (!r) return;
      this.sharing = null;
      if (this.tab === 'feed' || this.tab === 'mine') this.posts.unshift(r.post);
      const orig = this.posts.find((x) => x.id === (r.post.shared && r.post.shared.id));
      if (orig) orig.share_count++;
      Setlo.toast('Shared to your feed', 'ok');
    },

    /* ---- comments ---- */
    async toggleComments(p, forceOpen) {
      if (this.cm[p.id] && !forceOpen) { delete this.cm[p.id]; return; }
      this.cm[p.id] = { list: [], more: false, loading: true, text: '', sending: false };
      try {
        const r = await api.get('community.php', { view: 'comments', post: p.id });
        this.cm[p.id].list = r.comments.map(this.withThread); this.cm[p.id].more = r.has_more;
      } catch (e) { Setlo.toast(e.message); }
      if (this.cm[p.id]) this.cm[p.id].loading = false;
    },
    async moreComments(p) {
      const c = this.cm[p.id];
      const r = await api.get('community.php', { view: 'comments', post: p.id, before: c.list[0].id });
      c.list = [...r.comments.map(this.withThread), ...c.list]; c.more = r.has_more;
    },
    async sendComment(p) {
      const c = this.cm[p.id];
      if (!c.text.trim() || c.sending) return;
      c.sending = true;
      const r = await Setlo.run(this, () => api.post('community.php', { action: 'comment', id: p.id, body: c.text }));
      c.sending = false;
      if (r) { c.list.push(this.withThread(r.comment)); c.text = ''; p.comment_count = r.comment_count; }
    },
    async saveComment(p, c) {
      const r = await Setlo.run(this, () => api.post('community.php', { action: 'edit_comment', id: c.id, body: this.editingComment.body }));
      if (r) { Object.assign(c, r.comment); this.editingComment = null; }
    },
    async removeComment(p, c) {
      const hasReplies = !c.parent_id && c.reply_count > 0;
      if (!(await Setlo.confirm({ title: c.parent_id ? 'Delete this reply?' : 'Delete this comment?', text: hasReplies ? 'Its ' + c.reply_count + (c.reply_count === 1 ? ' reply' : ' replies') + ' will be deleted too.' : '', confirmText: 'Delete', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('community.php', { action: 'delete_comment', id: c.id }));
      if (!r) return;
      const list = this.cm[p.id].list;
      if (c.parent_id) {
        const top = list.find((x) => x.id === c.parent_id);
        if (top) { top.replies = top.replies.filter((x) => x.id !== c.id); top.reply_count = Math.max(0, top.reply_count - 1); }
      } else {
        this.cm[p.id].list = list.filter((x) => x.id !== c.id);
      }
      if (this.replyBox && this.replyBox.top === c.id) this.replyBox = null;
      p.comment_count = r.comment_count;
    },
    /* ---- replies (one level: replying to a reply stays in the same thread) ---- */
    withThread(c) { return { ...c, replies: [], open: false, moreReplies: false, loadingReplies: false }; },
    flatComments(p) {
      const out = [];
      for (const c of this.cm[p.id].list) {
        out.push({ type: 'c', key: 'c' + c.id, c });
        if (c.open) {
          for (const r of c.replies) out.push({ type: 'c', key: 'c' + r.id, c: r });
          if (c.moreReplies) out.push({ type: 'more', key: 'm' + c.id, top: c, label: 'View more replies' });
        } else if (c.reply_count) {
          out.push({ type: 'more', key: 'm' + c.id, top: c, label: 'View ' + c.reply_count + (c.reply_count === 1 ? ' reply' : ' replies') });
        }
        if (this.replyBox && this.replyBox.top === c.id) out.push({ type: 'box', key: 'b' + c.id, top: c });
      }
      return out;
    },
    async loadReplies(p, top) {
      top.loadingReplies = true;
      try {
        const r = await api.get('community.php', { view: 'replies', comment: top.id, ...(top.replies.length ? { after: top.replies[top.replies.length - 1].id } : {}) });
        top.replies.push(...r.replies.filter((x) => !top.replies.some((y) => y.id === x.id)));
        top.moreReplies = r.has_more;
        top.open = true;
      } catch (e) { Setlo.toast(e.message); }
      top.loadingReplies = false;
    },
    async startReply(p, c) {
      const top = this.cm[p.id].list.find((x) => x.id === (c.parent_id || c.id));
      if (!top) return;
      this.replyBox = { top: top.id, to: c.id, name: c.author.first, text: c.parent_id ? '@' + c.author.first + ' ' : '', sending: false };
      if (!top.open && top.reply_count) await this.loadReplies(p, top);
    },
    focusReply(el) { if (el && this.replyBox && !this.replyBox.focused) { this.replyBox.focused = true; el.focus(); } },
    async sendReply(p, top) {
      const box = this.replyBox;
      if (!box || !box.text.trim() || box.sending) return;
      box.sending = true;
      const r = await Setlo.run(this, () => api.post('community.php', { action: 'comment', id: p.id, body: box.text, parent_id: box.to }));
      box.sending = false;
      if (!r) return;
      top.reply_count++;
      p.comment_count = r.comment_count;
      if (top.moreReplies || !top.open) await this.loadReplies(p, top); else top.replies.push(r.comment);
      top.open = true;
      this.replyBox = null;
    },

    /* ---- translate: the comment's words are replaced in place by the translation and how it reads in Latin letters ---- */
    langName(code) { const l = this.langs.find((x) => x.code === code); return l ? l.name : code; },
    googleUrl(text) { return 'https://translate.google.com/?sl=auto&tl=' + encodeURIComponent(this.lang) + '&op=translate&text=' + encodeURIComponent(text); },
    async translate(c) {
      const lang = this.lang;
      const cached = this.tr[c.id];
      if (cached && cached.lang === lang && cached.text) { cached.shown = true; cached.error = ''; return; }
      this.tr[c.id] = { loading: true, shown: false, error: '', lang, text: '', roman: '', source: '' };
      try {
        const r = await api.post('community.php', { action: 'translate', comment_id: c.id, lang });
        this.tr[c.id] = { loading: false, shown: true, error: '', lang, text: r.translation, roman: r.romanization, source: r.source_language };
      } catch (e) {
        this.tr[c.id] = { loading: false, shown: false, error: e.message || 'Translation isn’t available right now.', lang };
      }
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
