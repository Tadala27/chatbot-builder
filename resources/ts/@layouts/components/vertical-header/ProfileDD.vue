<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAbility } from '@casl/vue'
import { useUserStore } from '@/stores/user'

const router = useRouter()
const ability = useAbility()
const userStore = useUserStore()

const userData = ref(userStore.user)

const logout = async () => {
  try {

    await userStore.logout()

    useCookie('accessToken').value = null
    useCookie('userAbilityRules').value = null

    // Reset ability
    ability.update([])

    localStorage.removeItem('user')
    localStorage.removeItem('isStaff')

    // window.location.href = '/login'
  } catch (error: any) {
    console.error('Logout failed:', error.response?.data || error.message)
    // Still redirect even if the API call failed
    router.push({ name: 'login' })
  }
}

const tab = ref(null)
const profiledata1 = ref([
  {
    title: 'Edit profile',
    icon: 'custom-edit',
    link: { name: 'profile' },
  },
])
</script>

<template>
  <!-- ---------------------------------------------- -->
  <!-- profile DD -->
  <!-- ---------------------------------------------- -->
  <div>
    <div class="d-flex align-center pa-5">

      <VAvatar variant="tonal" color="primary" class="me-2">
        <SvgSprite name="custom-user-fill" style="width: 20px; height: 20px" />
      </VAvatar>
      <div>
        <h6 class="text-subtitle-1 mb-0 text-error">
          {{ userData.firstname || 'Guest' }} {{ userData.lastname || '' }}
        </h6>
        <p class="text-caption text-lightText mb-0">
          {{ userData.user_role || 'User' }}
        </p>
      </div>
      <div class="ms-auto">
        <VBtn variant="text" aria-label="logout" color="error" rounded="sm" icon size="large" @click="logout">
          <SvgSprite name="custom-logout-1" />
        </VBtn>
      </div>
    </div>
    <VTabs v-model="tab" color="primary" grow>
      <VTab value="111">
        <div class="v-icon--start">
          <SvgSprite name="custom-user-outline" style="width: 18px; height: 18px" />
        </div>
        Profile
      </VTab>
    </VTabs>
    <VDivider />
    <PerfectScrollbar style="height: calc(100vh - 300px); max-height: 140px">
      <VWindow v-model="tab">
        <VWindowItem value="111">
          <VList class="px-2" aria-label="profile list" aria-busy="true">
            <VListItem v-for="(item, index) in profiledata1" :key="index" color="primary" base-color="secondary"
              rounded="md" :value="item.title" :to="item.link">
              <template #prepend>
                <div class="me-4">
                  <SvgSprite :name="item.icon || ''" style="width: 18px; height: 18px" />
                </div>
              </template>

              <VListItemTitle class="text-h6">
                {{ item.title }}
              </VListItemTitle>
            </VListItem>
            <VListItem color="primary" base-color="secondary" rounded="md" @click="logout">
              <template #prepend>
                <div class="me-4">
                  <SvgSprite name="custom-logout-1" style="width: 18px; height: 18px" />
                </div>
              </template>

              <VListItemTitle @click="logout" class="text-h6">
                Logout
              </VListItemTitle>
            </VListItem>
          </VList>
        </VWindowItem>
      </VWindow>
    </PerfectScrollbar>
  </div>
</template>
