<script setup>
/**
 * Scan.vue — FG Checker Scanning
 *
 * Flow: scan operator QR -> scan lot traveler QR -> tap a result button
 * (sourced live from fgchecker_monitoring_process) -> confirm -> insert.
 *
 * Requires: npm install qr-scanner
 * qr-scanner needs its worker file served statically. After installing, copy
 *   node_modules/qr-scanner/qr-scanner-worker.min.js  ->  public/qr-scanner-worker.min.js
 * (or use Vite's ?url import — see the onMounted note below.)
 *
 * Expected backend routes:
 *   GET  /processes -> { data: [{ Process, Type }, ...] }
 *   POST /scan      -> body { model_name, lot_no, bcs_quantity, encoder, result }
 */
import { Head, Link } from '@inertiajs/vue3'
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import axios from 'axios'
import QrScanner from 'qr-scanner'
import QrScannerWorkerPath from 'qr-scanner/qr-scanner-worker.min.js?url'

QrScanner.WORKER_PATH = QrScannerWorkerPath

/* ----------------------------- Theme (same as FGChecker.vue) ----------------------------- */
const theme = ref('dark')
function applyTheme(t) {
  document.documentElement.setAttribute('data-theme', t)
  localStorage.setItem('fgchecker-theme', t)
}
function toggleTheme() {
  theme.value = theme.value === 'dark' ? 'light' : 'dark'
  applyTheme(theme.value)
}
onMounted(() => {
  const saved = localStorage.getItem('fgchecker-theme')
  theme.value = saved || 'dark'
  applyTheme(theme.value)
  loadProcesses()
})

/* ----------------------------- Operator / Lot state ----------------------------- */
const operator = ref(null) // { idNo, name }
const lot = ref(null)      // { id, modelName, lotNo, qty, routingCode }
const scanError = ref('')

function changeOperator() {
  operator.value = null
  lot.value = null
  scanError.value = ''
  Object.keys(counts).forEach(k => delete counts[k])
}

function scanNewLot() {
  lot.value = null
  scanError.value = ''
  Object.keys(counts).forEach(k => delete counts[k])
  openCamera('lot')
}

/* ----------------------------- Camera / QR scanning ----------------------------- */
const showCamera = ref(false)
const cameraTarget = ref('operator') // 'operator' | 'lot'
const videoEl = ref(null)
let scannerInstance = null

async function openCamera(target) {
  scanError.value = ''
  cameraTarget.value = target
  showCamera.value = true
  await nextTick()
  try {
    scannerInstance = new QrScanner(videoEl.value, (result) => handleDecode(result.data), {
      highlightScanRegion: true,
      highlightCodeOutline: true,
      preferredCamera: 'environment',
    })
    await scannerInstance.start()
  } catch (e) {
    scanError.value = "Couldn't open the camera. Check that camera access is allowed for this site."
    showCamera.value = false
  }
}

function closeCamera() {
  showCamera.value = false
  if (scannerInstance) {
    scannerInstance.stop()
    scannerInstance.destroy()
    scannerInstance = null
  }
}

onBeforeUnmount(closeCamera)

function handleDecode(text) {
  if (cameraTarget.value === 'operator') {
    parseOperatorQr(text)
  } else {
    parseLotQr(text)
  }
}

/** Operator QR format: "00;ID No.;Name" */
function parseOperatorQr(text) {
  const parts = text.split(';')
  if (parts.length !== 3 || parts[0].trim() !== '00') {
    scanError.value = "That doesn't look like an operator QR code. Please scan the operator's badge."
    return
  }
  operator.value = { idNo: parts[1].trim(), name: parts[2].trim() }
  scanError.value = ''
  closeCamera()
}

/**
 * Lot traveler QR format: "ID;ModelName;LotNo;Qty;RoutingCode" — at least
 * 5 fields (4 semicolons). Extra trailing fields are tolerated and ignored.
 */
