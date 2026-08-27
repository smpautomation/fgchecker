<script setup>
/**
 * Admin.vue — Administrator Access
 *
 * Gated by the scanning tablet's own Role column (TabletRecord.Role === 'admin'),
 * not a user login — matches the tablet_id-based identity used across the app.
 *
 * Expected backend routes (see AdminController):
 *   GET  /admin/access?tablet_id=...      -> { isAdmin }
 *   GET  /admin/models | validations | processes | tablets -> { data: [...] }
 *   POST /admin/{resource}                -> { data }
 *   PUT  /admin/{resource}/{id}           -> { data }
 *   DELETE /admin/{resource}/{id}         -> { message }
 */
import { Head, Link } from '@inertiajs/vue3'
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

/* ----------------------------- Theme ----------------------------- */
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

/* ----------------------------- Access gate ----------------------------- */
const checkingAccess = ref(true)
const isAdmin = ref(false)

async function checkAccess() {
  checkingAccess.value = true
  if (!tabletId.value) {
    isAdmin.value = false
    checkingAccess.value = false
    return
  }
  try {
    const { data } = await axios.get('/admin/access', { params: { tablet_id: tabletId.value } })
    isAdmin.value = !!data.isAdmin
  } catch (e) {
    isAdmin.value = false
  } finally {
    checkingAccess.value = false
  }
}

onMounted(async () => {
  const saved = localStorage.getItem('fgchecker-theme')
  theme.value = saved || 'dark'
  applyTheme(theme.value)
  await checkAccess()
  if (isAdmin.value) {
    Object.keys(resourceDefs).forEach(loadResource)
  }
})

/* ----------------------------- Resource definitions ----------------------------- */
// Each tab is fully described here — the table, the add/edit form, and validation
// all render from this config instead of four near-duplicate blocks of markup.
const resourceDefs = reactive({
  modelsTab: {
    title: 'Models',
    hint: 'Model names available when scanning and printing.',
    endpoint: '/admin/models',
    idField: 'No',
    columns: [{ key: 'Model_Name', label: 'Model Name' }],
    fields: [{ key: 'Model_Name', label: 'Model Name', type: 'text', required: true }],
  },
  tabletTab: {
    title: 'Tablets',
    hint: 'Registered devices, their printer, and access role.',
    endpoint: '/admin/tablets',
    idField: 'tablet_id',
    columns: [
      { key: 'tablet_id', label: 'Tablet ID' },
      { key: 'Area', label: 'Area' },
      { key: 'r4_Name', label: 'R4 Name' },
      { key: 'role', label: 'Role' },
    ],
    fields: [
      { key: 'tablet_id', label: 'Tablet ID', type: 'text', required: true },
      { key: 'IP_Address', label: 'IP Address', type: 'text' },
      { key: 'Area', label: 'Area', type: 'text' },
      { key: 'SATO_IP', label: 'SATO IP', type: 'text' },
      { key: 'Horizontal_Offset', label: 'Horizontal Offset', type: 'number' },
      { key: 'Vertical_Offset', label: 'Vertical Offset', type: 'number' },
      { key: 'r4_name', label: 'R4 Name', type: 'text' },
      { key: 'role', label: 'Role', type: 'select', options: ['admin', 'operator'] },
    ],
  },
  validationTab: {
    title: 'Validation Types',
    hint: 'Options available when printing a validation sticker.',
    endpoint: '/admin/validations',
    idField: 'ID',
    columns: [{ key: 'Validation_Name', label: 'Validation Name' }],
    fields: [{ key: 'Validation_Name', label: 'Validation Name', type: 'text', required: true }],
  },
  resultsTab: {
    title: 'Result Options',
    hint: 'The buttons operators tap on the Scan page.',
    endpoint: '/admin/processes',
    idField: 'No',
    columns: [
      { key: 'Process', label: 'Process' },
      { key: 'Type', label: 'Type' },
    ],
    fields: [
      { key: 'Process', label: 'Process Name', type: 'text', required: true },
      { key: 'Type', label: 'Type', type: 'select', required: true, options: ['GOOD', 'NOT GOOD', 'RELOAD'] },
    ],
  },
})

