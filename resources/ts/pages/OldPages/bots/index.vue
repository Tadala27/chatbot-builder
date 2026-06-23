<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { useRouter } from 'vue-router'
import axios from 'axios'
import moment from 'moment'
import Swal from 'sweetalert2'

const router = useRouter()

// ────────────────────────────────────────
// Reactive State
// ────────────────────────────────────────
const isLoading = ref(true)
const Loading = ref(false)
const bots = ref<any[]>([])
const pagination = ref({
  total: 0,
  per_page: 20,
  current_page: 1,
  last_page: 1,
  from: 0,
  to: 0,
})

const statusCounts = ref({ all: 0, active: 0, inactive: 0 })
const hovered = ref('')
const searchQuery = ref('')
const statusFilter = ref('')     // '' | 'active' | 'inactive'
const sortBy = ref('created_at')
const sortOrder = ref('desc')

const snackbar = ref({ show: false, message: '', color: 'success', timeout: 4000 })
const showSnackbar = (msg: string, color = 'success', timeout = 4000) => {
  snackbar.value = { show: true, message: msg, color, timeout }
}

const toggleSort = (field: string) => {
  if (sortBy.value === field) {
    sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortBy.value = field
    sortOrder.value = 'asc'
  }
}

// ────────────────────────────────────────
// UI Options
// ────────────────────────────────────────
const statusOptions = computed(() => [
  { title: `All Bots (${statusCounts.value.all})`, value: '' },
  { title: `Active (${statusCounts.value.active})`, value: 'active' },
  { title: `Inactive (${statusCounts.value.inactive})`, value: 'inactive' },
])

const sortOptions = [
  { title: 'Created At', value: 'created_at' },
  { title: 'Name', value: 'name' },
  { title: 'Status', value: 'is_active' },
]

// ────────────────────────────────────────
// Helpers
// ────────────────────────────────────────
const formatDate = (date: string) => moment(date).format('DD MMM YYYY')

const botInitials = (name: string) =>
  name
    .split(' ')
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? '')
    .join('')

const waStatus = (bot: any) =>
  bot.whatsapp_account?.is_active
    ? { color: 'success', label: bot.whatsapp_account.display_phone_number ?? bot.whatsapp_account.phone_number }
    : { color: 'default', label: 'Not connected' }

// ────────────────────────────────────────
// API
// ────────────────────────────────────────
const fetchBots = async () => {
  try {
    Loading.value = true

    const params = {
      page: pagination.value.current_page,
      per_page: pagination.value.per_page,
      search: searchQuery.value || undefined,
      is_active: statusFilter.value === 'active' ? 1
        : statusFilter.value === 'inactive' ? 0
          : undefined,
      sort: sortBy.value,
      direction: sortOrder.value,
    }

    const { data } = await axios.get('/api/bots', { params })

    bots.value = data.data

    pagination.value = {
      total: data.total,
      per_page: data.per_page,
      current_page: data.current_page,
      last_page: data.last_page,
      from: data.from ?? 0,
      to: data.to ?? 0,
    }

    // Only update counts when not filtering so numbers stay stable
    if (!statusFilter.value) {
      statusCounts.value = {
        all: data.total,
        active: data.meta?.active_count ?? data.total,
        inactive: data.meta?.inactive_count ?? 0,
      }
    }
  } catch (e: any) {
    if (e.response?.status === 403) {
      Swal.fire('Access Denied', e.response.data.message, 'error')
        .then(() => window.history.back())
    } else {
      showSnackbar(e.response?.data?.message || 'Failed to load bots', 'error')
    }
  } finally {
    Loading.value = false
    isLoading.value = false
  }
}

// ────────────────────────────────────────
// Actions
// ────────────────────────────────────────
const deleteBot = async (bot: any) => {
  const { isConfirmed } = await Swal.fire({
    title: `Delete "${bot.name}"?`,
    text: 'All flows and data will be permanently removed.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Yes, delete!',
    cancelButtonText: 'Cancel',
    confirmButtonColor: '#ef4444',
  })
  if (!isConfirmed) return
  try {
    await axios.delete(`/api/bots/${bot.id}`)
    showSnackbar('Bot deleted', 'success')
    fetchBots()
  } catch (e: any) {
    showSnackbar(e.response?.data?.message || 'Failed to delete', 'error')
  }
}

const toggleActive = async (bot: any) => {
  const action = bot.is_active ? 'deactivate' : 'activate'
  const { isConfirmed } = await Swal.fire({
    title: `${bot.is_active ? 'Deactivate' : 'Activate'} "${bot.name}"?`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: `Yes, ${action}`,
  })
  if (!isConfirmed) return
  try {
    await axios.put(`/api/bots/${bot.id}`, { is_active: !bot.is_active })
    showSnackbar(`Bot ${action}d`, 'success')
    fetchBots()
  } catch (e: any) {
    showSnackbar(e.response?.data?.message || 'Failed', 'error')
  }
}

const openFlows = (bot: any) =>
  router.push({ name: 'bots-id', params: { id: bot.id } })

const openBuilder = (bot: any) => {
  router.push({ name: 'bots-bot-id-flowbuilder', params: { botId: bot.id } })
}


