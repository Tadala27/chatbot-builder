<script setup lang="ts">
import { ref, reactive, watch, onMounted } from 'vue'
import axios from "axios"

import moment from 'moment'

const props = defineProps<{
    shareholder: any
}>()

const emit = defineEmits<{
    (e: 'profile-updated'): void
}>()

const financialForm = ref()
const saving = ref(false)

// Form data
const form = reactive({
    bank_name: '',
    bank_account_number: '',
    bank_branch: '',
    share_units: 0,
    share_value: 0,
    registration_fee_paid: 0
})

// Snackbar
const snackbar = reactive({
    show: false,
    message: '',
    color: 'success'
})

// Validation rules
const rules = {
    number: (v: any) => {
        return v === '' || v === null || !isNaN(v) || 'Must be a valid number'
    }
}

// Load shareholder data
const loadFinancialData = () => {
    if (props.shareholder) {
        form.bank_name = props.shareholder.bank_name || ''
        form.bank_account_number = props.shareholder.bank_account_number || ''
        form.bank_branch = props.shareholder.bank_branch || ''
        form.share_units = props.shareholder.share_units || 0
        form.share_value = props.shareholder.share_value || 0
        form.registration_fee_paid = props.shareholder.registration_fee_paid || 0
    }
}

// Save financial info
const saveFinancialInfo = async () => {
    const { valid } = await financialForm.value.validate()
    if (!valid) return

    saving.value = true
    try {
        await axios.put(`/api/shareholders/${props.shareholder.id}`, form)
        showSnackbar('Financial information updated successfully', 'success')
        emit('profile-updated')
    } catch (error: any) {
        showSnackbar(error.response?.data?.message || 'Failed to update information', 'error')
    } finally {
        saving.value = false
    }
}

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'MWK',
        minimumFractionDigits: 0,
    }).format(amount || 0).replace('MWK', 'MWK ')
}

const formatDate = (date: string) => {
    return date ? moment(date).format('DD MMM YYYY') : 'N/A'
}

const showSnackbar = (message: string, color: string = 'success') => {
    snackbar.message = message
    snackbar.color = color
    snackbar.show = true
}

// Lifecycle
onMounted(() => {
    loadFinancialData()
})

// Watch for prop changes
watch(() => props.shareholder, () => {
    loadFinancialData()
}, { deep: true })
</script>

