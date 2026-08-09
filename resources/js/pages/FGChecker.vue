<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { ref, computed, onMounted, watch } from 'vue'
import axios from 'axios'

const props = defineProps({
    tableName: { type: String, default: () => `fgchecker${new Date().getFullYear()}` },
})

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
    getOrCreateTabletId()
})

const showTable = ref(false)
const loading = ref(false)
const errorMsg = ref('')
const records = ref([])

const today = new Date().toISOString().slice(0, 10)
const monthAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10)
const filters = ref({ from: monthAgo, to: today, modelName: '', lotNo: '' })

const page = ref(1)
const perPage = 15

const filteredRecords = computed(() => records.value)
const totalPages = computed(() => Math.max(1, Math.ceil(filteredRecords.value.length / perPage)))
const pageRecords = computed(() => {
    const start = (page.value - 1) * perPage
    return filteredRecords.value.slice(start, start + perPage)
})

watch(totalPages, (tp) => { if (page.value > tp) page.value = tp })

function buildParams(extra = {}) {
    const params = {
        from: filters.value.from,
        to: filters.value.to,
        ...extra,
    }
    if (filters.value.modelName.trim()) params.model_name = filters.value.modelName.trim()
    if (filters.value.lotNo.trim()) params.lot_no = filters.value.lotNo.trim()
    return params
}

async function loadRecords() {
    loading.value = true
    errorMsg.value = ''
    showTable.value = true
    try {
        const { data } = await axios.get('/records', { params: buildParams() })
        records.value = data.data ?? data
        page.value = 1
    } catch (e) {
        errorMsg.value = "Couldn't load the records. Check the connection and try again."
    } finally {
        loading.value = false
    }
}

function exportFile(format) {
    const params = new URLSearchParams(buildParams({ format }))
    window.location.href = `/export?${params.toString()}`
}

function goPage(n) {
    if (n < 1 || n > totalPages.value) return
    page.value = n
}

const passCount = computed(() => records.value.filter(r => resultType(r) === 'GOOD').length)
const failCount = computed(() => records.value.filter(r => resultType(r) === 'NOT GOOD').length)
const reloadCount = computed(() => records.value.filter(r => resultType(r) === 'RELOAD').length)

function resultType(record) {
    const type = String(record?.Type ?? '').trim().toUpperCase()
    return type === 'GOOD' || type === 'NOT GOOD' || type === 'RELOAD' ? type : 'NOT GOOD'
}

function resultBadgeClass(record) {
    const type = resultType(record)
    if (type === 'GOOD') return 'badge-good'
    if (type === 'RELOAD') return 'badge-reload'
    return 'badge-notgood'
}

function generateUUID() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

function getOrCreateTabletId() {
    let tabletId = localStorage.getItem('tablet_id');

    if (!tabletId) {
      tabletId = 'TABLET-' + generateUUID();
      localStorage.setItem('tablet_id', tabletId);
    }

    registerTabletID();

    return tabletId;
}

async function registerTabletID(){
    try {
        await axios.post('/tabletID', {
            tabletID: localStorage.getItem('tablet_id')
        })
        showToast(`Saved: Tablet ID`)
    } catch (e) {
        showToast("Couldn't save tablet ID. Please reload.")
    }
}

const tabletId = getOrCreateTabletId();
console.log("Tablet ID:", tabletId);
</script>

