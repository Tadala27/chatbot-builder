<template>
  <VRow class="mt-0">
    <!-- Chat Sidebar -->
    <VCol v-if="!toggleSide && mdAndUp" class="d-flex align-stretch chatSidebar pe-md-0">
      <VCard variant="outlined" class="bg-surface br-0" rounded="lg">
        <VCardText class="py-5 px-0">
          <h5 class="text-h5 px-5">
            Messages
            <VChip color="secondary" size="x-small" variant="flat">
              {{dummyChats.filter(c => c.unread > 0).length}}
            </VChip>
          </h5>

          <!-- Chat Listing -->
          <div>
            <div class="py-3 px-5 mt-2">
              <VTextField v-model="searchValue" variant="outlined" persistent-placeholder placeholder="Search Contact"
                hide-details>
                <template #prepend-inner>
                  <SvgSprite name="custom-search" class="text-lightText" style="width: 20px; height: 20px" />
                </template>
              </VTextField>
            </div>
            <PerfectScrollbar class="mb-3" style="height: 430px">
              <VList aria-label="chat list" aria-busy="true" border class="px-5">
                <VListItem v-for="chat in filteredChats" :key="chat.id" :value="chat.id" color="secondary"
                  class="text-no-wrap chatItem" lines="two" rounded="md" :active="activeChatId === chat.id"
                  @click="selectChat(chat.id)">
                  <template #prepend>
                    <VAvatar>
                      <img :src="chat.avatar" alt="pro" width="40">
                    </VAvatar>
                    <SvgSprite class="badg-dot" :name="chat.status === 'away'
                      ? 'custom-away-fill'
                      : chat.status === 'do not disturb'
                        ? 'custom-disturb-fill'
                        : chat.status === 'active'
                          ? 'custom-check-circle-fill'
                          : 'containerBg'
                      " :class="chat.status === 'away'
                        ? 'text-warning'
                        : chat.status === 'do not disturb'
                          ? 'text-secondary'
                          : chat.status === 'active'
                            ? 'text-success'
                            : 'text-containerBg'
                        " style="width: 14px; height: 14px" />
                  </template>
                  <VListItemTitle class="text-h5 pr-2 mb-1">
                    {{ chat.name }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-caption mt-n1 text-lightText" style="opacity: 1">
                    {{ chat.lastMessage }}
                  </VListItemSubtitle>
                  <template #append>
                    <div class="d-flex flex-column text-right">
                      <small class="text-lightText text-caption mb-1">{{ chat.time }}</small>
                      <VBadge v-if="chat.unread > 0" :color="chat.unread > 0 ? 'primary' : ''" :content="chat.unread"
                        inline />
                      <SvgSprite v-else name="custom-circle-check-outline" class="ml-auto text-lightText"
                        style="width: 16px; height: 16px" />
                    </div>
                  </template>
                </VListItem>
              </VList>
            </PerfectScrollbar>
          </div>

          <!-- User Profile -->
          <div>
            <VList rounded="md" density="comfortable" color="secondary" aria-label="profile list" aria-busy="true"
              elevation="0" class="py-0 px-5">
              <VListItem color="secondary" value="logout" rounded="md">
                <template #prepend>
                  <SvgSprite name="custom-logout-1" class="me-2 text-lightText" style="width: 24px; height: 24px" />
                </template>
                <VListItemTitle class="text-h6 text-lightText">
                  LogOut
                </VListItemTitle>
              </VListItem>
              <VListItem color="secondary" value="setting" rounded="md">
                <template #prepend>
                  <SvgSprite name="custom-setup" class="me-2 text-lightText" style="width: 24px; height: 24px" />
                </template>
                <VListItemTitle class="text-h6 text-lightText">
                  Settings
                </VListItemTitle>
              </VListItem>
            </VList>
            <div class="d-flex align-center pa-5 px-10 pb-0">
              <VAvatar class="me-2">
                <img :src="currentUser.avatar" alt="pro" width="40">
              </VAvatar>
              <SvgSprite class="badg-dotDetail" :name="currentUser.status === 'away'
                ? 'custom-away-fill'
                : currentUser.status === 'do not disturb'
                  ? 'custom-disturb-fill'
                  : currentUser.status === 'active'
                    ? 'custom-check-circle-fill'
                    : 'containerBg'
                " :class="currentUser.status === 'away'
                  ? 'text-warning'
                  : currentUser.status === 'do not disturb'
                    ? 'text-secondary'
                    : currentUser.status === 'active'
                      ? 'text-success'
                      : 'text-containerBg'
                  " style="width: 14px; height: 14px" />
              <div>
                <h5 class="text-h5 mb-0">
                  {{ currentUser.name }}
                </h5>
              </div>
              <div class="ms-auto">
                <VMenu location="top" rounded="md">
                  <template #activator="{ props }">
                    <VBtn icon v-bind="props" aria-label="dropdown" variant="text" size="x-small" rounded="md">
                      <SvgSprite name="custom-chevron-outline" class="text-lightText"
                        style="width: 16px; height: 16px" />
                    </VBtn>
                  </template>
                  <VList width="150" rounded="md" density="compact" elevation="24" class="py-0">
                    <VListItem v-for="(item, index) in profileItems" :key="index" :value="index">
                      <template #prepend>
                        <SvgSprite :name="item.icon || ''" :class="`me-1 text-${item.color}`"
                          style="width: 16px; height: 16px" />
                      </template>
                      <VListItemTitle class="text-h6" @click="currentUser.status = item.title">
                        {{ item.title }}
                      </VListItemTitle>
                    </VListItem>
                  </VList>
                </VMenu>
              </div>
            </div>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Chat Detail -->
    <VCol class="d-flex align-stretch ps-md-0">
      <VCard variant="outlined" class="bg-surface bl-0" rounded="lg">
        <div v-if="selectedChat" class="customHeight">
          <!-- Chat Detail Header -->
          <div class="d-sm-flex align-center ga-4 pa-4">
            <VBtn icon aria-label="menu" variant="text" rounded="md" class="d-none d-md-flex"
              @click="toggleSide = !toggleSide">
              <SvgSprite name="custom-menu-outline" class="text-lightText" style="width: 20px; height: 20px" />
            </VBtn>
            <div class="d-flex align-center">
              <VBtn icon variant="text" class="d-md-none d-sm-flex" @click="sDrawer = !sDrawer">
                <Menu2Icon size="20" />
              </VBtn>
              <div class="d-flex align-center">
                <VAvatar>
                  <img :src="selectedChat.avatar" alt="pro" width="40">
                </VAvatar>
                <SvgSprite class="badg-Detail" :name="selectedChat.status === 'away'
                  ? 'custom-away-fill'
                  : selectedChat.status === 'do not disturb'
                    ? 'custom-disturb-fill'
                    : selectedChat.status === 'active'
                      ? 'custom-check-circle-fill'
                      : 'containerBg'
                  " :class="selectedChat.status === 'away'
                    ? 'text-warning'
                    : selectedChat.status === 'do not disturb'
                      ? 'text-secondary'
                      : selectedChat.status === 'active'
                        ? 'text-success'
                        : 'text-containerBg'
                    " style="width: 14px; height: 14px" />
                <div>
                  <h5 class="text-subtitle-1 mb-0">
                    {{ selectedChat.name }}
                  </h5>
                  <small class="text-lightText"> Active {{ selectedChat.time }} </small>
                </div>
              </div>
            </div>
            <div class="ms-auto ga-2 d-flex">
              <VBtn icon variant="text" aria-label="phone" rounded="md">
                <SvgSprite name="custom-phone-outline" class="text-lightText" style="width: 20px; height: 20px" />
              </VBtn>
              <VBtn icon variant="text" aria-label="camera" rounded="md">
                <SvgSprite name="custom-camera-outline" class="text-lightText" style="width: 20px; height: 20px" />
              </VBtn>
              <VBtn icon variant="text" aria-label="info" rounded="md" @click.stop="infodrawer = !infodrawer">
                <SvgSprite name="custom-info-circle-outline" class="text-lightText" style="width: 20px; height: 20px" />
              </VBtn>
              <VMenu rounded="md">
                <template #activator="{ props }">
                  <VBtn icon variant="text" aria-label="menu" rounded="md" v-bind="props">
                    <SvgSprite name="custom-more-outline" class="text-lightText" style="width: 20px; height: 20px" />
                  </VBtn>
                </template>
                <VList rounded="md" elevation="24" aria-label="menu" aria-busy="true" width="110" density="compact"
                  class="py-0">
                  <VListItem v-for="(item, index) in menuItems" :key="index" :value="index">
                    <template #prepend>
                      <SvgSprite :name="item.icon || ''" class="me-2" style="width: 16px; height: 16px" />
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

          <!-- Chat History -->
          <PerfectScrollbar style="min-height: calc(100vh - 495px); height: 430px" :options="{ suppressScrollX: true }">
            <div v-for="(message, index) in selectedChat.messages" :key="index" class="pa-5 bg-containerBg">
              <div v-if="!message.fromMe" class="d-flex position-relative mb-4">
                <VAvatar size="40" variant="flat" class="me-2">
                  <img :src="selectedChat.avatar" width="40" alt="vector">
                </VAvatar>
                <SvgSprite class="detail-badg-dot" :name="selectedChat.status === 'away'
                  ? 'custom-away-fill'
                  : selectedChat.status === 'do not disturb'
                    ? 'custom-disturb-fill'
                    : selectedChat.status === 'active'
                      ? 'custom-check-circle-fill'
                      : 'containerBg'
                  " :class="selectedChat.status === 'away'
                    ? 'text-warning'
                    : selectedChat.status === 'do not disturb'
                      ? 'text-secondary'
                      : selectedChat.status === 'active'
                        ? 'text-success'
                        : 'text-containerBg'
                    " style="width: 14px; height: 14px" />
                <div class="mb-3">
                  <VSheet class="bg-surface rounded-md pa-3 mb-1 text-right">
                    <p class="text-body-1 mb-0">
                      {{ message.text }}
                    </p>
                  </VSheet>
                  <small class="text-subtitle-2 text-lightText">{{ message.time }}</small>
                </div>
              </div>
              <div v-else class="ml-auto text-end mb-4">
                <div class="d-flex flex-end userReply position-relative">
                  <VAvatar size="40" variant="flat" class="ms-2">
                    <img :src="currentUser.avatar" width="40" alt="vector">
                  </VAvatar>
                  <SvgSprite name="custom-check-circle-fill" class="detail-badg-dot text-success"
                    style="width: 14px; height: 14px" />
                  <div class="mb-3">
                    <VSheet class="bg-primary rounded-md pa-3 mb-1 d-inline-block">
                      <p class="text-body-1 mb-0">
                        {{ message.text }}
                      </p>
                    </VSheet>
                    <small class="text-subtitle-2 text-lightText d-block">
                      {{ message.time }}
                    </small>
                  </div>
                  <div style="min-width: 80px">
                    <VMenu rounded="md">
                      <template #activator="{ props }">
                        <VBtn icon variant="text" aria-label="menu" size="small" rounded="md" v-bind="props">
                          <SvgSprite name="custom-more-outline" class="text-lightText"
                            style="width: 16px; height: 16px" />
                        </VBtn>
                      </template>
                      <VList elevation="24" width="120" aria-label="menu" aria-busy="true" rounded="md"
                        density="compact" class="py-0">
                        <VListItem v-for="(item, index3) in replyItems" :key="index3" :value="index3">
                          <template #prepend>
                            <SvgSprite :name="item.icon || ''" class="me-2" style="width: 16px; height: 16px" />
                          </template>
                          <VListItemTitle class="text-h6">
                            {{ item.title }}
                          </VListItemTitle>
                        </VListItem>
                      </VList>
                    </VMenu>
                    <VBtn size="small" variant="text" aria-label="edit" class="me-2" rounded="md" icon>
                      <SvgSprite name="custom-edit-outline" class="text-lightText" style="width: 16px; height: 16px" />
                    </VBtn>
                  </div>
                </div>
              </div>
            </div>
          </PerfectScrollbar>

          <!-- Chat Send -->
          <VDivider />
          <form class="pa-4" @submit.prevent="sendMessage">
            <VTextarea v-model="newMessage" placeholder="Your message..." variant="underlined" />
            <div class="d-flex align-center">
              <VBtn icon rounded="md" aria-label="smile" variant="text" size="small">
                <SvgSprite name="custom-smile-outline" class="text-secondary"
                  style="width: 20px; height: 20px; opacity: 0.5" />
              </VBtn>
              <VBtn icon rounded="md" aria-label="clip" variant="text" size="small">
                <SvgSprite name="custom-paper-clip-outline" class="text-secondary"
                  style="width: 20px; height: 20px; opacity: 0.5" />
              </VBtn>
              <VBtn icon rounded="md" aria-label="picture" variant="text" size="small">
                <SvgSprite name="custom-picture-outline" class="text-secondary"
                  style="width: 20px; height: 20px; opacity: 0.5" />
              </VBtn>
              <VBtn icon rounded="md" aria-label="sound" variant="text" size="small">
                <SvgSprite name="custom-sound-outline" class="text-secondary"
                  style="width: 20px; height: 20px; opacity: 0.5" />
              </VBtn>
              <VBtn icon rounded="md" aria-label="send" variant="text" class="ms-auto" color="primary" type="submit">
                <SvgSprite name="custom-send-outline" style="width: 20px; height: 20px" />
              </VBtn>
            </div>
          </form>
        </div>
      </VCard>
    </VCol>
  </VRow>

  <!-- Info Sidebar Drawer -->
  <VNavigationDrawer v-model="infodrawer" temporary location="end" width="300">
    <div v-if="selectedChat" class="customHeight pa-4">
      <div class="text-end">
        <VBtn color="error" aria-label="close" variant="text" icon rounded="md" size="small"
          @click="infodrawer = false">
          <SvgSprite name="custom-close" style="width: 16px; height: 16px; transform: rotate(45deg)" />
        </VBtn>
      </div>
      <div class="py-4">
        <div class="text-center">
          <VAvatar size="88" variant="outlined" color="primary">
            <img :src="selectedChat.avatar" alt="pro" width="88" height="88" class="rounded-circle">
          </VAvatar>
          <h4 class="text-h5 mt-3 mb-0">
            {{ selectedChat.name }}
          </h4>
          <p class="text-caption text-lightText">
            {{ selectedChat.email }}
          </p>
          <div class="d-flex ga-2 align-center justify-center mt-2">
            <SvgSprite :name="selectedChat.status === 'away'
              ? 'custom-away-fill'
              : selectedChat.status === 'do not disturb'
                ? 'custom-disturb-fill'
                : selectedChat.status === 'active'
                  ? 'custom-check-circle-fill'
                  : 'containerBg'
              " :class="selectedChat.status === 'away'
                ? 'text-warning'
                : selectedChat.status === 'do not disturb'
                  ? 'text-secondary'
                  : selectedChat.status === 'active'
                    ? 'text-success'
                    : 'text-containerBg'
                " style="width: 14px; height: 14px" />
            <VChip :color="selectedChat.status === 'away'
              ? 'warning'
              : selectedChat.status === 'do not disturb'
                ? 'secondary'
                : selectedChat.status === 'active'
                  ? 'success'
                  : 'text-containerBg'
              " size="small">
              {{
                selectedChat.status === 'away'
                  ? 'Offline'
                  : selectedChat.status === 'do not disturb'
                    ? 'Do not disturb'
                    : selectedChat.status === 'active'
                      ? 'Available'
                      : 'Offline'
              }}
            </VChip>
          </div>
        </div>
        <div class="d-flex align-center justify-center ga-4 mt-6">
          <VBtn elevation="24" aria-label="mobile" icon rounded="md" size="small">
            <SvgSprite name="custom-mobile-outline-2" class="text-lightText ml-1" style="width: 20px; height: 20px" />
          </VBtn>
          <VBtn elevation="24" aria-label="mail" icon rounded="md" size="small">
            <SvgSprite name="custom-mail-outline" class="text-lightText" style="width: 20px; height: 20px" />
          </VBtn>
          <VBtn elevation="24" aria-label="camera" icon rounded="md" size="small">
            <SvgSprite name="custom-camera-outline" class="text-lightText" style="width: 20px; height: 20px" />
          </VBtn>
        </div>
        <div class="d-flex ga-4 mt-6">
          <div class="bg-lightprimary w-100 pa-4 rounded-lg">
            <h6 class="text-h6 text-primary mb-0">
              All File
            </h6>
            <div class="d-flex align-center">
              <SvgSprite name="custom-folder-open-outline" class="text-primary" />
              <h4 class="text-h4 mb-0 ms-2">
                231
              </h4>
            </div>
          </div>
          <div class="bg-gray100 w-100 pa-4 rounded-lg">
            <h6 class="text-h6 mb-0">
              All Link
            </h6>
            <div class="d-flex align-center">
              <SvgSprite name="custom-link3" />
              <h4 class="text-h4 mb-0 ms-2">
                231
              </h4>
            </div>
          </div>
        </div>
      </div>
      <VExpansionPanels v-model="panel1" class="accordionWithoutBorder mt-2">
        <VExpansionPanel elevation="0">
          <VExpansionPanelTitle class="text-h5 pa-0 pb-3" color="surface">
            Information
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <VList density="compact" class="pa-0" aria-label="information list" aria-busy="true" nav>
              <VListItem class="pa-0">
                <div class="d-flex">
                  <p class="mb-0 text-h6">
                    Address
                  </p>
                  <p class="mb-0 text-h6 text-lightText ms-auto">
                    {{ selectedChat.address || 'New York, USA' }}
                  </p>
                </div>
              </VListItem>
              <VListItem class="pa-0">
                <div class="d-flex">
                  <p class="mb-0 text-h6">
                    Email
                  </p>
                  <p class="mb-0 text-h6 text-lightText ms-auto">
                    {{ selectedChat.email }}
                  </p>
                </div>
              </VListItem>
              <VListItem class="pa-0">
                <div class="d-flex">
                  <p class="mb-0 text-h6">
                    Phone
                  </p>
                  <p class="mb-0 text-h6 text-lightText ms-auto">
                    {{ selectedChat.phone || '+1 253-418-5940' }}
                  </p>
                </div>
              </VListItem>
              <VListItem class="pa-0">
                <div class="d-flex">
                  <p class="mb-0 text-h6">
                    Last visited
                  </p>
                  <p class="mb-0 text-h6 text-lightText ms-auto">
                    {{ selectedChat.time }}
                  </p>
                </div>
              </VListItem>
            </VList>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>
      <div class="d-flex justify-space-between align-center mt-4 mb-1">
        <h5 class="text-h5 mb-0">
          Notification
        </h5>
        <VSwitch v-model="notification" color="primary" aria-label="switch" class="switchRight" hide-details />
      </div>
      <VDivider />
      <div class="d-flex justify-space-between align-center py-2">
        <h5 class="text-h5 mb-0">
          File type
        </h5>
        <VBtn icon rounded="md" aria-label="menu" variant="text" size="small">
          <SvgSprite name="custom-more-outline" class="text-lightText" style="width: 20px; height: 20px" />
        </VBtn>
      </div>
      <VDivider />
      <VList density="compact" lines="two" aria-label="files list" aria-busy="true">
        <VListItem v-for="(file, i) in attach" :key="i" rounded="sm" color="secondary" class="pa-0">
          <template #prepend>
            <div class="me-3">
              <VAvatar size="40" :color="file.color" variant="tonal" rounded="md">
                <SvgSprite :name="file.icon || ''" :class="`text-${file.color}`" style="width: 20px; height: 20px" />
              </VAvatar>
            </div>
          </template>
          <template #append>
            <VBtn icon size="x-small" aria-label="arrow" variant="text" rounded="md">
              <SvgSprite name="custom-chevron-outline" class="text-lightText" style="width: 16px; height: 16px" />
            </VBtn>
          </template>
          <div class="w-100">
            <h6 class="text-h6 mb-0">
              {{ file.name }}
            </h6>
            <span class="text-h6 text-lightText">{{ file.size }}</span>
          </div>
        </VListItem>
      </VList>
    </div>
  </VNavigationDrawer>

  <!-- Mobile Sidebar Drawer -->
  <VNavigationDrawer v-if="!mdAndUp" v-model="sDrawer" temporary width="300" top>
    <PerfectScrollbar style="height: calc(100vh - 60px)">
      <VCardText class="pa-5">
        <h5 class="text-h5">
          Messages
          <VChip color="secondary" size="x-small" variant="flat">
            {{dummyChats.filter(c => c.unread > 0).length}}
          </VChip>
        </h5>
        <!-- Mobile Chat Listing -->
        <div>
          <div class="py-3 px-5 mt-2">
            <VTextField v-model="searchValue" variant="outlined" persistent-placeholder placeholder="Search Contact"
              hide-details>
              <template #prepend-inner>
                <SvgSprite name="custom-search" class="text-lightText" style="width: 20px; height: 20px" />
              </template>
            </VTextField>
          </div>
          <VList aria-label="chat list" aria-busy="true" border class="px-5">
            <VListItem v-for="chat in filteredChats" :key="chat.id" :value="chat.id" color="secondary"
              class="text-no-wrap chatItem" lines="two" rounded="md" :active="activeChatId === chat.id"
              @click="selectChat(chat.id); sDrawer = false">
              <template #prepend>
                <VAvatar>
                  <img :src="chat.avatar" alt="pro" width="40">
                </VAvatar>
                <SvgSprite class="badg-dot" :name="chat.status === 'away'
                  ? 'custom-away-fill'
                  : chat.status === 'do not disturb'
                    ? 'custom-disturb-fill'
                    : chat.status === 'active'
                      ? 'custom-check-circle-fill'
                      : 'containerBg'
                  " :class="chat.status === 'away'
                    ? 'text-warning'
                    : chat.status === 'do not disturb'
                      ? 'text-secondary'
                      : chat.status === 'active'
                        ? 'text-success'
                        : 'text-containerBg'
                    " style="width: 14px; height: 14px" />
              </template>
              <VListItemTitle class="text-h5 pr-2 mb-1">
                {{ chat.name }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption mt-n1 text-lightText" style="opacity: 1">
                {{ chat.lastMessage }}
              </VListItemSubtitle>
              <template #append>
                <div class="d-flex flex-column text-right">
                  <small class="text-lightText text-caption mb-1">{{ chat.time }}</small>
                  <VBadge v-if="chat.unread > 0" :color="chat.unread > 0 ? 'primary' : ''" :content="chat.unread"
                    inline />
                  <SvgSprite v-else name="custom-circle-check-outline" class="ml-auto text-lightText"
                    style="width: 16px; height: 16px" />
                </div>
              </template>
            </VListItem>
          </VList>
        </div>
        <!-- Mobile User Profile -->
        <div>
          <VList rounded="md" density="comfortable" color="secondary" aria-label="profile list" aria-busy="true"
            elevation="0" class="py-0 px-5">
            <VListItem color="secondary" value="logout" rounded="md">
              <template #prepend>
                <SvgSprite name="custom-logout-1" class="me-2 text-lightText" style="width: 24px; height: 24px" />
              </template>
              <VListItemTitle class="text-h6 text-lightText">
                LogOut
              </VListItemTitle>
            </VListItem>
            <VListItem color="secondary" value="setting" rounded="md">
              <template #prepend>
                <SvgSprite name="custom-setup" class="me-2 text-lightText" style="width: 24px; height: 24px" />
              </template>
              <VListItemTitle class="text-h6 text-lightText">
                Settings
              </VListItemTitle>
            </VListItem>
          </VList>
          <div class="d-flex align-center pa-5 px-10 pb-0">
            <VAvatar class="me-2">
              <img :src="currentUser.avatar" alt="pro" width="40">
            </VAvatar>
            <SvgSprite class="badg-dotDetail" :name="currentUser.status === 'away'
              ? 'custom-away-fill'
              : currentUser.status === 'do not disturb'
                ? 'custom-disturb-fill'
                : currentUser.status === 'active'
                  ? 'custom-check-circle-fill'
                  : 'containerBg'
              " :class="currentUser.status === 'away'
                ? 'text-warning'
                : currentUser.status === 'do not disturb'
                  ? 'text-secondary'
                  : currentUser.status === 'active'
                    ? 'text-success'
                    : 'text-containerBg'
                " style="width: 14px; height: 14px" />
            <div>
              <h5 class="text-h5 mb-0">
                {{ currentUser.name }}
              </h5>
            </div>
            <div class="ms-auto">
              <VMenu location="top" rounded="md">
                <template #activator="{ props }">
                  <VBtn icon v-bind="props" aria-label="dropdown" variant="text" size="x-small" rounded="md">
                    <SvgSprite name="custom-chevron-outline" class="text-lightText" style="width: 16px; height: 16px" />
                  </VBtn>
                </template>
                <VList width="150" rounded="md" density="compact" elevation="24" class="py-0">
                  <VListItem v-for="(item, index) in profileItems" :key="index" :value="index">
                    <template #prepend>
                      <SvgSprite :name="item.icon || ''" :class="`me-1 text-${item.color}`"
                        style="width: 16px; height: 16px" />
                    </template>
                    <VListItemTitle class="text-h6" @click="currentUser.status = item.title">
                      {{ item.title }}
                    </VListItemTitle>
                  </VListItem>
                </VList>
              </VMenu>
            </div>
          </div>
        </div>
      </VCardText>
    </PerfectScrollbar>
  </VNavigationDrawer>
</template>

<script setup lang="ts">
import { ref, computed, shallowRef } from 'vue'
import { useDisplay } from 'vuetify'
import avatar1 from '@images/users/avatar-1.png'
import avatar2 from '@images/users/avatar-2.png'
import avatar3 from '@images/users/avatar-3.png'
import avatar4 from '@images/users/avatar-4.png'
import avatar5 from '@images/users/avatar-5.png'

// Display
const { mdAndUp } = useDisplay()

// State
const toggleSide = ref(false)
const sDrawer = ref(false)
const infodrawer = ref(false)
const searchValue = ref('')
const newMessage = ref('')
const notification = ref(true)
const panel1 = ref([0])
const activeChatId = ref(1)

// Current user
const currentUser = ref({
  name: 'John Doe',
  avatar: avatar1,
  status: 'active'
})

// Dummy data - Attachments
const attach = shallowRef([
  {
    color: 'success',
    icon: 'custom-file-outline-2',
    name: 'Document',
    size: '123 files, 193MB',
  },
  {
    color: 'warning',
    icon: 'custom-picture-outline',
    name: 'Photos',
    size: '53 files, 321MB',
  },
  {
    color: 'primary',
    icon: 'custom-document-outline-1',
    name: 'Other',
    size: '49 files, 193MB',
  },
])

// Menu items
const menuItems = shallowRef([
  { title: 'Archive', icon: 'custom-document-2' },
  { title: 'Muted', icon: 'custom-speaker-outline' },
  { title: 'Delete', icon: 'custom-trash' },
])

const replyItems = shallowRef([
  { title: 'Reply', icon: 'custom-reply-outline' },
  { title: 'Forward', icon: 'custom-play-outline' },
  { title: 'Copy', icon: 'custom-copy' },
  { title: 'Delete', icon: 'custom-trash' },
])

const profileItems = shallowRef([
  { title: 'active', icon: 'custom-check-circle-fill', color: 'success' },
  { title: 'away', icon: 'custom-away-fill', color: 'warning' },
  { title: 'do not disturb', icon: 'custom-disturb-fill', color: 'secondary' },
])

// Dummy chats data
const dummyChats = ref([
  {
    id: 1,
    name: 'John Doe',
    avatar: avatar2,
    status: 'active',
    lastMessage: 'Hey, how are you? ',
    time: '12:30 PM',
    unread: 2,
    email: 'john.doe@example.com',
    phone: '+1 234-567-8901',
    messages: [
      { fromMe: false, text: 'Hey, how are you?', time: '12:30 PM' },
      { fromMe: true, text: 'I\'m good, thanks! How about you?', time: '12:32 PM' },
      { fromMe: false, text: 'Doing great! Want to grab lunch later?', time: '12:33 PM' },
    ]
  },
  {
    id: 2,
    name: 'Sarah Smith',
    avatar: avatar3,
    status: 'away',
    lastMessage: 'See you tomorrow!',
    time: '11:15 AM',
    unread: 0,
    email: 'sarah.smith@example.com',
    phone: '+1 345-678-9012',
    messages: [
      { fromMe: true, text: 'Are we still meeting at 3?', time: '10:00 AM' },
      { fromMe: false, text: 'Yes, see you then!', time: '10:05 AM' },
      { fromMe: true, text: 'Great! See you tomorrow!', time: '10:06 AM' },
    ]
  },
  {
    id: 3,
    name: 'Mike Johnson',
    avatar: avatar4,
    status: 'do not disturb',
    lastMessage: 'Call me when you\'re free',
    time: 'Yesterday',
    unread: 3,
    email: 'mike.j@example.com',
    phone: '+1 456-789-0123',
    messages: [
      { fromMe: false, text: 'Call me when you\'re free', time: 'Yesterday' },
      { fromMe: true, text: 'Will do! Is it urgent?', time: 'Yesterday' },
      { fromMe: false, text: 'Not really, just want to discuss the project', time: 'Yesterday' },
    ]
  },
  {
    id: 4,
    name: 'Emily Brown',
    avatar: avatar5,
    status: 'active',
    lastMessage: 'Thanks for your help!',
    time: 'Yesterday',
    unread: 0,
    email: 'emily.b@example.com',
    phone: '+1 567-890-1234',
    messages: [
      { fromMe: true, text: 'Do you need any help with the presentation?', time: 'Yesterday' },
      { fromMe: false, text: 'Yes, could you review it?', time: 'Yesterday' },
      { fromMe: true, text: 'Sure, send it over', time: 'Yesterday' },
      { fromMe: false, text: 'Thanks for your help!', time: 'Yesterday' },
    ]
  },
])

// Computed
const filteredChats = computed(() => {
  return dummyChats.value.filter(chat =>
    chat.name.toLowerCase().includes(searchValue.value.toLowerCase())
  )
})

const selectedChat = computed(() => {
  return dummyChats.value.find(chat => chat.id === activeChatId.value)
})

// Methods
function selectChat(id: number) {
  activeChatId.value = id
  // Mark as read when selected
  const chat = dummyChats.value.find(c => c.id === id)
  if (chat) chat.unread = 0
}

function sendMessage() {
  if (!newMessage.value.trim() || !selectedChat.value) return

  // Add message to chat
  const now = new Date()
  const timeString = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })

  selectedChat.value.messages.push({
    fromMe: true,
    text: newMessage.value,
    time: timeString
  })

  // Update last message in chat list
  const chatInList = dummyChats.value.find(c => c.id === activeChatId.value)
  if (chatInList) {
    chatInList.lastMessage = newMessage.value
    chatInList.time = 'Just now'
  }

  newMessage.value = ''
}
</script>

