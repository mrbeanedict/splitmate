<?php
// Scan a friend's Pay-me QR inside the app: the camera (or a photo of the QR) -> their pay page (pages/pay.php?u=CODE).
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
if ($user['role'] === 'admin') {
    redirect('pages/admin-dashboard');
}
$title = 'Scan to pay';
$nav = 'scanpay';
$back = 'dashboard.php';
$headExtra = ['assets/js/vendor/jsqr.js']; // reads the QR where the browser has no BarcodeDetector
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0">
        <h1 class="text-[21px] font-extrabold tracking-tight">Scan to pay</h1>
        <p class="text-[12px] font-medium text-brand-50/90">Point the camera at a friend’s Pay-me QR</p>
      </div>
    </div>
  </div>

  <div class="flex-1 space-y-4 px-5 pb-6 pt-5">
    <div class="viewfinder h-[340px] shrink-0">
      <video ref="video" v-show="streaming" autoplay playsinline muted class="absolute inset-0 h-full w-full object-cover"></video>
      <span class="vf-corner tl"></span><span class="vf-corner tr"></span>
      <span class="vf-corner bl"></span><span class="vf-corner br"></span>
      <div class="vf-line" :class="{ run: streaming }"></div>
      <div v-if="!streaming" class="absolute inset-0 flex flex-col items-center justify-center gap-2 px-8 text-center text-white">
        <svg class="h-12 w-12 opacity-80" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path stroke-linecap="round" d="M14 14h3v3h-3zM20 14v.01M14 20h3M20 17v4"/></svg>
        <p class="text-[13.5px] font-semibold">{{ status || 'The camera is off.' }}</p>
      </div>
      <div v-else class="absolute inset-x-0 bottom-3 flex justify-center">
        <span class="vf-chip">{{ status || 'Looking for a QR…' }}</span>
      </div>
    </div>

    <p v-if="hint" class="rounded-xl bg-amber-50 px-3.5 py-2.5 text-center text-[12.5px] font-semibold text-amber-800" role="status">{{ hint }}</p>

    <div class="space-y-2">
      <button v-if="!streaming && secure" type="button" @click="start" class="btn btn-primary w-full">Start the camera</button>
      <button type="button" @click="$refs.photo.click()" class="btn btn-outline w-full">Take or choose a photo of the QR</button>
      <input ref="photo" type="file" accept="image/*" capture="environment" class="hidden" @change="onPhoto" />
    </div>

    <p v-if="!secure" class="text-center text-[12px] leading-snug text-slate-400">Live scanning needs a secure page (https). You can still take a photo of the QR above, or scan it with your phone’s own camera app.</p>
    <p class="text-center text-[12px] leading-snug text-slate-400">Your friend’s QR is in their <b>Payment methods</b> page, under “Your Pay-me QR”. After scanning you can pay what you owe or send any amount.</p>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
let stream = null, timer = null, decoding = false;
Setlo.mount({
  data: () => ({
    streaming: false, status: '', hint: '',
    // getUserMedia only works on https:// pages (and localhost)
    secure: !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
  }),
  mounted() {
    if (this.secure) this.start();
    else this.status = 'Live scanning isn’t available on this page.';
    document.addEventListener('visibilitychange', this.onVisibility);
  },
  unmounted() {
    this.stop();
    document.removeEventListener('visibilitychange', this.onVisibility);
  },
  methods: {
    async start() {
      this.hint = '';
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
      } catch (err) {
        this.status = err.name === 'NotAllowedError' ? 'Camera access was blocked. Allow it in the browser, or use a photo.' : 'No camera could be opened. Use a photo of the QR instead.';
        return;
      }
      this.streaming = true;
      this.status = '';
      await this.$nextTick();
      this.$refs.video.srcObject = stream;
      this.loop();
    },
    stop() {
      clearTimeout(timer);
      if (stream) stream.getTracks().forEach((t) => t.stop());
      stream = null;
      this.streaming = false;
    },
    // The camera sleeps when the tab is hidden (privacy and battery), and wakes when you come back.
    onVisibility() {
      if (document.hidden) this.stop();
      else if (this.secure && !this.streaming) this.start();
    },
    /** Look at the picture a few times a second; a slow phone just skips frames instead of piling them up. */
    loop() {
      clearTimeout(timer);
      const tick = async () => {
        const v = this.$refs.video;
        if (!this.streaming || !v) return;
        if (v.readyState >= 2 && !decoding) {
          decoding = true;
          try {
            const text = await Setlo.decodeQr(v);
            if (text && this.found(text)) return;
          } finally { decoding = false; }
        }
        timer = setTimeout(tick, 220);
      };
      tick();
    },
    /** A QR was read. Only a Setlo Pay-me code is used; we go to our own pay page, never to the address inside the QR. */
    found(text) {
      const code = Setlo.parsePayQr(text);
      if (!code) {
        this.hint = 'That QR isn’t a Setlo Pay-me QR.';
        setTimeout(() => { this.hint = ''; }, 3000);
        return false;
      }
      this.stop();
      if (navigator.vibrate) navigator.vibrate(60);
      this.status = 'Found it! Opening…';
      location.href = 'pay.php?u=' + encodeURIComponent(code);
      return true;
    },
    async onPhoto(e) {
      const file = e.target.files && e.target.files[0];
      e.target.value = '';
      if (!file) return;
      this.hint = '';
      let text = null;
      try {
        const bmp = await createImageBitmap(file);
        text = await Setlo.decodeQr(bmp, { tryInverted: true });
        if (bmp.close) bmp.close();
      } catch (err) { /* not an image the browser can read */ }
      if (!text) {
        this.hint = 'We couldn’t find a QR in that photo. Try again, closer and in good light.';
        return;
      }
      this.found(text);
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
