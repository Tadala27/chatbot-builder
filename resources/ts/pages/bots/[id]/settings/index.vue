<template>
  <div class="flow-library">
    <VCard variant="flat" rounded="lg">
      <VCard variant="outlined" rounded="lg">
        <VCardText class="px-0 pb-0">
          <VTabs v-model="activeTab" color="primary" class="mx-6" grow>
            <VTab value="tab-variables">
              <SvgSprite
                name="custom-level-1"
                class="v-icon--start"
                style="width: 18px; height: 18px"
              />
              Custom Variables
            </VTab>
            <VTab value="tab-functions">
              <SvgSprite
                name="custom-level"
                class="v-icon--start"
                style="width: 18px; height: 18px"
              />
              Functions
            </VTab>
            <VTab value="tab-apis">
              <SvgSprite
                name="custom-code"
                class="v-icon--start"
                style="width: 18px; height: 18px"
              />
              API Integrations
            </VTab>
          </VTabs>
          <VDivider class="mx-6" />

          <VTabsWindow v-model="activeTab" class="px-6 pb-6 pt-6">
            <!-- Custom Variables Tab -->
            <VTabsWindowItem value="tab-variables">
              <CustomVariablesLibrary
                :flow-id="flowId"
                :is-read-only="isReadOnly"
                @variables-updated="handleVariablesUpdated"
              />
            </VTabsWindowItem>

            <!-- Functions Tab -->
            <VTabsWindowItem value="tab-functions">
              <FunctionsLibrary
                :flow-id="flowId"
                @functions-updated="handleFunctionsUpdated"
              />
            </VTabsWindowItem>

            <!-- API Integrations Tab -->
            <VTabsWindowItem value="tab-apis">
              <ApiLibrary :flow-id="flowId" @apis-updated="handleApisUpdated" />
            </VTabsWindowItem>
          </VTabsWindow>
        </VCardText>
      </VCard>
    </VCard>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import CustomVariablesLibrary from "@/components/settings/custom-variables.vue";
import FunctionsLibrary from "@/components/settings/custom-functions.vue";
import ApiLibrary from "@/components/settings/apis.vue";

const route = useRoute();
const router = useRouter();
const flowId = computed(() => route.params.id as string);

const props = defineProps<{
  isReadOnly: boolean;
}>();

const emit = defineEmits<{
  (e: "variablesUpdated", variables: any[]): void;
  (e: "functionsUpdated", functions: any[]): void;
  (e: "apisUpdated", apis: any[]): void;
}>();

// State
const activeTab = ref("tab-variables");
// Handlers
function handleVariablesUpdated(variables: any[]) {
  emit("variablesUpdated", variables);
}

function handleFunctionsUpdated(functions: any[]) {
  emit("functionsUpdated", functions);
}

function handleApisUpdated(apis: any[]) {
  emit("apisUpdated", apis);
}
</script>

<style scoped>
.flow-library {
  width: 100%;
}
</style>