function parseLotQr(text) {
  const parts = text.split(';')
  if (parts.length < 5) {
    scanError.value = 'That doesn\u2019t look like a lot traveler QR code. Please scan the lot traveler.'
    return
  }
  lot.value = {
    id: parts[0].trim(),
    modelName: parts[1].trim(),
    lotNo: parts[2].trim(),
    qty: parts[3].trim(),
    routingCode: parts[4].trim(),
  }
  scanError.value = ''
  Object.keys(counts).forEach(k => delete counts[k])
  closeCamera()
}

/* ----------------------------- Result processes (live from DB) ----------------------------- */
const processes = ref([])
const loadingProcesses = ref(true)
const counts = reactive({})

async function loadProcesses() {
  loadingProcesses.value = true
  try {
    const { data } = await axios.get('/processes')
    processes.value = data.data ?? data
  } catch (e) {
    scanError.value = "Couldn't load the result options. Refresh the page to try again."
  } finally {
    loadingProcesses.value = false
  }
}

function typeClass(type) {
  const t = String(type ?? '').trim().toUpperCase()
  if (t === 'GOOD') return 'type-good'
  if (t === 'RELOAD') return 'type-reload'
  return 'type-notgood'
}

function typeIcon(type) {
  const t = String(type ?? '').trim().toUpperCase()
  if (t === 'GOOD') {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>'
  }
  if (t === 'RELOAD') {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg>'
  }
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>'
}

/* ----------------------------- Confirm + submit ----------------------------- */
const confirm = ref({ open: false, process: null })
const saving = ref(false)
const toast = ref(null)
let toastTimer = null

function selectProcess(p) {
  confirm.value = { open: true, process: p }
}
function cancelConfirm() {
  confirm.value = { open: false, process: null }
}

function showToast(message, type = 'good') {
  toast.value = { message, type }
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toast.value = null }, 2600)
}