// ────────────────────────────────────────
// Watchers
// ────────────────────────────────────────
watchDebounced(
  [searchQuery, statusFilter, sortBy, sortOrder],
  () => {
    pagination.value.current_page = 1
    fetchBots()
  },
  { debounce: 500, maxWait: 1000 }
)

watch(() => pagination.value.current_page, () => fetchBots())

// ────────────────────────────────────────
// Lifecycle
// ────────────────────────────────────────
onMounted(fetchBots)
</script>

<template>
  <div>
    <!-- Initial loading -->
    <v-container v-if="isLoading" class="d-flex justify-center py-12">
      <v-progress-circular indeterminate color="primary" size="64" />
    </v-container>

    <div v-else>
      <!-- ── Header ──────────────────────────────────────────────── -->
      <v-row align="center">
        <v-col cols="12" sm="8">
          <h1 class="text-h4 font-weight-bold">Bots</h1>
          <p class="text-subtitle-1 text-medium-emphasis mt-1">
            {{ statusFilter === 'active' ? 'Active Bots' : statusFilter === 'inactive' ? 'Inactive Bots' : 'All Bots' }}
            <span class="text-secondary">({{ pagination.total }})</span>
          </p>
        </v-col>

        <v-col cols="12" sm="4" class="text-start text-sm-end">
          <v-btn color="primary" prepend-icon="$plus" @click="router.push({ name: 'bots-create' })">
            Create Bot
          </v-btn>
        </v-col>
      </v-row>

      <!-- ── Filters ─────────────────────────────────────────────── -->
      <v-row align="center" class="mb-1">
        <v-col cols="12" sm="4">
          <v-text-field v-model="searchQuery" label="Search bots..." prepend-inner-icon="$magnify" variant="outlined"
            clearable hide-details density="comfortable" />
        </v-col>

        <v-col cols="0" sm="auto" class="flex-grow-1" />

        <v-col cols="12" sm="3" lg="2">
          <v-select v-model="statusFilter" :items="statusOptions" item-title="title" item-value="value" label="Status"
            variant="outlined" hide-details clearable prepend-inner-icon="$checkCircleOutline" density="comfortable" />
        </v-col>

        <v-col cols="12" sm="3" lg="2">
          <v-select v-model="sortBy" :items="sortOptions" item-title="title" item-value="value" label="Sort By"
            variant="outlined" hide-details density="comfortable" />
        </v-col>
      </v-row>

      <!-- ── Table ───────────────────────────────────────────────── -->
      <v-table class="bordered-table" density="comfortable">
        <thead class="bg-gray text-uppercase">
          <tr class="text-secondary">
            <!-- Bot column -->
            <th class="text-left pa-4 cursor-pointer" @click="toggleSort('name')" @mouseenter="hovered = 'name'"
              @mouseleave="hovered = ''">
              Bot
              <v-icon v-if="hovered === 'name' || sortBy === 'name'" size="18" class="ms-1">
                {{ sortBy === 'name' ? (sortOrder === 'asc' ? '$chevronUp' : '$chevronDown') : '$chevronUp' }}
              </v-icon>
            </th>

            <th class="text-left pa-4">WhatsApp</th>
            <th class="text-left pa-4">Flows</th>
            <th class="text-left pa-4">Status</th>

            <!-- Created column -->
            <th class="text-left pa-4 cursor-pointer" @click="toggleSort('created_at')"
              @mouseenter="hovered = 'created_at'" @mouseleave="hovered = ''">
              Created
              <v-icon v-if="hovered === 'created_at' || sortBy === 'created_at'" size="18" class="ms-1">
                {{ sortBy === 'created_at' ? (sortOrder === 'asc' ? '$chevronUp' : '$chevronDown') : '$chevronUp' }}
              </v-icon>
            </th>

            <th class="text-left pa-4">Actions</th>
          </tr>
        </thead>

        <!-- Loading rows -->
        <tbody v-if="Loading">
          <tr>
            <td colspan="6" class="py-12">
              <div class="d-flex justify-center">
                <v-progress-circular indeterminate color="primary" size="64" />
              </div>
            </td>
          </tr>
        </tbody>

        <tbody v-else class="bg-containerBg">
          <!-- Empty state -->
          <tr v-if="!bots.length">
            <td colspan="6" class="text-center py-12">
              <v-icon size="64" color="grey-lighten-1">$robotOutline</v-icon>
              <h3 class="text-h6 text-grey mt-4">No bots found</h3>
              <p class="text-grey mb-4">
                {{ searchQuery ? 'Try adjusting your search.' : 'Create your first bot to get started.' }}
              </p>
              <v-btn v-if="!searchQuery" color="primary" prepend-icon="$plus"
                @click="router.push({ name: 'bots-create' })">
                Create Bot
              </v-btn>
            </td>
          </tr>

          <!-- Data rows -->
          <tr v-for="bot in bots" :key="bot.id">
            <!-- Bot name + description -->
            <td class="pa-4">
              <div class="d-flex align-center gap-3">
                <v-avatar color="primary" size="40" rounded="lg">
                  <span class="text-white font-weight-bold text-body-2">
                    {{ botInitials(bot.name) }}
                  </span>
                </v-avatar>
                <div>
                  <div class="title-link text-subtitle-1 font-weight-medium" @click="openFlows(bot)">
                    {{ bot.name }}
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ bot.description || 'No description' }}
                  </div>
                </div>
              </div>
            </td>

            <!-- WhatsApp account -->
            <td class="pa-4">
              <div class="d-flex align-center gap-2">
                <v-icon :color="waStatus(bot).color" size="18">$whatsapp</v-icon>
                <span class="text-body-2" :class="waStatus(bot).color === 'default' ? 'text-medium-emphasis' : ''">
                  {{ waStatus(bot).label }}
                </span>
              </div>
            </td>

            <!-- Flows count -->
            <td class="pa-4">
              <div class="d-flex align-center gap-2">
                <SvgSprite name="custom-hierarchy-outline" style="width:16px;height:16px"
                  class="text-medium-emphasis" />
                <span class="text-body-2">{{ bot.flows_count ?? 0 }}</span>
                <v-chip v-if="(bot.published_flows_count ?? 0) > 0" color="success" variant="tonal" size="x-small">
                  {{ bot.published_flows_count }} live
                </v-chip>
              </div>
            </td>

            <!-- Status -->
            <td class="pa-4">
              <v-chip :color="bot.is_active ? 'success' : 'error'" variant="tonal" size="small"
                :prepend-icon="bot.is_active ? '$checkCircleOutline' : '$closeCircleOutline'">
                {{ bot.is_active ? 'Active' : 'Inactive' }}
              </v-chip>
            </td>

            <!-- Created -->
            <td class="pa-4 text-lightText">
              <div class="d-flex align-center gap-1">
                <SvgSprite name="custom-calendar-plus" class="me-1" style="width:18px;height:18px" />
                <span>{{ formatDate(bot.created_at) }}</span>
              </div>
            </td>

            <!-- Actions -->
            <td class="pa-4">
              <v-menu location="bottom">
                <template #activator="{ props }">
                  <v-btn v-bind="props" icon variant="text" color="grey">
                    <v-icon>$dotsVertical</v-icon>
                  </v-btn>
                </template>
                <v-list density="compact" rounded="lg" elevation="8">
                  <v-list-item @click="openFlows(bot)">
                    <v-list-item-title>
                      <v-icon start>$siteMapOutline</v-icon>View Flows
                    </v-list-item-title>
                  </v-list-item>

                  <v-list-item @click="openBuilder(bot)">
                    <v-list-item-title>
                      <v-icon start>$pencilRuler</v-icon>Open Builder
                    </v-list-item-title>
                  </v-list-item>

                  <v-list-item @click="router.push({ name: 'bots-edit', params: { id: bot.id } })">
                    <v-list-item-title>
                      <v-icon start>$pencil</v-icon>Edit
                    </v-list-item-title>
                  </v-list-item>

                  <v-divider class="my-1" />

                  <v-list-item @click="toggleActive(bot)">
                    <v-list-item-title :class="bot.is_active ? 'text-warning' : 'text-success'">
                      <v-icon start>
                        {{ bot.is_active ? '$pauseCircleOutline' : '$playCircleOutline' }}
                      </v-icon>
                      {{ bot.is_active ? 'Deactivate' : 'Activate' }}
                    </v-list-item-title>
                  </v-list-item>

                  <v-list-item @click="deleteBot(bot)" class="text-error">
                    <v-list-item-title>
                      <v-icon start>$trashCanOutline</v-icon> Delete
                    </v-list-item-title>
                  </v-list-item>
                </v-list>
              </v-menu>
            </td>
          </tr>
        </tbody>
      </v-table>

      <!-- ── Pagination ──────────────────────────────────────────── -->
      <v-card-text v-if="pagination.total > 0" class="pt-4">
        <VRow class="align-center text-center text-sm-start" justify="space-between">
          <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-start">
            <span class="text-medium-emphasis">
              Showing {{ pagination.from }}–{{ pagination.to }} of {{ pagination.total }} bots
            </span>
          </VCol>
          <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-end">
            <v-pagination v-model="pagination.current_page" :length="pagination.last_page" :total-visible="5"
              rounded="circle" density="comfortable" variant="outlined" color="primary" />
          </VCol>
        </VRow>
      </v-card-text>
    </div>

    <!-- ── Snackbar ────────────────────────────────────────────────── -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="snackbar.timeout" location="top right">
      {{ snackbar.message }}
      <template #actions>
        <v-btn variant="text" @click="snackbar.show = false">Close</v-btn>
      </template>
    </v-snackbar>
  </div>
</template>

<style scoped>
.title-link {
  cursor: pointer;
  transition: color 0.2s;
}

.title-link:hover {
  color: rgb(var(--v-theme-primary));
}

.bordered-table {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.bg-gray {
  background-color: #f5f5f5;
}

.bg-containerBg {
  background-color: rgb(var(--v-theme-surface));
}

.gap-1 {
  gap: 4px;
}

.gap-2 {
  gap: 8px;
}

.gap-3 {
  gap: 12px;
}
</style>