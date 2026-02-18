<script setup lang="ts">
import { ref, computed, onMounted, shallowRef, watch } from 'vue'
import { useDisplay } from 'vuetify'
import { useRouter } from 'vue-router'
import axios from "axios"

import moment from 'moment'
import user1 from '@images/users/avatar-1.png'

// Types
interface Notification {
  id: number
  title: string
  message: string
  notifiable_type: string
  event_type?: string
  priority?: string
  is_read: boolean
  created_at: string
  updated_at: string
  read_at?: string
  user_id: number
  expires_at?: string
  action_url?: string
}

const { mdAndUp } = useDisplay()
const router = useRouter()

// State management
const toggleSide = ref(false)
const sDrawer = ref(false)
const notifications = ref<Notification[]>([])
const selectedNotification = ref<Notification | null>(null)
const isLoading = ref(true)
const error = ref<string | null>(null)
const searchValue = ref('')
const filterType = ref<'all' | 'unread' | 'archived'>('all')

// **SNACKBAR STATE**
const snackbar = ref({
  show: false,
  message: '',
  color: 'success' as 'success' | 'error' | 'warning' | 'info',
  timeout: 4000,
  action: null as (() => void) | null,
  actionText: '' as string
})

// **SNACKBAR METHODS**
const showSnackbar = (message: string, color: 'success' | 'error' | 'warning' | 'info' = 'success', timeout = 4000, action?: () => void, actionText = '') => {
  snackbar.value = {
    show: true,
    message,
    color,
    timeout,
    action,
    actionText
  }
}

const closeSnackbar = () => {
  snackbar.value.show = false
}

