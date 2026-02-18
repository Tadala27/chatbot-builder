export interface menu {
  header?: string
  title?: string
  icon?: string
  to?: string
  divider?: boolean
  chip?: string
  chipColor?: string
  chipVariant?: string
  chipIcon?: string
  children?: menu[]
  disabled?: boolean
  subCaption?: string
  class?: string
  extraclass?: string
  type?: string
}

const horizontalItems: menu[] = [
  {
    title: 'Dashboard',
    icon: 'custom-home-trend',
    to: '#',
    children: [
      {
        title: 'Dashboard',
        icon: 'custom-home-trend',
        to: '#',
        children: [
          {
            title: 'Default',
            to: '/dashboards/default',
          },
          {
            title: 'Analytics',
            to: '/dashboards/analytics',
          },
        ],
      },
      {
        title: 'Components',
        icon: 'custom-box-1',
        to: '/forms/components/buttons',
      },
    ],
  },
  {
    title: 'Widgets',
    icon: 'custom-story',
    to: '#',
    children: [
      {
        title: 'Statistics',
        icon: 'custom-story',
        to: '/widgets/statistics',
      },
      {
        title: 'Data',
        icon: 'custom-fatrows',
        to: '/widgets/data',
      },
      {
        title: 'Chart',
        icon: 'custom-presentation-chart',
        to: '/widgets/chart',
      },
    ],
  },
  {
    title: 'Apps',
    icon: 'custom-application',
    to: '#',
    children: [
      {
        title: 'Chat',
        icon: 'custom-chat',
        to: '/apps/chats',
      },
      {
        title: 'Calendar',
        icon: 'custom-calendar-1',
        to: '/apps/calendar',
      },
      {
        title: 'Kanban',
        icon: 'custom-kanban',
        to: '/apps/kanban',
      },
      {
        title: 'Users',
        icon: 'custom-user-square',
        to: '/apps/users',
        children: [
          {
            title: 'Social Profile',
            to: '/apps/users/social/posts',
          },
          {
            title: 'Account Profile',
            to: '/apps/users/accountprofile',
            children: [
              {
                title: 'Profile 01',
                to: '/apps/users/accountprofile/profile1',
              },
              {
                title: 'Profile 02',
                to: '/apps/users/accountprofile/profile2',
              },
              {
                title: 'Profile 03',
                to: '/apps/users/accountprofile/profile3',
              },
            ],
          },
          {
            title: 'User Profile',
            to: '/apps/users/userprofile',
          },
          {
            title: 'Cards',
            to: '/apps/users/card',
            children: [
              {
                title: 'Style 01',
                to: '/apps/users/card/card1',
              },
              {
                title: 'Style 02',
                to: '/apps/users/card/card2',
              },
              {
                title: 'Style 03',
                to: '/apps/users/card/card3',
              },
            ],
          },
          {
            title: 'List',
            to: '/app/user/list',
            children: [
              {
                title: 'Style 01',
                to: '/apps/users/list/list1',
              },
              {
                title: 'Style 02',
                to: '/apps/users/list/list2',
              },
            ],
          },
        ],
      },
      {
        title: 'Customer',
        icon: 'custom-users',
        to: '/customer/',
        children: [
          {
            title: 'Customer List',
            to: '/apps/customer/customerlist',
          },
          {
            title: 'Create Invoice',
            to: '/apps/customer/createinvoice',
          },
          {
            title: 'Order Details',
            to: '/apps/customer/orderdetails',
          },
          {
            title: 'Order List',
            to: '/apps/customer/orderlist',
          },
          {
            title: 'Product List',
            to: '/apps/customer/productlist',
          },
          {
            title: 'Product Review',
            to: '/apps/customer/productreview',
          },
        ],
      },
      {
        title: 'Mail',
        icon: 'custom-direct-inbox',
        to: '/apps/mail',
      },
      {
        title: 'Contact',
        icon: 'custom-user-circle-add',
        to: '/app/contacts',
        children: [
          {
            title: 'Card',
            to: '/apps/contacts/card',
          },
          {
            title: 'List',
            to: '/apps/contacts/list',
          },
        ],
      },
      {
        title: 'E-Commerce',
        icon: 'custom-shopping-bag',
        to: '/ecommerce/',
        children: [
          {
            title: 'Products',
            to: '/apps/ecommerce/products',
          },
          {
            title: 'Product Detail',
            to: '/apps/ecommerce/details/1',
          },
          {
            title: 'Product List',
            to: '/apps/ecommerce/productlist',
          },
          {
            title: 'Add New Product',
            to: '/apps/ecommerce/addproduct',
          },
          {
            title: 'Checkout',
            to: '/apps/ecommerce/checkout',
          },
        ],
      },
    ],
  },
  {
    title: 'Forms',
    icon: 'custom-forms',
    to: '#',
    children: [
      {
        title: 'Form Validation',
        icon: 'custom-password-check',
        to: '/forms/formvalidation',
      },
      {
        title: 'Layouts',
        icon: 'custom-row-vertical',
        to: '/forms/layouts',
        children: [
          {
            title: 'Layouts',
            to: '/forms/layouts/layouts',
          },
          {
            title: 'Multi Columns',
            to: '/forms/layouts/multicolumnforms',
          },
          {
            title: 'Action Bar',
            to: '/forms/layouts/actionbar',
          },
          {
            title: 'Sticky Bar',
            to: '/forms/layouts/stickyactionbar',
          },
        ],
      },
      {
        title: 'Plugins',
        icon: 'custom-cpu-charge',
        to: '/forms/radio',
        children: [
          {
            title: 'Editor',
            to: '/forms/plugins/editor',
          },
          {
            title: 'Mask',
            to: '/forms/plugins/mask',
          },
          {
            title: 'reCaptcha',
            to: '/authentication/auth1/login1',
          },
          {
            title: 'Clipboard',
            to: '/forms/plugins/clipboard',
          },
        ],
      },
      {
        title: 'Tables',
        icon: 'custom-table',
        to: '/forms/tables',
        children: [
          {
            title: 'Basic Table',
            to: '/forms/tables/tablebasic',
          },
          {
            title: 'Dark Table',
            to: '/forms/tables/tabledark',
          },
          {
            title: 'Density Table',
            to: '/forms/tables/tabledensity',
          },
          {
            title: 'Height Table',
            to: '/forms/tables/tableheight',
          },
          {
            title: 'Fixed Header Table',
            to: '/forms/tables/tableheaderfixed',
          },
        ],
      },
      {
        title: 'Charts',
        icon: 'custom-graph',
        to: '/forms/radio',
        children: [
          {
            title: 'Org Charts',
            to: '/forms/charts/orgchart',
          },
          {
            title: 'Apex Charts',
            to: '/forms/charts/apexchart',
          },
        ],
      },
    ],
  },
  {
    title: 'More items',
    to: '#',
    icon: 'custom-items',
    children: [
      {
        title: 'Authentication',
        icon: 'custom-shield',
        to: '/auth',
        children: [
          {
            title: 'Login',
            to: '/authentication/auth1/login1',
          },
          {
            title: 'Register',
            to: '/authentication/auth1/register1',
          },
          {
            title: 'Forgot Password',
            to: '/authentication/auth1/forgotpwd1',
          },
          {
            title: 'Check Mail',
            to: '/authentication/auth1/checkmail1',
          },
          {
            title: 'Reset Password',
            to: '/authentication/auth1/resetpwd1',
          },
          {
            title: 'Code Verification',
            to: '/authentication/auth1/codeverification1',
          },
        ],
      },
      {
        title: 'Maintenance',
        icon: 'custom-maintenance',
        to: '/maintenenace',
        children: [
          {
            title: 'Error 404',
            to: '/:error(.*)',
          },
          {
            title: 'Error 500',
            to: '/error500',
          },
          {
            title: 'Coming soon',
            to: '/maintenance/comingsoon1',
          },
          {
            title: 'Under Construction',
            to: '/underconstruction/construction1',
          },
        ],
      },
      {
        title: 'Pricing',
        icon: 'custom-dollar-square',
        to: '/pricing/pricing1',
      },
      {
        title: 'Front-end',
        icon: 'custom-airplane',
        to: '/2level',
        children: [
          {
            title: 'Landingpage',
            to: '/landing',
            type: 'external',
          },
          {
            title: 'Contact Us',
            to: '/contactus',
            type: 'external',
          },
          {
            title: 'FAQs',
            to: '/faq',
            type: 'external',
          },
          {
            title: 'Privacy Policy',
            to: '/privacypolicy',
            type: 'external',
          },
        ],
      },
      {
        title: 'Utilities',
        icon: 'custom-mouse-circle',
        to: '/level1',
        children: [
          {
            title: 'Iconsax',
            type: 'external',
            to: 'https://iconsax.io/',
          },
          {
            title: 'Tabler Icons',
            to: '/utilities/icons/tabler',
          },
          {
            title: 'Material Icons',
            to: '/utilities/icons/material',
          },
        ],
      },
      {
        title: 'Disabled Menu',
        icon: 'custom-disabled',
        disabled: true,
        to: '/',
      },
      {
        title: 'Oval Chip',
        icon: 'custom-info-circle',
        to: '/',
        chip: 'Fire',
        chipColor: 'error',
        chipVariant: 'outlined',
      },
      {
        title: 'Sample Page',
        icon: 'custom-sample',
        to: '/starter',
      },
      {
        title: 'Documentation',
        icon: 'custom-support',
        to: 'https://phoenixcoded.gitbook.io/able-pro',
        type: 'external',
        chip: 'gitbook',
        chipColor: 'info',
        chipVariant: 'flat',
      },
      {
        title: 'Road Map',
        icon: 'custom-roadmap',
        to: 'https://phoenixcoded.gitbook.io/able-pro/v/vue-laravel/roadmap',
        type: 'external',
      },
    ],
  },
]

export default horizontalItems