async function confirmSelection() {
  const process = confirm.value.process
  if (!process || !operator.value || !lot.value) return

  saving.value = true
  try {
    await axios.post('/scan', {
      model_name: lot.value.modelName,
      lot_no: lot.value.lotNo,
      bcs_quantity: lot.value.qty,
      encoder: operator.value.name,
      result: process.Process,
    })
    counts[process.Process] = (counts[process.Process] || 0) + 1
    showToast(`Saved: ${process.Process}`, typeClass(process.Type) === 'type-good' ? 'good' : (typeClass(process.Type) === 'type-reload' ? 'reload' : 'notgood'))
    confirm.value = { open: false, process: null }
  } catch (e) {
    showToast("Couldn't save that. Please try again.", 'notgood')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Head title="FG Checker Scanning" />

  <div class="wrap">
    <!-- ============ HEADER ============ -->
    <header class="topbar">
      <div class="plate">
        <span class="plate-dot" aria-hidden="true"></span>
        <span class="plate-text">FG CHECKER</span>
      </div>

      <nav class="nav" aria-label="Main">
        <Link href="/" class="nav-link">Home</Link>
        <Link href="/scan" class="nav-link is-active">Scan</Link>
        <Link href="/print" class="nav-link">Print Sticker</Link>
        <Link href="/admin" class="nav-link">Admin</Link>
      </nav>

      <button class="switch" role="switch" :aria-checked="theme === 'dark'" @click="toggleTheme" title="Toggle light / dark">
        <span class="switch-track"><span class="switch-knob"></span></span>
        <span class="switch-label">{{ theme === 'dark' ? 'Dark' : 'Light' }}</span>
      </button>
    </header>

    <section class="scan-hero">
      <div class="hazard-strip" aria-hidden="true"></div>
      <p class="eyebrow">Finished Goods &middot; Line Inspection</p>
      <h1 class="hero-title-sm">Scan &amp; Record</h1>
      <p class="hero-sub">Scan the operator, then the lot traveler, then tap the result you see.</p>
    </section>

    <!-- ============ STEP 1 + 2 ============ -->
    <section class="steps">
      <div class="step-card" :class="{ done: operator }">
        <div class="step-num">1</div>
        <div class="step-body">
          <h2>Operator</h2>
          <p v-if="!operator" class="step-hint">Scan the operator's QR code to begin.</p>
          <div v-else class="scanned-info">
            <span class="info-label">Operator</span>
            <span class="info-value">{{ operator.name }} <span class="info-sub">(ID {{ operator.idNo }})</span></span>
          </div>
        </div>
        <button v-if="!operator" class="btn btn-primary" @click="openCamera('operator')">Scan Operator QR</button>
        <button v-else class="btn btn-ghost" @click="changeOperator">Change Operator</button>
      </div>

      <div class="step-card" :class="{ 'is-disabled': !operator, done: lot }">
        <div class="step-num">2</div>
        <div class="step-body">
          <h2>Lot Traveler</h2>
          <p v-if="!lot" class="step-hint">Scan the lot traveler QR code next.</p>
          <div v-else class="scanned-grid">
            <div><span class="info-label">Model</span><span class="info-value mono">{{ lot.modelName }}</span></div>
            <div><span class="info-label">Lot No.</span><span class="info-value mono">{{ lot.lotNo }}</span></div>
            <div><span class="info-label">Quantity</span><span class="info-value mono">{{ lot.qty }}</span></div>
          </div>
        </div>
        <button class="btn btn-primary" :disabled="!operator" @click="lot ? scanNewLot() : openCamera('lot')">
          {{ lot ? 'Scan New Lot' : 'Scan Lot QR' }}
        </button>
      </div>
    </section>

    <p v-if="scanError" class="scan-error">{{ scanError }}</p>

    <!-- ============ STEP 3: RESULTS ============ -->
    <section v-if="operator && lot" class="results">
      <h2 class="section-title">Select Result</h2>
      <p class="section-sub">Tap the button that matches, then confirm. Each tap records one unit.</p>

      <div v-if="loadingProcesses" class="empty-state">Loading result options&hellip;</div>
      <div v-else class="result-grid">
        <button
          v-for="p in processes"
          :key="p.Process"
          class="result-btn"
          :class="typeClass(p.Type)"
          @click="selectProcess(p)"
        >
          <span class="result-count">{{ counts[p.Process] || 0 }}</span>
          <span class="result-icon" v-html="typeIcon(p.Type)"></span>
          <span class="result-name">{{ p.Process }}</span>
        </button>
      </div>
    </section>

    <!-- ============ CAMERA MODAL ============ -->
    <Teleport to="body">
      <div v-if="showCamera" class="modal-overlay" @click.self="closeCamera">
        <div class="modal camera-modal">
          <h3>{{ cameraTarget === 'operator' ? 'Scan Operator QR Code' : 'Scan Lot Traveler QR Code' }}</h3>
          <div class="camera-frame">
            <video ref="videoEl" playsinline muted></video>
          </div>
          <p class="camera-hint">Hold the QR code steady inside the frame.</p>
          <button class="btn btn-ghost btn-block" @click="closeCamera">Cancel</button>
        </div>
      </div>
    </Teleport>

    <!-- ============ CONFIRM MODAL ============ -->
    <Teleport to="body">
      <div v-if="confirm.open" class="modal-overlay" @click.self="cancelConfirm">
        <div class="modal confirm-modal">
          <span class="confirm-icon" :class="typeClass(confirm.process?.Type)" v-html="typeIcon(confirm.process?.Type)"></span>
          <h3>Confirm Selection</h3>
          <p class="confirm-text">
            Record <strong>{{ confirm.process?.Process }}</strong> for lot <strong>{{ lot?.lotNo }}</strong>?
          </p>
          <div class="confirm-actions">
            <button class="btn btn-ghost" @click="cancelConfirm" :disabled="saving">Cancel</button>
            <button class="btn btn-primary" @click="confirmSelection" :disabled="saving">
              {{ saving ? 'Saving\u2026' : 'Confirm' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ============ TOAST ============ -->
    <Transition name="toast-fade">
      <div v-if="toast" class="toast" :class="'toast-' + toast.type">{{ toast.message }}</div>
    </Transition>
  </div>
</template>

<style scoped>

/* ===================== Design tokens (match FGChecker.vue) ===================== */
:global(html[data-theme='dark']) {
  --bg: #15181b;
  --bg-2: #1b1f23;
  --surface: #1e2327;
  --surface-2: #262b30;
  --text: #eae7df;
  --text-dim: #949c9f;
  --border: #33393e;
  --accent: #f2c14e;
  --accent-ink: #15181b;
  --pass: #4ac47f;
  --pass-ink: #0d1f14;
  --fail: #ef5b5b;
  --fail-ink: #2a0e0e;
  --reload: #f2b705;
  --reload-ink: #2b2103;
  --shadow: rgba(0, 0, 0, 0.45);
}
:global(html[data-theme='light']) {
  --bg: #f1ede2;
  --bg-2: #e9e3d3;
  --surface: #ffffff;
  --surface-2: #efe9db;
  --text: #201d17;
  --text-dim: #6a6355;
  --border: #d9d2bd;
  --accent: #c9950f;
  --accent-ink: #201d17;
  --pass: #1f8a4c;
  --pass-ink: #e6f5eb;
  --fail: #c62f2f;
  --fail-ink: #fbe9e9;
  --reload: #b8860b;
  --reload-ink: #fff6df;
  --shadow: rgba(120, 110, 80, 0.18);
}

.wrap {
  min-height: 100vh;
  background:
    radial-gradient(1100px 600px at 85% -10%, color-mix(in srgb, var(--accent) 10%, transparent), transparent 60%),
    var(--bg);
  color: var(--text);
  font-family: 'IBM Plex Sans', sans-serif;
}

/* ===================== Header (same as landing) ===================== */
.topbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  row-gap: 12px;
  gap: 24px;
  padding: 16px clamp(16px, 5vw, 56px);
  border-bottom: 1px solid var(--border);
  background: var(--bg-2);
}
.plate { display: flex; align-items: center; gap: 10px; font-family: 'Big Shoulders Display', sans-serif; font-weight: 800; font-size: 1.5rem; letter-spacing: 0.06em; }
.plate-dot { width: 12px; height: 12px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent); }
.nav { display: flex; gap: 6px; margin-left: 8px; }
.nav-link {
  display: inline-flex; align-items: center; min-height: 44px;
  padding: 10px 18px; border-radius: 8px; text-decoration: none;
  color: var(--text-dim); font-weight: 500; font-size: 1rem;
  border: 1px solid transparent; transition: color .15s ease, border-color .15s ease, background-color .15s ease;
}
.nav-link:hover { color: var(--text); background: var(--surface-2); }
.nav-link.is-active { color: var(--accent-ink); background: var(--accent); border-color: var(--accent); font-weight: 600; }
.switch {
  margin-left: auto; display: flex; align-items: center; gap: 10px;
  min-height: 44px; padding: 6px 10px; border-radius: 999px;
  background: none; border: none; cursor: pointer; color: var(--text); font-weight: 500;
}
.switch:hover { background: var(--surface-2); }
.switch-track { width: 46px; height: 26px; border-radius: 999px; background: var(--surface-2); border: 1px solid var(--border); position: relative; }
.switch-knob { position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; border-radius: 50%; background: var(--accent); transition: transform .2s ease; }
:global(html[data-theme='light']) .switch-knob { transform: translateX(20px); }

.nav-link:focus-visible, .btn:focus-visible, .switch:focus-visible, .result-btn:focus-visible {
  outline: 3px solid var(--accent);
  outline-offset: 2px;
}

/* ===================== Hero ===================== */
.scan-hero { position: relative; padding: 36px clamp(16px, 5vw, 56px) 8px; overflow: hidden; }
.hazard-strip { position: absolute; top: 0; left: 0; right: 0; height: 6px; background: repeating-linear-gradient(135deg, var(--accent) 0 18px, var(--bg-2) 18px 36px); opacity: 0.9; }
.eyebrow { text-transform: uppercase; letter-spacing: 0.14em; font-size: 0.8rem; color: var(--accent); font-weight: 600; margin: 0 0 10px; }
.hero-title-sm { font-family: 'Big Shoulders Display', sans-serif; font-weight: 800; font-size: clamp(2rem, 4.5vw, 3rem); margin: 0 0 8px; animation: rise .5s ease both; }
.hero-sub { color: var(--text-dim); margin: 0; font-size: 1.05rem; animation: rise .5s .08s ease both; }
@keyframes rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { .hero-title-sm, .hero-sub { animation: none; } }

/* ===================== Steps ===================== */
.steps {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  padding: 20px clamp(16px, 5vw, 56px) 8px;
}
@media (max-width: 860px) { .steps { grid-template-columns: 1fr; } }

.step-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  transition: opacity .2s ease, border-color .2s ease;
}
.step-card.is-disabled { opacity: 0.5; }
.step-card.done { border-color: color-mix(in srgb, var(--pass) 50%, var(--border)); }
.step-num {
  flex-shrink: 0;
  width: 40px; height: 40px;
  border-radius: 50%;
  background: var(--surface-2);
  border: 2px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  font-family: 'Big Shoulders Display', sans-serif;
  font-weight: 700; font-size: 1.2rem; color: var(--text-dim);
}
.step-card.done .step-num { background: var(--pass); border-color: var(--pass); color: var(--pass-ink); }
.step-body { flex: 1; min-width: 180px; }
.step-body h2 { font-family: 'Big Shoulders Display', sans-serif; font-size: 1.3rem; margin: 0 0 4px; }
.step-hint { margin: 0; color: var(--text-dim); font-size: 0.95rem; }

.scanned-info { display: flex; flex-direction: column; gap: 2px; }
.info-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-dim); }
.info-value { font-size: 1.05rem; font-weight: 600; }
.info-sub { font-weight: 400; color: var(--text-dim); font-size: 0.9rem; }
.mono { font-family: 'IBM Plex Mono', monospace; }

