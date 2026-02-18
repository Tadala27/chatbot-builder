<script setup lang="ts">
import { ref, watch } from 'vue'
import { useCustomizerStore } from '@layouts/stores/customizer'
import Logo from '@layouts/components/LogoMain.vue'

// dropdown imports
import LanguageDD from '@layouts/components/vertical-header/LanguageDD.vue'
import NotificationDD from '@layouts/components/vertical-header/NotificationDD.vue'
import ProfileDD from '@layouts/components/vertical-header/ProfileDD.vue'
import MegaMenuDD from '@layouts/components/vertical-header/MegaMenuDD.vue'
import Searchbar from '@layouts/components/vertical-header/SearchBarPanel.vue'

// assets
import message1 from '@images/widget/message/message1.svg'
import message2 from '@images/widget/message/message2.svg'
import message3 from '@images/widget/message/message3.svg'
import message4 from '@images/widget/message/message4.svg'

const messagedrawer = ref(false)

const customizer = useCustomizerStore()
const priority = ref(customizer.isHorizontalLayout ? 1 : 0)

watch(priority, newPriority => {
  // yes, console.log() is a side effect
  priority.value = newPriority
})
</script>

<template>
  <VAppBar elevation="0" :priority="priority" height="74" class="px-sm-10 px-5">
    <div class="py-5 pe-16 hidden-md-and-down">
      <Logo />
    </div>
    <VBtn class="hidden-lg-and-up text-secondary" color="darkText" icon rounded="sm" variant="text" size="small"
      @click.stop="customizer.SET_SIDEBAR_DRAWER">
      <SvgSprite name="custom-menu-outline" style="width: 24px; height: 24px" />
    </VBtn>

    <!-- search mobile -->
    <VMenu :close-on-content-click="false" class="hidden-lg-and-up" offset="10, 0">
      <template #activator="{ props }">
        <VBtn class="hidden-lg-and-up text-secondary ml-1" color="secondary" icon rounded="sm" variant="text"
          size="small" v-bind="props">
          <div class="text-lightText d-flex align-center">
            <SvgSprite name="custom-search" style="width: 16px; height: 16px" />
          </div>
        </VBtn>
      </template>
      <VSheet class="search-sheet v-col-12 pa-0" width="320">
        <VTextField persistent-placeholder placeholder="Search here.." color="primary" variant="solo" hide-details>
          <template #prepend-inner>
            <div class="text-lightText d-flex align-center">
              <SvgSprite name="custom-search" style="width: 16px; height: 16px" />
            </div>
          </template>
        </VTextField>
      </VSheet>
    </VMenu>

    <!-- ---------------------------------------------- -->
    <!-- Search part -->
    <!-- ---------------------------------------------- -->
    <VSheet class="d-none d-lg-block bg-transparent" width="250">
      <Searchbar />
    </VSheet>

    <!-- - /Search part -->

    <VSpacer />
    <!-- ---------------------------------------------- -->
    <!-- - right part -->
    <!-- ---------------------------------------------- -->

    <!-- ---------------------------------------------- -->
    <!-- Messages -->
    <!-- ---------------------------------------------- -->
    <VMenu :close-on-content-click="false" offset="10, 320">
      <template #activator="{ props }">
        <VBtn icon class="text-secondary hidden-sm-and-down d-lg-block d-none" color="secondary" rounded="sm"
          variant="text" v-bind="props">
          <SvgSprite name="custom-window" />
        </VBtn>
      </template>
      <VSheet width="1024" height="325" rounded="md" class="d-lg-block d-none">
        <MegaMenuDD />
      </VSheet>
    </VMenu>
    <!-- ---------------------------------------------- -->
    <!-- translate -->
    <!-- ---------------------------------------------- -->
    <VMenu :close-on-content-click="false" location="bottom" offset="6, 80">
      <template #activator="{ props }">
        <VBtn icon class="ml-sm-2 ml-1" color="secondary" rounded="sm" v-bind="props">
          <SvgSprite name="custom-translation" />
        </VBtn>
      </template>
      <VSheet rounded="md" width="200">
        <LanguageDD />
      </VSheet>
    </VMenu>

    <!-- ---------------------------------------------- -->
    <!-- Notification -->
    <!-- ---------------------------------------------- -->
    <NotificationDD />

    <!-- ---------------------------------------------- -->
    <!-- Message -->
    <!-- ---------------------------------------------- -->
    <VBtn icon class="ml-sm-2 ml-1" color="secondary" rounded="sm" @click.stop="messagedrawer = !messagedrawer">
      <SvgSprite name="custom-message-note" />
    </VBtn>

    <!-- ---------------------------------------------- -->
    <!-- User Profile -->
    <!-- ---------------------------------------------- -->
    <VMenu :close-on-content-click="false" offset="8, 0">
      <template #activator="{ props }">
        <VBtn class="profileBtn mr-0" variant="text" rounded="circle" icon v-bind="props">
          <VAvatar class="py-2" size="40" rounded="circle">
            <img src="@images/users/avatar-6.png" class="rounded-circle" alt="profile">
          </VAvatar>
        </VBtn>
      </template>
      <VSheet rounded="md" width="290">
        <ProfileDD />
      </VSheet>
    </VMenu>
  </VAppBar>
  <VNavigationDrawer v-model="messagedrawer" app temporary elevation="24" location="end" border="0" width="474"
    class="message-drawer" style="z-index: 1075 !important">
    <VRow class="ma-0">
      <VCol cols="12" class="pa-0">
        <div class="py-4 px-5 d-flex justify-space-between align-center">
          <div class="text-h5 font-weight-medium">
            What’s new announcement?
          </div>
          <VBtn variant="text" color="secondary" icon rounded="md" @click="messagedrawer = !messagedrawer">
            <SvgSprite name="custom-close" style="width: 18px; height: 18px; transform: rotate(45deg)" />
          </VBtn>
        </div>
      </VCol>
    </VRow>
    <PerfectScrollbar style="height: calc(100vh - 100px)">
      <VList class="px-5" aria-label="message list" aria-busy="true">
        <VListItemTitle class="text-h6 mb-2">
          Today
        </VListItemTitle>
        <VListItem class="px-0">
          <VCard variant="outlined">
            <VCardText>
              <VChip color="success" variant="tonal" size="small">
                New Feature
              </VChip>
              <span class="ms-2 text-caption">just now</span>
              <VBadge dot color="warning" size="small" inline />
              <h6 class="text-subtitle-1 my-3">
                Select Business Unit
              </h6>
              <p class="text-h6">
                You can use the Analytics Dashboard to explore how many new users download reports daily and monthly
              </p>
              <VImg :src="message1" alt="cover" width="100%" />
              <VRow class="mt-2">
                <VCol cols="6" sm="6">
                  <VBtn color="secondary" rounded="md" variant="outlined" block>
                    Skip intro
                  </VBtn>
                </VCol>
                <VCol cols="6" sm="6">
                  <VBtn color="primary" variant="flat" rounded="md" block>
                    Next
                  </VBtn>
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VListItem>
        <VListItem class="px-0 pt-2">
          <VCard variant="outlined">
            <VCardText>
              <VChip color="warning" variant="tonal" size="small">
                Meeting
              </VChip>
              <span class="ms-2 text-caption">2 min ago</span>
              <VBadge dot color="warning" size="small" inline />
              <h6 class="text-subtitle-1 my-3">
                General Meeting for update
              </h6>
              <p class="text-h6">
                You can use the Dashboard to explore how many new users download reports daily and monthly
              </p>
              <VImg :src="message2" alt="cover" width="100%" />
            </VCardText>
          </VCard>
        </VListItem>
        <VListItemTitle class="text-h6 mb-2 mt-4">
          Yesterday
        </VListItemTitle>
        <VListItem class="px-0">
          <VCard variant="outlined">
            <VCardText>
              <VChip color="primary" variant="tonal" size="small">
                Improvement
              </VChip>
              <span class="ms-2 text-caption">2 hours ago</span>
              <VBadge dot color="warning" size="small" inline />
              <h6 class="text-subtitle-1 my-3">
                Widgets update
              </h6>
              <p class="text-h6">
                We've made some updates to the emendable widget which we think you are going to love.
              </p>
              <VImg :src="message3" alt="cover" width="100%" />
            </VCardText>
          </VCard>
        </VListItem>
        <VListItem class="px-0 pt-2 pb-4">
          <VCard variant="outlined">
            <VCardText>
              <VChip color="primary" variant="tonal" size="small">
                Improvement
              </VChip>
              <span class="ms-2 text-caption">1 day ago</span>
              <VBadge dot color="warning" size="small" inline />
              <h6 class="text-subtitle-1 my-3">
                Coming soon dark mode
              </h6>
              <p class="text-h6">
                We've made some updates to the emendable widget which we think you are going to love.
              </p>
              <VImg :src="message4" alt="cover" width="100%" />
            </VCardText>
          </VCard>
        </VListItem>
      </VList>
    </PerfectScrollbar>
  </VNavigationDrawer>
</template>