const activeTab = ref('modelsTab')
const items = reactive({ modelsTab: [], tabletTab: [], validationTab: [], resultsTab: [] })
const loadingTabs = reactive({ modelsTab: false, tabletTab: false, validationTab: false, resultsTab: false })
const listErrors = reactive({ modelsTab: '', tabletTab: '', validationTab: '', resultsTab: '' })

const PER_PAGE = 10
const currentPage = reactive({ modelsTab: 1, tabletTab: 1, validationTab: 1, resultsTab: 1 })

function totalPages(tabKey) {
  return Math.max(1, Math.ceil(items[tabKey].length / PER_PAGE))
}
function pagedItems(tabKey) {
  const start = (currentPage[tabKey] - 1) * PER_PAGE
  return items[tabKey].slice(start, start + PER_PAGE)
}
function goToPage(tabKey, page) {
  const clamped = Math.min(Math.max(1, page), totalPages(tabKey))
  currentPage[tabKey] = clamped
}

async function loadResource(tabKey) {
  const def = resourceDefs[tabKey]
  loadingTabs[tabKey] = true
  listErrors[tabKey] = ''
  try {
    const { data } = await axios.get(def.endpoint)
    items[tabKey] = data.data ?? data ?? []
    currentPage[tabKey] = 1
  } catch (e) {
    listErrors[tabKey] = "Couldn't load this list. Pull to refresh or try again."
  } finally {
    loadingTabs[tabKey] = false
  }
}

/* ----------------------------- Add / Edit modal ----------------------------- */
const formModal = reactive({
  open: false,
  mode: 'add', // 'add' | 'edit'
  tabKey: null,
  values: {},
  fieldErrors: {},
  generalError: '',
  saving: false,
})

function openAddModal(tabKey) {
  const def = resourceDefs[tabKey]
  const values = {}
  def.fields.forEach(f => { values[f.key] = f.type === 'select' ? (f.options?.[0] ?? '') : '' })
  Object.assign(formModal, { open: true, mode: 'add', tabKey, values, fieldErrors: {}, generalError: '', saving: false })
}

function openEditModal(tabKey, row) {
  const def = resourceDefs[tabKey]
  const values = {}
  def.fields.forEach(f => { values[f.key] = row[f.key] ?? '' })
  Object.assign(formModal, { open: true, mode: 'edit', tabKey, values, fieldErrors: {}, generalError: '', saving: false, editingRow: row })
}

function closeFormModal() {
  formModal.open = false
}

async function submitFormModal() {
  const def = resourceDefs[formModal.tabKey]
  formModal.saving = true
  formModal.fieldErrors = {}
  formModal.generalError = ''
  try {
    if (formModal.mode === 'add') {
      await axios.post(def.endpoint, formModal.values)
    } else {
      const id = formModal.editingRow[def.idField]
      await axios.put(`${def.endpoint}/${id}`, formModal.values)
    }
    await loadResource(formModal.tabKey)
    closeFormModal()
  } catch (e) {
    if (e.response?.status === 422) {
      const errors = e.response.data.errors || {}
      Object.keys(errors).forEach(k => { formModal.fieldErrors[k] = errors[k][0] })
      formModal.generalError = 'Please fix the highlighted field(s).'
    } else {
      formModal.generalError = e.response?.data?.message || "Couldn't save. Please try again."
    }
  } finally {
    formModal.saving = false
  }
}

/* ----------------------------- Delete confirm modal ----------------------------- */
const deleteModal = reactive({ open: false, tabKey: null, row: null, deleting: false, error: '' })

function openDeleteModal(tabKey, row) {
  Object.assign(deleteModal, { open: true, tabKey, row, deleting: false, error: '' })
}
function closeDeleteModal() {
  deleteModal.open = false
}
async function confirmDelete() {
  const def = resourceDefs[deleteModal.tabKey]
  const id = deleteModal.row[def.idField]
  deleteModal.deleting = true
  deleteModal.error = ''
  try {
    await axios.delete(`${def.endpoint}/${id}`)
    await loadResource(deleteModal.tabKey)
    closeDeleteModal()
  } catch (e) {
    deleteModal.error = e.response?.data?.message || "Couldn't delete. Please try again."
  } finally {
    deleteModal.deleting = false
  }
}
</script>

