<script setup lang="ts">
import { computed } from 'vue'
import moment from 'moment'

const props = defineProps<{
    shareholder: any
    isOwnProfile?: boolean
}>()

const emit = defineEmits<{
    (e: 'view-payments'): void
    (e: 'view-invoices'): void
    (e: 'change-status', status: string): void
}>()

const fullName = computed(() => {
    if (!props.shareholder?.user) return ''
    return `${props.shareholder.user.first_name} ${props.shareholder.user.last_name}`
})

const avatarUrl = computed(() => props.shareholder?.user?.avatar || '')

const memberStats = computed(() => [
    {
        label: 'Share Units',
        value: props.shareholder?.share_units || 0
    },
    {
        label: 'Payments',
        value: props.shareholder?.payments?.length || 0
    }
])

const contactInfo = computed(() => [
    { text: props.shareholder?.user?.email || '', icon: 'custom-mail-outline', color: 'dark', showTooltip: true },
    { text: props.shareholder?.user?.phone || 'Not provided', icon: 'custom-phone-outline-1', color: 'dark' },
    { text: props.shareholder?.member_number || 'N/A', icon: 'custom-user-outline', color: 'primary' },
    { text: props.shareholder?.district || 'Not specified', icon: 'custom-goal-outline', color: 'dark' },
    { text: props.shareholder?.date_of_birth ? moment(props.shareholder.date_of_birth).format('DD MMM YYYY') : 'Not provided', icon: 'custom-calendar-outline', color: 'dark' },
    { text: props.shareholder?.gender || 'Not specified', icon: 'custom-profile-2user-outline', color: 'dark' },
    { text: props.shareholder?.country || 'Malawi', icon: 'custom-airplane', color: 'dark' },
    { text: props.shareholder?.physical_address || 'Not provided', icon: 'custom-navigation-outline', color: 'dark', showTooltip: true }
])

const statusColor = computed(() => {
    const colors: Record<string, string> = {
        'Active': 'success',
        'Inactive': 'warning',
        'Suspended': 'error',
        'Deceased': 'secondary'
    }
    return colors[props.shareholder?.status] || 'secondary'
})

const beneficiaries = computed(() => props.shareholder?.beneficiaries || [])

// Show quick actions based on context
const showStatusActions = computed(() => !props.isOwnProfile && props.shareholder?.status !== 'Deceased')

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'MWK',
        minimumFractionDigits: 0,
    }).format(amount || 0).replace('MWK', 'MWK ')
}

const formatDate = (date: string | null) => {
    return date ? moment(date).format('DD MMM YYYY') : 'N/A'
}

const getInitials = (firstName: string, lastName: string) => {
    return `${firstName?.[0] || ''}${lastName?.[0] || ''}`.toUpperCase()
}
</script>