<template>
    <Head title="FG Checker" />

    <div class="wrap">
        <header class="topbar">
            <div class="plate">
                <span class="plate-dot" aria-hidden="true"></span>
                <span class="plate-text">FG CHECKER</span>
            </div>

            <nav class="nav" aria-label="Main">
                <Link href="/fgchecker" class="nav-link is-active">Home</Link>
                <Link href="/scan" class="nav-link">Scan</Link>
                <Link href="/print" class="nav-link">Print Sticker</Link>
                <Link href="/admin" class="nav-link">Admin</Link>
            </nav>

            <button class="switch" role="switch" :aria-checked="theme === 'dark'" @click="toggleTheme" title="Toggle light / dark">
                <span class="switch-track">
                    <span class="switch-knob"></span>
                </span>
                <span class="switch-label">{{ theme === 'dark' ? 'Dark' : 'Light' }}</span>
            </button>
        </header>

        <section class="hero">
            <div class="hazard-strip" aria-hidden="true"></div>
            <div class="hero-inner">
                <p class="eyebrow">Finished Goods &middot; Line Inspection</p>
                <h1 class="hero-title">Check it once.<br />Ship it right.</h1>
                <p class="hero-sub">
                Scan finished goods, print validation stickers, and pull up today's
                shift records &mdash; all from one screen built for the line, not the office.
                </p>

                <div class="hero-actions">
                    <Link href="/scan" class="btn btn-primary">Start Scanning</Link>
                    <Link href="/print" class="btn btn-ghost">Print a Sticker</Link>
                </div>
                <div class="mt-10">
                    <p class="eyebrow2">Tablet ID: {{ tabletId }}</p>
                    <p class="hero-sub2">
                    Do not clear browser cache unless necessary.
                    </p>
                </div>
            </div>

            <div class="stamp-row" aria-hidden="true">
                <div class="stamp stamp-good">GOOD</div>
                <div class="stamp stamp-notgood">NOT GOOD</div>
                <div class="stamp stamp-reload">RELOAD</div>
            </div>
        </section>

        <section class="records">
            <div class="records-head">
                <div>
                    <h2 class="section-title">Shift Records</h2>
                    <p class="section-sub">From table <code>{{ tableName }}</code></p>
                </div>
                <button class="btn btn-primary" @click="loadRecords" :disabled="loading">
                    {{ loading ? 'Loading…' : (showTable ? 'Reload Records' : 'Load Records') }}
                </button>
            </div>

            <Transition name="reveal">
                <div v-if="showTable" class="panel">
                    <div class="filter-bar">
                        <label class="field">
                            <span>From date</span>
                            <input type="date" v-model="filters.from" />
                        </label>
                        <label class="field">
                            <span>To date</span>
                            <input type="date" v-model="filters.to" />
                        </label>
                        <label class="field">
                            <span>Model name</span>
                            <input
                                type="text"
                                v-model="filters.modelName"
                                placeholder="e.g. ABC-0123G"
                                @keyup.enter="loadRecords"
                            />
                        </label>
                        <label class="field">
                            <span>Lot no.</span>
                            <input
                                type="text"
                                v-model="filters.lotNo"
                                placeholder="e.g. 0001-01-X"
                                @keyup.enter="loadRecords"
                            />
                        </label>
                        <button class="btn btn-outline" @click="loadRecords" :disabled="loading">
                            Apply Filter
                        </button>

                        <div class="spacer"></div>

                        <div class="export-group">
                            <span class="export-label">Export as</span>
                            <button class="btn btn-chip" @click="exportFile('xlsx')">Excel</button>
                            <button class="btn btn-chip" @click="exportFile('csv')">CSV</button>
                        </div>
                    </div>

                    <div class="counters">
                        <div class="counter counter-good">
                            <span class="counter-num">{{ passCount }}</span>
                            <span class="counter-label">Good</span>
                        </div>
                        <div class="counter counter-notgood">
                            <span class="counter-num">{{ failCount }}</span>
                            <span class="counter-label">Not Good</span>
                        </div>
                        <div class="counter counter-reload">
                            <span class="counter-num">{{ reloadCount }}</span>
                            <span class="counter-label">Reload</span>
                        </div>
                        <div class="counter">
                            <span class="counter-num">{{ records.length }}</span>
                            <span class="counter-label">Total shown</span>
                        </div>
                    </div>

                    <p v-if="errorMsg" class="error-note">{{ errorMsg }}</p>

                    <div class="table-scroll">
                        <table v-if="!loading && pageRecords.length">
                            <thead>
                                <tr>
                                <th>Shift Date/Time</th>
                                <th>Area</th>
                                <th>Result</th>
                                <th>Model</th>
                                <th>Lot No.</th>
                                <th class="num">BCS Qty</th>
                                <th class="num">Output Qty</th>
                                <th class="num">Output Factor</th>
                                <th class="num">Final Qty</th>
                                <th>Encoder</th>
                                <th>IP Address</th>
                                <th>FG Checker</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(r, i) in pageRecords" :key="i">
                                <td class="mono" data-label="Shift Date/Time">{{ r.Shift_Date_Time }}</td>
                                <td data-label="Area">{{ r.Area }}</td>
                                <td data-label="Result">
                                    <span class="badge" :class="resultBadgeClass(r)">
                                    {{ r.Result }}
                                    </span>
                                </td>
                                <td data-label="Model">{{ r.Model_Name }}</td>
                                <td class="mono" data-label="Lot No.">{{ r.Lot_No }}</td>
                                <td class="num mono" data-label="BCS Qty">{{ r.BCS_Quantity }}</td>
                                <td class="num mono" data-label="Output Qty">{{ r.Output_Quantity }}</td>
                                <td class="num mono" data-label="Output Factor">{{ r.Output_Factor }}</td>
                                <td class="num mono" data-label="Final Qty">{{ r.Final_Quantity }}</td>
                                <td data-label="Encoder">{{ r.Encoder }}</td>
                                <td class="mono" data-label="IP Address">{{ r.IP_Address }}</td>
                                <td data-label="FG Checker">{{ r.FG_Checker }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-else-if="loading" class="empty-state">Pulling shift records&hellip;</div>

                        <div v-else class="empty-state">
                            <p><strong>No records in this date range.</strong></p>
                            <p>Try widening the from/to dates, or clearing the model/lot filters above, then select Apply Filter.</p>
                        </div>
                    </div>

                    <div v-if="totalPages > 1" class="pagination">
                        <button class="page-btn" @click="goPage(page - 1)" :disabled="page === 1">&larr; Previous</button>
                        <span class="page-status">Page {{ page }} of {{ totalPages }}</span>
                        <button class="page-btn" @click="goPage(page + 1)" :disabled="page === totalPages">Next &rarr;</button>
                    </div>
                </div>
            </Transition>
        </section>

        <footer class="foot">
        <span>FG Checker &middot; Finished Goods Inspection System</span>
        </footer>
    </div>
</template>

<style scoped>

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
    transition: background-color .3s ease, color .3s ease;
}
code {
    font-family: 'IBM Plex Mono', monospace;
}
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
.plate {
    display: flex;
    align-items: center;
    gap: 10px;
    font-family: 'Big Shoulders Display', sans-serif;
    font-weight: 800;
    font-size: 1.5rem;
    letter-spacing: 0.06em;
}
.plate-dot {
    width: 12px; height: 12px; border-radius: 50%;
    background: var(--accent);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent);
}
.nav {
    display: flex;
    gap: 6px;
    margin-left: 8px;
}
.nav-link {
    display: inline-flex;
    align-items: center;
    min-height: 44px;
    padding: 10px 18px;
    border-radius: 8px;
    text-decoration: none;
    color: var(--text-dim);
    font-weight: 500;
    font-size: 1rem;
    border: 1px solid transparent;
    transition: color .15s ease, border-color .15s ease, background-color .15s ease;
}
.nav-link:hover {
    color: var(--text);
    background: var(--surface-2);
}
.nav-link.is-active {
    color: var(--accent-ink);
    background: var(--accent);
    border-color: var(--accent);
    font-weight: 600;
}
.nav-link:focus-visible, .btn:focus-visible, input:focus-visible, .switch:focus-visible, .page-btn:focus-visible {
    outline: 3px solid var(--accent);
    outline-offset: 2px;
}
.switch {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 44px;
    padding: 6px 10px;
    border-radius: 999px;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text);
    font-weight: 500;
}
.switch:hover { background: var(--surface-2); }
.switch-track {
    width: 46px;
    height: 26px;
    border-radius: 999px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    position: relative;
    transition: background-color .2s ease;
}
.switch-knob {
    position: absolute;
    top: 2px; left: 2px;
    width: 20px; height: 20px;
    border-radius: 50%;
    background: var(--accent);
    transition: transform .2s ease;
}
:global(html[data-theme='light']) .switch-knob {
    transform: translateX(20px);
}
.hero {
    position: relative;
    padding: clamp(48px, 8vw, 88px) clamp(20px, 5vw, 56px) 40px;
    overflow: hidden;
}
.hazard-strip {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 6px;
    background: repeating-linear-gradient(135deg, var(--accent) 0 18px, var(--bg-2) 18px 36px);
    opacity: 0.9;
}
.hero-inner {
    max-width: 760px;
}
.eyebrow {
    text-transform: uppercase;
    letter-spacing: 0.14em;
    font-size: 0.8rem;
    color: var(--accent);
    font-weight: 600;
    margin: 0 0 14px;
}
.eyebrow2 {
    text-transform: uppercase;
    letter-spacing: 0.14em;
    font-size: 0.6rem;
    color: var(--accent);
    font-weight: 600;
    margin: 0 0 14px;
}
.hero-title {
    font-family: 'Big Shoulders Display', sans-serif;
    font-weight: 800;
    font-size: clamp(2.6rem, 6vw, 4.4rem);
    line-height: 0.98;
    letter-spacing: 0.01em;
    margin: 0 0 20px;
    animation: rise .6s ease both;
}
.hero-sub {
    font-size: 1.1rem;
    color: var(--text-dim);
    max-width: 52ch;
    line-height: 1.55;
    margin: 0 0 32px;
    animation: rise .6s .1s ease both;
}
.hero-sub2 {
    font-size: 0.7rem;
    color: var(--text-dim);
    max-width: 52ch;
    line-height: 1.55;
    margin: 0 0 32px;
    animation: rise .6s .1s ease both;
}
.hero-actions {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    animation: rise .6s .2s ease both;
}
@keyframes rise {
    from {
        opacity: 0;
        transform: translateY(14px);
    } to {
        opacity: 1;
        transform: translateY(0);
    }
}
@media (prefers-reduced-motion: reduce) {
    .hero-title, .hero-sub, .hero-actions {
        animation: none;
    }
}
.btn {
    border: none;
    border-radius: 10px;
    min-height: 48px;
    padding: 14px 26px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: transform .12s ease, box-shadow .12s ease, background-color .12s ease;
}
.btn:active {
    transform: translateY(1px);
}
.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
 }
