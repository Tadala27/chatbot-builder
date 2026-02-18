<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from "axios"


// Types
interface Notification {
  id: number
  title: string
  message: string
  notifiable_type: string
  is_read: boolean
  created_at: string
  updated_at: string
  user_id: number
}

// Reactive state
const notifications = ref<Notification[]>([])
const isLoading = ref(false)
const error = ref<string | null>(null)
const menuOpen = ref(false)

// Auto-refresh interval
let refreshInterval: NodeJS.Timeout | null = null

// Computed properties
const unreadCount = computed(() =>
  notifications.value.filter(n => !n.is_read).length
)

const sortedNotifications = computed(() =>
  notifications.value.sort((a, b) =>
    new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
  )
)

// Helper functions
const extractNotificationType = (notifiableType: string): string => {
  // Extract model name from Laravel namespace
  const modelName = notifiableType.split('\\').pop()?.toLowerCase() || 'default'

  // Map model names to notification types
  const typeMapping: Record<string, string> = {
    'jobrequisition': 'job',
    'jobpost': 'job',
    'jobapplication': 'application',
    'user': 'user',
    'message': 'message',
    'comment': 'comment',
    'notification': 'system',
    'meeting': 'meeting',
    'task': 'task',
    'project': 'project',
    'document': 'document',
    'report': 'report',
    'approval': 'approval',
    'leave': 'leave',
    'timesheet': 'timesheet',
    'performance': 'performance',
    'training': 'training',
    'expense': 'expense',
    'invoice': 'invoice',
    'payroll': 'payroll'
  }

  return typeMapping[modelName] || 'system'
}

const formatTimeAgo = (dateString: string): string => {
  const date = new Date(dateString)
  const now = new Date()
  const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000)

  if (diffInSeconds < 60) return `${diffInSeconds} seconds ago`
  if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)} min ago`
  if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)} hours ago`
  if (diffInSeconds < 2592000) return `${Math.floor(diffInSeconds / 86400)} days ago`

  return date.toLocaleDateString()
}

const formatTime = (dateString: string): string => {
  const date = new Date(dateString)
  return date.toLocaleTimeString('en-US', {
    hour: 'numeric',
    minute: '2-digit',
    hour12: true
  })
}

const getNotificationIcon = (notifiableType: string): string => {
  const type = extractNotificationType(notifiableType)

  switch (type) {
    case 'job':
      return 'custom-box-1'
    case 'application':
      return 'custom-user-check'
    case 'user':
      return 'custom-user'
    case 'message':
    case 'comment':
      return 'custom-message-fill'
    case 'meeting':
      return 'custom-calendar-fill'
    case 'task':
      return 'custom-check-circle'
    case 'project':
      return 'custom-folder'
    case 'document':
      return 'custom-file'
    case 'report':
      return 'custom-chart'
    case 'approval':
      return 'custom-check'
    case 'leave':
      return 'custom-calendar-off'
    case 'timesheet':
      return 'custom-clock'
    case 'performance':
      return 'custom-trending-up'
    case 'training':
      return 'custom-book'
    case 'expense':
      return 'custom-credit-card'
    case 'invoice':
      return 'custom-receipt'
    case 'payroll':
      return 'custom-dollar-sign'
    case 'system':
    default:
      return 'custom-setting-fill'
  }
}

const getNotificationTypeLabel = (notifiableType: string): string => {
  const type = extractNotificationType(notifiableType)

  const labels: Record<string, string> = {
    'job': 'Job',
    'application': 'Application',
    'user': 'User',
    'message': 'Message',
    'comment': 'Comment',
    'meeting': 'Meeting',
    'task': 'Task',
    'project': 'Project',
    'document': 'Document',
    'report': 'Report',
    'approval': 'Approval',
    'leave': 'Leave',
    'timesheet': 'Timesheet',
    'performance': 'Performance',
    'training': 'Training',
    'expense': 'Expense',
    'invoice': 'Invoice',
    'payroll': 'Payroll',
    'system': 'System'
  }

  return labels[type] || 'Notification'
}

// API methods
const fetchNotifications = async () => {
  try {
    isLoading.value = true
    error.value = null

    const response = await axios.get('/api/notifications')

    if (response.data && response.data.notifications) {
      notifications.value = response.data.notifications
    }
  } catch (err: any) {
    console.error('Error fetching notifications:', err)
    error.value = err.response?.data?.message || 'Failed to load notifications'

    if (err.response?.status === 401) {
      // Handle unauthorized - redirect to login or show auth error
      console.log('User unauthorized')
    }
  } finally {
    isLoading.value = false
  }
}

const markAsRead = async (notificationId: number) => {
  try {
    await axios.patch(`/api/notifications/${notificationId}/read`)

    // Update local state
    const notification = notifications.value.find(n => n.id === notificationId)
    if (notification) {
      notification.is_read = true
    }
  } catch (err: any) {
    console.error('Error marking notification as read:', err)
    error.value = err.response?.data?.message || 'Failed to mark notification as read'
  }
}

const markAllAsRead = async () => {
  try {
    await axios.post('/api/notifications/markAll')

    // Update local state
    notifications.value.forEach(notification => {
      notification.is_read = true
    })
  } catch (err: any) {
    console.error('Error marking all notifications as read:', err)
    error.value = err.response?.data?.message || 'Failed to mark all notifications as read'
  }
}

const handleNotificationClick = (notification: Notification) => {
  if (!notification.is_read) {
    markAsRead(notification.id)
  }
  console.log('Notification clicked:', notification)
}

