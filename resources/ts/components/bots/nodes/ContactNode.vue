<script setup lang="ts">
import type { FlowNode } from "../types";

const props = defineProps<{
  node: FlowNode;
  availableVariables: string[];
  nodeOptions: any[];
}>();

// Initialize contactData if not set
if (!props.node.contactData) {
  props.node.contactData = {
    name: {
      formatted_name: "",
      first_name: "",
      last_name: "",
    },
    phones: [{ phone: "", type: "Mobile", wa_id: "" }],
    emails: [],
    addresses: [],
    urls: [],
    org: {
      company: "",
      department: "",
      title: "",
    },
  };
}

function addPhone() {
  if (!props.node.contactData!.phones) {
    props.node.contactData!.phones = [];
  }
  props.node.contactData!.phones.push({
    phone: "",
    type: "Mobile",
    wa_id: "",
  });
}

function removePhone(index: number) {
  props.node.contactData!.phones?.splice(index, 1);
}

function addEmail() {
  if (!props.node.contactData!.emails) {
    props.node.contactData!.emails = [];
  }
  props.node.contactData!.emails.push({
    email: "",
    type: "Work",
  });
}

function removeEmail(index: number) {
  props.node.contactData!.emails?.splice(index, 1);
}
</script>

<template>
  <div>
    <v-alert type="info" variant="tonal" density="compact" class="mb-3">
      <div class="text-caption">
        Send a contact card (vCard) that users can save to their contacts
      </div>
    </v-alert>

    <!-- Name Section -->
    <div class="text-subtitle-2 font-weight-bold mb-2">Contact Name</div>
    <v-text-field
      v-model="node.contactData.name.formatted_name"
      label="Full Name *"
      placeholder="John Doe"
      variant="outlined"
      density="compact"
      hint="How the name appears in WhatsApp"
      persistent-hint
    >
      <template #prepend-inner>
        <v-icon icon="$account" size="small" />
      </template>
    </v-text-field>

    <v-row class="mt-2">
      <v-col cols="6">
        <v-text-field
          v-model="node.contactData.name.first_name"
          label="First Name"
          placeholder="John"
          variant="outlined"
          density="compact"
          hide-details
        />
      </v-col>
      <v-col cols="6">
        <v-text-field
          v-model="node.contactData.name.last_name"
          label="Last Name"
          placeholder="Doe"
          variant="outlined"
          density="compact"
          hide-details
        />
      </v-col>
    </v-row>

    <v-divider class="my-4" />

    <!-- Phone Numbers -->
    <div class="d-flex align-center justify-space-between mb-2">
      <div class="text-subtitle-2 font-weight-bold">Phone Numbers</div>
      <v-btn
        size="x-small"
        variant="outlined"
        prepend-icon="$plus"
        @click="addPhone"
      >
        Add Phone
      </v-btn>
    </div>

    <v-card
      v-for="(phone, pIdx) in node.contactData.phones"
      :key="pIdx"
      variant="outlined"
      class="mb-2"
    >
      <v-card-text class="pa-2">
        <div class="d-flex gap-2 align-center">
          <v-text-field
            v-model="phone.phone"
            label="Phone Number"
            placeholder="+1234567890"
            variant="outlined"
            density="compact"
            hide-details
            class="flex-grow-1"
          >
            <template #prepend-inner>
              <v-icon icon="$phone" size="small" />
            </template>
          </v-text-field>
          <v-select
            v-model="phone.type"
            :items="['Mobile', 'Work', 'Home', 'Landline']"
            variant="outlined"
            density="compact"
            hide-details
            style="max-width: 120px"
          />
          <v-btn
            icon="$trashCan"
            size="x-small"
            variant="text"
            color="error"
            @click="removePhone(pIdx)"
          />
        </div>
        <v-text-field
          v-if="phone.type === 'Mobile'"
          v-model="phone.wa_id"
          label="WhatsApp ID (optional)"
          placeholder="1234567890"
          variant="outlined"
          density="compact"
          class="mt-2"
          hint="Phone number without + or country code"
          persistent-hint
        />
      </v-card-text>
    </v-card>

    <v-divider class="my-4" />

    <!-- Emails (Optional) -->
    <div class="d-flex align-center justify-space-between mb-2">
      <div class="text-subtitle-2 font-weight-bold">Emails (Optional)</div>
      <v-btn
        size="x-small"
        variant="outlined"
        prepend-icon="$plus"
        @click="addEmail"
      >
        Add Email
      </v-btn>
    </div>

    <v-card
      v-for="(email, eIdx) in node.contactData.emails"
      :key="eIdx"
      variant="outlined"
      class="mb-2"
    >
      <v-card-text class="pa-2">
        <div class="d-flex gap-2 align-center">
          <v-text-field
            v-model="email.email"
            label="Email Address"
            placeholder="john@example.com"
            variant="outlined"
            density="compact"
            hide-details
            class="flex-grow-1"
          >
            <template #prepend-inner>
              <v-icon icon="$email" size="small" />
            </template>
          </v-text-field>
          <v-select
            v-model="email.type"
            :items="['Work', 'Personal', 'Other']"
            variant="outlined"
            density="compact"
            hide-details
            style="max-width: 120px"
          />
          <v-btn
            icon="$trashCan"
            size="x-small"
            variant="text"
            color="error"
            @click="removeEmail(eIdx)"
          />
        </div>
      </v-card-text>
    </v-card>

    <v-divider class="my-4" />

    <!-- Organization (Optional) -->
    <v-expansion-panels variant="accordion" class="mb-3">
      <v-expansion-panel>
        <v-expansion-panel-title>
          <v-icon icon="$domain" size="small" class="mr-2" />
          Organization Info (Optional)
        </v-expansion-panel-title>
        <v-expansion-panel-text>
          <v-text-field
            v-model="node.contactData.org.company"
            label="Company"
            placeholder="OneNICO"
            variant="outlined"
            density="compact"
            class="mb-2"
          />
          <v-text-field
            v-model="node.contactData.org.department"
            label="Department"
            placeholder="Customer Support"
            variant="outlined"
            density="compact"
            class="mb-2"
          />
          <v-text-field
            v-model="node.contactData.org.title"
            label="Job Title"
            placeholder="Support Manager"
            variant="outlined"
            density="compact"
          />
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>

    <v-select
      v-model="node.goTo"
      label="Then go to"
      :items="nodeOptions.filter((o) => o.value !== node.id)"
      variant="outlined"
      density="compact"
    >
      <template #prepend-inner>
        <v-icon icon="$navigationVariant" size="small" />
      </template>
    </v-select>
  </div>
</template>

<style scoped>
.gap-2 {
  gap: 8px;
}
</style>