.btn-primary {
    background: var(--accent);
    color: var(--accent-ink);
    box-shadow: 0 6px 0 color-mix(in srgb, var(--accent) 55%, black);
}
.btn-primary:hover:not(:disabled) {
    box-shadow: 0 4px 0 color-mix(in srgb, var(--accent) 55%, black);
    transform: translateY(2px);
}
.btn-ghost {
    background: transparent;
    color: var(--text);
    border: 1.5px solid var(--border);
}
.btn-ghost:hover {
    border-color: var(--accent);
    color: var(--accent);
}
.btn-outline {
    background: var(--surface);
    color: var(--text);
    border: 1.5px solid var(--border);
}
.btn-outline:hover:not(:disabled) {
    border-color: var(--accent);
}
.btn-chip {
    background: var(--surface-2);
    color: var(--text);
    border: 1px solid var(--border);
    padding: 10px 18px;
    font-size: 0.92rem;
}
.btn-chip:hover {
    background: var(--accent);
    color: var(--accent-ink);
    border-color: var(--accent);
}
.stamp-row {
    position: absolute;
    right: clamp(16px, 6vw, 72px);
    top: clamp(40px, 10vw, 90px);
    display: flex;
    gap: 22px;
}
.stamp {
    font-family: 'Big Shoulders Display', sans-serif;
    font-weight: 800;
    font-size: 1.3rem;
    letter-spacing: 0.12em;
    padding: 10px 18px;
    border: 3px double currentColor;
    border-radius: 6px;
    opacity: 0.85;
}
.stamp-good {
    color: var(--pass);
    transform: rotate(-7deg);
}
.stamp-notgood {
    color: var(--fail);
    transform: rotate(4deg);
}
.stamp-reload {
    color: var(--reload);
    transform: rotate(-3deg);
    font-size: 1.05rem;
}
@media (max-width: 820px) { .stamp-row { display: none; } }
.records {
    padding: 12px clamp(20px, 5vw, 56px) 64px;
}
.records-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 18px;
    padding-top: 28px;
    border-top: 1px solid var(--border);
}
.section-title {
    font-family: 'Big Shoulders Display', sans-serif;
    font-size: 1.9rem;
    font-weight: 700;
    margin: 0 0 4px;
}
.section-sub {
    margin: 0;
    color: var(--text-dim);
    font-size: 0.95rem;
}
.reveal-enter-active {
    transition: opacity .3s ease, transform .3s ease;
}
.reveal-enter-from {
    opacity: 0;
    transform: translateY(10px);
}
.panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 10px 30px var(--shadow);
}
.filter-bar {
    display: flex;
    align-items: end;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}
