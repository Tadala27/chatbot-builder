<script setup lang="ts">
import { shallowRef } from 'vue'

// assets
import imageChart from '@images/mega-menu/chart.svg'

const dropdownMenu = shallowRef([
  { header: 'Authentication' },
  { header: 'Other pages' },
  { header: 'SASS Pages' },
  {
    link: 'Login',
    href: '/authentication/auth1/login1',
  },
  {
    link: 'FAQs',
    href: '/faq',
  },
  {
    link: '404 Error',
    href: '/:error(.*)',
  },
  {
    link: 'Register',
    href: '/authentication/auth1/register1',
  },
  {
    link: 'Contact us',
    href: '/contactus',
  },
  {
    link: 'Landing',
    href: '/landing',
  },
  {
    link: 'Reset Password',
    href: '/authentication/auth1/resetpwd1',
  },
  {
    link: 'Pricing',
    href: '/pricing/pricing1',
  },
  {},
  {
    link: 'Forgot Password',
    href: '/authentication/auth1/forgotpwd1',
  },
  {
    link: 'Privacy policy',
    href: '/privacypolicy',
  },
  {},
  {
    link: 'Verification code',
    href: '/authentication/auth1/codeverification1',
  },
  {
    link: 'Construction',
    href: '/underconstruction/construction1',
  },
  {},
  {},
  {
    link: 'Coming soon',
    href: '/maintenance/comingsoon1',
  },
])
</script>

<template>
  <!-- ---------------------------------------------- -->
  <!-- mega menu DD -->
  <!-- ---------------------------------------------- -->
  <div>
    <VRow class="ma-0">
      <VCol cols="12" lg="4" class="pa-0">
        <div class="megamenu-banner pa-9">
          <h2 class="text-h2 text-white mb-1">
            Explore Components
          </h2>
          <p class="text-h6 text-white mb-0">
            Try NBS Bank's component pages to check how it feels and suits as per your need.
          </p>
          <div class="d-flex justify-between align-end">
            <VBtn rounded="md" variant="flat" class="scale-hover" href="/forms/components/buttons" target="_">
              View All
              <svg class="pc-icon ml-1 text-primary">
                <use xlink:href="/assets/svg/sprite.svg#custom-chevron-right" />
              </svg>
            </VBtn>
            <VImg :src="imageChart" alt="chart" width="124" height="126" />
          </div>
        </div>
      </VCol>
      <VCol cols="12" lg="8" class="pa-0">
        <VList density="compact" class="overflow-hidden pt-6 px-4" aria-label="mega menu list" aria-busy="true">
          <VRow no-gutters>
            <template v-for="(item, i) in dropdownMenu" :key="i">
              <VCol cols="6" sm="4">
                <VListItem v-if="item.header" rounded="md">
                  <VListItemTitle class="text-subtitle-1">
                    {{ item.header }}
                  </VListItemTitle>
                </VListItem>
                <VListItem v-if="item.link" :href="`${item.href}`" rounded="md" variant="plain" color="lightText"
                  target="_blank" rel="noopener noreferrer" class="no-spacer">
                  <template #prepend>
                    <VIcon class="text-8 me-4 text-lightText" icon="$circleOutline" />
                  </template>
                  <VListItemTitle class="text-subtitle-1 font-weight-regular text-lightText">
                    {{ item.link }}
                  </VListItemTitle>
                </VListItem>
              </VCol>
            </template>
          </VRow>
        </VList>
      </VCol>
    </VRow>
  </div>
</template>

<style lang="scss">
.megamenu-banner {
  background: rgb(var(--v-theme-darkprimary));
  height: 100%;
  padding-bottom: 37px !important;
  background-repeat: no-repeat;
  background-position: bottom;
  width: 100%;
  position: relative;

  &::after {
    position: absolute;
    content: '';
    width: 100%;
    height: 70%;
    background: url('@images/mega-menu/back.svg');
    background-size: cover;
    background-repeat: no-repeat;
    background-position: bottom;
    bottom: 0;
    left: 0;
    opacity: 0.5;
  }

  .d-flex {
    position: relative;
    z-index: 1;

    .v-img {
      object-fit: cover;
      margin-right: -90px;
      margin-bottom: -20px;
      width: 124px;

      [dir="rtl"] & {
        margin-right: unset;
        margin-left: -90px;
      }
    }
  }
}

.v-btn {
  &.scale-hover {
    &:hover {
      transform: scale(1.05);
    }
  }
}

.v-menu {
  .v-list-item__prepend {
    >.v-icon {
      --v-medium-emphasis-opacity: 1;
    }
  }

  .v-list[aria-label='mega menu list'] {
    .v-list-item--link {
      .text-lightText {
        &:hover {
          color: rgb(var(--v-theme-primary)) !important;
        }
      }
    }
  }

  .v-list-item--variant-plain {
    opacity: 1;

    &:hover {
      color: rgb(var(--v-theme-primary));
    }
  }
}
</style>