<style lang="scss">
.br-0 {
  @media (min-width: 960px) {
    border-right: none;
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;

    [dir="rtl"] & {
      border-left: none;
      border-right: 1px solid rgb(var(--v-theme-borderLight));
      border-top-right-radius: 8px !important;
      border-bottom-right-radius: 8px !important;
      border-top-left-radius: 0 !important;
      border-bottom-left-radius: 0 !important;
    }
  }
}

.bl-0 {
  @media (min-width: 960px) {
    border-top-left-radius: 0 !important;
    border-bottom-left-radius: 0 !important;

    [dir="rtl"] & {
      border-top-left-radius: 8px !important;
      border-bottom-left-radius: 8px !important;
      border-top-right-radius: 0 !important;
      border-bottom-right-radius: 0 !important;
    }
  }
}

.custom-main {
  margin: 0;
}

.chatSidebar {
  max-width: 319px;

  .v-list-item__prepend {
    >.v-avatar {
      ~.v-list-item__spacer {
        width: 0;
      }
    }
  }
}

.customHeight {
  min-height: calc(100vh - 300px);
}

.badg-dot {
  position: relative;
  top: -14px;
  left: -11px;
  background-color: rgb(var(--v-theme-surface));
  border-radius: 100%;

  [dir="rtl"] & {
    left: unset;
    right: -11px;
  }
}