const startAutoRefresh = () => {
  // Refresh notifications every 30 seconds
  refreshInterval = setInterval(() => {
    if (!menuOpen.value) {
      fetchNotifications()
    }
  }, 30000)
}

const stopAutoRefresh = () => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
    refreshInterval = null
  }
}

// Lifecycle
onMounted(() => {
  fetchNotifications()
  // startAutoRefresh()
})

onUnmounted(() => {
  stopAutoRefresh()
})

// Watch menu state to refresh when opened
const handleMenuUpdate = (isOpen: boolean) => {
  menuOpen.value = isOpen
  if (isOpen) {
    fetchNotifications()
  }
}
const truncateMessage = (text: string, length = 100): string => {
  if (!text) return ''
  return text.length > length ? text.substring(0, length) + '…' : text
}
</script>

<template>
  <VMenu :model-value="menuOpen" @update:model-value="handleMenuUpdate" :close-on-content-click="false" offset="6, 0">
    <template #activator="{ props }">
      <VBtn icon class="text-secondary ml-sm-2 ml-1" color="secondary" rounded="sm" v-bind="props">
        <VBadge v-if="unreadCount > 0" color="success" :content="unreadCount.toString()" offset-x="-2" offset-y="-2">
          <SvgSprite name="custom-notification" />
        </VBadge>
        <SvgSprite v-else name="custom-notification" />
      </VBtn>
    </template>

    <VSheet rounded="md" width="420" class="notification-dropdown py-6">
      <div class="d-flex align-center justify-space-between pb-4 px-6">
        <h5 class="text-h5 mb-0">
          Notifications
        </h5>
        <a v-if="unreadCount > 0" href="#" class="text-primary link-hover text-h6" @click.prevent="markAllAsRead">
          Mark all read
        </a>
      </div>

      <!-- Loading State -->
      <div v-if="isLoading" class="d-flex justify-center py-8">
        <VProgressCircular indeterminate color="primary" size="40" />
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="px-6 py-4">
        <VAlert type="error" variant="tonal" class="mb-0">
          {{ error }}
        </VAlert>
      </div>

      <PerfectScrollbar v-else-if="notifications.length > 0" style="height: calc(100vh - 300px); max-height: 430px">
        <VList aria-label="notification list" aria-busy="true" border class="px-5 py-0">

          <VListItem v-for="notification in sortedNotifications" :key="notification.id" :value="notification.id"
            color="secondary" class="text-no-wrap notificationItem py-5 mb-3 rounded-md cursor-pointer" lines="two"
            rounded="md" @click="handleNotificationClick(notification)">
            <!-- Avatar / Icon -->
            <template #prepend>
              <VAvatar size="40" color="primary">
                <SvgSprite :name="getNotificationIcon(notification.notifiable_type)" color="white" />
              </VAvatar>
            </template>

            <!-- Title -->
            <VListItemTitle class="text-h6 pr-2 mb-1 text-darkText">
              {{ notification.title }}
            </VListItemTitle>

            <!-- Message -->
            <VListItemSubtitle class="text-caption mt-n1 text-lightText">
              {{ truncateMessage(notification.message, 80) }}
            </VListItemSubtitle>

            <!-- Right-side Time + Unread Indicator -->
            <template #append>
              <div class="d-flex flex-column text-right">
                <small class="text-lightText text-caption mb-1">
                  {{ formatTime(notification.created_at) }}
                </small>

                <VBadge v-if="!notification.is_read" dot color="primary" inline />

                <SvgSprite v-else name="custom-circle-check-outline" class="ml-auto text-lightText"
                  style="width: 16px; height: 16px" />
              </div>
            </template>

            <!-- Bottom Row: Time Ago + Type -->
            <div class="d-flex justify-space-between align-center mt-2 w-100">
              <span class="text-caption text-lightText">
                {{ formatTimeAgo(notification.created_at) }}
              </span>

              <VChip size="x-small" color="primary" variant="outlined" class="text-caption">
                {{ getNotificationTypeLabel(notification.notifiable_type) }}
              </VChip>
            </div>
          </VListItem>
        </VList>
      </PerfectScrollbar>


      <!-- Empty State -->
      <div v-else class="text-center py-8 px-6">
        <VIcon size="48" color="disabled" class="mb-4">
          $bellRingOutline
        </VIcon>
        <h6 class="text-h6 text-disabled mb-2">
          No notifications
        </h6>
        <p class="text-caption text-disabled mb-0">
          You're all caught up! Check back later for new updates.
        </p>
      </div>

      <div v-if="notifications.length > 0" class="pt-2 text-center">
        <a href="#" class="text-primary text-h6 link-hover" @click.prevent="$router?.push({ name: 'notifications' })">
          View All
        </a>
      </div>
    </VSheet>
  </VMenu>
</template>

<style lang="scss">
.v-tooltip {
  >.v-overlay__content.custom-tooltip {
    padding: 2px 6px;
  }
}

.notification-dropdown {
  .v-list-item {
    transition: background-color 0.2s ease;
    border: 1px solid rgb(var(--v-theme-primary)) !important;

    &:hover {
      background-color: rgba(var(--v-theme-primary), 0.04);
    }

    &.bg-light {
      background-color: rgba(var(--v-theme-primary), 0.02);
      border-left: 3px solid rgb(var(--v-theme-primary));
    }
  }
}

.link-hover {
  text-decoration: none;
  transition: opacity 0.2s ease;

  &:hover {
    opacity: 0.8;
  }
}
</style>