.scanned-grid { display: flex; gap: 20px; flex-wrap: wrap; }
.scanned-grid > div { display: flex; flex-direction: column; gap: 2px; }

.scan-error {
  margin: 0 clamp(16px, 5vw, 56px) 8px;
  padding: 12px 16px;
  border-radius: 10px;
  background: color-mix(in srgb, var(--fail) 15%, var(--surface));
  border: 1px solid var(--fail);
  color: var(--text);
  font-weight: 500;
}

/* ===================== Buttons ===================== */
.btn {
  border: none; border-radius: 10px; min-height: 48px; padding: 14px 26px;
  font-size: 1rem; font-weight: 600; cursor: pointer; text-decoration: none;
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  transition: transform .12s ease, box-shadow .12s ease, background-color .12s ease;
}
.btn:active { transform: translateY(1px); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary { background: var(--accent); color: var(--accent-ink); box-shadow: 0 6px 0 color-mix(in srgb, var(--accent) 55%, black); }
.btn-primary:hover:not(:disabled) { box-shadow: 0 4px 0 color-mix(in srgb, var(--accent) 55%, black); transform: translateY(2px); }
.btn-ghost { background: transparent; color: var(--text); border: 1.5px solid var(--border); }
.btn-ghost:hover:not(:disabled) { border-color: var(--accent); color: var(--accent); }
.btn-block { width: 100%; margin-top: 8px; }

/* ===================== Results grid ===================== */
.results { padding: 24px clamp(16px, 5vw, 56px) 48px; }
.section-title { font-family: 'Big Shoulders Display', sans-serif; font-size: 1.7rem; font-weight: 700; margin: 0 0 4px; }
.section-sub { margin: 0 0 18px; color: var(--text-dim); }

.empty-state { padding: 40px 0; text-align: center; color: var(--text-dim); }

.result-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 14px;
}
.result-btn {
  position: relative;
  min-height: 110px;
  border-radius: 14px;
  border: 2px solid transparent;
  cursor: pointer;
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
  padding: 16px 10px;
  font-family: 'IBM Plex Sans', sans-serif;
  font-weight: 600;
  font-size: 0.98rem;
  text-align: center;
  transition: transform .1s ease, box-shadow .15s ease;
}
.result-btn:active { transform: scale(0.97); }
.result-icon svg { width: 26px; height: 26px; }
.result-count {
  position: absolute; top: 8px; right: 10px;
  font-family: 'IBM Plex Mono', monospace;
  font-size: 0.85rem;
  background: rgba(0,0,0,0.18);
  padding: 2px 8px;
  border-radius: 999px;
}