.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 0.85rem;
    color: var(--text-dim);
    font-weight: 500;
    max-width: 200px;
}
.field input {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 8px;
    min-height: 46px;
    padding: 10px 12px;
    color: var(--text);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 1rem;
    width: 100%;
}
.spacer {
    flex: 1;
    min-width: 8px;
}
.export-group {
    display: flex;
    align-items: center;
    gap: 10px;
}
.export-label {
    color: var(--text-dim);
    font-size: 0.85rem;
    font-weight: 500;
}
.counters {
    display: flex;
    gap: 14px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.counter {
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 20px;
    display: flex;
    flex-direction: column;
    min-width: 110px;
}
.counter-num {
    font-family: 'Big Shoulders Display', sans-serif;
    font-size: 1.8rem;
    font-weight: 700;
    line-height: 1;
}
.counter-label {
    color: var(--text-dim);
    font-size: 0.82rem;
    margin-top: 4px;
}
.counter-good .counter-num { color: var(--pass); }
.counter-notgood .counter-num { color: var(--fail); }
.counter-reload .counter-num { color: var(--reload); }
.error-note {
    color: var(--fail);
    font-weight: 500;
    margin: 0 0 14px;
}
.table-scroll {
    overflow-x: auto;
    border-radius: 10px;
    border: 1px solid var(--border);
}
table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}
thead th {
    text-align: left;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-dim);
    background: var(--surface-2);
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
tbody td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    font-size: 0.92rem;
    white-space: nowrap;
}
tbody tr:hover {
    background: var(--surface-2);
}
.num {
    text-align: right;
}
.mono {
    font-family: 'IBM Plex Mono', monospace;
}
.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.78rem;
    letter-spacing: 0.05em;
}
.badge-good { background: var(--pass); color: var(--pass-ink); }
.badge-notgood { background: var(--fail); color: var(--fail-ink); }
.badge-reload { background: var(--reload); color: var(--reload-ink); }
.empty-state {
    padding: 48px 20px;
    text-align: center;
    color: var(--text-dim);
}
.pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 18px;
    margin-top: 18px;
}
.page-btn {
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 500;
}
.page-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.page-btn:hover:not(:disabled) {
    border-color: var(--accent);
    color: var(--accent);
}
.page-status {
    color: var(--text-dim);
    font-size: 0.9rem;
}
.foot {
    text-align: center;
    color: var(--text-dim);
    padding: 24px;
    font-size: 0.85rem;
    border-top: 1px solid var(--border);
}
@media (max-width: 1024px) {
    .stamp-row {
        right: 24px;
        top: 32px;
    }
    .stamp {
        font-size: 1.1rem;
        padding: 8px 14px;
    }
}
@media (max-width: 860px) {
    .nav {
        width: 100%;
        order: 3;
        justify-content: flex-start;
    }
    .plate {
        order: 1;
    }
    .switch {
        order: 2;
        margin-left: auto;
    }
    .filter-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .field {
        width: 100%;
        max-width: none;
    }
    .field input {
        width: 100%;
    }
    .btn-outline {
        width: 100%;
    }
    .export-group {
        width: 100%;
        justify-content: space-between;
    }
    .spacer {
        display: none;
    }
    .counters {
        flex-wrap: wrap;
    }
    .counter {
        flex: 1 1 30%;
    }
    .table-scroll {
        overflow: visible;
        border: none;
    }
    table {
        min-width: 0;
        width: 100%;
    }
    thead {
        display: none;
    }
    tbody, tr, td {
        display: block;
        width: 100%;
    }
    tr {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 6px 14px;
        margin-bottom: 14px;
    }
    tbody td {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
        white-space: normal;
        text-align: right;
        font-size: 0.95rem;
    }
    tbody tr td:last-child {
        border-bottom: none;
    }
    tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--text-dim);
        text-align: left;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        flex-shrink: 0;
    }
    .num { text-align: right; }
}

@media (max-width: 480px) {
    .hero-actions {
        flex-direction: column;
        align-items: stretch;
    }
    .counter {
        flex: 1 1 100%;
    }
    .plate-text {
        font-size: 1.2rem;
    }
}
</style>