<template>
  <Head title="Administrator Access" />

  <div class="wrap">
    <header class="topbar">
      <div class="plate">
        <span class="plate-dot" aria-hidden="true"></span>
        <span class="plate-text">FG CHECKER</span>
      </div>

      <nav class="nav" aria-label="Main">
        <Link href="/" class="nav-link">Home</Link>
        <Link href="/scan" class="nav-link">Scan</Link>
        <Link href="/print" class="nav-link">Print Sticker</Link>
        <Link href="/admin" class="nav-link is-active">Admin</Link>
      </nav>

      <button class="switch" role="switch" :aria-checked="theme === 'dark'" @click="toggleTheme" title="Toggle light / dark">
        <span class="switch-track"><span class="switch-knob"></span></span>
        <span class="switch-label">{{ theme === 'dark' ? 'Dark' : 'Light' }}</span>
      </button>
    </header>

    <section class="print-hero">
      <div class="hazard-strip" aria-hidden="true"></div>
      <p class="eyebrow">Finished Goods &middot; Line Inspection</p>
      <h1 class="hero-title-sm">Administrator Access</h1>
      <p class="hero-sub">Modify models, tablets, validation, and result options.</p>
    </section>

    <!-- ============ ACCESS GATE ============ -->
    <div v-if="checkingAccess" class="empty-state">Checking access&hellip;</div>

    <p v-else-if="!isAdmin" class="scan-error tabletid-warning">
      This tablet doesn't have admin access. If this is a mistake, ask an administrator
      to set this tablet's Role to "admin" in the Tablets list.
    </p>

    <template v-else>
      <div class="tabs" role="tablist">
        <button
          v-for="(def, key) in resourceDefs"
          :key="key"
          role="tab"
          :aria-selected="activeTab === key"
          class="tab-btn"
          :class="{ active: activeTab === key }"
          @click="activeTab = key"
        >
          {{ def.title }}
        </button>
      </div>

      <section v-for="(def, key) in resourceDefs" :key="key" v-show="activeTab === key" class="panel-section">
        <div class="panel panel-wide">
          <div class="panel-head">
            <div>
              <h2 class="section-title">{{ def.title }}</h2>
              <p class="section-sub">{{ def.hint }}</p>
            </div>
            <button class="btn btn-primary" @click="openAddModal(key)">+ Add</button>
          </div>

          <p v-if="listErrors[key]" class="result-banner result-error">{{ listErrors[key] }}</p>

          <div v-if="loadingTabs[key]" class="empty-state">Loading&hellip;</div>
          <div v-else-if="items[key].length === 0" class="empty-state">No entries yet. Tap + Add to create one.</div>

          <div v-else class="table-scroll">
            <table>
              <thead>
                <tr>
                  <th v-for="col in def.columns" :key="col.key">{{ col.label }}</th>
                  <th class="col-actions">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in pagedItems(key)" :key="row[def.idField]">
                  <td v-for="col in def.columns" :key="col.key" :data-label="col.label">{{ row[col.key] }}</td>
                  <td class="col-actions" data-label="Action">
                    <button class="btn btn-chip" @click="openEditModal(key, row)">Edit</button>
                    <button class="btn btn-chip btn-chip-danger" @click="openDeleteModal(key, row)">Delete</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="!loadingTabs[key] && items[key].length > 0" class="pagination">
            <button class="page-btn" @click="goToPage(key, currentPage[key] - 1)" :disabled="currentPage[key] === 1">&larr; Previous</button>
            <span class="page-status">Page {{ currentPage[key] }} of {{ totalPages(key) }}</span>
            <button class="page-btn" @click="goToPage(key, currentPage[key] + 1)" :disabled="currentPage[key] === totalPages(key)">Next &rarr;</button>
          </div>
        </div>
      </section>
    </template>

    <!-- ============ ADD / EDIT MODAL ============ -->
    <Teleport to="body">
      <div v-if="formModal.open" class="modal-overlay" @click.self="closeFormModal">
        <div class="modal">
          <h3>{{ formModal.mode === 'add' ? 'Add' : 'Edit' }} {{ resourceDefs[formModal.tabKey]?.title.replace(/s$/, '') }}</h3>

          <p v-if="formModal.generalError" class="result-banner result-error">{{ formModal.generalError }}</p>

          <div class="modal-form">
            <label v-for="f in resourceDefs[formModal.tabKey]?.fields" :key="f.key" class="field">
              <span>{{ f.label }}<span v-if="f.required" class="required-mark"> *</span></span>
              <select v-if="f.type === 'select'" v-model="formModal.values[f.key]">
                <option v-for="opt in f.options" :key="opt" :value="opt">{{ opt }}</option>
              </select>
              <input v-else :type="f.type" v-model="formModal.values[f.key]" />
              <span v-if="formModal.fieldErrors[f.key]" class="field-error">{{ formModal.fieldErrors[f.key] }}</span>
            </label>
          </div>

          <div class="confirm-actions">
            <button class="btn btn-ghost" @click="closeFormModal" :disabled="formModal.saving">Cancel</button>
            <button class="btn btn-primary" @click="submitFormModal" :disabled="formModal.saving">
              {{ formModal.saving ? 'Saving\u2026' : 'Save' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ============ DELETE CONFIRM MODAL ============ -->
    <Teleport to="body">
      <div v-if="deleteModal.open" class="modal-overlay" @click.self="closeDeleteModal">
        <div class="modal confirm-modal">
          <h3>Delete this entry?</h3>
          <p class="confirm-text">This can't be undone. Existing scan records won't be affected.</p>
          <p v-if="deleteModal.error" class="result-banner result-error">{{ deleteModal.error }}</p>
          <div class="confirm-actions">
            <button class="btn btn-ghost" @click="closeDeleteModal" :disabled="deleteModal.deleting">Cancel</button>
            <button class="btn btn-primary btn-danger" @click="confirmDelete" :disabled="deleteModal.deleting">
              {{ deleteModal.deleting ? 'Deleting\u2026' : 'Delete' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
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
.switch-knob { position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; border-radius: 50%; background: var(--accent); transition: transform .2s ease; }
:global(html[data-theme='light']) .switch-knob { transform: translateX(20px); }
.nav-link:focus-visible, .btn:focus-visible, .switch:focus-visible, .tab-btn:focus-visible, select:focus-visible, input:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }

.print-hero { position: relative; padding: 36px clamp(16px, 5vw, 56px) 8px; overflow: hidden; }
.hazard-strip { position: absolute; top: 0; left: 0; right: 0; height: 6px; background: repeating-linear-gradient(135deg, var(--accent) 0 18px, var(--bg-2) 18px 36px); opacity: 0.9; }
.eyebrow { text-transform: uppercase; letter-spacing: 0.14em; font-size: 0.8rem; color: var(--accent); font-weight: 600; margin: 0 0 10px; }
.hero-title-sm { font-family: 'Big Shoulders Display', sans-serif; font-weight: 800; font-size: clamp(2rem, 4.5vw, 3rem); margin: 0 0 8px; }
.hero-sub { color: var(--text-dim); margin: 0; font-size: 1.05rem; }

.empty-state { padding: 40px clamp(16px, 5vw, 56px); text-align: center; color: var(--text-dim); }

.scan-error, .tabletid-warning {
  margin: 8px clamp(16px, 5vw, 56px) 0;
  padding: 12px 16px;
  border-radius: 10px;
  background: color-mix(in srgb, var(--fail) 15%, var(--surface));
  border: 1px solid var(--fail);
  color: var(--text);
  font-weight: 500;
}

.tabs { display: flex; gap: 8px; padding: 20px clamp(16px, 5vw, 56px) 0; flex-wrap: wrap; }
.tab-btn {
  min-height: 48px; padding: 12px 24px; border-radius: 10px 10px 0 0;
  border: 1px solid var(--border); border-bottom: none;
  background: var(--surface-2); color: var(--text-dim);
  font-weight: 600; font-size: 1rem; cursor: pointer;
}
.tab-btn.active { background: var(--surface); color: var(--text); }
.tab-btn:not(.active):hover { color: var(--text); }

.panel-section { padding: 0 clamp(16px, 5vw, 56px) 56px; }
.panel {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 0 14px 14px 14px; padding: 28px;
  box-shadow: 0 10px 30px var(--shadow);
}
.panel-wide { max-width: 900px; }
.panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.section-title { font-family: 'Big Shoulders Display', sans-serif; font-size: 1.6rem; font-weight: 700; margin: 0 0 4px; }
.section-sub { margin: 0; color: var(--text-dim); }

.btn { border: none; border-radius: 10px; min-height: 48px; padding: 14px 26px; font-size: 1rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: transform .12s ease, box-shadow .12s ease; }
.btn:active { transform: translateY(1px); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary { background: var(--accent); color: var(--accent-ink); box-shadow: 0 6px 0 color-mix(in srgb, var(--accent) 55%, black); }
.btn-primary:hover:not(:disabled) { box-shadow: 0 4px 0 color-mix(in srgb, var(--accent) 55%, black); transform: translateY(2px); }
.btn-ghost { background: transparent; color: var(--text); border: 1.5px solid var(--border); }
.btn-ghost:hover:not(:disabled) { border-color: var(--accent); color: var(--accent); }
.btn-chip { background: var(--surface-2); color: var(--text); border: 1px solid var(--border); min-height: 40px; padding: 8px 16px; font-size: 0.9rem; }
.btn-chip:hover { border-color: var(--accent); color: var(--accent); }
.btn-chip-danger:hover { border-color: var(--fail); color: var(--fail); }
.btn-danger { background: var(--fail); color: var(--fail-ink); box-shadow: 0 6px 0 color-mix(in srgb, var(--fail) 55%, black); }
.btn-danger:hover:not(:disabled) { box-shadow: 0 4px 0 color-mix(in srgb, var(--fail) 55%, black); transform: translateY(2px); }

.result-banner { margin: 0 0 16px; padding: 12px 16px; border-radius: 10px; font-weight: 500; }
.result-error { background: color-mix(in srgb, var(--fail) 15%, var(--surface)); border: 1px solid var(--fail); color: var(--text); }

.table-scroll { overflow-x: auto; border: 1px solid var(--border); border-radius: 10px; }
table { width: 100%; border-collapse: collapse; min-width: 480px; }
thead th {
  text-align: left; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em;
  color: var(--text-dim); background: var(--surface-2); padding: 12px 14px;
  border-bottom: 1px solid var(--border); white-space: nowrap;
}
tbody td { padding: 10px 14px; border-bottom: 1px solid var(--border); font-size: 0.92rem; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover { background: var(--surface-2); }
.col-actions { display: flex; gap: 8px; white-space: nowrap; }

.pagination { display: flex; align-items: center; justify-content: center; gap: 18px; margin-top: 18px; }
.page-btn { background: var(--surface-2); border: 1px solid var(--border); color: var(--text); padding: 8px 16px; border-radius: 8px; cursor: pointer; font-weight: 500; min-height: 40px; }
.page-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.page-btn:hover:not(:disabled) { border-color: var(--accent); color: var(--accent); }
.page-status { color: var(--text-dim); font-size: 0.9rem; }

@media (max-width: 640px) {
  table { min-width: 0; }
  thead { display: none; }
  tbody, tr, td { display: block; width: 100%; }
  tr { border-bottom: 1px solid var(--border); padding: 10px 0; }
  tbody td { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; border: none; text-align: right; }
  tbody td::before { content: attr(data-label); font-weight: 600; color: var(--text-dim); text-align: left; }
  .col-actions { justify-content: flex-end; }
}

.modal-overlay {
  position: fixed; inset: 0; background: rgba(10, 10, 10, 0.55); backdrop-filter: blur(2px);
  display: flex; align-items: center; justify-content: center; padding: 20px; z-index: 50;
}
.modal {
  background: var(--surface); border: 1px solid var(--border); border-radius: 16px;
  padding: 28px; max-width: 460px; width: 100%; box-shadow: 0 20px 50px var(--shadow);
}
.modal h3 { font-family: 'Big Shoulders Display', sans-serif; font-size: 1.4rem; margin: 0 0 16px; text-align: center; }
.confirm-modal { text-align: center; }
.confirm-text { color: var(--text-dim); margin: 0 0 20px; }
.confirm-actions { display: flex; gap: 12px; margin-top: 8px; }
.confirm-actions .btn { flex: 1; }

.modal-form { display: flex; flex-direction: column; gap: 16px; margin-bottom: 8px; }
.field { display: flex; flex-direction: column; gap: 6px; font-size: 0.85rem; color: var(--text-dim); font-weight: 500; }
.required-mark { color: var(--fail); }
.field input, .field select {
  min-height: 48px; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border);
  background: var(--surface-2); color: var(--text); font-size: 1rem; font-family: 'IBM Plex Sans', sans-serif;
}
.field-error { color: var(--fail); font-size: 0.8rem; }
</style>
