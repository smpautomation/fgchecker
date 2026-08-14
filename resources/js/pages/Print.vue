<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted } from 'vue'
import axios from 'axios'

const theme = ref('dark')

function applyTheme(t) {
  document.documentElement.setAttribute('data-theme', t)
  localStorage.setItem('fgchecker-theme', t)
}

function toggleTheme() {
  theme.value = theme.value === 'dark' ? 'light' : 'dark'
  applyTheme(theme.value)
}

function getTabletId() {
  return localStorage.getItem('tablet_id') || null
}

const tabletId = ref(getTabletId())

onMounted(() => {
  const saved = localStorage.getItem('fgchecker-theme')
  theme.value = saved || 'dark'
  applyTheme(theme.value)
  loadModels()
  loadValidationTypes()
})

const activeTab = ref('sticker')

const models = ref([])
const validationTypes = ref([])
const loadingOptions = ref(true)
const selectedModel = ref('')
const selectedValidation = ref('')
const printingSticker = ref(false)
const stickerResult = ref(null)

async function loadModels() {
  try {
    const { data } = await axios.get('/models')
    models.value = data.data ?? data ?? []
  } catch (e) {

  }
}

async function loadValidationTypes() {
  loadingOptions.value = true
  try {
    const { data } = await axios.get('/validations')
    validationTypes.value = data.data ?? data ?? []
  } finally {
    loadingOptions.value = false
  }
}

async function printSticker() {
  if (!selectedModel.value || !selectedValidation.value) return
  if (!tabletId.value) {
    stickerResult.value = { ok: false, message: 'This tablet has no ID yet. Visit the Scan page once, then come back.' }
    return
  }
  printingSticker.value = true
  stickerResult.value = null
  try {
    const { data } = await axios.post('/print/validation-sticker', {
      model_name: selectedModel.value,
      validation_name: selectedValidation.value,
      tablet_id: tabletId.value,
    })
    stickerResult.value = { ok: true, message: data.message || 'Sticker sent to printer.' }
  } catch (e) {
    stickerResult.value = { ok: false, message: e.response?.data?.message || "Couldn't print. Please contact PIC." }
  } finally {
    printingSticker.value = false
  }
}

const rtvFile = ref(null)
const rtvFileName = ref('')
const printingRtv = ref(false)
const rtvResult = ref(null)
const fileInputEl = ref(null)

function handleFileChange(e) {
  const file = e.target.files?.[0] || null
  rtvFile.value = file
  rtvFileName.value = file?.name || ''
  rtvResult.value = null
}