.type-good { background: var(--pass); color: var(--pass-ink); }
.type-good:hover { box-shadow: 0 0 0 3px color-mix(in srgb, var(--pass) 45%, transparent); }
.type-notgood { background: var(--fail); color: var(--fail-ink); }
.type-notgood:hover { box-shadow: 0 0 0 3px color-mix(in srgb, var(--fail) 45%, transparent); }
.type-reload { background: var(--reload); color: var(--reload-ink); }
.type-reload:hover { box-shadow: 0 0 0 3px color-mix(in srgb, var(--reload) 45%, transparent); }

/* ===================== Modals ===================== */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(10, 10, 10, 0.55);
  backdrop-filter: blur(2px);
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
  z-index: 50;
}
.modal {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 28px;
  max-width: 420px;
  width: 100%;
  box-shadow: 0 20px 50px var(--shadow);
  animation: pop .18s ease both;
}
@keyframes pop { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
.modal h3 { font-family: 'Big Shoulders Display', sans-serif; font-size: 1.4rem; margin: 0 0 14px; text-align: center; }

.camera-frame {
  position: relative;
  border-radius: 12px;
  overflow: hidden;
  background: #000;
  aspect-ratio: 1 / 1;
}
.camera-frame video { width: 100%; height: 100%; object-fit: cover; }
.camera-hint { text-align: center; color: var(--text-dim); margin: 14px 0 0; font-size: 0.9rem; }

.confirm-modal { text-align: center; }
.confirm-icon {
  display: inline-flex; align-items: center; justify-content: center;
  width: 56px; height: 56px; border-radius: 50%; margin-bottom: 12px;
}
.confirm-icon svg { width: 28px; height: 28px; }
.confirm-text { color: var(--text-dim); margin: 0 0 20px; font-size: 1rem; }
.confirm-actions { display: flex; gap: 12px; }
.confirm-actions .btn { flex: 1; }

/* ===================== Toast ===================== */
.toast {
  position: fixed;
  left: 50%; bottom: 28px; transform: translateX(-50%);
  padding: 14px 24px;
  border-radius: 12px;
  font-weight: 600;
  box-shadow: 0 10px 30px var(--shadow);
  z-index: 60;
}
.toast-good { background: var(--pass); color: var(--pass-ink); }
.toast-notgood { background: var(--fail); color: var(--fail-ink); }
.toast-reload { background: var(--reload); color: var(--reload-ink); }
.toast-fade-enter-active, .toast-fade-leave-active { transition: opacity .2s ease, transform .2s ease; }
.toast-fade-enter-from, .toast-fade-leave-to { opacity: 0; transform: translate(-50%, 8px); }

/* ===================== Small tablets / phones ===================== */
@media (max-width: 480px) {
  .result-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); }
  .step-card { flex-direction: column; align-items: stretch; text-align: center; }
}
</style>