// Store notifications data
const fetchNotifications = async () => {
  isLoading.value = true
  error.value = null
  try {
    const response = await axios.get('/api/notifications')
    notifications.value = response.data.notifications || response.data.data || []

    if (notifications.value.length > 0) {
      // Sort notifications by created_at descending to get latest first
      const sorted = [...notifications.value].sort(
        (a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
      )

      selectedNotification.value = sorted[0]

      // If the selected notification is unread, mark it as read
      if (!selectedNotification.value.is_read) {
        await markAsRead(selectedNotification.value.id)
      }
    }
  } catch (err: any) {
    console.error('Error fetching notifications:', err)
    error.value = err.response?.data?.message || 'Failed to load notifications'
    showSnackbar(error.value, 'error')
  } finally {
    isLoading.value = false
  }
}

// Computed properties
const unreadCount = computed(() =>
  notifications.value.filter(n => !n.is_read).length
)
const archivedCount = computed(() =>
  notifications.value.filter(n => !n.is_archived).length
)

const allCount = computed(() =>
  notifications.value.filter(n => !n.is_archived && n.deleted_at === null).length
)

const filteredNotifications = computed(() => {
  let filtered = notifications.value

  // Filter by search
  if (searchValue.value) {
    const query = searchValue.value.toLowerCase()
    filtered = filtered.filter(n =>
      n.title.toLowerCase().includes(query) ||
      n.message.toLowerCase().includes(query)
    )
  }

  // Filter by type
  if (filterType.value === 'all') {
    filtered = filtered.filter(n => !n.is_archived && n.deleted_at === null)
  } else if (filterType.value === 'unread') {
    filtered = filtered.filter(n => !n.is_read && n.deleted_at === null && !n.is_archived)
  } else if (filterType.value === 'archived') {
    filtered = filtered.filter(n => n.is_archived && n.deleted_at === null)
  }

  // Sort by date descending
  return filtered.sort((a, b) =>
    new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
  )
})


// Menu items - FIXED with proper notification ID
const items = shallowRef((notificationId: number) => [
  {
    title: 'Archive',
    icon: 'custom-document-2',
    action: () => archiveNotification(notificationId)
  },
  {
    title: 'Mark as Unread',
    icon: 'custom-eye-invisible',
    action: () => markUnRead(notificationId)
  },
  {
    title: 'Delete',
    icon: 'custom-trash',
    action: () => deleteNotification(notificationId)
  },
])

// **FIXED HELPER METHODS** - Now properly handle notification ID
const selectNotification = async (notification: Notification) => {
  selectedNotification.value = notification
  if (!notification.is_read) {
    await markAsRead(notification.id)
  }
}

const markAsRead = async (id: number) => {
  try {
    await axios.patch(`/api/notifications/${id}/read`)
    const notification = notifications.value.find(n => n.id === id)
    if (notification) {
      notification.is_read = true
      notification.read_at = new Date().toISOString()
    }
  } catch (error: any) {
    console.error('Error marking notification as read:', error)
  }
}

const markAllAsRead = async () => {
  try {
    await axios.post('/api/notifications/markAll')
    notifications.value.forEach(n => {
      n.is_read = true
      n.read_at = new Date().toISOString()
    })
    showSnackbar('All notifications marked as read', 'success')
  } catch (error: any) {
    console.error('Error marking all as read:', error)
    showSnackbar(error.response?.data?.message || 'Failed to mark all as read', 'error')
  }
}

const markUnRead = async (id: number) => {
  try {
    await axios.post(`/api/notifications/${id}/unread`)
    const notification = notifications.value.find(n => n.id === id)
    if (notification) {
      notification.is_read = false
      notification.read_at = null
    }
    showSnackbar('Notification marked as unread', 'info', 2000)
  } catch (error: any) {
    console.error('Error marking as unread:', error)
    showSnackbar(error.response?.data?.message || 'Failed to mark as unread', 'error')
  }
}

const archiveNotification = async (id: number) => {
  try {
    await axios.post(`/api/notifications/${id}/archive`)
    // Remove from list or mark as archived
    notifications.value = notifications.value.filter(n => n.id !== id)
    showSnackbar('Notification archived', 'success', 2000)
  } catch (error: any) {
    console.error('Error archiving notification:', error)
    showSnackbar(error.response?.data?.message || 'Failed to archive notification', 'error')
  }
}

const deleteNotification = async (id: number) => {
  try {
    await axios.delete(`/api/notifications/${id}`)
    notifications.value = notifications.value.filter(n => n.id !== id)
    if (selectedNotification.value?.id === id) {
      selectedNotification.value = null
    }
    showSnackbar('Notification deleted', 'success', 2000)
  } catch (error: any) {
    console.error('Error deleting notification:', error)
    showSnackbar(error.response?.data?.message || 'Failed to delete notification', 'error')
  }
}

// Helper methods (unchanged)
const getNotificationIcon = (eventType?: string): string => {
  const iconMap: Record<string, string> = {
    'requisition_submitted': 'custom-table',
    'requisition_approved': 'custom-check-circle-fill',
    'requisition_rejected': 'custom-x-circle',
    'interview_scheduled': 'custom-calendar-fill',
    'offer_made': 'custom-gift-fill',
    'application_received': 'custom-envelope',
    default: 'custom-notification'
  }
  return iconMap[eventType || 'default'] || iconMap.default
}

const getNotificationColor = (eventType?: string, priority?: string): string => {
  if (priority === 'high') return 'error'
  if (priority === 'medium') return 'warning'

  const colorMap: Record<string, string> = {
    'requisition_submitted': 'primary',
    'requisition_approved': 'success',
    'requisition_rejected': 'error',
    'interview_scheduled': 'info',
    'offer_made': 'success',
    'application_received': 'info',
    default: 'secondary'
  }
  return colorMap[eventType || 'default'] || colorMap.default
}

const getPriorityBadge = (priority?: string) => {
  const badges: Record<string, { color: string; text: string }> = {
    high: { color: 'error', text: 'High Priority' },
    medium: { color: 'warning', text: 'Medium' },
    low: { color: 'secondary', text: 'Low' }
  }
  return badges[priority || ''] || null
}

const formatDate = (date: string) => moment(date).fromNow()
const formatTime = (date: string) => moment(date).format('HH:mm')
const formatFullDate = (date: string) => moment(date).format('MMMM Do YYYY, h:mm:ss a')


const isExpiringSoon = (expiresAt?: string) => {
  if (!expiresAt) return false
  return moment(expiresAt).isBefore(moment().add(24, 'hours'))
}

const goBack = () => router.go(-1)

// Watchers
watch(filterType, () => {
  selectedNotification.value = null
})

watch(searchValue, () => {
  selectedNotification.value = null
})

// Lifecycle
onMounted(() => {
  fetchNotifications()
})
</script>

<template>
  <!-- LOADING INDICATOR -->
  <v-container v-if="isLoading" class="d-flex justify-center py-8">
    <v-progress-circular indeterminate color="primary" size="64"></v-progress-circular>
  </v-container>

  <!-- MAIN CONTENT -->
  <VRow v-else class="mt-0">
    <!-- SIDEBAR -->
    <VSlideXTransition>
      <VCol v-if="!toggleSide && mdAndUp" class="d-flex align-stretch notificationSidebar pe-md-0">
        <VCard variant="outlined" class="bg-surface br-0" rounded="sm">
          <VCardText class="py-5 px-0">
            <h5 class="text-h5 px-5 mb-3">
              Notifications
              <VChip v-if="unreadCount > 0" color="error" size="x-small" variant="flat">
                {{ unreadCount }}
              </VChip>
            </h5>

            <!-- Filter Toggle -->
            <VBtnToggle v-model="filterType" mandatory size="small" color="error" class="w-100 px-5 mb-3"
              density="comfortable">
              <VBtn :value="'all'" variant="flat">
                All ({{ allCount }})
              </VBtn>
              <VBtn :value="'unread'" variant="flat">
                Unread ({{ unreadCount }})
              </VBtn>
              <VBtn :value="'archived'" variant="flat">
                Archived ({{ notifications.length - archivedCount }})
              </VBtn>
            </VBtnToggle>

            <!-- Search -->
            <VTextField v-model="searchValue" variant="outlined" persistent-placeholder
              placeholder="Search notifications" hide-details class="mb-3 px-5">
              <template #prepend-inner>
                <SvgSprite name="custom-search" class="text-lightText" style="width: 20px; height: 20px" />
              </template>
            </VTextField>

            <PerfectScrollbar class="mb-3" style="height: 430px">
              <VList aria-label="notification list" aria-busy="true" border class="px-5">
                <!-- Single Notification -->
                <VListItem v-for="notification in filteredNotifications" :key="notification.id" :value="notification.id"
                  color="secondary" class="text-no-wrap notificationItem" lines="two" rounded="md"
                  :active="selectedNotification?.id === notification.id" @click="selectNotification(notification)">
                  <!-- Avatar / Icon -->
                  <template #prepend>
                    <VAvatar color="error">
                      <SvgSprite :name="getNotificationIcon(selectedNotification.event_type)" color="white" />
                    </VAvatar>
                  </template>

                  <!-- Title -->
                  <VListItemTitle class="text-h6 pr-2 mb-1">
                    {{ notification.title }}
                  </VListItemTitle>

                  <!-- Message / Excerpt -->
                  <VListItemSubtitle class="text-caption mt-n1 text-lightText">
                    {{ notification.message }}
                  </VListItemSubtitle>

                  <!-- Time + Unread Indicator -->
                  <template #append>
                    <div class="d-flex flex-column text-right">
                      <small class="text-lightText text-caption mb-1">
                        {{ formatTime(notification.created_at) }}
                      </small>

                      <VBadge v-if="!notification.is_read" dot color="error" inline />

                      <SvgSprite v-else name="custom-circle-check-outline" class="ml-auto text-lightText"
                        style="width: 16px; height: 16px" />
                    </div>
                  </template>
                </VListItem>

                <!-- Empty State -->
                <div v-if="filteredNotifications.length === 0" class="text-center py-8">
                  <VIcon size="48" color="grey-lighten-1" class="mb-4">custom-bell-off</VIcon>
                  <h6 class="text-h6 text-medium-emphasis mb-2">
                    {{ filterType === 'all'
                      ? 'No notifications available'
                      : `No ${filterType} notifications`
                    }}
                  </h6>
                  <p v-if="searchValue" class="text-caption text-medium-emphasis mb-0">
                    Try a different search term
                  </p>
                </div>
              </VList>
            </PerfectScrollbar>

          </VCardText>
        </VCard>
      </VCol>
    </VSlideXTransition>

    <!-- MAIN CONTENT AREA -->
    <VCol class="d-flex align-stretch ps-md-0">
      <VCard variant="outlined" class="bg-surface bl-0" rounded="lg">
        <!-- HEADER -->
        <div class="d-sm-flex align-center ga-4 pa-4">
          <VBtn icon variant="text" rounded="md" class="d-none d-md-flex" @click="toggleSide = !toggleSide">
            <SvgSprite name="custom-menu-outline" class="text-lightText" style="width: 20px; height: 20px" />
          </VBtn>

          <div class="d-flex align-center">
            <VBtn icon variant="text" class="d-md-none d-sm-flex" @click="sDrawer = !sDrawer">
              <SvgSprite name="custom-menu-outline" class="text-lightText" style="width: 20px; height: 20px" />
            </VBtn>

            <div class="d-flex align-center" v-if="selectedNotification">
              <VAvatar color="error">
                <SvgSprite :name="getNotificationIcon(selectedNotification.event_type)" color="white" />
              </VAvatar>
              <div class="mx-2">
                <h5 class="text-subtitle-1 mb-0">{{ selectedNotification.title }}</h5>
                <small class="text-lightText">{{ formatFullDate(selectedNotification.created_at) }}</small>
              </div>
            </div>
          </div>

          <!-- ACTIONS -->
          <div class="ms-auto ga-2 d-flex">
            <VBtn icon variant="text" rounded="md" @click="goBack">
              <SvgSprite name="custom-arrow-right" class="text-lightText" style="width: 20px; height: 20px" />
              <VTooltip activator="parent" location="top" content-class="smallTooltip">
                <span class="text-caption">Back</span>
              </VTooltip>
            </VBtn>

            <VBtn icon variant="text" rounded="md" @click="markAllAsRead">
              <SvgSprite name="custom-check-circle-fill" class="text-lightText" style="width: 20px; height: 20px" />
              <VTooltip activator="parent" location="top" content-class="smallTooltip">
                <span class="text-caption">Mark All as Read</span>
              </VTooltip>
            </VBtn>

            <!-- MENU -->
            <VMenu rounded="md" :close-on-content-click="false">
              <template #activator="{ props }">
                <VBtn icon variant="text" rounded="md" v-bind="props">
                  <VIcon icon="$dotsVertical" class="text-lightText" size="20" />
                </VBtn>
              </template>
              <VList rounded="md" elevation="24" width="180" density="compact" class="py-0">
                <VListItem v-for="(item, index) in items(selectedNotification?.id || 0)" :key="index"
                  @click="item.action()">
                  <template #prepend>
                    <SvgSprite :name="item.icon" class="me-2 text-lightText" style="width: 16px; height: 16px" />
                  </template>
                  <VListItemTitle class="text-h6">
                    {{ item.title }}
                  </VListItemTitle>
                </VListItem>
              </VList>
            </VMenu>
          </div>
        </div>

        <VDivider />

        <!-- NOTIFICATION DETAIL -->
        <div v-if="selectedNotification" class="customHeight pa-4">
          <VCard flat class="pa-6 mb-4">
            <p class="text-body1 mb-4 text-justify">{{ selectedNotification.message }}</p>

            <!-- PRIORITY BADGE -->
            <VChip v-if="getPriorityBadge(selectedNotification.priority)"
              :color="getPriorityBadge(selectedNotification.priority).color" size="small" class="mb-3">
              {{ getPriorityBadge(selectedNotification.priority).text }}
            </VChip>

            <!-- Expiry Warning -->
            <VAlert v-if="selectedNotification.expires_at"
              :type="isExpiringSoon(selectedNotification.expires_at) ? 'error' : 'warning'" variant="tonal"
              density="compact" class="mb-4">
              <template #prepend>
                <VIcon>$alarm</VIcon>
              </template>
              This notification expires {{ formatDate(selectedNotification.expires_at) }}
            </VAlert>

            <!-- Action Button -->
            <div class="d-flex justify-center">
              <a v-if="selectedNotification.action_url" :href="selectedNotification.action_url" target="_blank"
                class="d-flex align-center text-decoration-none text-body2 text-error">
                <VIcon size="18" start>$openInNew</VIcon>
                View Details
              </a>
            </div>
          </VCard>
        </div>

        <!-- NO SELECTION STATE -->
        <div v-else class="customHeight d-flex flex-column align-center justify-center text-center pa-4">
          <VAvatar size="64" color="error" class="mb-4">
            <VIcon color="white" size="large">$bellRingOutline</VIcon>
          </VAvatar>
          <h4 class="text-h4 mb-3 text-medium-emphasis">No Notification Selected</h4>
          <p class="text-body2 text-medium-emphasis mb-4">
            Select a notification from the list to view details
          </p>
          <VBtn v-if="filteredNotifications.length > 0" color="primary"
            @click="selectNotification(filteredNotifications[0])">
            View Latest Notification
          </VBtn>
        </div>

        <!-- MOBILE DRAWER -->
        <VNavigationDrawer v-if="!mdAndUp" v-model="sDrawer" temporary width="300" location="start">
          <!-- Mobile content (unchanged) -->
          <VCardText class="pa-0">
            <div class="pa-4 border-b">
              <h5 class="text-h5 mb-2">
                Notifications
                <VChip color="error" size="x-small" variant="flat" class="ms-2">
                  {{ unreadCount }}
                </VChip>
              </h5>
            </div>
            <PerfectScrollbar style="height: calc(100vh - 140px)">
              <VList class="px-3">
                <VListItem v-for="notification in filteredNotifications.slice(0, 10)" :key="notification.id"
                  :active="selectedNotification?.id === notification.id" class="notificationItemMobile"
                  @click="selectNotification(notification); sDrawer = false">
                  <template #prepend>
                    <VAvatar size="32" color="error">
                      <SvgSprite :name="getNotificationIcon(notification.event_type)" color="white" size="small" />
                    </VAvatar>
                  </template>
                  <VListItemTitle class="text-body-2 font-weight-medium">
                    {{ notification.title }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-caption">
                    {{ formatTime(notification.created_at) }}
                  </VListItemSubtitle>
                </VListItem>
              </VList>
            </PerfectScrollbar>
          </VCardText>
        </VNavigationDrawer>
      </VCard>
    </VCol>
  </VRow>

  <!-- **SNACKBAR** -->
  <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="snackbar.timeout" location="top right">
    {{ snackbar.message }}
    <template #actions>
      <VBtn v-if="snackbar.actionText" variant="text" color="white" size="small"
        @click="snackbar.action?.(); closeSnackbar()">
        {{ snackbar.actionText }}
      </VBtn>
      <VBtn variant="text" color="white" size="small" @click="closeSnackbar()">
        <VIcon icon="$close" size="14" />
      </VBtn>
    </template>
  </VSnackbar>
</template>

<!-- STYLES (unchanged) -->
<style lang="scss">
.br-0 {
  @media (min-width: 960px) {
    border-right: none;
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;
  }
}

.bl-0 {
  @media (min-width: 960px) {
    border-top-left-radius: 0 !important;
    border-bottom-left-radius: 0 !important;
  }
}

.notificationSidebar {
  max-width: 360px;
}

.customHeight {
  min-height: calc(100vh - 200px);
}

.notificationItem {
  padding: 16px !important;
  margin-bottom: 4px !important;

  &:deep(.v-list-item__content) {
    flex-grow: 1;
  }

  &.v-list-item--active {
    border-left: 3px solid rgb(var(--v-theme-error)) !important;

  }

}

.notificationItemMobile {
  padding: 12px !important;
  margin: 2px 0 !important;
}

:deep(.v-btn-toggle .v-btn) {
  flex: 1;
}

:deep(.v-btn-toggle .v-btn--active) {
  background-color: rgb(var(--v-theme-primary)) !important;
  color: white !important;
}

.border-b {
  border-bottom: 1px solid rgba(0, 0, 0, 0.12);
}
</style>