async function printRtv() {
  if (!rtvFile.value) return
  if (!tabletId.value) {
    rtvResult.value = { ok: false, message: 'This tablet has no ID yet. Visit the Scan page once, then come back.' }
    return
  }
  printingRtv.value = true
  rtvResult.value = null
  try {
    const formData = new FormData()
    formData.append('file', rtvFile.value)
    formData.append('tablet_id', tabletId.value)
    const { data } = await axios.post('/print/rtv', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    rtvResult.value = { ok: true, message: data.message || 'Stickers sent to printer.' }
    rtvFile.value = null
    rtvFileName.value = ''
    if (fileInputEl.value) fileInputEl.value.value = ''
  } catch (e) {
    rtvResult.value = { ok: false, message: e.response?.data?.message || "Couldn't print. Please contact PIC." }
  } finally {
    printingRtv.value = false
  }
}
</script>

<template>
  <Head title="Print Validation Sticker / RTV FG QR Code" />

  <div class="wrap">
    <header class="topbar">
      <div class="plate">
        <span class="plate-dot" aria-hidden="true"></span>
        <span class="plate-text">FG CHECKER</span>
      </div>

      <nav class="nav" aria-label="Main">
        <Link href="/" class="nav-link">Home</Link>
        <Link href="/scan" class="nav-link">Scan</Link>
        <Link href="/print" class="nav-link is-active">Print Sticker</Link>
        <Link href="/admin" class="nav-link">Admin</Link>
      </nav>

      <button class="switch" role="switch" :aria-checked="theme === 'dark'" @click="toggleTheme" title="Toggle light / dark">
        <span class="switch-track"><span class="switch-knob"></span></span>
        <span class="switch-label">{{ theme === 'dark' ? 'Dark' : 'Light' }}</span>
      </button>
    </header>

    <section class="print-hero">
      <div class="hazard-strip" aria-hidden="true"></div>
      <p class="eyebrow">Finished Goods &middot; Line Inspection</p>
      <h1 class="hero-title-sm">Print Stickers</h1>
      <p class="hero-sub">Print a validation sticker, or print RTV FG box labels from a spreadsheet.</p>
    </section>

    <p v-if="!tabletId" class="scan-error tabletid-warning">
      This tablet doesn't have an ID yet. Open the Scan page once to register it, then come back here to print.
    </p>

    <div class="tabs" role="tablist">
      <button
        role="tab"
        :aria-selected="activeTab === 'sticker'"
        class="tab-btn"
        :class="{ active: activeTab === 'sticker' }"
        @click="activeTab = 'sticker'"
      >
        Validation Sticker
      </button>
      <button
        role="tab"
        :aria-selected="activeTab === 'rtv'"
        class="tab-btn"
        :class="{ active: activeTab === 'rtv' }"
        @click="activeTab = 'rtv'"
      >
        RTV FG Box
      </button>
    </div>

    <section v-if="activeTab === 'sticker'" class="panel-section">
      <div class="panel">
        <h2 class="section-title">Print a Validation Sticker</h2>
        <p class="section-sub">Used to test that the printer and QR reader are working correctly.</p>

        <div class="form-grid">
          <label class="field">
            <span>Model name</span>
            <select v-model="selectedModel">
              <option value="" disabled>Select a model</option>
              <option v-for="m in models" :key="m" :value="m">{{ m }}</option>
            </select>
          </label>

          <label class="field">
            <span>Validation type</span>
            <select v-model="selectedValidation" :disabled="loadingOptions">
              <option value="" disabled>{{ loadingOptions ? 'Loading\u2026' : 'Select a type' }}</option>
              <option v-for="v in validationTypes" :key="v" :value="v">{{ v }}</option>
            </select>
          </label>
        </div>

        <button
          class="btn btn-primary btn-wide"
          :disabled="!selectedModel || !selectedValidation || printingSticker"
          @click="printSticker"
        >
          {{ printingSticker ? 'Printing\u2026' : 'Print Sticker' }}
        </button>

        <p v-if="stickerResult" class="result-banner" :class="stickerResult.ok ? 'result-ok' : 'result-error'">
          {{ stickerResult.message }}
        </p>
      </div>
    </section>

    <section v-if="activeTab === 'rtv'" class="panel-section">
      <div class="panel">
        <h2 class="section-title">Print RTV FG Box Labels</h2>
        <p class="section-sub">Upload the RTV spreadsheet &mdash; one sticker will print for every row.</p>

        <label class="file-drop" :class="{ 'has-file': rtvFileName }">
          <input ref="fileInputEl" type="file" accept=".xls,.xlsx" @change="handleFileChange" />
          <span v-if="!rtvFileName">Tap to choose an Excel file (.xls or .xlsx)</span>
          <span v-else class="file-drop-name">{{ rtvFileName }}</span>
        </label>

        <button
          class="btn btn-primary btn-wide"
          :disabled="!rtvFile || printingRtv"
          @click="printRtv"
        >
          {{ printingRtv ? 'Uploading & Printing\u2026' : 'Upload & Print' }}
        </button>

        <p v-if="rtvResult" class="result-banner" :class="rtvResult.ok ? 'result-ok' : 'result-error'">
          {{ rtvResult.message }}
        </p>
      </div>
    </section>
  </div>
</template>

<style scoped>
:global(html[data-theme='dark']) {
  --bg: #15181b; --bg-2: #1b1f23; --surface: #1e2327; --surface-2: #262b30;
  --text: #eae7df; --text-dim: #949c9f; --border: #33393e;
  --accent: #f2c14e; --accent-ink: #15181b;
  --pass: #4ac47f; --pass-ink: #0d1f14;
  --fail: #ef5b5b; --fail-ink: #2a0e0e;
  --reload: #f2b705; --reload-ink: #2b2103;
  --shadow: rgba(0, 0, 0, 0.45);
}
:global(html[data-theme='light']) {
  --bg: #f1ede2; --bg-2: #e9e3d3; --surface: #ffffff; --surface-2: #efe9db;
  --text: #201d17; --text-dim: #6a6355; --border: #d9d2bd;
  --accent: #c9950f; --accent-ink: #201d17;
  --pass: #1f8a4c; --pass-ink: #e6f5eb;
  --fail: #c62f2f; --fail-ink: #fbe9e9;
  --reload: #b8860b; --reload-ink: #fff6df;
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

.topbar { display: flex; align-items: center; flex-wrap: wrap; row-gap: 12px; gap: 24px; padding: 16px clamp(16px, 5vw, 56px); border-bottom: 1px solid var(--border); background: var(--bg-2); }
.plate { display: flex; align-items: center; gap: 10px; font-family: 'Big Shoulders Display', sans-serif; font-weight: 800; font-size: 1.5rem; letter-spacing: 0.06em; }
.plate-dot { width: 12px; height: 12px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent); }
.nav { display: flex; gap: 6px; margin-left: 8px; }
.nav-link { display: inline-flex; align-items: center; min-height: 44px; padding: 10px 18px; border-radius: 8px; text-decoration: none; color: var(--text-dim); font-weight: 500; font-size: 1rem; border: 1px solid transparent; transition: color .15s ease, border-color .15s ease, background-color .15s ease; }
.nav-link:hover { color: var(--text); background: var(--surface-2); }
.nav-link.is-active { color: var(--accent-ink); background: var(--accent); border-color: var(--accent); font-weight: 600; }
.switch { margin-left: auto; display: flex; align-items: center; gap: 10px; min-height: 44px; padding: 6px 10px; border-radius: 999px; background: none; border: none; cursor: pointer; color: var(--text); font-weight: 500; }
.switch:hover { background: var(--surface-2); }
.switch-track { width: 46px; height: 26px; border-radius: 999px; background: var(--surface-2); border: 1px solid var(--border); position: relative; }
.switch-knob { position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; border-radius: 50%; background: var(--a  ccent); transition: transform .2s ease; }
:global(html[data-theme='light']) .switch-knob { transform: translateX(20px); }
.nav-link:focus-visible, .btn:focus-visible, .switch:focus-visible, .tab-btn:focus-visible, select:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }

.print-hero { position: relative; padding: 36px clamp(16px, 5vw, 56px) 8px; overflow: hidden; }
.hazard-strip { position: absolute; top: 0; left: 0; right: 0; height: 6px; background: repeating-linear-gradient(135deg, var(--accent) 0 18px, var(--bg-2) 18px 36px); opacity: 0.9; }
.eyebrow { text-transform: uppercase; letter-spacing: 0.14em; font-size: 0.8rem; color: var(--accent); font-weight: 600; margin: 0 0 10px; }
.hero-title-sm { font-family: 'Big Shoulders Display', sans-serif; font-weight: 800; font-size: clamp(2rem, 4.5vw, 3rem); margin: 0 0 8px; }
.hero-sub { color: var(--text-dim); margin: 0; font-size: 1.05rem; }

.scan-error, .tabletid-warning {
  margin: 8px clamp(16px, 5vw, 56px) 0;
  padding: 12px 16px;
  border-radius: 10px;
  background: color-mix(in srgb, var(--fail) 15%, var(--surface));
  border: 1px solid var(--fail);
  color: var(--text);
  font-weight: 500;
}

.tabs {
  display: flex;
  gap: 8px;
  padding: 20px clamp(16px, 5vw, 56px) 0;
}
.tab-btn {
  min-height: 48px;
  padding: 12px 24px;
  border-radius: 10px 10px 0 0;
  border: 1px solid var(--border);
  border-bottom: none;
  background: var(--surface-2);
  color: var(--text-dim);
  font-weight: 600;
  font-size: 1rem;
  cursor: pointer;
}
.tab-btn.active { background: var(--surface); color: var(--text); }
.tab-btn:not(.active):hover { color: var(--text); }

.panel-section { padding: 0 clamp(16px, 5vw, 56px) 56px; }
.panel {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 0 14px 14px 14px;
  padding: 28px;
  box-shadow: 0 10px 30px var(--shadow);
  max-width: 640px;
}
.section-title { font-family: 'Big Shoulders Display', sans-serif; font-size: 1.6rem; font-weight: 700; margin: 0 0 4px; }
.section-sub { margin: 0 0 22px; color: var(--text-dim); }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 22px; }
@media (max-width: 560px) { .form-grid { grid-template-columns: 1fr; } }

.field { display: flex; flex-direction: column; gap: 6px; font-size: 0.85rem; color: var(--text-dim); font-weight: 500; }
.field select {
  min-height: 48px;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--surface-2);
  color: var(--text);
  font-size: 1rem;
  font-family: 'IBM Plex Sans', sans-serif;
}

.btn { border: none; border-radius: 10px; min-height: 48px; padding: 14px 26px; font-size: 1rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: transform .12s ease, box-shadow .12s ease; }
.btn:active { transform: translateY(1px); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary { background: var(--accent); color: var(--accent-ink); box-shadow: 0 6px 0 color-mix(in srgb, var(--accent) 55%, black); }
.btn-primary:hover:not(:disabled) { box-shadow: 0 4px 0 color-mix(in srgb, var(--accent) 55%, black); transform: translateY(2px); }
.btn-wide { width: 100%; }

.result-banner { margin: 18px 0 0; padding: 12px 16px; border-radius: 10px; font-weight: 500; }
.result-ok { background: color-mix(in srgb, var(--pass) 18%, var(--surface)); border: 1px solid var(--pass); color: var(--text); }
.result-error { background: color-mix(in srgb, var(--fail) 15%, var(--surface)); border: 1px solid var(--fail); color: var(--text); }

.file-drop {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 96px;
  border: 2px dashed var(--border);
  border-radius: 12px;
  background: var(--surface-2);
  color: var(--text-dim);
  font-weight: 500;
  text-align: center;
  padding: 16px;
  cursor: pointer;
  margin-bottom: 20px;
  transition: border-color .15s ease, color .15s ease;
}
.file-drop:hover { border-color: var(--accent); color: var(--accent); }
.file-drop.has-file { border-style: solid; border-color: var(--pass); color: var(--text); }
.file-drop input { display: none; }
.file-drop-name { font-family: 'IBM Plex Mono', monospace; word-break: break-all; }
</style>