<template>
    <div>
        <!-- Banking Information -->
        <div class="px-5 py-6">
            <h5 class="text-h5 mb-0">Banking Information</h5>
        </div>

        <VForm ref="financialForm" @submit.prevent="saveFinancialInfo">
            <VRow>
                <VCol cols="12" md="4">
                    <VLabel class="mb-2">Bank Name</VLabel>
                    <VTextField v-model="form.bank_name" placeholder="Enter bank name" density="comfortable"
                        variant="outlined" hide-details />
                </VCol>
                <VCol cols="12" md="4">
                    <VLabel class="mb-2">Account Number</VLabel>
                    <VTextField v-model="form.bank_account_number" placeholder="Enter account number"
                        density="comfortable" variant="outlined" hide-details />
                </VCol>
                <VCol cols="12" md="4">
                    <VLabel class="mb-2">Bank Branch</VLabel>
                    <VTextField v-model="form.bank_branch" placeholder="Enter branch" density="comfortable"
                        variant="outlined" hide-details />
                </VCol>
            </VRow>

            <!-- Share Information -->
            <div class="px-0 py-6">
                <h5 class="text-h5 mb-0">Share Information</h5>
                <p class="text-caption text-medium-emphasis">Update share units and values</p>
            </div>

            <VRow>
                <VCol cols="12" md="4">
                    <VLabel class="mb-2">Share Units</VLabel>
                    <VTextField v-model.number="form.share_units" type="number" placeholder="0" density="comfortable"
                        variant="outlined" :rules="[rules.number]" />
                </VCol>
                <VCol cols="12" md="4">
                    <VLabel class="mb-2">Share Value (MWK)</VLabel>
                    <VTextField v-model.number="form.share_value" type="number" placeholder="0" density="comfortable"
                        variant="outlined" :rules="[rules.number]" />
                </VCol>
                <VCol cols="12" md="4">
                    <VLabel class="mb-2">Registration Fee Paid (MWK)</VLabel>
                    <VTextField v-model.number="form.registration_fee_paid" type="number" placeholder="0"
                        density="comfortable" variant="outlined" :rules="[rules.number]" />
                </VCol>
            </VRow>

            <VBtn type="submit" color="primary" rounded="md" variant="flat" class="mt-5" :loading="saving">
                Save Changes
            </VBtn>
        </VForm>

        <!-- Financial Summary (Read-Only) -->
        <div class="px-0 py-6 mt-8">
            <h5 class="text-h5 mb-0">Financial Summary</h5>
            <p class="text-caption text-medium-emphasis">Overview of contributions and payments</p>
        </div>

        <VRow>
            <VCol cols="12" md="6">
                <VCard variant="outlined" rounded="lg">
                    <VCardText>
                        <div class="d-flex align-center justify-space-between mb-4">
                            <div>
                                <span class="text-caption text-lightText">Total Contributions</span>
                                <h4 class="text-h4 text-success">
                                    {{ formatCurrency(shareholder?.totals?.total_contributions || 0) }}
                                </h4>
                            </div>
                            <VAvatar color="success" variant="tonal" size="48">
                                <VIcon>$currencyUsd</VIcon>
                            </VAvatar>
                        </div>
                        <VDivider class="my-3" />
                        <div class="d-flex justify-space-between">
                            <span class="text-body-2 text-lightText">Share Contributions</span>
                            <span class="text-body-2 font-weight-medium">
                                {{ formatCurrency(shareholder?.totals?.total_share_contributions || 0) }}
                            </span>
                        </div>
                        <div class="d-flex justify-space-between mt-2">
                            <span class="text-body-2 text-lightText">Capital Contributions</span>
                            <span class="text-body-2 font-weight-medium">
                                {{ formatCurrency(shareholder?.totals?.total_capital_contributions || 0) }}
                            </span>
                        </div>
                    </VCardText>
                </VCard>
            </VCol>

            <VCol cols="12" md="6">
                <VCard variant="outlined" rounded="lg">
                    <VCardText>
                        <div class="d-flex align-center justify-space-between mb-4">
                            <div>
                                <span class="text-caption text-lightText">Subscription Fees</span>
                                <h4 class="text-h4 text-primary">
                                    {{ formatCurrency(shareholder?.totals?.total_subscription_fees || 0) }}
                                </h4>
                            </div>
                            <VAvatar color="primary" variant="tonal" size="48">
                                <VIcon>$cash</VIcon>
                            </VAvatar>
                        </div>
                        <VDivider class="my-3" />
                        <div class="d-flex justify-space-between">
                            <span class="text-body-2 text-lightText">Monthly Fees</span>
                            <span class="text-body-2 font-weight-medium">
                                {{ formatCurrency(shareholder?.totals?.total_monthly_fees || 0) }}
                            </span>
                        </div>
                        <div class="d-flex justify-space-between mt-2">
                            <span class="text-body-2 text-lightText">Annual Fees</span>
                            <span class="text-body-2 font-weight-medium">
                                {{ formatCurrency(shareholder?.totals?.total_annual_fees || 0) }}
                            </span>
                        </div>
                    </VCardText>
                </VCard>
            </VCol>
        </VRow>

        <!-- Recent Payments -->
        <div class="px-0 py-6 mt-4">
            <h5 class="text-h5 mb-0">Recent Payments</h5>
        </div>

        <VTable v-if="shareholder?.payments?.length > 0" density="comfortable" class="bordered-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="payment in shareholder.payments.slice(0, 5)" :key="payment.id">
                    <td>{{ formatDate(payment.transaction_date) }}</td>
                    <td>{{ payment.payment_type }}</td>
                    <td class="text-success font-weight-bold">{{ formatCurrency(payment.amount) }}</td>
                    <td>{{ payment.payment_method }}</td>
                    <td>
                        <VChip :color="payment.is_verified ? 'success' : 'warning'" size="small" variant="tonal">
                            {{ payment.is_verified ? 'Verified' : 'Pending' }}
                        </VChip>
                    </td>
                </tr>
            </tbody>
        </VTable>

        <VAlert v-else type="info" variant="tonal" class="mt-2">
            No payment records found
        </VAlert>

        <!-- Recent Invoices -->
        <div class="px-0 py-6 mt-4">
            <h5 class="text-h5 mb-0">Recent Invoices</h5>
        </div>

        <VTable v-if="shareholder?.invoices?.length > 0" density="comfortable" class="bordered-table">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Balance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="invoice in shareholder.invoices.slice(0, 5)" :key="invoice.id">
                    <td>{{ invoice.invoice_number }}</td>
                    <td>{{ formatDate(invoice.invoice_date) }}</td>
                    <td>{{ invoice.invoice_type }}</td>
                    <td>{{ formatCurrency(invoice.amount) }}</td>
                    <td :class="invoice.balance > 0 ? 'text-error' : 'text-success'">
                        {{ formatCurrency(invoice.balance) }}
                    </td>
                    <td>
                        <VChip
                            :color="invoice.status === 'Paid' ? 'success' : invoice.status === 'Overdue' ? 'error' : 'warning'"
                            size="small" variant="tonal">
                            {{ invoice.status }}
                        </VChip>
                    </td>
                </tr>
            </tbody>
        </VTable>

        <VAlert v-else type="info" variant="tonal" class="mt-2">
            No invoice records found
        </VAlert>

        <!-- Snackbar -->
        <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3000" location="top right">
            {{ snackbar.message }}
            <template #actions>
                <VBtn variant="text" @click="snackbar.show = false">Close</VBtn>
            </template>
        </VSnackbar>
    </div>
</template>

<style scoped>
.text-lightText {
    color: rgba(var(--v-theme-on-surface), 0.6);
}

.bordered-table {
    border: 1px solid var(--v-theme-border);
}

.bordered-table thead {
    background-color: rgb(245, 245, 245);
}
</style>