<template>
    <VRow>
        <!-- Left Sidebar -->
        <VCol cols="12" xl="3" md="4">
            <VCard variant="outlined" rounded="lg">
                <VCardText>
                    <!-- Header with Status -->
                    <div class="text-center pb-4">
                        <div class="text-end text-capitalize">
                            <VChip :color="statusColor" size="small" rounded="sm" class="mt-2" variant="tonal">
                                {{ shareholder?.status || 'Unknown' }}
                            </VChip>
                        </div>

                        <!-- Avatar -->
                        <VAvatar v-if="avatarUrl" size="80" color="lightprimary" class="mb-3">
                            <img :src="avatarUrl" :alt="fullName" width="80" class="v-avatar">
                        </VAvatar>
                        <VAvatar v-else variant="tonal" size="80" color="primary" class="mb-3">
                            <span class="text-h4">{{ getInitials(shareholder?.user?.first_name,
                                shareholder?.user?.last_name) }}</span>
                        </VAvatar>

                        <h5 class="text-h5 mb-1">{{ fullName }}</h5>
                        <span class="text-h6 text-lightText">{{ shareholder?.member_number }}</span>

                    </div>

                    <VDivider />

                    <!-- Stats -->
                    <div class="my-4 d-flex align-center ga-2">
                        <div v-for="(stat, i) in memberStats" :key="i" class="text-center w-100">
                            <h5 class="text-h5 mb-1">{{ stat.value }}</h5>
                            <span class="text-h6 text-lightText">{{ stat.label }}</span>
                        </div>
                    </div>

                    <VDivider />

                    <!-- Contact Info -->
                    <VList density="compact" class="pb-0">
                        <VListItem v-for="(item, i) in contactInfo" :key="i" class="py-0 px-0">
                            <template #prepend>
                                <SvgSprite :name="item.icon" class="text-lightText me-2"
                                    style="width: 18px; height: 18px" />
                            </template>
                            <VListItemTitle :class="`text-h6 text-end text-${item.color}`">
                                {{ item.text }}
                                <VTooltip v-if="item.showTooltip" activator="parent" location="top">
                                    {{ item.text }}
                                </VTooltip>
                            </VListItemTitle>
                        </VListItem>
                    </VList>

                    <VDivider class="my-4" />

                    <!-- Quick Actions -->
                    <div class="d-flex flex-row ga-2">
                        <VBtn color="primary" variant="outlined" @click="emit('view-payments')" class="me-2">
                            <VIcon start>$currencyUsd</VIcon>
                            {{ isOwnProfile ? 'My Payments' : 'View Payments' }}
                        </VBtn>
                        <VBtn color="primary" variant="outlined" @click="emit('view-invoices')">
                            <VIcon start>$fileDocument</VIcon>
                            {{ isOwnProfile ? 'My Invoices' : 'View Invoices' }}
                        </VBtn>
                    </div>
                </VCardText>
            </VCard>

            <VCard variant="outlined" class="mt-2" v-if="shareholder?.next_of_kin_name" rounded="lg">
                <VCardText>
                    <div>
                        <h5 class="text-h5 mb-0">Next of Kin</h5>
                    </div>
                    <VDivider class="my-2" />

                    <VList density="compact" class="pb-0">
                        <VListItem class="py-0 px-0">
                            <template #prepend>
                                <span class="text-h6 text-lightText">Name</span>
                            </template>
                            <VListItemTitle class="text-h6 text-end">
                                {{ shareholder?.next_of_kin_name }}
                            </VListItemTitle>
                        </VListItem>
                        <VListItem class="py-0 px-0">
                            <template #prepend>
                                <span class="text-h6 text-lightText">Relationship</span>
                            </template>
                            <VListItemTitle class="text-h6 text-end">
                                {{ shareholder?.next_of_kin_relationship || 'N/A' }}
                            </VListItemTitle>
                        </VListItem>
                        <VListItem class="py-0 px-0">
                            <template #prepend>
                                <span class="text-h6 text-lightText">Phone</span>
                            </template>
                            <VListItemTitle class="text-h6 text-end">
                                {{ shareholder?.next_of_kin_phone || 'N/A' }}
                            </VListItemTitle>
                        </VListItem>

                    </VList>


                </VCardText>
            </VCard>

            <VCard variant="outlined" class="mt-4" rounded="lg" v-if="showStatusActions">
                <VCardText>
                    <h5 class="text-subtitle-1 mb-3">Status Actions</h5>

                    <VRow dense>
                        <VCol v-if="shareholder?.status !== 'Active'" cols="12" md="6">
                            <VBtn block color="success" variant="tonal" size="small" prepend-icon="$checkCircle"
                                @click="emit('change-status', 'Active')">
                                Activate
                            </VBtn>
                        </VCol>

                        <VCol v-if="shareholder?.status !== 'Inactive'" cols="12" md="6">
                            <VBtn block color="warning" variant="tonal" size="small" prepend-icon="$pause"
                                @click="emit('change-status', 'Inactive')">
                                Deactivate
                            </VBtn>
                        </VCol>

                        <VCol v-if="shareholder?.status !== 'Suspended'" cols="12" md="6">
                            <VBtn block color="error" variant="tonal" size="small" prepend-icon="$cancel"
                                @click="emit('change-status', 'Suspended')">
                                Suspend
                            </VBtn>
                        </VCol>
                    </VRow>
                </VCardText>
            </VCard>



        </VCol>

        <!-- Right Content -->
        <VCol cols="12" xl="9" md="8">
            <!-- Membership Information -->
            <VCard variant="outlined" rounded="lg">
                <VList>
                    <VListItem>
                        <template #title>
                            <h5 class="text-subtitle-1 mb-0">Membership Information</h5>
                        </template>
                    </VListItem>
                </VList>
                <VDivider />
                <VCardText>
                    <VRow>
                        <VCol cols="12" md="6">
                            <div class="mb-4">
                                <span class="text-caption text-lightText">Member Number</span>
                                <h6 class="text-h6">{{ shareholder?.member_number || 'N/A' }}</h6>
                            </div>
                        </VCol>
                        <VCol cols="12" md="6">
                            <div class="mb-4">
                                <span class="text-caption text-lightText">Date Joined</span>
                                <h6 class="text-h6">{{ formatDate(shareholder?.date_joined) }}</h6>
                            </div>
                        </VCol>
                        <VCol cols="12" md="6">
                            <div class="mb-4">
                                <span class="text-caption text-lightText">Share Units</span>
                                <h6 class="text-h5 text-primary">{{ shareholder?.share_units || 0 }}</h6>
                            </div>
                        </VCol>
                        <VCol cols="12" md="6">
                            <div class="mb-4">
                                <span class="text-caption text-lightText">Share Value</span>
                                <h6 class="text-h5 text-success">{{ formatCurrency(shareholder?.share_value || 0) }}
                                </h6>
                            </div>
                        </VCol>
                        <VCol cols="12" md="6">
                            <div class="mb-4">
                                <span class="text-caption text-lightText">Total Contributions</span>
                                <h6 class="text-h5 text-success">{{
                                    formatCurrency(shareholder?.totals?.total_contributions ||
                                        0) }}</h6>
                            </div>
                        </VCol>
                        <VCol cols="12" md="6">
                            <div class="mb-4">
                                <span class="text-caption text-lightText">Registration Fee</span>
                                <h6 class="text-h5">{{ formatCurrency(shareholder?.registration_fee_paid || 0) }}</h6>
                            </div>
                        </VCol>
                        <VCol cols="12" md="6" v-if="shareholder?.date_left">
                            <div class="mb-4">
                                <span class="text-caption text-lightText">Date Left</span>
                                <h6 class="text-h6">{{ formatDate(shareholder?.date_left) }}</h6>
                            </div>
                        </VCol>
                        <VCol cols="12" v-if="shareholder?.status_notes && !isOwnProfile">
                            <div class="mb-4">
                                <span class="text-caption text-lightText">Status Notes</span>
                                <h6 class="text-h6">{{ shareholder?.status_notes }}</h6>
                            </div>
                        </VCol>
                    </VRow>
                </VCardText>
            </VCard>

            <!-- Beneficiaries -->
            <VCard variant="outlined" class="mt-4" rounded="lg" v-if="beneficiaries.length > 0">
                <VList>
                    <VListItem>
                        <template #title>
                            <h5 class="text-subtitle-1 mb-0">Beneficiaries</h5>
                        </template>
                    </VListItem>
                </VList>
                <VDivider />
                <VCardText>
                    <VRow v-for="(beneficiary, i) in beneficiaries" :key="i" class="">
                        <VCol cols="12">
                            <VCard variant="tonal" color="primary">
                                <VCardText>
                                    <VRow>
                                        <VCol cols="12" md="4">
                                            <span class="text-caption text-lightText">Name</span>
                                            <h6 class="text-h6">{{ beneficiary.first_name }} {{ beneficiary.last_name }}
                                            </h6>
                                        </VCol>
                                        <VCol cols="12" md="4">
                                            <span class="text-caption text-lightText">Relationship</span>
                                            <h6 class="text-h6">{{ beneficiary.relationship || 'N/A' }}</h6>
                                        </VCol>
                                        <VCol cols="12" md="4">
                                            <span class="text-caption text-lightText">Shareholding %</span>
                                            <h6 class="text-h6">{{ beneficiary.shareholding_percentage }}%</h6>
                                        </VCol>
                                        <VCol cols="12" md="4" v-if="beneficiary.phone">
                                            <span class="text-caption text-lightText">Phone</span>
                                            <h6 class="text-h6">{{ beneficiary.phone }}</h6>
                                        </VCol>
                                        <VCol cols="12" md="4" v-if="beneficiary.email">
                                            <span class="text-caption text-lightText">Email</span>
                                            <h6 class="text-h6">{{ beneficiary.email }}</h6>
                                        </VCol>
                                    </VRow>
                                </VCardText>
                            </VCard>
                        </VCol>
                    </VRow>
                </VCardText>
            </VCard>
        </VCol>
    </VRow>
</template>

<style scoped>
.text-lightText {
    color: rgba(var(--v-theme-on-surface), 0.6);
}
</style>