.badg-dotDetail {
  left: -16px;
  position: relative;
  top: -16px;

  [dir="rtl"] & {
    left: unset;
    right: -16px;
  }
}

.detail-badg-dot {
  position: absolute;
  top: 0;
  left: 28px;
  background-color: rgb(var(--v-theme-surface));
  border-radius: 100%;

  [dir="rtl"] & {
    left: unset;
    right: 28px;
  }
}

.userReply {
  flex-flow: row-reverse;

  .detail-badg-dot {
    right: 0;
    left: unset;

    [dir="rtl"] & {
      left: 0;
      right: unset;
    }
  }
}

.badg-Detail {
  left: -12px;
  position: relative;
  top: -15px;
  background-color: rgb(var(--v-theme-surface));
  border-radius: 100%;

  [dir="rtl"] & {
    left: unset;
    right: -12px;
  }
}

.chatItem {
  padding: 16px !important;
}

.accordionWithoutBorder {
  .v-expansion-panel {
    border: none;

    .v-expansion-panel-title {
      border-bottom: 1px solid rgb(var(--v-theme-borderLight));
      min-height: unset;

      &:hover {
        >.v-expansion-panel-title__overlay {
          opacity: 0;
        }
      }
    }

    &.v-expansion-panel--active {
      .v-expansion-panel-title--active {
        .v-expansion-panel-title__overlay {
          background: transparent;
        }
      }
    }

    .v-expansion-panel-text__wrapper {
      border-top: none;
      padding: 0;
      padding-top: 15px;
    }
  }
}
</style>